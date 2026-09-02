<?php
/**
 * Payments — the cross-year Stripe payments ledger (Stripe migration
 * phase 4). Sibling to Invoicing in the menu, but where Invoicing is a
 * per-year workflow tool (create/send/collect), this page is a read
 * surface across every year at once: what's outstanding right now, what
 * firms/members are current, and how old the unpaid balances are.
 *
 * Same structural conventions as MyNJILGA_Page_Invoicing (see that file
 * and design.md's "Data-table workspace" section): MyNJILGA_Admin_UI::
 * open()/close(), a client-side tab/search/filter/paginate/expand
 * pattern, stat cards via MyNJILGA_Admin_UI::stat_cards(). The tabs here
 * are four DIFFERENT VIEWS of the same underlying row set (By Invoice /
 * By Firm / By Member / Aging), not status buckets the way Invoicing's
 * tabs are — so unlike Invoicing, the toolbar filters and the tabs are
 * independent: the filters narrow the row set every view draws from, the
 * tabs just choose which view is on screen.
 *
 * Data model: every njilga_dues_invoices row that has actually reached
 * the gateway (created/sent/processing/paid/voided/uncollectible/
 * downgraded — never draft/approved/excluded, which don't exist in
 * Stripe yet), across every dues year, filtered to the CURRENTLY ACTIVE
 * Stripe mode. build_lines() is the one place that assembles this set;
 * group_by_firm()/group_by_member()/aging_buckets() derive the other
 * three views from it, and are public so the CSV/XLS exporters
 * (MyNJILGA_Report_Csv/MyNJILGA_Report_Xls) can reuse exactly the same
 * data the on-screen tables show.
 *
 * The money arithmetic those views rest on — the five stat-card figures
 * and the aging subtotals/counts — is NOT here: it lives in the pure
 * MyNJILGA_Ledger_Totals, which takes the same line arrays and is unit
 * tested without WordPress (tests/LedgerTotalsTest.php).
 */
class MyNJILGA_Page_Payments {

    const TAB_INVOICE = 'invoice';
    const TAB_FIRM     = 'firm';
    const TAB_MEMBER   = 'member';
    const TAB_AGING    = 'aging';

    /**
     * Statuses that mean an invoice actually exists in the gateway — the
     * scope of this whole page. draft/approved/excluded rows are
     * Invoicing's business, not a payments ledger's.
     */
    const RELEVANT_STATUSES = [
        MyNJILGA_Dues_Invoice_Table::STATUS_CREATED,
        MyNJILGA_Dues_Invoice_Table::STATUS_SENT,
        MyNJILGA_Dues_Invoice_Table::STATUS_PROCESSING,
        MyNJILGA_Dues_Invoice_Table::STATUS_PAID,
        MyNJILGA_Dues_Invoice_Table::STATUS_VOIDED,
        MyNJILGA_Dues_Invoice_Table::STATUS_UNCOLLECTIBLE,
        MyNJILGA_Dues_Invoice_Table::STATUS_DOWNGRADED,
    ];

    /**
     * The status sets and bucket labels the ledger arithmetic turns on
     * are defined WITH that arithmetic, in the pure MyNJILGA_Ledger_Totals
     * (whose inlined literals a unit test pins back to
     * MyNJILGA_Dues_Invoice_Table's STATUS_* values). Aliased here so this
     * page — and anything reading them off it — still finds them under
     * the old names, from one definition.
     */
    const TERMINAL_STATUSES   = MyNJILGA_Ledger_Totals::TERMINAL_STATUSES;
    const WRITEOFF_STATUSES   = MyNJILGA_Ledger_Totals::WRITEOFF_STATUSES;
    const AGING_BUCKET_LABELS = MyNJILGA_Ledger_Totals::AGING_BUCKET_LABELS;

    const METHODS = [ 'card', 'us_bank_account', 'check', 'cash', 'wire', 'other' ];

    /**
     * A member-year's most advanced touching invoice status wins (lowest
     * number = shown), so a contact billed on more than one invoice for
     * the same year (individual/split-assessment billing modes) shows
     * the best-known outcome rather than whichever row happened to be
     * decoded last. Used by group_by_member().
     */
    const MEMBER_STATUS_PRECEDENCE = [
        MyNJILGA_Dues_Invoice_Table::STATUS_PAID          => 0,
        MyNJILGA_Dues_Invoice_Table::STATUS_PROCESSING    => 1,
        MyNJILGA_Dues_Invoice_Table::STATUS_SENT           => 2,
        MyNJILGA_Dues_Invoice_Table::STATUS_CREATED        => 3,
        MyNJILGA_Dues_Invoice_Table::STATUS_DOWNGRADED     => 4,
        MyNJILGA_Dues_Invoice_Table::STATUS_VOIDED         => 5,
        MyNJILGA_Dues_Invoice_Table::STATUS_UNCOLLECTIBLE  => 6,
    ];

    // -------------------------------------------------------------------------
    // Render
    // -------------------------------------------------------------------------

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Access denied.' );
        }

        MyNJILGA_Dues_Invoice_Table::maybe_upgrade();
        MyNJILGA_Dues_Payments_Table::maybe_upgrade();

        MyNJILGA_Admin_UI::open( 'Payments', 'Every Stripe invoice that has actually been billed, across every dues year — who owes, who\'s paid, and how old the balances are.' );

        if ( MyNJILGA_Stripe_Connection::active_mode() === MyNJILGA_Stripe_Connection::MODE_TEST ) {
            MyNJILGA_Admin_UI::callout( esc_html( 'Test mode — these payments are not real and are hidden from Live.' ), 'warning' );
        }

        if ( MyNJILGA_Admin_Menu::require_fluentcrm() ) {
            MyNJILGA_Admin_UI::close();
            return;
        }
        if ( ! MyNJILGA_Members_Data::companies_module_active() ) {
            MyNJILGA_Admin_UI::callout( '<strong>FluentCRM Companies</strong> is not active on this site. Enable it under FluentCRM &rarr; Settings &rarr; Modules.', 'warning' );
            MyNJILGA_Admin_UI::close();
            return;
        }

        $lines = self::build_lines();

        if ( empty( $lines ) ) {
            self::render_empty_state();
            MyNJILGA_Admin_UI::close();
            return;
        }

        $firms   = self::group_by_firm( $lines );
        $members = self::group_by_member( $lines );
        $aging   = self::aging_buckets( $lines );

        self::render_stat_cards( $lines );
        self::render_toolbar( $lines );

        echo '<div class="njilga-tabs njilga-tabs-bare" role="tablist">';
        self::tab( self::TAB_INVOICE, 'By Invoice', count( $lines ), true );
        self::tab( self::TAB_FIRM,    'By Firm',    count( $firms ), false );
        self::tab( self::TAB_MEMBER,  'By Member',  count( $members ), false );
        self::tab( self::TAB_AGING,   'Aging',      self::aging_outstanding_count( $aging ), false );
        echo '</div>';

        echo '<div data-panel="' . self::TAB_INVOICE . '">';
        self::render_by_invoice( $lines );
        echo '</div>';

        echo '<div data-panel="' . self::TAB_FIRM . '" hidden>';
        self::render_by_firm( $firms );
        echo '</div>';

        echo '<div data-panel="' . self::TAB_MEMBER . '" hidden>';
        self::render_by_member( $members );
        echo '</div>';

        echo '<div data-panel="' . self::TAB_AGING . '" hidden>';
        self::render_aging( $aging );
        echo '</div>';

        self::scripts();
        MyNJILGA_Admin_UI::close();
    }

    private static function render_empty_state(): void {
        echo '<div class="njilga-card njilga-empty">';
        echo '<div class="njilga-empty-icon">' . self::icon( 'file' ) . '</div>';
        echo '<h2 class="njilga-empty-title">No invoices billed yet</h2>';
        printf(
            '<p class="njilga-empty-text">Nothing has been created against Stripe in this mode yet. Once invoices are created and sent from <a href="%s">Invoicing</a>, they\'ll show up here.</p>',
            esc_url( MyNJILGA_Admin_Menu::url( MyNJILGA_Admin_Menu::SLUG_INVOICING ) )
        );
        echo '</div>';
    }

    // -------------------------------------------------------------------------
    // Data — the one place every view (and both exporters) reads from.
    // -------------------------------------------------------------------------

    /**
     * Every invoice row that exists in the gateway, across every dues
     * year, scoped to the currently active Stripe mode. Schema §1.2.0's
     * intent ("every read in the Invoicing UI filters on the currently
     * active mode") applies here too — this page is an Invoicing UI read
     * surface just as much as the Invoicing page itself, even though it
     * lives on a different admin page. Scoped to the active Stripe mode
     * in SQL — a test-mode row must never appear in a live ledger, and
     * the money on this page is summed from exactly these rows.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function build_lines(): array {
        $wantLive = ( MyNJILGA_Stripe_Connection::active_mode() === MyNJILGA_Stripe_Connection::MODE_LIVE );

        $lines = [];
        foreach ( MyNJILGA_Dues_Invoice_Table::years() as $year ) {
            foreach ( MyNJILGA_Dues_Invoice_Table::get_by_year( $year, self::RELEVANT_STATUSES, $wantLive ) as $row ) {
                $lines[] = self::to_line( $row );
            }
        }
        return $lines;
    }

    /**
     * @return array<string,mixed>
     */
    private static function to_line( object $row ): array {
        $status  = (string) $row->status;
        $dueDate = (string) ( $row->due_date ?? '' );

        // Age is only meaningful for a balance that's still genuinely
        // outstanding — a terminal row (paid/voided/uncollectible/
        // downgraded) or one with no due date on file gets no bucket at
        // all ('' — rendered as a dim em-dash), never a stale one.
        $ageBucket = '';
        $ageDays   = null;
        if ( ! in_array( $status, self::TERMINAL_STATUSES, true ) && $dueDate !== '' ) {
            $today   = (int) current_time( 'timestamp' );
            $due     = (int) strtotime( $dueDate . ' 00:00:00' );
            $ageDays = (int) floor( ( $today - $due ) / DAY_IN_SECONDS );
            if ( $ageDays < 0 ) {
                $ageBucket = 'notyet';
            } elseif ( $ageDays <= 30 ) {
                $ageBucket = '0-30';
            } elseif ( $ageDays <= 60 ) {
                $ageBucket = '31-60';
            } elseif ( $ageDays <= 90 ) {
                $ageBucket = '61-90';
            } else {
                $ageBucket = '90+';
            }
        }

        $paidAt = (string) ( $row->paid_at ?? '' );

        return [
            'row'       => $row,
            'id'        => (int) $row->id,
            'firm'      => MyNJILGA_Dues_Snapshot::company_name( $row ),
            'companyId' => (int) $row->fluentcrm_company_id,
            'year'      => (int) $row->dues_year,
            'status'    => $status,
            'method'    => (string) ( $row->primary_method ?? '' ),
            'total'     => (int) $row->total_amount_cents,
            'paid'      => (int) $row->amount_paid_cents,
            'due'       => (int) $row->amount_due_cents,
            'paidAt'    => $paidAt,
            'paidDate'  => $paidAt !== '' ? substr( $paidAt, 0, 10 ) : '',
            'dueDate'   => $dueDate,
            'ageBucket' => $ageBucket,
            'ageDays'   => $ageDays,
            'invoiceNo' => (string) ( $row->gateway_invoice_number ?? '' ),
            'invoiceId' => (string) ( $row->gateway_invoice_id ?? '' ),
            'hostedUrl' => (string) ( $row->hosted_invoice_url ?? '' ),
            'pdfUrl'    => (string) ( $row->invoice_pdf_url ?? '' ),
            'billTo'    => self::bill_to_label( $row ),
        ];
    }

    /**
     * One row per FluentCRM Company with at least one line — the "is
     * this firm current across every year" view. Each year present gets
     * a paid/partial/unpaid/written-off verdict rolled up from every
     * line touching that (company, year) pair (a firm can have more than
     * one row per year under individual/split-assessment billing).
     *
     * @param array<int,array<string,mixed>> $lines
     * @return array<int,array{companyId:int,name:string,years:array<int,array<string,mixed>>,outstandingCents:int}>
     */
    public static function group_by_firm( array $lines ): array {
        $firms = [];
        foreach ( $lines as $l ) {
            $cid = $l['companyId'];
            if ( ! isset( $firms[ $cid ] ) ) {
                $firms[ $cid ] = [ 'companyId' => $cid, 'name' => $l['firm'], 'years' => [], 'outstandingCents' => 0 ];
            }
            $y = $l['year'];
            if ( ! isset( $firms[ $cid ]['years'][ $y ] ) ) {
                $firms[ $cid ]['years'][ $y ] = [ 'allPaid' => true, 'anyPaid' => false, 'anyOpen' => false ];
            }
            $yr = &$firms[ $cid ]['years'][ $y ];
            if ( $l['status'] !== MyNJILGA_Dues_Invoice_Table::STATUS_PAID ) {
                $yr['allPaid'] = false;
            }
            if ( $l['paid'] > 0 ) {
                $yr['anyPaid'] = true;
            }
            if ( ! in_array( $l['status'], self::TERMINAL_STATUSES, true ) ) {
                $yr['anyOpen'] = true;
            }
            unset( $yr );

            if ( ! in_array( $l['status'], self::TERMINAL_STATUSES, true ) ) {
                $firms[ $cid ]['outstandingCents'] += $l['due'];
            }
        }

        foreach ( $firms as &$f ) {
            foreach ( $f['years'] as $y => &$yr ) {
                if ( $yr['allPaid'] ) {
                    $yr['verdict'] = 'paid';
                } elseif ( $yr['anyPaid'] ) {
                    $yr['verdict'] = 'partial';
                } elseif ( $yr['anyOpen'] ) {
                    $yr['verdict'] = 'unpaid';
                } else {
                    // Nothing open, nothing collected — voided/uncollectible/
                    // downgraded with no payment ever landing.
                    $yr['verdict'] = 'written_off';
                }
            }
            unset( $yr );
            ksort( $f['years'] );
        }
        unset( $f );

        usort( $firms, static function ( $a, $b ) {
            return strcasecmp( (string) $a['name'], (string) $b['name'] );
        } );

        return array_values( $firms );
    }

    /**
     * One row per contact appearing on any invoice's frozen roster, any
     * year, active mode — pulled from MyNJILGA_Dues_Snapshot::members()
     * on every line's row, not a separate members query. Deliberately
     * in-memory / no pagination (see the class docblock and the spec
     * this implements) — acceptable for a first version at this
     * plugin's scale, consistent with how MyNJILGA_Members_Data already
     * builds similarly-sized in-memory structures elsewhere.
     *
     * @param array<int,array<string,mixed>> $lines
     * @return array<int,array{contactId:int,name:string,firm:string,years:array<int,string>}>
     */
    public static function group_by_member( array $lines ): array {
        $members = [];
        foreach ( $lines as $l ) {
            foreach ( MyNJILGA_Dues_Snapshot::members( $l['row'] ) as $m ) {
                $cid = (int) ( $m['contact_id'] ?? 0 );
                if ( $cid <= 0 ) {
                    continue;
                }
                if ( ! isset( $members[ $cid ] ) ) {
                    $members[ $cid ] = [
                        'contactId' => $cid,
                        'name'      => (string) ( $m['name'] ?? '' ),
                        'firm'      => $l['firm'],
                        'years'     => [],
                    ];
                }
                $y        = $l['year'];
                $existing = $members[ $cid ]['years'][ $y ] ?? null;
                if ( $existing === null || ( self::MEMBER_STATUS_PRECEDENCE[ $l['status'] ] ?? 99 ) < ( self::MEMBER_STATUS_PRECEDENCE[ $existing ] ?? 99 ) ) {
                    $members[ $cid ]['years'][ $y ] = $l['status'];
                }
            }
        }

        foreach ( $members as &$m ) {
            ksort( $m['years'] );
        }
        unset( $m );

        usort( $members, static function ( $a, $b ) {
            return strcasecmp( (string) $a['name'], (string) $b['name'] );
        } );

        return array_values( $members );
    }

    /**
     * Outstanding invoices (status NOT IN paid/voided/uncollectible/
     * downgraded) grouped into fixed age buckets by days since due_date.
     * The arithmetic itself is MyNJILGA_Ledger_Totals'; this stays public
     * because both exporters (MyNJILGA_Report_Csv/MyNJILGA_Report_Xls)
     * reach the Aging view through it.
     *
     * @param array<int,array<string,mixed>> $lines
     * @return array{buckets:array<string,array{label:string,lines:array<int,array<string,mixed>>,subtotalCents:int}>,grandTotalCents:int}
     */
    public static function aging_buckets( array $lines ): array {
        return MyNJILGA_Ledger_Totals::aging_buckets( $lines );
    }

    /**
     * @param array{buckets:array<string,array<string,mixed>>,grandTotalCents:int} $aging
     */
    private static function aging_outstanding_count( array $aging ): int {
        return MyNJILGA_Ledger_Totals::outstanding_count( $aging );
    }

    // -------------------------------------------------------------------------
    // PART A — stat cards
    // -------------------------------------------------------------------------

    /**
     * Server-rendered from the FULL (unfiltered) row set for the first
     * paint; scripts() recomputes these same five totals client-side —
     * from the identical predicate the By-invoice/Aging rows are shown
     * or hidden by — every time a toolbar filter changes, so the cards
     * always describe exactly the row set currently on screen. The
     * arithmetic (including why "Written off" reads the still-outstanding
     * balance rather than the invoice total) lives in the pure
     * MyNJILGA_Ledger_Totals; this method only paints it.
     *
     * @param array<int,array<string,mixed>> $lines
     */
    private static function render_stat_cards( array $lines ): void {
        $stats = MyNJILGA_Ledger_Totals::stats( $lines );

        echo '<div id="njilga-pay-stats">';
        MyNJILGA_Admin_UI::stat_cards( [
            [ 'label' => 'Outstanding', 'value' => MyNJILGA_Invoicing::money( $stats['outstandingCents'] ), 'variant' => $stats['outstandingCents'] > 0 ? 'warning' : 'default', 'icon' => 'file' ],
            [ 'label' => 'Collected',   'value' => MyNJILGA_Invoicing::money( $stats['collectedCents'] ),   'variant' => 'success', 'icon' => 'check-circle' ],
            [ 'label' => 'In Flight',   'value' => MyNJILGA_Invoicing::money( $stats['inFlightCents'] ),    'variant' => 'info',    'icon' => 'refresh' ],
            [ 'label' => 'Past Due',    'value' => MyNJILGA_Invoicing::money( $stats['pastDueCents'] ),     'variant' => 'destructive', 'icon' => 'alert' ],
            [ 'label' => 'Written Off', 'value' => MyNJILGA_Invoicing::money( $stats['writtenOffCents'] ),  'variant' => 'muted',   'icon' => 'inbox' ],
        ] );
        echo '</div>';
    }

    // -------------------------------------------------------------------------
    // PART B — toolbar
    // -------------------------------------------------------------------------

    /**
     * @param array<int,array<string,mixed>> $lines
     */
    private static function render_toolbar( array $lines ): void {
        $years = [];
        foreach ( $lines as $l ) {
            $years[ $l['year'] ] = true;
        }
        $years = array_keys( $years );
        rsort( $years );

        echo '<div class="njilga-card"><div class="njilga-toolbar">';

        printf(
            '<div class="njilga-search">%s<input type="text" id="njilga-pay-search" placeholder="Search law firms…" autocomplete="off"></div>',
            self::icon( 'search' )
        );

        // Year: a plain multi-select is the simplest correct UI here
        // (design.md's own allowance) — no selection means "all years",
        // which is this control's default state.
        echo '<div class="njilga-year"><span class="njilga-year-label">' . self::icon( 'calendar' ) . ' Years</span>';
        echo '<select id="njilga-pay-years" class="njilga-select njilga-select-multi" multiple size="4" title="Ctrl/Cmd-click to select more than one — none selected means all years">';
        foreach ( $years as $y ) {
            printf( '<option value="%1$d">%1$d</option>', $y );
        }
        echo '</select></div>';

        echo '<select id="njilga-pay-status" class="njilga-select">';
        echo '<option value="all">All statuses</option>';
        foreach ( self::RELEVANT_STATUSES as $s ) {
            printf( '<option value="%1$s">%2$s</option>', esc_attr( $s ), esc_html( self::status_pill_parts( $s )[0] ) );
        }
        echo '</select>';

        echo '<select id="njilga-pay-method" class="njilga-select">';
        echo '<option value="all">All methods</option>';
        foreach ( self::METHODS as $m ) {
            printf( '<option value="%1$s">%2$s</option>', esc_attr( $m ), esc_html( self::method_label( $m ) ) );
        }
        echo '</select>';

        echo '<div class="njilga-year"><span class="njilga-year-label">' . self::icon( 'calendar' ) . ' Paid</span>';
        echo '<input type="date" id="njilga-pay-paid-from" class="njilga-input-sm" aria-label="Paid from">';
        echo '<span class="njilga-dim">&ndash;</span>';
        echo '<input type="date" id="njilga-pay-paid-to" class="njilga-input-sm" aria-label="Paid to">';
        echo '</div>';

        echo '<label class="njilga-check-label"><input type="checkbox" id="njilga-pay-overdue-only"> <span>Overdue only</span></label>';

        echo '<div class="njilga-toolbar-spacer"></div>';
        echo '</div></div>';
    }

    private static function tab( string $key, string $label, int $count, bool $active ): void {
        printf(
            '<button type="button" class="njilga-tab%s" data-tab="%s" role="tab">%s <span class="njilga-tab-count">%d</span></button>',
            $active ? ' active' : '',
            esc_attr( $key ),
            esc_html( $label ),
            $count
        );
    }

    // -------------------------------------------------------------------------
    // PART C.1 — By invoice
    // -------------------------------------------------------------------------

    /**
     * @param array<int,array<string,mixed>> $lines
     */
    private static function render_by_invoice( array $lines ): void {
        echo '<div class="njilga-actions">' . MyNJILGA_Admin_UI::action_form( 'my_njilga_export_payments', 'Download CSV', [ 'view' => 'invoice' ], 'outline', 'download' ) . '</div>';

        echo '<div class="njilga-card njilga-table-boxed"><div class="njilga-tablewrap">';
        echo '<table class="njilga-table" id="njilga-pay-inv-table"><thead><tr>';
        echo '<th>Firm</th><th>Year</th><th>Invoice #</th><th>Bill-to</th>';
        echo '<th class="njilga-col-num">Amount</th><th class="njilga-col-num">Paid</th><th class="njilga-col-num">Balance</th>';
        echo '<th>Status</th><th>Method</th><th>Paid On</th><th>Age</th><th class="njilga-col-actions">Actions</th><th class="njilga-col-expand"></th>';
        echo '</tr></thead><tbody>';

        foreach ( $lines as $l ) {
            self::render_invoice_row( $l );
        }

        echo '</tbody></table></div>';

        echo '<div class="njilga-tablefoot">
                <div class="njilga-showing" id="njilga-pay-inv-showing"></div>
                <div class="njilga-pagectl">
                    <label class="njilga-per-label">Rows per page
                        <select id="njilga-pay-inv-per" class="njilga-select njilga-select-sm">
                            <option value="10">10</option>
                            <option value="25" selected>25</option>
                            <option value="50">50</option>
                            <option value="100000">All</option>
                        </select>
                    </label>
                    <div class="njilga-pager" id="njilga-pay-inv-pager"></div>
                </div>
              </div>';
        echo '<div class="njilga-noresults" id="njilga-pay-inv-noresults" hidden>No invoices match your filters.</div>';
        echo '</div>'; // .njilga-card
    }

    /**
     * @param array<string,mixed> $l
     */
    private static function render_invoice_row( array $l ): void {
        $id = $l['id'];
        [ $statusLabel, $statusVariant ] = self::status_pill_parts( $l['status'] );

        $invoiceNo = $l['invoiceNo'] !== ''
            ? $l['invoiceNo']
            : ( $l['invoiceId'] !== '' ? '…' . substr( $l['invoiceId'], -8 ) : '—' );

        printf(
            '<tr class="njilga-row" data-id="%1$d" data-firm="%2$s" data-year="%3$d" data-status="%4$s" data-method="%5$s" data-paiddate="%6$s" data-duedate="%7$s" data-overdue="%8$s" data-due-cents="%9$d" data-paid-cents="%10$d" data-total-cents="%11$d">',
            $id,
            esc_attr( strtolower( $l['firm'] ) ),
            $l['year'],
            esc_attr( $l['status'] ),
            esc_attr( $l['method'] ),
            esc_attr( $l['paidDate'] ),
            esc_attr( $l['dueDate'] ),
            ( $l['ageBucket'] !== '' && $l['ageBucket'] !== 'notyet' ) ? '1' : '0',
            $l['due'],
            $l['paid'],
            $l['total']
        );

        printf( '<td class="njilga-firmcell"><span class="njilga-firmname">%s</span></td>', esc_html( $l['firm'] ) );
        printf( '<td>%d</td>', $l['year'] );
        printf( '<td>%s</td>', esc_html( $invoiceNo ) );
        printf( '<td>%s</td>', esc_html( $l['billTo'] ) );
        printf( '<td class="njilga-col-num">%s</td>', esc_html( MyNJILGA_Invoicing::money( $l['total'] ) ) );
        printf( '<td class="njilga-col-num">%s</td>', esc_html( MyNJILGA_Invoicing::money( $l['paid'] ) ) );
        printf( '<td class="njilga-col-num">%s</td>', esc_html( MyNJILGA_Invoicing::money( $l['due'] ) ) );
        echo '<td>' . MyNJILGA_Admin_UI::pill( $statusLabel, $statusVariant ) . '</td>';

        // Coarse method label only — see method_label()'s docblock for
        // why the fuller "Visa ••4242" style detail is reserved for the
        // row-expansion ledger table instead of this summary column.
        $methodLabel = self::method_label( $l['method'] );
        echo '<td>' . ( $methodLabel !== '' ? MyNJILGA_Admin_UI::pill( $methodLabel, 'outline' ) : MyNJILGA_Admin_UI::blank() ) . '</td>';

        echo '<td>' . ( $l['paidDate'] !== '' ? esc_html( $l['paidDate'] ) : MyNJILGA_Admin_UI::blank() ) . '</td>';
        echo '<td>' . self::age_cell( $l ) . '</td>';
        echo '<td class="njilga-col-actions">' . self::invoice_actions( $l ) . '</td>';
        printf( '<td class="njilga-col-expand"><button type="button" class="njilga-chevron njilga-expand" data-id="%d" aria-label="Toggle payment history">%s</button></td>', $id, self::icon( 'chevron' ) );
        echo '</tr>';

        echo '<tr class="njilga-preview" data-for="' . $id . '" hidden><td colspan="13">';
        self::render_invoice_detail( $l );
        echo '</td></tr>';
    }

    /**
     * @param array<string,mixed> $l
     */
    private static function age_cell( array $l ): string {
        if ( $l['ageBucket'] === '' ) {
            return MyNJILGA_Admin_UI::blank();
        }
        if ( $l['ageBucket'] === 'notyet' ) {
            return '<span class="njilga-dim">Not yet due</span>';
        }
        $variant = [ '0-30' => 'info', '31-60' => 'warning', '61-90' => 'destructive', '90+' => 'destructive' ][ $l['ageBucket'] ] ?? 'muted';
        return MyNJILGA_Admin_UI::pill( sprintf( '%dd', (int) $l['ageDays'] ), $variant );
    }

    /**
     * @param array<string,mixed> $l
     */
    private static function invoice_actions( array $l ): string {
        $out = [];
        if ( $l['hostedUrl'] !== '' ) {
            $out[] = sprintf( '<a class="njilga-btn njilga-btn-outline njilga-btn-sm" href="%s" target="_blank" rel="noopener">View</a>', esc_url( $l['hostedUrl'] ) );
        }
        if ( $l['pdfUrl'] !== '' ) {
            $out[] = sprintf( '<a class="njilga-btn njilga-btn-outline njilga-btn-sm" href="%s" target="_blank" rel="noopener">PDF</a>', esc_url( $l['pdfUrl'] ) );
        }
        return empty( $out ) ? '<span class="njilga-dim">—</span>' : implode( ' ', $out );
    }

    /**
     * PART D — row expansion: the payment ledger for this invoice
     * (newest first, per MyNJILGA_Dues_Payments_Table::get_for_invoice_row()'s
     * own ordering), then the frozen roster.
     *
     * @param array<string,mixed> $l
     */
    private static function render_invoice_detail( array $l ): void {
        $payments = MyNJILGA_Dues_Payments_Table::get_for_invoice_row( $l['id'] );

        // Two figures that live on the invoice row rather than in the
        // ledger, and only matter when they aren't zero: money refunded
        // (Stripe's own cumulative total), and money settled off Stripe
        // by check/wire/cash — the part a Stripe payout will never
        // account for, which is exactly what someone reconciling a bank
        // statement against this page needs told.
        $subline   = [ MyNJILGA_Invoicing::money( $l['total'] ) . ' total' ];
        $refunded  = (int) ( $l['row']->amount_refunded_cents ?? 0 );
        $offStripe = (int) ( $l['row']->paid_off_stripe_cents ?? 0 );
        if ( $offStripe > 0 ) {
            $subline[] = MyNJILGA_Invoicing::money( $offStripe ) . ' paid outside Stripe';
        }
        if ( $refunded > 0 ) {
            $subline[] = MyNJILGA_Invoicing::money( $refunded ) . ' refunded';
        }

        echo '<div class="njilga-preview-card">';
        printf(
            '<div class="njilga-preview-head"><div><div class="njilga-preview-title">Payment History — %s</div><div class="njilga-preview-sub">%d dues year &middot; %s</div></div></div>',
            esc_html( $l['firm'] ),
            $l['year'],
            esc_html( implode( ' · ', $subline ) )
        );

        if ( empty( $payments ) ) {
            echo '<p class="njilga-dim njilga-preview-empty">No payment activity recorded yet.</p>';
        } else {
            echo '<table class="njilga-preview-table"><thead><tr><th>Date</th><th>Kind</th><th>Method</th><th class="njilga-col-num">Amount</th><th>Recorded By</th><th>Receipt</th></tr></thead><tbody>';
            foreach ( $payments as $p ) {
                self::render_payment_row( $p );
            }
            echo '</tbody></table>';
        }

        $members = MyNJILGA_Dues_Snapshot::members( $l['row'] );
        if ( ! empty( $members ) ) {
            echo '<details class="njilga-roster"><summary>' . self::icon( 'users' ) . ' View member roster (' . count( $members ) . ')</summary>';
            echo '<table class="njilga-preview-table njilga-roster-table"><thead><tr><th>Member</th><th>Category</th><th class="njilga-col-num">Dues</th><th class="njilga-col-num">Assessment</th></tr></thead><tbody>';
            foreach ( $members as $m ) {
                $duesCell = (int) ( $m['dues_cents'] ?? 0 ) > 0
                    ? esc_html( MyNJILGA_Invoicing::money( (int) $m['dues_cents'] ) )
                    : '<span class="njilga-dim">&mdash;</span>';
                $feeCell = (int) ( $m['assessment_cents'] ?? 0 ) > 0
                    ? esc_html( MyNJILGA_Invoicing::money( (int) $m['assessment_cents'] ) )
                    : '<span class="njilga-dim">&mdash;</span>';
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

    private static function render_payment_row( object $p ): void {
        $kindMap = [
            MyNJILGA_Dues_Payments_Table::KIND_PAYMENT => [ 'Payment', 'success' ],
            MyNJILGA_Dues_Payments_Table::KIND_REFUND  => [ 'Refund', 'destructive' ],
            MyNJILGA_Dues_Payments_Table::KIND_MANUAL  => [ 'Manual', 'outline' ],
        ];
        [ $kindLabel, $kindVariant ] = $kindMap[ (string) $p->kind ] ?? [ ucfirst( (string) $p->kind ), 'muted' ];

        $isRefund = ( (string) $p->kind === MyNJILGA_Dues_Payments_Table::KIND_REFUND );
        $amount   = MyNJILGA_Invoicing::money( (int) $p->amount_cents );
        $amountCell = $isRefund
            ? '<span class="njilga-status njilga-status-bad">-' . esc_html( $amount ) . '</span>'
            : esc_html( $amount );

        $recordedBy = MyNJILGA_Admin_UI::blank();
        $uid        = (int) ( $p->recorded_by_user_id ?? 0 );
        if ( $uid > 0 ) {
            $user = get_user_by( 'id', $uid );
            if ( $user ) {
                $recordedBy = esc_html( $user->display_name );
            }
        }

        $receipt = ! empty( $p->receipt_url )
            ? sprintf( '<a href="%s" target="_blank" rel="noopener">Receipt</a>', esc_url( (string) $p->receipt_url ) )
            : (string) MyNJILGA_Admin_UI::blank();

        printf(
            '<tr><td>%s</td><td>%s</td><td>%s</td><td class="njilga-col-num">%s</td><td>%s</td><td>%s</td></tr>',
            esc_html( substr( (string) $p->occurred_at, 0, 10 ) ),
            MyNJILGA_Admin_UI::pill( $kindLabel, $kindVariant ),
            esc_html( self::method_label_full( $p ) ),
            $amountCell,
            $recordedBy,
            $receipt
        );
    }

    // -------------------------------------------------------------------------
    // PART C.2 — By firm
    // -------------------------------------------------------------------------

    /**
     * @param array<int,array<string,mixed>> $firms
     */
    private static function render_by_firm( array $firms ): void {
        echo '<div class="njilga-actions">'
            . MyNJILGA_Admin_UI::action_form( 'my_njilga_export_payments', 'Download CSV', [ 'view' => 'firm' ], 'outline', 'download' )
            . MyNJILGA_Admin_UI::action_form( 'my_njilga_export_payments_xls', 'Export to Excel', [ 'view' => 'firm' ], 'outline', 'download' )
            . '</div>';

        echo '<div class="njilga-card njilga-table-boxed"><div class="njilga-tablewrap">';
        echo '<table class="njilga-table" id="njilga-pay-firm-table"><thead><tr><th>Firm</th><th>Dues Years</th><th class="njilga-col-num">Total Outstanding</th></tr></thead><tbody>';

        if ( empty( $firms ) ) {
            echo '<tr class="njilga-emptyrow"><td colspan="3">No firms yet.</td></tr>';
        }

        foreach ( $firms as $f ) {
            printf( '<tr class="njilga-row" data-firm="%s">', esc_attr( strtolower( $f['name'] ) ) );
            printf( '<td class="njilga-firmcell"><span class="njilga-firmname">%s</span></td>', esc_html( $f['name'] ) );
            echo '<td><div class="njilga-chips">';
            foreach ( $f['years'] as $year => $yr ) {
                [ $label, $variant ] = self::firm_verdict_pill( $yr['verdict'] );
                printf(
                    '<span class="njilga-badge njilga-badge-%s njilga-chip-year" data-year="%d" title="%s">%d</span>',
                    esc_attr( $variant ),
                    (int) $year,
                    esc_attr( $label ),
                    (int) $year
                );
            }
            echo '</div></td>';
            printf( '<td class="njilga-col-num">%s</td>', esc_html( MyNJILGA_Invoicing::money( (int) $f['outstandingCents'] ) ) );
            echo '</tr>';
        }

        echo '</tbody></table></div></div>';
        echo '<div class="njilga-noresults" id="njilga-pay-firm-noresults" hidden>No firms match your filters.</div>';
        echo '<p class="njilga-help">Each year chip: green = fully paid, amber = partially paid, red = unpaid and still active, grey = written off (voided/uncollectible/downgraded) with nothing ever collected.</p>';
    }

    /**
     * @return array{0:string,1:string}
     */
    private static function firm_verdict_pill( string $verdict ): array {
        switch ( $verdict ) {
            case 'paid':        return [ 'Paid', 'success' ];
            case 'partial':     return [ 'Partial', 'warning' ];
            case 'unpaid':      return [ 'Unpaid', 'destructive' ];
            default:            return [ 'Written off', 'muted' ];
        }
    }

    // -------------------------------------------------------------------------
    // PART C.3 — By member
    // -------------------------------------------------------------------------

    /**
     * @param array<int,array<string,mixed>> $members
     */
    private static function render_by_member( array $members ): void {
        echo '<div class="njilga-actions">'
            . MyNJILGA_Admin_UI::action_form( 'my_njilga_export_payments', 'Download CSV', [ 'view' => 'member' ], 'outline', 'download' )
            . '</div>';

        echo '<div class="njilga-card njilga-table-boxed"><div class="njilga-tablewrap">';
        echo '<table class="njilga-table" id="njilga-pay-member-table"><thead><tr><th>Member</th><th>Firm</th><th>Dues Years</th></tr></thead><tbody>';

        if ( empty( $members ) ) {
            echo '<tr class="njilga-emptyrow"><td colspan="3">No members on any billed roster yet.</td></tr>';
        }

        foreach ( $members as $m ) {
            printf( '<tr class="njilga-row" data-firm="%s" data-member="%s">', esc_attr( strtolower( $m['firm'] ) ), esc_attr( strtolower( $m['name'] ) ) );
            printf( '<td>%s</td>', esc_html( $m['name'] ) );
            printf( '<td>%s</td>', esc_html( $m['firm'] ) );
            echo '<td><div class="njilga-chips">';
            foreach ( $m['years'] as $year => $status ) {
                [ $label, $variant ] = self::status_pill_parts( $status );
                printf(
                    '<span class="njilga-badge njilga-badge-%s njilga-chip-year" data-year="%d" title="%s">%d</span>',
                    esc_attr( $variant ),
                    (int) $year,
                    esc_attr( $label ),
                    (int) $year
                );
            }
            echo '</div></td>';
            echo '</tr>';
        }

        echo '</tbody></table></div></div>';
        echo '<div class="njilga-noresults" id="njilga-pay-member-noresults" hidden>No members match your filters.</div>';
    }

    // -------------------------------------------------------------------------
    // PART C.4 — Aging
    // -------------------------------------------------------------------------

    /**
     * @param array{buckets:array<string,array<string,mixed>>,grandTotalCents:int} $aging
     */
    private static function render_aging( array $aging ): void {
        echo '<div class="njilga-actions">'
            . MyNJILGA_Admin_UI::action_form( 'my_njilga_export_payments', 'Download CSV', [ 'view' => 'aging' ], 'outline', 'download' )
            . MyNJILGA_Admin_UI::action_form( 'my_njilga_export_payments_xls', 'Export to Excel', [ 'view' => 'aging' ], 'outline', 'download' )
            . '</div>';

        $any = false;
        foreach ( $aging['buckets'] as $key => $bucket ) {
            if ( empty( $bucket['lines'] ) ) {
                continue;
            }
            $any = true;
            echo '<div class="njilga-aging-bucket" data-bucket-section="' . esc_attr( $key ) . '">';
            MyNJILGA_Admin_UI::section(
                $bucket['label'],
                sprintf( 'Subtotal %s across %d invoice%s', MyNJILGA_Invoicing::money( (int) $bucket['subtotalCents'] ), count( $bucket['lines'] ), count( $bucket['lines'] ) === 1 ? '' : 's' ),
                count( $bucket['lines'] )
            );

            echo '<div class="njilga-card njilga-table-boxed" data-bucket="' . esc_attr( $key ) . '"><div class="njilga-tablewrap">';
            echo '<table class="njilga-table"><thead><tr><th>Firm</th><th>Invoice #</th><th>Status</th><th>Method</th><th class="njilga-col-num">Balance</th><th>Due Date</th></tr></thead><tbody>';
            foreach ( $bucket['lines'] as $l ) {
                self::render_aging_row( $l );
            }
            echo '</tbody></table></div></div>';
            echo '</div>';
        }

        if ( ! $any ) {
            echo '<div class="njilga-card njilga-empty">';
            echo '<div class="njilga-empty-icon">' . self::icon( 'check-circle' ) . '</div>';
            echo '<h2 class="njilga-empty-title">Nothing outstanding</h2>';
            echo '<p class="njilga-empty-text">Every invoice in this mode is settled — nothing owed, nothing overdue.</p>';
            echo '</div>';
            return;
        }

        printf(
            '<div class="njilga-banner"><span class="njilga-banner-title">Grand Total Outstanding</span><span class="njilga-banner-total" id="njilga-pay-aging-grandtotal">%s</span></div>',
            esc_html( MyNJILGA_Invoicing::money( (int) $aging['grandTotalCents'] ) )
        );
    }

    /**
     * @param array<string,mixed> $l
     */
    private static function render_aging_row( array $l ): void {
        [ $statusLabel, $statusVariant ] = self::status_pill_parts( $l['status'] );
        $methodLabel = self::method_label( $l['method'] );
        $invoiceNo   = $l['invoiceNo'] !== '' ? $l['invoiceNo'] : ( $l['invoiceId'] !== '' ? '…' . substr( $l['invoiceId'], -8 ) : '—' );

        printf(
            '<tr class="njilga-row" data-firm="%s" data-year="%d" data-status="%s" data-method="%s" data-overdue="%s" data-due-cents="%d">',
            esc_attr( strtolower( $l['firm'] ) ),
            $l['year'],
            esc_attr( $l['status'] ),
            esc_attr( $l['method'] ),
            ( $l['ageBucket'] !== '' && $l['ageBucket'] !== 'notyet' ) ? '1' : '0',
            $l['due']
        );
        printf( '<td class="njilga-firmcell"><span class="njilga-firmname">%s</span></td>', esc_html( $l['firm'] ) );
        printf( '<td>%s</td>', esc_html( $invoiceNo ) );
        echo '<td>' . MyNJILGA_Admin_UI::pill( $statusLabel, $statusVariant ) . '</td>';
        echo '<td>' . ( $methodLabel !== '' ? MyNJILGA_Admin_UI::pill( $methodLabel, 'outline' ) : MyNJILGA_Admin_UI::blank() ) . '</td>';
        printf( '<td class="njilga-col-num">%s</td>', esc_html( MyNJILGA_Invoicing::money( $l['due'] ) ) );
        echo '<td>' . ( $l['dueDate'] !== '' ? esc_html( $l['dueDate'] ) : MyNJILGA_Admin_UI::blank() ) . '</td>';
        echo '</tr>';
    }

    // -------------------------------------------------------------------------
    // Small shared parts
    // -------------------------------------------------------------------------

    /**
     * @return array{0:string,1:string} [label, badge variant]
     */
    public static function status_pill_parts( string $status ): array {
        switch ( $status ) {
            case MyNJILGA_Dues_Invoice_Table::STATUS_CREATED:       return [ 'Invoice Created', 'info' ];
            case MyNJILGA_Dues_Invoice_Table::STATUS_SENT:          return [ 'Sent', 'info' ];
            // Amber, and named for what it is: an ACH debit that has been
            // submitted and settled nothing yet, which can take days. The
            // submit date rides in the equivalent pill on the Invoicing
            // page, where the "is this stuck?" question gets acted on.
            case MyNJILGA_Dues_Invoice_Table::STATUS_PROCESSING:    return [ 'ACH Clearing', 'warning' ];
            case MyNJILGA_Dues_Invoice_Table::STATUS_PAID:          return [ 'Paid', 'success' ];
            case MyNJILGA_Dues_Invoice_Table::STATUS_VOIDED:        return [ 'Voided', 'muted' ];
            case MyNJILGA_Dues_Invoice_Table::STATUS_UNCOLLECTIBLE: return [ 'Uncollectible', 'destructive' ];
            case MyNJILGA_Dues_Invoice_Table::STATUS_DOWNGRADED:    return [ 'Downgraded', 'destructive' ];
            default:                                                return [ ucfirst( $status ), 'muted' ];
        }
    }

    /**
     * Coarse method label for a row's primary_method — the By-invoice and
     * Aging tables' Method column. Deliberately just "Card"/"ACH"/
     * "Check"/etc., no card_brand/last4/bank_name detail: that finer
     * detail (card_brand/last4/bank_name/reference) lives on the
     * PAYMENT LEDGER row (njilga_dues_payments), not the invoice row —
     * showing it here would mean joining the most-recent ledger row per
     * invoice into every summary table row for a detail only the
     * row-expansion ledger (method_label_full(), used there) actually
     * needs. See the task's own scope note on this.
     */
    public static function method_label( string $method ): string {
        $labels = [
            'card'            => 'Card',
            'us_bank_account' => 'ACH',
            'check'           => 'Check',
            'cash'            => 'Cash',
            'wire'            => 'Wire',
            'other'           => 'Other',
        ];
        return $labels[ $method ] ?? '';
    }

    /**
     * Full method detail for one payment-ledger row (row-expansion only):
     * "Visa ••4242", "ACH — Chase Bank", "Check #4417", "Wire ref 9081",
     * "Cash", or "Manual" as the last-resort fallback.
     */
    private static function method_label_full( object $p ): string {
        $method = (string) ( $p->method ?? '' );
        $brand  = (string) ( $p->card_brand ?? '' );
        $last4  = (string) ( $p->last4 ?? '' );
        $bank   = (string) ( $p->bank_name ?? '' );
        $ref    = (string) ( $p->reference ?? '' );

        if ( $method === 'card' && $brand !== '' ) {
            return ucfirst( $brand ) . ( $last4 !== '' ? ' ••' . $last4 : '' );
        }
        if ( $method === 'us_bank_account' || $bank !== '' ) {
            return 'ACH' . ( $bank !== '' ? ' — ' . $bank : '' ) . ( $last4 !== '' ? ' ••' . $last4 : '' );
        }
        if ( $method === 'check' ) {
            return 'Check' . ( $ref !== '' ? ' #' . $ref : '' );
        }
        if ( $method === 'wire' ) {
            return 'Wire' . ( $ref !== '' ? ' ref ' . $ref : '' );
        }
        if ( $method === 'cash' ) {
            return 'Cash';
        }
        // An invoice closed out with Stripe's own "Mark as paid" carries
        // that phrase as its reference — say so, rather than the bare
        // "Manual" that tells nobody where the record came from.
        if ( $ref === MyNJILGA_Stripe_Webhook::MARKED_PAID_IN_STRIPE ) {
            return $ref;
        }
        return 'Manual';
    }

    public static function bill_to_label( object $row ): string {
        $p = MyNJILGA_Dues_Snapshot::bill_to( $row );
        return $p['name'] !== '' ? $p['name'] : ( $p['email'] !== '' ? $p['email'] : '—' );
    }

    private static function icon( string $name ): string {
        return MyNJILGA_Admin_UI::icon( $name );
    }

    // -------------------------------------------------------------------------
    // Page behaviour (tabs / toolbar filters / stat recompute / paginate /
    // expand). All styling comes from MyNJILGA_Admin_UI; see design.md.
    //
    // Filter scope, documented once here rather than scattered in JS
    // comments: Year + firm search apply to every view (By-invoice,
    // By-firm, By-member, Aging all have a firm name and a dues year).
    // Status + Method + paid-date-range + overdue-only apply to
    // By-invoice and Aging — both are literally one row per invoice, so
    // every one of those filters maps cleanly onto a row. They are
    // intentionally NOT applied to By-firm/By-member: those two are
    // ROSTER views (one row per firm/member spanning many invoices), and
    // "hide this firm if its invoice's method was X" doesn't reduce to a
    // sensible row-level toggle the way it does for an invoice-shaped
    // row — narrowing them to Year + search is what stays meaningful.
    // -------------------------------------------------------------------------

    private static function scripts(): void {
        echo <<<'JS'
<script>
(function(){
  var root=document.querySelector('.njilga-ui');
  if(!root) return;

  function money(c){
    var neg = c<0; c=Math.abs(c);
    var n=(c/100).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g,',');
    return (neg?'-':'')+'$'+n;
  }

  var state={years:new Set(),status:'all',method:'all',q:'',paidFrom:'',paidTo:'',overdueOnly:false};

  function readState(){
    var ysel=document.getElementById('njilga-pay-years');
    state.years=new Set();
    if(ysel){ Array.prototype.forEach.call(ysel.selectedOptions,function(o){ state.years.add(o.value); }); }
    var statusSel=document.getElementById('njilga-pay-status');
    state.status=statusSel?statusSel.value:'all';
    var methodSel=document.getElementById('njilga-pay-method');
    state.method=methodSel?methodSel.value:'all';
    var q=document.getElementById('njilga-pay-search');
    state.q=q?q.value.trim().toLowerCase():'';
    var pf=document.getElementById('njilga-pay-paid-from');
    state.paidFrom=pf?pf.value:'';
    var pt=document.getElementById('njilga-pay-paid-to');
    state.paidTo=pt?pt.value:'';
    var ov=document.getElementById('njilga-pay-overdue-only');
    state.overdueOnly=ov?ov.checked:false;
  }

  // Shared predicate for an invoice-shaped row (By-invoice + Aging):
  // year/status/method/firm-search/overdue-only. Paid-date range is
  // checked separately (dateOk) since Aging rows are never paid and
  // should just fall out naturally rather than force a special case.
  function commonOk(el){
    if(state.years.size && !state.years.has(el.dataset.year)) return false;
    if(state.status!=='all' && el.dataset.status!==undefined && el.dataset.status!==state.status) return false;
    if(state.method!=='all' && el.dataset.method!==undefined && el.dataset.method!==state.method) return false;
    if(state.q && el.dataset.firm!==undefined && el.dataset.firm.indexOf(state.q)===-1) return false;
    if(state.overdueOnly && el.dataset.overdue!==undefined && el.dataset.overdue!=='1') return false;
    return true;
  }
  function dateOk(el){
    if(!state.paidFrom && !state.paidTo) return true;
    var pd=el.dataset.paiddate||'';
    if(!pd) return false;
    if(state.paidFrom && pd<state.paidFrom) return false;
    if(state.paidTo && pd>state.paidTo) return false;
    return true;
  }

  // ---- tab state (stat cards need to know which view is on screen — see below) ----
  function activeTab(){
    var t=root.querySelector('.njilga-tabs .njilga-tab.active');
    return t?t.dataset.tab:'invoice';
  }

  // ---- stat cards ----
  // By-invoice/Aging are one row per invoice, so the stat cards can use
  // the full filter predicate. By-firm/By-member are roster views scoped
  // to just Year + search (see the filter-scope note above applyFirm/
  // applyMember) — when one of those tabs is on screen the cards must
  // agree with what the visible table is actually narrowed to, not
  // silently apply Status/Method/paid-date/Overdue-only filters that
  // view doesn't honor.
  var invRows=Array.prototype.slice.call(document.querySelectorAll('#njilga-pay-inv-table tbody tr.njilga-row'));
  function statRowOk(r){
    var tab=activeTab();
    if(tab==='firm'||tab==='member'){
      if(state.years.size && !state.years.has(r.dataset.year)) return false;
      if(state.q && r.dataset.firm!==undefined && r.dataset.firm.indexOf(state.q)===-1) return false;
      return true;
    }
    return commonOk(r)&&dateOk(r);
  }
  function recomputeStats(){
    var wrap=document.getElementById('njilga-pay-stats');
    if(!wrap) return;
    var vals=wrap.querySelectorAll('.njilga-stat-value');
    if(!vals.length) return;
    var outstanding=0,collected=0,inflight=0,pastdue=0,writtenoff=0;
    invRows.forEach(function(r){
      if(!statRowOk(r)) return;
      var due=parseInt(r.dataset.dueCents||'0',10);
      var paid=parseInt(r.dataset.paidCents||'0',10);
      var status=r.dataset.status;
      var writeoff=(status==='voided'||status==='uncollectible');
      if(!writeoff) outstanding+=due;
      collected+=paid;
      if(status==='processing') inflight+=due;
      if(r.dataset.overdue==='1') pastdue+=due;
      if(writeoff) writtenoff+=due;
    });
    var order=[outstanding,collected,inflight,pastdue,writtenoff];
    order.forEach(function(v,i){ if(vals[i]) vals[i].textContent=money(v); });
  }

  // ---- generic pager (By-invoice) ----
  function buildPagerUI(el,pages,cur){
    if(!el) return;
    if(pages<=1){ el.innerHTML=''; return; }
    function btn(label,pg,dis,curr){
      return '<button type="button" class="njilga-pgbtn'+(curr?' cur':'')+'"'+(dis?' disabled':'')+' data-pg="'+pg+'">'+label+'</button>';
    }
    var html=btn('‹',cur-1,cur<=1,false);
    var set=[],i;
    for(i=1;i<=pages;i++){ if(i===1||i===pages||Math.abs(i-cur)<=1) set.push(i); }
    var last=0;
    set.forEach(function(i){
      if(last&&i-last>1) html+='<span class="njilga-pgellip">…</span>';
      html+=btn(i,i,false,i===cur); last=i;
    });
    html+=btn('›',cur+1,cur>=pages,false);
    el.innerHTML=html;
  }

  var invPreviews={};
  invRows.forEach(function(r){
    var pv=r.nextElementSibling;
    if(pv&&pv.classList.contains('njilga-preview')) invPreviews[r.dataset.id]=pv;
  });
  var invPage={page:1,per:25};
  var invPerSel=document.getElementById('njilga-pay-inv-per');
  var invPagerEl=document.getElementById('njilga-pay-inv-pager');
  var invShowingEl=document.getElementById('njilga-pay-inv-showing');
  var invNoResultsEl=document.getElementById('njilga-pay-inv-noresults');
  if(invPerSel){ invPage.per=parseInt(invPerSel.value,10)||25; invPerSel.addEventListener('change',function(){ invPage.per=parseInt(invPerSel.value,10)||25; invPage.page=1; applyInvoice(); }); }
  if(invPagerEl) invPagerEl.addEventListener('click',function(e){
    var b=e.target.closest('.njilga-pgbtn'); if(!b||b.disabled) return;
    invPage.page=parseInt(b.dataset.pg,10)||1; applyInvoice();
    document.getElementById('njilga-pay-inv-table').scrollIntoView({behavior:'smooth',block:'start'});
  });

  function applyInvoice(){
    var filtered=invRows.filter(function(r){ return commonOk(r)&&dateOk(r); });
    var total=filtered.length;
    var pages=Math.max(1,Math.ceil(total/invPage.per));
    if(invPage.page>pages) invPage.page=pages;
    if(invPage.page<1) invPage.page=1;
    var start=(invPage.page-1)*invPage.per, end=start+invPage.per;

    invRows.forEach(function(r){ r.hidden=true; if(invPreviews[r.dataset.id]) invPreviews[r.dataset.id].hidden=true; });
    filtered.forEach(function(r,i){
      if(i>=start&&i<end){
        r.hidden=false;
        if(r.classList.contains('open')&&invPreviews[r.dataset.id]) invPreviews[r.dataset.id].hidden=false;
      }
    });

    if(invNoResultsEl) invNoResultsEl.hidden=(total!==0);
    if(invShowingEl){
      invShowingEl.textContent=total===0?'No invoices to show'
        :('Showing '+(start+1)+'–'+Math.min(end,total)+' of '+total+' invoice'+(total===1?'':'s'));
    }
    buildPagerUI(invPagerEl,pages,invPage.page);
  }

  // expand / collapse (By-invoice payment history)
  root.querySelectorAll('.njilga-expand').forEach(function(btn){
    btn.addEventListener('click',function(){
      var id=btn.dataset.id, row=document.querySelector('#njilga-pay-inv-table tr.njilga-row[data-id="'+id+'"]'), pv=invPreviews[id];
      if(!row||!pv) return;
      var open=row.classList.toggle('open');
      pv.hidden=!open;
    });
  });

  // ---- Aging: filter rows within each bucket, no repagination ----
  // Each bucket's heading count/subtotal and the grand-total banner are
  // server-rendered from the UNFILTERED set — recompute them from
  // whatever's actually still visible, and drop a bucket entirely once
  // nothing in it survives the filter.
  var agingBuckets=Array.prototype.slice.call(document.querySelectorAll('[data-panel="aging"] [data-bucket-section]'));
  var agingGrandTotalEl=document.getElementById('njilga-pay-aging-grandtotal');
  function applyAging(){
    var grand=0;
    agingBuckets.forEach(function(section){
      var rows=Array.prototype.slice.call(section.querySelectorAll('tr.njilga-row'));
      var visible=0,subtotal=0;
      rows.forEach(function(r){
        var show=commonOk(r);
        r.hidden=!show;
        if(show){ visible++; subtotal+=parseInt(r.dataset.dueCents||'0',10); }
      });
      grand+=subtotal;
      section.hidden=(visible===0);
      var countEl=section.querySelector('.njilga-section-count');
      if(countEl) countEl.textContent=visible;
      var descEl=section.querySelector('.njilga-section-desc');
      if(descEl) descEl.textContent='Subtotal '+money(subtotal)+' across '+visible+' invoice'+(visible===1?'':'s');
    });
    if(agingGrandTotalEl) agingGrandTotalEl.textContent=money(grand);
  }

  // ---- By-firm / By-member: year-chip + firm/member search only ----
  var firmRows=Array.prototype.slice.call(document.querySelectorAll('#njilga-pay-firm-table tbody tr.njilga-row'));
  var memberRows=Array.prototype.slice.call(document.querySelectorAll('#njilga-pay-member-table tbody tr.njilga-row'));
  var firmNoResultsEl=document.getElementById('njilga-pay-firm-noresults');
  var memberNoResultsEl=document.getElementById('njilga-pay-member-noresults');
  function applyChipRows(rows,matchExtra,noResultsEl){
    var visible=0;
    rows.forEach(function(r){
      var firmOk = !state.q || r.dataset.firm.indexOf(state.q)>-1 || (matchExtra && matchExtra(r));
      var chips=r.querySelectorAll('.njilga-chip-year');
      var anyVisible = chips.length===0;
      chips.forEach(function(c){
        var show = !state.years.size || state.years.has(c.dataset.year);
        c.hidden=!show;
        if(show) anyVisible=true;
      });
      var show=(firmOk && anyVisible);
      r.hidden = !show;
      if(show) visible++;
    });
    if(noResultsEl) noResultsEl.hidden=(visible!==0);
  }
  function applyFirm(){ applyChipRows(firmRows,null,firmNoResultsEl); }
  function applyMember(){ applyChipRows(memberRows,function(r){ return r.dataset.member && r.dataset.member.indexOf(state.q)>-1; },memberNoResultsEl); }

  function applyAll(){
    readState();
    invPage.page=1;
    applyInvoice();
    applyAging();
    applyFirm();
    applyMember();
    recomputeStats();
  }

  [ 'njilga-pay-years','njilga-pay-status','njilga-pay-method' ].forEach(function(id){
    var el=document.getElementById(id);
    if(el) el.addEventListener('change',applyAll);
  });
  [ 'njilga-pay-paid-from','njilga-pay-paid-to' ].forEach(function(id){
    var el=document.getElementById(id);
    if(el) el.addEventListener('change',applyAll);
  });
  var overdueEl=document.getElementById('njilga-pay-overdue-only');
  if(overdueEl) overdueEl.addEventListener('change',applyAll);
  var searchEl=document.getElementById('njilga-pay-search');
  if(searchEl){
    searchEl.addEventListener('input',applyAll);
    searchEl.addEventListener('keydown',function(e){ if(e.key==='Enter') e.preventDefault(); });
  }

  // ---- tabs (four views, not status buckets — see class docblock) ----
  root.querySelectorAll('.njilga-tabs .njilga-tab').forEach(function(tab){
    tab.addEventListener('click',function(){
      root.querySelectorAll('.njilga-tabs .njilga-tab').forEach(function(t){ t.classList.remove('active'); });
      tab.classList.add('active');
      root.querySelectorAll('[data-panel]').forEach(function(p){ p.hidden=(p.dataset.panel!==tab.dataset.tab); });
      recomputeStats();
    });
  });

  applyAll();
})();
</script>
JS;
    }
}
