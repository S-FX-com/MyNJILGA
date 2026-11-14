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
    const ACTION_VOID      = 'my_njilga_dues_void';
    const ACTION_WIPE      = 'my_njilga_dues_wipe';
    const ACTION_MOVE_JOIN = 'my_njilga_dues_move_join';
    const ACTION_EMAIL_PICK = 'my_njilga_dues_email_pick';
    const ACTION_EMAIL_TEST = 'my_njilga_dues_email_test';
    const ACTION_EMAIL_SEND = 'my_njilga_dues_email_send';

    /** How long a "Generate emails" selection waits for staff on the review screen. */
    const EMAIL_BATCH_TTL = 6 * HOUR_IN_SECONDS;

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
        if ( $view === 'wipe' ) {
            self::render_wipe_confirm( $duesYear, $liveMode );
            echo '</div>';
            return;
        }
        if ( $view === 'email' ) {
            self::render_email_review( $duesYear, $liveMode );
            echo '</div>';
            return;
        }
        if ( $view === 'checks' ) {
            self::render_checks_view( $duesYear );
            echo '</div>';
            return;
        }

        self::render_notice();
        self::render_gateway_notices();

        $rows   = MyNJILGA_Dues_Invoice_Table::get_by_year( $duesYear, [], $liveMode );

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

        // Online joins are paid before their row exists and are nobody's
        // annual invoice: a year with only those (people joining for next
        // year after the cutover) still hasn't had its batch generated.
        if ( count( $views ) === $tally['joins'] ) {
            if ( ! MyNJILGA_Invoicing::year_is_open( $duesYear, false, MyNJILGA_Year_Wipe::was_wiped( $duesYear, $liveMode ) ) ) {
                self::render_closed_year( $duesYear, $liveMode );
                echo '</div>';
                return;
            }
            self::render_empty_state( $duesYear, $tally['joins'] );
            echo '</div>';
            return;
        }

        self::render_summary( $tally, self::annual_totals( $views ) );

        MyNJILGA_Create_Progress::render( $duesYear, $liveMode, self::page_url( $duesYear ) );
        MyNJILGA_Email_Run::render( $duesYear, $liveMode, self::page_url( $duesYear ) );

        self::render_workspace( $duesYear, $views, $canCreate, $liveMode );
        self::render_email_pick_form( $duesYear );
        self::render_downgrade_entry( $duesYear );
        self::render_wipe_entry( $duesYear, $liveMode );

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
        $ready       = $t['none'];
        $canBulk     = $canCreate && $ready > 0;
        $settingsUrl = MyNJILGA_Admin_Menu::url( MyNJILGA_Admin_Menu::SLUG_SETTINGS );

        echo '<div class="njilga-header">';
        echo '<div class="njilga-header-text">';
        echo '<h1 class="njilga-title">Generate Annual Dues Invoices</h1>';
        echo '<p class="njilga-subtitle">Each firm moves left to right: no invoice &rarr; invoice created &rarr; email sent &rarr; paid. Create invoices, email them, and watch the payments come in, all from here.</p>';
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
        // Years with rows, plus the selected one and next year (the
        // default). Not the current year unless it already has rows: a
        // late batch for a year in progress (a "last call" in January) is
        // reachable because its rows put it in years(), while a year this
        // plugin never billed isn't offered at all — see year_is_open().
        $years = array_merge(
            MyNJILGA_Dues_Invoice_Table::years(),
            [ $duesYear, MyNJILGA_Invoicing::default_dues_year() ]
        );
        $years = array_values( array_unique( array_map( 'intval', $years ) ) );
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
            [ 'label' => 'No Invoice',      'value' => $t['none'],      'variant' => 'default', 'icon' => 'users' ],
            [ 'label' => 'Invoice Created', 'value' => $t['created'],   'variant' => 'info',    'icon' => 'file' ],
            [ 'label' => 'Email Sent',      'value' => $t['sent'],      'variant' => 'info',    'icon' => 'inbox' ],
            [ 'label' => 'Paid',            'value' => $t['paid'],      'variant' => 'success', 'icon' => 'check-circle' ],
            [ 'label' => 'Needs Attention', 'value' => $t['attention'], 'variant' => $t['attention'] > 0 ? 'warning' : 'default', 'icon' => 'alert' ],
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
    private static function render_workspace( int $duesYear, array $views, bool $canCreate, bool $live ): void {
        $t = self::tally( $views );

        echo '<div class="njilga-card njilga-workspace">';

        // Card header + the pipeline, in the order a firm moves through it.
        echo '<div class="njilga-card-head"><h2 class="njilga-card-title">Law Firms</h2></div>';
        echo '<div class="njilga-tabs" role="tablist">';
        self::tab( 'all',       'All',             count( $views ) );
        self::tab( 'none',      'No Invoice',      $t['tabs']['none'] );
        self::tab( 'attention', 'Needs Attention', $t['tabs']['attention'] );
        self::tab( 'created',   'Invoice Created', $t['tabs']['created'] );
        self::tab( 'sent',      'Email Sent',      $t['tabs']['sent'] );
        self::tab( 'paid',      'Paid',            $t['tabs']['paid'] );
        echo '</div>';

        self::render_stage_notes( $duesYear, $views, $live );

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
                <label class="njilga-bulkbar-check"><input type="checkbox" id="njilga-all"> <span id="njilga-selcount">0</span> selected</label>
                <span class="njilga-bulkbar-sep"></span>
                <span class="njilga-bulkbar-total">Total <strong id="njilga-seltotal">$0.00</strong></span>
                <span class="njilga-bulkbar-actions">
                  <button type="button" class="njilga-btn njilga-btn-outline njilga-btn-sm" id="njilga-bulkemail" hidden>Generate Emails</button>
                  <button type="submit" class="njilga-btn njilga-btn-primary njilga-btn-sm" id="njilga-bulkcreate" hidden>Create Invoices</button>
                </span>
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

    /**
     * What each stage is and what to do there — one note per tab, shown
     * with it (the script switches them). No Invoice also carries the
     * Launch Wizard's review checks for the batch as it stands, and a way
     * to the pre-flight checks.
     *
     * @param array<int,array{row:object,c:array<string,mixed>}> $views
     */
    private static function render_stage_notes( int $duesYear, array $views, bool $live ): void {
        $rows    = array_map( static function ( $v ) { return $v['row']; }, $views );
        $review  = MyNJILGA_Launch_Checks::review( $rows );
        $issues  = array_values( array_filter( MyNJILGA_Launch_Checks::review_checks( $review, $duesYear, '' ), static function ( $c ) {
            return in_array( (string) ( $c['status'] ?? '' ), [ MyNJILGA_Launch_Checks::FAIL, MyNJILGA_Launch_Checks::WARN ], true );
        } ) );
        $checks  = sprintf( '<a href="%s">Run the pre-flight checks</a>', esc_url( self::page_url( $duesYear, [ 'view' => 'checks' ] ) ) );

        $notes = [
            'none'      => 'Firms with no invoice yet. Tick the ones to bill (or Select all) and <strong>Create</strong>: that freezes each firm\'s roster and price and issues its invoice in Stripe, in the background. Nothing is emailed yet. ' . $checks . ' first if this is the start of the year.',
            'attention' => 'Firms that won\'t be invoiced, or need a decision, as they stand: no Owner on the firm, nothing to bill, an error from Stripe, or an invoice voided, written off or swept as unpaid. Fix the cause in FluentCRM, then <strong>Refresh Firms</strong>.',
            'created'   => 'Created in Stripe, not emailed yet. Tick firms (or Select all) and <strong>Generate emails</strong>: you\'ll read the exact email each firm gets, can send yourself a test, then send them all in the background.',
            'sent'      => 'Emailed and awaiting payment. Payments arrive from Stripe on their own. To remind a firm, tick it and <strong>Generate emails</strong> to send its invoice again.',
            'paid'      => 'Paid in full, including people who joined online.',
        ];

        foreach ( $notes as $tab => $html ) {
            printf( '<div class="njilga-stage-note" data-stage="%s" hidden><p>%s</p>', esc_attr( $tab ), wp_kses_post( $html ) );
            if ( $tab === 'none' && $issues ) {
                echo '<ul class="njilga-list">';
                foreach ( $issues as $c ) {
                    printf(
                        '<li>%s %s%s</li>',
                        MyNJILGA_Admin_UI::validation( (string) $c['title'], false ),
                        isset( $c['detail'] ) && $c['detail'] !== '' ? '<span class="njilga-dim">— ' . esc_html( (string) $c['detail'] ) . '</span>' : '',
                        ''
                    );
                }
                echo '</ul>';
            }
            echo '</div>';
        }
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

        // A box wherever there's a bulk action: Create for a firm with no
        // invoice, Generate emails for one created (or emailed already).
        if ( $createable ) {
            $checkCell = sprintf( '<input type="checkbox" class="njilga-check" data-act="create" name="row_ids[]" value="%d" data-cents="%d" aria-label="Select %s">', $rowId, $cents, esc_attr( $name ) );
        } elseif ( ! empty( $c['emailable'] ) ) {
            $checkCell = sprintf( '<input type="checkbox" class="njilga-check" data-act="email" name="email_ids[]" value="%d" data-cents="%d" aria-label="Select %s">', $rowId, $cents, esc_attr( $name ) );
        } else {
            $checkCell = '';
        }

        // Firm name cell — name + badges + bill-to line.
        $badges = self::row_badges( $row, $snapshot );
        if ( $c['status'] === 'blocked' ) {
            $subline = '<span class="njilga-subline njilga-subline-warn">' . self::icon( 'alert' ) . ' No firm owner</span>';
        } elseif ( $billTo !== '—' ) {
            $subline = '<span class="njilga-subline">Bill to ' . self::bill_to_link( $row ) . '</span>';
        } else {
            $subline = '';
        }

        $duesCell = $cents > 0 ? esc_html( MyNJILGA_Invoicing::money( $cents ) ) : '<span class="njilga-dim">—</span>';

        echo '<tr class="njilga-row" data-id="' . $rowId . '" data-name="' . esc_attr( strtolower( $name ) ) . '" data-bucket="' . esc_attr( $c['bucket'] ) . '" data-status="' . esc_attr( $c['status'] ) . '" data-createable="' . ( $createable ? '1' : '0' ) . '">';
        echo '<td class="njilga-col-check">' . $checkCell . '</td>';
        printf(
            '<td class="njilga-firmcell"><button type="button" class="njilga-firmname njilga-expand" data-id="%d"><span class="njilga-firm-label">%s</span>%s</button>%s%s</td>',
            $rowId,
            esc_html( $name ),
            $badges,
            MyNJILGA_Admin_UI::company_icon_link( (int) $row->fluentcrm_company_id, $name ),
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
                    '<button type="button" class="njilga-btn njilga-btn-primary njilga-btn-sm" data-send="%d" title="Read the email, then send it">Email</button> %s %s %s %s',
                    $rowId,
                    self::view_link( $link ),
                    self::pdf_link( $pdf ),
                    self::void_button( $row ),
                    self::sync_button( $rowId )
                );

            case 'sent':
                return sprintf(
                    '%s %s <button type="button" class="njilga-btn njilga-btn-ghost njilga-btn-sm" data-send="%d" title="Read the email, then send it again">Email again</button> %s %s',
                    self::view_link( $link ),
                    self::pdf_link( $pdf ),
                    $rowId,
                    self::void_button( $row ),
                    self::sync_button( $rowId )
                );

            case 'processing':
                return sprintf(
                    '<span class="njilga-dim njilga-inline-status">%s Processing (ACH)</span> %s %s %s %s',
                    self::icon( 'refresh' ),
                    self::view_link( $link ),
                    self::pdf_link( $pdf ),
                    self::void_button( $row ),
                    self::sync_button( $rowId )
                );

            case 'paid':
            case 'downgraded':
            case 'voided':
            case 'uncollectible':
                $move = MyNJILGA_Paid_Year_Move::offered( $row ) ? ' ' . self::move_paid_button( $row ) : '';
                if ( $link === '' ) {
                    return $move !== '' ? ltrim( $move ) : '<span class="njilga-dim">—</span>';
                }
                return sprintf(
                    '<a class="njilga-btn njilga-btn-outline njilga-btn-sm" href="%s" target="_blank" rel="noopener">View Invoice</a> %s%s',
                    esc_url( $link ),
                    self::pdf_link( $pdf ),
                    $move
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

    /** A single POST + JS confirm() — voiding one invoice is a small enough blast radius not to need a full confirmation screen. */
    /** A paid invoice or online join from this year: count it toward the coming year instead. */
    private static function move_paid_button( object $row ): string {
        $to   = MyNJILGA_Invoicing::default_dues_year();
        $from = (int) $row->dues_year;
        $text = (string) $row->invoice_kind === MyNJILGA_Dues_Snapshot::KIND_JOIN
            ? sprintf( 'Count this online join toward %1$d dues instead of %2$d? Its members are tagged Dues Paid %1$d (keeping %2$d), and the %1$d invoices list them at $0 after the next Refresh Firms. Nothing changes in Stripe.', $to, $from )
            : sprintf( 'Count this paid %2$d invoice toward %1$d dues instead? It becomes the firm\'s %1$d invoice, already paid: the firm\'s %1$d preview is replaced, its members are tagged Dues Paid %1$d (keeping %2$d), and the firm is not billed again for %1$d. Nothing changes in Stripe — the Stripe invoice still reads %2$d.', $to, $from );
        return MyNJILGA_Admin_UI::action_form(
            self::ACTION_MOVE_JOIN,
            sprintf( 'Count toward %d', $to ),
            [ 'row_id' => (int) $row->id, 'dues_year' => $from ],
            'outline',
            '',
            $text,
            'sm'
        );
    }

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
            MyNJILGA_Admin_UI::company_link( (int) $row->fluentcrm_company_id, $name ),
            self::bill_to_link( $row ),
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
                        MyNJILGA_Admin_UI::contact_link( (int) ( $m['contact_id'] ?? 0 ), (string) ( $m['name'] ?? '' ) ),
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
                    MyNJILGA_Admin_UI::contact_link( (int) ( $m['contact_id'] ?? 0 ), (string) ( $m['name'] ?? '' ) ),
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

        // An online join (Applications → Online joins) was paid through
        // Checkout before its row was written: not a firm invoice, nothing
        // to create, send or void, so its own bucket, which the firm
        // counts and the progress bar leave out.
        if ( (string) ( $row->invoice_kind ?? '' ) === MyNJILGA_Dues_Snapshot::KIND_JOIN ) {
            $paid = $status === $T::STATUS_PAID;
            return array_merge( self::verdict(
                $paid ? 'paid' : 'attention',
                $paid ? 'paid' : 'join',
                [ $paid ? 'Paid online' : ucfirst( $status ), $paid ? 'success' : 'muted' ],
                $hasError ? [ (string) $row->last_error, false ] : [ 'Online join', true ],
                false
            ), [ 'join' => true ] );
        }

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
                // Only while a job can still plausibly have it: a chunk that was
                // killed leaves queued_at set for good, and the row must come
                // back as Ready (selectable) rather than "Creating…" forever.
                if ( $status === $T::STATUS_APPROVED && MyNJILGA_Invoice_Creator::is_actively_queued( (string) ( $row->queued_at ?? '' ), current_time( 'mysql' ) ) ) {
                    return self::verdict( 'none', 'creating', [ 'Creating…', 'info' ], [ 'Complete', true ], false );
                }
                return self::verdict( 'none', 'ready', [ 'Ready', 'success' ], [ 'Complete', true ], true );

            case $T::STATUS_CREATED:
                $val = $hasError ? [ (string) $row->last_error, false ] : [ 'Complete', true ];
                return array_merge( self::verdict( 'created', 'created', [ 'Invoice Created', 'info' ], $val, false ), [ 'emailable' => self::emailable( $row ) ] );

            case $T::STATUS_SENT:
                $sentOn = substr( (string) ( $row->sent_at ?? '' ), 0, 10 );
                $val    = $hasError ? [ (string) $row->last_error, false ] : [ 'Complete', true ];
                return array_merge( self::verdict( 'sent', 'sent', [ $sentOn !== '' ? 'Emailed ' . $sentOn : 'Emailed', 'info' ], $val, false ), [ 'emailable' => self::emailable( $row ) ] );

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
                // Awaiting money either way: with the emails if it was emailed.
                return self::verdict( (string) ( $row->sent_at ?? '' ) !== '' ? 'sent' : 'created', 'processing', [ $pillLabel, 'warning' ], $val, false );

            case $T::STATUS_PAID:
                return self::verdict( 'paid', 'paid', [ 'Paid', 'success' ], [ 'Complete', true ], false );

            case $T::STATUS_DOWNGRADED:
                return self::verdict( 'attention', 'downgraded', [ 'Downgraded', 'destructive' ], [ 'Unpaid — swept', false ], false );

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
    /** Whether an invoice can go into "Generate emails": it has a payment link to send. */
    private static function emailable( object $row ): bool {
        return (string) ( $row->hosted_invoice_url ?? '' ) !== '';
    }

    private static function verdict( string $bucket, string $status, array $badge, array $validation, bool $createable ): array {
        return [
            'join'       => false,
            'emailable'  => false,
            'bucket'     => $bucket,
            'status'     => $status,
            'badge'      => $badge,
            'validation' => $validation,
            'createable' => $createable,
        ];
    }

    /**
     * Roll the classified rows up into the summary/tab numbers. Online
     * joins are only counted as 'joins': each is one person's payment
     * (every student join sits under company 0), so letting them in would
     * count them as invoiced firms — 100% invoiced before any annual
     * invoice exists.
     *
     * @param array<int,array{row:object,c:array<string,mixed>}> $views
     * @return array<string,int>
     */
    private static function tally( array $views ): array {
        $t = [
            'firms' => 0, 'none' => 0, 'created' => 0, 'sent' => 0, 'paid' => 0, 'attention' => 0,
            'eligible' => 0, 'invoiced' => 0, 'joins' => 0,
            // Rows per tab, joins included (they sit under Paid).
            'tabs' => [ 'none' => 0, 'attention' => 0, 'created' => 0, 'sent' => 0, 'paid' => 0 ],
        ];
        $invoicedStatuses = [
            MyNJILGA_Dues_Invoice_Table::STATUS_CREATED, MyNJILGA_Dues_Invoice_Table::STATUS_SENT, MyNJILGA_Dues_Invoice_Table::STATUS_PROCESSING,
            MyNJILGA_Dues_Invoice_Table::STATUS_PAID, MyNJILGA_Dues_Invoice_Table::STATUS_DOWNGRADED,
        ];

        $firms         = [];
        $eligibleFirms = [];
        $invoicedFirms = [];
        foreach ( $views as $v ) {
            $bucket = (string) $v['c']['bucket'];
            if ( isset( $t['tabs'][ $bucket ] ) ) {
                $t['tabs'][ $bucket ]++;
            }
            // Online joins are someone's own payment, not part of the batch:
            // counted on their tab, never in the firm figures.
            if ( ! empty( $v['c']['join'] ) ) {
                $t['joins']++;
                continue;
            }
            $companyId = (int) $v['row']->fluentcrm_company_id;
            $firms[ $companyId ] = true;
            $t[ $bucket ] = (int) ( $t[ $bucket ] ?? 0 ) + 1;

            if ( $bucket === 'none' ) {
                $eligibleFirms[ $companyId ] = true;
            } elseif ( in_array( (string) $v['row']->status, $invoicedStatuses, true ) ) {
                $eligibleFirms[ $companyId ] = true;
                $invoicedFirms[ $companyId ] = true;
            }
        }

        $t['firms']    = count( $firms );
        $t['eligible'] = count( $eligibleFirms );
        $t['invoiced'] = count( $invoicedFirms );
        return $t;
    }

    /**
     * The batch's totals by status, in cents — the annual invoices only,
     * for the same reason tally() leaves online joins out: join money is
     * no part of the batch, and would read as Collected before a single
     * firm invoice had gone out.
     *
     * @param array<int,array{row:object,c:array<string,mixed>}> $views
     * @return array<string,int> status => sum(total_amount_cents)
     */
    private static function annual_totals( array $views ): array {
        $totals = [];
        foreach ( $views as $v ) {
            if ( ! empty( $v['c']['join'] ) ) {
                continue;
            }
            $status            = (string) $v['row']->status;
            $totals[ $status ] = (int) ( $totals[ $status ] ?? 0 ) + (int) $v['row']->total_amount_cents;
        }
        return $totals;
    }

    // -------------------------------------------------------------------------
    // Empty state
    // -------------------------------------------------------------------------

    /**
     * @param int $joins Online joins already paid for the year — not part of the batch, but worth saying.
     */
    private static function render_empty_state( int $duesYear, int $joins = 0 ): void {
        echo '<div class="njilga-card njilga-empty">';
        echo '<div class="njilga-empty-icon">' . self::icon( 'file' ) . '</div>';
        printf( '<h2 class="njilga-empty-title">No invoices generated for %d yet</h2>', $duesYear );
        printf(
            '<p class="njilga-empty-text">Generate this year\'s roster and pricing from FluentCRM Companies using the current <a href="%s">Dues &amp; Billing settings</a>. Safe to re-run at any time — firms already invoiced are never recomputed.</p>',
            esc_url( MyNJILGA_Admin_Menu::url( MyNJILGA_Admin_Menu::SLUG_SETTINGS ) )
        );
        if ( $joins > 0 ) {
            printf(
                '<p class="njilga-empty-text">Online joins already paid for %d: %d (see <a href="%s">Online joins</a>). The preview lists the people they cover at $0, so nobody is billed twice.</p>',
                $duesYear,
                $joins,
                esc_url( add_query_arg( 'tab', 'joins', MyNJILGA_Admin_Menu::url( MyNJILGA_Admin_Menu::SLUG_APPLICATIONS ) ) )
            );
        }
        self::render_generate_form( $duesYear, sprintf( 'Generate Preview for %d', $duesYear ), 'primary' );
        echo '</div>';
    }

    /**
     * A past year this plugin never billed: nothing to show and nothing
     * to generate — point at the year that is open instead.
     */
    private static function render_closed_year( int $duesYear, bool $live ): void {
        $next = MyNJILGA_Invoicing::default_dues_year();
        echo '<div class="njilga-card njilga-empty">';
        echo '<div class="njilga-empty-icon">' . self::icon( 'calendar' ) . '</div>';
        printf( '<h2 class="njilga-empty-title">No invoices for %d</h2>', $duesYear );
        printf(
            '<p class="njilga-empty-text">%s</p>',
            esc_html( MyNJILGA_Invoicing::closed_year_message( $duesYear, MyNJILGA_Year_Wipe::was_wiped( $duesYear, $live ) ) )
        );
        printf(
            '<a class="njilga-btn njilga-btn-primary njilga-btn-sm" href="%s">Go to %d invoicing</a>',
            esc_url( self::page_url( $next ) ),
            $next
        );
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

    /** The hidden form every "Email" button posts through: it opens the review screen for those invoices. */
    private static function render_email_pick_form( int $duesYear ): void {
        printf(
            '<form id="njilga-inv-emailpick" method="post" action="%s" hidden>
                <input type="hidden" name="action" value="%s">
                <input type="hidden" name="dues_year" value="%d">
                %s
                <span id="njilga-emailpick-ids"></span>
             </form>',
            esc_url( admin_url( 'admin-post.php' ) ),
            esc_attr( self::ACTION_EMAIL_PICK ),
            $duesYear,
            wp_nonce_field( self::ACTION_EMAIL_PICK, '_wpnonce', true, false )
        );
    }

    // -------------------------------------------------------------------------
    // Generate emails — the review screen
    // -------------------------------------------------------------------------

    private static function email_batch_key( string $token ): string {
        return 'njilga_email_batch_' . get_current_user_id() . '_' . preg_replace( '/[^A-Za-z0-9]/', '', $token );
    }

    /** @return array{year:int,live:bool,ids:array<int,int>}|null */
    private static function email_batch( string $token ): ?array {
        if ( $token === '' ) {
            return null;
        }
        $b = get_transient( self::email_batch_key( $token ) );
        return is_array( $b ) && ! empty( $b['ids'] ) ? $b : null;
    }

    private static function email_review_url( int $duesYear, string $token, array $args = [] ): string {
        return self::page_url( $duesYear, array_merge( [ 'view' => 'email', 'batch' => $token ], $args ) );
    }

    /**
     * "Generate emails": the invoices staff picked, the exact email each
     * firm gets (the same compose() that sends it), a test to an address
     * of their choosing, who it goes to, and — once confirmed — the send,
     * queued in the background (MyNJILGA_Email_Run).
     */
    private static function render_email_review( int $duesYear, bool $live ): void {
        $token = isset( $_GET['batch'] ) ? sanitize_key( wp_unslash( (string) $_GET['batch'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $batch = self::email_batch( $token );

        printf( '<p class="njilga-back"><a href="%s">&larr; Back to %d invoicing</a></p>', esc_url( self::page_url( $duesYear ) ), $duesYear );
        printf( '<h1 class="njilga-title">Email the %d invoices</h1>', $duesYear );
        self::render_notice();

        if ( ! $batch || (int) $batch['year'] !== $duesYear || (bool) $batch['live'] !== $live ) {
            MyNJILGA_Admin_UI::callout( 'This selection has expired, or was made in the other Stripe mode. Go back, tick the invoices again, and click <strong>Generate Emails</strong>.', 'info' );
            return;
        }

        $rows = array_values( MyNJILGA_Dues_Invoice_Table::get_many( $batch['ids'] ) );
        usort( $rows, static function ( $a, $b ) { return strcasecmp( MyNJILGA_Dues_Snapshot::company_name( $a ), MyNJILGA_Dues_Snapshot::company_name( $b ) ); } );
        $sel = MyNJILGA_Email_Run::sort_selection( $rows, $duesYear, $live );

        // Emails that can't be built (no address for the bill-to) are found
        // now, not halfway through the send.
        $built   = [];
        $cantBuild = [];
        foreach ( array_merge( $sel['unsent'], $sel['sent'] ) as $row ) {
            $m = MyNJILGA_Invoice_Sender::compose( $row );
            if ( empty( $m['ok'] ) ) {
                $cantBuild[] = [ 'row' => $row, 'reason' => (string) ( $m['error'] ?? 'The email could not be built.' ) ];
            } else {
                $built[ (int) $row->id ] = $m;
            }
        }
        $unsent = array_values( array_filter( $sel['unsent'], static function ( $r ) use ( $built ) { return isset( $built[ (int) $r->id ] ); } ) );
        $resent = array_values( array_filter( $sel['sent'], static function ( $r ) use ( $built ) { return isset( $built[ (int) $r->id ] ); } ) );
        $refused = array_merge( $sel['refused'], $cantBuild );

        $cents = 0;
        foreach ( array_merge( $unsent, $resent ) as $r ) {
            $cents += (int) $r->total_amount_cents;
        }
        MyNJILGA_Admin_UI::stat_cards( [
            [ 'label' => 'Not emailed yet',       'value' => count( $unsent ),  'variant' => 'info',    'icon' => 'inbox', 'sub' => MyNJILGA_Invoicing::money( $cents ) . ' invoiced in all' ],
            [ 'label' => 'Already emailed',       'value' => count( $resent ),  'variant' => 'default', 'icon' => 'refresh', 'sub' => 'Sent again only if you tick it below' ],
            [ 'label' => 'Can\'t be emailed',     'value' => count( $refused ), 'variant' => $refused ? 'warning' : 'default', 'icon' => 'alert' ],
        ], 3 );

        if ( ! $built ) {
            MyNJILGA_Admin_UI::callout( 'None of these invoices can be emailed — see why below.', 'warning' );
            self::render_email_refused( $refused );
            return;
        }

        // 1. Read it.
        $pick = isset( $_GET['firm'] ) ? (int) $_GET['firm'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $ids  = array_keys( $built );
        $show = in_array( $pick, $ids, true ) ? $pick : (int) $ids[0];
        $showRow = null;
        foreach ( $rows as $r ) {
            if ( (int) $r->id === $show ) {
                $showRow = $r;
            }
        }

        MyNJILGA_Admin_UI::section( '1. Read the email', sprintf( 'Exactly what each firm receives — built by the code that sends it. Pick any firm to read theirs. To change the wording: <a href="%s">Settings → Invoice Email</a>.', esc_url( add_query_arg( 'tab', 'email', MyNJILGA_Admin_Menu::url( MyNJILGA_Admin_Menu::SLUG_SETTINGS ) ) ) ) );
        printf(
            '<form method="get" action="%s" class="njilga-inline-fields"><input type="hidden" name="page" value="%s"><input type="hidden" name="dues_year" value="%d"><input type="hidden" name="view" value="email"><input type="hidden" name="batch" value="%s"><div class="njilga-field"><label for="njilga-email-firm"><strong>Firm</strong></label><select id="njilga-email-firm" name="firm" onchange="this.form.submit()">',
            esc_url( admin_url( 'admin.php' ) ),
            esc_attr( MyNJILGA_Admin_Menu::SLUG_INVOICING ),
            $duesYear,
            esc_attr( $token )
        );
        foreach ( $rows as $r ) {
            if ( isset( $built[ (int) $r->id ] ) ) {
                printf( '<option value="%d"%s>%s</option>', (int) $r->id, (int) $r->id === $show ? ' selected' : '', esc_html( MyNJILGA_Dues_Snapshot::company_name( $r ) ) );
            }
        }
        echo '</select></div><noscript><button type="submit" class="njilga-btn njilga-btn-outline">Show</button></noscript></form>';
        echo '<div class="njilga-card njilga-card-pad">';
        MyNJILGA_Page_Launch::render_message( $built[ $show ] );
        echo '</div>';

        // 2. Test it.
        $user = wp_get_current_user();
        MyNJILGA_Admin_UI::section( '2. Send yourself a test', 'The email above goes only to the address you enter — never to the firm, with no CC — marked TEST so it can\'t be mistaken for the real thing. Check it arrives, and isn\'t in spam.' );
        printf(
            '<form method="post" action="%s" class="njilga-inline-fields">
                <input type="hidden" name="action" value="%s"><input type="hidden" name="dues_year" value="%d"><input type="hidden" name="batch" value="%s"><input type="hidden" name="row_id" value="%d">%s
                <div class="njilga-field"><label for="njilga-email-test"><strong>Send the test to</strong></label><input type="email" id="njilga-email-test" name="to" value="%s" required></div>
                <button type="submit" class="njilga-btn njilga-btn-outline">Send me a test</button>
             </form>',
            esc_url( admin_url( 'admin-post.php' ) ),
            esc_attr( self::ACTION_EMAIL_TEST ),
            $duesYear,
            esc_attr( $token ),
            $show,
            wp_nonce_field( self::ACTION_EMAIL_TEST, '_wpnonce', true, false ),
            esc_attr( (string) $user->user_email )
        );

        // 3. Who it goes to.
        MyNJILGA_Admin_UI::section( '3. Who gets it', '', count( $unsent ) + count( $resent ) );
        echo '<div class="njilga-card njilga-table-boxed"><div class="njilga-tablewrap"><table class="njilga-table"><thead><tr><th>Firm</th><th>To</th><th>CC</th><th>Status</th><th class="njilga-col-num">Total</th></tr></thead><tbody>';
        foreach ( array_merge( $unsent, $resent ) as $r ) {
            $m  = $built[ (int) $r->id ];
            $cc = 0;
            foreach ( (array) $m['headers'] as $h ) {
                if ( stripos( $h, 'Cc:' ) === 0 ) {
                    $cc = count( array_filter( array_map( 'trim', explode( ',', substr( $h, 3 ) ) ) ) );
                }
            }
            $sentOn = substr( (string) ( $r->sent_at ?? '' ), 0, 10 );
            printf(
                '<tr><td>%s</td><td>%s<span class="njilga-subline">%s</span></td><td>%s</td><td>%s</td><td class="njilga-col-num">%s</td></tr>',
                MyNJILGA_Admin_UI::company_link( (int) $r->fluentcrm_company_id, MyNJILGA_Dues_Snapshot::company_name( $r ) ),
                MyNJILGA_Admin_UI::contact_link( (int) ( $m['bill_to']['contact_id'] ?? 0 ), (string) ( $m['bill_to']['name'] ?? '' ) ),
                esc_html( (string) $m['to'] ),
                $cc > 0 ? (int) $cc : MyNJILGA_Admin_UI::blank(),
                (string) $r->status === MyNJILGA_Dues_Invoice_Table::STATUS_SENT
                    ? self::pill( 'Emailed ' . $sentOn . ' — again only if ticked', 'muted' )
                    : self::pill( 'Not emailed yet', 'info' ),
                esc_html( MyNJILGA_Invoicing::money( (int) $r->total_amount_cents ) )
            );
        }
        echo '</tbody></table></div></div>';
        self::render_email_refused( $refused );

        // 4. Send.
        MyNJILGA_Admin_UI::section( '4. Send' );
        $blocked = '';
        if ( ! $live ) {
            $blocked = '<strong>Sending to firms is switched off in Test mode</strong> — they would be emailed a test payment link. The preview and test above still work. Switch Stripe to Live (Settings → Payments) to send for real.';
        } else {
            $schedule = MyNJILGA_Launch_Schedule::get( $duesYear );
            if ( $schedule && MyNJILGA_Launch_Schedule::is_open( (string) ( $schedule['status'] ?? '' ) ) ) {
                $blocked = sprintf( '<strong>A scheduled send for %d is still %s in the Launch Wizard.</strong> Let it finish, or cancel it there, so no firm is emailed by both.', $duesYear, esc_html( (string) $schedule['status'] ) );
            }
        }
        if ( $blocked !== '' ) {
            MyNJILGA_Admin_UI::callout( $blocked, 'warning' );
            return;
        }

        $defaultResend = ! $unsent && $resent;
        printf(
            '<div class="njilga-card njilga-card-pad"><form method="post" action="%s" class="njilga-confirm-form">
                <input type="hidden" name="action" value="%s"><input type="hidden" name="dues_year" value="%d"><input type="hidden" name="batch" value="%s">%s
                %s
                <label class="njilga-ack"><input type="checkbox" name="confirm" value="1" required> I have read the email (and sent myself a test). Email these firms now.</label>
                <div class="njilga-confirm-actions"><button type="submit" class="njilga-btn njilga-btn-primary" id="njilga-email-go" data-unsent="%d" data-resent="%d">%s <span>Email %d firm%s</span></button> <a class="njilga-btn njilga-btn-outline" href="%s">Cancel</a></div>
                <p class="njilga-help">They go out in the background, %d at a time, and this page shows their progress — you can leave it while they send. An invoice paid or changed before its turn is skipped, and none is emailed twice.</p>
             </form></div>',
            esc_url( admin_url( 'admin-post.php' ) ),
            esc_attr( self::ACTION_EMAIL_SEND ),
            $duesYear,
            esc_attr( $token ),
            wp_nonce_field( self::ACTION_EMAIL_SEND, '_wpnonce', true, false ),
            $resent
                ? sprintf( '<p><label class="njilga-check-label"><input type="checkbox" name="resend" value="1"%s> Also email the %d invoice%s already emailed, again</label></p>', $defaultResend ? ' checked' : '', count( $resent ), count( $resent ) === 1 ? '' : 's' )
                : '',
            count( $unsent ),
            count( $resent ),
            self::icon( 'inbox' ),
            count( $unsent ) + ( $defaultResend ? count( $resent ) : 0 ),
            ( count( $unsent ) + ( $defaultResend ? count( $resent ) : 0 ) ) === 1 ? '' : 's',
            esc_url( self::page_url( $duesYear ) ),
            MyNJILGA_Email_Run::CHUNK
        );
        // The button says how many go; ticking "again" changes that. Sending
        // once only: a second click while the first posts does nothing.
        echo <<<'JS'
<script>
(function(){
  var go=document.getElementById('njilga-email-go'); if(!go) return;
  var form=go.form, again=form.querySelector('input[name="resend"]'), label=go.querySelector('span');
  function sync(){ var n=parseInt(go.dataset.unsent,10)+((again&&again.checked)?parseInt(go.dataset.resent,10):0); label.textContent='Email '+n+' firm'+(n===1?'':'s'); }
  if(again) again.addEventListener('change',sync);
  var busy=false;
  form.addEventListener('submit',function(e){ if(busy){ e.preventDefault(); return; } busy=true; go.classList.add('njilga-busy'); label.textContent='Queuing…'; });
})();
</script>
JS;
    }

    /** @param array<int,array{row:object,reason:string}> $refused */
    private static function render_email_refused( array $refused ): void {
        if ( ! $refused ) {
            return;
        }
        MyNJILGA_Admin_UI::section( 'Left out', 'Not emailed — fix the cause, then generate the emails for these again.', count( $refused ) );
        echo '<div class="njilga-card njilga-table-boxed"><div class="njilga-tablewrap"><table class="njilga-table"><thead><tr><th>Firm</th><th>Why</th></tr></thead><tbody>';
        foreach ( $refused as $x ) {
            printf(
                '<tr><td>%s</td><td>%s</td></tr>',
                MyNJILGA_Admin_UI::company_link( (int) $x['row']->fluentcrm_company_id, MyNJILGA_Dues_Snapshot::company_name( $x['row'] ) ),
                esc_html( $x['reason'] )
            );
        }
        echo '</tbody></table></div></div>';
    }

    /**
     * The Launch Wizard's pre-flight checks, on this page: is the site
     * ready to bill this year. Behind a link rather than on every load —
     * several checks ask Stripe.
     */
    private static function render_checks_view( int $duesYear ): void {
        printf( '<p class="njilga-back"><a href="%s">&larr; Back to %d invoicing</a></p>', esc_url( self::page_url( $duesYear ) ), $duesYear );
        printf( '<h1 class="njilga-title">Is the site ready to bill %d?</h1>', $duesYear );
        $checks = MyNJILGA_Launch_Checks::evaluate( MyNJILGA_Launch_Checks::gather_facts( $duesYear ) );
        $n      = MyNJILGA_Launch_Checks::summarize( $checks );
        MyNJILGA_Admin_UI::stat_cards( [
            [ 'label' => 'To fix',            'value' => $n['fail'], 'variant' => $n['fail'] > 0 ? 'destructive' : 'success', 'icon' => 'alert' ],
            [ 'label' => 'Worth a look',      'value' => $n['warn'], 'variant' => $n['warn'] > 0 ? 'warning' : 'default', 'icon' => 'alert' ],
            [ 'label' => 'Passed',            'value' => $n['ok'],   'variant' => 'success', 'icon' => 'check-circle' ],
            [ 'label' => 'To confirm by eye', 'value' => $n['info'], 'variant' => 'info', 'icon' => 'file' ],
        ], 4 );
        MyNJILGA_Page_Launch::render_checks_table( $checks );
        printf(
            '<div class="njilga-actions"><a class="njilga-btn njilga-btn-outline" href="%s">Run the checks again</a> <a class="njilga-btn njilga-btn-primary" href="%s">Back to %d invoicing</a></div>',
            esc_url( self::page_url( $duesYear, [ 'view' => 'checks' ] ) ),
            esc_url( self::page_url( $duesYear ) ),
            $duesYear
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
            $p['remove_roles'] ? ' and removes their WordPress membership roles' : '',
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
            ? '<li>Every WordPress membership role (each role mapped in Settings, now or before) is removed where they have a linked account (setting: <em>Remove roles on downgrade</em> is on).</li>'
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
                MyNJILGA_Admin_UI::company_link( (int) $row->fluentcrm_company_id, MyNJILGA_Dues_Snapshot::company_name( $row ) ),
                self::kind_pill( $row ),
                self::pill( ucfirst( (string) $row->status ), 'muted' ),
                self::bill_to_link( $row ),
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
    // Wipe a year billed in error
    // -------------------------------------------------------------------------

    /**
     * Offered only for a year before the coming one, with nothing paid or
     * acted on — a year the association never really billed here.
     */
    private static function render_wipe_entry( int $duesYear, bool $live ): void {
        if ( ! MyNJILGA_Year_Wipe::year_qualifies( $duesYear ) ) {
            return;
        }
        $p = MyNJILGA_Year_Wipe::preview( $duesYear, $live );
        if ( ! $p['delete'] && ! $p['void'] ) {
            return;
        }

        echo '<div class="njilga-danger-card">';
        echo '<div class="njilga-danger-head">' . self::icon( 'alert' ) . '<h2>Wipe ' . (int) $duesYear . ' invoices</h2></div>';
        printf(
            '<p>If %d was billed by mistake — the dues were already covered — this removes the %d dues invoices and previews here: <strong>%d</strong> open invoice%s voided in Stripe, <strong>%d</strong> other record%s deleted, across <strong>%d</strong> firm%s.%s Online joins are kept.</p>',
            $duesYear,
            $duesYear,
            count( $p['void'] ), count( $p['void'] ) === 1 ? '' : 's',
            count( $p['delete'] ), count( $p['delete'] ) === 1 ? '' : 's',
            (int) $p['firms'], (int) $p['firms'] === 1 ? '' : 's',
            $p['kept'] ? sprintf( ' <strong>%d</strong> invoice%s with a payment against %s %s kept and listed for you to settle in Stripe.', count( $p['kept'] ), count( $p['kept'] ) === 1 ? '' : 's', count( $p['kept'] ) === 1 ? 'it' : 'them', count( $p['kept'] ) === 1 ? 'is' : 'are' ) : ''
        );
        printf(
            '<a class="njilga-btn njilga-btn-danger njilga-btn-sm" href="%s">Review &amp; wipe %d →</a>',
            esc_url( self::page_url( $duesYear, [ 'view' => 'wipe' ] ) ),
            $duesYear
        );
        echo '</div>';
    }

    private static function render_wipe_confirm( int $duesYear, bool $live ): void {
        $p = MyNJILGA_Year_Wipe::preview( $duesYear, $live );

        printf( '<p class="njilga-back"><a href="%s">&larr; Back to %d invoicing</a></p>', esc_url( self::page_url( $duesYear ) ), $duesYear );
        printf( '<h1 class="njilga-title njilga-title-danger">Wipe the %d invoices</h1>', $duesYear );
        self::render_notice();

        MyNJILGA_Admin_UI::stat_cards( [
            [ 'label' => 'Voided in Stripe, then removed', 'value' => count( $p['void'] ),   'variant' => 'destructive', 'icon' => 'file' ],
            [ 'label' => 'Previews & records removed',     'value' => count( $p['delete'] ), 'variant' => 'destructive', 'icon' => 'file' ],
            [ 'label' => 'Paid or in progress — kept',     'value' => count( $p['kept'] ),   'variant' => 'warning',     'icon' => 'alert' ],
            [ 'label' => 'Online joins kept',              'value' => (int) $p['joins'],     'variant' => 'success',     'icon' => 'check-circle' ],
        ] );

        echo '<div class="njilga-danger-card"><p><strong>What will happen</strong> (' . ( $live ? 'Live' : 'Test' ) . ' mode only):</p><ul class="njilga-list">';
        printf( '<li>Every created or sent %d invoice (%s outstanding) is <strong>voided in Stripe</strong>, so it can no longer be paid. Stripe keeps the voided invoice in its own records.</li>', $duesYear, esc_html( MyNJILGA_Invoicing::money( (int) $p['open_cents'] ) ) );
        printf( '<li>Every %d dues invoice, preview draft and exception is then <strong>deleted here</strong> — gone from Invoicing, Payments, the Dashboard and every member\'s page.</li>', $duesYear );
        echo '<li>Online joins, and any invoice with a payment against it (paid, processing, partly paid, swept or written off), are <strong>kept</strong> — listed below so you can refund or keep each one in Stripe. Member tags and roles are not touched.</li>';
        echo '<li>A note is left on each firm\'s FluentCRM Company record.</li>';
        printf( '<li>%d is then closed: no invoices can be generated for it again. Invoicing carries on with %d.</li>', $duesYear, MyNJILGA_Invoicing::default_dues_year() );
        echo '</ul></div>';

        if ( $p['kept'] ) {
            MyNJILGA_Admin_UI::section( 'Kept — settle these in Stripe', 'A payment was made or started against each of these. Refund it in Stripe if it was paid in error, or leave it.' );
            echo '<div class="njilga-card"><div class="njilga-tablewrap"><table class="njilga-table"><thead><tr><th>Firm</th><th>Status</th><th>Why it is kept</th><th class="njilga-col-num">Total</th></tr></thead><tbody>';
            foreach ( $p['kept'] as $k ) {
                printf(
                    '<tr><td>%s</td><td>%s</td><td>%s</td><td class="njilga-col-num">%s</td></tr>',
                    MyNJILGA_Admin_UI::company_link( (int) $k['row']->fluentcrm_company_id, MyNJILGA_Dues_Snapshot::company_name( $k['row'] ) ),
                    self::pill( ucfirst( (string) $k['row']->status ), 'muted' ),
                    esc_html( $k['reason'] ),
                    esc_html( MyNJILGA_Invoicing::money( (int) $k['row']->total_amount_cents ) )
                );
            }
            echo '</tbody></table></div></div>';
        }

        if ( $p['stop'] ) {
            echo '<div class="njilga-callout njilga-callout-error"><p><strong>This year can\'t be wiped right now:</strong></p><ul class="njilga-list">';
            foreach ( $p['stop'] as $reason ) {
                printf( '<li>%s</li>', esc_html( (string) $reason ) );
            }
            echo '</ul></div>';
            return;
        }

        if ( ! $p['delete'] && ! $p['void'] ) {
            printf( '<p><strong>Nothing to wipe</strong> — %d has no dues invoices or previews in this mode.</p>', $duesYear );
            return;
        }

        if ( $p['void'] ) {
            echo '<div class="njilga-card"><div class="njilga-tablewrap"><table class="njilga-table"><thead><tr><th>Firm</th><th>Status</th><th>Bill to</th><th class="njilga-col-num">Total</th></tr></thead><tbody>';
            foreach ( $p['void'] as $row ) {
                printf(
                    '<tr><td>%s</td><td>%s</td><td>%s</td><td class="njilga-col-num">%s</td></tr>',
                    MyNJILGA_Admin_UI::company_link( (int) $row->fluentcrm_company_id, MyNJILGA_Dues_Snapshot::company_name( $row ) ),
                    self::pill( ucfirst( (string) $row->status ), 'muted' ),
                    self::bill_to_link( $row ),
                    esc_html( MyNJILGA_Invoicing::money( (int) $row->total_amount_cents ) )
                );
            }
            echo '</tbody></table></div></div>';
        }

        printf(
            '<form method="post" action="%s" class="njilga-confirm-form">
                <input type="hidden" name="action" value="%s">
                <input type="hidden" name="dues_year" value="%d">
                <input type="hidden" name="confirmed" value="1">
                %s
                <div class="njilga-field"><label for="njilga-wipe-year"><strong>Type %d to confirm</strong></label>
                <input type="text" id="njilga-wipe-year" name="confirm_year" class="njilga-input-sm" inputmode="numeric" autocomplete="off" required pattern="%d"></div>
                <div class="njilga-confirm-actions">
                    <button type="submit" class="njilga-btn njilga-btn-danger">Wipe %d — void %d, remove %d</button>
                    <a class="njilga-btn njilga-btn-outline" href="%s">Cancel</a>
                </div>
             </form>',
            esc_url( admin_url( 'admin-post.php' ) ),
            esc_attr( self::ACTION_WIPE ),
            $duesYear,
            wp_nonce_field( self::ACTION_WIPE, '_wpnonce', true, false ),
            $duesYear,
            $duesYear,
            $duesYear,
            count( $p['void'] ),
            count( $p['void'] ) + count( $p['delete'] ),
            esc_url( self::page_url( $duesYear ) )
        );
    }

    // -------------------------------------------------------------------------
    // Mark Paid (by check/cash/wire) — confirmation screen
    // -------------------------------------------------------------------------

    // -------------------------------------------------------------------------
    // Badges / small parts
    // -------------------------------------------------------------------------

    private static function row_badges( object $row, array $snapshot ): string {
        $out  = self::kind_pill( $row );
        $mode = (string) ( $row->billing_mode ?? 'firm' );
        if ( $mode !== MyNJILGA_Dues_Settings::MODE_FIRM && (string) ( $row->invoice_kind ?? '' ) !== MyNJILGA_Dues_Snapshot::KIND_JOIN ) {
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
            case MyNJILGA_Dues_Snapshot::KIND_JOIN:
                // Paid through Stripe Checkout by the member themselves
                // (Applications → Online joins) — nothing to create or send.
                return self::pill( 'online join', 'info' );
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

    /** The bill-to name, linked to their FluentCRM contact when there is one. */
    private static function bill_to_link( object $row ): string {
        $label = self::bill_to_label( $row );
        if ( $label === '—' ) {
            return $label;
        }
        $id = (int) MyNJILGA_Dues_Snapshot::bill_to( $row )['contact_id'] ?: (int) ( $row->bill_to_contact_id ?? 0 );
        return MyNJILGA_Admin_UI::contact_link( $id, $label );
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
            'voided_invoice'  => 'success',
            'wiped'           => 'success',
            'join_moved'      => 'success',
            'emails_queued'   => 'success',
            'email_test_sent' => 'success',
            'invoice_moved'   => 'success',
            'wiped_partial'   => 'warning',
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
                return sprintf( '%d invoice%s queued for creation in the background. Progress is shown below — you can leave this page while it runs.', $g( 'count' ), $g( 'count' ) === 1 ? '' : 's' );
            case 'created':
                return sprintf( '%d invoice%s created.', $g( 'count' ), $g( 'count' ) === 1 ? '' : 's' );
            case 'created_partial':
                return sprintf( '%d invoice%s created, %d failed — see "Needs Attention".', $g( 'count' ), $g( 'count' ) === 1 ? '' : 's', $g( 'fail' ) );
            case 'sent':
                return sprintf( '%d invoice%s sent.', $g( 'count' ), $g( 'count' ) === 1 ? '' : 's' );
            case 'sent_partial':
                return sprintf( '%d invoice%s sent, %d failed — see details below.', $g( 'count' ), $g( 'count' ) === 1 ? '' : 's', $g( 'fail' ) );
            case 'emails_queued':
                return sprintf( '%d invoice email%s queued. They go out in the background — progress is shown below, and you can leave this page while they send.', $g( 'count' ), $g( 'count' ) === 1 ? '' : 's' );
            case 'email_test_sent':
                return sprintf( 'Test sent to %s. Check it arrived (and isn\'t in spam) before sending to the firms.', isset( $_GET['to'] ) ? sanitize_email( wp_unslash( (string) $_GET['to'] ) ) : 'you' );
            case 'invoice_moved':
                return sprintf(
                    'Paid invoice moved: it is now the firm\'s %d invoice, already paid, and %d member%s %s tagged Dues Paid %d. Refresh Firms leaves it as it is.',
                    MyNJILGA_Invoicing::default_dues_year(),
                    $g( 'n' ), $g( 'n' ) === 1 ? '' : 's', $g( 'n' ) === 1 ? 'is' : 'are',
                    MyNJILGA_Invoicing::default_dues_year()
                );
            case 'join_moved':
                return sprintf(
                    'Online join moved: it now counts toward %d dues, and %d member%s %s tagged Dues Paid %d. Click Refresh Firms so the %d invoices list %s at $0.',
                    MyNJILGA_Invoicing::default_dues_year(),
                    $g( 'n' ), $g( 'n' ) === 1 ? '' : 's', $g( 'n' ) === 1 ? 'is' : 'are',
                    MyNJILGA_Invoicing::default_dues_year(),
                    MyNJILGA_Invoicing::default_dues_year(),
                    $g( 'n' ) === 1 ? 'them' : 'everyone it covers'
                );
            case 'wiped':
            case 'wiped_partial':
                return sprintf(
                    '%d wiped: %d invoice%s voided in Stripe and %d record%s removed across %d firm%s.%s',
                    $g( 'wiped_year' ),
                    $g( 'voided' ), $g( 'voided' ) === 1 ? '' : 's',
                    $g( 'deleted' ), $g( 'deleted' ) === 1 ? '' : 's',
                    $g( 'firms' ), $g( 'firms' ) === 1 ? '' : 's',
                    ( $g( 'kept' ) > 0 ? sprintf( ' %d invoice%s with a payment against %s %s kept.', $g( 'kept' ), $g( 'kept' ) === 1 ? '' : 's', $g( 'kept' ) === 1 ? 'it' : 'them', $g( 'kept' ) === 1 ? 'was' : 'were' ) : '' ) .
                    ( $msg === 'wiped_partial' ? sprintf( ' %d invoice%s could not be voided and %s left in place — see below, then run the wipe again.', $g( 'failed' ), $g( 'failed' ) === 1 ? '' : 's', $g( 'failed' ) === 1 ? 'was' : 'were' ) : '' )
                );
            case 'downgraded':
                return sprintf( 'Sweep complete: %d invoice%s across %d firm%s — %d member%s tagged unpaid, %d WordPress role%s removed, %d protected as already paid.', $g( 'invoices' ), $g( 'invoices' ) === 1 ? '' : 's', $g( 'firms' ), $g( 'firms' ) === 1 ? '' : 's', $g( 'members' ), $g( 'members' ) === 1 ? '' : 's', $g( 'roles' ), $g( 'roles' ) === 1 ? '' : 's', $g( 'protected' ) );
            case 'synced':
                $syncMsg = sprintf( 'Checked %d invoice%s — %d updated, %d needs attention.', $g( 'count' ), $g( 'count' ) === 1 ? '' : 's', $g( 'updated' ), $g( 'attention' ) );
                if ( isset( $_GET['orphan_scan'] ) && $g( 'orphan_scan' ) === 0 ) {
                    $syncMsg .= ' Stripe could not be fully read for invoices missing from this site, so that check was skipped — the previous result on Setup → Stripe still stands.';
                } elseif ( $g( 'orphans' ) > 0 ) {
                    $syncMsg .= sprintf(
                        ' Also found %d invoice%s in Stripe with no record here — see Setup → Stripe for the details.',
                        $g( 'orphans' ),
                        $g( 'orphans' ) === 1 ? '' : 's'
                    );
                }
                return $syncMsg;
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

  // Create: say at once that it's working, and stop a second click from
  // queuing the same firms twice. Buttons are dimmed rather than disabled
  // — a disabled submitter would drop its own name/value ("all",
  // "single") from what the form sends.
  var createForm=document.getElementById('njilga-inv-form');
  if(createForm){
    var submitting=false;
    createForm.addEventListener('submit',function(e){
      if(submitting){ e.preventDefault(); return; }
      submitting=true;
      var btn=e.submitter;
      var n=btn&&btn.name==='single'?1:(btn&&btn.name==='all'?null:createForm.querySelectorAll('input.njilga-check[data-act="create"]:checked').length);
      document.querySelectorAll('button[form="njilga-inv-form"],#njilga-inv-form button[type="submit"]').forEach(function(b){ b.classList.add('njilga-busy'); b.setAttribute('aria-disabled','true'); });
      if(btn){ btn.textContent=n===1?'Queuing…':(n?'Queuing '+n+' invoices…':'Queuing invoices…'); }
    });
  }

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
    var n=checked.length,total=0,nc=0,ne=0;
    checked.forEach(function(cb){
      total+=parseInt(cb.dataset.cents||'0',10);
      if(cb.dataset.act==='email') ne++; else nc++;
    });
    var bar=document.getElementById('njilga-bulkbar');
    var cnt=document.getElementById('njilga-selcount');
    var tot=document.getElementById('njilga-seltotal');
    var btn=document.getElementById('njilga-bulkcreate');
    var em=document.getElementById('njilga-bulkemail');
    if(cnt) cnt.textContent=n;
    if(tot) tot.textContent=money(total);
    if(btn){ btn.hidden=(nc===0); btn.textContent='Create '+nc+' Invoice'+(nc===1?'':'s'); }
    if(em){ em.hidden=(ne===0); em.textContent='Generate '+ne+' Email'+(ne===1?'':'s'); }
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
      state.tab=tab.dataset.tab; state.page=1;
      // A selection belongs to the stage it was made in.
      table.querySelectorAll('.njilga-check:checked').forEach(function(cb){ cb.checked=false; });
      showStage(); apply();
    });
  });
  function showStage(){
    root.querySelectorAll('.njilga-stage-note').forEach(function(n){ n.hidden=(n.dataset.stage!==state.tab); });
  }
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

  // Emails — the bulk button and each row's Email / Email again — all go
  // to the review screen first: read the email, send yourself a test,
  // then send. Nothing is emailed from here.
  var pickForm=document.getElementById('njilga-inv-emailpick');
  var pickIds=document.getElementById('njilga-emailpick-ids');
  function pick(ids){
    if(!pickForm||!pickIds||!ids.length) return;
    pickIds.innerHTML='';
    ids.forEach(function(id){
      var i=document.createElement('input'); i.type='hidden'; i.name='row_ids[]'; i.value=String(parseInt(id,10)); pickIds.appendChild(i);
    });
    pickForm.submit();
  }
  var bulkEmail=document.getElementById('njilga-bulkemail');
  if(bulkEmail) bulkEmail.addEventListener('click',function(){
    var ids=[]; table.querySelectorAll('.njilga-check[data-act="email"]:checked').forEach(function(cb){ ids.push(cb.value); });
    bulkEmail.classList.add('njilga-busy'); bulkEmail.textContent='Generating…';
    pick(ids);
  });
  root.addEventListener('click',function(e){
    var b=e.target.closest('[data-send]'); if(!b) return;
    pick([b.dataset.send]);
  });

  showStage();
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

        $live = MyNJILGA_Stripe_Connection::active_mode() === MyNJILGA_Stripe_Connection::MODE_LIVE;
        if ( ! MyNJILGA_Invoicing::year_is_open_now( $duesYear, $live ) ) {
            self::redirect( $duesYear, [ 'msg' => 'error', 'detail' => MyNJILGA_Invoicing::closed_year_message( $duesYear, MyNJILGA_Year_Wipe::was_wiped( $duesYear, $live ) ) ] );
        }

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

    /** Every "Email" button: remember the invoices picked, and open the review screen for them. */
    public static function handle_email_pick(): void {
        self::guard( self::ACTION_EMAIL_PICK );
        $duesYear = self::post_year();
        $ids      = ( isset( $_POST['row_ids'] ) && is_array( $_POST['row_ids'] ) ) ? array_values( array_unique( array_filter( array_map( 'intval', wp_unslash( $_POST['row_ids'] ) ) ) ) ) : [];
        if ( ! $ids ) {
            self::redirect( $duesYear, [ 'msg' => 'nothing' ] );
        }
        $token = strtolower( wp_generate_password( 16, false, false ) );
        set_transient( self::email_batch_key( $token ), [
            'year' => $duesYear,
            'live' => MyNJILGA_Stripe_Connection::active_mode() === MyNJILGA_Stripe_Connection::MODE_LIVE,
            'ids'  => $ids,
        ], self::EMAIL_BATCH_TTL );
        wp_safe_redirect( self::email_review_url( $duesYear, $token ) );
        exit;
    }

    /** The review screen's test: the shown firm's real email, to the address typed only. */
    public static function handle_email_test(): void {
        self::guard( self::ACTION_EMAIL_TEST );
        $duesYear = self::post_year();
        $token    = isset( $_POST['batch'] ) ? sanitize_key( wp_unslash( (string) $_POST['batch'] ) ) : '';
        $batch    = self::email_batch( $token );
        $rowId    = isset( $_POST['row_id'] ) ? (int) $_POST['row_id'] : 0;
        $back     = static function ( array $args ) use ( $duesYear, $token, $rowId ): void {
            wp_safe_redirect( self::email_review_url( $duesYear, $token, $args + [ 'firm' => $rowId ] ) );
            exit;
        };

        $to = sanitize_email( wp_unslash( (string) ( $_POST['to'] ?? '' ) ) );
        if ( $to === '' || ! is_email( $to ) ) {
            $back( [ 'msg' => 'error', 'detail' => 'That doesn\'t look like an email address.' ] );
        }
        $live = MyNJILGA_Stripe_Connection::active_mode() === MyNJILGA_Stripe_Connection::MODE_LIVE;
        $row  = MyNJILGA_Dues_Invoice_Table::get( $rowId );
        if ( ! $batch || ! $row || ! in_array( $rowId, array_map( 'intval', $batch['ids'] ), true ) || MyNJILGA_Email_Run::refusal( $row, $duesYear, $live ) !== '' ) {
            $back( [ 'msg' => 'error', 'detail' => 'That invoice isn\'t part of this selection any more — go back and generate the emails again.' ] );
        }
        $m = MyNJILGA_Invoice_Sender::compose( $row );
        if ( empty( $m['ok'] ) ) {
            $back( [ 'msg' => 'error', 'detail' => (string) $m['error'] ] );
        }

        // To the typed address ONLY: no firm, no CC, and a banner so it can never pass for the real thing.
        $headers = [];
        foreach ( (array) $m['headers'] as $h ) {
            if ( stripos( $h, 'Reply-To:' ) === 0 ) {
                $headers[] = $h;
            }
        }
        $banner = '*** TEST — sent only to ' . $to . '. The real email goes to ' . (string) $m['to'] . ' when you send. ***';
        $ok     = wp_mail( $to, '[TEST] ' . (string) $m['subject'], $banner . "\n\n" . (string) $m['body'], $headers );
        $back( [ 'msg' => $ok ? 'email_test_sent' : 'error', 'to' => $to, 'detail' => $ok ? '' : 'The test could not be sent — the site\'s mail isn\'t working. Fix that before sending to firms.' ] );
    }

    /** The review screen's Send: queue the confirmed invoices to go out in the background. */
    public static function handle_email_send(): void {
        self::guard( self::ACTION_EMAIL_SEND );
        $duesYear = self::post_year();
        $token    = isset( $_POST['batch'] ) ? sanitize_key( wp_unslash( (string) $_POST['batch'] ) ) : '';
        $batch    = self::email_batch( $token );
        $live     = MyNJILGA_Stripe_Connection::active_mode() === MyNJILGA_Stripe_Connection::MODE_LIVE;

        $fail = static function ( string $why ) use ( $duesYear, $token ): void {
            wp_safe_redirect( self::email_review_url( $duesYear, $token, [ 'msg' => 'error', 'detail' => $why ] ) );
            exit;
        };
        if ( empty( $_POST['confirm'] ) ) {
            $fail( 'Tick the box to confirm, then send.' );
        }
        if ( ! $batch || (int) $batch['year'] !== $duesYear || (bool) $batch['live'] !== $live ) {
            $fail( 'This selection has expired, or was made in the other Stripe mode — go back and generate the emails again.' );
        }
        if ( ! $live ) {
            $fail( 'Stripe is in Test mode, so nothing is sent to firms. Switch to Live first.' );
        }
        $schedule = MyNJILGA_Launch_Schedule::get( $duesYear );
        if ( $schedule && MyNJILGA_Launch_Schedule::is_open( (string) ( $schedule['status'] ?? '' ) ) ) {
            $fail( 'A scheduled send for ' . $duesYear . ' is still open in the Launch Wizard — let it finish, or cancel it there, first.' );
        }

        $rows   = MyNJILGA_Dues_Invoice_Table::get_many( $batch['ids'] );
        $resend = [];
        if ( ! empty( $_POST['resend'] ) ) {
            foreach ( $rows as $r ) {
                if ( (string) $r->status === MyNJILGA_Dues_Invoice_Table::STATUS_SENT ) {
                    $resend[] = (int) $r->id;
                }
            }
        }
        $result = MyNJILGA_Email_Run::queue( array_map( 'intval', $batch['ids'] ), $resend, $duesYear, $live );
        if ( ! $result['ok'] ) {
            $fail( $result['error'] );
        }
        delete_transient( self::email_batch_key( $token ) );
        self::redirect( $duesYear, [ 'msg' => 'emails_queued', 'count' => $result['queued'] ] );
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

    public static function handle_move_join(): void {
        self::guard( self::ACTION_MOVE_JOIN );
        $rowId  = isset( $_POST['row_id'] ) ? (int) $_POST['row_id'] : 0;
        $result = MyNJILGA_Paid_Year_Move::move( $rowId );
        if ( ! $result['ok'] ) {
            self::redirect( self::post_year(), [ 'msg' => 'error', 'detail' => $result['error'] ] );
        }
        // On to the year it now counts toward.
        self::redirect( $result['to_year'], [ 'msg' => $result['join'] ? 'join_moved' : 'invoice_moved', 'n' => $result['members'] ] );
    }

    public static function handle_wipe(): void {
        self::guard( self::ACTION_WIPE );
        $duesYear = self::post_year();

        // Only the confirmation screen's form carries these, and the typed
        // year must be this year.
        $typed = isset( $_POST['confirm_year'] ) ? trim( (string) wp_unslash( $_POST['confirm_year'] ) ) : '';
        if ( empty( $_POST['confirmed'] ) || $typed !== (string) $duesYear ) {
            wp_safe_redirect( self::page_url( $duesYear, [ 'view' => 'wipe' ] ) );
            exit;
        }

        $live   = MyNJILGA_Stripe_Connection::active_mode() === MyNJILGA_Stripe_Connection::MODE_LIVE;
        $result = MyNJILGA_Year_Wipe::run( $duesYear, $live );
        if ( ! $result['ok'] ) {
            self::redirect( $duesYear, [ 'view' => 'wipe', 'msg' => 'error', 'detail' => $result['error'] ] );
        }
        if ( $result['failed'] ) {
            set_transient( 'njilga_dues_errors_' . get_current_user_id(), $result['failed'], 60 );
        }

        // Back to the year itself if anything is left to look at, else to
        // the coming year — the one there is work to do in.
        $target = $result['failed'] ? $duesYear : MyNJILGA_Invoicing::default_dues_year();
        self::redirect( $target, [
            'msg'     => $result['failed'] ? 'wiped_partial' : 'wiped',
            'wiped_year' => $duesYear,
            'voided'  => $result['voided'],
            'deleted' => $result['deleted'],
            'firms'   => $result['firms'],
            'failed'  => count( $result['failed'] ),
            'kept'    => $result['kept'],
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
        $orphans     = 0;
        $orphanScanOk = 1;
        if ( $onlyRowId <= 0 ) {
            $scan = MyNJILGA_Stripe_Reconciler::scan_for_orphans( $duesYear );
            // An abandoned scan must not read as "none found" — that is
            // the difference between "Stripe holds nothing we don't" and
            // "we couldn't ask".
            $orphanScanOk = empty( $scan['ok'] ) ? 0 : 1;
            $orphans      = count( $scan['orphans'] );
        }

        self::redirect( $duesYear, [
            'msg'          => 'synced',
            'count'        => $result['checked'],
            'updated'      => $result['updated'],
            'attention'    => $result['needs_attention'],
            'orphans'      => $orphans,
            'orphan_scan'  => $orphanScanOk,
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
