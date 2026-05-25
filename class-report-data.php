<?php
/**
 * Fetches and groups member data from FluentCRM via the REST API.
 *
 * Uses wp_remote_get() internally so this works as a WordPress plugin.
 * Auth credentials are stored in wp-config.php or wp_options (see README).
 *
 * Key API facts (from FluentCRM developer docs):
 *  - List contacts:  GET /wp-json/fluent-crm/v2/subscribers
 *      ?tags[]=<id>&custom_fields=true&per_page=100&page=N
 *      Response: { subscribers: { data: [...], last_page: N } }
 *      Each contact has:  custom_values: { slug: value }  (flat object)
 *  - List all tags:  GET /wp-json/fluent-crm/v2/tags?all_tags=true
 *      Response: { tags: { data: [ { id, slug, title }, ... ] } }
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
     * Custom field slugs expected on each contact.
     * Values come back in contact.custom_values as a flat { slug: value } object.
     */
    const CF_STATUS        = 'dues_status';        // "Paid" | "Unpaid" | "Partial"
    const CF_OPEN_BALANCE  = 'dues_open_balance';  // numeric string
    const CF_AMOUNT_PAID   = 'dues_amount_paid';   // numeric string

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

        $tiers   = [];
        $summary = [ 'total' => 0, 'paid' => 0, 'unpaid' => 0, 'partial' => 0, 'zero' => 0 ];

        foreach ( self::TIER_MAP as $slug => $label ) {
            if ( ! isset( $tag_map[ $slug ] ) ) {
                // Tag doesn't exist in FluentCRM yet — include empty section.
                $tiers[ $label ] = [ 'members' => [], 'totals' => self::compute_totals( [] ) ];
                continue;
            }

            $tag_id  = $tag_map[ $slug ];
            $members = self::fetch_members_for_tag( $tag_id );
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
            'year'    => (int) date( 'Y' ),
            'title'   => 'New Jersey Institute of Local Government Attorneys',
            'tiers'   => $tiers,
            'summary' => $summary,
        ];
    }

    // -------------------------------------------------------------------------
    // API helpers
    // -------------------------------------------------------------------------

    /**
     * Fetches all FluentCRM tags and returns [ slug => id ].
     *
     * Endpoint: GET /wp-json/fluent-crm/v2/tags?all_tags=true
     * Response:  { tags: { data: [ { id, slug, title } ] } }
     *
     * @return array|WP_Error
     */
    private static function fetch_tag_id_map() {
        $response = self::api_get( '/tags', [ 'all_tags' => 'true' ] );
        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $map = [];
        foreach ( $response['tags']['data'] ?? [] as $tag ) {
            $map[ $tag['slug'] ] = (int) $tag['id'];
        }
        return $map;
    }

    /**
     * Fetches all contacts tagged with $tag_id (handles pagination).
     *
     * Endpoint: GET /wp-json/fluent-crm/v2/subscribers
     *   ?tags[]=<id>&custom_fields=true&per_page=100&page=N&sort_by=company_name&sort_type=ASC
     *
     * Response: { subscribers: { data: [...contact...], last_page: N } }
     *
     * Each contact has:
     *   first_name, last_name, company_name,
     *   custom_values: { dues_status, dues_open_balance, dues_amount_paid }
     *
     * @return array|WP_Error  Array of member rows.
     */
    private static function fetch_members_for_tag( int $tag_id ) {
        $members    = [];
        $page       = 1;
        $last_page  = 1;

        do {
            $response = self::api_get( '/subscribers', [
                'tags[]'        => $tag_id,
                'custom_fields' => 'true',
                'sort_by'       => 'company_name',
                'sort_type'     => 'ASC',
                'per_page'      => 100,
                'page'          => $page,
            ] );

            if ( is_wp_error( $response ) ) {
                return $response;
            }

            $last_page = (int) ( $response['subscribers']['last_page'] ?? 1 );

            foreach ( $response['subscribers']['data'] ?? [] as $contact ) {
                // custom_values is a flat { slug: value } object per API docs.
                $cv     = (array) ( $contact['custom_values'] ?? [] );
                $status = $cv[ self::CF_STATUS ]       ?? 'Unpaid';
                $open   = (float) ( $cv[ self::CF_OPEN_BALANCE ] ?? 0 );
                $paid   = (float) ( $cv[ self::CF_AMOUNT_PAID ]  ?? 0 );

                $members[] = [
                    'firm'         => $contact['company_name'] ?? '',
                    'member'       => trim( ( $contact['first_name'] ?? '' ) . ' ' . ( $contact['last_name'] ?? '' ) ),
                    'status'       => $status,
                    'open_balance' => $open,
                    'amount_paid'  => $paid,
                    'qty'          => 1,
                ];
            }

            $page++;
        } while ( $page <= $last_page );

        // Build running invoiced total column.
        $running = 0;
        foreach ( $members as &$m ) {
            $running           += $m['open_balance'] + $m['amount_paid'];
            $m['invoiced_total'] = $running;
        }
        unset( $m );

        return $members;
    }

    /**
     * Wraps wp_remote_get() with auth headers from saved options.
     *
     * Credentials are set once via the admin page and stored with:
     *   update_option( 'njilga_fcrm_api_user', $username );
     *   update_option( 'njilga_fcrm_api_pass', $app_password );
     *   update_option( 'njilga_fcrm_base_url', 'https://yoursite.com' );
     *
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
