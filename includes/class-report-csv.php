<?php
/**
 * Streams a CSV download for one of the three My NJILGA reports.
 *
 * Pure SPL: no PhpSpreadsheet, no composer dependency, no minimum PHP
 * version beyond what WordPress itself requires. Each method writes a
 * header row + data rows to php://output and exits.
 */
class MyNJILGA_Report_Csv {

    const TYPE_MEMBERS   = 'members';
    const TYPE_TRUSTEES  = 'trustees';
    const TYPE_COMPANIES = 'companies';

    /**
     * Entry point: dispatch on the report type.
     */
    public static function stream( string $type ): void {
        switch ( $type ) {
            case self::TYPE_MEMBERS:   self::stream_members();   break;
            case self::TYPE_TRUSTEES:  self::stream_trustees();  break;
            case self::TYPE_COMPANIES: self::stream_companies(); break;
            default:
                wp_die( 'Unknown report type.' );
        }
    }

    private static function stream_members(): void {
        $rows = MyNJILGA_Members_Data::get_active_members();
        $fh   = self::open( 'active-members' );

        fputcsv( $fh, [ 'First Name', 'Last Name', 'Email', 'Firm Name', 'Trustee', 'Payment Method', 'CRM ID' ] );
        foreach ( $rows as $r ) {
            fputcsv( $fh, [
                $r['first_name'],
                $r['last_name'],
                $r['email'],
                $r['firm'],
                $r['trustee_status'],
                $r['payment_method'],
                $r['subscriber_id'],
            ] );
        }
        fclose( $fh );
        exit;
    }

    private static function stream_trustees(): void {
        $rows = MyNJILGA_Members_Data::get_trustees();
        $fh   = self::open( 'trustees' );

        fputcsv( $fh, [ 'First Name', 'Last Name', 'Email', 'Firm Name', 'Trustee', 'Payment Method', 'CRM ID' ] );
        foreach ( $rows as $r ) {
            fputcsv( $fh, [
                $r['first_name'],
                $r['last_name'],
                $r['email'],
                $r['firm'],
                $r['trustee_status'],
                $r['payment_method'],
                $r['subscriber_id'],
            ] );
        }
        fclose( $fh );
        exit;
    }

    /**
     * Long-format companies CSV: one row per (company, member). Bucket
     * label is repeated so the file can be filtered / pivoted in Excel.
     */
    private static function stream_companies(): void {
        $data         = MyNJILGA_Members_Data::get_companies_bucketed();
        $bucket_order = [ '1', '2-5', '6+', '0' ];
        $fh           = self::open( 'companies' );

        fputcsv( $fh, [ 'Bucket', 'Company', 'Paid Members', 'Total Members', 'Member', 'Status' ] );

        foreach ( $bucket_order as $key ) {
            $companies = $data['buckets'][ $key ] ?? [];
            $label     = $data['bucket_labels'][ $key ];
            foreach ( $companies as $c ) {
                if ( empty( $c['members'] ) ) {
                    fputcsv( $fh, [ $label, $c['name'], $c['paid_count'], $c['total_count'], '', '' ] );
                    continue;
                }
                foreach ( $c['members'] as $m ) {
                    fputcsv( $fh, [
                        $label,
                        $c['name'],
                        $c['paid_count'],
                        $c['total_count'],
                        $m['name'],
                        $m['is_paid'] ? 'Paid' : 'Unpaid',
                    ] );
                }
            }
        }
        fclose( $fh );
        exit;
    }

    // -------------------------------------------------------------------------
    // Payments ledger (Stripe migration phase 4) — By-invoice/By-firm/Aging.
    // Data comes from MyNJILGA_Page_Payments's own build_lines()/
    // group_by_firm()/aging_buckets(), the exact same source the on-screen
    // tables render from, so an export always matches what's on screen.
    // -------------------------------------------------------------------------

    const PAYMENTS_VIEW_INVOICE = 'invoice';
    const PAYMENTS_VIEW_FIRM    = 'firm';
    const PAYMENTS_VIEW_AGING   = 'aging';

    /**
     * Entry point for the Payments page's CSV export — dispatches on
     * ?view= the same way stream() dispatches on ?type=.
     */
    public static function stream_payments( string $view ): void {
        switch ( $view ) {
            case self::PAYMENTS_VIEW_INVOICE: self::stream_payments_by_invoice(); break;
            case self::PAYMENTS_VIEW_FIRM:    self::stream_payments_by_firm();    break;
            case self::PAYMENTS_VIEW_AGING:   self::stream_payments_aging();      break;
            default:
                wp_die( 'Unknown payments view.' );
        }
    }

    private static function stream_payments_by_invoice(): void {
        $lines = MyNJILGA_Page_Payments::build_lines();
        $fh    = self::open( 'payments-by-invoice' );

        fputcsv( $fh, [ 'Firm', 'Dues Year', 'Invoice #', 'Bill To', 'Status', 'Method', 'Amount', 'Paid', 'Balance', 'Paid On', 'Due Date' ] );
        foreach ( $lines as $l ) {
            $invoiceNo = $l['invoiceNo'] !== '' ? $l['invoiceNo'] : ( $l['invoiceId'] !== '' ? '…' . substr( $l['invoiceId'], -8 ) : '' );
            fputcsv( $fh, [
                $l['firm'],
                $l['year'],
                $invoiceNo,
                $l['billTo'],
                MyNJILGA_Page_Payments::status_pill_parts( $l['status'] )[0],
                MyNJILGA_Page_Payments::method_label( $l['method'] ),
                number_format( $l['total'] / 100, 2, '.', '' ),
                number_format( $l['paid'] / 100, 2, '.', '' ),
                number_format( $l['due'] / 100, 2, '.', '' ),
                $l['paidDate'],
                $l['dueDate'],
            ] );
        }
        fclose( $fh );
        exit;
    }

    /**
     * Long-format, one row per (firm, dues year) — same convention as
     * stream_companies() above: the firm's aggregate (its total
     * outstanding) is repeated on every one of its rows so the file can
     * be filtered/pivoted in Excel.
     */
    private static function stream_payments_by_firm(): void {
        $lines = MyNJILGA_Page_Payments::build_lines();
        $firms = MyNJILGA_Page_Payments::group_by_firm( $lines );
        $fh    = self::open( 'payments-by-firm' );

        $verdictLabels = [ 'paid' => 'Paid', 'partial' => 'Partial', 'unpaid' => 'Unpaid', 'written_off' => 'Written Off' ];

        fputcsv( $fh, [ 'Firm', 'Dues Year', 'Year Status', 'Firm Total Outstanding' ] );
        foreach ( $firms as $f ) {
            $outstanding = number_format( $f['outstandingCents'] / 100, 2, '.', '' );
            if ( empty( $f['years'] ) ) {
                fputcsv( $fh, [ $f['name'], '', '', $outstanding ] );
                continue;
            }
            foreach ( $f['years'] as $year => $yr ) {
                fputcsv( $fh, [ $f['name'], $year, $verdictLabels[ $yr['verdict'] ] ?? ucfirst( $yr['verdict'] ), $outstanding ] );
            }
        }
        fclose( $fh );
        exit;
    }

    /**
     * Long-format, one row per outstanding invoice, Bucket label repeated
     * per row (same filter/pivot-friendly convention as stream_companies()
     * and stream_payments_by_firm() above) rather than subtotal rows —
     * Excel can pivot/subtotal this on the Bucket column itself.
     */
    private static function stream_payments_aging(): void {
        $lines = MyNJILGA_Page_Payments::build_lines();
        $aging = MyNJILGA_Page_Payments::aging_buckets( $lines );
        $fh    = self::open( 'payments-aging' );

        fputcsv( $fh, [ 'Bucket', 'Firm', 'Invoice #', 'Status', 'Balance', 'Due Date' ] );
        foreach ( $aging['buckets'] as $bucket ) {
            foreach ( $bucket['lines'] as $l ) {
                $invoiceNo = $l['invoiceNo'] !== '' ? $l['invoiceNo'] : ( $l['invoiceId'] !== '' ? '…' . substr( $l['invoiceId'], -8 ) : '' );
                fputcsv( $fh, [
                    $bucket['label'],
                    $l['firm'],
                    $invoiceNo,
                    MyNJILGA_Page_Payments::status_pill_parts( $l['status'] )[0],
                    number_format( $l['due'] / 100, 2, '.', '' ),
                    $l['dueDate'],
                ] );
            }
        }
        fclose( $fh );
        exit;
    }

    /**
     * Sends the headers + opens php://output. Excel-friendly UTF-8 BOM
     * so accented firm names render correctly when opened directly.
     */
    private static function open( string $slug ) {
        $filename = sprintf( 'MyNJILGA_%s_%s.csv', $slug, date( 'Y-m-d' ) );

        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

        $fh = fopen( 'php://output', 'w' );
        fwrite( $fh, "\xEF\xBB\xBF" );
        return $fh;
    }
}
