<?php
/**
 * Enrollment gate (spec §10) — the public application form.
 *
 *   [njilga_membership_application]
 *
 * Replaces open self-registration: submitting creates (or finds) the
 * FluentCRM contact, tags them pending-approval, records the application
 * in the review queue, and notifies staff. The applicant is NOT attached
 * to any Company and gets no role or paid tag, so they never enter the
 * billing pool until approved on My NJILGA → Applications.
 *
 * Firm field: search-as-you-type against existing FluentCRM Companies
 * (a small vanilla-JS autocomplete hitting admin-ajax), with an
 * "Add “…” as a new firm" fallback. Without JS the field still works as
 * a plain text box — the server reuses an exact-name match or records
 * the typed name as a new firm for staff to confirm.
 */
class MyNJILGA_Application_Form {

    const SHORTCODE      = 'njilga_membership_application';
    const ACTION_SUBMIT  = 'njilga_apply';
    const AJAX_SEARCH    = 'njilga_company_search';
    const NONCE_SUBMIT   = 'njilga_apply_nonce';
    const NONCE_SEARCH   = 'njilga_company_search_nonce';

    public static function register(): void {
        add_shortcode( self::SHORTCODE, [ __CLASS__, 'render' ] );
        add_action( 'wp_ajax_' . self::AJAX_SEARCH, [ __CLASS__, 'ajax_company_search' ] );
        add_action( 'wp_ajax_nopriv_' . self::AJAX_SEARCH, [ __CLASS__, 'ajax_company_search' ] );
        add_action( 'admin_post_' . self::ACTION_SUBMIT, [ __CLASS__, 'handle_submit' ] );
        add_action( 'admin_post_nopriv_' . self::ACTION_SUBMIT, [ __CLASS__, 'handle_submit' ] );
    }

    // -------------------------------------------------------------------------
    // Shortcode
    // -------------------------------------------------------------------------

    public static function render( $atts = [] ): string {
        if ( ! MyNJILGA_Members_Data::fluentcrm_active() ) {
            return '<p>Membership applications are temporarily unavailable.</p>';
        }

        if ( ! empty( $_GET['njilga_applied'] ) ) {
            return '<div class="njilga-app njilga-app--success"><p>' . esc_html( (string) MyNJILGA_Dues_Settings::general( 'application_success_text', 'Thank you — your application has been received.' ) ) . '</p></div>';
        }

        $error = isset( $_GET['njilga_error'] ) ? sanitize_key( $_GET['njilga_error'] ) : '';
        $old   = isset( $_GET['njilga_old'] ) ? (array) json_decode( base64_decode( (string) $_GET['njilga_old'] ), true ) : [];
        $errors = [
            'invalid'   => 'Please check the highlighted fields — first name, last name, a valid email and a firm are required.',
            'category'  => 'Please choose a membership category.',
            'throttle'  => 'Please wait a moment before submitting again.',
            'failed'    => 'Something went wrong saving your application. Please try again or contact NJILGA.',
        ];

        $categories = array_values( array_filter( MyNJILGA_Dues_Settings::categories(), static function ( $c ) { return ! empty( $c['applicant_selectable'] ); } ) );
        $ajaxUrl    = admin_url( 'admin-ajax.php' );
        $uid        = 'njilga-app-' . wp_generate_password( 6, false, false );

        ob_start();
        ?>
        <style>
            .njilga-app{max-width:640px}
            .njilga-app label{display:block;font-weight:600;margin:14px 0 4px}
            .njilga-app input[type=text],.njilga-app input[type=email],.njilga-app input[type=tel],.njilga-app select,.njilga-app textarea{width:100%;padding:8px 10px;border:1px solid #c3c4c7;border-radius:4px;box-sizing:border-box;font:inherit}
            .njilga-app .njilga-app__row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
            .njilga-app .njilga-app__firm{position:relative}
            .njilga-app .njilga-app__suggest{position:absolute;left:0;right:0;top:100%;z-index:50;background:#fff;border:1px solid #c3c4c7;border-top:0;border-radius:0 0 4px 4px;list-style:none;margin:0;padding:0;max-height:240px;overflow:auto;display:none}
            .njilga-app .njilga-app__suggest li{padding:8px 10px;cursor:pointer}
            .njilga-app .njilga-app__suggest li:hover,.njilga-app .njilga-app__suggest li[aria-selected=true]{background:#f0f6fc}
            .njilga-app .njilga-app__suggest li.is-new{font-style:italic;color:#2271b1;border-top:1px solid #eee}
            .njilga-app .njilga-app__hint{font-size:12px;color:#646970;margin:4px 0 0}
            .njilga-app .njilga-app__error{padding:10px 12px;background:#fcf0f1;border:1px solid #d63638;border-radius:4px;margin-bottom:12px}
            .njilga-app--success{padding:14px 16px;background:#edfaef;border:1px solid #1d6f42;border-radius:4px}
            .njilga-app .njilga-app__hp{position:absolute;left:-9999px;opacity:0;height:0;overflow:hidden}
            .njilga-app button{margin-top:18px;padding:10px 18px;font:inherit;font-weight:600;border-radius:4px;border:1px solid #2271b1;background:#2271b1;color:#fff;cursor:pointer}
            @media (max-width:560px){.njilga-app .njilga-app__row{grid-template-columns:1fr}}
        </style>
        <form class="njilga-app" id="<?php echo esc_attr( $uid ); ?>" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php if ( $error !== '' && isset( $errors[ $error ] ) ) : ?>
                <div class="njilga-app__error"><?php echo esc_html( $errors[ $error ] ); ?></div>
            <?php endif; ?>
            <input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_SUBMIT ); ?>">
            <input type="hidden" name="_redirect" value="<?php echo esc_url( self::current_url() ); ?>">
            <?php wp_nonce_field( self::NONCE_SUBMIT, '_njilga_nonce' ); ?>
            <div class="njilga-app__hp" aria-hidden="true"><label>Leave this field empty<input type="text" name="njilga_website" tabindex="-1" autocomplete="off"></label></div>

            <div class="njilga-app__row">
                <div><label for="<?php echo esc_attr( $uid ); ?>-first">First name *</label><input type="text" id="<?php echo esc_attr( $uid ); ?>-first" name="first_name" required value="<?php echo esc_attr( (string) ( $old['first_name'] ?? '' ) ); ?>"></div>
                <div><label for="<?php echo esc_attr( $uid ); ?>-last">Last name *</label><input type="text" id="<?php echo esc_attr( $uid ); ?>-last" name="last_name" required value="<?php echo esc_attr( (string) ( $old['last_name'] ?? '' ) ); ?>"></div>
            </div>
            <div class="njilga-app__row">
                <div><label for="<?php echo esc_attr( $uid ); ?>-email">Email *</label><input type="email" id="<?php echo esc_attr( $uid ); ?>-email" name="email" required value="<?php echo esc_attr( (string) ( $old['email'] ?? '' ) ); ?>"></div>
                <div><label for="<?php echo esc_attr( $uid ); ?>-phone">Phone</label><input type="tel" id="<?php echo esc_attr( $uid ); ?>-phone" name="phone" value="<?php echo esc_attr( (string) ( $old['phone'] ?? '' ) ); ?>"></div>
            </div>

            <label for="<?php echo esc_attr( $uid ); ?>-firm">Firm / organization *</label>
            <div class="njilga-app__firm">
                <input type="text" id="<?php echo esc_attr( $uid ); ?>-firm" name="firm_name" required autocomplete="off" role="combobox" aria-autocomplete="list" aria-expanded="false" placeholder="Start typing your firm's name…" value="<?php echo esc_attr( (string) ( $old['firm_name'] ?? '' ) ); ?>">
                <input type="hidden" name="company_id" id="<?php echo esc_attr( $uid ); ?>-company" value="<?php echo (int) ( $old['company_id'] ?? 0 ); ?>">
                <ul class="njilga-app__suggest" id="<?php echo esc_attr( $uid ); ?>-suggest" role="listbox"></ul>
            </div>
            <p class="njilga-app__hint" id="<?php echo esc_attr( $uid ); ?>-firmhint">Pick your firm from the list, or keep typing to add it as a new firm.</p>

            <label for="<?php echo esc_attr( $uid ); ?>-cat">Membership category *</label>
            <select id="<?php echo esc_attr( $uid ); ?>-cat" name="category_key" required>
                <option value="">Choose…</option>
                <?php foreach ( $categories as $c ) : ?>
                    <option value="<?php echo esc_attr( $c['key'] ); ?>"<?php selected( (string) ( $old['category_key'] ?? '' ), $c['key'] ); ?>><?php echo esc_html( $c['label'] ); ?></option>
                <?php endforeach; ?>
            </select>

            <label for="<?php echo esc_attr( $uid ); ?>-msg">Anything NJILGA should know?</label>
            <textarea id="<?php echo esc_attr( $uid ); ?>-msg" name="message" rows="4"><?php echo esc_textarea( (string) ( $old['message'] ?? '' ) ); ?></textarea>

            <button type="submit">Submit application</button>
        </form>
        <script>
        (function(){
            var form=document.getElementById(<?php echo wp_json_encode( $uid ); ?>);if(!form)return;
            var input=form.querySelector('input[name=firm_name]'),hidden=form.querySelector('input[name=company_id]'),list=form.querySelector('.njilga-app__suggest'),hint=document.getElementById(<?php echo wp_json_encode( $uid . '-firmhint' ); ?>);
            var url=<?php echo wp_json_encode( $ajaxUrl ); ?>,nonce=<?php echo wp_json_encode( wp_create_nonce( self::NONCE_SEARCH ) ); ?>,timer=null,items=[],active=-1,lastPicked=input.value;
            function close(){list.style.display='none';list.innerHTML='';input.setAttribute('aria-expanded','false');active=-1;}
            function pick(item){if(item.id){hidden.value=item.id;input.value=item.name;hint.textContent='Existing firm selected: '+item.name;}else{hidden.value='0';input.value=item.name;hint.textContent='“'+item.name+'” will be added as a new firm.';}lastPicked=input.value;close();}
            function render(q,data){items=[];list.innerHTML='';data.forEach(function(c){items.push({id:c.id,name:c.name});});var exact=data.some(function(c){return c.name.toLowerCase()===q.toLowerCase();});if(!exact&&q.length>=2){items.push({id:0,name:q,isNew:true});}
                if(!items.length){close();return;}
                items.forEach(function(it,i){var li=document.createElement('li');li.setAttribute('role','option');li.textContent=it.isNew?('Add “'+it.name+'” as a new firm'):it.name;if(it.isNew)li.className='is-new';li.addEventListener('mousedown',function(e){e.preventDefault();pick(it);});list.appendChild(li);});
                list.style.display='block';input.setAttribute('aria-expanded','true');active=-1;}
            function search(){var q=input.value.trim();if(q.length<2){close();return;}
                var body=new URLSearchParams({action:<?php echo wp_json_encode( self::AJAX_SEARCH ); ?>,q:q,_nonce:nonce});
                fetch(url,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:body.toString()}).then(function(r){return r.json();}).then(function(res){render(q,(res&&res.success&&res.data)?res.data:[]);}).catch(function(){render(q,[]);});}
            input.addEventListener('input',function(){if(input.value!==lastPicked){hidden.value='0';hint.textContent='Pick your firm from the list, or keep typing to add it as a new firm.';}clearTimeout(timer);timer=setTimeout(search,250);});
            input.addEventListener('keydown',function(e){if(list.style.display!=='block')return;var lis=list.querySelectorAll('li');
                if(e.key==='ArrowDown'){e.preventDefault();active=Math.min(active+1,lis.length-1);}else if(e.key==='ArrowUp'){e.preventDefault();active=Math.max(active-1,0);}else if(e.key==='Enter'){if(active>=0){e.preventDefault();pick(items[active]);}return;}else if(e.key==='Escape'){close();return;}else{return;}
                lis.forEach(function(li,i){li.setAttribute('aria-selected',i===active?'true':'false');});});
            document.addEventListener('click',function(e){if(!form.contains(e.target))close();});
            input.addEventListener('blur',function(){setTimeout(close,150);});
        })();
        </script>
        <?php
        return (string) ob_get_clean();
    }

    // -------------------------------------------------------------------------
    // AJAX: company autocomplete
    // -------------------------------------------------------------------------

    public static function ajax_company_search(): void {
        if ( ! isset( $_POST['_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_nonce'] ) ), self::NONCE_SEARCH ) ) {
            wp_send_json_error( 'bad nonce', 403 );
        }
        $q = isset( $_POST['q'] ) ? sanitize_text_field( wp_unslash( $_POST['q'] ) ) : '';
        if ( mb_strlen( $q ) < 2 || ! MyNJILGA_Members_Data::companies_module_active() ) {
            wp_send_json_success( [] );
        }
        global $wpdb;
        $rows = \FluentCrm\App\Models\Company::where( 'name', 'LIKE', '%' . $wpdb->esc_like( $q ) . '%' )
            ->orderBy( 'name', 'asc' )
            ->limit( 10 )
            ->get();
        $out = [];
        foreach ( $rows as $c ) {
            $out[] = [ 'id' => (int) $c->id, 'name' => (string) $c->name ];
        }
        wp_send_json_success( $out );
    }

    // -------------------------------------------------------------------------
    // Submit
    // -------------------------------------------------------------------------

    public static function handle_submit(): void {
        $redirect = isset( $_POST['_redirect'] ) ? esc_url_raw( wp_unslash( $_POST['_redirect'] ) ) : home_url( '/' );
        if ( ! wp_validate_redirect( $redirect, '' ) ) {
            $redirect = home_url( '/' );
        }

        if ( ! isset( $_POST['_njilga_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_njilga_nonce'] ) ), self::NONCE_SUBMIT ) ) {
            self::back( $redirect, 'failed' );
        }
        if ( ! empty( $_POST['njilga_website'] ) ) {
            self::back( $redirect, '', true ); // Honeypot: pretend success, store nothing.
        }

        $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
        $throttleKey = 'njilga_apply_' . md5( $ip );
        if ( $ip !== '' && get_transient( $throttleKey ) ) {
            self::back( $redirect, 'throttle' );
        }

        $in = [
            'first_name'   => sanitize_text_field( wp_unslash( $_POST['first_name'] ?? '' ) ),
            'last_name'    => sanitize_text_field( wp_unslash( $_POST['last_name'] ?? '' ) ),
            'email'        => sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ),
            'phone'        => sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) ),
            'firm_name'    => sanitize_text_field( wp_unslash( $_POST['firm_name'] ?? '' ) ),
            'company_id'   => (int) ( $_POST['company_id'] ?? 0 ),
            'category_key' => sanitize_key( wp_unslash( $_POST['category_key'] ?? '' ) ),
            'message'      => sanitize_textarea_field( wp_unslash( $_POST['message'] ?? '' ) ),
        ];

        if ( $in['first_name'] === '' || $in['last_name'] === '' || ! is_email( $in['email'] ) || $in['firm_name'] === '' ) {
            self::back( $redirect, 'invalid', false, $in );
        }
        // Only categories the form actually offers — never an exempt one
        // smuggled in by a crafted POST.
        $chosen = $in['category_key'] !== '' ? MyNJILGA_Dues_Settings::category( $in['category_key'] ) : null;
        if ( ! $chosen || empty( $chosen['applicant_selectable'] ) ) {
            self::back( $redirect, 'category', false, $in );
        }

        // Resolve the firm: chosen id must exist; otherwise an exact
        // (case-insensitive) name match is reused; otherwise it's new.
        $companyId = 0;
        $newName   = '';
        if ( MyNJILGA_Members_Data::companies_module_active() ) {
            if ( $in['company_id'] > 0 && \FluentCrm\App\Models\Company::find( $in['company_id'] ) ) {
                $companyId = $in['company_id'];
            } else {
                // Default MySQL collations compare case-insensitively, so a
                // plain equality match is the "same firm, different casing" check.
                $match = \FluentCrm\App\Models\Company::where( 'name', $in['firm_name'] )->first();
                if ( $match ) {
                    $companyId = (int) $match->id;
                } else {
                    $newName = $in['firm_name'];
                }
            }
        } else {
            $newName = $in['firm_name'];
        }

        // FluentCRM contact: create or update, tag pending. Never attached
        // to a Company here — that happens on approval.
        $contactId = 0;
        try {
            $payload = [
                'email'      => $in['email'],
                'first_name' => $in['first_name'],
                'last_name'  => $in['last_name'],
                'phone'      => $in['phone'],
                'status'     => 'subscribed',
                'source'     => 'NJILGA membership application',
            ];
            $contact = function_exists( 'FluentCrmApi' ) ? FluentCrmApi( 'contacts' )->createOrUpdate( $payload ) : null;
            if ( $contact && ! empty( $contact->id ) ) {
                $contactId = (int) $contact->id;
                MyNJILGA_Tags::attach_slug( $contact, (string) MyNJILGA_Dues_Settings::general( 'pending_tag', 'pending-approval' ), 'Pending Approval' );
            }
        } catch ( \Throwable $e ) {
            // The application is still recorded below; staff can fix the contact.
        }

        $data = [
            'first_name'           => $in['first_name'],
            'last_name'            => $in['last_name'],
            'email'                => $in['email'],
            'phone'                => $in['phone'],
            'fluentcrm_contact_id' => $contactId,
            'fluentcrm_company_id' => $companyId,
            'new_company_name'     => $newName,
            'category_key'         => $in['category_key'],
            'message'              => $in['message'],
            'ip'                   => $ip,
        ];

        $existing = MyNJILGA_Applications_Table::get_pending_by_email( $in['email'] );
        if ( $existing ) {
            MyNJILGA_Applications_Table::update_pending( (int) $existing->id, $data );
            $appId = (int) $existing->id;
        } else {
            $appId = MyNJILGA_Applications_Table::insert( $data );
        }
        if ( ! $appId ) {
            self::back( $redirect, 'failed', false, $in );
        }

        if ( $ip !== '' ) {
            set_transient( $throttleKey, 1, 30 );
        }

        self::notify_staff( $appId, $data, $companyId, $newName );
        self::back( $redirect, '', true );
    }

    private static function notify_staff( int $appId, array $data, int $companyId, string $newName ): void {
        $to = (string) MyNJILGA_Dues_Settings::general( 'application_notify_email', '' );
        $recipients = array_filter( array_map( 'sanitize_email', preg_split( '/[\s,;]+/', $to ) ) );
        if ( empty( $recipients ) ) {
            $recipients = [ get_option( 'admin_email' ) ];
        }
        $firm = $companyId > 0 && MyNJILGA_Members_Data::companies_module_active()
            ? ( (string) ( \FluentCrm\App\Models\Company::find( $companyId )->name ?? '' ) . ' (existing firm)' )
            : $newName . ' (NEW firm)';
        $cat  = MyNJILGA_Dues_Settings::category( (string) $data['category_key'] );

        $body = sprintf(
            "A new NJILGA membership application is waiting for review.\n\nName: %s %s\nEmail: %s\nPhone: %s\nFirm: %s\nCategory: %s\n\nMessage:\n%s\n\nReview it here: %s",
            $data['first_name'], $data['last_name'], $data['email'], $data['phone'] !== '' ? $data['phone'] : '—',
            $firm,
            $cat ? $cat['label'] : $data['category_key'],
            $data['message'] !== '' ? $data['message'] : '—',
            MyNJILGA_Admin_Menu::url( MyNJILGA_Admin_Menu::SLUG_APPLICATIONS )
        );
        wp_mail( $recipients, sprintf( 'New membership application #%d — %s %s', $appId, $data['first_name'], $data['last_name'] ), $body );
    }

    private static function back( string $redirect, string $error, bool $success = false, array $old = [] ): void {
        $args = [];
        if ( $success ) {
            $args['njilga_applied'] = '1';
        } elseif ( $error !== '' ) {
            $args['njilga_error'] = $error;
            if ( $old ) {
                unset( $old['message'] );
                $args['njilga_old'] = base64_encode( (string) wp_json_encode( $old ) );
            }
        }
        wp_safe_redirect( add_query_arg( $args, remove_query_arg( [ 'njilga_applied', 'njilga_error', 'njilga_old' ], $redirect ) ) );
        exit;
    }

    private static function current_url(): string {
        $scheme = is_ssl() ? 'https' : 'http';
        $host   = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : wp_parse_url( home_url(), PHP_URL_HOST );
        $uri    = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
        return remove_query_arg( [ 'njilga_applied', 'njilga_error', 'njilga_old' ], $scheme . '://' . $host . $uri );
    }
}
