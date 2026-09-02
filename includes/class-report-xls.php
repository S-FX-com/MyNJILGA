<?php
/**
 * Streams the Membership by Firm report as a formatted Excel (.xls) file.
 *
 * No PhpSpreadsheet dependency: the file is an HTML table served with the
 * Excel MIME type, which Excel (and Google Sheets / LibreOffice) opens with
 * the inline formatting intact — bold firm headings, a styled header row,
 * and one block per firm. This keeps the plugin's no-library export ethos
 * while preserving the grouped, bold formatting that CSV can't carry.
 */
class MyNJILGA_Report_Xls {

    /** Column headers for a Membership by Firm contacts table. */
    const FIRM_HEADERS = [ 'First Name', 'Last Name', 'Email', 'Dues', 'Trustees', 'Past President', 'Payment' ];

    /**
     * admin-post handler for the Membership by Firm export.
     */
    public static function handle(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Access denied.' );
        }
        check_admin_referer( 'my_njilga_export_firms' );

        if ( ! MyNJILGA_Members_Data::fluentcrm_active() ) {
            wp_die( 'FluentCRM is not active.' );
        }

        $scope = ( sanitize_key( $_REQUEST['scope'] ?? '' ) === 'active' ) ? 'active' : 'all';
        self::stream_firms( $scope );
    }

    private static function stream_firms( string $scope = 'all' ): void {
        $firms    = MyNJILGA_Members_Data::get_membership_by_firm( $scope );
        $title    = $scope === 'active' ? 'Membership by Firm — Active Membership Only' : 'Membership by Firm — All Membership';
        $slug     = $scope === 'active' ? 'active' : 'all';
        $filename = sprintf( 'MyNJILGA_membership-by-firm_%s_%s.xls', $slug, date( 'Y-m-d' ) );

        nocache_headers();
        header( 'Content-Type: application/vnd.ms-excel; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

        // UTF-8 BOM so accented firm/contact names render correctly in Excel.
        echo "\xEF\xBB\xBF";

        $cols = count( self::FIRM_HEADERS );

        echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel">';
        echo '<head><meta charset="utf-8"></head><body>';
        echo '<table border="1" cellspacing="0" cellpadding="4" style="border-collapse:collapse;font-family:Calibri,Arial,sans-serif;font-size:11pt">';

        echo '<tr><td colspan="' . $cols . '" style="font-size:16pt;font-weight:bold">' . self::xls( $title ) . '</td></tr>';
        echo '<tr><td colspan="' . $cols . '" style="color:#888">Generated ' . esc_html( date( 'Y-m-d' ) ) . '</td></tr>';
        echo '<tr><td colspan="' . $cols . '"></td></tr>';

        self::render_firm_blocks( $firms );

        echo '</table></body></html>';
        exit;
    }

    /**
     * Renders one block per firm — a bold firm heading, a bold column
     * header row, then a colored row per contact (green "Dues Paid" / red
     * "Unpaid Dues") — followed by a spacer row. Expects to be echoed
     * inside an already-open <table>. Shared by the standalone Membership
     * by Firm export and the Executive Summary, so both stay in sync on
     * column order and Dues coloring.
     *
     * @param array<int,array{name:string,contacts:array<int,array<string,string>>}> $firms
     */
    public static function render_firm_blocks( array $firms ): void {
        $cols = count( self::FIRM_HEADERS );

        foreach ( $firms as $firm ) {
            // Bold firm heading spanning the full width of the table.
            printf(
                '<tr><td colspan="%d" style="font-weight:bold;font-size:13pt;background-color:#DCE6F1">%s (%d)</td></tr>',
                $cols,
                self::xls( $firm['name'] ),
                count( $firm['contacts'] )
            );

            // Bold column header row.
            echo '<tr>';
            foreach ( self::FIRM_HEADERS as $h ) {
                echo '<td style="font-weight:bold;background-color:#F2F2F2">' . self::xls( $h ) . '</td>';
            }
            echo '</tr>';

            foreach ( $firm['contacts'] as $c ) {
                echo '<tr>';
                foreach ( [ 'first_name', 'last_name', 'email' ] as $key ) {
                    echo '<td style="mso-number-format:\'\@\'">' . self::xls( $c[ $key ] ) . '</td>';
                }
                echo self::dues_cell( $c['dues'] );
                foreach ( [ 'trustees', 'past_president', 'payment' ] as $key ) {
                    echo '<td style="mso-number-format:\'\@\'">' . self::xls( $c[ $key ] ) . '</td>';
                }
                echo '</tr>';
            }

            // Spacer row between firms.
            echo '<tr><td colspan="' . $cols . '"></td></tr>';
        }
    }

    /**
     * Dues cell: bold green for "Dues Paid", bold red for "Unpaid Dues",
     * plain otherwise — matches the on-screen Membership by Firm coloring.
     */
    private static function dues_cell( string $dues ): string {
        $color = MyNJILGA_Tags::dues_color( $dues );
        $style = 'mso-number-format:\'\@\'' . ( $color !== '' ? ';font-weight:bold;color:' . $color : '' );
        return '<td style="' . $style . '">' . self::xls( $dues ) . '</td>';
    }

    /**
     * Escapes a cell value for the HTML-based .xls. Empty strings stay
     * empty (no em-dash placeholder — a blank cell is the export's "blank").
     */
    public static function xls( string $value ): string {
        return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' );
    }

    // -------------------------------------------------------------------------
    // Payments ledger (Stripe migration phase 4) — By-firm / Aging exports.
    // Same MyNJILGA_Page_Payments data source as the CSV exports above and
    // the on-screen tables; same bold-heading/subtotal-row block pattern
    // as render_firm_blocks() (Membership by Firm), reused rather than
    // reinvented.
    // -------------------------------------------------------------------------

    /** Semantic colors matching the design system's status ramp tokens (design.md §2) — the one place this exporter needs literal hex, since Excel only understands inline styling. */
    const COLOR_SUCCESS = '#067647';
    const COLOR_WARN     = '#c2410c';
    const COLOR_DANGER   = '#b42318';
    const COLOR_MUTED    = '#71717a';

    /**
     * admin-post handler for the Payments page's Excel exports.
     * ?view=firm|aging selects which.
     */
    public static function handle_payments(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Access denied.' );
        }
        check_admin_referer( 'my_njilga_export_payments_xls' );

        if ( ! MyNJILGA_Members_Data::fluentcrm_active() ) {
            wp_die( 'FluentCRM is not active.' );
        }

        $view = sanitize_key( $_REQUEST['view'] ?? '' );
        switch ( $view ) {
            case 'firm':  self::stream_payments_firm_xls();  break;
            case 'aging': self::stream_payments_aging_xls(); break;
            default:
                wp_die( 'Unknown payments view.' );
        }
    }

    private static function stream_payments_firm_xls(): void {
        $lines = MyNJILGA_Page_Payments::build_lines();
        $firms = MyNJILGA_Page_Payments::group_by_firm( $lines );
        $title = 'Payments — By Firm';

        self::stream_payments_header( $title, 'payments-by-firm', 4 );

        $verdictColors = [
            'paid'        => self::COLOR_SUCCESS,
            'partial'     => self::COLOR_WARN,
            'unpaid'      => self::COLOR_DANGER,
            'written_off' => self::COLOR_MUTED,
        ];
        $verdictLabels = [ 'paid' => 'Paid', 'partial' => 'Partial', 'unpaid' => 'Unpaid', 'written_off' => 'Written Off' ];

        foreach ( $firms as $f ) {
            printf(
                '<tr><td colspan="4" style="font-weight:bold;font-size:13pt;background-color:#DCE6F1">%s &mdash; Outstanding %s</td></tr>',
                self::xls( $f['name'] ),
                self::xls( MyNJILGA_Invoicing::money( (int) $f['outstandingCents'] ) )
            );
            echo '<tr>';
            foreach ( [ 'Dues Year', 'Status' ] as $h ) {
                echo '<td style="font-weight:bold;background-color:#F2F2F2">' . self::xls( $h ) . '</td>';
            }
            echo '<td colspan="2" style="font-weight:bold;background-color:#F2F2F2"></td></tr>';

            foreach ( $f['years'] as $year => $yr ) {
                $color = $verdictColors[ $yr['verdict'] ] ?? '';
                printf(
                    '<tr><td style="mso-number-format:\'0\'">%d</td><td style="%s">%s</td><td colspan="2"></td></tr>',
                    (int) $year,
                    $color !== '' ? 'font-weight:bold;color:' . $color : '',
                    self::xls( $verdictLabels[ $yr['verdict'] ] ?? ucfirst( $yr['verdict'] ) )
                );
            }

            echo '<tr><td colspan="4"></td></tr>';
        }

        echo '</table></body></html>';
        exit;
    }

    private static function stream_payments_aging_xls(): void {
        $lines = MyNJILGA_Page_Payments::build_lines();
        $aging = MyNJILGA_Page_Payments::aging_buckets( $lines );
        $title = 'Payments — Aging';

        self::stream_payments_header( $title, 'payments-aging', 4 );

        foreach ( $aging['buckets'] as $bucket ) {
            if ( empty( $bucket['lines'] ) ) {
                continue;
            }
            printf(
                '<tr><td colspan="4" style="font-weight:bold;font-size:13pt;background-color:#DCE6F1">%s (%d) &mdash; Subtotal %s</td></tr>',
                self::xls( $bucket['label'] ),
                count( $bucket['lines'] ),
                self::xls( MyNJILGA_Invoicing::money( (int) $bucket['subtotalCents'] ) )
            );
            echo '<tr>';
            foreach ( [ 'Firm', 'Invoice #', 'Balance', 'Due Date' ] as $h ) {
                echo '<td style="font-weight:bold;background-color:#F2F2F2">' . self::xls( $h ) . '</td>';
            }
            echo '</tr>';

            foreach ( $bucket['lines'] as $l ) {
                $invoiceNo = $l['invoiceNo'] !== '' ? $l['invoiceNo'] : ( $l['invoiceId'] !== '' ? '…' . substr( $l['invoiceId'], -8 ) : '' );
                printf(
                    '<tr><td style="mso-number-format:\'\@\'">%s</td><td style="mso-number-format:\'\@\'">%s</td><td>%s</td><td style="mso-number-format:\'\@\'">%s</td></tr>',
                    self::xls( $l['firm'] ),
                    self::xls( $invoiceNo ),
                    self::xls( MyNJILGA_Invoicing::money( $l['due'] ) ),
                    self::xls( $l['dueDate'] )
                );
            }

            echo '<tr><td colspan="4"></td></tr>';
        }

        printf(
            '<tr><td colspan="2" style="font-weight:bold;font-size:12pt">Grand Total</td><td style="font-weight:bold;font-size:12pt">%s</td><td></td></tr>',
            self::xls( MyNJILGA_Invoicing::money( (int) $aging['grandTotalCents'] ) )
        );

        echo '</table></body></html>';
        exit;
    }

    /**
     * Shared header block (MIME headers + title/timestamp rows + opening
     * <table>) for the two Payments .xls exports — mirrors stream_firms()'s
     * own opening block above, just parameterized by title/slug/column
     * count instead of duplicated per export.
     */
    private static function stream_payments_header( string $title, string $slug, int $cols ): void {
        $filename = sprintf( 'MyNJILGA_%s_%s.xls', $slug, date( 'Y-m-d' ) );

        nocache_headers();
        header( 'Content-Type: application/vnd.ms-excel; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

        echo "\xEF\xBB\xBF";

        echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel">';
        echo '<head><meta charset="utf-8"></head><body>';
        echo '<table border="1" cellspacing="0" cellpadding="4" style="border-collapse:collapse;font-family:Calibri,Arial,sans-serif;font-size:11pt">';

        echo '<tr><td colspan="' . $cols . '" style="font-size:16pt;font-weight:bold">' . self::xls( $title ) . '</td></tr>';
        echo '<tr><td colspan="' . $cols . '" style="color:#888">Generated ' . esc_html( date( 'Y-m-d' ) ) . '</td></tr>';
        echo '<tr><td colspan="' . $cols . '"></td></tr>';
    }
}
