<?php
/**
 * Invoicing — annual dues invoicing by firm (spec §7). Generates the
 * per-firm roster/price preview across every FluentCRM Company, lets an
 * admin review and approve it, queues invoice creation through the
 * gateway, sends payment links, and — behind a confirmation screen — runs
 * the end-of-year downgrade sweep.
 *
 * Plain server-rendered PHP forms posting to admin-post.php, same as the
 * rest of the plugin — no JS, no build step. Multi-member firms render as
 * native <details> cards; single-member firms as a flat line.
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

        $duesYear = self::selected_year();
        $view     = isset( $_GET['view'] ) ? sanitize_key( $_GET['view'] ) : '';

        echo '<div class="wrap"><h1>Dues Invoicing by Firm</h1>';

        if ( MyNJILGA_Admin_Menu::require_fluentcrm() ) {
            echo '</div>';
            return;
        }
        if ( ! MyNJILGA_Members_Data::companies_module_active() ) {
            echo '<div class="notice notice-warning"><p>The FluentCRM <strong>Companies</strong> module is not active on this site. Enable it under FluentCRM → Settings → Modules.</p></div></div>';
            return;
        }

        if ( $view === 'downgrade' ) {
            self::render_downgrade_confirm( $duesYear );
            echo '</div>';
            return;
        }

        self::render_notice();
        self::render_year_selector( $duesYear );
        self::render_gateway_notices();

        $counts = MyNJILGA_Dues_Invoice_Table::counts_by_status( $duesYear );
        $totals = MyNJILGA_Dues_Invoice_Table::totals_by_status( $duesYear );

        self::render_batch_totals( $duesYear, $counts, $totals );
        self::render_generate_button( $duesYear );

        if ( array_sum( $counts ) === 0 ) {
            printf(
                '<p style="color:#999;font-style:italic">No invoices generated for %d yet. Click "Generate Preview" above to compute this year\'s roster and pricing from FluentCRM Companies using the <a href="%s">Dues &amp; Billing settings</a>.</p></div>',
                $duesYear,
                esc_url( MyNJILGA_Admin_Menu::url( MyNJILGA_Admin_Menu::SLUG_SETTINGS ) )
            );
            return;
        }

        if ( MyNJILGA_Invoice_Creator::has_pending_jobs() ) {
            echo '<div class="notice notice-info"><p><strong>Invoices are being created in the background.</strong> Reload this page in a moment to see them move from Approved to Created. Failures are recorded on each row.</p></div>';
        }

        self::render_errors_section( $duesYear );
        self::render_excluded_section( $duesYear );
        self::render_draft_section( $duesYear );
        self::render_approved_section( $duesYear );
        self::render_created_section( $duesYear );
        self::render_readonly_section( $duesYear, MyNJILGA_Dues_Invoice_Table::STATUS_SENT, 'Sent', 'sent_at' );
        self::render_readonly_section( $duesYear, MyNJILGA_Dues_Invoice_Table::STATUS_PAID, 'Paid', 'paid_at' );
        self::render_readonly_section( $duesYear, MyNJILGA_Dues_Invoice_Table::STATUS_DOWNGRADED, 'Downgraded', 'downgraded_at' );

        self::render_downgrade_entry( $duesYear );

        echo '</div>';
    }

    private static function selected_year(): int {
        $year = isset( $_GET['dues_year'] ) ? (int) $_GET['dues_year'] : 0;
        return ( $year >= 2000 && $year <= 2100 ) ? $year : MyNJILGA_Invoicing::default_dues_year();
    }

    private static function render_year_selector( int $duesYear ): void {
        $years = MyNJILGA_Dues_Invoice_Table::years();
        $links = [];
        foreach ( $years as $y ) {
            $links[] = $y === $duesYear
                ? sprintf( '<strong>%d</strong>', $y )
                : sprintf( '<a href="%s">%d</a>', esc_url( self::page_url( $y ) ), $y );
        }
        printf(
            '<form method="get" action="%s" style="margin:12px 0 20px;display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                <input type="hidden" name="page" value="%s">
                <label for="njilga-dues-year" style="font-weight:600">Dues Year</label>
                <input type="number" id="njilga-dues-year" name="dues_year" value="%d" min="2000" max="2100" style="width:100px">
                <button type="submit" class="button">Go</button>
                %s
             </form>',
            esc_url( admin_url( 'admin.php' ) ),
            esc_attr( MyNJILGA_Admin_Menu::SLUG_INVOICING ),
            $duesYear,
            $links ? '<span style="color:#646970;font-size:13px">Years with data: ' . implode( ' · ', $links ) . '</span>' : ''
        );
    }

    private static function render_gateway_notices(): void {
        $gateway = MyNJILGA_Invoicing::gateway();
        if ( ! $gateway->is_available() ) {
            printf( '<div class="notice notice-warning"><p><strong>%s is not active.</strong> Invoices can still be previewed and approved, but "Create Invoices" needs it installed and active.</p></div>', esc_html( $gateway->name() ) );
            return;
        }
        foreach ( $gateway->readiness_errors() as $err ) {
            printf( '<div class="notice notice-warning"><p><strong>%s isn\'t ready to create invoices:</strong> %s</p></div>', esc_html( $gateway->name() ), esc_html( $err ) );
        }
    }

    /**
     * Batch-level numbers, up front: what the year is worth, what's in,
     * what's outstanding, and how many exceptions need a human.
     *
     * @param array<string,int> $counts
     * @param array<string,int> $totals
     */
    private static function render_batch_totals( int $duesYear, array $counts, array $totals ): void {
        $t = static function ( array $statuses ) use ( $totals ) {
            $sum = 0;
            foreach ( $statuses as $s ) {
                $sum += $totals[ $s ] ?? 0;
            }
            return $sum;
        };
        $c = static function ( array $statuses ) use ( $counts ) {
            $sum = 0;
            foreach ( $statuses as $s ) {
                $sum += $counts[ $s ] ?? 0;
            }
            return $sum;
        };
        $T = MyNJILGA_Dues_Invoice_Table::class;

        $live = [ $T::STATUS_DRAFT, $T::STATUS_APPROVED, $T::STATUS_CREATED, $T::STATUS_SENT, $T::STATUS_PAID, $T::STATUS_DOWNGRADED ];

        // Distinct firms & members covered — needs the snapshots.
        $firms = []; $members = 0;
        foreach ( MyNJILGA_Dues_Invoice_Table::get_by_year( $duesYear, $live ) as $row ) {
            $firms[ (int) $row->fluentcrm_company_id ] = true;
            if ( MyNJILGA_Dues_Snapshot::settles_dues( $row ) ) {
                $members += count( MyNJILGA_Dues_Snapshot::members( $row ) );
            }
        }

        printf( '<h2 style="margin:8px 0 0">%d batch</h2>', $duesYear );
        self::render_money_tiles( [
            [ 'Batch total',          $t( $live ),                                              '#1d2327' ],
            [ 'Collected (paid)',     $t( [ $T::STATUS_PAID ] ),                                '#1d6f42' ],
            [ 'Outstanding (sent)',   $t( [ $T::STATUS_CREATED, $T::STATUS_SENT ] ),            '#2271b1' ],
            [ 'Awaiting approval',    $t( [ $T::STATUS_DRAFT, $T::STATUS_APPROVED ] ),          '#646970' ],
            [ 'Never paid (swept)',   $t( [ $T::STATUS_DOWNGRADED ] ),                          '#d63638' ],
        ] );

        MyNJILGA_Admin_Menu::render_stat_tiles( [
            [ 'Firms in batch',      count( $firms ),                          '#1d2327' ],
            [ 'Members covered',     $members,                                 '#1d2327' ],
            [ 'Exceptions',          $c( [ $T::STATUS_EXCLUDED ] ),            '#b26200' ],
            [ 'Draft',               $c( [ $T::STATUS_DRAFT ] ),               '#646970' ],
            [ 'Approved',            $c( [ $T::STATUS_APPROVED ] ),            '#2271b1' ],
            [ 'Created',             $c( [ $T::STATUS_CREATED ] ),             '#2271b1' ],
            [ 'Sent',                $c( [ $T::STATUS_SENT ] ),                '#2271b1' ],
            [ 'Paid',                $c( [ $T::STATUS_PAID ] ),                '#1d6f42' ],
            [ 'Downgraded',          $c( [ $T::STATUS_DOWNGRADED ] ),          '#d63638' ],
        ] );
    }

    /**
     * @param array<int,array{0:string,1:int,2:string}> $tiles [label, cents, colour]
     */
    private static function render_money_tiles( array $tiles ): void {
        echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin:12px 0 4px">';
        foreach ( $tiles as $tile ) {
            printf(
                '<div style="padding:14px 16px;background:#fff;border:1px solid #c3c4c7;border-left:4px solid %s;border-radius:4px">
                    <div style="font-size:24px;font-weight:600;line-height:1.1">%s</div>
                    <div style="color:#646970;font-size:13px">%s</div>
                 </div>',
                esc_attr( $tile[2] ),
                esc_html( MyNJILGA_Invoicing::money( (int) $tile[1] ) ),
                esc_html( $tile[0] )
            );
        }
        echo '</div>';
    }

    private static function render_generate_button( int $duesYear ): void {
        printf(
            '<form method="post" action="%s" style="margin:12px 0 6px">
                <input type="hidden" name="action" value="%s">
                <input type="hidden" name="dues_year" value="%d">
                %s
                <button type="submit" class="button button-primary">Generate Preview for %d</button>
             </form>
             <p style="color:#646970;font-size:13px;margin:0 0 20px">Runs the pricing engine across every FluentCRM Company with the current <a href="%s">Dues &amp; Billing settings</a>. Safe to re-run — firms already approved (or further along) are never recomputed or overwritten.</p>',
            esc_url( admin_url( 'admin-post.php' ) ),
            esc_attr( self::ACTION_PREVIEW ),
            $duesYear,
            wp_nonce_field( self::ACTION_PREVIEW, '_wpnonce', true, false ),
            $duesYear,
            esc_url( MyNJILGA_Admin_Menu::url( MyNJILGA_Admin_Menu::SLUG_SETTINGS ) )
        );
    }

    // -------------------------------------------------------------------------
    // Sections
    // -------------------------------------------------------------------------

    private static function render_errors_section( int $duesYear ): void {
        $rows = MyNJILGA_Dues_Invoice_Table::get_with_errors( $duesYear );
        if ( empty( $rows ) ) {
            return;
        }
        printf( '<h2 style="margin-top:28px;color:#d63638">Needs attention <span style="color:#888;font-weight:400;font-size:13px">(%d)</span></h2>', count( $rows ) );
        echo '<p style="color:#646970">These rows hit an error on their last step. Fix the cause (usually a missing email, a disabled payment method, or an unpublished product), then re-run the step — the row is still selectable in its section below.</p>';
        echo '<table class="widefat striped"><thead><tr><th>Firm</th><th>Bill to</th><th>Status</th><th>Error</th></tr></thead><tbody>';
        foreach ( $rows as $row ) {
            printf(
                '<tr><td>%s</td><td>%s</td><td><code>%s</code></td><td style="color:#d63638">%s</td></tr>',
                esc_html( MyNJILGA_Dues_Snapshot::company_name( $row ) ),
                esc_html( self::bill_to_label( $row ) ),
                esc_html( $row->status ),
                esc_html( (string) $row->last_error )
            );
        }
        echo '</tbody></table>';
    }

    private static function render_excluded_section( int $duesYear ): void {
        $rows = MyNJILGA_Dues_Invoice_Table::get_by_year( $duesYear, [ MyNJILGA_Dues_Invoice_Table::STATUS_EXCLUDED ] );
        if ( empty( $rows ) ) {
            return;
        }

        $groups = [
            MyNJILGA_Dues_Preview::EXCLUDED_NO_OWNER   => [ 'title' => 'No Owner assigned', 'help' => 'These firms have contacts but no Company Owner, so there\'s no bill-to contact. Assign an Owner in FluentCRM (with an email), then re-run "Generate Preview" — this plugin won\'t guess one for you. The roster below is what would be billed.', 'rows' => [] ],
            MyNJILGA_Dues_Preview::EXCLUDED_NO_MEMBERS => [ 'title' => 'No members',        'help' => 'These FluentCRM Companies have no attached contacts. Nothing to bill; listed so nobody wonders where the firm went.', 'rows' => [] ],
            MyNJILGA_Dues_Preview::EXCLUDED_ZERO_TOTAL => [ 'title' => 'Nothing to bill',   'help' => 'Every member owes $0 this year (all exempt/comped, inactive, or uncategorised). No invoice is created — a $0 order would be auto-settled by the store.', 'rows' => [] ],
        ];
        foreach ( $rows as $row ) {
            $reason = (string) ( MyNJILGA_Dues_Snapshot::decode( $row )['exclusion_reason'] ?? MyNJILGA_Dues_Preview::EXCLUDED_NO_OWNER );
            if ( ! isset( $groups[ $reason ] ) ) {
                $reason = MyNJILGA_Dues_Preview::EXCLUDED_NO_OWNER;
            }
            $groups[ $reason ]['rows'][] = $row;
        }

        printf( '<h2 style="margin-top:28px;color:#b26200">Exceptions <span style="color:#888;font-weight:400;font-size:13px">(%d)</span></h2>', count( $rows ) );
        foreach ( $groups as $g ) {
            if ( empty( $g['rows'] ) ) {
                continue;
            }
            printf( '<h3 style="margin:16px 0 4px">%s <span style="color:#888;font-weight:400;font-size:13px">(%d)</span></h3><p style="color:#646970;margin-top:0">%s</p>', esc_html( $g['title'] ), count( $g['rows'] ), esc_html( $g['help'] ) );
            foreach ( $g['rows'] as $row ) {
                if ( empty( MyNJILGA_Dues_Snapshot::members( $row ) ) ) {
                    printf( '<div style="padding:8px 14px;border:1px solid #dcdcde;border-radius:4px;margin-bottom:6px"><strong>%s</strong></div>', esc_html( MyNJILGA_Dues_Snapshot::company_name( $row ) ) );
                } else {
                    self::render_firm_card( $row, false );
                }
            }
        }
    }

    private static function render_draft_section( int $duesYear ): void {
        $rows = MyNJILGA_Dues_Invoice_Table::get_by_year( $duesYear, [ MyNJILGA_Dues_Invoice_Table::STATUS_DRAFT ] );
        if ( empty( $rows ) ) {
            return;
        }

        printf( '<h2 style="margin-top:28px">Draft — Review &amp; Approve <span style="color:#888;font-weight:400;font-size:13px">(%d)</span></h2>', count( $rows ) );
        echo '<p style="color:#646970">Approving freezes the roster and price. No invoice is created yet.</p>';

        self::open_form( self::ACTION_APPROVE, $duesYear );
        foreach ( $rows as $row ) {
            self::render_firm_card( $row, true );
        }
        printf(
            '<p><button type="submit" class="button button-primary">Approve Selected</button> <button type="submit" name="all" value="1" class="button">Approve All Drafts (%d)</button></p></form>',
            count( $rows )
        );
    }

    private static function render_approved_section( int $duesYear ): void {
        $rows = MyNJILGA_Dues_Invoice_Table::get_by_year( $duesYear, [ MyNJILGA_Dues_Invoice_Table::STATUS_APPROVED ] );
        if ( empty( $rows ) ) {
            return;
        }

        $gateway   = MyNJILGA_Invoicing::gateway();
        $canCreate = $gateway->is_available() && empty( $gateway->readiness_errors() );
        $batch     = (int) MyNJILGA_Dues_Settings::general( 'batch_size', 25 );

        printf( '<h2 style="margin-top:28px">Approved — Create Invoices <span style="color:#888;font-weight:400;font-size:13px">(%d)</span></h2>', count( $rows ) );
        printf(
            '<p style="color:#646970">Creates one %s order per row, in background batches of %d. One failed firm never blocks the rest — it\'s recorded on its row and stays here for a retry.</p>',
            esc_html( $gateway->name() ),
            $batch
        );

        self::open_form( self::ACTION_CREATE, $duesYear );
        foreach ( $rows as $row ) {
            self::render_firm_card( $row, true );
        }
        printf(
            '<p><button type="submit" class="button button-primary"%1$s>Create Selected</button> <button type="submit" name="all" value="1" class="button"%1$s>Create All Approved (%2$d)</button></p></form>',
            $canCreate ? '' : ' disabled',
            count( $rows )
        );
    }

    private static function render_created_section( int $duesYear ): void {
        $rows = MyNJILGA_Dues_Invoice_Table::get_by_year( $duesYear, [ MyNJILGA_Dues_Invoice_Table::STATUS_CREATED ] );
        if ( empty( $rows ) ) {
            return;
        }

        $ccMode = (string) MyNJILGA_Dues_Settings::general( 'send_cc_mode', MyNJILGA_Dues_Settings::CC_OWNER_ONLY );
        $ccText = MyNJILGA_Dues_Settings::cc_mode_labels()[ $ccMode ] ?? $ccMode;

        printf( '<h2 style="margin-top:28px">Created — Send <span style="color:#888;font-weight:400;font-size:13px">(%d)</span></h2>', count( $rows ) );
        printf( '<p style="color:#646970">Emails the payment link. Recipients: <em>%s</em> (change under Settings → Dues &amp; Billing). Anyone with the link can pay.</p>', esc_html( $ccText ) );

        self::open_form( self::ACTION_SEND, $duesYear );
        echo '<table class="widefat striped"><thead><tr><th style="width:32px"></th><th>Firm</th><th>Invoice</th><th>Bill to</th><th>Total</th><th>Payment Link</th></tr></thead><tbody>';
        foreach ( $rows as $row ) {
            $link = MyNJILGA_Invoice_Creator::payment_link( (string) $row->fluentcart_order_uuid );
            printf(
                '<tr><td><input type="checkbox" name="row_ids[]" value="%d"></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                (int) $row->id,
                esc_html( MyNJILGA_Dues_Snapshot::company_name( $row ) ),
                self::kind_badge( $row ) . ' <span style="color:#888">#' . (int) $row->fluentcart_order_id . '</span>',
                esc_html( self::bill_to_label( $row ) ),
                esc_html( MyNJILGA_Invoicing::money( (int) $row->total_amount_cents ) ),
                $link !== '' ? '<a href="' . esc_url( $link ) . '" target="_blank" rel="noopener">Open payment link</a>' : '<span style="color:#999">—</span>'
            );
        }
        echo '</tbody></table>';
        printf(
            '<p style="margin-top:10px"><button type="submit" class="button button-primary">Send Selected</button> <button type="submit" name="all" value="1" class="button">Send All Created (%d)</button></p></form>',
            count( $rows )
        );
    }

    private static function render_readonly_section( int $duesYear, string $status, string $label, string $timestampField ): void {
        $rows = MyNJILGA_Dues_Invoice_Table::get_by_year( $duesYear, [ $status ] );
        if ( empty( $rows ) ) {
            return;
        }

        printf( '<h2 style="margin-top:28px">%s <span style="color:#888;font-weight:400;font-size:13px">(%d)</span></h2>', esc_html( $label ), count( $rows ) );
        printf( '<table class="widefat striped"><thead><tr><th>Firm</th><th>Invoice</th><th>Bill to</th><th>Members</th><th>Total</th><th>%s On</th>%s</tr></thead><tbody>', esc_html( $label ), $status === MyNJILGA_Dues_Invoice_Table::STATUS_SENT ? '<th>Payment Link</th>' : '' );
        foreach ( $rows as $row ) {
            $extra = '';
            if ( $status === MyNJILGA_Dues_Invoice_Table::STATUS_SENT ) {
                $link  = MyNJILGA_Invoice_Creator::payment_link( (string) $row->fluentcart_order_uuid );
                $extra = '<td>' . ( $link !== '' ? '<a href="' . esc_url( $link ) . '" target="_blank" rel="noopener">Open</a>' : '—' ) . '</td>';
            }
            printf(
                '<tr><td>%s</td><td>%s</td><td>%s</td><td>%d</td><td>%s</td><td>%s</td>%s</tr>',
                esc_html( MyNJILGA_Dues_Snapshot::company_name( $row ) ),
                self::kind_badge( $row ) . ( $row->fluentcart_order_id ? ' <span style="color:#888">#' . (int) $row->fluentcart_order_id . '</span>' : '' ),
                esc_html( self::bill_to_label( $row ) ),
                count( MyNJILGA_Dues_Snapshot::members( $row ) ),
                esc_html( MyNJILGA_Invoicing::money( (int) $row->total_amount_cents ) ),
                esc_html( (string) ( $row->{$timestampField} ?? '' ) ),
                $extra
            );
        }
        echo '</tbody></table>';
    }

    private static function render_downgrade_entry( int $duesYear ): void {
        $p = MyNJILGA_Downgrade_Sweep::preview( $duesYear );
        echo '<h2 style="margin-top:36px;padding-top:20px;border-top:1px solid #dcdcde">Downgrade Sweep</h2>';
        echo '<div style="padding:14px 16px;background:#fcf0f1;border:1px solid #d63638;border-radius:4px;max-width:720px">';
        printf(
            '<p style="margin-top:0">For every %d dues invoice still not paid, this tags every roster member <strong>%s</strong>%s. Right now that would affect <strong>%d invoice%s</strong> across <strong>%d firm%s</strong> — <strong>%d member%s</strong>. Grace periods and reminders are handled outside this plugin; run this only after that process has closed out the cycle.</p>',
            $duesYear,
            esc_html( MyNJILGA_Dues_Settings::year_tag( 'year_unpaid_tag_pattern', $duesYear ) ),
            $p['remove_roles'] ? ' and removes their WordPress membership role' : '',
            $p['invoices'], $p['invoices'] === 1 ? '' : 's',
            $p['firms'], $p['firms'] === 1 ? '' : 's',
            $p['members'], $p['members'] === 1 ? '' : 's'
        );
        printf(
            '<a class="button" style="border-color:#d63638;color:#d63638" href="%s">Review &amp; run the %d sweep →</a>',
            esc_url( self::page_url( $duesYear, [ 'view' => 'downgrade' ] ) ),
            $duesYear
        );
        echo '</div>';
    }

    /**
     * The confirmation screen (spec §7 step 6): exactly what will happen,
     * to whom, before the button.
     */
    private static function render_downgrade_confirm( int $duesYear ): void {
        $p = MyNJILGA_Downgrade_Sweep::preview( $duesYear );

        printf( '<p style="margin:4px 0 12px"><a href="%s" style="text-decoration:none">&larr; Back to %d invoicing</a></p>', esc_url( self::page_url( $duesYear ) ), $duesYear );
        printf( '<h2 style="color:#d63638">Confirm the %d downgrade sweep</h2>', $duesYear );

        MyNJILGA_Admin_Menu::render_stat_tiles( [
            [ 'Unpaid invoices',      $p['invoices'],  '#d63638' ],
            [ 'Firms affected',       $p['firms'],     '#d63638' ],
            [ 'Members downgraded',   $p['members'],   '#d63638' ],
            [ 'Protected (paid elsewhere)', $p['protected'], '#1d6f42' ],
        ] );

        echo '<div style="padding:14px 16px;background:#fcf0f1;border:1px solid #d63638;border-radius:4px;max-width:760px;margin-bottom:16px"><p style="margin:0 0 8px"><strong>What will happen to each member listed below:</strong></p><ul style="margin:0 0 0 20px;list-style:disc">';
        printf( '<li>Tagged <code>%s</code> and <code>%s</code>; <code>%s</code> removed.</li>', esc_html( MyNJILGA_Dues_Settings::year_tag( 'year_unpaid_tag_pattern', $duesYear ) ), esc_html( (string) MyNJILGA_Dues_Settings::general( 'unpaid_tag' ) ), esc_html( (string) MyNJILGA_Dues_Settings::general( 'paid_tag' ) ) );
        echo $p['remove_roles']
            ? '<li>Their category\'s WordPress role is removed where they have a linked account (setting: <em>Remove roles on downgrade</em> is on).</li>'
            : '<li>WordPress roles are <em>not</em> touched (setting: <em>Remove roles on downgrade</em> is off).</li>';
        echo '<li>Each invoice is marked <code>downgraded</code> and a note is left on the firm\'s FluentCRM Company record.</li>';
        echo '<li>Members also covered by a <em>paid</em> invoice for this year are skipped.</li>';
        echo '</ul></div>';

        if ( $p['invoices'] === 0 ) {
            echo '<p><strong>Nothing to sweep</strong> — every dues invoice for this year is paid, already downgraded, or excluded.</p>';
            return;
        }

        echo '<table class="widefat striped" style="max-width:960px"><thead><tr><th>Firm</th><th>Invoice</th><th>Status</th><th>Bill to</th><th>Members</th><th>Total</th></tr></thead><tbody>';
        foreach ( $p['rows'] as $row ) {
            $names = array_map( static function ( $m ) { return (string) ( $m['name'] ?? '' ); }, MyNJILGA_Dues_Snapshot::members( $row ) );
            printf(
                '<tr><td>%s</td><td>%s</td><td><code>%s</code></td><td>%s</td><td>%s</td><td>%s</td></tr>',
                esc_html( MyNJILGA_Dues_Snapshot::company_name( $row ) ),
                self::kind_badge( $row ),
                esc_html( $row->status ),
                esc_html( self::bill_to_label( $row ) ),
                esc_html( implode( ', ', $names ) ),
                esc_html( MyNJILGA_Invoicing::money( (int) $row->total_amount_cents ) )
            );
        }
        echo '</tbody></table>';

        printf(
            '<form method="post" action="%s" style="margin-top:16px">
                <input type="hidden" name="action" value="%s">
                <input type="hidden" name="dues_year" value="%d">
                <input type="hidden" name="confirmed" value="1">
                %s
                <label style="display:block;margin-bottom:10px"><input type="checkbox" name="acknowledge" value="1" required> I\'ve reviewed the list above and the %d billing cycle is closed.</label>
                <button type="submit" class="button button-primary" style="background:#d63638;border-color:#d63638">Run Downgrade Sweep — %d invoice%s, %d member%s</button>
                <a class="button" href="%s" style="margin-left:8px">Cancel</a>
             </form>',
            esc_url( admin_url( 'admin-post.php' ) ),
            esc_attr( self::ACTION_DOWNGRADE ),
            $duesYear,
            wp_nonce_field( self::ACTION_DOWNGRADE, '_wpnonce', true, false ),
            $duesYear,
            $p['invoices'], $p['invoices'] === 1 ? '' : 's',
            $p['members'], $p['members'] === 1 ? '' : 's',
            esc_url( self::page_url( $duesYear ) )
        );
    }

    // -------------------------------------------------------------------------
    // Cards
    // -------------------------------------------------------------------------

    /**
     * One invoice row: a flat line for single-member rows, a <details>
     * card with the per-member breakdown otherwise.
     */
    private static function render_firm_card( object $row, bool $withCheckbox ): void {
        $snapshot = MyNJILGA_Dues_Snapshot::decode( $row );
        $members  = $snapshot['members'];
        $name     = MyNJILGA_Dues_Snapshot::company_name( $row );
        $total    = MyNJILGA_Invoicing::money( (int) $row->total_amount_cents );
        $badges   = self::card_badges( $row, $snapshot );

        $checkbox = $withCheckbox
            ? sprintf( '<input type="checkbox" name="row_ids[]" value="%d" style="margin-right:10px">', (int) $row->id )
            : '';

        $errorLine = ! empty( $row->last_error )
            ? sprintf( '<div style="color:#d63638;font-size:12px;margin-top:6px">⚠ %s</div>', esc_html( (string) $row->last_error ) )
            : '';
        $notes = '';
        foreach ( (array) ( $snapshot['notes'] ?? [] ) as $n ) {
            $notes .= sprintf( '<div style="color:#b26200;font-size:12px;margin-top:4px">%s</div>', esc_html( (string) $n ) );
        }

        if ( count( $members ) <= 1 ) {
            $solo = $members[0] ?? [];
            printf(
                '<div style="padding:10px 14px;border:1px solid #dcdcde;border-radius:4px;margin-bottom:6px">
                    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
                        <span>%s<strong>%s</strong> %s <span style="color:#888;font-size:12px">%s</span></span>
                        <strong>%s</strong>
                    </div>%s%s
                 </div>',
                $checkbox,
                esc_html( $name ),
                $badges,
                esc_html( self::solo_summary( $solo, $snapshot ) ),
                esc_html( $total ),
                $notes,
                $errorLine
            );
            return;
        }

        echo '<details style="border:1px solid #dcdcde;border-radius:4px;margin-bottom:6px;padding:10px 14px">';
        printf(
            '<summary style="cursor:pointer;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
                <span>%s<strong>%s</strong> %s <span style="color:#888;font-size:12px">(%d members · bill to %s)</span></span>
                <strong>%s</strong>
             </summary>',
            $checkbox,
            esc_html( $name ),
            $badges,
            count( $members ),
            esc_html( self::bill_to_label( $row ) ),
            esc_html( $total )
        );
        echo $notes . $errorLine;

        echo '<table class="widefat striped" style="margin-top:10px"><thead><tr><th>Member</th><th>Category</th><th>Dues</th><th>Assessment</th></tr></thead><tbody>';
        foreach ( $members as $m ) {
            if ( ! empty( $m['unbilled_reason'] ) ) {
                printf(
                    '<tr><td>%s</td><td>%s</td><td colspan="2" style="color:#888">%s — not billed</td></tr>',
                    esc_html( (string) ( $m['name'] ?? '' ) ),
                    esc_html( (string) ( $m['category_label'] ?: '—' ) ),
                    esc_html( ucfirst( (string) $m['unbilled_reason'] ) )
                );
                continue;
            }
            $tier = ! empty( $m['tier_label'] ) ? ' <span style="color:#888;font-size:12px">(' . esc_html( (string) $m['tier_label'] ) . ')</span>' : '';
            $duesCell = (int) ( $m['dues_cents'] ?? 0 ) > 0
                ? esc_html( MyNJILGA_Invoicing::money( (int) $m['dues_cents'] ) ) . $tier
                : '<span style="color:#888">' . esc_html( $m['dues_note'] !== '' ? ucfirst( (string) $m['dues_note'] ) : '—' ) . '</span>';
            $feeCell = (int) ( $m['assessment_cents'] ?? 0 ) > 0
                ? esc_html( MyNJILGA_Invoicing::money( (int) $m['assessment_cents'] ) ) . ' <span style="color:#888;font-size:12px">(' . esc_html( (string) ( $m['assessment_qualifier'] ?? '' ) ) . ')</span>'
                : '<span style="color:#bbb">' . esc_html( (string) ( $m['assessment_note'] ?? '—' ) ) . '</span>';
            printf(
                '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                esc_html( (string) ( $m['name'] ?? '' ) ),
                esc_html( (string) ( $m['category_label'] ?: '—' ) ),
                $duesCell,
                $feeCell
            );
        }
        echo '</tbody></table></details>';
    }

    private static function solo_summary( array $m, array $snapshot ): string {
        if ( empty( $m ) ) {
            return '';
        }
        $parts = [ (string) ( $m['name'] ?? '' ) ];
        if ( ! empty( $m['unbilled_reason'] ) ) {
            $parts[] = ucfirst( (string) $m['unbilled_reason'] ) . ' — not billed';
        } else {
            $parts[] = (string) ( $m['category_label'] ?? '' ) . ( ! empty( $m['tier_label'] ) ? ' (' . $m['tier_label'] . ')' : '' );
            if ( (int) ( $m['assessment_cents'] ?? 0 ) > 0 ) {
                $parts[] = '+ ' . (string) $m['assessment_label'] . ' (' . (string) $m['assessment_qualifier'] . ')';
            }
        }
        $billTo = MyNJILGA_Dues_Snapshot::person( $snapshot['bill_to'] ?? [] );
        if ( $billTo['name'] !== '' && $billTo['name'] !== ( $m['name'] ?? '' ) ) {
            $parts[] = 'bill to ' . $billTo['name'];
        }
        return '· ' . implode( ' · ', array_filter( $parts ) );
    }

    private static function card_badges( object $row, array $snapshot ): string {
        $out = self::kind_badge( $row );
        $mode = (string) ( $row->billing_mode ?? 'firm' );
        if ( $mode !== MyNJILGA_Dues_Settings::MODE_FIRM ) {
            $out .= ' ' . self::badge( str_replace( '_', ' ', $mode ), '#6c3483' );
        }
        if ( ! empty( $row->queued_at ) && $row->status === MyNJILGA_Dues_Invoice_Table::STATUS_APPROVED ) {
            $out .= ' ' . self::badge( 'queued', '#2271b1' );
        }
        $noCat = 0;
        foreach ( $snapshot['members'] as $m ) {
            if ( ( $m['unbilled_reason'] ?? '' ) === MyNJILGA_Pricing_Engine::UNBILLED_NO_CATEGORY ) {
                $noCat++;
            }
        }
        if ( $noCat > 0 ) {
            $out .= ' ' . self::badge( $noCat . ' uncategorised', '#b26200' );
        }
        return $out;
    }

    private static function kind_badge( object $row ): string {
        $kind = (string) ( $row->invoice_kind ?? MyNJILGA_Dues_Snapshot::KIND_COMBINED );
        switch ( $kind ) {
            case MyNJILGA_Dues_Snapshot::KIND_DUES:
                return self::badge( 'dues only', '#2271b1' );
            case MyNJILGA_Dues_Snapshot::KIND_ASSESSMENT:
                return self::badge( 'assessment', '#b26200' );
            default:
                return '';
        }
    }

    private static function badge( string $text, string $color ): string {
        return sprintf( '<span style="display:inline-block;padding:1px 7px;border-radius:10px;font-size:11px;font-weight:600;color:#fff;background:%s;vertical-align:middle">%s</span>', esc_attr( $color ), esc_html( $text ) );
    }

    private static function bill_to_label( object $row ): string {
        $p = MyNJILGA_Dues_Snapshot::bill_to( $row );
        return $p['name'] !== '' ? $p['name'] : ( $p['email'] !== '' ? $p['email'] : '—' );
    }

    private static function open_form( string $action, int $duesYear ): void {
        printf(
            '<form method="post" action="%s">
                <input type="hidden" name="action" value="%s">
                <input type="hidden" name="dues_year" value="%d">
                %s',
            esc_url( admin_url( 'admin-post.php' ) ),
            esc_attr( $action ),
            $duesYear,
            wp_nonce_field( $action, '_wpnonce', true, false )
        );
    }

    public static function page_url( int $duesYear, array $args = [] ): string {
        $args['page']      = MyNJILGA_Admin_Menu::SLUG_INVOICING;
        $args['dues_year'] = $duesYear;
        return add_query_arg( $args, admin_url( 'admin.php' ) );
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
            'queued'          => 'notice-success',
            'created'         => 'notice-success',
            'created_partial' => 'notice-warning',
            'sent'            => 'notice-success',
            'sent_partial'    => 'notice-warning',
            'downgraded'      => 'notice-success',
            'nothing'         => 'notice-info',
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
        $g = static function ( string $k ): int { return isset( $_GET[ $k ] ) ? (int) $_GET[ $k ] : 0; };

        switch ( $msg ) {
            case 'previewed':
                return sprintf(
                    'Preview generated: %d draft invoice%s totalling %s, %d exception%s%s%s.',
                    $g( 'drafts' ), $g( 'drafts' ) === 1 ? '' : 's',
                    MyNJILGA_Invoicing::money( $g( 'cents' ) ),
                    $g( 'excluded' ), $g( 'excluded' ) === 1 ? '' : 's',
                    $g( 'blocked' ) > 0 ? sprintf( ', %d already approved or further along (left untouched)', $g( 'blocked' ) ) : '',
                    $g( 'stale' ) > 0 ? sprintf( ', %d stale draft%s removed', $g( 'stale' ), $g( 'stale' ) === 1 ? '' : 's' ) : ''
                );
            case 'approved':
                return sprintf( '%d invoice%s approved.', $g( 'count' ), $g( 'count' ) === 1 ? '' : 's' );
            case 'queued':
                return sprintf( '%d invoice%s queued for creation in %d background batch%s. Reload in a moment.', $g( 'count' ), $g( 'count' ) === 1 ? '' : 's', $g( 'chunks' ), $g( 'chunks' ) === 1 ? '' : 'es' );
            case 'created':
                return sprintf( '%d invoice%s created.', $g( 'count' ), $g( 'count' ) === 1 ? '' : 's' );
            case 'created_partial':
                return sprintf( '%d invoice%s created, %d failed — see "Needs attention" below.', $g( 'count' ), $g( 'count' ) === 1 ? '' : 's', $g( 'fail' ) );
            case 'sent':
                return sprintf( '%d invoice%s sent.', $g( 'count' ), $g( 'count' ) === 1 ? '' : 's' );
            case 'sent_partial':
                return sprintf( '%d invoice%s sent, %d failed — see details below.', $g( 'count' ), $g( 'count' ) === 1 ? '' : 's', $g( 'fail' ) );
            case 'downgraded':
                return sprintf( 'Sweep complete: %d invoice%s across %d firm%s — %d member%s tagged unpaid, %d WordPress role%s removed, %d protected by a paid invoice.', $g( 'invoices' ), $g( 'invoices' ) === 1 ? '' : 's', $g( 'firms' ), $g( 'firms' ) === 1 ? '' : 's', $g( 'members' ), $g( 'members' ) === 1 ? '' : 's', $g( 'roles' ), $g( 'roles' ) === 1 ? '' : 's', $g( 'protected' ) );
            case 'nothing':
                return 'Nothing selected.';
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

        $stats = MyNJILGA_Dues_Preview::generate_and_persist( $duesYear );

        self::redirect( $duesYear, [
            'msg'      => 'previewed',
            'drafts'   => $stats['drafts'],
            'excluded' => $stats['excluded'],
            'blocked'  => $stats['blocked'],
            'stale'    => $stats['stale_removed'],
            'cents'    => $stats['total_cents'],
        ] );
    }

    public static function handle_approve(): void {
        self::guard( self::ACTION_APPROVE );
        $duesYear = self::post_year();
        $ids      = self::post_ids( $duesYear, MyNJILGA_Dues_Invoice_Table::STATUS_DRAFT );
        if ( empty( $ids ) ) {
            self::redirect( $duesYear, [ 'msg' => 'nothing' ] );
        }

        $count = MyNJILGA_Dues_Invoice_Table::mark_approved( $ids );

        self::redirect( $duesYear, [ 'msg' => 'approved', 'count' => $count ] );
    }

    public static function handle_create(): void {
        self::guard( self::ACTION_CREATE );
        $duesYear = self::post_year();
        $ids      = self::post_ids( $duesYear, MyNJILGA_Dues_Invoice_Table::STATUS_APPROVED );
        if ( empty( $ids ) ) {
            self::redirect( $duesYear, [ 'msg' => 'nothing' ] );
        }

        $r = MyNJILGA_Invoice_Creator::schedule( $ids, $duesYear );

        if ( $r['mode'] === 'scheduled' && $r['queued'] > 0 ) {
            self::redirect( $duesYear, [ 'msg' => 'queued', 'count' => $r['queued'], 'chunks' => $r['chunks'] ] );
        }
        self::redirect( $duesYear, [ 'msg' => $r['fail'] > 0 ? 'created_partial' : 'created', 'count' => $r['ok'], 'fail' => $r['fail'] ] );
    }

    public static function handle_send(): void {
        self::guard( self::ACTION_SEND );
        $duesYear = self::post_year();
        $ids      = self::post_ids( $duesYear, MyNJILGA_Dues_Invoice_Table::STATUS_CREATED );
        if ( empty( $ids ) ) {
            self::redirect( $duesYear, [ 'msg' => 'nothing' ] );
        }

        $ok = 0; $fail = 0; $errors = [];
        foreach ( $ids as $id ) {
            $row = MyNJILGA_Dues_Invoice_Table::get( $id );
            if ( ! $row || $row->status !== MyNJILGA_Dues_Invoice_Table::STATUS_CREATED ) {
                continue;
            }
            try {
                $result = MyNJILGA_Invoice_Sender::send_for_row( $row );
            } catch ( \Throwable $e ) {
                $result = [ 'ok' => false, 'error' => $e->getMessage() ];
            }
            if ( $result['ok'] ) {
                $ok++;
            } else {
                $fail++;
                $errors[] = MyNJILGA_Dues_Snapshot::company_name( $row ) . ': ' . $result['error'];
                MyNJILGA_Dues_Invoice_Table::set_error( (int) $row->id, (string) $result['error'] );
            }
        }

        self::store_errors( $errors );
        self::redirect( $duesYear, [ 'msg' => $fail > 0 ? 'sent_partial' : 'sent', 'count' => $ok, 'fail' => $fail ] );
    }

    public static function handle_downgrade(): void {
        self::guard( self::ACTION_DOWNGRADE );
        $duesYear = self::post_year();

        // Only the confirmation screen's form carries these.
        if ( empty( $_POST['confirmed'] ) || empty( $_POST['acknowledge'] ) ) {
            wp_safe_redirect( self::page_url( $duesYear, [ 'view' => 'downgrade' ] ) );
            exit;
        }

        $result = MyNJILGA_Downgrade_Sweep::run( $duesYear );

        self::redirect( $duesYear, [
            'msg'       => 'downgraded',
            'invoices'  => $result['invoices_swept'],
            'firms'     => $result['firms_swept'],
            'members'   => $result['members_downgraded'],
            'roles'     => $result['roles_removed'],
            'protected' => $result['protected'],
        ] );
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
        return ( $year >= 2000 && $year <= 2100 ) ? $year : MyNJILGA_Invoicing::default_dues_year();
    }

    /**
     * Selected row ids — or, with the "all" button, every row of the year
     * in the given status.
     *
     * @return array<int,int>
     */
    private static function post_ids( int $duesYear, string $statusForAll ): array {
        if ( ! empty( $_POST['all'] ) ) {
            return array_map( static function ( $r ) { return (int) $r->id; }, MyNJILGA_Dues_Invoice_Table::get_by_year( $duesYear, [ $statusForAll ] ) );
        }
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
        wp_safe_redirect( self::page_url( $duesYear, $args ) );
        exit;
    }
}
