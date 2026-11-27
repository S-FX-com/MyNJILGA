<?php
/**
 * A preview of what the Executive Summary export will hold, before it is
 * downloaded: each sheet's title, how many rows it has, its columns and its
 * first few rows. Built from the same row sets the export streams
 * (MyNJILGA_Members_Data), so the preview and the file can't disagree.
 *
 * Pure — no WordPress, no FluentCRM. The Reports page draws it.
 */
class MyNJILGA_Report_Preview {

    /** Rows shown per sheet. */
    const SAMPLE = 5;

    /**
     * @param array<int,array<string,mixed>>                 $members  MyNJILGA_Members_Data::get_active_members()
     * @param array<int,array<string,mixed>>                 $trustees MyNJILGA_Members_Data::get_trustees()
     * @param array<string,mixed>                            $bucketed MyNJILGA_Members_Data::get_companies_bucketed()
     * @param array<int,array<string,mixed>>                 $firms    MyNJILGA_Members_Data::get_membership_by_firm( 'all' )
     * @return array<int,array{key:string,title:string,count:int,unit:string,columns:array<int,string>,rows:array<int,array<int,string>>}>
     */
    public static function sheets( array $members, array $trustees, array $bucketed, array $firms, int $sample = self::SAMPLE ): array {
        $memberRows = [];
        foreach ( $members as $r ) {
            $memberRows[] = [ (string) $r['member'], (string) $r['email'], (string) $r['firm'], (string) $r['trustee_status'], (string) $r['payment_method'] ];
        }

        $trusteeRows = [];
        foreach ( $trustees as $r ) {
            $trusteeRows[] = [
                (string) $r['member'],
                (string) $r['trustee_status'],
                (string) $r['firm'],
                MyNJILGA_Members_Data::dues_pill( (string) $r['state'], ! empty( $r['is_exempt'] ) )[0],
                (string) $r['payment_method'],
            ];
        }

        $companyRows = [];
        foreach ( [ '1', '2-5', '6+', '0' ] as $key ) {
            $label = (string) ( $bucketed['bucket_labels'][ $key ] ?? $key );
            foreach ( (array) ( $bucketed['buckets'][ $key ] ?? [] ) as $c ) {
                $companyRows[] = [ $label, (string) $c['name'], $c['paid_count'] . ' paid / ' . $c['total_count'] . ' total' ];
            }
        }

        $firmRows = [];
        foreach ( $firms as $firm ) {
            foreach ( (array) $firm['contacts'] as $c ) {
                $firmRows[] = [ (string) $firm['name'], trim( $c['first_name'] . ' ' . $c['last_name'] ), (string) $c['email'], (string) $c['dues'], (string) $c['payment'] ];
            }
        }

        $defs = [
            [ 'members',   'Active Paid Members', 'member',  [ 'Member', 'Email', 'Firm', 'Trustee', 'Payment Method' ], $memberRows ],
            [ 'trustees',  'Trustees',            'trustee', [ 'Name', 'Role', 'Firm', 'Dues', 'Payment Method' ], $trusteeRows ],
            [ 'companies', 'Companies',           'company', [ 'Group', 'Company', 'Members' ], $companyRows ],
            [ 'firms',     'Membership by Firm',  'contact', [ 'Firm', 'Contact', 'Email', 'Dues', 'Payment' ], $firmRows ],
        ];

        $out = [];
        foreach ( $defs as [ $key, $title, $unit, $columns, $rows ] ) {
            $out[] = [
                'key'     => $key,
                'title'   => $title,
                'count'   => count( $rows ),
                'unit'    => $unit,
                'columns' => $columns,
                'rows'    => array_slice( $rows, 0, max( 0, $sample ) ),
            ];
        }
        return $out;
    }

    /** "1 member" / "12 members" / "0 companies". */
    public static function count_label( int $count, string $unit ): string {
        $plural = $unit === 'company' ? 'companies' : $unit . 's';
        return $count . ' ' . ( $count === 1 ? $unit : $plural );
    }
}
