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
