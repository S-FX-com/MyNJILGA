<?php
/**
 * Builds the data sets backing every My NJILGA page and the Excel export.
 *
 * Reads FluentCRM directly via its PHP API/models (no REST calls). Status
 * is purely tag-driven: a member is "paid" iff they carry the `dues-paid`
 * tag (see MyNJILGA_Tags). Payment method is derived from `paid-by-check`
 * / `paid-by-invoice` tags, defaulting to "Credit Card".
 */
class MyNJILGA_Members_Data {

    /**
     * Sentinel returned when FluentCRM isn't active. Pages render a single
     * notice instead of a fatal.
     */
    public static function fluentcrm_active(): bool {
        return class_exists( '\\FluentCrm\\App\\Models\\Subscriber' );
    }

    public static function companies_module_active(): bool {
        return class_exists( '\\FluentCrm\\App\\Models\\Company' );
    }

    // -------------------------------------------------------------------------
    // Public datasets
    // -------------------------------------------------------------------------

    /**
     * @return array<int,array{member:string,member_url:string,firm:string,is_trustee:bool,payment_method:string,subscriber_id:int}>
     */
    public static function get_active_members(): array {
        $dues_paid_id = MyNJILGA_Tags::id_for( MyNJILGA_Tags::SLUG_DUES_PAID );
        if ( ! $dues_paid_id ) {
            return [];
        }

        $subs = \FluentCrm\App\Models\Subscriber::filterByTags( [ $dues_paid_id ] )
            ->where( 'status', 'subscribed' )
            ->get();

        $rows = [];
        foreach ( $subs as $sub ) {
            $rows[] = [
                'subscriber_id'  => (int) $sub->id,
                'member'         => self::display_name( $sub ),
                'member_url'     => self::contact_admin_url( $sub ),
                'firm'           => self::firm_for( $sub ),
                'is_trustee'     => MyNJILGA_Tags::is_trustee( $sub ),
                'payment_method' => MyNJILGA_Tags::payment_method( $sub ),
            ];
        }

        self::sort_rows( $rows );
        return $rows;
    }

    /**
     * @return array<int,array{member:string,member_url:string,firm:string,is_paid:bool,payment_method:string,subscriber_id:int}>
     */
    public static function get_trustees(): array {
        $trustees_id = MyNJILGA_Tags::id_for( MyNJILGA_Tags::SLUG_TRUSTEES );
        if ( ! $trustees_id ) {
            return [];
        }

        $subs = \FluentCrm\App\Models\Subscriber::filterByTags( [ $trustees_id ] )
            ->where( 'status', 'subscribed' )
            ->get();

        $rows = [];
        foreach ( $subs as $sub ) {
            $rows[] = [
                'subscriber_id'  => (int) $sub->id,
                'member'         => self::display_name( $sub ),
                'member_url'     => self::contact_admin_url( $sub ),
                'firm'           => self::firm_for( $sub ),
                'is_paid'        => MyNJILGA_Tags::is_paid( $sub ),
                'payment_method' => MyNJILGA_Tags::payment_method( $sub ),
            ];
        }

        self::sort_rows( $rows );
        return $rows;
    }

    /**
     * Companies grouped into paid-member-count buckets.
     *
     *   '1'    → exactly 1 paid member
     *   '2-5'  → 2 to 5 paid members
     *   '6+'   → 6 or more paid members
     *   '0'    → companies with FluentCRM contacts but zero paid members
     *
     * @return array{
     *   buckets: array<string, array<int,array{name:string,paid_count:int,total_count:int,members:array<int,array{name:string,is_paid:bool}>}>>,
     *   bucket_labels: array<string,string>
     * }
     */
    public static function get_companies_bucketed(): array {
        $bucket_labels = [
            '1'   => '1 Paid Member',
            '2-5' => '2–5 Paid Members',
            '6+'  => '6+ Paid Members',
            '0'   => 'No Paid Members',
        ];
        $buckets = [ '1' => [], '2-5' => [], '6+' => [], '0' => [] ];

        if ( ! self::companies_module_active() ) {
            return [ 'buckets' => $buckets, 'bucket_labels' => $bucket_labels ];
        }

        $companies = \FluentCrm\App\Models\Company::orderBy( 'name', 'asc' )->get();

        foreach ( $companies as $company ) {
            $members = [];
            $paid    = 0;
            foreach ( $company->subscribers as $sub ) {
                $is_paid   = MyNJILGA_Tags::is_paid( $sub );
                $paid     += $is_paid ? 1 : 0;
                $members[] = [
                    'name'    => self::display_name( $sub ),
                    'url'     => self::contact_admin_url( $sub ),
                    'is_paid' => $is_paid,
                ];
            }

            $bucket = self::bucket_for( $paid );

            $buckets[ $bucket ][] = [
                'name'        => (string) ( $company->name ?? '' ),
                'paid_count'  => $paid,
                'total_count' => count( $members ),
                'members'     => $members,
            ];
        }

        return [ 'buckets' => $buckets, 'bucket_labels' => $bucket_labels ];
    }

    /**
     * Dashboard counts.
     *
     * @return array{paid:int,trustees:int,companies_with_paid:int,bucket_counts:array<string,int>}
     */
    public static function summary(): array {
        $members  = self::get_active_members();
        $trustees = self::get_trustees();
        $bucketed = self::get_companies_bucketed();

        $companies_with_paid = 0;
        $bucket_counts       = [];
        foreach ( $bucketed['buckets'] as $key => $rows ) {
            $bucket_counts[ $key ] = count( $rows );
            if ( $key !== '0' ) {
                $companies_with_paid += count( $rows );
            }
        }

        return [
            'paid'                => count( $members ),
            'trustees'            => count( $trustees ),
            'companies_with_paid' => $companies_with_paid,
            'bucket_counts'       => $bucket_counts,
        ];
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @param \FluentCrm\App\Models\Subscriber $sub
     */
    private static function full_name( $sub ): string {
        return trim( ( $sub->first_name ?? '' ) . ' ' . ( $sub->last_name ?? '' ) );
    }

    /**
     * Always returns something printable: full name → email → "(contact #ID)".
     * Keeps every row identifiable instead of leaving a blank Member cell.
     *
     * @param \FluentCrm\App\Models\Subscriber $sub
     */
    private static function display_name( $sub ): string {
        $name = self::full_name( $sub );
        if ( $name !== '' ) {
            return $name;
        }
        if ( ! empty( $sub->email ) ) {
            return (string) $sub->email;
        }
        return '(contact #' . (int) $sub->id . ')';
    }

    /**
     * Link to the FluentCRM contact admin screen.
     *
     * @param \FluentCrm\App\Models\Subscriber $sub
     */
    private static function contact_admin_url( $sub ): string {
        return admin_url( 'admin.php?page=fluentcrm-admin#/subscribers/' . (int) $sub->id );
    }

    /**
     * Firm name: prefer FluentCRM Company entity, fall back to the
     * `company_name` custom field text, finally blank.
     *
     * @param \FluentCrm\App\Models\Subscriber $sub
     */
    private static function firm_for( $sub ): string {
        if ( ! empty( $sub->company_id ) && self::companies_module_active() ) {
            $company = $sub->company;
            if ( $company && ! empty( $company->name ) ) {
                return (string) $company->name;
            }
        }

        $cf = (array) ( method_exists( $sub, 'custom_fields' ) ? $sub->custom_fields() : [] );
        return (string) ( $cf['company_name'] ?? '' );
    }

    private static function bucket_for( int $paid ): string {
        if ( $paid <= 0 )  return '0';
        if ( $paid === 1 ) return '1';
        if ( $paid <= 5 )  return '2-5';
        return '6+';
    }

    /**
     * In-place sort by firm, then by member name.
     */
    private static function sort_rows( array &$rows ): void {
        usort( $rows, static function ( $a, $b ) {
            $cmp = strcasecmp( $a['firm'], $b['firm'] );
            return $cmp !== 0 ? $cmp : strcasecmp( $a['member'], $b['member'] );
        } );
    }
}
