<?php
/**
 * One invoice, on screen — the page the Dues History tabs open when staff
 * "call up" an invoice. A hidden admin page (admin.php?page=
 * my-njilga-dues-invoice&src=live|hist&id=N), the same document for every
 * kind of invoice: a live Stripe invoice (with its hosted payment page and
 * PDF a click away) and an invoice recreated from PMPro or a spreadsheet
 * (which has no Stripe page, so this IS the invoice).
 *
 * Read-only. It prints cleanly: the print stylesheet in the design system
 * hides the admin chrome.
 */
class MyNJILGA_Page_Dues_Invoice {

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Access denied.' );
        }

        $src   = isset( $_GET['src'] ) && sanitize_key( wp_unslash( (string) $_GET['src'] ) ) === 'hist' ? MyNJILGA_Dues_History::SRC_HIST : MyNJILGA_Dues_History::SRC_LIVE;
        $id    = isset( $_GET['id'] ) ? absint( wp_unslash( (string) $_GET['id'] ) ) : 0;
        $entry = MyNJILGA_Dues_History::entry( $src, $id );

        if ( ! $entry ) {
            MyNJILGA_Admin_UI::open( 'Invoice not found', 'There is no issued invoice with that number. Drafts are not shown here — they live in Invoicing until they are created.' );
            MyNJILGA_Admin_UI::close();
            return;
        }

        $today = current_time( 'Y-m-d' );
        MyNJILGA_Admin_UI::open(
            'Invoice ' . $entry['number'],
            trim( $entry['company'] . ( $entry['company'] !== '' ? ' · ' : '' ) . $entry['year'] . ' dues' ),
            self::header_actions( $entry )
        );

        echo '<div class="njilga-card njilga-doc">';
        self::head( $entry, $today );
        self::meta( $entry );
        self::parties( $entry );
        self::lines( $entry );
        self::totals( $entry );
        self::payments( $entry );
        if ( $entry['notes'] !== '' ) {
            echo '<div class="njilga-doc-note">' . nl2br( esc_html( $entry['notes'] ) ) . '</div>';
        }
        if ( $entry['src'] === MyNJILGA_Dues_History::SRC_HIST ) {
            echo '<p class="njilga-dim">This invoice was recreated from ' . esc_html( $entry['origin'] === 'pmpro' ? 'Paid Memberships Pro' : 'an uploaded spreadsheet' ) . ' for reference. It is a record only: it is not in Stripe and does not affect any current invoice, payment total or membership.</p>';
        }
        echo '</div>';

        self::scripts();
        MyNJILGA_Admin_UI::close();
    }

    /**
     * @param array<string,mixed> $e
     */
    private static function header_actions( array $e ): string {
        $out = '<button type="button" class="njilga-btn njilga-btn-outline njilga-noprint" data-njilga-print>' . MyNJILGA_Admin_UI::icon( 'printer' ) . ' Print</button>';
        if ( $e['pdf_url'] !== '' ) {
            $out .= sprintf( ' <a class="njilga-btn njilga-btn-outline njilga-noprint" href="%s" target="_blank" rel="noopener">%s PDF</a>', esc_url( $e['pdf_url'] ), MyNJILGA_Admin_UI::icon( 'download' ) );
        }
        if ( $e['status'] === MyNJILGA_Dues_History::ST_OPEN && $e['hosted_url'] !== '' ) {
            $out .= sprintf( ' <a class="njilga-btn njilga-btn-primary njilga-noprint" href="%s" target="_blank" rel="noopener">Payment page</a>', esc_url( $e['hosted_url'] ) );
        } elseif ( $e['hosted_url'] !== '' ) {
            $out .= sprintf( ' <a class="njilga-btn njilga-btn-outline njilga-noprint" href="%s" target="_blank" rel="noopener">%s Stripe page</a>', esc_url( $e['hosted_url'] ), MyNJILGA_Admin_UI::icon( 'external' ) );
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $e
     */
    private static function head( array $e, string $today ): void {
        printf(
            '<div class="njilga-doc-head"><div><div class="njilga-doc-org">%s</div><div class="njilga-doc-title">Invoice %s</div><div class="njilga-doc-sub">%s</div></div><div>%s %s</div></div>',
            esc_html( (string) get_bloginfo( 'name' ) ),
            esc_html( (string) $e['number'] ),
            esc_html( (string) $e['description'] ),
            MyNJILGA_Dues_History_View::status_pill( $e, $today ),
            MyNJILGA_Dues_History_View::source_pill( $e )
        );
    }

    /**
     * @param array<string,mixed> $e
     */
    private static function meta( array $e ): void {
        $cells = [
            'Dues year' => (string) $e['year'],
            'Issued'    => MyNJILGA_Dues_History_View::date( (string) $e['issued'] ),
            'Due'       => MyNJILGA_Dues_History_View::date( (string) $e['due'] ),
            'Paid on'   => MyNJILGA_Dues_History_View::date( (string) $e['paid_on'] ),
        ];
        if ( $e['method'] !== '' ) {
            $cells['Method'] = (string) $e['method'];
        }
        echo '<div class="njilga-doc-meta">';
        foreach ( $cells as $label => $value ) {
            printf( '<div><div class="njilga-doc-k">%s</div><div class="njilga-doc-v">%s</div></div>', esc_html( $label ), esc_html( $value ) );
        }
        echo '</div>';
    }

    /**
     * @param array<string,mixed> $e
     */
    private static function parties( array $e ): void {
        echo '<div class="njilga-doc-parties">';

        $billTo = $e['bill_to'] !== '' ? esc_html( (string) $e['bill_to'] ) : MyNJILGA_Admin_UI::blank();
        if ( $e['bill_to'] !== '' && (int) $e['bill_to_id'] > 0 ) {
            $billTo = sprintf( '<a href="%s" target="_blank" rel="noopener">%s</a>', esc_url( MyNJILGA_Dues_History_View::contact_url( (int) $e['bill_to_id'] ) ), $billTo );
        }
        printf( '<div><div class="njilga-doc-k">Billed to</div><div class="njilga-doc-v">%s</div></div>', $billTo ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.

        $firm = $e['company'] !== '' ? esc_html( (string) $e['company'] ) : MyNJILGA_Admin_UI::blank();
        if ( $e['company'] !== '' && (int) $e['company_id'] > 0 ) {
            $firm = sprintf( '<a href="%s" target="_blank" rel="noopener">%s</a>', esc_url( MyNJILGA_Dues_History_View::company_url( (int) $e['company_id'] ) ), $firm );
        }
        printf( '<div><div class="njilga-doc-k">Firm</div><div class="njilga-doc-v">%s</div></div>', $firm ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.

        echo '</div>';
    }

    /**
     * @param array<string,mixed> $e
     */
    private static function lines( array $e ): void {
        echo '<div class="njilga-card njilga-table-boxed"><div class="njilga-tablewrap"><table class="njilga-table"><thead><tr><th>Description</th><th class="njilga-col-num">Amount</th></tr></thead><tbody>';
        if ( ! $e['lines'] ) {
            printf(
                '<tr><td>%s</td><td class="njilga-col-num">%s</td></tr>',
                esc_html( (string) $e['description'] ),
                esc_html( MyNJILGA_Invoicing::money( (int) $e['total'] ) )
            );
        }
        foreach ( $e['lines'] as $l ) {
            $cents = (int) $l['amount'];
            printf(
                '<tr><td>%s</td><td class="njilga-col-num">%s</td></tr>',
                esc_html( (string) $l['title'] ),
                $cents < 0 ? '<span class="njilga-status njilga-status-ok">-' . esc_html( MyNJILGA_Invoicing::money( abs( $cents ) ) ) . '</span>' : esc_html( MyNJILGA_Invoicing::money( $cents ) )
            );
        }
        echo '</tbody></table></div></div>';
    }

    /**
     * @param array<string,mixed> $e
     */
    private static function totals( array $e ): void {
        echo '<div class="njilga-doc-totals"><table><tbody>';
        printf( '<tr><td>Total</td><td>%s</td></tr>', esc_html( MyNJILGA_Invoicing::money( (int) $e['total'] ) ) );
        if ( (int) $e['paid'] > 0 ) {
            printf( '<tr><td>Paid</td><td>%s</td></tr>', esc_html( MyNJILGA_Invoicing::money( (int) $e['paid'] ) ) );
        }
        if ( (int) $e['refunded'] > 0 ) {
            printf( '<tr><td>Refunded</td><td>-%s</td></tr>', esc_html( MyNJILGA_Invoicing::money( (int) $e['refunded'] ) ) );
        }
        if ( MyNJILGA_Dues_History::is_open( $e ) ) {
            printf( '<tr class="njilga-doc-due"><td>Balance due</td><td>%s</td></tr>', esc_html( MyNJILGA_Invoicing::money( (int) $e['balance'] ) ) );
        }
        echo '</tbody></table></div>';
    }

    /**
     * @param array<string,mixed> $e
     */
    private static function payments( array $e ): void {
        MyNJILGA_Admin_UI::section( 'Payments', '', count( $e['payments'] ) );
        if ( ! $e['payments'] ) {
            echo '<p class="njilga-dim">No payments recorded against this invoice.</p>';
            return;
        }
        echo '<div class="njilga-card njilga-table-boxed"><div class="njilga-tablewrap"><table class="njilga-table"><thead><tr><th>Date</th><th>Method</th><th>Reference</th><th>Receipt</th><th class="njilga-col-num">Amount</th></tr></thead><tbody>';
        foreach ( $e['payments'] as $p ) {
            $isRefund = $p['kind'] === 'refund';
            $amount   = MyNJILGA_Invoicing::money( abs( (int) $p['amount'] ) );
            printf(
                '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td class="njilga-col-num">%s</td></tr>',
                esc_html( MyNJILGA_Dues_History_View::date( substr( (string) $p['when'], 0, 10 ) ) ),
                $p['method'] !== '' ? esc_html( (string) $p['method'] ) . ( $isRefund ? ' ' . MyNJILGA_Admin_UI::pill( 'Refund', 'destructive' ) : '' ) : MyNJILGA_Admin_UI::blank(), // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in the helpers.
                $p['reference'] !== '' ? '<span class="njilga-mono">' . esc_html( (string) $p['reference'] ) . '</span>' : MyNJILGA_Admin_UI::blank(), // phpcs:ignore WordPress.Security.EscapeOutput
                $p['receipt_url'] !== '' ? sprintf( '<a href="%s" target="_blank" rel="noopener">Receipt</a>', esc_url( (string) $p['receipt_url'] ) ) : MyNJILGA_Admin_UI::blank(), // phpcs:ignore WordPress.Security.EscapeOutput
                $isRefund ? '<span class="njilga-status njilga-status-bad">-' . esc_html( $amount ) . '</span>' : esc_html( $amount ) // phpcs:ignore WordPress.Security.EscapeOutput
            );
        }
        echo '</tbody></table></div></div>';
    }

    private static function scripts(): void {
        echo <<<'HTML'
<script>
(function(){
  var b = document.querySelector('[data-njilga-print]');
  if (b) { b.addEventListener('click', function(){ window.print(); }); }
})();
</script>
HTML;
    }
}
