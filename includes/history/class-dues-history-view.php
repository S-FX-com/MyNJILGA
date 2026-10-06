<?php
/**
 * Markup for Dues History: the panel shown inside the FluentCRM contact
 * and company records, and the helpers the invoice viewer shares with it
 * (status pills, links, dates).
 *
 * The panels are handed to FluentCRM as `content_html`, which its admin
 * app renders with v-html. Two things follow from that and shape this
 * file:
 *
 *   1. No JavaScript runs inside v-html, so nothing here depends on any.
 *      The contact tab's "this contact / their firm" switch is CSS-only
 *      (radio inputs + sibling selectors — see .njilga-scope in the design
 *      system), and an invoice is "called up" by a plain link that opens
 *      the viewer in a new tab.
 *   2. The HTML is not sanitised on the way in, so every dynamic value is
 *      escaped here, at the point it is printed.
 *
 * Built from MyNJILGA_Admin_UI components like every other screen; see
 * design.md.
 */
class MyNJILGA_Dues_History_View {

    /** Most rows any one table prints; a firm with more is told how many were left off. */
    const MAX_ROWS = 150;

    /** Most firms a contact's tab offers as a scope (the scope switch is styled for six). */
    const MAX_FIRMS = 5;

    // -------------------------------------------------------------------------
    // The two FluentCRM tabs
    // -------------------------------------------------------------------------

    /**
     * The Dues History tab of a FluentCRM contact: the invoices that name
     * them, with a switch to each firm's whole history.
     *
     * @param int                 $contactId
     * @param string              $contactName
     * @param array<int,string>   $firms       company id => name
     */
    public static function contact_html( int $contactId, string $contactName, array $firms ): string {
        $scopes = [
            [
                'label'      => 'This contact',
                'entries'    => MyNJILGA_Dues_History::for_contact( $contactId ),
                'contact_id' => $contactId,
                'show_firm'  => count( $firms ) > 1,
                'empty'      => 'No dues invoices on record that name ' . ( $contactName !== '' ? $contactName : 'this contact' ) . '.',
            ],
        ];
        foreach ( array_slice( $firms, 0, self::MAX_FIRMS, true ) as $companyId => $name ) {
            $scopes[] = [
                'label'      => $name !== '' ? $name : 'Firm #' . (int) $companyId,
                'entries'    => MyNJILGA_Dues_History::for_company( (int) $companyId ),
                'contact_id' => $contactId,
                'show_firm'  => false,
                'empty'      => 'No dues invoices on record for this firm.',
            ];
        }

        return self::wrap( static function () use ( $scopes ) {
            echo '<p class="njilga-dim">Live invoices come from Stripe and update as they are paid. Invoices marked PMPro or Imported were recreated from earlier years for reference.</p>';
            self::scopes( $scopes );
        } );
    }

    /**
     * The Dues History tab of a FluentCRM company.
     */
    public static function company_html( int $companyId ): string {
        $entries = MyNJILGA_Dues_History::for_company( $companyId );
        return self::wrap( static function () use ( $entries ) {
            echo '<p class="njilga-dim">Every dues invoice for this firm, whoever it was billed to. Live invoices come from Stripe and update as they are paid; invoices marked PMPro or Imported were recreated from earlier years for reference.</p>';
            self::panel( $entries, 0, false, 'No dues invoices on record for this firm.' );
        } );
    }

    /**
     * The shell every panel is returned in: the design-system stylesheet
     * (once — see MyNJILGA_Admin_UI::embed()) and the scoping wrapper.
     */
    private static function wrap( callable $body ): string {
        return MyNJILGA_Admin_UI::embed( $body );
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    /**
     * One panel, or — with more than one scope — a switch between them.
     *
     * @param array<int,array{label:string,entries:array<int,array<string,mixed>>,contact_id:int,show_firm:bool,empty:string}> $scopes
     */
    private static function scopes( array $scopes ): void {
        // Nothing for the contact personally AND no firm: one plain empty
        // state, not a switch with a single option.
        if ( count( $scopes ) === 1 ) {
            $s = $scopes[0];
            self::panel( $s['entries'], $s['contact_id'], $s['show_firm'], $s['empty'] );
            return;
        }

        $uid = 'njilga-dh-' . substr( md5( uniqid( '', true ) ), 0, 8 );
        echo '<div class="njilga-scope">';
        foreach ( $scopes as $i => $s ) {
            printf(
                '<input type="radio" class="njilga-scope-radio" name="%1$s" id="%1$s-%2$d"%3$s>',
                esc_attr( $uid ),
                (int) $i,
                $i === 0 ? ' checked' : ''
            );
        }
        echo '<div class="njilga-scope-bar" role="group" aria-label="Show dues history for">';
        foreach ( $scopes as $i => $s ) {
            printf(
                '<label for="%s-%d">%s <span class="njilga-scope-n">%d</span></label>',
                esc_attr( $uid ),
                (int) $i,
                esc_html( $s['label'] ),
                count( $s['entries'] )
            );
        }
        echo '</div><div class="njilga-scope-panels">';
        foreach ( $scopes as $s ) {
            echo '<div class="njilga-scope-panel">';
            self::panel( $s['entries'], $s['contact_id'], $s['show_firm'], $s['empty'] );
            echo '</div>';
        }
        echo '</div></div>';
    }

    // -------------------------------------------------------------------------
    // One panel: figures, open invoices, payments, every invoice
    // -------------------------------------------------------------------------

    /**
     * @param array<int,array<string,mixed>> $entries   Newest year first.
     * @param int                            $contactId Whose share to show on each invoice (0 = none).
     */
    private static function panel( array $entries, int $contactId, bool $showFirm, string $emptyText ): void {
        if ( ! $entries ) {
            echo '<div class="njilga-card njilga-empty"><div class="njilga-empty-icon">' . MyNJILGA_Admin_UI::icon( 'receipt' ) . '</div>';
            echo '<h2 class="njilga-empty-title">Nothing here yet</h2><p class="njilga-empty-text">' . esc_html( $emptyText ) . '</p></div>';
            return;
        }

        $today = current_time( 'Y-m-d' );
        $sum   = MyNJILGA_Dues_History::summarise( $entries );
        $open  = MyNJILGA_Dues_History::open_entries( $entries );
        $pays  = MyNJILGA_Dues_History::all_payments( $entries );
        $net   = $sum['paid_cents'] - $sum['refunded_cents'];

        MyNJILGA_Admin_UI::stat_cards( [
            [
                'label'   => 'Open balance',
                'value'   => MyNJILGA_Invoicing::money( $sum['open_cents'] ),
                'variant' => $sum['open_cents'] > 0 ? 'warning' : 'success',
                'icon'    => $sum['open_cents'] > 0 ? 'alert' : 'check-circle',
                'sub'     => $sum['open_count'] > 0 ? sprintf( '%d open invoice%s', $sum['open_count'], $sum['open_count'] === 1 ? '' : 's' ) : 'Nothing owed',
            ],
            [
                'label'   => 'Paid to date',
                'value'   => MyNJILGA_Invoicing::money( $net ),
                'variant' => 'default',
                'icon'    => 'receipt',
                'sub'     => $sum['refunded_cents'] > 0 ? MyNJILGA_Invoicing::money( $sum['refunded_cents'] ) . ' refunded' : '',
            ],
            [
                'label'   => 'Invoices',
                'value'   => $sum['invoices'],
                'variant' => 'default',
                'icon'    => 'file',
                'sub'     => $sum['first_year'] > 0 ? ( $sum['first_year'] === $sum['last_year'] ? (string) $sum['first_year'] : $sum['first_year'] . '–' . $sum['last_year'] ) : '',
            ],
            [
                'label'   => 'Last payment',
                'value'   => $sum['last_payment'] !== '' ? self::date( $sum['last_payment'] ) : '—',
                'variant' => 'default',
                'icon'    => 'calendar',
            ],
        ], 4 );

        // Open invoices.
        MyNJILGA_Admin_UI::section( 'Open invoices', 'Unpaid dues, oldest first. "Lapsed" means the cycle closed without payment.', count( $open ) );
        if ( ! $open ) {
            MyNJILGA_Admin_UI::callout( '<strong>All paid up.</strong> No invoice on record has a balance outstanding.', 'success' );
        } else {
            self::open_table( $open, $contactId, $showFirm, $today );
        }

        // Payments.
        MyNJILGA_Admin_UI::section( 'Payment history', 'Every payment and refund recorded against these invoices, newest first.', count( $pays ) );
        if ( ! $pays ) {
            echo '<p class="njilga-dim">No payments recorded yet.</p>';
        } else {
            self::payments_table( $pays );
        }

        // Every invoice.
        MyNJILGA_Admin_UI::section( 'All invoices', 'Newest dues year first. Open one to see its line items, who it covers and its payments.', count( $entries ) );
        self::invoices_table( $entries, $contactId, $showFirm, $today );
    }

    /**
     * @param array<int,array<string,mixed>> $open
     */
    private static function open_table( array $open, int $contactId, bool $showFirm, string $today ): void {
        $cols = 6 + ( $contactId > 0 ? 1 : 0 ) + ( $showFirm ? 1 : 0 );
        echo '<div class="njilga-card njilga-table-boxed"><div class="njilga-tablewrap"><table class="njilga-table"><thead><tr>';
        echo '<th>Invoice</th><th>Year</th>' . ( $showFirm ? '<th class="njilga-col-wide">Firm</th>' : '' ) . '<th class="njilga-col-wide">Billed to</th><th>Due</th>';
        echo $contactId > 0 ? '<th class="njilga-col-num">This contact</th>' : '';
        echo '<th class="njilga-col-num">Balance</th><th>Status</th><th class="njilga-col-actions"></th>';
        echo '</tr></thead><tbody>';
        foreach ( array_slice( $open, 0, self::MAX_ROWS ) as $e ) {
            echo '<tr>';
            printf( '<td class="njilga-nowrap">%s</td><td>%d</td>', self::invoice_link( $e ), (int) $e['year'] );
            echo $showFirm ? '<td class="njilga-col-wide">' . esc_html( (string) $e['company'] ) . '</td>' : '';
            printf( '<td class="njilga-col-wide">%s</td><td class="njilga-nowrap">%s</td>', self::person_cell( $e ), $e['due'] !== '' ? esc_html( self::date( $e['due'] ) ) : MyNJILGA_Admin_UI::blank() );
            echo $contactId > 0 ? '<td class="njilga-col-num">' . self::share_cell( $e, $contactId ) . '</td>' : '';
            printf( '<td class="njilga-col-num">%s</td><td>%s</td><td class="njilga-col-actions">%s</td>', esc_html( MyNJILGA_Invoicing::money( (int) $e['balance'] ) ), self::status_pill( $e, $today ), self::actions( $e ) );
            echo '</tr>';
        }
        self::overflow_row( count( $open ), $cols );
        echo '</tbody></table></div></div>';
    }

    /**
     * @param array<int,array<string,mixed>> $payments
     */
    private static function payments_table( array $payments ): void {
        echo '<div class="njilga-card njilga-table-boxed"><div class="njilga-tablewrap"><table class="njilga-table"><thead><tr>';
        echo '<th>Date</th><th>Invoice</th><th>Year</th><th>Method</th><th>Reference</th><th class="njilga-col-num">Amount</th>';
        echo '</tr></thead><tbody>';
        foreach ( array_slice( $payments, 0, self::MAX_ROWS ) as $p ) {
            $isRefund = $p['kind'] === 'refund';
            $amount   = MyNJILGA_Invoicing::money( abs( (int) $p['amount'] ) );
            printf(
                '<tr><td class="njilga-nowrap">%s</td><td class="njilga-nowrap">%s</td><td>%d</td><td>%s</td><td>%s</td><td class="njilga-col-num">%s</td></tr>',
                esc_html( self::date( substr( (string) $p['when'], 0, 10 ) ) ),
                self::link_to_key( (string) $p['invoice_key'], (string) $p['invoice_number'] ),
                (int) $p['year'],
                $p['method'] !== '' ? esc_html( (string) $p['method'] ) . ( $isRefund ? ' ' . MyNJILGA_Admin_UI::pill( 'Refund', 'destructive' ) : '' ) : MyNJILGA_Admin_UI::blank(),
                $p['reference'] !== '' ? '<span class="njilga-mono">' . esc_html( (string) $p['reference'] ) . '</span>' : MyNJILGA_Admin_UI::blank(),
                $isRefund ? '<span class="njilga-status njilga-status-bad">-' . esc_html( $amount ) . '</span>' : esc_html( $amount )
            );
        }
        self::overflow_row( count( $payments ), 6 );
        echo '</tbody></table></div></div>';
    }

    /**
     * @param array<int,array<string,mixed>> $entries
     */
    private static function invoices_table( array $entries, int $contactId, bool $showFirm, string $today ): void {
        $cols = 8 + ( $contactId > 0 ? 1 : 0 ) + ( $showFirm ? 1 : 0 );
        echo '<div class="njilga-card njilga-table-boxed"><div class="njilga-tablewrap"><table class="njilga-table"><thead><tr>';
        echo '<th>Invoice</th><th>Year</th>' . ( $showFirm ? '<th class="njilga-col-wide">Firm</th>' : '' ) . '<th class="njilga-col-wide">Description</th><th class="njilga-col-wide">Billed to</th>';
        echo $contactId > 0 ? '<th class="njilga-col-num">This contact</th>' : '';
        echo '<th class="njilga-col-num">Total</th><th class="njilga-col-num">Paid</th><th>Status</th><th>Source</th><th class="njilga-col-actions"></th>';
        echo '</tr></thead><tbody>';
        foreach ( array_slice( $entries, 0, self::MAX_ROWS ) as $e ) {
            echo '<tr>';
            printf( '<td class="njilga-nowrap">%s</td><td>%d</td>', self::invoice_link( $e ), (int) $e['year'] );
            echo $showFirm ? '<td class="njilga-col-wide">' . esc_html( (string) $e['company'] ) . '</td>' : '';
            printf( '<td class="njilga-col-wide">%s</td><td class="njilga-col-wide">%s</td>', esc_html( (string) $e['description'] ), self::person_cell( $e ) );
            echo $contactId > 0 ? '<td class="njilga-col-num">' . self::share_cell( $e, $contactId ) . '</td>' : '';
            printf(
                '<td class="njilga-col-num">%s</td><td class="njilga-col-num">%s</td><td>%s</td><td>%s</td><td class="njilga-col-actions">%s</td>',
                esc_html( MyNJILGA_Invoicing::money( (int) $e['total'] ) ),
                (int) $e['paid'] > 0 ? esc_html( MyNJILGA_Invoicing::money( (int) $e['paid'] ) ) : MyNJILGA_Admin_UI::blank(),
                self::status_pill( $e, $today ),
                self::source_pill( $e ),
                self::actions( $e )
            );
            echo '</tr>';
        }
        self::overflow_row( count( $entries ), $cols );
        echo '</tbody></table></div></div>';
    }

    private static function overflow_row( int $count, int $cols ): void {
        if ( $count > self::MAX_ROWS ) {
            printf(
                '<tr class="njilga-emptyrow"><td colspan="%d">Showing the newest %d of %d.</td></tr>',
                (int) $cols,
                self::MAX_ROWS,
                $count
            );
        }
    }

    // -------------------------------------------------------------------------
    // Cells and parts (shared with the invoice viewer)
    // -------------------------------------------------------------------------

    /**
     * The invoice's status as a pill. An open invoice past its due date
     * reads "Overdue".
     *
     * @param array<string,mixed> $e
     */
    public static function status_pill( array $e, string $today ): string {
        switch ( $e['status'] ) {
            case MyNJILGA_Dues_History::ST_PAID:
                return MyNJILGA_Admin_UI::pill( 'Paid', 'success' );
            case MyNJILGA_Dues_History::ST_OPEN:
                return MyNJILGA_Dues_History::is_overdue( $e, $today )
                    ? MyNJILGA_Admin_UI::pill( 'Overdue', 'destructive' )
                    : MyNJILGA_Admin_UI::pill( 'Open', 'info' );
            case MyNJILGA_Dues_History::ST_PROCESSING:
                return MyNJILGA_Admin_UI::pill( 'ACH clearing', 'warning' );
            case MyNJILGA_Dues_History::ST_LAPSED:
                return MyNJILGA_Admin_UI::pill( 'Lapsed', 'destructive' );
            case MyNJILGA_Dues_History::ST_VOID:
                return MyNJILGA_Admin_UI::pill( 'Voided', 'muted' );
            case MyNJILGA_Dues_History::ST_UNCOLLECTIBLE:
                return MyNJILGA_Admin_UI::pill( 'Written off', 'destructive' );
            default:
                return MyNJILGA_Admin_UI::pill( ucfirst( (string) $e['status'] ), 'muted' );
        }
    }

    /**
     * Where the invoice came from: Stripe for live invoices, PMPro /
     * Imported for recreated history. A Test-mode invoice says so.
     *
     * @param array<string,mixed> $e
     */
    public static function source_pill( array $e ): string {
        $labels = [ 'stripe' => 'Stripe', 'pmpro' => 'PMPro', 'import' => 'Imported' ];
        $out    = MyNJILGA_Admin_UI::pill( $labels[ $e['origin'] ] ?? ucfirst( (string) $e['origin'] ), 'outline' );
        if ( empty( $e['livemode'] ) ) {
            $out .= ' ' . MyNJILGA_Admin_UI::pill( 'Test', 'warning' );
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $e
     */
    private static function person_cell( array $e ): string {
        return $e['bill_to'] !== '' ? esc_html( (string) $e['bill_to'] ) : MyNJILGA_Admin_UI::blank();
    }

    /**
     * A contact's own part of an invoice: their lines' total, or a dash
     * when the invoice does not list them (they are only its bill-to, or
     * it is another member's invoice at their firm).
     *
     * @param array<string,mixed> $e
     */
    private static function share_cell( array $e, int $contactId ): string {
        $share = MyNJILGA_Dues_History::share_for( $e, $contactId );
        return $share === null ? MyNJILGA_Admin_UI::blank() : esc_html( MyNJILGA_Invoicing::money( $share ) );
    }

    /**
     * The invoice number as a link that opens the viewer in a new tab —
     * a plain link, because the panel is inside another app's page and
     * must not navigate it away.
     *
     * @param array<string,mixed> $e
     */
    public static function invoice_link( array $e ): string {
        return sprintf(
            '<a href="%s" target="_blank" rel="noopener"><strong>%s</strong></a>',
            esc_url( self::invoice_url( $e['src'], (int) $e['id'] ) ),
            esc_html( (string) $e['number'] )
        );
    }

    /**
     * A payment row's link back to its invoice, from the "src:id" key.
     */
    private static function link_to_key( string $key, string $number ): string {
        $parts = explode( ':', $key, 2 );
        if ( count( $parts ) !== 2 ) {
            return esc_html( $number );
        }
        return sprintf(
            '<a href="%s" target="_blank" rel="noopener">%s</a>',
            esc_url( self::invoice_url( $parts[0], (int) $parts[1] ) ),
            esc_html( $number )
        );
    }

    /**
     * View (the viewer page) and, for a live invoice that can still be
     * paid, a link to Stripe's hosted payment page.
     *
     * @param array<string,mixed> $e
     */
    private static function actions( array $e ): string {
        $out = sprintf(
            '<a class="njilga-btn njilga-btn-outline njilga-btn-sm" href="%s" target="_blank" rel="noopener">View</a>',
            esc_url( self::invoice_url( $e['src'], (int) $e['id'] ) )
        );
        if ( $e['status'] === MyNJILGA_Dues_History::ST_OPEN && $e['hosted_url'] !== '' ) {
            $out .= sprintf(
                ' <a class="njilga-btn njilga-btn-primary njilga-btn-sm" href="%s" target="_blank" rel="noopener">Pay online</a>',
                esc_url( $e['hosted_url'] )
            );
        }
        return $out;
    }

    public static function invoice_url( string $src, int $id ): string {
        return add_query_arg(
            [ 'page' => MyNJILGA_Admin_Menu::SLUG_DUES_INVOICE, 'src' => $src === MyNJILGA_Dues_History::SRC_HIST ? 'hist' : 'live', 'id' => $id ],
            admin_url( 'admin.php' )
        );
    }

    /** "Mar 4, 2026" from a Y-m-d; '—' for none. Noon, so a timezone offset can never roll the day. */
    public static function date( string $ymd ): string {
        $ymd = MyNJILGA_Dues_History::date_part( $ymd );
        if ( $ymd === '' ) {
            return '—';
        }
        $ts = strtotime( $ymd . ' 12:00:00' );
        return $ts ? date_i18n( 'M j, Y', $ts ) : $ymd;
    }

    public static function contact_url( int $contactId ): string {
        return admin_url( 'admin.php?page=fluentcrm-admin#/subscribers/' . $contactId );
    }

    public static function company_url( int $companyId ): string {
        return admin_url( 'admin.php?page=fluentcrm-admin#/companies/' . $companyId );
    }
}
