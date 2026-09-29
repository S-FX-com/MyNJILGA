<?php
/**
 * My NJILGA → Applications — the enrollment review queue (spec §10), and,
 * on its second tab, Online joins: everyone who joined (or started to)
 * through the [njilga_join] shortcode.
 */
class MyNJILGA_Page_Applications {

    const ACTION_DECIDE = 'my_njilga_application_decide';
    const ACTION_JOIN   = 'my_njilga_join_action';

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Access denied.' );
        }
        MyNJILGA_Applications_Table::maybe_upgrade();
        MyNJILGA_Join_Orders_Table::maybe_upgrade();
        MyNJILGA_Join_Invites_Table::maybe_upgrade();

        $tab = self::current_tab();

        MyNJILGA_Admin_UI::styles();
        echo '<div class="wrap njilga-ui">';
        if ( $tab === 'joins' ) {
            MyNJILGA_Admin_UI::page_header( 'Online Joins', 'Everyone joining through the [njilga_join] shortcode: paid through Stripe Checkout, membership applied on payment, colleagues invited.' );
        } else {
            MyNJILGA_Admin_UI::page_header( 'Membership Applications', 'Review the enrollment queue: approve to attach an applicant to their firm, or reject.' );
        }
        self::render_tabs( $tab );

        if ( MyNJILGA_Admin_Menu::require_fluentcrm() ) {
            MyNJILGA_Admin_UI::close();
            return;
        }

        if ( isset( $_GET['msg'] ) ) {
            $ok   = ! empty( $_GET['ok'] );
            $text = sanitize_text_field( wp_unslash( $_GET['msg'] ) );
            MyNJILGA_Admin_UI::callout( esc_html( $text ), $ok ? 'success' : 'error' );
        }

        if ( $tab === 'joins' ) {
            self::render_joins();
            MyNJILGA_Admin_UI::close();
            return;
        }

        $policy = (string) MyNJILGA_Dues_Settings::general( 'mid_year_join_policy' );
        printf(
            '<p class="njilga-section-desc">Applicants submitted through <code>[njilga_membership_application]</code> land here. They\'re FluentCRM contacts tagged <code>%s</code>, not attached to any firm, so they can\'t be invoiced until approved. On approval: attached to the firm (created if new; made Owner if the firm has none), category tag applied, then the mid-year join policy runs — currently <strong>%s</strong> (<a href="%s">change</a>).</p>',
            esc_html( (string) MyNJILGA_Dues_Settings::general( 'pending_tag' ) ),
            esc_html( MyNJILGA_Dues_Settings::join_policy_labels()[ $policy ] ?? $policy ),
            esc_url( MyNJILGA_Admin_Menu::url( MyNJILGA_Admin_Menu::SLUG_SETTINGS ) )
        );

        self::render_pending();
        self::render_decided();

        MyNJILGA_Admin_UI::close();
    }

    private static function current_tab(): string {
        $tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : '';
        return $tab === 'joins' ? 'joins' : 'applications';
    }

    private static function render_tabs( string $active ): void {
        $base = MyNJILGA_Admin_Menu::url( MyNJILGA_Admin_Menu::SLUG_APPLICATIONS );
        MyNJILGA_Admin_UI::nav_tabs( [
            [ 'label' => 'Applications', 'url' => $base, 'active' => $active === 'applications', 'count' => MyNJILGA_Applications_Table::count_pending() ],
            [ 'label' => 'Online joins', 'url' => add_query_arg( 'tab', 'joins', $base ), 'active' => $active === 'joins', 'count' => MyNJILGA_Join_Orders_Table::needs_attention_count() ],
        ] );
    }

    // -------------------------------------------------------------------------
    // Online joins tab
    // -------------------------------------------------------------------------

    private static function render_joins(): void {
        $T      = 'MyNJILGA_Join_Orders_Table';
        $counts = MyNJILGA_Join_Orders_Table::counts_by_status();
        $rows   = MyNJILGA_Join_Orders_Table::get_list( [], 300 );
        $year   = MyNJILGA_Invoicing::current_dues_year();
        $joined = 0;
        foreach ( $rows as $r ) {
            if ( $r->status === $T::STATUS_FULFILLED && (int) $r->dues_year === $year ) {
                $joined++;
            }
        }
        $attention = array_values( array_filter( $rows, [ $T, 'needs_attention' ] ) );

        MyNJILGA_Admin_UI::stat_cards( [
            [ 'label' => sprintf( 'Joined for %d', $year ), 'value' => $joined, 'variant' => 'success', 'icon' => 'check-circle' ],
            [ 'label' => 'Awaiting payment', 'value' => (int) ( $counts[ $T::STATUS_PENDING ] ?? 0 ), 'variant' => 'info', 'icon' => 'inbox' ],
            [ 'label' => 'Bank payment clearing', 'value' => (int) ( $counts[ $T::STATUS_PROCESSING ] ?? 0 ), 'variant' => 'info', 'icon' => 'refresh' ],
            [ 'label' => 'Needs attention', 'value' => count( $attention ), 'variant' => $attention ? 'warning' : 'default', 'icon' => 'alert' ],
        ] );

        // The public form takes payment in the mode active under Settings →
        // Payments (MyNJILGA_Join_Form::join_mode()), so what matters here
        // is whether that mode can take a checkout at all.
        $mode  = MyNJILGA_Stripe_Connection::active_mode();
        $label = $mode === MyNJILGA_Stripe_Connection::MODE_LIVE ? 'Live' : 'Test';
        // The lines to paste live on the Shortcodes page, built from the
        // current categories — naming keys here went stale on a rename.
        printf(
            '<p class="njilga-section-desc">Paste a <code>[njilga_join category="…"]</code> line on each Membership page — <a href="%s">Shortcodes</a> has one ready to copy for every category an applicant may pick in <a href="%s">Settings</a>, and shows which pages already carry one. A paid join becomes a membership the moment Stripe confirms the payment; a join that comes to $0 waits here for a decision. Visitors pay in the Stripe mode active under Settings → Payments — <strong>%s</strong> right now; while it\'s Live, staff can rehearse in Test by adding <code>?njilga_test=1</code> to the page\'s address.</p>',
            esc_url( MyNJILGA_Admin_Menu::url( MyNJILGA_Admin_Menu::SLUG_SHORTCODES ) ),
            esc_url( MyNJILGA_Admin_Menu::url( MyNJILGA_Admin_Menu::SLUG_SETTINGS ) ),
            esc_html( $label )
        );
        if ( ! MyNJILGA_Stripe_Connection::is_connected( $mode ) ) {
            MyNJILGA_Admin_UI::callout( sprintf( '<strong>Stripe %s mode isn\'t connected</strong>, so the form tells visitors joining online is unavailable. Connect it under <a href="%s">Settings → Payments</a>.', esc_html( $label ), esc_url( add_query_arg( 'tab', 'payments', MyNJILGA_Admin_Menu::url( MyNJILGA_Admin_Menu::SLUG_SETTINGS ) ) ) ), 'warning' );
        } elseif ( MyNJILGA_Stripe_Connection::checkout_access( $mode ) === false ) {
            MyNJILGA_Admin_UI::callout( sprintf( '<strong>The %s Stripe key can\'t create Checkout Sessions</strong>, so the form tells visitors joining online is unavailable. Give the key "Checkout Sessions: Write", then re-check it on <a href="%s">Setup</a>.', esc_html( $label ), esc_url( MyNJILGA_Admin_Menu::url( MyNJILGA_Admin_Menu::SLUG_SETUP ) ) ), 'warning' );
        }

        // Everything the rows need, loaded once for both tables below —
        // "Needs attention" is a subset of "All online joins".
        $context = self::joins_context( $rows );

        if ( $attention ) {
            MyNJILGA_Admin_UI::section( 'Needs attention', 'Joins waiting on a decision, a payment that hasn\'t been applied yet, or people a join left off their firm until you confirm them.', count( $attention ) );
            self::joins_table( $attention, $context );
        }

        MyNJILGA_Admin_UI::section( 'All online joins', 'Newest first. Abandoned and expired attempts are kept so you can follow up.', count( $rows ) );
        if ( ! $rows ) {
            echo '<div class="njilga-card njilga-empty"><div class="njilga-empty-icon">' . MyNJILGA_Admin_UI::icon( 'inbox' ) . '</div><h2 class="njilga-empty-title">No online joins yet</h2><p class="njilga-empty-text">Once the shortcode is on a Membership page, every attempt shows up here — paid or not.</p></div>';
            return;
        }
        self::joins_table( $rows, $context );
    }

    /**
     * The invites, firm names and invoice rows behind a list of joins, one
     * query each, keyed for joins_table() — rather than up to three
     * queries per row, for up to 300 rows, twice over.
     *
     * @param array<int,object> $rows
     * @return array{invites:array<int,array<int,object>>,firms:array<int,string>,invoice_rows:array<int,object>}
     */
    private static function joins_context( array $rows ): array {
        $joinIds    = [];
        $companyIds = [];
        $rowIds     = [];
        foreach ( $rows as $join ) {
            if ( MyNJILGA_Join_Orders_Table::json( $join, 'colleagues' ) ) {
                $joinIds[] = (int) $join->id;
            }
            if ( (int) $join->company_id > 0 ) {
                $companyIds[] = (int) $join->company_id;
            }
            if ( $join->status === MyNJILGA_Join_Orders_Table::STATUS_FULFILLED && (int) $join->invoice_row_id > 0 ) {
                $rowIds[] = (int) $join->invoice_row_id;
            }
        }

        $firms = [];
        if ( $companyIds && MyNJILGA_Members_Data::companies_module_active() ) {
            foreach ( \FluentCrm\App\Models\Company::whereIn( 'id', array_values( array_unique( $companyIds ) ) )->get() as $c ) {
                $firms[ (int) $c->id ] = (string) $c->name;
            }
        }

        return [
            'invites'      => self::invites_by_join( $joinIds ),
            'firms'        => $firms,
            'invoice_rows' => MyNJILGA_Dues_Invoice_Table::get_many( $rowIds ),
        ];
    }

    /**
     * Every invite for these joins, in one query — join id => its invites,
     * oldest first, the same rows MyNJILGA_Join_Invites_Table::get_for_join()
     * returns one join at a time.
     *
     * @param array<int,int> $joinIds
     * @return array<int,array<int,object>>
     */
    private static function invites_by_join( array $joinIds ): array {
        return MyNJILGA_Join_Invites_Table::get_for_joins( $joinIds );
    }

    /**
     * @param array<int,object>   $rows
     * @param array<string,mixed> $context joins_context()
     */
    private static function joins_table( array $rows, array $context ): void {
        echo '<div class="njilga-card njilga-table-boxed"><div class="njilga-tablewrap"><table class="njilga-table"><thead><tr><th>Applicant</th><th>Membership</th><th>Firm</th><th>Colleagues</th><th class="njilga-col-num">Total</th><th>Status</th><th class="njilga-col-actions">Actions</th></tr></thead><tbody>';
        foreach ( $rows as $join ) {
            $category   = MyNJILGA_Dues_Settings::category( (string) $join->category_key );
            $applicant  = MyNJILGA_Join_Orders_Table::json( $join, 'applicant' );
            $colleagues = MyNJILGA_Join_Orders_Table::json( $join, 'colleagues' );
            $progress   = MyNJILGA_Join_Orders_Table::json( $join, 'progress' );
            $invites    = $colleagues ? ( $context['invites'][ (int) $join->id ] ?? [] ) : [];
            $accepted   = count( array_filter( $invites, static function ( $i ) { return $i->status !== MyNJILGA_Join_Invites_Table::STATUS_SENT; } ) );

            // Applicant.
            $detail = $join->form === MyNJILGA_Join_Orders_Table::FORM_STUDENT
                ? ( ( $applicant['student_status'] ?? '' ) === 'undergrad' ? 'Undergraduate (pre-law)' : 'Enrolled law student' ) . ( ! empty( $applicant['school'] ) ? ' · ' . $applicant['school'] : '' )
                : trim( ( ! empty( $applicant['attorney_id'] ) ? 'NJ Attorney ID ' . $applicant['attorney_id'] : '' ) . ( ! empty( $applicant['bar_admission_date'] ) ? ' · admitted ' . $applicant['bar_admission_date'] : '' ), ' ·' );
            $cell = sprintf(
                '<div class="njilga-firmcell"><span class="njilga-firmname">%s %s</span>%s<span class="njilga-subline"><a href="mailto:%s">%s</a> · %s</span>%s</div>',
                esc_html( (string) $join->first_name ),
                esc_html( (string) $join->last_name ),
                empty( $join->livemode ) ? ' ' . MyNJILGA_Admin_UI::pill( 'Test', 'warning' ) : '',
                esc_attr( (string) $join->email ),
                esc_html( (string) $join->email ),
                esc_html( (string) $join->created_at ),
                $detail !== '' ? '<span class="njilga-subline">' . esc_html( $detail ) . '</span>' : ''
            );

            // Firm.
            $firm = MyNJILGA_Admin_UI::blank();
            if ( (int) $join->company_id > 0 && MyNJILGA_Members_Data::companies_module_active() ) {
                $name = $context['firms'][ (int) $join->company_id ] ?? '';
                $firm = esc_html( $name !== '' ? $name : 'Company #' . (int) $join->company_id ) . ' ' . MyNJILGA_Admin_UI::pill( ! empty( $progress['new_firm'] ) ? 'new firm' : 'existing', ! empty( $progress['new_firm'] ) ? 'info' : 'outline' );
            } elseif ( (string) $join->new_company_name !== '' ) {
                $firm = esc_html( (string) $join->new_company_name ) . ' ' . MyNJILGA_Admin_UI::pill( 'new firm', 'info' );
            }
            foreach ( (array) ( $progress['held'] ?? [] ) as $h ) {
                $firm .= sprintf( '<span class="njilga-subline njilga-subline-warn">Not yet on the firm: %s — %s</span>', esc_html( (string) ( $h['name'] ?? '' ) ), esc_html( (string) ( $h['reason'] ?? '' ) ) );
            }

            // Colleagues.
            $coll = MyNJILGA_Admin_UI::blank();
            if ( $colleagues ) {
                $names = array_map( static function ( $c ) { return trim( (string) ( $c['first_name'] ?? '' ) . ' ' . (string) ( $c['last_name'] ?? '' ) ); }, $colleagues );
                $coll  = sprintf( '%d <span class="njilga-subline">%s</span>', count( $colleagues ), esc_html( implode( ', ', $names ) ) );
                if ( $join->status === MyNJILGA_Join_Orders_Table::STATUS_FULFILLED ) {
                    $coll .= sprintf( '<span class="njilga-subline">%d of %d set up an account</span>', $accepted, count( $invites ) );
                }
            }

            // Status + anything staff should read.
            [ $label, $variant ] = self::join_status_pill( (string) $join->status );
            $status = MyNJILGA_Admin_UI::pill( $label, $variant );
            foreach ( array_merge( (array) ( $progress['already_current'] ?? [] ), (array) ( $progress['checks'] ?? [] ) ) as $w ) {
                $status .= '<span class="njilga-subline njilga-subline-warn">' . esc_html( (string) $w ) . '</span>';
            }
            if ( ! empty( $progress['document_unverified'] ) ) {
                $status .= '<span class="njilga-subline njilga-subline-warn">Student ID / transcript not yet checked.</span>';
            }
            if ( (string) ( $join->last_error ?? '' ) !== '' ) {
                $status .= '<span class="njilga-subline njilga-subline-warn">' . esc_html( (string) $join->last_error ) . '</span>';
            }

            printf(
                '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td class="njilga-col-num">%s</td><td>%s</td><td class="njilga-col-actions">%s</td></tr>',
                $cell,
                esc_html( $category ? $category['label'] : (string) $join->category_key ) . '<span class="njilga-subline">' . (int) $join->dues_year . '</span>',
                $firm,
                $coll,
                esc_html( MyNJILGA_Invoicing::money( (int) $join->total_cents ) ),
                $status,
                self::join_actions( $join, $progress, $invites, $accepted, $context['invoice_rows'][ (int) $join->invoice_row_id ] ?? null )
            );
        }
        echo '</tbody></table></div></div>';
    }

    /**
     * @return array{0:string,1:string} [label, pill variant]
     */
    private static function join_status_pill( string $status ): array {
        $T = 'MyNJILGA_Join_Orders_Table';
        switch ( $status ) {
            case $T::STATUS_FULFILLED:  return [ 'Member', 'success' ];
            case $T::STATUS_PENDING:    return [ 'Awaiting payment', 'info' ];
            case $T::STATUS_PROCESSING: return [ 'Bank payment clearing', 'info' ];
            case $T::STATUS_PAID:       return [ 'Paid — not applied yet', 'warning' ];
            case $T::STATUS_FULFILLING: return [ 'Applying…', 'info' ];
            case $T::STATUS_REVIEW:     return [ 'Needs a decision ($0)', 'warning' ];
            case $T::STATUS_REJECTED:   return [ 'Rejected', 'muted' ];
            case $T::STATUS_EXPIRED:    return [ 'Checkout expired', 'muted' ];
            case $T::STATUS_FAILED:     return [ 'Bank payment failed', 'destructive' ];
            case $T::STATUS_ABANDONED:  return [ 'Started over', 'muted' ];
        }
        return [ ucfirst( $status ), 'muted' ];
    }

    /**
     * @param array<string,mixed> $progress
     * @param array<int,object>   $invites
     * @param object|null         $row      The join's invoice row, when it has one.
     */
    private static function join_actions( object $join, array $progress, array $invites, int $accepted, $row = null ): string {
        $T   = 'MyNJILGA_Join_Orders_Table';
        $id  = (int) $join->id;
        $btn = static function ( string $op, string $label, string $style, string $confirm = '' ) use ( $id ): string {
            return MyNJILGA_Admin_UI::action_form( self::ACTION_JOIN, $label, [ 'join_id' => $id, 'op' => $op ], $style, '', $confirm, 'sm' );
        };
        $out = '';
        switch ( (string) $join->status ) {
            case $T::STATUS_REVIEW:
                $out .= $btn( 'approve', 'Approve', 'primary', 'Approve this join? Membership is applied at no charge.' );
                $out .= $btn( 'reject', 'Reject', 'danger-outline', 'Reject this join? The applicant is emailed.' );
                break;
            case $T::STATUS_PENDING:
            case $T::STATUS_PROCESSING:
                $out .= $btn( 'sync', 'Check payment', 'outline' );
                break;
            case $T::STATUS_PAID:
            case $T::STATUS_FULFILLING:
                $out .= $btn( 'sync', 'Retry applying', 'primary' );
                break;
            case $T::STATUS_FULFILLED:
                if ( ! empty( $progress['held'] ) ) {
                    $out .= $btn( 'confirm_held', 'Add to firm', 'primary', 'Attach the people listed to this firm?' );
                    $out .= $btn( 'dismiss_held', 'Leave off firm', 'ghost', 'Leave them off the firm? Their memberships stand.' );
                }
                if ( $invites && $accepted < count( $invites ) ) {
                    $out .= $btn( 'resend', 'Resend invites', 'outline', 'Email a fresh invitation link to each colleague who hasn\'t set up an account? Earlier links stop working.' );
                }
                if ( ! empty( $progress['already_current'] ) || ! empty( $progress['checks'] ) || ! empty( $progress['document_unverified'] ) || (string) ( $join->last_error ?? '' ) !== '' ) {
                    $out .= $btn( 'ack', ! empty( $progress['document_unverified'] ) ? 'Mark checked' : 'Mark reviewed', 'ghost' );
                }
                if ( $row && ! empty( $row->hosted_invoice_url ) ) {
                    $out .= sprintf( '<a class="njilga-btn njilga-btn-ghost njilga-btn-sm" href="%s" target="_blank" rel="noopener">%sReceipt</a>', esc_url( (string) $row->hosted_invoice_url ), MyNJILGA_Admin_UI::icon( 'external' ) );
                }
                break;
        }
        if ( (string) $join->document_path !== '' ) {
            $out .= sprintf( '<a class="njilga-btn njilga-btn-ghost njilga-btn-sm" href="%s" target="_blank" rel="noopener">%sStudent ID</a>', esc_url( MyNJILGA_Join_Documents::view_url( $id ) ), MyNJILGA_Admin_UI::icon( 'file' ) );
        }
        return $out !== '' ? '<div class="njilga-actions">' . $out . '</div>' : MyNJILGA_Admin_UI::blank();
    }

    public static function handle_join_action(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Access denied.' );
        }
        check_admin_referer( self::ACTION_JOIN );
        $id = (int) ( $_POST['join_id'] ?? 0 );
        $op = sanitize_key( wp_unslash( (string) ( $_POST['op'] ?? '' ) ) );

        switch ( $op ) {
            case 'approve':
                $r = MyNJILGA_Join_Fulfillment::approve_review( $id, get_current_user_id(), '' );
                break;
            case 'reject':
                $r = MyNJILGA_Join_Fulfillment::reject_review( $id, get_current_user_id(), '' );
                break;
            case 'sync':
                $r = MyNJILGA_Join_Fulfillment::sync( $id, 'staff (Online joins)' );
                break;
            case 'confirm_held':
                $r = MyNJILGA_Join_Fulfillment::confirm_held( $id );
                break;
            case 'dismiss_held':
                $r = MyNJILGA_Join_Fulfillment::dismiss_held( $id );
                break;
            case 'resend':
                $n = MyNJILGA_Join_Invites::resend( $id );
                $r = [ 'ok' => $n > 0, 'message' => $n > 0 ? sprintf( 'Sent %d fresh invitation%s.', $n, $n === 1 ? '' : 's' ) : 'No invitations were waiting.' ];
                break;
            case 'ack':
                $join = MyNJILGA_Join_Orders_Table::get( $id );
                if ( $join ) {
                    $progress = MyNJILGA_Join_Orders_Table::json( $join, 'progress' );
                    $progress['reviewed_notes'] = array_merge( (array) ( $progress['reviewed_notes'] ?? [] ), (array) ( $progress['already_current'] ?? [] ), (array) ( $progress['checks'] ?? [] ), (string) $join->last_error !== '' ? [ (string) $join->last_error ] : [] );
                    if ( ! empty( $progress['document_unverified'] ) ) {
                        $progress['document_checked_by'] = get_current_user_id();
                        $progress['document_checked_at'] = current_time( 'mysql' );
                    }
                    $progress['already_current']     = [];
                    $progress['checks']              = [];
                    $progress['document_unverified'] = false;
                    MyNJILGA_Join_Orders_Table::update( $id, [ 'progress' => $progress, 'last_error' => null ] );
                }
                $r = [ 'ok' => (bool) $join, 'message' => 'Marked reviewed.' ];
                break;
            default:
                $r = [ 'ok' => false, 'message' => 'Unknown action.' ];
        }

        wp_safe_redirect( add_query_arg( [ 'tab' => 'joins', 'msg' => rawurlencode( (string) $r['message'] ), 'ok' => ! empty( $r['ok'] ) ? 1 : 0 ], MyNJILGA_Admin_Menu::url( MyNJILGA_Admin_Menu::SLUG_APPLICATIONS ) ) );
        exit;
    }

    // -------------------------------------------------------------------------
    // Applications tab
    // -------------------------------------------------------------------------

    private static function render_pending(): void {
        $rows = MyNJILGA_Applications_Table::get_pending();
        MyNJILGA_Admin_UI::section( 'Pending review', '', count( $rows ) );
        if ( empty( $rows ) ) {
            MyNJILGA_Admin_UI::callout( 'No applications waiting.', 'info' );
            return;
        }

        foreach ( $rows as $app ) {
            $cat  = MyNJILGA_Dues_Settings::category( (string) $app->category_key );
            $firm = self::firm_label( $app );

            echo '<div class="njilga-reviewcard">';
            printf(
                '<div class="njilga-reviewcard-top"><div><span class="njilga-reviewcard-name">%s %s</span> &nbsp;<a href="mailto:%s">%s</a>%s<div class="njilga-reviewcard-meta">Firm: %s &nbsp;·&nbsp; Category: <strong>%s</strong>%s</div></div><div class="njilga-reviewcard-stamp">Submitted %s<br>#%d</div></div>',
                esc_html( $app->first_name ), esc_html( $app->last_name ),
                esc_attr( $app->email ), esc_html( $app->email ),
                $app->phone !== '' ? ' &nbsp;·&nbsp; ' . esc_html( $app->phone ) : '',
                $firm,
                esc_html( $cat ? $cat['label'] : $app->category_key ),
                $app->fluentcrm_contact_id ? sprintf( ' &nbsp;·&nbsp; <a href="%s" target="_blank" rel="noopener">FluentCRM contact #%d</a>', esc_url( admin_url( 'admin.php?page=fluentcrm-admin#/subscribers/' . (int) $app->fluentcrm_contact_id ) ), (int) $app->fluentcrm_contact_id ) : ' &nbsp;·&nbsp; ' . MyNJILGA_Admin_UI::status( 'no FluentCRM contact (will be created on approval)', 'bad' ),
                esc_html( (string) $app->created_at ),
                (int) $app->id
            );
            if ( ! empty( $app->message ) ) {
                printf( '<blockquote class="njilga-quote">%s</blockquote>', nl2br( esc_html( (string) $app->message ) ) );
            }
            printf(
                '<form method="post" action="%s" class="njilga-reviewform">
                    <input type="hidden" name="action" value="%s"><input type="hidden" name="app_id" value="%d">%s
                    <textarea name="note" rows="1" placeholder="Optional note to the applicant / for the record"></textarea>
                    <button type="submit" name="decision" value="approve" class="njilga-btn njilga-btn-primary">Approve</button>
                    <button type="submit" name="decision" value="reject" class="njilga-btn njilga-btn-danger-outline" onclick="return confirm(\'Reject this application?\')">Reject</button>
                 </form>',
                esc_url( admin_url( 'admin-post.php' ) ),
                esc_attr( self::ACTION_DECIDE ),
                (int) $app->id,
                wp_nonce_field( self::ACTION_DECIDE . '_' . (int) $app->id, '_wpnonce', true, false )
            );
            echo '</div>';
        }
    }

    private static function render_decided(): void {
        $rows = MyNJILGA_Applications_Table::get_decided( 50 );
        if ( empty( $rows ) ) {
            return;
        }
        MyNJILGA_Admin_UI::section( 'Recent decisions', '', count( $rows ) );
        echo '<div class="njilga-card njilga-table-boxed"><div class="njilga-tablewrap"><table class="njilga-table"><thead><tr><th>Applicant</th><th>Firm</th><th>Category</th><th>Decision</th><th>By</th><th>On</th><th>Note</th></tr></thead><tbody>';
        foreach ( $rows as $app ) {
            $cat  = MyNJILGA_Dues_Settings::category( (string) $app->category_key );
            $user = $app->decided_by ? get_user_by( 'id', (int) $app->decided_by ) : null;
            printf(
                '<tr><td>%s %s<br><span class="njilga-dim" style="font-size:12px">%s</span></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                esc_html( $app->first_name ), esc_html( $app->last_name ), esc_html( $app->email ),
                self::firm_label( $app ),
                esc_html( $cat ? $cat['label'] : $app->category_key ),
                MyNJILGA_Admin_UI::pill(
                    $app->status === MyNJILGA_Applications_Table::STATUS_SUPERSEDED ? 'Joined online' : ucfirst( $app->status ),
                    $app->status === MyNJILGA_Applications_Table::STATUS_APPROVED ? 'success' : ( $app->status === MyNJILGA_Applications_Table::STATUS_SUPERSEDED ? 'info' : 'destructive' )
                ),
                $user ? esc_html( $user->display_name ) : '—',
                esc_html( (string) $app->decided_at ),
                esc_html( (string) $app->decision_note )
            );
        }
        echo '</tbody></table></div></div>';
    }

    private static function firm_label( object $app ): string {
        if ( $app->fluentcrm_company_id && MyNJILGA_Members_Data::companies_module_active() ) {
            $c = \FluentCrm\App\Models\Company::find( (int) $app->fluentcrm_company_id );
            if ( $c ) {
                return esc_html( (string) $c->name ) . ' ' . MyNJILGA_Admin_UI::pill( 'existing', 'outline' );
            }
        }
        return esc_html( (string) $app->new_company_name ) . ' ' . MyNJILGA_Admin_UI::pill( 'new firm', 'warning' );
    }

    public static function handle_decide(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Access denied.' );
        }
        $appId = (int) ( $_POST['app_id'] ?? 0 );
        check_admin_referer( self::ACTION_DECIDE . '_' . $appId );

        $decision = sanitize_key( $_POST['decision'] ?? '' );
        $note     = sanitize_textarea_field( wp_unslash( $_POST['note'] ?? '' ) );

        if ( $decision === 'approve' ) {
            $r = MyNJILGA_Application_Review::approve( $appId, get_current_user_id(), $note );
        } elseif ( $decision === 'reject' ) {
            $r = MyNJILGA_Application_Review::reject( $appId, get_current_user_id(), $note );
        } else {
            $r = [ 'ok' => false, 'message' => 'Unknown decision.' ];
        }

        wp_safe_redirect( add_query_arg( [ 'msg' => rawurlencode( $r['message'] ), 'ok' => $r['ok'] ? 1 : 0 ], MyNJILGA_Admin_Menu::url( MyNJILGA_Admin_Menu::SLUG_APPLICATIONS ) ) );
        exit;
    }
}
