<?php
/**
 * Invoicing — annual dues invoicing by firm. Generates a per-firm roster
 * and price preview from FluentCRM Companies, lets an admin review and
 * approve it, creates the FluentCart order (invoice) for each approved
 * firm, sends the payment link to the firm's Owner, and — separately,
 * manually — runs the end-of-year downgrade sweep for anyone who never
 * paid.
 *
 * Plain server-rendered PHP forms throughout, posting to admin-post.php,
 * same as every other page in this plugin — no JS, no build step. The
 * per-firm line-item breakdown uses native <details>/<summary> for
 * expand/collapse instead of a script. The one exception is a single
 * native `onsubmit="return confirm(...)"` on the destructive downgrade
 * action, the same progressive-enhancement idiom WordPress core itself
 * uses for risky actions (e.g. "Empty Trash") — not a JS dashboard.
 */
class MyNJILGA_Page_Invoicing {

    const ACTION_PREVIEW   = 'my_njilga_dues_preview';
    const ACTION_APPROVE   = 'my_njilga_dues_approve';
    const ACTION_CREATE    = 'my_njilga_dues_create';
    const ACTION_SEND      = 'my_njilga_dues_send';
    const ACTION_DOWNGRADE = 'my_njilga_dues_downgrade';

    // -------------------------------------------------------------------------
    // Render
    // -------------------------------------------------------------------------

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Access denied.' );
        }

        MyNJILGA_Dues_Invoice_Table::maybe_upgrade();

        echo '<div class="wrap"><h1>Dues Invoicing by Firm</h1>';

        if ( MyNJILGA_Admin_Menu::require_fluentcrm() ) {
            echo '</div>';
            return;
        }
        if ( ! MyNJILGA_Members_Data::companies_module_active() ) {
            echo '<div class="notice notice-warning"><p>The FluentCRM <strong>Companies</strong> module is not active on this site. Enable it under FluentCRM → Settings → Modules.</p></div></div>';
            return;
        }

        $duesYear = self::selected_year();

        self::render_notice();
        self::render_year_selector( $duesYear );

        $counts = MyNJILGA_Dues_Invoice_Table::counts_by_status( $duesYear );
        MyNJILGA_Admin_Menu::render_stat_tiles( [
            [ 'Excluded (no Owner)', $counts[ MyNJILGA_Dues_Invoice_Table::STATUS_EXCLUDED ] ?? 0,   '#b26200' ],
            [ 'Draft',               $counts[ MyNJILGA_Dues_Invoice_Table::STATUS_DRAFT ] ?? 0,      '#646970' ],
            [ 'Approved',            $counts[ MyNJILGA_Dues_Invoice_Table::STATUS_APPROVED ] ?? 0,   '#2271b1' ],
            [ 'Created',             $counts[ MyNJILGA_Dues_Invoice_Table::STATUS_CREATED ] ?? 0,    '#2271b1' ],
            [ 'Sent',                $counts[ MyNJILGA_Dues_Invoice_Table::STATUS_SENT ] ?? 0,       '#2271b1' ],
            [ 'Paid',                $counts[ MyNJILGA_Dues_Invoice_Table::STATUS_PAID ] ?? 0,       '#1d6f42' ],
            [ 'Downgraded',          $counts[ MyNJILGA_Dues_Invoice_Table::STATUS_DOWNGRADED ] ?? 0, '#d63638' ],
        ] );

        self::render_generate_button( $duesYear );

        if ( array_sum( $counts ) === 0 ) {
            printf(
                '<p style="color:#999;font-style:italic">No invoices generated for %d yet. Click "Generate Preview" above to compute this year\'s roster and pricing from FluentCRM Companies.</p></div>',
                $duesYear
            );
            return;
        }

        if ( ! MyNJILGA_Invoice_Creator::fluentcart_active() ) {
            echo '<div class="notice notice-warning"><p><strong>FluentCart is not active.</strong> Invoices can still be previewed and approved, but "Create Invoices" needs FluentCart installed and active.</p></div>';
        }

        self::render_excluded_section( $duesYear );
        self::render_draft_section( $duesYear );
        self::render_approved_section( $duesYear );
        self::render_created_section( $duesYear );
        self::render_readonly_section( $duesYear, MyNJILGA_Dues_Invoice_Table::STATUS_SENT, 'Sent', 'sent_at' );
        self::render_readonly_section( $duesYear, MyNJILGA_Dues_Invoice_Table::STATUS_PAID, 'Paid', 'paid_at' );
        self::render_readonly_section( $duesYear, MyNJILGA_Dues_Invoice_Table::STATUS_DOWNGRADED, 'Downgraded', 'downgraded_at' );

        self::render_downgrade_sweep_section( $duesYear );

        echo '</div>';
    }

    private static function selected_year(): int {
        $year = isset( $_GET['dues_year'] ) ? (int) $_GET['dues_year'] : 0;
        return ( $year >= 2000 && $year <= 2100 ) ? $year : ( (int) gmdate( 'Y' ) + 1 );
    }

    private static function render_year_selector( int $duesYear ): void {
        printf(
            '<form method="get" action="%s" style="margin:12px 0 20px">
                <input type="hidden" name="page" value="%s">
                <label for="njilga-dues-year" style="font-weight:600;margin-right:8px">Dues Year</label>
                <input type="number" id="njilga-dues-year" name="dues_year" value="%d" min="2000" max="2100" style="width:100px">
                <button type="submit" class="button">Go</button>
             </form>',
            esc_url( admin_url( 'admin.php' ) ),
            esc_attr( MyNJILGA_Admin_Menu::SLUG_INVOICING ),
            $duesYear
        );
    }

    private static function render_generate_button( int $duesYear ): void {
        printf(
            '<form method="post" action="%s" style="margin:0 0 6px">
                <input type="hidden" name="action" value="%s">
                <input type="hidden" name="dues_year" value="%d">
                %s
                <button type="submit" class="button button-primary">Generate Preview for %d</button>
             </form>
             <p style="color:#646970;font-size:13px;margin:0 0 20px">Computes this year\'s roster and pricing from FluentCRM Companies. Safe to re-run — firms already approved (or further along) are never recomputed or overwritten.</p>',
            esc_url( admin_url( 'admin-post.php' ) ),
            esc_attr( self::ACTION_PREVIEW ),
            $duesYear,
            wp_nonce_field( self::ACTION_PREVIEW, '_wpnonce', true, false ),
            $duesYear
        );
    }

    // -------------------------------------------------------------------------
    // Sections
    // -------------------------------------------------------------------------

    private static function render_excluded_section( int $duesYear ): void {
        $rows = MyNJILGA_Dues_Invoice_Table::get_by_year( $duesYear, [ MyNJILGA_Dues_Invoice_Table::STATUS_EXCLUDED ] );
        if ( empty( $rows ) ) {
            return;
        }

        printf( '<h2 style="margin-top:28px;color:#b26200">Excluded — No Owner Assigned <span style="color:#888;font-weight:400;font-size:13px">(%d)</span></h2>', count( $rows ) );
        echo '<p style="color:#646970">These firms have FluentCRM contacts but no Company Owner set, so there\'s no bill-to contact. Assign an Owner in FluentCRM, then re-run "Generate Preview" — this plugin won\'t guess one for you.</p>';
        echo '<ul style="list-style:disc;padding-left:24px">';
        foreach ( $rows as $row ) {
            printf( '<li>%s</li>', esc_html( self::company_label( $row ) ) );
        }
        echo '</ul>';
    }

    private static function render_draft_section( int $duesYear ): void {
        $rows = MyNJILGA_Dues_Invoice_Table::get_by_year( $duesYear, [ MyNJILGA_Dues_Invoice_Table::STATUS_DRAFT ] );
        if ( empty( $rows ) ) {
            return;
        }

        printf( '<h2 style="margin-top:28px">Draft — Review &amp; Approve <span style="color:#888;font-weight:400;font-size:13px">(%d)</span></h2>', count( $rows ) );

        printf(
            '<form method="post" action="%s">
                <input type="hidden" name="action" value="%s">
                <input type="hidden" name="dues_year" value="%d">
                %s',
            esc_url( admin_url( 'admin-post.php' ) ),
            esc_attr( self::ACTION_APPROVE ),
            $duesYear,
            wp_nonce_field( self::ACTION_APPROVE, '_wpnonce', true, false )
        );

        foreach ( $rows as $row ) {
            self::render_firm_card( $row, true );
        }

        echo '<p><button type="submit" class="button button-primary">Approve Selected</button></p></form>';
    }

    private static function render_approved_section( int $duesYear ): void {
        $rows = MyNJILGA_Dues_Invoice_Table::get_by_year( $duesYear, [ MyNJILGA_Dues_Invoice_Table::STATUS_APPROVED ] );
        if ( empty( $rows ) ) {
            return;
        }

        printf( '<h2 style="margin-top:28px">Approved — Create Invoices <span style="color:#888;font-weight:400;font-size:13px">(%d)</span></h2>', count( $rows ) );

        $fluentCartActive = MyNJILGA_Invoice_Creator::fluentcart_active();

        printf(
            '<form method="post" action="%s">
                <input type="hidden" name="action" value="%s">
                <input type="hidden" name="dues_year" value="%d">
                %s',
            esc_url( admin_url( 'admin-post.php' ) ),
            esc_attr( self::ACTION_CREATE ),
            $duesYear,
            wp_nonce_field( self::ACTION_CREATE, '_wpnonce', true, false )
        );

        foreach ( $rows as $row ) {
            self::render_firm_card( $row, true );
        }

        printf(
            '<p><button type="submit" class="button button-primary"%s>Create Invoices</button></p></form>',
            $fluentCartActive ? '' : ' disabled'
        );
    }

    private static function render_created_section( int $duesYear ): void {
        $rows = MyNJILGA_Dues_Invoice_Table::get_by_year( $duesYear, [ MyNJILGA_Dues_Invoice_Table::STATUS_CREATED ] );
        if ( empty( $rows ) ) {
            return;
        }

        printf( '<h2 style="margin-top:28px">Created — Send to Owner <span style="color:#888;font-weight:400;font-size:13px">(%d)</span></h2>', count( $rows ) );

        printf(
            '<form method="post" action="%s">
                <input type="hidden" name="action" value="%s">
                <input type="hidden" name="dues_year" value="%d">
                %s',
            esc_url( admin_url( 'admin-post.php' ) ),
            esc_attr( self::ACTION_SEND ),
            $duesYear,
            wp_nonce_field( self::ACTION_SEND, '_wpnonce', true, false )
        );

        echo '<table class="widefat striped"><thead><tr><th style="width:32px"></th><th>Firm</th><th>Owner</th><th>Total</th><th>Payment Link</th></tr></thead><tbody>';
        foreach ( $rows as $row ) {
            $link = MyNJILGA_Invoice_Creator::payment_link( (string) $row->fluentcart_order_uuid );
            printf(
                '<tr><td><input type="checkbox" name="row_ids[]" value="%d"></td><td>%s</td><td>%s</td><td>$%s</td><td>%s</td></tr>',
                (int) $row->id,
                esc_html( self::company_label( $row ) ),
                esc_html( self::owner_label( $row ) ),
                number_format( $row->total_amount_cents / 100, 2 ),
                $link !== '' ? '<a href="' . esc_url( $link ) . '" target="_blank" rel="noopener">Open payment link</a>' : '<span style="color:#999">—</span>'
            );
        }
        echo '</tbody></table>';

        echo '<p style="margin-top:10px"><button type="submit" class="button button-primary">Send Selected</button></p></form>';
    }

    private static function render_readonly_section( int $duesYear, string $status, string $label, string $timestampField ): void {
        $rows = MyNJILGA_Dues_Invoice_Table::get_by_year( $duesYear, [ $status ] );
        if ( empty( $rows ) ) {
            return;
        }

        printf( '<h2 style="margin-top:28px">%s <span style="color:#888;font-weight:400;font-size:13px">(%d)</span></h2>', esc_html( $label ), count( $rows ) );
        printf( '<table class="widefat striped"><thead><tr><th>Firm</th><th>Owner</th><th>Total</th><th>%s On</th></tr></thead><tbody>', esc_html( $label ) );
        foreach ( $rows as $row ) {
            printf(
                '<tr><td>%s</td><td>%s</td><td>$%s</td><td>%s</td></tr>',
                esc_html( self::company_label( $row ) ),
                esc_html( self::owner_label( $row ) ),
                number_format( $row->total_amount_cents / 100, 2 ),
                esc_html( (string) ( $row->{$timestampField} ?? '' ) )
            );
        }
        echo '</tbody></table>';
    }

    private static function render_downgrade_sweep_section( int $duesYear ): void {
        echo '<h2 style="margin-top:36px;padding-top:20px;border-top:1px solid #dcdcde">Downgrade Sweep</h2>';
        echo '<div style="padding:14px 16px;background:#fcf0f1;border:1px solid #d63638;border-radius:4px;max-width:640px">';
        printf(
            '<p style="margin-top:0">For every %d invoice still not paid (draft, approved, created, or sent), this strips the <code>professional</code> role and tags every roster member <strong>Unpaid Dues %d</strong>. Grace periods and reminders are handled outside this plugin — run this only after that process has actually closed out the cycle.</p>',
            $duesYear,
            $duesYear
        );
        printf(
            '<form method="post" action="%s" onsubmit="return confirm(\'Run the %d downgrade sweep? This strips the professional role from every member on every still-unpaid invoice for this year.\')">
                <input type="hidden" name="action" value="%s">
                <input type="hidden" name="dues_year" value="%d">
                %s
                <button type="submit" class="button" style="border-color:#d63638;color:#d63638">Run Downgrade Sweep for %d</button>
             </form>',
            esc_url( admin_url( 'admin-post.php' ) ),
            $duesYear,
            esc_attr( self::ACTION_DOWNGRADE ),
            $duesYear,
            wp_nonce_field( self::ACTION_DOWNGRADE, '_wpnonce', true, false ),
            $duesYear
        );
        echo '</div>';
    }

    /**
     * One firm's row: a flat single line for single-member firms (the
     * math is trivial and doesn't need a breakdown table), or a
     * <details>/<summary> expandable card with the per-member line-item
     * breakdown for multi-member firms — no JS, native browser
     * disclosure widget.
     */
    private static function render_firm_card( object $row, bool $withCheckbox ): void {
        $roster  = json_decode( (string) $row->roster_snapshot, true );
        $members = $roster['members'] ?? [];
        $name    = self::company_label( $row );
        $owner   = self::owner_label( $row );
        $total   = number_format( $row->total_amount_cents / 100, 2 );

        $checkbox = $withCheckbox
            ? sprintf( '<input type="checkbox" name="row_ids[]" value="%d" style="margin-right:10px">', (int) $row->id )
            : '';

        if ( count( $members ) <= 1 ) {
            printf(
                '<div style="padding:10px 14px;border:1px solid #dcdcde;border-radius:4px;margin-bottom:6px;display:flex;align-items:center;justify-content:space-between">
                    <span>%s<strong>%s</strong>%s</span>
                    <strong>$%s</strong>
                 </div>',
                $checkbox,
                esc_html( $name ),
                $owner !== '' ? ' <span style="color:#888;font-size:12px">(Owner: ' . esc_html( $owner ) . ')</span>' : '',
                $total
            );
            return;
        }

        echo '<details style="border:1px solid #dcdcde;border-radius:4px;margin-bottom:6px;padding:10px 14px">';
        printf(
            '<summary style="cursor:pointer;display:flex;align-items:center;justify-content:space-between">
                <span>%s<strong>%s</strong> <span style="color:#888;font-size:12px">(%d members, Owner: %s)</span></span>
                <strong>$%s</strong>
             </summary>',
            $checkbox,
            esc_html( $name ),
            count( $members ),
            esc_html( $owner ),
            $total
        );

        echo '<table class="widefat striped" style="margin-top:10px"><thead><tr><th>Member</th><th>Dues</th><th>Trustee Fee</th></tr></thead><tbody>';
        foreach ( $members as $m ) {
            $duesCell = ! empty( $m['dues_exempt'] )
                ? '<span style="color:#888">Exempt</span>'
                : self::money_or_dash( (int) ( $m['tier_price_cents'] ?? 0 ) );
            printf(
                '<tr><td>%s</td><td>%s</td><td>%s</td></tr>',
                esc_html( (string) ( $m['name'] ?? '' ) ),
                $duesCell,
                self::money_or_dash( (int) ( $m['trustee_fee_cents'] ?? 0 ) )
            );
        }
        echo '</tbody></table></details>';
    }

    private static function money_or_dash( int $cents ): string {
        return $cents > 0 ? ( '$' . number_format( $cents / 100, 2 ) ) : '<span style="color:#bbb">—</span>';
    }

    private static function company_label( object $row ): string {
        $roster = json_decode( (string) $row->roster_snapshot, true );
        return (string) ( $roster['company_name'] ?? ( 'Company #' . $row->fluentcrm_company_id ) );
    }

    private static function owner_label( object $row ): string {
        $roster = json_decode( (string) $row->roster_snapshot, true );
        return (string) ( $roster['owner_name'] ?? '' );
    }

    // -------------------------------------------------------------------------
    // Notices
    // -------------------------------------------------------------------------

    private static function render_notice(): void {
        $msg = isset( $_GET['msg'] ) ? sanitize_key( $_GET['msg'] ) : '';
        if ( $msg === '' ) {
            return;
        }

        $classes = [
            'previewed'       => 'notice-success',
            'approved'        => 'notice-success',
            'created'         => 'notice-success',
            'created_partial' => 'notice-warning',
            'sent'            => 'notice-success',
            'sent_partial'    => 'notice-warning',
            'downgraded'      => 'notice-success',
            'error'           => 'notice-error',
        ];
        $text = self::notice_text( $msg );
        if ( $text === '' ) {
            return;
        }

        printf( '<div class="notice %s is-dismissible"><p>%s</p></div>', esc_attr( $classes[ $msg ] ?? 'notice-info' ), esc_html( $text ) );

        $key    = 'njilga_dues_errors_' . get_current_user_id();
        $errors = get_transient( $key );
        if ( $errors ) {
            delete_transient( $key );
            echo '<div class="notice notice-error"><p><strong>Details:</strong></p><ul style="list-style:disc;padding-left:24px">';
            foreach ( (array) $errors as $line ) {
                printf( '<li>%s</li>', esc_html( (string) $line ) );
            }
            echo '</ul></div>';
        }
    }

    private static function notice_text( string $msg ): string {
        $count   = isset( $_GET['count'] ) ? (int) $_GET['count'] : 0;
        $fail    = isset( $_GET['fail'] ) ? (int) $_GET['fail'] : 0;
        $firms   = isset( $_GET['firms'] ) ? (int) $_GET['firms'] : 0;
        $members = isset( $_GET['members'] ) ? (int) $_GET['members'] : 0;

        switch ( $msg ) {
            case 'previewed':
                return 'Preview generated.';
            case 'approved':
                return sprintf( '%d firm%s approved.', $count, $count === 1 ? '' : 's' );
            case 'created':
                return sprintf( '%d invoice%s created.', $count, $count === 1 ? '' : 's' );
            case 'created_partial':
                return sprintf( '%d invoice%s created, %d failed — see details below.', $count, $count === 1 ? '' : 's', $fail );
            case 'sent':
                return sprintf( '%d invoice%s sent.', $count, $count === 1 ? '' : 's' );
            case 'sent_partial':
                return sprintf( '%d invoice%s sent, %d failed — see details below.', $count, $count === 1 ? '' : 's', $fail );
            case 'downgraded':
                return sprintf( '%d firm%s swept — %d member%s downgraded.', $firms, $firms === 1 ? '' : 's', $members, $members === 1 ? '' : 's' );
            case 'error':
                return isset( $_GET['detail'] ) ? sanitize_text_field( wp_unslash( $_GET['detail'] ) ) : 'Something went wrong.';
            default:
                return '';
        }
    }

    // -------------------------------------------------------------------------
    // admin-post handlers
    // -------------------------------------------------------------------------

    public static function handle_preview(): void {
        self::guard( self::ACTION_PREVIEW );
        $duesYear = self::post_year();

        MyNJILGA_Dues_Preview::generate_and_persist( $duesYear );

        self::redirect( $duesYear, [ 'msg' => 'previewed' ] );
    }

    public static function handle_approve(): void {
        self::guard( self::ACTION_APPROVE );
        $duesYear = self::post_year();
        $ids      = self::post_ids();

        MyNJILGA_Dues_Invoice_Table::mark_approved( $ids );

        self::redirect( $duesYear, [ 'msg' => 'approved', 'count' => count( $ids ) ] );
    }

    public static function handle_create(): void {
        self::guard( self::ACTION_CREATE );
        $duesYear = self::post_year();
        $ids      = self::post_ids();

        $ok = 0; $fail = 0; $errors = [];
        foreach ( $ids as $id ) {
            $row = MyNJILGA_Dues_Invoice_Table::get( $id );
            if ( ! $row || $row->status !== MyNJILGA_Dues_Invoice_Table::STATUS_APPROVED ) {
                continue; // Stale checkbox / already processed elsewhere — skip quietly.
            }
            $result = MyNJILGA_Invoice_Creator::create_for_row( $row );
            if ( $result['ok'] ) {
                $ok++;
            } else {
                $fail++;
                $errors[] = self::company_label( $row ) . ': ' . $result['error'];
            }
        }

        self::store_errors( $errors );
        self::redirect( $duesYear, [ 'msg' => $fail > 0 ? 'created_partial' : 'created', 'count' => $ok, 'fail' => $fail ] );
    }

    public static function handle_send(): void {
        self::guard( self::ACTION_SEND );
        $duesYear = self::post_year();
        $ids      = self::post_ids();

        $ok = 0; $fail = 0; $errors = [];
        foreach ( $ids as $id ) {
            $row = MyNJILGA_Dues_Invoice_Table::get( $id );
            if ( ! $row || $row->status !== MyNJILGA_Dues_Invoice_Table::STATUS_CREATED ) {
                continue;
            }
            $result = MyNJILGA_Invoice_Sender::send_for_row( $row );
            if ( $result['ok'] ) {
                $ok++;
            } else {
                $fail++;
                $errors[] = self::company_label( $row ) . ': ' . $result['error'];
            }
        }

        self::store_errors( $errors );
        self::redirect( $duesYear, [ 'msg' => $fail > 0 ? 'sent_partial' : 'sent', 'count' => $ok, 'fail' => $fail ] );
    }

    public static function handle_downgrade(): void {
        self::guard( self::ACTION_DOWNGRADE );
        $duesYear = self::post_year();

        $result = MyNJILGA_Downgrade_Sweep::run( $duesYear );

        self::redirect( $duesYear, [ 'msg' => 'downgraded', 'firms' => $result['firms_swept'], 'members' => $result['members_downgraded'] ] );
    }

    // -------------------------------------------------------------------------
    // Handler helpers
    // -------------------------------------------------------------------------

    private static function guard( string $action ): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Access denied.' );
        }
        check_admin_referer( $action );
    }

    private static function post_year(): int {
        $year = isset( $_POST['dues_year'] ) ? (int) $_POST['dues_year'] : 0;
        return ( $year >= 2000 && $year <= 2100 ) ? $year : ( (int) gmdate( 'Y' ) + 1 );
    }

    /**
     * @return array<int,int>
     */
    private static function post_ids(): array {
        $ids = ( isset( $_POST['row_ids'] ) && is_array( $_POST['row_ids'] ) ) ? $_POST['row_ids'] : [];
        return array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
    }

    private static function store_errors( array $errors ): void {
        if ( empty( $errors ) ) {
            return;
        }
        set_transient( 'njilga_dues_errors_' . get_current_user_id(), $errors, 60 );
    }

    private static function redirect( int $duesYear, array $args ): void {
        $args['page']      = MyNJILGA_Admin_Menu::SLUG_INVOICING;
        $args['dues_year'] = $duesYear;
        wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
        exit;
    }
}
