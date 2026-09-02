<?php
/**
 * Invoicing — annual dues invoicing by firm (spec §7). Generates the
 * per-firm roster/price preview across every FluentCRM Company, lets an
 * admin review and create invoices for the selected year — one firm at a
 * time or in bulk — sends payment links, and (behind a confirmation
 * screen) runs the end-of-year downgrade sweep.
 *
 * The screen is a single firm-focused workspace: one "Law Firms" table
 * with tabs (All / Ready / Created / Needs Attention), search, a status
 * filter and bulk selection, styled after shadcn/ui element styles. The
 * firms that can't be billed yet — no Owner, no members, individuals —
 * are still called out (Needs Attention), just not the main event.
 *
 * "Create Invoice" is one click: a draft is approved and its order
 * created in a single action (review happens in the inline preview). The
 * staged draft→approved→created→sent→paid model underneath is unchanged;
 * only the presentation and the approve+create shortcut are new.
 *
 * Server-rendered PHP posting to admin-post.php, with a small inline
 * stylesheet and vanilla JS (tabs / search / filter / paginate / expand)
 * — no build step, no external dependency.
 */
class MyNJILGA_Page_Invoicing {

    const ACTION_PREVIEW   = 'my_njilga_dues_preview';
    const ACTION_APPROVE   = 'my_njilga_dues_approve';
    const ACTION_CREATE    = 'my_njilga_dues_create';
    const ACTION_SEND      = 'my_njilga_dues_send';
    const ACTION_DOWNGRADE = 'my_njilga_dues_downgrade';
    const ACTION_SYNC      = 'my_njilga_dues_stripe_sync';
    const ACTION_MARK_PAID = 'my_njilga_dues_mark_paid';
    const ACTION_VOID      = 'my_njilga_dues_void';

    const FORM_ID      = 'njilga-inv-form';
    const SYNC_FORM_ID = 'njilga-inv-sync';

    /**
     * Statuses where a balance is genuinely outstanding — Mark Paid and
     * Void both apply consistently across these three.
     *
     * @return array<int,string>
     */
    private static function outstanding_statuses(): array {
        return [
            MyNJILGA_Dues_Invoice_Table::STATUS_CREATED,
            MyNJILGA_Dues_Invoice_Table::STATUS_SENT,
            MyNJILGA_Dues_Invoice_Table::STATUS_PROCESSING,
        ];
    }

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

        $liveMode = ( MyNJILGA_Stripe_Connection::active_mode() === MyNJILGA_Stripe_Connection::MODE_LIVE );

        MyNJILGA_Admin_UI::styles();
        echo '<div class="wrap njilga-ui">';

        if ( ! $liveMode ) {
            MyNJILGA_Admin_UI::callout( esc_html( 'Test mode — these invoices are not real and are hidden from Live.' ), 'warning' );
        }

        if ( MyNJILGA_Admin_Menu::require_fluentcrm() ) {
            echo '</div>';
            return;
        }
        if ( ! MyNJILGA_Members_Data::companies_module_active() ) {
            echo '<div class="njilga-callout njilga-callout-warning"><p>The FluentCRM <strong>Companies</strong> module is not active on this site. Enable it under FluentCRM → Settings → Modules.</p></div></div>';
            return;
        }

        if ( $view === 'downgrade' ) {
            self::render_downgrade_confirm( $duesYear );
            echo '</div>';
            return;
        }

        if ( $view === 'mark_paid' ) {
            $rowId = isset( $_GET['row_id'] ) ? (int) $_GET['row_id'] : 0;
            self::render_mark_paid_confirm( $rowId );
            echo '</div>';
            return;
        }

        self::render_notice();
        self::render_gateway_notices();

        $rows   = MyNJILGA_Dues_Invoice_Table::get_by_year( $duesYear, [], $liveMode );
        $counts = MyNJILGA_Dues_Invoice_Table::counts_by_status( $duesYear, $liveMode );
        $totals = MyNJILGA_Dues_Invoice_Table::totals_by_status( $duesYear, $liveMode );

        // Classify every row once — the summary, the tabs and the rows all
        // read the same verdict.
        $views = [];
        foreach ( $rows as $row ) {
            $views[] = [ 'row' => $row, 'c' => self::classify( $row ) ];
        }

        $gateway   = MyNJILGA_Invoicing::gateway();
        $canCreate = $gateway->is_available() && empty( $gateway->readiness_errors() );

        $tally = self::tally( $views );

        self::render_header( $duesYear, $tally, $canCreate );
        // Always present once the header is — the header's own "Sync with
        // Stripe" button targets this form by id regardless of whether
        // the year has any rows yet.
        self::render_sync_form( $duesYear );

        if ( empty( $rows ) ) {
            self::render_empty_state( $duesYear );
            echo '</div>';
            return;
        }

        self::render_summary( $tally, $totals );

        if ( MyNJILGA_Invoice_Creator::has_pending_jobs() ) {
            echo '<div class="njilga-callout njilga-callout-info"><p><strong>Invoices are being created in the background.</strong> Reload this page in a moment to see them move to Created. Any failures are recorded on the firm\'s row.</p></div>';
        }

        self::render_workspace( $duesYear, $views, $canCreate );
        self::render_send_form( $duesYear );
        self::render_downgrade_entry( $duesYear );

        self::scripts();
        echo '</div>';
    }

    private static function selected_year(): int {
        $year = isset( $_GET['dues_year'] ) ? (int) $_GET['dues_year'] : 0;
        return ( $year >= 2000 && $year <= 2100 ) ? $year : MyNJILGA_Invoicing::default_dues_year();
    }

    // -------------------------------------------------------------------------
    // Header / summary
    // -------------------------------------------------------------------------

    /**
     * @param array<string,int> $t tally
     */
    private static function render_header( int $duesYear, array $t, bool $canCreate ): void {
        $ready       = $t['ready'];
        $canBulk     = $canCreate && $ready > 0;
        $settingsUrl = MyNJILGA_Admin_Menu::url( MyNJILGA_Admin_Menu::SLUG_SETTINGS );

        echo '<div class="njilga-header">';
        echo '<div class="njilga-header-text">';
        echo '<h1 class="njilga-title">Generate Annual Dues Invoices</h1>';
        echo '<p class="njilga-subtitle">Review participating law firms, validate dues, and create invoices for the selected year.</p>';
        echo '</div>';

        echo '<div class="njilga-header-actions">';
        self::render_year_form( $duesYear );
        printf(
            '<a class="njilga-btn njilga-btn-outline" href="%s">%s Manage Dues Rules</a>',
            esc_url( $settingsUrl ),
            self::icon( 'sliders' )
        );
        printf(
            '<button type="submit" form="%s" class="njilga-btn njilga-btn-outline" title="Check every created/sent invoice for this year against Stripe">%s Sync with Stripe</button>',
            esc_attr( self::SYNC_FORM_ID ),
            self::icon( 'refresh' )
        );
        printf(
            '<button type="submit" %s name="all" value="1" class="njilga-btn njilga-btn-primary"%s>%s Create Ready Invoices</button>',
            $canBulk ? 'form="' . esc_attr( self::FORM_ID ) . '"' : '',
            $canBulk ? '' : ' disabled',
            self::icon( 'file' )
        );
        echo '</div>';
        echo '</div>';

        echo '<p class="njilga-header-note">Dues are calculated from active member tags for the selected year.</p>';
    }

    private static function render_year_form( int $duesYear ): void {
        $years   = MyNJILGA_Dues_Invoice_Table::years();
        if ( empty( $years ) ) {
            $years = [ $duesYear ];
        }
        if ( ! in_array( $duesYear, $years, true ) ) {
            array_unshift( $years, $duesYear );
        }
        sort( $years );

        $options = '';
        foreach ( $years as $y ) {
            $options .= sprintf(
                '<option value="%d"%s>%d</option>',
                $y,
                $y === $duesYear ? ' selected' : '',
                $y
            );
        }

        printf(
            '<form method="get" action="%s" class="njilga-year">
                <input type="hidden" name="page" value="%s">
                <span class="njilga-year-label">%s Dues Year</span>
                <select name="dues_year" class="njilga-select" onchange="this.form.submit()">%s</select>
                <noscript><button type="submit" class="njilga-btn njilga-btn-outline njilga-btn-sm">Go</button></noscript>
             </form>',
            esc_url( admin_url( 'admin.php' ) ),
            esc_attr( MyNJILGA_Admin_Menu::SLUG_INVOICING ),
            self::icon( 'calendar' ),
            $options
        );
    }

    /**
     * @param array<string,int> $t tally
     * @param array<string,int> $totals status => cents
     */
    private static function render_summary( array $t, array $totals ): void {
        MyNJILGA_Admin_UI::stat_cards( [
            [ 'label' => 'Law Firms',        'value' => $t['firms'],     'variant' => 'default', 'icon' => 'users' ],
            [ 'label' => 'Ready to Invoice', 'value' => $t['ready'],     'variant' => 'success', 'icon' => 'check-circle' ],
            [ 'label' => 'Invoices Created', 'value' => $t['created'],   'variant' => 'info',    'icon' => 'file' ],
            [ 'label' => 'Needs Attention',  'value' => $t['attention'], 'variant' => $t['attention'] > 0 ? 'warning' : 'default', 'icon' => 'alert' ],
        ] );

        $T           = MyNJILGA_Dues_Invoice_Table::class;
        $eligible    = $t['eligible'];
        $invoiced    = $t['invoiced'];
        $pct         = $eligible > 0 ? (int) round( ( $invoiced / $eligible ) * 100 ) : 0;
        $batchTotal  = (int) ( $totals[ $T::STATUS_DRAFT ] ?? 0 ) + (int) ( $totals[ $T::STATUS_APPROVED ] ?? 0 ) + (int) ( $totals[ $T::STATUS_CREATED ] ?? 0 ) + (int) ( $totals[ $T::STATUS_SENT ] ?? 0 ) + (int) ( $totals[ $T::STATUS_PAID ] ?? 0 ) + (int) ( $totals[ $T::STATUS_DOWNGRADED ] ?? 0 );
        $collected   = (int) ( $totals[ $T::STATUS_PAID ] ?? 0 );
        $outstanding = (int) ( $totals[ $T::STATUS_CREATED ] ?? 0 ) + (int) ( $totals[ $T::STATUS_SENT ] ?? 0 );

        echo '<div class="njilga-progress-wrap">';
        printf(
            '<div class="njilga-progress-top"><span class="njilga-progress-label">%d of %d eligible firms invoiced</span><span class="njilga-money-line">Batch %s · Collected %s · Outstanding %s</span></div>',
            $invoiced,
            $eligible,
            esc_html( MyNJILGA_Invoicing::money( $batchTotal ) ),
            esc_html( MyNJILGA_Invoicing::money( $collected ) ),
            esc_html( MyNJILGA_Invoicing::money( $outstanding ) )
        );
        printf( '<div class="njilga-progress"><div class="njilga-progress-bar" style="width:%d%%"></div></div>', $pct );
        echo '</div>';
    }

    // -------------------------------------------------------------------------
    // Workspace (the one table)
    // -------------------------------------------------------------------------

    /**
     * @param array<int,array{row:object,c:array<string,mixed>}> $views
     */
    private static function render_workspace( int $duesYear, array $views, bool $canCreate ): void {
        $t = self::tally( $views );

        echo '<div class="njilga-card njilga-workspace">';

        // Card header + tabs.
        echo '<div class="njilga-card-head"><h2 class="njilga-card-title">Law Firms</h2></div>';
        echo '<div class="njilga-tabs" role="tablist">';
        self::tab( 'all',       'All',            count( $views ) );
        self::tab( 'ready',     'Ready',          $t['ready'] );
        self::tab( 'created',   'Created',        $t['created'] );
        self::tab( 'attention', 'Needs Attention', $t['attention'] );
        echo '</div>';

        // Toolbar: search + status filter + refresh.
        echo '<div class="njilga-toolbar">';
        printf(
            '<div class="njilga-search">%s<input type="text" id="njilga-search" placeholder="Search law firms…" autocomplete="off"></div>',
            self::icon( 'search' )
        );
        echo '<select id="njilga-status" class="njilga-select">
                <option value="all">All statuses</option>
                <option value="ready">Ready</option>
                <option value="created">Invoice Created</option>
                <option value="sent">Sent</option>
                <option value="processing">Processing (ACH)</option>
                <option value="paid">Paid</option>
                <option value="blocked">Blocked</option>
                <option value="error">Error</option>
                <option value="downgraded">Downgraded</option>
                <option value="voided">Voided</option>
                <option value="uncollectible">Uncollectible</option>
              </select>';
        echo '<div class="njilga-toolbar-spacer"></div>';
        self::render_generate_form( $duesYear, 'Refresh Firms', 'outline' );
        echo '</div>';

        // The create form wraps the bulk bar + table so checkboxes and
        // per-row Create buttons all post together.
        printf(
            '<form id="%s" method="post" action="%s">
                <input type="hidden" name="action" value="%s">
                <input type="hidden" name="dues_year" value="%d">
                %s',
            esc_attr( self::FORM_ID ),
            esc_url( admin_url( 'admin-post.php' ) ),
            esc_attr( self::ACTION_CREATE ),
            $duesYear,
            wp_nonce_field( self::ACTION_CREATE, '_wpnonce', true, false )
        );

        echo '<div class="njilga-bulkbar" id="njilga-bulkbar" hidden>
                <label class="njilga-bulkbar-check"><input type="checkbox" id="njilga-all"> <span id="njilga-selcount">0</span> firms selected</label>
                <span class="njilga-bulkbar-sep"></span>
                <span class="njilga-bulkbar-total">Estimated total <strong id="njilga-seltotal">$0.00</strong></span>
                <button type="submit" class="njilga-btn njilga-btn-primary njilga-btn-sm" id="njilga-bulkcreate">Create Invoices</button>
              </div>';

        echo '<div class="njilga-tablewrap"><table class="njilga-table" id="njilga-table"><thead><tr>';
        echo '<th class="njilga-col-check"><input type="checkbox" id="njilga-all-head" aria-label="Select all ready firms"></th>';
        echo '<th>Law Firm</th>';
        echo '<th class="njilga-col-num">Members</th>';
        echo '<th class="njilga-col-num">Calculated Dues</th>';
        echo '<th>Invoice Status</th>';
        echo '<th>Validation</th>';
        echo '<th class="njilga-col-actions">Actions</th>';
        echo '<th class="njilga-col-expand"></th>';
        echo '</tr></thead><tbody>';

        foreach ( $views as $v ) {
            self::render_row( $v['row'], $v['c'], $canCreate );
        }

        echo '</tbody></table></div>';
        echo '</form>';

        // Footer: showing count + rows-per-page + pager.
        echo '<div class="njilga-tablefoot">
                <div class="njilga-showing" id="njilga-showing"></div>
                <div class="njilga-pagectl">
                    <label class="njilga-per-label">Rows per page
                        <select id="njilga-per" class="njilga-select njilga-select-sm">
                            <option value="10">10</option>
                            <option value="25">25</option>
                            <option value="50">50</option>
                            <option value="100000">All</option>
                        </select>
                    </label>
                    <div class="njilga-pager" id="njilga-pager"></div>
                </div>
              </div>';

        echo '<div class="njilga-noresults" id="njilga-noresults" hidden>No firms match your filters.</div>';

        echo '</div>'; // .njilga-card
    }

    private static function tab( string $key, string $label, int $count ): void {
        printf(
            '<button type="button" class="njilga-tab%s" data-tab="%s" role="tab">%s <span class="njilga-tab-count">%d</span></button>',
            $key === 'all' ? ' active' : '',
            esc_attr( $key ),
            esc_html( $label ),
            $count
        );
    }

    /**
     * One firm row + its (hidden) invoice-preview row.
     *
     * @param array<string,mixed> $c classification
     */
    private static function render_row( object $row, array $c, bool $canCreate ): void {
        $snapshot   = MyNJILGA_Dues_Snapshot::decode( $row );
        $name       = MyNJILGA_Dues_Snapshot::company_name( $row );
        $members    = $snapshot['members'];
        $memberCnt  = count( $members );
        $billTo     = self::bill_to_label( $row );
        $createable = (bool) $c['createable'];
        $cents      = (int) $row->total_amount_cents;
        $rowId      = (int) $row->id;

        $checkCell = $createable
            ? sprintf( '<input type="checkbox" class="njilga-check" name="row_ids[]" value="%d" data-cents="%d" aria-label="Select %s">', $rowId, $cents, esc_attr( $name ) )
            : '';

        // Firm name cell — name + badges + bill-to line.
        $badges = self::row_badges( $row, $snapshot );
        if ( $c['status'] === 'blocked' ) {
            $subline = '<span class="njilga-subline njilga-subline-warn">' . self::icon( 'alert' ) . ' No firm owner</span>';
        } elseif ( $billTo !== '—' ) {
            $subline = '<span class="njilga-subline">Bill to ' . esc_html( $billTo ) . '</span>';
        } else {
            $subline = '';
        }

        $duesCell = $cents > 0 ? esc_html( MyNJILGA_Invoicing::money( $cents ) ) : '<span class="njilga-dim">—</span>';

        echo '<tr class="njilga-row" data-id="' . $rowId . '" data-name="' . esc_attr( strtolower( $name ) ) . '" data-bucket="' . esc_attr( $c['bucket'] ) . '" data-status="' . esc_attr( $c['status'] ) . '" data-createable="' . ( $createable ? '1' : '0' ) . '">';
        echo '<td class="njilga-col-check">' . $checkCell . '</td>';
        printf(
            '<td class="njilga-firmcell"><button type="button" class="njilga-firmname njilga-expand" data-id="%d"><span class="njilga-firm-label">%s</span>%s</button>%s</td>',
            $rowId,
            esc_html( $name ),
            $badges,
            $subline
        );
        echo '<td class="njilga-col-num">' . ( $memberCnt > 0 ? (int) $memberCnt : '<span class="njilga-dim">0</span>' ) . '</td>';
        echo '<td class="njilga-col-num">' . $duesCell . '</td>';
        echo '<td>' . self::pill( $c['badge'][0], $c['badge'][1] ) . '</td>';
        echo '<td>' . self::validation_cell( $c['validation'][0], (bool) $c['validation'][1] ) . '</td>';
        echo '<td class="njilga-col-actions">' . self::row_actions( $row, $c, $canCreate ) . '</td>';
        printf( '<td class="njilga-col-expand"><button type="button" class="njilga-chevron njilga-expand" data-id="%d" aria-label="Toggle preview">%s</button></td>', $rowId, self::icon( 'chevron' ) );
        echo '</tr>';

        // Preview row.
        echo '<tr class="njilga-preview" data-for="' . $rowId . '" hidden><td colspan="8">';
        self::preview_card( $row, $snapshot, $c );
        echo '</td></tr>';
    }

    /**
     * @param array<string,mixed> $c
     */
    private static function row_actions( object $row, array $c, bool $canCreate ): string {
        $rowId = (int) $row->id;
        $link  = (string) ( $row->hosted_invoice_url ?? '' );
        $pdf   = (string) ( $row->invoice_pdf_url ?? '' );

        switch ( $c['status'] ) {
            case 'ready':
            case 'error':
                $label = $c['status'] === 'error' ? 'Retry' : 'Create Invoice';
                return sprintf(
                    '<button type="submit" form="%s" name="single" value="%d" class="njilga-btn njilga-btn-primary njilga-btn-sm"%s>%s</button>',
                    esc_attr( self::FORM_ID ),
                    $rowId,
                    $canCreate ? '' : ' disabled title="Payment gateway is not ready"',
                    esc_html( $label )
                );

            case 'creating':
                return '<span class="njilga-dim njilga-inline-status">' . self::icon( 'refresh' ) . ' Creating…</span>';

            case 'created':
                return sprintf(
                    '<button type="button" class="njilga-btn njilga-btn-primary njilga-btn-sm" data-send="%d">Send</button> %s %s %s %s %s',
                    $rowId,
                    self::view_link( $link ),
                    self::pdf_link( $pdf ),
                    self::mark_paid_button( $row ),
                    self::void_button( $row ),
                    self::sync_button( $rowId )
                );

            case 'sent':
                return sprintf(
                    '%s %s <button type="button" class="njilga-btn njilga-btn-ghost njilga-btn-sm" data-send="%d">Resend</button> %s %s %s',
                    self::view_link( $link ),
                    self::pdf_link( $pdf ),
                    $rowId,
                    self::mark_paid_button( $row ),
                    self::void_button( $row ),
                    self::sync_button( $rowId )
                );

            case 'processing':
                return sprintf(
                    '<span class="njilga-dim njilga-inline-status">%s Processing (ACH)</span> %s %s %s %s %s',
                    self::icon( 'refresh' ),
                    self::view_link( $link ),
                    self::pdf_link( $pdf ),
                    self::mark_paid_button( $row ),
                    self::void_button( $row ),
                    self::sync_button( $rowId )
                );

            case 'paid':
            case 'downgraded':
            case 'voided':
            case 'uncollectible':
                if ( $link === '' ) {
                    return '<span class="njilga-dim">—</span>';
                }
                return sprintf(
                    '<a class="njilga-btn njilga-btn-outline njilga-btn-sm" href="%s" target="_blank" rel="noopener">View Invoice</a> %s',
                    esc_url( $link ),
                    self::pdf_link( $pdf )
                );

            case 'blocked':
                return sprintf(
                    '<a class="njilga-btn njilga-btn-outline njilga-btn-sm" href="%s">Review Members</a>',
                    esc_url( MyNJILGA_Admin_Menu::url( MyNJILGA_Admin_Menu::SLUG_COMPANIES ) )
                );

            default: // no-members / zero
                return '<span class="njilga-dim">—</span>';
        }
    }

    /** A small outline "View" link to the hosted Stripe invoice, or '' when there isn't one yet. */
    private static function view_link( string $url ): string {
        return $url !== ''
            ? sprintf( '<a class="njilga-btn njilga-btn-outline njilga-btn-sm" href="%s" target="_blank" rel="noopener">View</a>', esc_url( $url ) )
            : '';
    }

    /** A small outline "PDF" link to the invoice's PDF, or '' when there isn't one yet. */
    private static function pdf_link( string $url ): string {
        return $url !== ''
            ? sprintf( '<a class="njilga-btn njilga-btn-outline njilga-btn-sm" href="%s" target="_blank" rel="noopener">PDF</a>', esc_url( $url ) )
            : '';
    }

    /**
     * Links to the ?view=mark_paid confirmation screen — the same
     * GET-driven, scoped-confirmation-view pattern as the downgrade
     * sweep's "Review & run" link, just keyed by a row id instead of a
     * whole-year sweep.
     */
    private static function mark_paid_button( object $row ): string {
        $url = self::page_url( (int) $row->dues_year, [ 'view' => 'mark_paid', 'row_id' => (int) $row->id ] );
        return sprintf( '<a class="njilga-btn njilga-btn-outline njilga-btn-sm" href="%s">Mark Paid</a>', esc_url( $url ) );
    }

    /** A single POST + JS confirm() — voiding one invoice is a small enough blast radius not to need a full confirmation screen. */
    private static function void_button( object $row ): string {
        return MyNJILGA_Admin_UI::action_form(
            self::ACTION_VOID,
            'Void',
            [ 'row_id' => (int) $row->id, 'dues_year' => (int) $row->dues_year ],
            'danger-outline',
            '',
            'Void this invoice? This cannot be undone — the firm will need a new invoice if they still owe dues.',
            'sm'
        );
    }

    /**
     * The expandable "Invoice Preview" card — dues grouped by tier the way
     * the invoice will read, assessments listed, then the roster behind a
     * disclosure.
     *
     * @param array<string,mixed> $snapshot
     * @param array<string,mixed> $c
     */
    private static function preview_card( object $row, array $snapshot, array $c ): void {
        $name    = MyNJILGA_Dues_Snapshot::company_name( $row );
        $members = $snapshot['members'];
        $billTo  = self::bill_to_label( $row );

        $billed = 0;
        foreach ( $members as $m ) {
            if ( (int) ( $m['dues_cents'] ?? 0 ) + (int) ( $m['assessment_cents'] ?? 0 ) > 0 ) {
                $billed++;
            }
        }

        // Group dues by (category, rate); assessments by (label, rate).
        $duesGroups = [];
        $feeGroups  = [];
        $unbilled   = 0;
        foreach ( $members as $m ) {
            $dues = (int) ( $m['dues_cents'] ?? 0 );
            $fee  = (int) ( $m['assessment_cents'] ?? 0 );
            if ( $dues > 0 ) {
                $label = (string) ( $m['category_label'] ?: 'Membership' );
                if ( ! empty( $m['tier_label'] ) ) {
                    $label .= ' · ' . (string) $m['tier_label'];
                }
                $key = $label . '|' . $dues;
                $duesGroups[ $key ] = $duesGroups[ $key ] ?? [ 'label' => $label, 'rate' => $dues, 'count' => 0 ];
                $duesGroups[ $key ]['count']++;
            }
            if ( $fee > 0 ) {
                $label = (string) ( $m['assessment_label'] ?: 'Assessment' );
                if ( ! empty( $m['assessment_qualifier'] ) ) {
                    $label .= ' (' . (string) $m['assessment_qualifier'] . ')';
                }
                $key = $label . '|' . $fee;
                $feeGroups[ $key ] = $feeGroups[ $key ] ?? [ 'label' => $label, 'rate' => $fee, 'count' => 0 ];
                $feeGroups[ $key ]['count']++;
            }
            if ( $dues + $fee <= 0 ) {
                $unbilled++;
            }
        }

        echo '<div class="njilga-preview-card">';
        printf(
            '<div class="njilga-preview-head"><div><div class="njilga-preview-title">Invoice Preview — %s</div><div class="njilga-preview-sub">Bill to %s · %d billed member%s</div></div></div>',
            esc_html( $name ),
            esc_html( $billTo ),
            $billed,
            $billed === 1 ? '' : 's'
        );

        // Notes / warnings from the snapshot.
        foreach ( (array) ( $snapshot['notes'] ?? [] ) as $n ) {
            printf( '<div class="njilga-preview-note">%s %s</div>', self::icon( 'alert' ), esc_html( (string) $n ) );
        }
        if ( ! empty( $row->last_error ) ) {
            printf( '<div class="njilga-preview-note njilga-preview-error">%s %s</div>', self::icon( 'alert' ), esc_html( (string) $row->last_error ) );
        }

        if ( empty( $duesGroups ) && empty( $feeGroups ) ) {
            echo '<p class="njilga-dim njilga-preview-empty">Nothing is billable on this roster for the selected year.</p>';
        } else {
            echo '<table class="njilga-preview-table"><thead><tr><th>Line</th><th class="njilga-col-num">Members</th><th class="njilga-col-num">Rate</th><th class="njilga-col-num">Subtotal</th></tr></thead><tbody>';
            foreach ( $duesGroups as $g ) {
                printf(
                    '<tr><td>%s</td><td class="njilga-col-num">%d</td><td class="njilga-col-num">%s</td><td class="njilga-col-num">%s</td></tr>',
                    esc_html( $g['label'] ),
                    (int) $g['count'],
                    esc_html( MyNJILGA_Invoicing::money( (int) $g['rate'] ) ),
                    esc_html( MyNJILGA_Invoicing::money( (int) $g['rate'] * (int) $g['count'] ) )
                );
            }
            foreach ( $feeGroups as $g ) {
                printf(
                    '<tr><td>%s</td><td class="njilga-col-num">%d</td><td class="njilga-col-num">%s</td><td class="njilga-col-num">%s</td></tr>',
                    esc_html( $g['label'] ),
                    (int) $g['count'],
                    esc_html( MyNJILGA_Invoicing::money( (int) $g['rate'] ) ),
                    esc_html( MyNJILGA_Invoicing::money( (int) $g['rate'] * (int) $g['count'] ) )
                );
            }
            printf(
                '<tr class="njilga-preview-total"><td colspan="3">Invoice Total</td><td class="njilga-col-num">%s</td></tr>',
                esc_html( MyNJILGA_Invoicing::money( (int) $row->total_amount_cents ) )
            );
            echo '</tbody></table>';
        }

        // Full roster behind a disclosure.
        if ( ! empty( $members ) ) {
            echo '<details class="njilga-roster"><summary>' . self::icon( 'users' ) . ' View member roster (' . count( $members ) . ')</summary>';
            echo '<table class="njilga-preview-table njilga-roster-table"><thead><tr><th>Member</th><th>Category</th><th class="njilga-col-num">Dues</th><th class="njilga-col-num">Assessment</th></tr></thead><tbody>';
            foreach ( $members as $m ) {
                if ( ! empty( $m['unbilled_reason'] ) ) {
                    printf(
                        '<tr class="njilga-roster-unbilled"><td>%s</td><td>%s</td><td colspan="2" class="njilga-dim">%s — not billed</td></tr>',
                        esc_html( (string) ( $m['name'] ?? '' ) ),
                        esc_html( (string) ( $m['category_label'] ?: '—' ) ),
                        esc_html( ucfirst( (string) $m['unbilled_reason'] ) )
                    );
                    continue;
                }
                $duesCell = (int) ( $m['dues_cents'] ?? 0 ) > 0
                    ? esc_html( MyNJILGA_Invoicing::money( (int) $m['dues_cents'] ) )
                    : '<span class="njilga-dim">' . esc_html( $m['dues_note'] !== '' ? ucfirst( (string) $m['dues_note'] ) : '—' ) . '</span>';
                $feeCell = (int) ( $m['assessment_cents'] ?? 0 ) > 0
                    ? esc_html( MyNJILGA_Invoicing::money( (int) $m['assessment_cents'] ) )
                    : '<span class="njilga-dim">' . esc_html( (string) ( $m['assessment_note'] ?? '—' ) ) . '</span>';
                printf(
                    '<tr><td>%s</td><td>%s</td><td class="njilga-col-num">%s</td><td class="njilga-col-num">%s</td></tr>',
                    esc_html( (string) ( $m['name'] ?? '' ) ),
                    esc_html( (string) ( $m['category_label'] ?: '—' ) ),
                    $duesCell,
                    $feeCell
                );
            }
            echo '</tbody></table></details>';
        }

        echo '</div>';
    }

    // -------------------------------------------------------------------------
    // Classification — one row → its bucket / badge / validation / action
    // -------------------------------------------------------------------------

    /**
     * @return array{bucket:string,status:string,badge:array{0:string,1:string},validation:array{0:string,1:bool},createable:bool}
     */
    private static function classify( object $row ): array {
        $T        = MyNJILGA_Dues_Invoice_Table::class;
        $status   = (string) $row->status;
        $hasError = ! empty( $row->last_error );

        switch ( $status ) {
            case $T::STATUS_EXCLUDED:
                $reason = (string) ( MyNJILGA_Dues_Snapshot::decode( $row )['exclusion_reason'] ?? MyNJILGA_Dues_Preview::EXCLUDED_NO_OWNER );
                if ( $reason === MyNJILGA_Dues_Preview::EXCLUDED_NO_MEMBERS ) {
                    return self::verdict( 'attention', 'no-members', [ 'No members', 'muted' ], [ 'No members', false ], false );
                }
                if ( $reason === MyNJILGA_Dues_Preview::EXCLUDED_ZERO_TOTAL ) {
                    return self::verdict( 'attention', 'zero', [ 'Nothing to bill', 'muted' ], [ '$0 this year', false ], false );
                }
                return self::verdict( 'attention', 'blocked', [ 'Blocked', 'warning' ], [ 'No firm owner', false ], false );

            case $T::STATUS_DRAFT:
            case $T::STATUS_APPROVED:
                if ( $hasError ) {
                    return self::verdict( 'attention', 'error', [ 'Error', 'destructive' ], [ (string) $row->last_error, false ], true );
                }
                if ( $status === $T::STATUS_APPROVED && ! empty( $row->queued_at ) ) {
                    return self::verdict( 'ready', 'creating', [ 'Creating…', 'info' ], [ 'Complete', true ], false );
                }
                return self::verdict( 'ready', 'ready', [ 'Ready', 'success' ], [ 'Complete', true ], true );

            case $T::STATUS_CREATED:
                $val = $hasError ? [ (string) $row->last_error, false ] : [ 'Complete', true ];
                return self::verdict( 'created', 'created', [ 'Invoice Created', 'info' ], $val, false );

            case $T::STATUS_SENT:
                return self::verdict( 'created', 'sent', [ 'Sent', 'info' ], [ 'Complete', true ], false );

            // ACH-in-flight (set only by the payment_intent.processing
            // webhook event, or the reconciler catching up to it) — not
            // yet settled, not an error either.
            case $T::STATUS_PROCESSING:
                // Amber, not blue, and it says what is actually happening:
                // money has been submitted but nothing has settled, and an
                // ACH debit can take days. The submit date is the thing
                // staff want ("is this stuck?"), so it rides in the pill.
                $submitted = substr( (string) ( $row->processing_at ?? '' ), 0, 10 );
                $pillLabel = $submitted !== '' ? 'ACH clearing since ' . $submitted : 'ACH clearing';
                $val       = $hasError ? [ (string) $row->last_error, false ] : [ 'Payment in progress (ACH)', true ];
                return self::verdict( 'created', 'processing', [ $pillLabel, 'warning' ], $val, false );

            case $T::STATUS_PAID:
                return self::verdict( 'created', 'paid', [ 'Paid', 'success' ], [ 'Complete', true ], false );

            case $T::STATUS_DOWNGRADED:
                return self::verdict( 'created', 'downgraded', [ 'Downgraded', 'destructive' ], [ 'Unpaid — swept', false ], false );

            // Terminal in Stripe — staff voided it, or it was written off.
            // Neither is retryable; both are things worth a look.
            case $T::STATUS_VOIDED:
                return self::verdict( 'attention', 'voided', [ 'Voided', 'muted' ], [ 'Voided — no longer collectible', false ], false );

            case $T::STATUS_UNCOLLECTIBLE:
                return self::verdict( 'attention', 'uncollectible', [ 'Uncollectible', 'destructive' ], [ 'Written off', false ], false );

            default:
                return self::verdict( 'attention', 'error', [ ucfirst( $status ), 'muted' ], [ '—', false ], false );
        }
    }

    /**
     * @param array{0:string,1:string} $badge
     * @param array{0:string,1:bool}   $validation
     * @return array<string,mixed>
     */
    private static function verdict( string $bucket, string $status, array $badge, array $validation, bool $createable ): array {
        return [
            'bucket'     => $bucket,
            'status'     => $status,
            'badge'      => $badge,
            'validation' => $validation,
            'createable' => $createable,
        ];
    }

    /**
     * Roll the classified rows up into the summary/tab numbers.
     *
     * @param array<int,array{row:object,c:array<string,mixed>}> $views
     * @return array<string,int>
     */
    private static function tally( array $views ): array {
        $t = [ 'firms' => 0, 'ready' => 0, 'created' => 0, 'attention' => 0, 'eligible' => 0, 'invoiced' => 0 ];

        $firms         = [];
        $eligibleFirms = [];
        $invoicedFirms = [];
        foreach ( $views as $v ) {
            $companyId = (int) $v['row']->fluentcrm_company_id;
            $firms[ $companyId ] = true;

            $bucket = $v['c']['bucket'];
            if ( $bucket === 'ready' ) {
                $t['ready']++;
                $eligibleFirms[ $companyId ] = true;
            } elseif ( $bucket === 'created' ) {
                $t['created']++;
                $eligibleFirms[ $companyId ] = true;
                $invoicedFirms[ $companyId ] = true;
            } else {
                $t['attention']++;
            }
        }

        $t['firms']    = count( $firms );
        $t['eligible'] = count( $eligibleFirms );
        $t['invoiced'] = count( $invoicedFirms );
        return $t;
    }

    // -------------------------------------------------------------------------
    // Empty state
    // -------------------------------------------------------------------------

    private static function render_empty_state( int $duesYear ): void {
        echo '<div class="njilga-card njilga-empty">';
        echo '<div class="njilga-empty-icon">' . self::icon( 'file' ) . '</div>';
        printf( '<h2 class="njilga-empty-title">No invoices generated for %d yet</h2>', $duesYear );
        printf(
            '<p class="njilga-empty-text">Generate this year\'s roster and pricing from FluentCRM Companies using the current <a href="%s">Dues &amp; Billing settings</a>. Safe to re-run at any time — firms already invoiced are never recomputed.</p>',
            esc_url( MyNJILGA_Admin_Menu::url( MyNJILGA_Admin_Menu::SLUG_SETTINGS ) )
        );
        self::render_generate_form( $duesYear, sprintf( 'Generate Preview for %d', $duesYear ), 'primary' );
        echo '</div>';
    }

    private static function render_generate_form( int $duesYear, string $label, string $style ): void {
        printf(
            '<form method="post" action="%s" class="njilga-generate">
                <input type="hidden" name="action" value="%s">
                <input type="hidden" name="dues_year" value="%d">
                %s
                <button type="submit" class="njilga-btn njilga-btn-%s njilga-btn-sm">%s %s</button>
             </form>',
            esc_url( admin_url( 'admin-post.php' ) ),
            esc_attr( self::ACTION_PREVIEW ),
            $duesYear,
            wp_nonce_field( self::ACTION_PREVIEW, '_wpnonce', true, false ),
            esc_attr( $style === 'primary' ? 'primary' : 'outline' ),
            self::icon( 'refresh' ),
            esc_html( $label )
        );
    }

    // -------------------------------------------------------------------------
    // Send (hidden form driven by the per-row Send buttons)
    // -------------------------------------------------------------------------

    private static function render_send_form( int $duesYear ): void {
        printf(
            '<form id="njilga-inv-send" method="post" action="%s" hidden>
                <input type="hidden" name="action" value="%s">
                <input type="hidden" name="dues_year" value="%d">
                %s
                <span id="njilga-send-ids"></span>
             </form>',
            esc_url( admin_url( 'admin-post.php' ) ),
            esc_attr( self::ACTION_SEND ),
            $duesYear,
            wp_nonce_field( self::ACTION_SEND, '_wpnonce', true, false )
        );
    }

    // -------------------------------------------------------------------------
    // Sync with Stripe (header button + per-row "Refresh", same form)
    // -------------------------------------------------------------------------

    /**
     * One shared form for both the header's whole-year "Sync with Stripe"
     * button and every per-row "Refresh" button — exactly the same
     * all-vs-single shape #njilga-inv-form already uses for "Create Ready
     * Invoices" vs. a single-row "Create Invoice": no `single` field means
     * the whole year, a `single` value scopes it to one invoice row. Both
     * kinds of button live outside this form in the DOM and target it by
     * id via the `form="..."` attribute (HTML doesn't require a form
     * control to be a DOM descendant of the form it submits to).
     */
    private static function render_sync_form( int $duesYear ): void {
        printf(
            '<form id="%s" method="post" action="%s" hidden>
                <input type="hidden" name="action" value="%s">
                <input type="hidden" name="dues_year" value="%d">
                %s
             </form>',
            esc_attr( self::SYNC_FORM_ID ),
            esc_url( admin_url( 'admin-post.php' ) ),
            esc_attr( self::ACTION_SYNC ),
            $duesYear,
            wp_nonce_field( self::ACTION_SYNC, '_wpnonce', true, false )
        );
    }

    private static function sync_button( int $rowId ): string {
        return sprintf(
            '<button type="submit" form="%s" name="single" value="%d" class="njilga-btn njilga-btn-outline njilga-btn-sm" title="Check this invoice against Stripe">%s Refresh</button>',
            esc_attr( self::SYNC_FORM_ID ),
            $rowId,
            self::icon( 'refresh' )
        );
    }

    // -------------------------------------------------------------------------
    // Downgrade
    // -------------------------------------------------------------------------

    private static function render_downgrade_entry( int $duesYear ): void {
        $p = MyNJILGA_Downgrade_Sweep::preview( $duesYear );

        echo '<div class="njilga-danger-card">';
        echo '<div class="njilga-danger-head">' . self::icon( 'alert' ) . '<h2>Downgrade Sweep</h2></div>';
        printf(
            '<p>For every %d dues invoice still not paid, this tags every roster member <strong>%s</strong>%s. Right now that would affect <strong>%d invoice%s</strong> across <strong>%d firm%s</strong> — <strong>%d member%s</strong>. Grace periods and reminders are handled outside this plugin; run this only after that process has closed out the cycle.</p>',
            $duesYear,
            esc_html( MyNJILGA_Dues_Settings::year_tag( 'year_unpaid_tag_pattern', $duesYear ) ),
            $p['remove_roles'] ? ' and removes their WordPress membership role' : '',
            $p['invoices'], $p['invoices'] === 1 ? '' : 's',
            $p['firms'], $p['firms'] === 1 ? '' : 's',
            $p['members'], $p['members'] === 1 ? '' : 's'
        );
        printf(
            '<a class="njilga-btn njilga-btn-danger njilga-btn-sm" href="%s">Review &amp; run the %d sweep →</a>',
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

        printf( '<p class="njilga-back"><a href="%s">&larr; Back to %d invoicing</a></p>', esc_url( self::page_url( $duesYear ) ), $duesYear );
        printf( '<h1 class="njilga-title njilga-title-danger">Confirm the %d downgrade sweep</h1>', $duesYear );

        MyNJILGA_Admin_UI::stat_cards( [
            [ 'label' => 'Unpaid invoices',           'value' => (int) $p['invoices'],  'variant' => 'destructive', 'icon' => 'file' ],
            [ 'label' => 'Firms affected',            'value' => (int) $p['firms'],     'variant' => 'destructive', 'icon' => 'users' ],
            [ 'label' => 'Members downgraded',        'value' => (int) $p['members'],   'variant' => 'destructive', 'icon' => 'alert' ],
            [ 'label' => 'Protected (paid elsewhere)', 'value' => (int) $p['protected'], 'variant' => 'success',    'icon' => 'check-circle' ],
        ] );

        echo '<div class="njilga-danger-card"><p><strong>What will happen to each member listed below:</strong></p><ul class="njilga-list">';
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

        echo '<div class="njilga-card"><div class="njilga-tablewrap"><table class="njilga-table"><thead><tr><th>Firm</th><th>Invoice</th><th>Status</th><th>Bill to</th><th>Members</th><th class="njilga-col-num">Total</th></tr></thead><tbody>';
        foreach ( $p['rows'] as $row ) {
            $names = array_map( static function ( $m ) { return (string) ( $m['name'] ?? '' ); }, MyNJILGA_Dues_Snapshot::members( $row ) );
            printf(
                '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td class="njilga-col-num">%s</td></tr>',
                esc_html( MyNJILGA_Dues_Snapshot::company_name( $row ) ),
                self::kind_pill( $row ),
                self::pill( ucfirst( (string) $row->status ), 'muted' ),
                esc_html( self::bill_to_label( $row ) ),
                esc_html( implode( ', ', $names ) ),
                esc_html( MyNJILGA_Invoicing::money( (int) $row->total_amount_cents ) )
            );
        }
        echo '</tbody></table></div></div>';

        printf(
            '<form method="post" action="%s" class="njilga-confirm-form">
                <input type="hidden" name="action" value="%s">
                <input type="hidden" name="dues_year" value="%d">
                <input type="hidden" name="confirmed" value="1">
                %s
                <label class="njilga-ack"><input type="checkbox" name="acknowledge" value="1" required> I\'ve reviewed the list above and the %d billing cycle is closed.</label>
                <div class="njilga-confirm-actions">
                    <button type="submit" class="njilga-btn njilga-btn-danger">Run Downgrade Sweep — %d invoice%s, %d member%s</button>
                    <a class="njilga-btn njilga-btn-outline" href="%s">Cancel</a>
                </div>
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
    // Mark Paid (by check/cash/wire) — confirmation screen
    // -------------------------------------------------------------------------

    /**
     * The ?view=mark_paid&row_id=… confirmation screen — same GET-driven,
     * scoped-confirmation-view shape as render_downgrade_confirm(), just
     * keyed by one invoice row instead of a whole-year sweep. See
     * handle_mark_paid() for the money-correctness split between a
     * partial payment (recorded purely in WordPress) and a
     * balance-zeroing payment (recorded via Stripe's
     * mark_paid_out_of_band(), whose resulting invoice.paid webhook is
     * the only place that writes THAT payment's ledger row).
     */
    private static function render_mark_paid_confirm( int $rowId ): void {
        $row      = $rowId > 0 ? MyNJILGA_Dues_Invoice_Table::get( $rowId ) : null;
        $duesYear = $row ? (int) $row->dues_year : self::selected_year();

        printf( '<p class="njilga-back"><a href="%s">&larr; Back to %d invoicing</a></p>', esc_url( self::page_url( $duesYear ) ), $duesYear );

        if ( ! $row || ! in_array( $row->status, self::outstanding_statuses(), true ) ) {
            MyNJILGA_Admin_UI::callout( esc_html( 'This invoice can\'t be marked paid right now — it may already be paid, voided, or no longer exists.' ), 'warning' );
            return;
        }

        $name    = MyNJILGA_Dues_Snapshot::company_name( $row );
        $balance = (int) $row->amount_due_cents;

        printf( '<h1 class="njilga-title">Mark %s Paid</h1>', esc_html( $name ) );
        printf(
            '<p class="njilga-subtitle">Invoice %s &middot; Current balance <strong>%s</strong></p>',
            esc_html( (string) ( $row->gateway_invoice_number ?: '—' ) ),
            esc_html( MyNJILGA_Invoicing::money( $balance ) )
        );

        $today          = current_time( 'Y-m-d' );
        $balanceDollars = number_format( $balance / 100, 2, '.', '' );

        echo '<div class="njilga-card njilga-card-pad" style="max-width:560px">';
        printf(
            '<form method="post" action="%s">
                <input type="hidden" name="action" value="%s">
                <input type="hidden" name="row_id" value="%d">
                <input type="hidden" name="dues_year" value="%d">
                %s',
            esc_url( admin_url( 'admin-post.php' ) ),
            esc_attr( self::ACTION_MARK_PAID ),
            $rowId,
            $duesYear,
            wp_nonce_field( self::ACTION_MARK_PAID, '_wpnonce', true, false )
        );

        printf(
            '<div class="njilga-field"><label for="mp-amount">Amount</label>
                <input type="number" step="0.01" min="0.01" max="%1$s" id="mp-amount" name="amount" value="%1$s" class="regular-text">
                <p class="njilga-help">Current balance: %2$s. Enter less to record a partial payment — the rest stays outstanding.</p>
             </div>',
            esc_attr( $balanceDollars ),
            esc_html( MyNJILGA_Invoicing::money( $balance ) )
        );

        if ( ! empty( $row->hosted_invoice_url ) ) {
            MyNJILGA_Admin_UI::callout(
                'Recording a partial payment here only updates this record — it does not reduce what Stripe\'s hosted invoice page still asks the firm for. If they might pay the rest online using the original link, let them know the remaining balance directly so they don\'t pay the full original amount again.',
                'warning'
            );
        }

        echo '<div class="njilga-field"><label>Method</label><div class="njilga-radio-list">';
        foreach ( [ 'check' => 'Check', 'cash' => 'Cash', 'wire' => 'Wire', 'other' => 'Other' ] as $val => $label ) {
            printf(
                '<label class="njilga-check-label"><input type="radio" name="method" value="%s"%s> <span>%s</span></label>',
                esc_attr( $val ),
                checked( $val, 'check', false ),
                esc_html( $label )
            );
        }
        echo '</div></div>';

        // Field name is deliberately generic ("reference") even though
        // it's labelled as a check number: with Method = Wire, the same
        // field holds the wire reference instead — both are carried
        // through mark_paid_out_of_band() under its one existing
        // 'check_number' => 'njilga_check_number' metadata slot (see
        // class-stripe-invoice-gateway.php), so no second metadata key
        // was needed for this run's additive change there.
        echo '<div class="njilga-field"><label for="mp-reference">Check number</label>
                <input type="text" id="mp-reference" name="reference" class="regular-text" placeholder="e.g. 1042" autocomplete="off">
                <p class="njilga-help">Required when Method is Check. Also holds the wire reference number when Method is Wire.</p>
              </div>';

        printf(
            '<div class="njilga-field"><label for="mp-date">Date received</label>
                <input type="date" id="mp-date" name="date_received" value="%1$s" max="%1$s">
             </div>',
            esc_attr( $today )
        );

        echo '<div class="njilga-field"><label for="mp-note">Note (optional)</label>
                <textarea id="mp-note" name="note" rows="3" class="large-text"></textarea>
              </div>';

        printf(
            '<div class="njilga-actions">
                <button type="submit" class="njilga-btn njilga-btn-primary">Record Payment</button>
                <a class="njilga-btn njilga-btn-outline" href="%s">Cancel</a>
             </div>',
            esc_url( self::page_url( $duesYear ) )
        );

        echo '</form></div>';
    }

    // -------------------------------------------------------------------------
    // Badges / small parts
    // -------------------------------------------------------------------------

    private static function row_badges( object $row, array $snapshot ): string {
        $out  = self::kind_pill( $row );
        $mode = (string) ( $row->billing_mode ?? 'firm' );
        if ( $mode !== MyNJILGA_Dues_Settings::MODE_FIRM ) {
            $out .= ' ' . self::pill( str_replace( '_', ' ', $mode ), 'outline' );
        }
        $noCat = 0;
        foreach ( (array) ( $snapshot['members'] ?? [] ) as $m ) {
            if ( ( $m['unbilled_reason'] ?? '' ) === MyNJILGA_Pricing_Engine::UNBILLED_NO_CATEGORY ) {
                $noCat++;
            }
        }
        if ( $noCat > 0 ) {
            $out .= ' ' . self::pill( $noCat . ' uncategorised', 'warning' );
        }
        return $out !== '' ? ' ' . $out : '';
    }

    private static function kind_pill( object $row ): string {
        $kind = (string) ( $row->invoice_kind ?? MyNJILGA_Dues_Snapshot::KIND_COMBINED );
        switch ( $kind ) {
            case MyNJILGA_Dues_Snapshot::KIND_DUES:
                return self::pill( 'dues only', 'outline' );
            case MyNJILGA_Dues_Snapshot::KIND_ASSESSMENT:
                return self::pill( 'assessment', 'outline' );
            default:
                return '';
        }
    }

    private static function pill( string $text, string $variant ): string {
        return MyNJILGA_Admin_UI::pill( $text, $variant );
    }

    private static function validation_cell( string $label, bool $ok ): string {
        return MyNJILGA_Admin_UI::validation( $label, $ok );
    }

    private static function bill_to_label( object $row ): string {
        $p = MyNJILGA_Dues_Snapshot::bill_to( $row );
        return $p['name'] !== '' ? $p['name'] : ( $p['email'] !== '' ? $p['email'] : '—' );
    }

    public static function page_url( int $duesYear, array $args = [] ): string {
        $args['page']      = MyNJILGA_Admin_Menu::SLUG_INVOICING;
        $args['dues_year'] = $duesYear;
        return add_query_arg( $args, admin_url( 'admin.php' ) );
    }

    // -------------------------------------------------------------------------
    // Icons — delegated to the design system.
    // -------------------------------------------------------------------------

    private static function icon( string $name ): string {
        return MyNJILGA_Admin_UI::icon( $name );
    }

    // -------------------------------------------------------------------------
    // Notices
    // -------------------------------------------------------------------------

    private static function render_gateway_notices(): void {
        $gateway = MyNJILGA_Invoicing::gateway();
        if ( ! $gateway->is_available() ) {
            printf( '<div class="njilga-callout njilga-callout-warning"><p><strong>%s is not active.</strong> Invoices can still be previewed, but "Create Invoice" needs it installed and active.</p></div>', esc_html( $gateway->name() ) );
            return;
        }
        foreach ( $gateway->readiness_errors() as $err ) {
            printf( '<div class="njilga-callout njilga-callout-warning"><p><strong>%s isn\'t ready to create invoices:</strong> %s</p></div>', esc_html( $gateway->name() ), esc_html( $err ) );
        }
        // Soft findings (a webhook gone quiet, ACH switched off at
        // Stripe) don't block anything, but this is the page where
        // invoices get made — the ACH one in particular is about what a
        // firm will see on the invoice about to be sent, so it belongs
        // here and not only on Setup.
        foreach ( MyNJILGA_Stripe_Connection::health_warnings() as $warning ) {
            printf( '<div class="njilga-callout njilga-callout-info"><p>%s</p></div>', esc_html( $warning ) );
        }
    }

    private static function render_notice(): void {
        $msg = isset( $_GET['msg'] ) ? sanitize_key( $_GET['msg'] ) : '';
        if ( $msg === '' ) {
            return;
        }

        $classes = [
            'previewed'       => 'success',
            'approved'        => 'success',
            'queued'          => 'success',
            'created'         => 'success',
            'created_partial' => 'warning',
            'sent'            => 'success',
            'sent_partial'    => 'warning',
            'downgraded'      => 'success',
            'synced'          => 'info',
            'marked_paid'     => 'success',
            'voided_invoice'  => 'success',
            'nothing'         => 'info',
            'error'           => 'error',
        ];
        $text = self::notice_text( $msg );
        if ( $text === '' ) {
            return;
        }

        printf( '<div class="njilga-callout njilga-callout-%s"><p>%s</p></div>', esc_attr( $classes[ $msg ] ?? 'info' ), esc_html( $text ) );

        $key    = 'njilga_dues_errors_' . get_current_user_id();
        $errors = get_transient( $key );
        if ( $errors ) {
            delete_transient( $key );
            echo '<div class="njilga-callout njilga-callout-error"><p><strong>Details:</strong></p><ul class="njilga-list">';
            foreach ( (array) $errors as $line ) {
                printf( '<li>%s</li>', esc_html( (string) $line ) );
            }
            echo '</ul></div>';
        }
    }

    private static function notice_text( string $msg ): string {
        $g  = static function ( string $k ): int { return isset( $_GET[ $k ] ) ? (int) $_GET[ $k ] : 0; };
        $gs = static function ( string $k ): string { return isset( $_GET[ $k ] ) ? sanitize_key( (string) $_GET[ $k ] ) : ''; };

        switch ( $msg ) {
            case 'previewed':
                return sprintf(
                    'Preview generated: %d ready invoice%s totalling %s, %d exception%s%s%s.',
                    $g( 'drafts' ), $g( 'drafts' ) === 1 ? '' : 's',
                    MyNJILGA_Invoicing::money( $g( 'cents' ) ),
                    $g( 'excluded' ), $g( 'excluded' ) === 1 ? '' : 's',
                    $g( 'blocked' ) > 0 ? sprintf( ', %d already invoiced or further along (left untouched)', $g( 'blocked' ) ) : '',
                    $g( 'stale' ) > 0 ? sprintf( ', %d stale draft%s removed', $g( 'stale' ), $g( 'stale' ) === 1 ? '' : 's' ) : ''
                );
            case 'approved':
                return sprintf( '%d invoice%s approved.', $g( 'count' ), $g( 'count' ) === 1 ? '' : 's' );
            case 'queued':
                return sprintf( '%d invoice%s queued for creation in %d background batch%s. Reload in a moment.', $g( 'count' ), $g( 'count' ) === 1 ? '' : 's', $g( 'chunks' ), $g( 'chunks' ) === 1 ? '' : 'es' );
            case 'created':
                return sprintf( '%d invoice%s created.', $g( 'count' ), $g( 'count' ) === 1 ? '' : 's' );
            case 'created_partial':
                return sprintf( '%d invoice%s created, %d failed — see "Needs Attention".', $g( 'count' ), $g( 'count' ) === 1 ? '' : 's', $g( 'fail' ) );
            case 'sent':
                return sprintf( '%d invoice%s sent.', $g( 'count' ), $g( 'count' ) === 1 ? '' : 's' );
            case 'sent_partial':
                return sprintf( '%d invoice%s sent, %d failed — see details below.', $g( 'count' ), $g( 'count' ) === 1 ? '' : 's', $g( 'fail' ) );
            case 'downgraded':
                return sprintf( 'Sweep complete: %d invoice%s across %d firm%s — %d member%s tagged unpaid, %d WordPress role%s removed, %d protected by a paid invoice.', $g( 'invoices' ), $g( 'invoices' ) === 1 ? '' : 's', $g( 'firms' ), $g( 'firms' ) === 1 ? '' : 's', $g( 'members' ), $g( 'members' ) === 1 ? '' : 's', $g( 'roles' ), $g( 'roles' ) === 1 ? '' : 's', $g( 'protected' ) );
            case 'synced':
                $syncMsg = sprintf( 'Checked %d invoice%s — %d updated, %d needs attention.', $g( 'count' ), $g( 'count' ) === 1 ? '' : 's', $g( 'updated' ), $g( 'attention' ) );
                if ( $g( 'orphans' ) > 0 ) {
                    $syncMsg .= sprintf(
                        ' Also found %d invoice%s in Stripe with no record here — see Setup → Stripe for the details.',
                        $g( 'orphans' ),
                        $g( 'orphans' ) === 1 ? '' : 's'
                    );
                }
                return $syncMsg;
            case 'marked_paid':
                $methodLabel = ucfirst( $gs( 'method' ) );
                return $g( 'full' )
                    ? sprintf( '%s recorded by %s — invoice now fully paid.', MyNJILGA_Invoicing::money( $g( 'amount' ) ), $methodLabel )
                    : sprintf( '%s recorded by %s — %s still outstanding.', MyNJILGA_Invoicing::money( $g( 'amount' ) ), $methodLabel, MyNJILGA_Invoicing::money( $g( 'remain' ) ) );
            case 'voided_invoice':
                return 'Invoice voided.';
            case 'nothing':
                return 'Nothing selected.';
            case 'error':
                return isset( $_GET['detail'] ) ? sanitize_text_field( wp_unslash( $_GET['detail'] ) ) : 'Something went wrong.';
            default:
                return '';
        }
    }

    // -------------------------------------------------------------------------
    // Page behaviour (tabs / search / filter / paginate / expand / send).
    // All styling comes from MyNJILGA_Admin_UI; see design.md.
    // -------------------------------------------------------------------------

    private static function scripts(): void {
        echo <<<'JS'
<script>
(function(){
  var root=document.querySelector('.njilga-ui');
  if(!root) return;
  var table=document.getElementById('njilga-table');
  if(!table) return;

  var rows=Array.prototype.slice.call(table.querySelectorAll('tr.njilga-row'));
  var previews={};
  rows.forEach(function(r){
    var pv=r.nextElementSibling;
    if(pv&&pv.classList.contains('njilga-preview')) previews[r.dataset.id]=pv;
  });

  var state={tab:'all',q:'',status:'all',page:1,per:10};

  function money(c){
    var n=(c/100).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g,',');
    return '$'+n;
  }
  function matches(r){
    if(state.tab!=='all'&&r.dataset.bucket!==state.tab) return false;
    if(state.status!=='all'&&r.dataset.status!==state.status) return false;
    if(state.q&&r.dataset.name.indexOf(state.q)===-1) return false;
    return true;
  }

  function apply(){
    var filtered=rows.filter(matches);
    var total=filtered.length;
    var pages=Math.max(1,Math.ceil(total/state.per));
    if(state.page>pages) state.page=pages;
    if(state.page<1) state.page=1;
    var start=(state.page-1)*state.per, end=start+state.per;

    rows.forEach(function(r){ r.hidden=true; if(previews[r.dataset.id]) previews[r.dataset.id].hidden=true; });
    filtered.forEach(function(r,i){
      if(i>=start&&i<end){
        r.hidden=false;
        if(r.classList.contains('open')&&previews[r.dataset.id]) previews[r.dataset.id].hidden=false;
      }
    });

    var nores=document.getElementById('njilga-noresults');
    if(nores) nores.hidden=(total!==0);

    var showing=document.getElementById('njilga-showing');
    if(showing){
      showing.textContent=total===0?'No firms to show'
        :('Showing '+(start+1)+'–'+Math.min(end,total)+' of '+total+' firm'+(total===1?'':'s'));
    }
    buildPager(pages);
    syncSelectAll(filtered);
  }

  function buildPager(pages){
    var el=document.getElementById('njilga-pager');
    if(!el) return;
    if(pages<=1){ el.innerHTML=''; return; }
    var p=state.page, html='';
    function btn(label,pg,dis,cur){
      return '<button type="button" class="njilga-pgbtn'+(cur?' cur':'')+'"'+(dis?' disabled':'')+' data-pg="'+pg+'">'+label+'</button>';
    }
    html+=btn('‹',p-1,p<=1,false);
    var set=[],i;
    for(i=1;i<=pages;i++){ if(i===1||i===pages||Math.abs(i-p)<=1) set.push(i); }
    var last=0;
    set.forEach(function(i){
      if(last&&i-last>1) html+='<span class="njilga-pgellip">…</span>';
      html+=btn(i,i,false,i===p); last=i;
    });
    html+=btn('›',p+1,p>=pages,false);
    el.innerHTML=html;
  }

  // ---- selection ----
  function checkable(filtered){
    var out=[];
    filtered.forEach(function(r){
      var cb=r.querySelector('.njilga-check');
      if(cb) out.push(cb);
    });
    return out;
  }
  function updateBulk(){
    var checked=table.querySelectorAll('.njilga-check:checked');
    var n=checked.length,total=0;
    checked.forEach(function(cb){ total+=parseInt(cb.dataset.cents||'0',10); });
    var bar=document.getElementById('njilga-bulkbar');
    var cnt=document.getElementById('njilga-selcount');
    var tot=document.getElementById('njilga-seltotal');
    var btn=document.getElementById('njilga-bulkcreate');
    if(cnt) cnt.textContent=n;
    if(tot) tot.textContent=money(total);
    if(btn) btn.textContent=n>0?('Create '+n+' Invoice'+(n===1?'':'s')):'Create Invoices';
    if(bar) bar.hidden=(n===0);
  }
  function syncSelectAll(filtered){
    var boxes=checkable(filtered);
    var all=boxes.length>0&&boxes.every(function(cb){return cb.checked;});
    ['njilga-all','njilga-all-head'].forEach(function(id){
      var el=document.getElementById(id);
      if(el){ el.checked=all; el.indeterminate=!all&&boxes.some(function(cb){return cb.checked;}); }
    });
    updateBulk();
  }
  function selectAll(on){
    var boxes=checkable(rows.filter(matches));
    boxes.forEach(function(cb){ cb.checked=on; });
    syncSelectAll(rows.filter(matches));
  }

  // ---- events ----
  root.querySelectorAll('.njilga-tab').forEach(function(tab){
    tab.addEventListener('click',function(){
      root.querySelectorAll('.njilga-tab').forEach(function(t){t.classList.remove('active');});
      tab.classList.add('active');
      state.tab=tab.dataset.tab; state.page=1; apply();
    });
  });
  var search=document.getElementById('njilga-search');
  if(search){
    search.addEventListener('input',function(){ state.q=search.value.trim().toLowerCase(); state.page=1; apply(); });
    search.addEventListener('keydown',function(e){ if(e.key==='Enter') e.preventDefault(); });
  }
  var statusSel=document.getElementById('njilga-status');
  if(statusSel) statusSel.addEventListener('change',function(){ state.status=statusSel.value; state.page=1; apply(); });
  var perSel=document.getElementById('njilga-per');
  if(perSel) perSel.addEventListener('change',function(){ state.per=parseInt(perSel.value,10)||10; state.page=1; apply(); });

  var pager=document.getElementById('njilga-pager');
  if(pager) pager.addEventListener('click',function(e){
    var b=e.target.closest('.njilga-pgbtn'); if(!b||b.disabled) return;
    state.page=parseInt(b.dataset.pg,10)||1; apply();
    table.scrollIntoView({behavior:'smooth',block:'start'});
  });

  // expand / collapse
  root.querySelectorAll('.njilga-expand').forEach(function(btn){
    btn.addEventListener('click',function(){
      var id=btn.dataset.id, row=table.querySelector('.njilga-row[data-id="'+id+'"]'), pv=previews[id];
      if(!row||!pv) return;
      var open=row.classList.toggle('open');
      pv.hidden=!open;
    });
  });

  // selection
  table.addEventListener('change',function(e){
    if(e.target.classList.contains('njilga-check')) syncSelectAll(rows.filter(matches));
  });
  ['njilga-all','njilga-all-head'].forEach(function(id){
    var el=document.getElementById(id);
    if(el) el.addEventListener('change',function(){ selectAll(el.checked); });
  });

  // send (per-row) via the hidden send form
  var sendForm=document.getElementById('njilga-inv-send');
  var sendIds=document.getElementById('njilga-send-ids');
  root.addEventListener('click',function(e){
    var b=e.target.closest('[data-send]'); if(!b||!sendForm||!sendIds) return;
    sendIds.innerHTML='<input type="hidden" name="row_ids[]" value="'+parseInt(b.dataset.send,10)+'">';
    sendForm.submit();
  });

  apply();
})();
</script>
JS;
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

    /**
     * Kept for backward compatibility — the redesigned UI folds approval
     * into "Create Invoice" (see handle_create), but the admin-post action
     * is still registered.
     */
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

    /**
     * One-click create: approve any selected drafts, then schedule creation
     * for the whole selection in a single action. Accepts a single row
     * (per-row "Create Invoice" button), a checkbox selection, or every
     * ready row for the year (the "Create Ready Invoices" button).
     */
    public static function handle_create(): void {
        self::guard( self::ACTION_CREATE );
        $duesYear = self::post_year();

        // Gateway must be ready before we approve anything — otherwise a
        // one-click create would freeze rows as approved with no order.
        $gateway = MyNJILGA_Invoicing::gateway();
        if ( ! $gateway->is_available() || $gateway->readiness_errors() ) {
            $errs   = $gateway->readiness_errors();
            $detail = ! $gateway->is_available()
                ? $gateway->name() . ' is not active.'
                : ( $errs[0] ?? $gateway->name() . ' is not ready.' );
            self::redirect( $duesYear, [ 'msg' => 'error', 'detail' => $detail ] );
        }

        // Only draft/approved rows can be created (approved covers a retry
        // of a row whose earlier creation errored out).
        $ids      = self::post_create_ids( $duesYear );
        $eligible = [];
        foreach ( $ids as $id ) {
            $row = MyNJILGA_Dues_Invoice_Table::get( $id );
            if ( $row && in_array( $row->status, [ MyNJILGA_Dues_Invoice_Table::STATUS_DRAFT, MyNJILGA_Dues_Invoice_Table::STATUS_APPROVED ], true ) ) {
                $eligible[] = (int) $row->id;
            }
        }
        if ( empty( $eligible ) ) {
            self::redirect( $duesYear, [ 'msg' => 'nothing' ] );
        }

        // Approve any drafts (no-op on already-approved rows), then create.
        MyNJILGA_Dues_Invoice_Table::mark_approved( $eligible );
        $r = MyNJILGA_Invoice_Creator::schedule( $eligible, $duesYear );

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
            if ( ! $row || ! in_array( $row->status, [ MyNJILGA_Dues_Invoice_Table::STATUS_CREATED, MyNJILGA_Dues_Invoice_Table::STATUS_SENT ], true ) ) {
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

    /**
     * The whole-year "Sync with Stripe" button and every per-row
     * "Refresh" button post here — a `single` value scopes the sweep to
     * one invoice row, its absence (the header button) means the whole
     * year. A single synchronous POST + redirect + summary notice, same
     * as every other bulk action on this page — no progress bar/polling.
     */
    public static function handle_sync(): void {
        self::guard( self::ACTION_SYNC );
        $duesYear = self::post_year();

        $onlyRowId = isset( $_POST['single'] ) ? (int) $_POST['single'] : 0;
        $result    = MyNJILGA_Stripe_Reconciler::sync_year( $duesYear, null, $onlyRowId > 0 ? $onlyRowId : null );

        // A full-year sync also looks the other way down the road: an
        // invoice sitting in Stripe with no row here. Skipped for a
        // single-row Refresh, which has no business paging the whole
        // year's invoices at Stripe.
        $orphans = 0;
        if ( $onlyRowId <= 0 ) {
            $scan    = MyNJILGA_Stripe_Reconciler::scan_for_orphans( $duesYear );
            $orphans = count( $scan['orphans'] );
        }

        self::redirect( $duesYear, [
            'msg'       => 'synced',
            'count'     => $result['checked'],
            'updated'   => $result['updated'],
            'attention' => $result['needs_attention'],
            'orphans'   => $orphans,
        ] );
    }

    /**
     * The ?view=mark_paid confirmation screen's submit. The
     * money-correctness split documented in CLAUDE.md governs everything
     * below:
     *
     *   - PARTIAL (this amount doesn't zero the balance): recorded
     *     ENTIRELY in WordPress — this handler writes its own ledger row
     *     directly and updates the invoice row's amount_paid_cents/
     *     amount_due_cents itself. No Stripe call at all (Stripe has no
     *     notion of a partial out-of-band payment on an invoice), and
     *     never settle() — the balance isn't zero yet.
     *
     *   - FULL / balance-zeroing: this handler writes NO ledger row.
     *     mark_paid_out_of_band() marks the Stripe invoice paid, which
     *     triggers Stripe's invoice.paid webhook — the ONLY code path
     *     allowed to write the ledger row for the payment that actually
     *     zeroes the balance (see class-stripe-webhook.php's
     *     handle_invoice_paid(), extended this run to read the
     *     njilga_* metadata this branch sets below). Getting this split
     *     wrong double-counts real money against the same invoice.
     *
     * Either way, a FluentCRM Company Note is logged immediately from
     * here (not from the webhook) — informational, and staff want to see
     * it right away rather than wait on the async webhook round-trip.
     */
    public static function handle_mark_paid(): void {
        self::guard( self::ACTION_MARK_PAID );

        $rowId    = isset( $_POST['row_id'] ) ? (int) $_POST['row_id'] : 0;
        $row      = $rowId > 0 ? MyNJILGA_Dues_Invoice_Table::get( $rowId ) : null;
        $duesYear = $row ? (int) $row->dues_year : self::post_year();

        if ( ! $row || ! in_array( $row->status, self::outstanding_statuses(), true ) ) {
            self::redirect( $duesYear, [ 'msg' => 'error', 'detail' => 'That invoice can\'t be marked paid right now — it may already be paid, voided, or no longer exists.' ] );
        }

        $balanceCents = (int) $row->amount_due_cents;
        if ( $balanceCents <= 0 ) {
            self::redirect( $duesYear, [ 'msg' => 'error', 'detail' => 'This invoice has no balance outstanding.' ] );
        }

        $amountCents = self::dollars_to_cents( isset( $_POST['amount'] ) ? wp_unslash( $_POST['amount'] ) : '' );
        if ( $amountCents <= 0 ) {
            self::redirect( $duesYear, [ 'msg' => 'error', 'detail' => 'Enter a payment amount greater than $0.' ] );
        }
        // Never accept an overpayment through this flow — clamp to what's
        // actually owed rather than reject a slightly-over amount.
        if ( $amountCents > $balanceCents ) {
            $amountCents = $balanceCents;
        }

        $method = isset( $_POST['method'] ) ? sanitize_key( (string) wp_unslash( $_POST['method'] ) ) : '';
        if ( ! in_array( $method, [ 'check', 'cash', 'wire', 'other' ], true ) ) {
            self::redirect( $duesYear, [ 'msg' => 'error', 'detail' => 'Choose a payment method.' ] );
        }

        $reference = isset( $_POST['reference'] ) ? sanitize_text_field( wp_unslash( $_POST['reference'] ) ) : '';
        if ( $method === 'check' && $reference === '' ) {
            self::redirect( $duesYear, [ 'msg' => 'error', 'detail' => 'Enter a check number — it\'s required when the method is Check.' ] );
        }

        $dateReceived = isset( $_POST['date_received'] ) ? sanitize_text_field( wp_unslash( $_POST['date_received'] ) ) : '';
        $parsedDate   = \DateTime::createFromFormat( 'Y-m-d', $dateReceived );
        $today        = current_time( 'Y-m-d' );
        if ( ! $parsedDate || $parsedDate->format( 'Y-m-d' ) !== $dateReceived || $dateReceived > $today ) {
            self::redirect( $duesYear, [ 'msg' => 'error', 'detail' => 'Enter a valid date received that isn\'t in the future.' ] );
        }

        $note = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';

        $actor        = wp_get_current_user();
        $recordedName = ( $actor && $actor->display_name !== '' ) ? $actor->display_name : 'a staff member';
        $occurredAt   = $dateReceived . ' ' . current_time( 'H:i:s' );

        // amount_due_cents already reflects any prior partials this
        // handler recorded, so this one comparison correctly covers both
        // "first and only payment" and "final payment after partials".
        $remainderAfter = $balanceCents - $amountCents;
        $isFull         = $remainderAfter <= 0;

        if ( ! $isFull ) {
            MyNJILGA_Dues_Payments_Table::record( [
                'invoice_row_id'      => (int) $row->id,
                'livemode'            => (bool) $row->livemode,
                'stripe_object_id'    => null,
                'kind'                => MyNJILGA_Dues_Payments_Table::KIND_MANUAL,
                'method'              => $method,
                'amount_cents'        => $amountCents,
                'status'              => 'succeeded',
                'occurred_at'         => $occurredAt,
                'recorded_by_user_id' => get_current_user_id(),
                'reference'           => $reference !== '' ? $reference : null,
            ] );

            MyNJILGA_Dues_Invoice_Table::update_gateway_fields( (int) $row->id, [
                'amount_paid_cents' => (int) $row->amount_paid_cents + $amountCents,
                'amount_due_cents'  => $remainderAfter,
            ] );
        } else {
            $invoiceId = (string) ( $row->gateway_invoice_id ?? '' );
            if ( $invoiceId === '' ) {
                self::redirect( $duesYear, [ 'msg' => 'error', 'detail' => 'This invoice has no Stripe invoice id on file — cannot mark it paid.' ] );
            }

            // $balanceCents (the balance BEFORE this payment) is exactly
            // what needs to reach Stripe/the webhook as the amount to log
            // — never Stripe's own cumulative amount_paid, which would
            // double-count any prior manually-recorded partial.
            $result = MyNJILGA_Invoicing::gateway()->mark_paid_out_of_band( $invoiceId, [
                'payment_method'             => $method,
                // The one existing metadata slot doubles as the wire
                // reference when Method is Wire — see
                // class-stripe-invoice-gateway.php's mark_paid_out_of_band().
                'check_number'               => $reference,
                'check_date'                 => $dateReceived,
                'recorded_by'                => $recordedName,
                'final_payment_amount_cents' => (string) $balanceCents,
            ] );

            if ( ! $result['ok'] ) {
                self::redirect( $duesYear, [ 'msg' => 'error', 'detail' => $result['error'] ?? MyNJILGA_Invoicing::gateway()->name() . ' could not mark the invoice paid.' ] );
            }

            // Reflect the payment locally right away — NOT status (that
            // stays exclusively the njilga_stripe_invoice_paid webhook
            // cascade's call, per this migration's single-settlement-
            // trigger design; no tag/role is granted here). Without this,
            // the row shows its stale pre-payment balance until the
            // webhook (or the next reconcile) catches up, and a second
            // Mark Paid attempt in that window would pass the "no balance
            // outstanding" guard and log a real duplicate manual payment.
            // (The failure path above always exits, so $result['ok'] is
            // guaranteed true here.)
            MyNJILGA_Dues_Invoice_Table::update_gateway_fields( (int) $row->id, [
                'amount_paid_cents' => (int) $row->amount_paid_cents + $balanceCents,
                'amount_due_cents'  => 0,
            ] );
        }

        MyNJILGA_Invoicing_Notes::log(
            (int) $row->fluentcrm_company_id,
            'Payment recorded (' . ucfirst( $method ) . ')',
            sprintf(
                '%s recorded by %s on %s via %s%s.%s%s',
                MyNJILGA_Invoicing::money( $amountCents ),
                $recordedName,
                $dateReceived,
                ucfirst( $method ),
                $reference !== '' ? ' (' . $reference . ')' : '',
                $isFull ? ' Invoice now fully paid.' : sprintf( ' %s still outstanding.', MyNJILGA_Invoicing::money( max( 0, $remainderAfter ) ) ),
                $note !== '' ? ' Note: ' . $note : ''
            )
        );

        self::redirect( $duesYear, [
            'msg'    => 'marked_paid',
            'amount' => $amountCents,
            'full'   => $isFull ? 1 : 0,
            'remain' => max( 0, $remainderAfter ),
            'method' => $method,
        ] );
    }

    /**
     * A single POST behind a JS confirm() (see void_button()) — voiding
     * ONE invoice is a small enough blast radius not to need a full
     * confirmation screen the way the downgrade sweep does.
     */
    public static function handle_void(): void {
        self::guard( self::ACTION_VOID );

        $rowId    = isset( $_POST['row_id'] ) ? (int) $_POST['row_id'] : 0;
        $row      = $rowId > 0 ? MyNJILGA_Dues_Invoice_Table::get( $rowId ) : null;
        $duesYear = $row ? (int) $row->dues_year : self::post_year();

        if ( ! $row || ! in_array( $row->status, self::outstanding_statuses(), true ) ) {
            self::redirect( $duesYear, [ 'msg' => 'error', 'detail' => 'That invoice can\'t be voided right now.' ] );
        }

        $invoiceId = (string) ( $row->gateway_invoice_id ?? '' );
        if ( $invoiceId === '' ) {
            self::redirect( $duesYear, [ 'msg' => 'error', 'detail' => 'This invoice has no Stripe invoice id on file — cannot void it.' ] );
        }

        $result = MyNJILGA_Invoicing::gateway()->void_invoice( $invoiceId );
        if ( ! $result['ok'] ) {
            self::redirect( $duesYear, [ 'msg' => 'error', 'detail' => $result['error'] ?? MyNJILGA_Invoicing::gateway()->name() . ' could not void the invoice.' ] );
        }

        MyNJILGA_Dues_Invoice_Table::update_gateway_fields( (int) $row->id, [
            'status'    => MyNJILGA_Dues_Invoice_Table::STATUS_VOIDED,
            'voided_at' => current_time( 'mysql' ),
        ] );

        $actor = wp_get_current_user();
        MyNJILGA_Invoicing_Notes::log(
            (int) $row->fluentcrm_company_id,
            'Invoice voided',
            sprintf( 'The %d dues invoice was voided by %s.', $duesYear, ( $actor && $actor->display_name !== '' ) ? $actor->display_name : 'a staff member' )
        );

        self::redirect( $duesYear, [ 'msg' => 'voided_invoice' ] );
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

    /** Same conversion MyNJILGA_Page_Settings::dollars_to_cents() uses for a posted price field. */
    private static function dollars_to_cents( $value ): int {
        $value = str_replace( [ ',', '$', ' ' ], '', (string) $value );
        return max( 0, (int) round( (float) $value * 100 ) );
    }

    /**
     * Selected row ids — or, with the "all" button, every row of the year
     * in the given status.
     *
     * @return array<int,int>
     */
    private static function post_ids( int $duesYear, string $statusForAll ): array {
        if ( ! empty( $_POST['all'] ) ) {
            $liveMode = ( MyNJILGA_Stripe_Connection::active_mode() === MyNJILGA_Stripe_Connection::MODE_LIVE );
            return array_map( static function ( $r ) { return (int) $r->id; }, MyNJILGA_Dues_Invoice_Table::get_by_year( $duesYear, [ $statusForAll ], $liveMode ) );
        }
        $ids = ( isset( $_POST['row_ids'] ) && is_array( $_POST['row_ids'] ) ) ? $_POST['row_ids'] : [];
        return array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
    }

    /**
     * Row ids for the create action: a single row (per-row button), every
     * ready row (the "all" button covers draft + approved), or a checkbox
     * selection.
     *
     * @return array<int,int>
     */
    private static function post_create_ids( int $duesYear ): array {
        if ( ! empty( $_POST['all'] ) ) {
            $liveMode = ( MyNJILGA_Stripe_Connection::active_mode() === MyNJILGA_Stripe_Connection::MODE_LIVE );
            $rows     = MyNJILGA_Dues_Invoice_Table::get_by_year( $duesYear, [ MyNJILGA_Dues_Invoice_Table::STATUS_DRAFT, MyNJILGA_Dues_Invoice_Table::STATUS_APPROVED ], $liveMode );
            return array_map( static function ( $r ) { return (int) $r->id; }, $rows );
        }
        if ( isset( $_POST['single'] ) ) {
            $id = (int) $_POST['single'];
            return $id > 0 ? [ $id ] : [];
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
