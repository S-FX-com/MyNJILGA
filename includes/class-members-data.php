<?php
/**
 * Builds the data sets backing every My NJILGA report page and export.
 *
 * Nothing here decides who is a member. MyNJILGA_Membership_Stats owns that
 * (one rule for the Dashboard, every tile, list, CSV and Excel file, and the
 * member-facing My Membership page): a contact is an ACTIVE member when they
 * are paid through this dues year or later — read off the "Dues Paid {year}"
 * tags, with the evergreen paid tag standing in for a contact who has no year
 * tag at all — whatever their FluentCRM email-subscription status. This class
 * reshapes the provider's classified contacts and firms into the rows the
 * pages render; it queries FluentCRM for none of it.
 *
 * Firm membership is the Company roster ($company->subscribers). A contact on
 * two rosters is listed under both firms and counted once in the member totals.
 * The company_name custom field survives only as a DISPLAY fallback for the
 * firm column of a contact who is on no roster.
 *
 * The row builders (active_member_rows, trustee_rows, bucket_companies,
 * firm_rows) and the label helpers are pure — WordPress-dependent pieces (the
 * contact admin link, the custom-field fallback) are handed in as callables —
 * and are covered by tests/MembershipStatsTest.php.
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
     * Active members — everyone whose standing is 'active', whatever their
     * FluentCRM status. Sorted by firm, then name.
     *
     * @return array<int,array{member:string,member_url:string,first_name:string,last_name:string,email:string,firm:string,is_trustee:bool,trustee_status:string,payment_method:string,subscriber_id:int}>
     */
    public static function get_active_members(): array {
        return self::active_member_rows( MyNJILGA_Membership_Stats::data(), self::url_builder(), self::firm_fallback() );
    }

    /**
     * The whole trustee family — Trustees, Senior Trustees and Past
     * Presidents — with their standing. `trustee_status` is the role with
     * Past President / Senior Trustee taking precedence over plain Trustee.
     *
     * `is_paid` is standing 'active'; `is_unpaid` is 'expired' and not
     * exempt; `is_exempt` is a Past President or Senior Trustee tag whatever
     * the dues state. The three never overlap in the tiles: an exempt row is
     * shown as Exempt, not as Paid or Unpaid (see dues_pill()). `state` is
     * the raw standing for the rows that are none of the three.
     *
     * @return array<int,array{member:string,member_url:string,first_name:string,last_name:string,email:string,firm:string,is_paid:bool,is_unpaid:bool,is_exempt:bool,state:string,trustee_status:string,payment_method:string,subscriber_id:int}>
     */
    public static function get_trustees(): array {
        return self::trustee_rows( MyNJILGA_Membership_Stats::data(), self::url_builder(), self::firm_fallback() );
    }

    /**
     * Companies grouped into active-member-count buckets.
     *
     *   '1'    → exactly 1 active member
     *   '2-5'  → 2 to 5 active members
     *   '6+'   → 6 or more active members
     *   '0'    → companies with contacts but no active member
     *
     * Companies with no contacts at all are in no bucket; their number is
     * `empty_companies`. A member row's `is_paid` is standing 'active'
     * (`state` carries the full standing).
     *
     * @return array{
     *   buckets: array<string, array<int,array{name:string,paid_count:int,total_count:int,members:array<int,array{name:string,url:string,is_paid:bool,state:string}>}>>,
     *   bucket_labels: array<string,string>,
     *   empty_companies: int
     * }
     */
    public static function get_companies_bucketed(): array {
        if ( ! self::companies_module_active() ) {
            return self::bucket_companies( [], self::url_builder() );
        }
        return self::bucket_companies( MyNJILGA_Membership_Stats::data(), self::url_builder() );
    }

    /**
     * Membership by Firm: every FluentCRM Company that has at least one
     * attached contact, sorted alphabetically by company name, each with
     * its contacts listed (sorted by last name, then first name).
     *
     * Two scopes:
     *   - 'all'    every company with >=1 contact, all contacts shown.
     *   - 'active' only companies with >=1 active member, and only those
     *              active members are listed.
     *
     * Per-contact fields mirror the report columns:
     *   - dues          "Dues Paid" (active) | "Unpaid Dues" (expired) | ""
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
        return self::firm_rows( MyNJILGA_Membership_Stats::data(), $scope );
    }

    /**
     * Cross-report KPI dashboard shown atop every report page, read from the
     * cached MyNJILGA_Membership_Stats::snapshot():
     *   - paid_members       active members (paid through this year or later)
     *   - unpaid_members     expired members (lapsed, or flagged unpaid)
     *   - firms_with_paid    firms with >=1 active member
     *   - firms_without_paid firms with >=1 contact but no active member
     *   - paid_trustees      active trustees who are not dues-exempt
     *   - unpaid_trustees    expired trustees who are not dues-exempt
     *   - exempt             Past Presidents and Senior Trustees, whatever their dues state
     *
     * The three trustee figures plus the trustee "other" count partition the
     * trustee family, so they always add up to the Trustees list. All zeros
     * when FluentCRM is unavailable.
     *
     * @return array{paid_members:int,unpaid_members:int,firms_with_paid:int,firms_without_paid:int,paid_trustees:int,unpaid_trustees:int,exempt:int}
     */
    public static function report_stats(): array {
        return self::stats_from_snapshot( MyNJILGA_Membership_Stats::snapshot() );
    }

    // -------------------------------------------------------------------------
    // Pure shaping — no WordPress, no FluentCRM
    // -------------------------------------------------------------------------

    /**
     * report_stats() from a snapshot() array (zeros when it is unavailable).
     *
     * @param array<string,mixed> $snapshot
     * @return array{paid_members:int,unpaid_members:int,firms_with_paid:int,firms_without_paid:int,paid_trustees:int,unpaid_trustees:int,exempt:int}
     */
    public static function stats_from_snapshot( array $snapshot ): array {
        if ( empty( $snapshot['available'] ) ) {
            return [
                'paid_members'       => 0,
                'unpaid_members'     => 0,
                'firms_with_paid'    => 0,
                'firms_without_paid' => 0,
                'paid_trustees'      => 0,
                'unpaid_trustees'    => 0,
                'exempt'             => 0,
            ];
        }
        return [
            'paid_members'       => (int) $snapshot['members']['active'],
            'unpaid_members'     => (int) $snapshot['members']['expired'],
            'firms_with_paid'    => (int) $snapshot['firms']['with_active'],
            'firms_without_paid' => (int) $snapshot['firms']['without_active'],
            'paid_trustees'      => (int) $snapshot['trustees']['active'],
            'unpaid_trustees'    => (int) $snapshot['trustees']['expired'],
            'exempt'             => (int) $snapshot['trustees']['exempt'],
        ];
    }

    /**
     * The Active Paid Members rows from MyNJILGA_Membership_Stats::data().
     *
     * @param array<string,mixed>         $data
     * @param callable(int):string        $urlFor       contact id → admin link
     * @param callable(array):string      $firmFallback contact → firm text for a contact on no roster
     * @return array<int,array<string,mixed>>
     */
    public static function active_member_rows( array $data, callable $urlFor, callable $firmFallback ): array {
        $rows = [];
        foreach ( (array) ( $data['contacts'] ?? [] ) as $c ) {
            if ( $c['state'] !== MyNJILGA_My_Membership::STATE_ACTIVE ) {
                continue;
            }
            $rows[] = [
                'subscriber_id'  => (int) $c['id'],
                'member'         => (string) $c['name'],
                'member_url'     => $urlFor( (int) $c['id'] ),
                'first_name'     => (string) $c['first_name'],
                'last_name'      => (string) $c['last_name'],
                'email'          => (string) $c['email'],
                'firm'           => self::firm_label( $c, (array) ( $data['firms'] ?? [] ), $firmFallback ),
                'is_trustee'     => (bool) $c['is_trustee'],
                'trustee_status' => (string) $c['trustee_role'],
                'payment_method' => self::payment_method( $c ),
            ];
        }

        self::sort_rows( $rows );
        return $rows;
    }

    /**
     * The Trustees rows from MyNJILGA_Membership_Stats::data(): the whole
     * trustee family, no status filter.
     *
     * @param array<string,mixed>         $data
     * @param callable(int):string        $urlFor
     * @param callable(array):string      $firmFallback
     * @return array<int,array<string,mixed>>
     */
    public static function trustee_rows( array $data, callable $urlFor, callable $firmFallback ): array {
        $rows = [];
        foreach ( (array) ( $data['contacts'] ?? [] ) as $c ) {
            if ( ! $c['is_trustee'] ) {
                continue;
            }
            $rows[] = [
                'subscriber_id'  => (int) $c['id'],
                'member'         => (string) $c['name'],
                'member_url'     => $urlFor( (int) $c['id'] ),
                'first_name'     => (string) $c['first_name'],
                'last_name'      => (string) $c['last_name'],
                'email'          => (string) $c['email'],
                'firm'           => self::firm_label( $c, (array) ( $data['firms'] ?? [] ), $firmFallback ),
                'is_paid'        => $c['state'] === MyNJILGA_My_Membership::STATE_ACTIVE,
                'is_unpaid'      => $c['state'] === MyNJILGA_My_Membership::STATE_EXPIRED && ! $c['exempt'],
                'is_exempt'      => (bool) $c['exempt'],
                'state'          => (string) $c['state'],
                'trustee_status' => (string) $c['trustee_role'],
                'payment_method' => self::payment_method( $c ),
            ];
        }

        self::sort_rows( $rows );
        return $rows;
    }

    /**
     * The Companies report from MyNJILGA_Membership_Stats::data(): firms
     * (alphabetical) bucketed by their number of active members. A firm with
     * no contacts is counted in `empty_companies` and listed nowhere — it
     * can neither have nor lack an active member.
     *
     * @param array<string,mixed>  $data
     * @param callable(int):string $urlFor
     * @return array{buckets:array<string,array<int,array<string,mixed>>>,bucket_labels:array<string,string>,empty_companies:int}
     */
    public static function bucket_companies( array $data, callable $urlFor ): array {
        $bucket_labels = [
            '1'   => '1 Paid Member',
            '2-5' => '2–5 Paid Members',
            '6+'  => '6+ Paid Members',
            '0'   => 'No Paid Members',
        ];
        $buckets = [ '1' => [], '2-5' => [], '6+' => [], '0' => [] ];
        $empty   = 0;

        $contacts = (array) ( $data['contacts'] ?? [] );
        foreach ( (array) ( $data['firms'] ?? [] ) as $firm ) {
            if ( ! $firm['contact_ids'] ) {
                $empty++;
                continue;
            }
            $members = [];
            $active  = 0;
            foreach ( $firm['contact_ids'] as $cid ) {
                $c = $contacts[ $cid ] ?? null;
                if ( ! $c ) {
                    continue;
                }
                $is_paid   = $c['state'] === MyNJILGA_My_Membership::STATE_ACTIVE;
                $active   += $is_paid ? 1 : 0;
                $members[] = [
                    'name'    => (string) $c['name'],
                    'url'     => $urlFor( (int) $cid ),
                    'is_paid' => $is_paid,
                    'state'   => (string) $c['state'],
                ];
            }

            $buckets[ MyNJILGA_Membership_Stats::size_bucket( $active ) ][] = [
                'name'        => (string) $firm['name'],
                'paid_count'  => $active,
                'total_count' => count( $members ),
                'members'     => $members,
            ];
        }

        return [ 'buckets' => $buckets, 'bucket_labels' => $bucket_labels, 'empty_companies' => $empty ];
    }

    /**
     * Membership by Firm rows from MyNJILGA_Membership_Stats::data().
     *
     * @param array<string,mixed> $data
     * @param string              $scope 'all' or 'active'
     * @return array<int,array{name:string,contacts:array<int,array<string,string>>}>
     */
    public static function firm_rows( array $data, string $scope ): array {
        $active_only = ( $scope === 'active' );
        $contacts    = (array) ( $data['contacts'] ?? [] );

        $firms = [];
        foreach ( (array) ( $data['firms'] ?? [] ) as $firm ) {
            $rows = [];
            foreach ( $firm['contact_ids'] as $cid ) {
                $c = $contacts[ $cid ] ?? null;
                if ( ! $c || ( $active_only && $c['state'] !== MyNJILGA_My_Membership::STATE_ACTIVE ) ) {
                    continue; // Active scope: skip anyone who is not an active member.
                }
                $rows[] = [
                    'first_name'     => (string) $c['first_name'],
                    'last_name'      => (string) $c['last_name'],
                    'email'          => (string) $c['email'],
                    'dues'           => self::dues_label( (string) $c['state'] ),
                    'trustees'       => in_array( MyNJILGA_Tags::SLUG_TRUSTEES, $c['role_tags'], true ) ? 'Trustees' : '',
                    'past_president' => in_array( MyNJILGA_Tags::SLUG_PAST_PRESIDENT, $c['role_tags'], true ) ? 'Past President' : '',
                    'payment'        => self::dues_payment_method( $c ),
                ];
            }

            if ( empty( $rows ) ) {
                continue; // No qualifying contacts for this scope — omit the firm.
            }

            usort( $rows, static function ( $a, $b ) {
                $cmp = strcasecmp( $a['last_name'], $b['last_name'] );
                return $cmp !== 0 ? $cmp : strcasecmp( $a['first_name'], $b['first_name'] );
            } );

            $firms[] = [
                'name'     => (string) $firm['name'],
                'contacts' => $rows,
            ];
        }

        return $firms;
    }

    /**
     * The Dues column of Membership by Firm for a standing: "Dues Paid" for
     * an active member, "Unpaid Dues" for an expired one, blank for the rest
     * (exempt, inactive, none). MyNJILGA_Tags::dues_color()/dues_variant()
     * read exactly these two strings.
     */
    public static function dues_label( string $state ): string {
        if ( $state === MyNJILGA_My_Membership::STATE_ACTIVE ) {
            return 'Dues Paid';
        }
        if ( $state === MyNJILGA_My_Membership::STATE_EXPIRED ) {
            return 'Unpaid Dues';
        }
        return '';
    }

    /**
     * The Payment column of Membership by Firm — MyNJILGA_Tags::
     * dues_payment_method() over the contact's facts: the paid-by-invoice tag
     * first, then paid-by-check, then "Paid by Website" for an active member
     * with neither, else blank. (Active, not "carries the evergreen tag":
     * a member paid through a year tag alone is a website payment too.)
     *
     * @param array<string,mixed> $contact
     */
    public static function dues_payment_method( array $contact ): string {
        $roles = (array) $contact['role_tags'];
        if ( in_array( MyNJILGA_Tags::SLUG_PAID_BY_INVOICE, $roles, true ) ) {
            return 'Paid by Invoice';
        }
        if ( in_array( MyNJILGA_Tags::SLUG_PAID_BY_CHECK, $roles, true ) ) {
            return 'Paid by Check';
        }
        return $contact['state'] === MyNJILGA_My_Membership::STATE_ACTIVE ? 'Paid by Website' : '';
    }

    /**
     * The Payment Method column of the Members and Trustees lists —
     * MyNJILGA_Tags::payment_method() over the contact's facts: "Check",
     * "Invoice", or "Credit Card" (the default). Note the tag order differs
     * from dues_payment_method() (check first here, invoice first there);
     * both are kept exactly as the tag helpers had them.
     *
     * @param array<string,mixed> $contact
     */
    public static function payment_method( array $contact ): string {
        $roles = (array) $contact['role_tags'];
        if ( in_array( MyNJILGA_Tags::SLUG_PAID_BY_CHECK, $roles, true ) ) {
            return 'Check';
        }
        if ( in_array( MyNJILGA_Tags::SLUG_PAID_BY_INVOICE, $roles, true ) ) {
            return 'Invoice';
        }
        return 'Credit Card';
    }

    /**
     * The pill for a dues standing on a list row: [ label, Admin UI variant ].
     * $exempt puts the Exempt pill on a Past President or Senior Trustee
     * whatever their dues state, so the Trustees list and the Exempt tile
     * agree row for row.
     *
     * @return array{0:string,1:string}
     */
    public static function dues_pill( string $state, bool $exempt = false ): array {
        if ( $exempt ) {
            return [ 'Exempt', 'info' ];
        }
        switch ( $state ) {
            case MyNJILGA_My_Membership::STATE_ACTIVE:
                return [ 'Paid', 'success' ];
            case MyNJILGA_My_Membership::STATE_EXPIRED:
                return [ 'Unpaid', 'destructive' ];
            case MyNJILGA_My_Membership::STATE_EXEMPT:
                return [ 'Exempt', 'info' ];
            case MyNJILGA_My_Membership::STATE_INACTIVE:
                return [ 'Inactive', 'muted' ];
            default:
                return [ 'None', 'muted' ];
        }
    }

    /**
     * The Firm column: the rosters the contact is on (primary company first,
     * "; " between two), or — only for a contact on no roster — whatever the
     * fallback finds (the company_name custom field).
     *
     * @param array<string,mixed>                   $contact
     * @param array<int|string,array<string,mixed>> $firms       firm id => firm
     * @param callable(array):string                $fallback
     */
    public static function firm_label( array $contact, array $firms, callable $fallback ): string {
        if ( empty( $contact['firm_ids'] ) ) {
            return (string) $fallback( $contact );
        }
        $names = [];
        foreach ( $contact['firm_ids'] as $fid ) {
            $name = (string) ( $firms[ $fid ]['name'] ?? '' );
            if ( $name !== '' && ! in_array( $name, $names, true ) ) {
                $names[] = $name;
            }
        }
        return implode( '; ', $names );
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
    public static function display_name( $sub ): string {
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
     * Contact id → the link to the FluentCRM contact admin screen.
     */
    private static function url_builder(): callable {
        return static function ( int $id ): string {
            return admin_url( 'admin.php?page=fluentcrm-admin#/subscribers/' . $id );
        };
    }

    /**
     * Firm text for a contact who is on no firm roster: the `company_name`
     * custom field, else blank. One custom-field read per such contact —
     * the only per-contact lookup left, and only for list rows.
     */
    private static function firm_fallback(): callable {
        return static function ( array $contact ): string {
            $sub = $contact['model'] ?? null;
            $cf  = (array) ( $sub && method_exists( $sub, 'custom_fields' ) ? $sub->custom_fields() : [] );
            return (string) ( $cf['company_name'] ?? '' );
        };
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
