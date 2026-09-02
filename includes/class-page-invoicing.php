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

    const FORM_ID = 'njilga-inv-form';

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

        echo '<div class="wrap njilga-inv">';
        self::styles();

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

        self::render_notice();
        self::render_gateway_notices();

        $rows   = MyNJILGA_Dues_Invoice_Table::get_by_year( $duesYear );
        $counts = MyNJILGA_Dues_Invoice_Table::counts_by_status( $duesYear );
        $totals = MyNJILGA_Dues_Invoice_Table::totals_by_status( $duesYear );

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
        self::stat_cards( [
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

    /**
     * @param array<int,array{label:string,value:int,variant:string,icon:string}> $cards
     */
    private static function stat_cards( array $cards ): void {
        echo '<div class="njilga-stats">';
        foreach ( $cards as $card ) {
            printf(
                '<div class="njilga-stat njilga-stat-%s">
                    <div class="njilga-stat-icon">%s</div>
                    <div class="njilga-stat-body">
                        <div class="njilga-stat-label">%s</div>
                        <div class="njilga-stat-value">%s</div>
                    </div>
                 </div>',
                esc_attr( $card['variant'] ),
                self::icon( $card['icon'] ),
                esc_html( $card['label'] ),
                esc_html( (string) $card['value'] )
            );
        }
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
                <option value="paid">Paid</option>
                <option value="blocked">Blocked</option>
                <option value="error">Error</option>
                <option value="downgraded">Downgraded</option>
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
        $link  = MyNJILGA_Invoice_Creator::payment_link( (string) $row->fluentcart_order_uuid );

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
                $view = $link !== '' ? sprintf( '<a class="njilga-btn njilga-btn-outline njilga-btn-sm" href="%s" target="_blank" rel="noopener">View</a>', esc_url( $link ) ) : '';
                return sprintf(
                    '<button type="button" class="njilga-btn njilga-btn-primary njilga-btn-sm" data-send="%d">Send</button> %s',
                    $rowId,
                    $view
                );

            case 'sent':
                $view = $link !== '' ? sprintf( '<a class="njilga-btn njilga-btn-outline njilga-btn-sm" href="%s" target="_blank" rel="noopener">View</a>', esc_url( $link ) ) : '';
                return sprintf(
                    '%s <button type="button" class="njilga-btn njilga-btn-ghost njilga-btn-sm" data-send="%d">Resend</button>',
                    $view,
                    $rowId
                );

            case 'paid':
            case 'downgraded':
                return $link !== ''
                    ? sprintf( '<a class="njilga-btn njilga-btn-outline njilga-btn-sm" href="%s" target="_blank" rel="noopener">View Invoice</a>', esc_url( $link ) )
                    : '<span class="njilga-dim">—</span>';

            case 'blocked':
                return sprintf(
                    '<a class="njilga-btn njilga-btn-outline njilga-btn-sm" href="%s">Review Members</a>',
                    esc_url( MyNJILGA_Admin_Menu::url( MyNJILGA_Admin_Menu::SLUG_COMPANIES ) )
                );

            default: // no-members / zero
                return '<span class="njilga-dim">—</span>';
        }
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

            case $T::STATUS_PAID:
                return self::verdict( 'created', 'paid', [ 'Paid', 'success' ], [ 'Complete', true ], false );

            case $T::STATUS_DOWNGRADED:
                return self::verdict( 'created', 'downgraded', [ 'Downgraded', 'destructive' ], [ 'Unpaid — swept', false ], false );

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

        self::stat_cards( [
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
        return sprintf(
            '<span class="njilga-badge njilga-badge-%s">%s</span>',
            esc_attr( $variant ),
            esc_html( $text )
        );
    }

    private static function validation_cell( string $label, bool $ok ): string {
        if ( $ok ) {
            return '<span class="njilga-valid njilga-valid-ok">' . self::icon( 'check' ) . esc_html( $label ) . '</span>';
        }
        return '<span class="njilga-valid njilga-valid-warn">' . self::icon( 'alert' ) . esc_html( $label ) . '</span>';
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
    // Icons (lucide-style inline SVG)
    // -------------------------------------------------------------------------

    private static function icon( string $name ): string {
        static $paths = [
            'chevron'      => '<path d="m6 9 6 6 6-6"/>',
            'check'        => '<circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/>',
            'alert'        => '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
            'users'        => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
            'check-circle' => '<circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/>',
            'file'         => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v5h5"/><path d="M16 13H8"/><path d="M16 17H8"/><path d="M10 9H8"/>',
            'search'       => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
            'sliders'      => '<path d="M20 7h-9"/><path d="M14 17H5"/><circle cx="17" cy="17" r="3"/><circle cx="7" cy="7" r="3"/>',
            'calendar'     => '<rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/><path d="M8 2v4"/><path d="M16 2v4"/>',
            'refresh'      => '<path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/>',
        ];
        $body = $paths[ $name ] ?? '';
        return '<svg class="njilga-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $body . '</svg>';
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
        $g = static function ( string $k ): int { return isset( $_GET[ $k ] ) ? (int) $_GET[ $k ] : 0; };

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
            case 'nothing':
                return 'Nothing selected.';
            case 'error':
                return isset( $_GET['detail'] ) ? sanitize_text_field( wp_unslash( $_GET['detail'] ) ) : 'Something went wrong.';
            default:
                return '';
        }
    }

    // -------------------------------------------------------------------------
    // Inline CSS / JS (shadcn/ui-inspired, scoped to .njilga-inv)
    // -------------------------------------------------------------------------

    private static function styles(): void {
        echo <<<'CSS'
<style>
.njilga-inv{
  --bg:#ffffff; --fg:#09090b; --muted:#f4f4f5; --muted-fg:#71717a;
  --border:#e4e4e7; --primary:#18181b; --primary-fg:#fafafa; --accent:#f4f4f5;
  --ring:#a1a1aa; --radius:8px;
  --success-bg:#ecfdf3; --success-fg:#067647; --success-bd:#abefc6;
  --info-bg:#eff6ff;    --info-fg:#1d4ed8;    --info-bd:#bfdbfe;
  --warn-bg:#fff7ed;    --warn-fg:#c2410c;    --warn-bd:#fed7aa;
  --danger-bg:#fef2f2;  --danger-fg:#b42318;  --danger-bd:#fecdca;
  color:var(--fg);
  font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
  max-width:1240px;
}
.njilga-inv *{box-sizing:border-box}
.njilga-inv tr[hidden]{display:none}
.njilga-inv [hidden]{display:none!important}
.njilga-inv a{color:var(--info-fg)}
.njilga-inv code{background:var(--muted);padding:1px 5px;border-radius:4px;font-size:12px}
.njilga-icon{width:16px;height:16px;flex:0 0 auto;vertical-align:middle}

/* Header */
.njilga-header{display:flex;align-items:flex-start;justify-content:space-between;gap:20px;flex-wrap:wrap;margin:6px 0 2px}
.njilga-title{font-size:26px;font-weight:700;line-height:1.2;margin:0;padding:0;color:var(--fg)}
.njilga-title-danger{color:var(--danger-fg)}
.njilga-subtitle{color:var(--muted-fg);font-size:14px;margin:6px 0 0}
.njilga-header-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.njilga-header-note{color:var(--muted-fg);font-size:12.5px;margin:8px 0 18px;text-align:right}

/* Year select */
.njilga-year{display:flex;align-items:center;gap:6px;margin:0}
.njilga-year-label{display:inline-flex;align-items:center;gap:5px;color:var(--muted-fg);font-size:12.5px;font-weight:500}

/* Buttons */
.njilga-btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;
  height:38px;padding:0 15px;border-radius:6px;border:1px solid transparent;
  font-size:13.5px;font-weight:500;line-height:1;cursor:pointer;text-decoration:none;
  transition:background .12s,border-color .12s,opacity .12s;white-space:nowrap;background:none}
.njilga-btn:focus-visible{outline:2px solid var(--ring);outline-offset:2px}
.njilga-btn-sm{height:32px;padding:0 11px;font-size:12.5px;border-radius:6px}
.njilga-btn-primary{background:var(--primary);color:var(--primary-fg);border-color:var(--primary)}
.njilga-btn-primary:hover{background:#27272a}
.njilga-btn-outline{background:var(--bg);color:var(--fg);border-color:var(--border)}
.njilga-btn-outline:hover{background:var(--accent)}
.njilga-btn-ghost{background:transparent;color:var(--fg)}
.njilga-btn-ghost:hover{background:var(--accent)}
.njilga-btn-danger{background:var(--danger-fg);color:#fff;border-color:var(--danger-fg)}
.njilga-btn-danger:hover{background:#912018}
.njilga-btn[disabled]{opacity:.5;cursor:not-allowed;pointer-events:none}

/* Stat cards */
.njilga-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:14px;margin:16px 0}
.njilga-stat{display:flex;align-items:center;gap:14px;padding:16px 18px;background:var(--bg);
  border:1px solid var(--border);border-radius:12px}
.njilga-stat-icon{display:flex;align-items:center;justify-content:center;width:42px;height:42px;
  border-radius:10px;background:var(--muted);color:var(--muted-fg)}
.njilga-stat-icon .njilga-icon{width:20px;height:20px}
.njilga-stat-label{color:var(--muted-fg);font-size:13px;font-weight:500}
.njilga-stat-value{font-size:26px;font-weight:700;line-height:1.15;margin-top:2px}
.njilga-stat-success .njilga-stat-icon{background:var(--success-bg);color:var(--success-fg)}
.njilga-stat-info .njilga-stat-icon{background:var(--info-bg);color:var(--info-fg)}
.njilga-stat-warning .njilga-stat-icon{background:var(--warn-bg);color:var(--warn-fg)}
.njilga-stat-warning .njilga-stat-value{color:var(--warn-fg)}
.njilga-stat-destructive .njilga-stat-icon{background:var(--danger-bg);color:var(--danger-fg)}
.njilga-stat-destructive .njilga-stat-value{color:var(--danger-fg)}

/* Progress */
.njilga-progress-wrap{margin:6px 0 22px}
.njilga-progress-top{display:flex;justify-content:space-between;align-items:baseline;gap:12px;flex-wrap:wrap;margin-bottom:8px}
.njilga-progress-label{font-size:13px;color:var(--muted-fg);font-weight:500}
.njilga-money-line{font-size:12.5px;color:var(--muted-fg)}
.njilga-progress{height:8px;background:var(--muted);border-radius:999px;overflow:hidden}
.njilga-progress-bar{height:100%;background:var(--primary);border-radius:999px;transition:width .3s}

/* Card */
.njilga-card{background:var(--bg);border:1px solid var(--border);border-radius:12px;overflow:hidden;margin-bottom:20px}
.njilga-card-head{padding:16px 18px 0}
.njilga-card-title{font-size:17px;font-weight:600;margin:0}

/* Tabs */
.njilga-tabs{display:flex;gap:4px;padding:10px 12px 0;border-bottom:1px solid var(--border);flex-wrap:wrap}
.njilga-tab{display:inline-flex;align-items:center;gap:7px;padding:8px 12px;background:none;border:none;
  border-bottom:2px solid transparent;color:var(--muted-fg);font-size:13.5px;font-weight:500;cursor:pointer;
  margin-bottom:-1px}
.njilga-tab:hover{color:var(--fg)}
.njilga-tab.active{color:var(--fg);border-bottom-color:var(--primary)}
.njilga-tab-count{display:inline-flex;align-items:center;justify-content:center;min-width:20px;height:20px;
  padding:0 6px;border-radius:999px;background:var(--muted);color:var(--muted-fg);font-size:11.5px;font-weight:600}
.njilga-tab.active .njilga-tab-count{background:var(--primary);color:var(--primary-fg)}

/* Toolbar */
.njilga-toolbar{display:flex;align-items:center;gap:10px;padding:14px 18px;flex-wrap:wrap}
.njilga-toolbar-spacer{flex:1 1 auto}
.njilga-search{position:relative;flex:1 1 260px;max-width:380px}
.njilga-search .njilga-icon{position:absolute;left:11px;top:50%;transform:translateY(-50%);color:var(--muted-fg)}
.njilga-search input{width:100%;height:38px;padding:0 12px 0 34px;border:1px solid var(--border);
  border-radius:6px;font-size:13.5px;background:var(--bg);color:var(--fg)}
.njilga-search input:focus{outline:none;border-color:var(--ring);box-shadow:0 0 0 3px rgba(161,161,170,.25)}
.njilga-select{height:38px;padding:0 30px 0 12px;border:1px solid var(--border);border-radius:6px;
  font-size:13.5px;background:var(--bg) url("data:image/svg+xml;charset=utf-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2371717a' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E") no-repeat right 9px center;
  color:var(--fg);cursor:pointer;-webkit-appearance:none;appearance:none}
.njilga-select:focus{outline:none;border-color:var(--ring);box-shadow:0 0 0 3px rgba(161,161,170,.25)}
.njilga-select-sm{height:32px;font-size:12.5px}

/* Bulk bar */
.njilga-bulkbar{display:flex;align-items:center;gap:14px;padding:10px 18px;background:var(--muted);
  border-top:1px solid var(--border);border-bottom:1px solid var(--border);flex-wrap:wrap}
.njilga-bulkbar-check{display:inline-flex;align-items:center;gap:8px;font-size:13px;font-weight:500}
.njilga-bulkbar-sep{width:1px;height:18px;background:var(--border)}
.njilga-bulkbar-total{font-size:13px;color:var(--muted-fg)}
.njilga-bulkbar-total strong{color:var(--fg)}
.njilga-bulkbar #njilga-bulkcreate{margin-left:auto}

/* Table */
.njilga-tablewrap{overflow-x:auto}
.njilga-table{width:100%;border-collapse:collapse;font-size:13.5px}
.njilga-table thead th{text-align:left;padding:11px 14px;color:var(--muted-fg);font-weight:500;
  font-size:12.5px;border-bottom:1px solid var(--border);white-space:nowrap;background:var(--bg)}
.njilga-table tbody td{padding:12px 14px;border-bottom:1px solid var(--border);vertical-align:middle}
.njilga-row:hover td{background:#fafafa}
.njilga-col-num{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
.njilga-col-check{width:40px}
.njilga-col-actions{white-space:nowrap}
.njilga-col-expand{width:44px;text-align:center}
.njilga-inv input[type=checkbox]{width:16px;height:16px;accent-color:var(--primary);cursor:pointer;margin:0}
.njilga-firmcell{min-width:220px}
.njilga-firmname{display:inline-flex;align-items:center;gap:8px;background:none;border:none;padding:0;
  font-size:14px;font-weight:600;color:var(--fg);cursor:pointer;text-align:left;flex-wrap:wrap}
.njilga-firmname:hover .njilga-firm-label{text-decoration:underline}
.njilga-subline{display:block;color:var(--muted-fg);font-size:12px;margin-top:3px}
.njilga-subline-warn{color:var(--warn-fg);display:inline-flex;align-items:center;gap:4px}
.njilga-subline-warn .njilga-icon{width:13px;height:13px}
.njilga-dim{color:var(--muted-fg)}
.njilga-inline-status{display:inline-flex;align-items:center;gap:6px;font-size:12.5px}
.njilga-chevron{background:none;border:none;cursor:pointer;color:var(--muted-fg);padding:6px;border-radius:6px;
  display:inline-flex;transition:transform .18s,background .12s}
.njilga-chevron:hover{background:var(--accent);color:var(--fg)}
.njilga-row.open .njilga-chevron{transform:rotate(180deg)}

/* Badges */
.njilga-badge{display:inline-flex;align-items:center;padding:2px 9px;border-radius:999px;
  font-size:11.5px;font-weight:600;line-height:1.5;border:1px solid transparent;white-space:nowrap}
.njilga-badge-success{background:var(--success-bg);color:var(--success-fg);border-color:var(--success-bd)}
.njilga-badge-info{background:var(--info-bg);color:var(--info-fg);border-color:var(--info-bd)}
.njilga-badge-warning{background:var(--warn-bg);color:var(--warn-fg);border-color:var(--warn-bd)}
.njilga-badge-destructive{background:var(--danger-bg);color:var(--danger-fg);border-color:var(--danger-bd)}
.njilga-badge-muted{background:var(--muted);color:var(--muted-fg);border-color:var(--border)}
.njilga-badge-outline{background:var(--bg);color:var(--muted-fg);border-color:var(--border)}

/* Validation cell */
.njilga-valid{display:inline-flex;align-items:center;gap:6px;font-size:13px}
.njilga-valid .njilga-icon{width:15px;height:15px}
.njilga-valid-ok{color:var(--success-fg)}
.njilga-valid-warn{color:var(--warn-fg)}

/* Preview */
.njilga-preview>td{background:#fafafa;padding:0!important}
.njilga-preview-card{padding:18px 20px;border-top:1px dashed var(--border)}
.njilga-preview-head{display:flex;justify-content:space-between;gap:16px;margin-bottom:12px}
.njilga-preview-title{font-size:15px;font-weight:600}
.njilga-preview-sub{color:var(--muted-fg);font-size:12.5px;margin-top:2px}
.njilga-preview-note{display:flex;align-items:center;gap:7px;font-size:12.5px;color:var(--warn-fg);margin-bottom:8px}
.njilga-preview-note .njilga-icon{width:14px;height:14px}
.njilga-preview-error{color:var(--danger-fg)}
.njilga-preview-empty{margin:4px 0}
.njilga-preview-table{width:100%;max-width:620px;border-collapse:collapse;font-size:13px;background:var(--bg);
  border:1px solid var(--border);border-radius:8px;overflow:hidden}
.njilga-preview-table th{text-align:left;padding:9px 12px;color:var(--muted-fg);font-weight:500;font-size:12px;
  background:var(--muted);border-bottom:1px solid var(--border)}
.njilga-preview-table td{padding:9px 12px;border-bottom:1px solid var(--border)}
.njilga-preview-table tbody tr:last-child td{border-bottom:none}
.njilga-preview-total td{font-weight:700;background:var(--muted)}
.njilga-roster{margin-top:12px;max-width:620px}
.njilga-roster>summary{display:inline-flex;align-items:center;gap:7px;cursor:pointer;font-size:12.5px;
  font-weight:500;color:var(--info-fg);list-style:none;padding:4px 0}
.njilga-roster>summary::-webkit-details-marker{display:none}
.njilga-roster>summary .njilga-icon{width:14px;height:14px}
.njilga-roster-table{margin-top:8px}
.njilga-roster-unbilled td{color:var(--muted-fg)}

/* Table foot / pager */
.njilga-tablefoot{display:flex;justify-content:space-between;align-items:center;gap:14px;padding:14px 18px;flex-wrap:wrap}
.njilga-showing{color:var(--muted-fg);font-size:12.5px}
.njilga-pagectl{display:flex;align-items:center;gap:16px;flex-wrap:wrap}
.njilga-per-label{display:inline-flex;align-items:center;gap:8px;color:var(--muted-fg);font-size:12.5px}
.njilga-pager{display:flex;gap:4px}
.njilga-pgbtn{min-width:32px;height:32px;padding:0 9px;border:1px solid var(--border);background:var(--bg);
  color:var(--fg);border-radius:6px;font-size:12.5px;cursor:pointer}
.njilga-pgbtn:hover:not([disabled]):not(.cur){background:var(--accent)}
.njilga-pgbtn.cur{background:var(--primary);color:var(--primary-fg);border-color:var(--primary)}
.njilga-pgbtn[disabled]{opacity:.4;cursor:not-allowed}
.njilga-pgellip{display:inline-flex;align-items:center;padding:0 4px;color:var(--muted-fg)}
.njilga-noresults{padding:40px 18px;text-align:center;color:var(--muted-fg);font-size:13.5px}

/* Callouts (notices) */
.njilga-callout{border:1px solid var(--border);border-radius:8px;padding:2px 14px;margin:12px 0;background:var(--bg)}
.njilga-callout p{margin:10px 0}
.njilga-callout-success{background:var(--success-bg);border-color:var(--success-bd);color:var(--success-fg)}
.njilga-callout-info{background:var(--info-bg);border-color:var(--info-bd);color:var(--info-fg)}
.njilga-callout-warning{background:var(--warn-bg);border-color:var(--warn-bd);color:var(--warn-fg)}
.njilga-callout-error{background:var(--danger-bg);border-color:var(--danger-bd);color:var(--danger-fg)}
.njilga-callout a{color:inherit;text-decoration:underline}
.njilga-list{list-style:disc;padding-left:22px;margin:6px 0}

/* Empty state */
.njilga-empty{padding:52px 24px;text-align:center}
.njilga-empty-icon{display:inline-flex;align-items:center;justify-content:center;width:52px;height:52px;
  border-radius:12px;background:var(--muted);color:var(--muted-fg);margin-bottom:14px}
.njilga-empty-icon .njilga-icon{width:26px;height:26px}
.njilga-empty-title{font-size:18px;font-weight:600;margin:0 0 8px}
.njilga-empty-text{color:var(--muted-fg);font-size:13.5px;max-width:520px;margin:0 auto 18px}
.njilga-empty .njilga-generate{display:flex;justify-content:center}

/* Danger card / downgrade */
.njilga-danger-card{background:var(--danger-bg);border:1px solid var(--danger-bd);border-radius:12px;
  padding:18px 20px;margin:24px 0;max-width:860px}
.njilga-danger-card p{margin:0 0 12px}
.njilga-danger-head{display:flex;align-items:center;gap:9px;color:var(--danger-fg);margin-bottom:8px}
.njilga-danger-head h2{margin:0;font-size:17px;font-weight:600}
.njilga-danger-head .njilga-icon{width:19px;height:19px}
.njilga-back{margin:6px 0 12px}
.njilga-ack{display:flex;align-items:center;gap:9px;margin:14px 0;font-size:13.5px}
.njilga-confirm-actions{display:flex;gap:10px;flex-wrap:wrap}
.njilga-generate{margin:0}
</style>
CSS;
    }

    private static function scripts(): void {
        echo <<<'JS'
<script>
(function(){
  var root=document.querySelector('.njilga-inv');
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

    /**
     * Row ids for the create action: a single row (per-row button), every
     * ready row (the "all" button covers draft + approved), or a checkbox
     * selection.
     *
     * @return array<int,int>
     */
    private static function post_create_ids( int $duesYear ): array {
        if ( ! empty( $_POST['all'] ) ) {
            $rows = MyNJILGA_Dues_Invoice_Table::get_by_year( $duesYear, [ MyNJILGA_Dues_Invoice_Table::STATUS_DRAFT, MyNJILGA_Dues_Invoice_Table::STATUS_APPROVED ] );
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
