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
}
