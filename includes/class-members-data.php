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
     * @return array<int,array{member:string,member_url:string,first_name:string,last_name:string,email:string,firm:string,is_trustee:bool,trustee_status:string,payment_method:string,subscriber_id:int}>
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
                'first_name'     => (string) ( $sub->first_name ?? '' ),
                'last_name'      => (string) ( $sub->last_name ?? '' ),
                'email'          => (string) ( $sub->email ?? '' ),
                'firm'           => self::firm_for( $sub ),
                'is_trustee'     => MyNJILGA_Tags::is_trustee( $sub ),
                'trustee_status' => MyNJILGA_Tags::trustee_status( $sub ),
                'payment_method' => MyNJILGA_Tags::payment_method( $sub ),
            ];
        }

        self::sort_rows( $rows );
        return $rows;
    }

    /**
     * Trustees, Senior Trustees, and Past Presidents — anyone carrying any
     * of the trustee-family tags. The `trustee_status` field denotes which
     * role they hold (with Past President / Senior Trustee taking
     * precedence over plain Trustee).
     *
     * @return array<int,array{member:string,member_url:string,first_name:string,last_name:string,email:string,firm:string,is_paid:bool,is_unpaid:bool,trustee_status:string,payment_method:string,subscriber_id:int}>
     */
    public static function get_trustees(): array {
        $trustee_ids = array_values( array_filter( array_map(
            static fn( $slug ) => MyNJILGA_Tags::id_for( $slug ),
            MyNJILGA_Tags::TRUSTEE_SLUGS
        ) ) );
        if ( ! $trustee_ids ) {
            return [];
        }

        $subs = \FluentCrm\App\Models\Subscriber::filterByTags( $trustee_ids )
            ->where( 'status', 'subscribed' )
            ->get();

        $rows = [];
        foreach ( $subs as $sub ) {
            $rows[] = [
                'subscriber_id'  => (int) $sub->id,
                'member'         => self::display_name( $sub ),
                'member_url'     => self::contact_admin_url( $sub ),
                'first_name'     => (string) ( $sub->first_name ?? '' ),
                'last_name'      => (string) ( $sub->last_name ?? '' ),
                'email'          => (string) ( $sub->email ?? '' ),
                'firm'           => self::firm_for( $sub ),
                'is_paid'        => MyNJILGA_Tags::is_paid( $sub ),
                'is_unpaid'      => MyNJILGA_Tags::is_unpaid( $sub ),
                'trustee_status' => MyNJILGA_Tags::trustee_status( $sub ),
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
     * Membership by Firm: every FluentCRM Company that has at least one
     * attached contact, sorted alphabetically by company name, each with
     * its contacts listed (sorted by last name, then first name).
     *
     * Two scopes:
     *   - 'all'    every company with >=1 contact, all contacts shown.
     *   - 'active' only companies with >=1 active (Dues Paid) member, and
     *              only those active members are listed.
     *
     * Per-contact fields mirror the report columns:
     *   - dues          "Dues Paid" | "Unpaid Dues" | ""
     *   - trustees      "Trustees" | ""        (the `trustees` tag specifically)
     *   - past_president "Past President" | "" (the `past-president` tag)
     *   - payment       "Paid by Invoice" | "Paid by Check" | "Paid by Website" | ""
     *
     * Companies with zero qualifying contacts (for the chosen scope) are omitted.
     *
     * @param string $scope 'all' (default) or 'active'.
     * @return array<int,array{name:string,contacts:array<int,array{first_name:string,last_name:string,email:string,dues:string,trustees:string,past_president:string,payment:string}>}>
     */
    public static function get_membership_by_firm( string $scope = 'all' ): array {
        if ( ! self::companies_module_active() ) {
            return [];
        }

        $active_only = ( $scope === 'active' );

        $companies = \FluentCrm\App\Models\Company::orderBy( 'name', 'asc' )->get();

        $firms = [];
        foreach ( $companies as $company ) {
            $contacts = [];
            foreach ( $company->subscribers as $sub ) {
                if ( $active_only && ! MyNJILGA_Tags::is_paid( $sub ) ) {
                    continue; // Active scope: skip members without the Dues Paid tag.
                }
                $contacts[] = [
                    'first_name'     => (string) ( $sub->first_name ?? '' ),
                    'last_name'      => (string) ( $sub->last_name ?? '' ),
                    'email'          => (string) ( $sub->email ?? '' ),
                    'dues'           => MyNJILGA_Tags::dues_label( $sub ),
                    'trustees'       => MyNJILGA_Tags::has( $sub, MyNJILGA_Tags::SLUG_TRUSTEES ) ? 'Trustees' : '',
                    'past_president' => MyNJILGA_Tags::has( $sub, MyNJILGA_Tags::SLUG_PAST_PRESIDENT ) ? 'Past President' : '',
                    'payment'        => MyNJILGA_Tags::dues_payment_method( $sub ),
                ];
            }

            if ( empty( $contacts ) ) {
                continue; // No qualifying contacts for this scope — omit the firm.
            }

            usort( $contacts, static function ( $a, $b ) {
                $cmp = strcasecmp( $a['last_name'], $b['last_name'] );
                return $cmp !== 0 ? $cmp : strcasecmp( $a['first_name'], $b['first_name'] );
            } );

            $firms[] = [
                'name'     => (string) ( $company->name ?? '' ),
                'contacts' => $contacts,
            ];
        }

        return $firms;
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

    /**
     * Cross-report KPI dashboard shown atop every report page.
     *
     * Definitions (tag-driven, across all subscribed FluentCRM contacts):
     *   - paid_members       carry the "Dues Paid" tag
     *   - unpaid_members     carry the "Unpaid Dues" tag (lapsed last cycle)
     *   - firms_with_paid    companies with >=1 Dues-Paid contact
     *   - firms_without_paid companies with >=1 contact but 0 Dues-Paid
     *   - paid_trustees      trustee-family tag AND Dues Paid
     *   - unpaid_trustees    trustee-family tag AND Unpaid Dues (excluding exempt)
     *   - exempt             Past President or Senior Trustee (dues-exempt)
     *
     * Exempt contacts (Past Presidents / Senior Trustees) are never counted as
     * Unpaid — they owe nothing — so they're excluded from both unpaid tallies.
     *
     * Unpaid counts read 0 until the "Unpaid Dues" tag exists and is applied.
     *
     * @return array{paid_members:int,unpaid_members:int,firms_with_paid:int,firms_without_paid:int,paid_trustees:int,unpaid_trustees:int,exempt:int}
     */
    public static function report_stats(): array {
        $paid_members   = self::count_subscribers_with_tag( MyNJILGA_Tags::SLUG_DUES_PAID );
        $unpaid_members = self::count_unpaid_excluding_exempt();

        $paid_trustees   = 0;
        $unpaid_trustees = 0;
        $exempt          = 0;
        foreach ( self::get_trustees() as $t ) {
            $is_exempt        = in_array( $t['trustee_status'], [ 'Past President', 'Senior Trustee' ], true );
            $paid_trustees   += $t['is_paid'] ? 1 : 0;
            $exempt          += $is_exempt ? 1 : 0;
            // Exempt trustees owe nothing, so never tally them as unpaid.
            $unpaid_trustees += ( $t['is_unpaid'] && ! $is_exempt ) ? 1 : 0;
        }

        $firms_with_paid    = 0;
        $firms_without_paid = 0;
        if ( self::companies_module_active() ) {
            foreach ( \FluentCrm\App\Models\Company::get() as $company ) {
                $total = 0;
                $paid  = 0;
                foreach ( $company->subscribers as $sub ) {
                    $total++;
                    $paid += MyNJILGA_Tags::is_paid( $sub ) ? 1 : 0;
                }
                if ( $total === 0 ) {
                    continue; // Skip firms with no attached contacts.
                }
                if ( $paid > 0 ) {
                    $firms_with_paid++;
                } else {
                    $firms_without_paid++;
                }
            }
        }

        return [
            'paid_members'       => $paid_members,
            'unpaid_members'     => $unpaid_members,
            'firms_with_paid'    => $firms_with_paid,
            'firms_without_paid' => $firms_without_paid,
            'paid_trustees'      => $paid_trustees,
            'unpaid_trustees'    => $unpaid_trustees,
            'exempt'             => $exempt,
        ];
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Count subscribed contacts carrying the tag for the given NJILGA slug.
     * Returns 0 when the tag doesn't exist on the install.
     */
    private static function count_subscribers_with_tag( string $slug ): int {
        $id = MyNJILGA_Tags::id_for( $slug );
        if ( ! $id ) {
            return 0;
        }
        return (int) \FluentCrm\App\Models\Subscriber::filterByTags( [ $id ] )
            ->where( 'status', 'subscribed' )
            ->count();
    }

    /**
     * Count subscribed contacts carrying the "Unpaid Dues" tag, minus anyone
     * who is dues-exempt (Past President / Senior Trustee). Exempt contacts
     * owe nothing, so they must never surface in the Unpaid tally even if a
     * stale "Unpaid Dues" tag lingers on their record. Returns 0 when the tag
     * doesn't exist on the install.
     */
    private static function count_unpaid_excluding_exempt(): int {
        $id = MyNJILGA_Tags::id_for( MyNJILGA_Tags::SLUG_UNPAID_DUES );
        if ( ! $id ) {
            return 0;
        }
        $subs = \FluentCrm\App\Models\Subscriber::filterByTags( [ $id ] )
            ->where( 'status', 'subscribed' )
            ->get();

        $count = 0;
        foreach ( $subs as $sub ) {
            if ( ! MyNJILGA_Tags::is_exempt( $sub ) ) {
                $count++;
            }
        }
        return $count;
    }

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
