<?php
/**
 * Builds the Member Dues Report by reading FluentCRM and Paid Memberships Pro
 * directly from the local WordPress install — no REST API, no credentials.
 *
 * FluentCRM access:
 *   - Tags:     FluentCrm\App\Models\Tag (resolves dues-* slugs → IDs)
 *   - Contacts: FluentCrm\App\Models\Subscriber
 *               ->filterByTags([id])->where('status','subscribed')->get()
 *   - Custom fields per contact: $subscriber->custom_fields()
 *     (returns a flat slug → value array — includes `company_name` and any
 *     dues_* fallbacks)
 *
 * PMPro access: see class-pmpro-data.php (queries pmpro_* tables via $wpdb).
 */
class NJILGA_Report_Data {

    /**
     * Tag slug → human-readable section label (order defines report order).
     */
    const TIER_MAP = [
        'dues-1-4-year'                => '1-4 Year Admission',
        'dues-1st-member'              => '1st Member of Firm',
        'dues-2-5-member'              => '2-5 Member of Firm',
        'dues-6-plus-member'           => '6+ Member of Firm',
        'dues-past-president-active'   => 'Past President Active (Trustee Assessment for dinners)',
        'dues-past-president-inactive' => 'Past President Inactive',
        'dues-senior-trustee-active'   => 'Senior Trustee Active (Trustee Assessment for dinners)',
        'dues-senior-trustee-inactive' => 'Senior Trustee Inactive',
        'dues-subscription'            => 'Subscription $125',
        'dues-trustee-1st'             => 'Trustee - 1st Member of Firm (Trustee Assessment for dinners)',
        'dues-trustee-2-5'             => 'Trustee - 2-5 Member of Firm (Trustee Assessment for dinners)',
    ];

    /**
     * FluentCRM custom field slugs read from $subscriber->custom_fields().
     * The dues_* fields are used as a fallback when a contact has no
     * linked WP user (i.e. no PMPro membership record).
     */
    const CF_COMPANY_NAME = 'company_name';
    const CF_STATUS       = 'dues_status';
    const CF_OPEN_BALANCE = 'dues_open_balance';
    const CF_AMOUNT_PAID  = 'dues_amount_paid';

    // -------------------------------------------------------------------------
    // Public interface
    // -------------------------------------------------------------------------

    /**
     * @return array|WP_Error
     */
    public static function get() {
        if ( ! class_exists( '\\FluentCrm\\App\\Models\\Subscriber' ) ) {
            return new WP_Error(
                'fluentcrm_missing',
                'FluentCRM does not appear to be active on this site. Install and activate FluentCRM, then reload this page.'
            );
        }

        $tag_map = self::fetch_tag_id_map();

        $year    = (int) date( 'Y' );
        $tiers   = [];
        $summary = [ 'total' => 0, 'paid' => 0, 'unpaid' => 0, 'partial' => 0, 'zero' => 0 ];

        foreach ( self::TIER_MAP as $slug => $label ) {
            if ( ! isset( $tag_map[ $slug ] ) ) {
                $tiers[ $label ] = [ 'members' => [], 'totals' => self::compute_totals( [] ) ];
                continue;
            }

            $members = self::fetch_members_for_tag( $tag_map[ $slug ], $year );
            $totals  = self::compute_totals( $members );

            $tiers[ $label ] = [ 'members' => $members, 'totals' => $totals ];

            $summary['total']   += count( $members );
            $summary['paid']    += $totals['paid_count'];
            $summary['unpaid']  += $totals['unpaid_count'];
            $summary['partial'] += $totals['partial_count'];
            $summary['zero']    += $totals['zero_count'];
        }

        return [
            'year'             => $year,
            'title'            => 'New Jersey Institute of Local Government Attorneys',
            'tiers'            => $tiers,
            'summary'          => $summary,
            'pmpro_available'  => NJILGA_PMPro_Data::is_available(),
        ];
    }

    // -------------------------------------------------------------------------
    // FluentCRM helpers
    // -------------------------------------------------------------------------

    /**
     * @return array  [ slug => id ] for every FluentCRM tag.
     */
    private static function fetch_tag_id_map() {
        $map = [];
        foreach ( \FluentCrm\App\Models\Tag::all() as $tag ) {
            if ( ! empty( $tag->slug ) ) {
                $map[ (string) $tag->slug ] = (int) $tag->id;
            }
        }
        return $map;
    }

    /**
     * @return array  Member row dicts for a single tier (tag).
     */
    private static function fetch_members_for_tag( int $tag_id, int $year ): array {
        $subscribers = \FluentCrm\App\Models\Subscriber::filterByTags( [ $tag_id ] )
            ->where( 'status', 'subscribed' )
            ->get();

        $members = [];
        foreach ( $subscribers as $subscriber ) {
            $members[] = self::build_member_row( $subscriber, $year );
        }

        // Sort by firm (company_name) for predictable display order.
        usort( $members, static fn( $a, $b ) => strcasecmp( $a['firm'], $b['firm'] ) );

        // Running invoiced total column.
        $running = 0.0;
        foreach ( $members as &$m ) {
            $running           += $m['open_balance'] + $m['amount_paid'];
            $m['invoiced_total'] = $running;
        }
        unset( $m );

        return $members;
    }

    /**
     * Builds one report row from a FluentCRM Subscriber model, layering
     * PMPro data on top of (or substituting for) the FluentCRM custom
     * field values.
     *
     * @param \FluentCrm\App\Models\Subscriber $subscriber
     */
    private static function build_member_row( $subscriber, int $year ): array {
        $cf = (array) $subscriber->custom_fields();

        $firm   = (string) ( $cf[ self::CF_COMPANY_NAME ] ?? '' );
        $member = trim( ( $subscriber->first_name ?? '' ) . ' ' . ( $subscriber->last_name ?? '' ) );

        // FluentCRM fallback values.
        $status = (string) ( $cf[ self::CF_STATUS ]       ?? '' );
        $open   = (float)  ( $cf[ self::CF_OPEN_BALANCE ] ?? 0 );
        $paid   = (float)  ( $cf[ self::CF_AMOUNT_PAID ]  ?? 0 );
        $source = 'fluentcrm';

        // PMPro override when the subscriber is linked to a WP user.
        $user_id = (int) ( $subscriber->user_id ?? 0 );
        if ( $user_id > 0 ) {
            $pm = NJILGA_PMPro_Data::for_user( $user_id, $year );
            if ( $pm ) {
                $status = $pm['status'];
                $open   = $pm['open_balance'];
                $paid   = $pm['amount_paid'];
                $source = 'pmpro';
            }
        }

        if ( $status === '' ) {
            $status = ( $open == 0 && $paid == 0 ) ? '$0' : 'Unpaid';
        }

        return [
            'firm'         => $firm,
            'member'       => $member,
            'status'       => $status,
            'open_balance' => $open,
            'amount_paid'  => $paid,
            'qty'          => 1,
            'source'       => $source,
        ];
    }

    // -------------------------------------------------------------------------
    // Data helpers
    // -------------------------------------------------------------------------

    private static function compute_totals( array $members ): array {
        $t = [
            'open_balance'  => 0,
            'amount_paid'   => 0,
            'qty'           => 0,
            'paid_count'    => 0,
            'unpaid_count'  => 0,
            'partial_count' => 0,
            'zero_count'    => 0,
        ];

        foreach ( $members as $m ) {
            $t['open_balance'] += $m['open_balance'];
            $t['amount_paid']  += $m['amount_paid'];
            $t['qty']          += $m['qty'];

            $status = strtolower( $m['status'] );
            if ( $status === 'paid' ) {
                $t['paid_count']++;
            } elseif ( $status === 'partial' ) {
                $t['partial_count']++;
            } elseif ( $m['open_balance'] == 0 && $m['amount_paid'] == 0 ) {
                $t['zero_count']++;
            } else {
                $t['unpaid_count']++;
            }
        }

        return $t;
    }
}
