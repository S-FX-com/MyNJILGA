<?php
/**
 * Executive Summary — a single formatted .xls that combines every My
 * NJILGA report: Overview KPIs, Active Paid Members, Trustees, Companies,
 * and Membership by Firm.
 *
 * Same HTML-table-served-as-.xls approach as MyNJILGA_Report_Xls (no
 * PhpSpreadsheet dependency). Sections are stacked in one sheet, each
 * introduced by a bold banner row, so it opens the same way in Excel,
 * Google Sheets, and LibreOffice rather than gambling on multi-sheet
 * support that only some of those honor.
 */
class MyNJILGA_Report_Summary {

    /** Full width for section banners/spacers — the widest section (Membership by Firm) has 7 columns. */
    const COLS = 7;

    /**
     * admin-post handler for the Executive Summary export.
     */
    public static function handle(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Access denied.' );
        }
        check_admin_referer( 'my_njilga_export_summary' );

        if ( ! MyNJILGA_Members_Data::fluentcrm_active() ) {
            wp_die( 'FluentCRM is not active.' );
        }

        self::stream();
    }

    private static function stream(): void {
        $filename = sprintf( 'MyNJILGA_executive-summary_%s.xls', date( 'Y-m-d' ) );

        nocache_headers();
        header( 'Content-Type: application/vnd.ms-excel; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

        // UTF-8 BOM so accented names render correctly in Excel.
        echo "\xEF\xBB\xBF";

        echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel">';
        echo '<head><meta charset="utf-8"></head><body>';
        echo '<table border="1" cellspacing="0" cellpadding="4" style="border-collapse:collapse;font-family:Calibri,Arial,sans-serif;font-size:11pt">';

        echo '<tr><td colspan="' . self::COLS . '" style="font-size:16pt;font-weight:bold">My NJILGA — Executive Summary</td></tr>';
        echo '<tr><td colspan="' . self::COLS . '" style="color:#888">Generated ' . esc_html( date( 'Y-m-d' ) ) . '</td></tr>';

        self::section( 'Overview' );
        self::render_overview();

        self::section( 'Active Paid Members' );
        self::render_members();

        self::section( 'Trustees' );
        self::render_trustees();

        self::section( 'Companies' );
        self::render_companies();

        self::section( 'Membership by Firm — All Membership' );
        MyNJILGA_Report_Xls::render_firm_blocks( MyNJILGA_Members_Data::get_membership_by_firm( 'all' ) );

        echo '</table></body></html>';
        exit;
    }

    /**
     * Full-width section banner (blue background, white bold text) that
     * introduces each report's block within the shared sheet.
     */
    private static function section( string $title ): void {
        echo '<tr><td colspan="' . self::COLS . '"></td></tr>';
        printf(
            '<tr><td colspan="%d" style="font-weight:bold;font-size:14pt;background-color:#2271b1;color:#ffffff">%s</td></tr>',
            self::COLS,
            MyNJILGA_Report_Xls::xls( $title )
        );
    }

    /**
     * Cross-report KPIs (paid/unpaid members, firms with/without paid
     * members, paid/unpaid trustees, exempt) plus the company-size
     * distribution buckets — the same figures shown in the report
     * dashboards and the Dashboard page's "Company distribution" list.
     */
    private static function render_overview(): void {
        $s        = MyNJILGA_Members_Data::report_stats();
        $bucketed = MyNJILGA_Members_Data::get_companies_bucketed();

        $rows = [
            [ 'Paid Members',                              $s['paid_members'] ],
            [ 'Unpaid Members',                            $s['unpaid_members'] ],
            [ 'Firms w/ Paid Members',                      $s['firms_with_paid'] ],
            [ 'Firms w/ No Paid Members',                   $s['firms_without_paid'] ],
            [ 'Paid Trustees',                              $s['paid_trustees'] ],
            [ 'Unpaid Trustees',                            $s['unpaid_trustees'] ],
            [ 'Exempt (Past President / Senior Trustee)',   $s['exempt'] ],
            [ 'Companies — 1 Paid Member',                  count( $bucketed['buckets']['1'] ?? [] ) ],
            [ 'Companies — 2–5 Paid Members',               count( $bucketed['buckets']['2-5'] ?? [] ) ],
            [ 'Companies — 6+ Paid Members',                count( $bucketed['buckets']['6+'] ?? [] ) ],
            [ 'Companies — No Paid Members',                count( $bucketed['buckets']['0'] ?? [] ) ],
        ];

        foreach ( $rows as $row ) {
            printf(
                '<tr><td style="font-weight:bold">%s</td><td>%d</td></tr>',
                MyNJILGA_Report_Xls::xls( $row[0] ),
                (int) $row[1]
            );
        }
    }

    /**
     * Active Paid Members section — mirrors the on-screen report's columns,
     * including the green PAID column (every row here carries the Dues
     * Paid tag by definition).
     */
    private static function render_members(): void {
        $rows = MyNJILGA_Members_Data::get_active_members();

        echo '<tr>';
        foreach ( [ 'Member', 'Email', 'Firm', 'Trustee', 'Payment Method', 'Paid' ] as $h ) {
            echo '<td style="font-weight:bold;background-color:#F2F2F2">' . MyNJILGA_Report_Xls::xls( $h ) . '</td>';
        }
        echo '</tr>';

        if ( empty( $rows ) ) {
            echo '<tr><td colspan="6" style="color:#999">No paid members yet.</td></tr>';
            return;
        }

        foreach ( $rows as $r ) {
            printf(
                '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td style="font-weight:bold;color:#1d6f42">PAID</td></tr>',
                MyNJILGA_Report_Xls::xls( $r['member'] ),
                MyNJILGA_Report_Xls::xls( $r['email'] ),
                MyNJILGA_Report_Xls::xls( $r['firm'] ),
                MyNJILGA_Report_Xls::xls( $r['trustee_status'] ),
                MyNJILGA_Report_Xls::xls( $r['payment_method'] )
            );
        }
    }

    /**
     * Trustees section — mirrors the on-screen report's columns, including
     * the green/red Paid indicator.
     */
    private static function render_trustees(): void {
        $rows = MyNJILGA_Members_Data::get_trustees();

        echo '<tr>';
        foreach ( [ 'Name', 'Role', 'Firm', 'Dues', 'Payment Method' ] as $h ) {
            echo '<td style="font-weight:bold;background-color:#F2F2F2">' . MyNJILGA_Report_Xls::xls( $h ) . '</td>';
        }
        echo '</tr>';

        if ( empty( $rows ) ) {
            echo '<tr><td colspan="5" style="color:#999">No trustees yet.</td></tr>';
            return;
        }

        foreach ( $rows as $r ) {
            printf(
                '<tr><td>%s</td><td>%s</td><td>%s</td><td style="font-weight:bold;color:%s">%s</td><td>%s</td></tr>',
                MyNJILGA_Report_Xls::xls( $r['member'] ),
                MyNJILGA_Report_Xls::xls( $r['trustee_status'] ),
                MyNJILGA_Report_Xls::xls( $r['firm'] ),
                $r['is_paid'] ? '#1d6f42' : '#d63638',
                $r['is_paid'] ? 'Paid' : 'Unpaid',
                MyNJILGA_Report_Xls::xls( $r['payment_method'] )
            );
        }
    }

    /**
     * Companies section — bucketed by paid-member count, same grouping as
     * the on-screen Companies report.
     */
    private static function render_companies(): void {
        $data         = MyNJILGA_Members_Data::get_companies_bucketed();
        $bucket_order = [ '1', '2-5', '6+', '0' ];

        foreach ( $bucket_order as $key ) {
            $companies = $data['buckets'][ $key ] ?? [];
            $label     = $data['bucket_labels'][ $key ];

            printf(
                '<tr><td colspan="%d" style="font-weight:bold;background-color:#DCE6F1">%s (%d)</td></tr>',
                self::COLS,
                MyNJILGA_Report_Xls::xls( $label ),
                count( $companies )
            );

            echo '<tr>';
            foreach ( [ 'Company', 'Member', 'Status' ] as $h ) {
                echo '<td style="font-weight:bold;background-color:#F2F2F2">' . MyNJILGA_Report_Xls::xls( $h ) . '</td>';
            }
            echo '</tr>';

            if ( empty( $companies ) ) {
                echo '<tr><td colspan="3" style="color:#999">None.</td></tr>';
                continue;
            }

            foreach ( $companies as $c ) {
                if ( empty( $c['members'] ) ) {
                    printf(
                        '<tr><td>%s</td><td colspan="2" style="color:#999">No contacts</td></tr>',
                        MyNJILGA_Report_Xls::xls( $c['name'] )
                    );
                    continue;
                }
                foreach ( $c['members'] as $m ) {
                    printf(
                        '<tr><td>%s</td><td>%s</td><td style="font-weight:bold;color:%s">%s</td></tr>',
                        MyNJILGA_Report_Xls::xls( $c['name'] ),
                        MyNJILGA_Report_Xls::xls( $m['name'] ),
                        $m['is_paid'] ? '#1d6f42' : '#d63638',
                        $m['is_paid'] ? 'Paid' : 'Unpaid'
                    );
                }
            }
        }
    }
}
