<?php
/**
 * Stripe HTTP transport (spec: Stripe migration phase 1) — the thin,
 * stateless layer every raw Stripe REST call in this plugin goes through.
 * This file, and the Stripe invoice gateway a later phase builds on top
 * of it, are the ONLY places in the plugin allowed to construct a raw
 * Stripe HTTP request.
 *
 * This class knows exactly one thing: HTTP method + path + params + opts
 * in, a normalized result array out. It must NEVER reference invoices,
 * customers, dues, firms, or any other plugin domain concept — that
 * belongs to the gateway built on top of it. It also has no opinion on
 * test vs. live mode: the caller decides which secret key to hand the
 * constructor, and this client never reads a WordPress option to figure
 * that out for itself.
 */
class MyNJILGA_Stripe_Client {

    const API_BASE = 'https://api.stripe.com/v1';

    // Pinned Stripe API version, sent as the Stripe-Version header on
    // every request. Deliberately NOT the account's dashboard-configured
    // default — that would let a Stripe-side default bump silently change
    // this plugin's request/response shape out from under it. Only bump
    // this after checking the new version's payload compatibility against
    // every call site that uses this client.
    const API_VERSION = '2024-06-20';

    const TIMEOUT_SECONDS = 20;

    const OPTION_REQUEST_LOG = 'njilga_stripe_request_log';
    const REQUEST_LOG_MAX    = 100;

    /** @var string */
    private $secretKey;

    public function __construct( string $secretKey ) {
        $this->secretKey = $secretKey;
    }

    // -------------------------------------------------------------------------
    // Transport
    // -------------------------------------------------------------------------

    /**
     * @param array<string,mixed>                                       $params
     * @param array{idempotency_key?:string,expand?:array<int,string>}  $opts
     * @return array{ok:bool,status:int,body:array<string,mixed>,request_id:string,error:string,code:string}
     */
    public function request( string $method, string $path, array $params = [], array $opts = [] ): array {
        $method = strtoupper( $method );

        // Convenience: fold opts['expand'] into params['expand'] so callers
        // don't have to build that array entry by hand.
        if ( ! empty( $opts['expand'] ) && is_array( $opts['expand'] ) ) {
            $existingExpand   = ( isset( $params['expand'] ) && is_array( $params['expand'] ) ) ? $params['expand'] : [];
            $params['expand'] = array_values( array_merge( $existingExpand, $opts['expand'] ) );
        }

        $url     = self::API_BASE . $path;
        $headers = [
            'Authorization'  => 'Bearer ' . $this->secretKey,
            'Stripe-Version' => self::API_VERSION,
        ];

        $args = [
            'method'  => $method,
            'headers' => $headers,
            'timeout' => self::TIMEOUT_SECONDS,
        ];

        if ( $method === 'GET' ) {
            $query = self::encode_params( $params );
            if ( $query !== '' ) {
                $url .= '?' . $query;
            }
        } elseif ( $method === 'POST' ) {
            $headers['Content-Type'] = 'application/x-www-form-urlencoded';
            $args['headers']         = $headers;
            $args['body']            = self::encode_params( $params );
        }
        // DELETE: no body — Stripe delete endpoints take no params here.

        if ( isset( $opts['idempotency_key'] ) && $opts['idempotency_key'] !== '' ) {
            $headers['Idempotency-Key'] = (string) $opts['idempotency_key'];
            $args['headers']            = $headers;
        }

        $result = $this->send_once( $url, $args );

        // Retry exactly once, after a 1s delay, on a 5xx or a transport
        // failure (status 0). Never retry a 4xx. This client is only ever
        // called from Action Scheduler background jobs, never a
        // user-facing request thread, so a blocking sleep is acceptable.
        if ( $result['status'] === 0 || $result['status'] >= 500 ) {
            sleep( 1 );
            $result = $this->send_once( $url, $args );
        }

        self::log_request( $method, $path, $result['status'], $result['request_id'] );

        return $result;
    }

    /**
     * @param array<string,mixed> $args
     * @return array{ok:bool,status:int,body:array<string,mixed>,request_id:string,error:string,code:string}
     */
    private function send_once( string $url, array $args ): array {
        $response = wp_remote_request( $url, $args );

        if ( is_wp_error( $response ) ) {
            return [
                'ok'         => false,
                'status'     => 0,
                'body'       => [],
                'request_id' => '',
                'error'      => $response->get_error_message(),
                'code'       => '',
            ];
        }

        $status    = (int) wp_remote_retrieve_response_code( $response );
        $decoded   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
        $body      = is_array( $decoded ) ? $decoded : [];
        $requestId = (string) wp_remote_retrieve_header( $response, 'request-id' );
        $ok        = ( $status >= 200 && $status < 300 );

        $error = '';
        $code  = '';
        if ( ! $ok ) {
            if ( isset( $body['error'] ) && is_array( $body['error'] ) ) {
                $error = isset( $body['error']['message'] ) ? (string) $body['error']['message'] : '';
                $code  = isset( $body['error']['code'] ) ? (string) $body['error']['code'] : '';
            }
            if ( $error === '' ) {
                $error = sprintf( 'Stripe request failed with HTTP %d.', $status );
            }
        }

        return [
            'ok'         => $ok,
            'status'     => $status,
            'body'       => $body,
            'request_id' => $requestId,
            'error'      => $error,
            'code'       => $code,
        ];
    }

    // -------------------------------------------------------------------------
    // Param encoding (pure — no WordPress calls, unit tested directly)
    // -------------------------------------------------------------------------

    /**
     * Flattens $params into Stripe's bracket-notation, form-urlencoded
     * query string: `metadata[foo]=bar`, `lines[0][amount]=12500`, etc.
     * Both keys and values are rawurlencode()'d. Booleans encode as the
     * literal strings "true"/"false". A null value is OMITTED entirely
     * (Stripe treats an explicit empty string differently from an absent
     * param for some fields). Order follows the array as given.
     *
     * Deliberately hand-written rather than http_build_query(), whose
     * bracket/space encoding differs across PHP versions and doesn't
     * match Stripe's expectations.
     *
     * @param array<string,mixed> $params
     */
    public static function encode_params( array $params ): string {
        $pairs = [];
        self::flatten_params( $params, '', $pairs );
        return implode( '&', $pairs );
    }

    /**
     * @param mixed                $value
     * @param array<int,string>    $pairs Accumulator, passed by reference.
     */
    private static function flatten_params( $value, string $prefix, array &$pairs ): void {
        if ( is_array( $value ) ) {
            foreach ( $value as $key => $item ) {
                $nextPrefix = ( $prefix === '' ) ? (string) $key : $prefix . '[' . $key . ']';
                self::flatten_params( $item, $nextPrefix, $pairs );
            }
            return;
        }

        if ( $value === null || $prefix === '' ) {
            // null => omitted entirely. $prefix === '' only happens if a
            // bare scalar were passed as the top-level $params, which the
            // type hint (array) already rules out.
            return;
        }

        $pairs[] = rawurlencode( $prefix ) . '=' . rawurlencode( self::stringify_scalar( $value ) );
    }

    /**
     * @param mixed $value
     */
    private static function stringify_scalar( $value ): string {
        if ( is_bool( $value ) ) {
            return $value ? 'true' : 'false';
        }
        return (string) $value;
    }

    // -------------------------------------------------------------------------
    // Request log (ring buffer — method/path/status only, never params,
    // response bodies, or the secret key)
    // -------------------------------------------------------------------------

    private static function log_request( string $method, string $path, int $status, string $requestId ): void {
        $log   = get_option( self::OPTION_REQUEST_LOG, [] );
        $log   = is_array( $log ) ? $log : [];
        array_unshift( $log, [
            'at'         => current_time( 'mysql' ),
            'method'     => $method,
            'path'       => $path,
            'status'     => $status,
            'request_id' => $requestId,
        ] );
        if ( count( $log ) > self::REQUEST_LOG_MAX ) {
            $log = array_slice( $log, 0, self::REQUEST_LOG_MAX );
        }
        update_option( self::OPTION_REQUEST_LOG, $log, false );
    }

    /**
     * Most recent Stripe requests, newest first — for a later phase's
     * Setup-page diagnostics panel.
     *
     * @return array<int,array{at:string,method:string,path:string,status:int,request_id:string}>
     */
    public static function recent_requests(): array {
        $log = get_option( self::OPTION_REQUEST_LOG, [] );
        return is_array( $log ) ? $log : [];
    }
}
