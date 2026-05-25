<?php
/**
 * Fetches and groups member data from FluentCRM via the REST API, then
 * enriches per-member payment numbers from Paid Memberships Pro tables
 * when a contact is linked to a WordPress user.
 *
 * Uses wp_remote_get() internally so this works as a WordPress plugin.
 * Auth credentials are stored in wp_options (see README).
 *
 * Key FluentCRM API facts (from the developer OpenAPI specs):
 *
 *  - List contacts:  GET /wp-json/fluent-crm/v2/subscribers
 *      Documented filters: tags[]=<id>&statuses[]=subscribed
 *      To embed custom field values on each row, pass:
 *          with[]=subscriber.custom_values
 *      Response: { subscribers: { data: [...], last_page: N } }
 *      Each contact carries custom_values: { slug: value } when requested.
 *
 *  - List all tags:  GET /wp-json/fluent-crm/v2/tags?all_tags=true
 *      Response: { tags: { data: [...paginated 15/page...],
 *                          all_tags: [ { id, slug, title }, ... ] }
 *      With all_tags=true we MUST read $response['all_tags']; the
 *      tags.data array is only the first page.
 *
 * Notes on Contact schema:
 *   - There is no built-in company_name property. The "Firm" field comes
 *     from a FluentCRM custom field named `company_name` (see README).
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
     * FluentCRM custom field slugs used as a fallback when a contact has
     * no linked WordPress user (and therefore no PMPro record).
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
        $tag_map = self::fetch_tag_id_map();
        if ( is_wp_error( $tag_map ) ) {
            return $tag_map;
        }

        $year    = (int) date( 'Y' );
        $tiers   = [];
        $summary = [ 'total' => 0, 'paid' => 0, 'unpaid' => 0, 'partial' => 0, 'zero' => 0 ];

        foreach ( self::TIER_MAP as $slug => $label ) {
            if ( ! isset( $tag_map[ $slug ] ) ) {
                $tiers[ $label ] = [ 'members' => [], 'totals' => self::compute_totals( [] ) ];
                continue;
            }

            $members = self::fetch_members_for_tag( $tag_map[ $slug ], $year );
            if ( is_wp_error( $members ) ) {
                return $members;
            }

            $totals = self::compute_totals( $members );
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
    // FluentCRM API helpers
    // -------------------------------------------------------------------------

    /**
     * Returns [ slug => id ] for every FluentCRM tag.
     *
     * Endpoint: GET /tags?all_tags=true
     * The `all_tags` query parameter triggers a flat top-level `all_tags`
     * array that contains every tag — bypassing pagination on `tags.data`,
     * which is the first 15 only.
     *
     * @return array|WP_Error
     */
    private static function fetch_tag_id_map() {
        $response = self::api_get( '/tags', [ 'all_tags' => 'true' ] );
        if ( is_wp_error( $response ) ) {
            return $response;
        }

        // Prefer the flat all_tags list (complete); fall back to tags.data
        // for older FluentCRM versions that may not emit all_tags.
        $rows = $response['all_tags'] ?? $response['tags']['data'] ?? [];

        $map = [];
        foreach ( $rows as $tag ) {
            if ( ! empty( $tag['slug'] ) && isset( $tag['id'] ) ) {
                $map[ (string) $tag['slug'] ] = (int) $tag['id'];
            }
        }
        return $map;
    }

    /**
     * Fetches all subscribed contacts tagged with $tag_id (handles pagination)
     * and enriches each with PMPro payment data when a linked WP user exists.
     *
     * @return array|WP_Error
     */
    private static function fetch_members_for_tag( int $tag_id, int $year ) {
        $members   = [];
        $page      = 1;
        $last_page = 1;

        do {
            $response = self::api_get( '/subscribers', [
                'tags[]'       => $tag_id,
                'statuses[]'   => 'subscribed',
                'with[]'       => 'subscriber.custom_values',
                'sort_by'      => 'last_name',
                'sort_type'    => 'ASC',
                'per_page'     => 100,
                'page'         => $page,
            ] );

            if ( is_wp_error( $response ) ) {
                return $response;
            }

            $last_page = (int) ( $response['subscribers']['last_page'] ?? 1 );

            foreach ( $response['subscribers']['data'] ?? [] as $contact ) {
                $members[] = self::build_member_row( $contact, $year );
            }

            $page++;
        } while ( $page <= $last_page );

        // Running invoiced total column, sorted by firm for display.
        usort( $members, static fn( $a, $b ) => strcasecmp( $a['firm'], $b['firm'] ) );
        $running = 0.0;
        foreach ( $members as &$m ) {
            $running           += $m['open_balance'] + $m['amount_paid'];
            $m['invoiced_total'] = $running;
        }
        unset( $m );

        return $members;
    }

    /**
     * Builds one report row from a FluentCRM contact, layering PMPro data on
     * top of (or substituting for) the FluentCRM custom field values.
     */
    private static function build_member_row( array $contact, int $year ): array {
        $cv = (array) ( $contact['custom_values'] ?? [] );

        // Firm: prefer the documented `company_name` custom field; the
        // built-in Contact schema has no direct company_name property.
        $firm = (string) ( $cv[ self::CF_COMPANY_NAME ] ?? '' );

        $member = trim(
            ( $contact['first_name'] ?? '' ) . ' ' . ( $contact['last_name'] ?? '' )
        );

        // FluentCRM fallback values.
        $status = (string) ( $cv[ self::CF_STATUS ]        ?? '' );
        $open   = (float)  ( $cv[ self::CF_OPEN_BALANCE ]  ?? 0 );
        $paid   = (float)  ( $cv[ self::CF_AMOUNT_PAID ]   ?? 0 );
        $source = 'fluentcrm';

        // PMPro override when the contact is linked to a WordPress user.
        $user_id = (int) ( $contact['user_id'] ?? 0 );
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
            // No status anywhere — distinguish "no data" from "unpaid".
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

    /**
     * @return array|WP_Error  Decoded JSON body as array.
     */
    private static function api_get( string $endpoint, array $params = [] ) {
        $base_url = rtrim( get_option( 'njilga_fcrm_base_url', home_url() ), '/' );
        $username = get_option( 'njilga_fcrm_api_user', '' );
        $password = get_option( 'njilga_fcrm_api_pass', '' );

        $url = $base_url . '/wp-json/fluent-crm/v2' . $endpoint;
        if ( $params ) {
            $url .= '?' . http_build_query( $params );
        }

        $response = wp_remote_get( $url, [
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode( $username . ':' . $password ),
                'Content-Type'  => 'application/json',
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code !== 200 ) {
            $msg = $body['message'] ?? "HTTP {$code}";
            return new WP_Error( 'api_error', "FluentCRM API error: {$msg}", [ 'status' => $code ] );
        }

        return $body;
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
