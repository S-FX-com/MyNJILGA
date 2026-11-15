<?php
/**
 * Read-only invoicing tools for AI assistants (Claude and others), over
 * the Model Context Protocol.
 *
 * WordPress core's Abilities API (6.9+) is where a plugin registers what
 * it can do; the MCP Adapter plugin (wordpress.org/plugins/mcp-adapter)
 * turns abilities into MCP tools. This class registers eight READ-ONLY
 * abilities and, when the adapter is active, a dedicated MCP server for
 * them at /wp-json/my-njilga/mcp:
 *
 *   my-njilga/find-firm               firm name → FluentCRM company id
 *   my-njilga/list-firm-invoices      a firm's invoice rows, by year
 *   my-njilga/get-invoice             one invoice in full: status history,
 *                                     amounts, errors, whether it can be reissued
 *   my-njilga/invoice-stripe-events   what Stripe's webhook told us about it
 *   my-njilga/firm-notes              the firm's FluentCRM Company notes
 *                                     (who voided / sent / moved / wiped what)
 *   my-njilga/year-summary            counts and totals by status, and any
 *                                     invoice-creation or email run under way
 *   my-njilga/flagged-invoices        invoices carrying an error
 *   my-njilga/stripe-health           connection, active mode, webhook
 *
 * Nothing here changes data, sends email or calls Stripe. Who may use it:
 * only users with the `njilga_read_invoices` capability — administrators,
 * and the "NJILGA AI (read-only)" role made for the AI's own login. Members
 * have WordPress accounts on this site, so "logged in" is never enough.
 *
 * What it never returns: email addresses (redacted even inside notes and
 * errors), payment links, or member rosters. Firm and bill-to names are
 * returned — staff need them to talk about an invoice. Text fields carry
 * what staff and members typed; they are data for the assistant to read,
 * not instructions to it.
 *
 * The abilities stay off the MCP Adapter's shared default server and off
 * the core REST abilities endpoint (meta.mcp.public / show_in_rest false):
 * a custom server's listed tools don't need either.
 */
class MyNJILGA_Abilities {

    const CAP       = 'njilga_read_invoices';
    const ROLE      = 'njilga_ai_reader';
    const CATEGORY  = 'njilga-invoicing';
    const NS        = 'my-njilga';
    const SERVER_ID = 'njilga-invoicing';
    const ROUTE     = 'mcp';

    /** Bump when the role/capability setup changes, so it re-runs once on every site. */
    const CAPS_VERSION    = 1;
    const OPTION_CAPS_VER = 'njilga_ai_caps_version';

    /** The ability names, in the order the MCP server lists them. */
    const ABILITIES = [
        'my-njilga/find-firm',
        'my-njilga/list-firm-invoices',
        'my-njilga/get-invoice',
        'my-njilga/invoice-stripe-events',
        'my-njilga/firm-notes',
        'my-njilga/year-summary',
        'my-njilga/flagged-invoices',
        'my-njilga/stripe-health',
    ];

    public static function boot(): void {
        // The role and capability, once per CAPS_VERSION — plugin updates
        // don't fire activation hooks, so this can't live in one.
        add_action( 'init', [ __CLASS__, 'maybe_install_caps' ] );

        if ( ! function_exists( 'wp_register_ability' ) ) {
            return; // WordPress before 6.9: no Abilities API.
        }
        add_action( 'wp_abilities_api_categories_init', [ __CLASS__, 'register_category' ] );
        add_action( 'wp_abilities_api_init', [ __CLASS__, 'register_abilities' ] );
        add_action( 'mcp_adapter_init', [ __CLASS__, 'register_server' ] );
    }

    // =========================================================================
    // Access
    // =========================================================================

    public static function maybe_install_caps(): void {
        if ( (int) get_option( self::OPTION_CAPS_VER, 0 ) >= self::CAPS_VERSION ) {
            return;
        }
        if ( ! get_role( self::ROLE ) ) {
            add_role( self::ROLE, 'NJILGA AI (read-only)', [ 'read' => true, self::CAP => true ] );
        } else {
            get_role( self::ROLE )->add_cap( self::CAP );
        }
        $admin = get_role( 'administrator' );
        if ( $admin ) {
            $admin->add_cap( self::CAP );
        }
        update_option( self::OPTION_CAPS_VER, self::CAPS_VERSION, true );
    }

    public static function can_read(): bool {
        return current_user_can( self::CAP );
    }

    // =========================================================================
    // Registration
    // =========================================================================

    public static function register_category(): void {
        wp_register_ability_category( self::CATEGORY, [
            'label'       => 'NJILGA dues invoicing',
            'description' => 'Read-only views of NJILGA dues invoices, their Stripe webhook history and the firm notes that record what staff did.',
        ] );
    }

    public static function register_abilities(): void {
        foreach ( self::definitions() as $name => $def ) {
            wp_register_ability( $name, $def + [
                'category'            => self::CATEGORY,
                'permission_callback' => [ __CLASS__, 'can_read' ],
                'meta'                => [
                    'annotations'  => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
                    'show_in_rest' => false,
                    'mcp'          => [ 'public' => false, 'type' => 'tool' ],
                ],
            ] );
        }
    }

    /**
     * The adapter's hook. Our own server, so only our tools are on it and
     * only our capability reaches it.
     *
     * @param object $adapter \WP\MCP\Core\McpAdapter
     */
    public static function register_server( $adapter ): void {
        if ( ! is_object( $adapter ) || ! method_exists( $adapter, 'create_server' ) || ! class_exists( '\\WP\\MCP\\Transport\\HttpTransport' ) ) {
            return;
        }
        $errorHandler = class_exists( '\\WP\\MCP\\Infrastructure\\ErrorHandling\\ErrorLogMcpErrorHandler' )
            ? '\\WP\\MCP\\Infrastructure\\ErrorHandling\\ErrorLogMcpErrorHandler'
            : null;
        $adapter->create_server(
            self::SERVER_ID,
            self::NS,
            self::ROUTE,
            'My NJILGA invoicing (read-only)',
            'Read-only diagnostics for NJILGA dues invoices: firms, invoice history, Stripe webhook events, firm notes and Stripe health. Nothing here changes data or sends email.',
            self::plugin_version(),
            [ '\\WP\\MCP\\Transport\\HttpTransport' ],
            $errorHandler,
            null,
            self::ABILITIES,
            [],
            [],
            static function (): bool {
                return current_user_can( self::CAP );
            }
        );
    }

    /** The plugin's Version header — the one place it is kept. */
    private static function plugin_version(): string {
        $data = function_exists( 'get_file_data' ) && defined( 'NJILGA_REPORT_DIR' )
            ? get_file_data( NJILGA_REPORT_DIR . 'njilga-membership-report.php', [ 'Version' => 'Version' ] )
            : [];
        return (string) ( $data['Version'] ?? '' ) !== '' ? (string) $data['Version'] : '1.0.0';
    }

    /** The REST URL Claude connects to. */
    public static function endpoint(): string {
        return rest_url( self::NS . '/' . self::ROUTE );
    }

    // =========================================================================
    // The abilities
    // =========================================================================

    /** @return array<string,array<string,mixed>> */
    private static function definitions(): array {
        $str  = [ 'type' => 'string' ];
        $strN = [ 'type' => [ 'string', 'null' ] ];
        $int  = [ 'type' => 'integer' ];
        $intN = [ 'type' => [ 'integer', 'null' ] ];
        $bool = [ 'type' => 'boolean' ];
        $mode = [ 'type' => 'string', 'enum' => [ 'live', 'test' ], 'default' => 'live', 'description' => 'Stripe mode. Invoices exist separately in each; almost always live.' ];
        $year = [ 'type' => 'integer', 'minimum' => 2000, 'maximum' => 2100, 'description' => 'Dues year, e.g. 2027.' ];

        $invoiceSummary = [
            'type'       => 'object',
            'properties' => [
                'invoice_id'     => $int, 'company_id' => $int, 'firm' => $str, 'dues_year' => $int,
                'kind'           => $str, 'status' => $str, 'stage' => $str,
                'bill_to'        => $str, 'total_cents' => $int,
                'stripe_invoice' => $strN, 'stripe_number' => $strN,
                'sent_at'        => $strN, 'paid_at' => $strN, 'voided_at' => $strN,
                'error'          => $strN,
            ],
        ];

        return [
            'my-njilga/find-firm' => [
                'label'         => 'Find a law firm',
                'description'   => 'Search FluentCRM companies (law firms) by name; returns their company ids for the other tools. Read-only.',
                'input_schema'  => [
                    'type' => 'object',
                    'properties' => [
                        'query' => [ 'type' => 'string', 'minLength' => 2, 'maxLength' => 100, 'description' => 'Part of the firm name.' ],
                        'limit' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 25, 'default' => 10 ],
                    ],
                    'required' => [ 'query' ],
                    'additionalProperties' => false,
                ],
                'output_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'firms' => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'properties' => [ 'company_id' => $int, 'name' => $str ] ] ],
                    ],
                ],
                'execute_callback' => [ __CLASS__, 'find_firm' ],
            ],

            'my-njilga/list-firm-invoices' => [
                'label'         => "List a firm's invoices",
                'description'   => "Every dues invoice row for one firm (FluentCRM company id) — any status, including drafts, voided and paid — optionally for one dues year. Read-only; no payment links or emails.",
                'input_schema'  => [
                    'type' => 'object',
                    'properties' => [ 'company_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'dues_year' => $year, 'mode' => $mode ],
                    'required' => [ 'company_id' ],
                    'additionalProperties' => false,
                ],
                'output_schema' => [
                    'type' => 'object',
                    'properties' => [ 'invoices' => [ 'type' => 'array', 'items' => $invoiceSummary ] ],
                ],
                'execute_callback' => [ __CLASS__, 'list_firm_invoices' ],
            ],

            'my-njilga/get-invoice' => [
                'label'         => 'Get one invoice in full',
                'description'   => 'One invoice row by its id (from list-firm-invoices) or by its Stripe invoice id / number: its status, every timestamp (created, approved, queued, sent, finalized, paid, voided, synced…), amounts, the error on it, its roster size, whether it can be issued again after a void. An old Stripe invoice that was voided and issued again is traced to its firm\'s invoice (stripe_invoice_retired, with a note). Read-only.',
                'input_schema'  => [
                    'type' => 'object',
                    'properties' => [
                        'invoice_id'     => [ 'type' => 'integer', 'minimum' => 1 ],
                        'stripe_invoice' => [ 'type' => 'string', 'maxLength' => 100, 'description' => 'A Stripe invoice id (in_…) or invoice number.' ],
                    ],
                    'additionalProperties' => false,
                ],
                'output_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'found'   => $bool,
                        'invoice' => [ 'type' => [ 'object', 'null' ] ],
                        'note'    => $strN,
                    ],
                ],
                'execute_callback' => [ __CLASS__, 'get_invoice' ],
            ],

            'my-njilga/invoice-stripe-events' => [
                'label'         => "An invoice's Stripe webhook history",
                'description'   => 'The Stripe webhook events received for one invoice (by invoice id): type (invoice.paid, invoice.voided, …), when it arrived, and what the plugin did with it. Kept for 180 days. Read-only.',
                'input_schema'  => [
                    'type' => 'object',
                    'properties' => [
                        'invoice_id' => [ 'type' => 'integer', 'minimum' => 1 ],
                        'limit'      => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 50 ],
                    ],
                    'required' => [ 'invoice_id' ],
                    'additionalProperties' => false,
                ],
                'output_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'events' => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'properties' => [
                            'type' => $str, 'status' => $str, 'received_at' => $strN, 'processed_at' => $strN, 'message' => $strN, 'stripe_object' => $strN,
                        ] ] ],
                    ],
                ],
                'execute_callback' => [ __CLASS__, 'invoice_stripe_events' ],
            ],

            'my-njilga/firm-notes' => [
                'label'         => "A firm's activity notes",
                'description'   => "The notes on a firm's FluentCRM Company record, newest first. The plugin writes one for every invoice sent, paid, voided, wiped, moved or reissued, with who did it — the audit trail for 'why is this invoice like this?'. Email addresses are redacted. Read-only.",
                'input_schema'  => [
                    'type' => 'object',
                    'properties' => [
                        'company_id' => [ 'type' => 'integer', 'minimum' => 1 ],
                        'limit'      => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 30 ],
                    ],
                    'required' => [ 'company_id' ],
                    'additionalProperties' => false,
                ],
                'output_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'notes' => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'properties' => [
                            'title' => $str, 'text' => $str, 'by' => $strN, 'at' => $strN,
                        ] ] ],
                    ],
                ],
                'execute_callback' => [ __CLASS__, 'firm_notes' ],
            ],

            'my-njilga/year-summary' => [
                'label'         => 'Summary of a dues year',
                'description'   => 'For one dues year: how many invoices are in each status and their totals, and the progress of any invoice-creation or email run under way. Read-only.',
                'input_schema'  => [
                    'type' => 'object',
                    'properties' => [ 'dues_year' => $year, 'mode' => $mode ],
                    'required' => [ 'dues_year' ],
                    'additionalProperties' => false,
                ],
                'output_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'dues_year'      => $int,
                        'mode'           => $str,
                        'counts'         => [ 'type' => 'object' ],
                        'totals_cents'   => [ 'type' => 'object' ],
                        'creation_run'   => [ 'type' => [ 'object', 'null' ] ],
                        'email_run'      => [ 'type' => [ 'object', 'null' ] ],
                        'wiped'          => $bool,
                    ],
                ],
                'execute_callback' => [ __CLASS__, 'year_summary' ],
            ],

            'my-njilga/flagged-invoices' => [
                'label'         => 'Invoices with an error',
                'description'   => 'Invoice rows carrying an error message (a failed creation or email, a payment-processing problem…), newest dues year first. Read-only.',
                'input_schema'  => [
                    'type' => 'object',
                    'properties' => [ 'mode' => $mode, 'limit' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50 ] ],
                    'additionalProperties' => false,
                ],
                'output_schema' => [
                    'type' => 'object',
                    'properties' => [ 'invoices' => [ 'type' => 'array', 'items' => $invoiceSummary ], 'total' => $int ],
                ],
                'execute_callback' => [ __CLASS__, 'flagged_invoices' ],
            ],

            'my-njilga/stripe-health' => [
                'label'         => 'Stripe and webhook health',
                'description'   => "Which Stripe mode is active, whether each mode is connected, when Stripe's webhook last reached the site, and whether the webhook endpoint is subscribed to every event the plugin needs. Never returns keys. Read-only.",
                // No input: core then calls the callback with no argument.
                'output_schema' => [
                    'type' => 'object',
                    'properties' => [ 'active_mode' => $str, 'modes' => [ 'type' => 'object' ] ],
                ],
                'execute_callback' => [ __CLASS__, 'stripe_health' ],
            ],
        ];
    }

    // =========================================================================
    // Execute callbacks — reads only
    // =========================================================================

    /** @param array<string,mixed>|null $in */
    public static function find_firm( $in = null ): array {
        $q     = trim( (string) ( $in['query'] ?? '' ) );
        $limit = self::clamp( $in['limit'] ?? 10, 1, 25 );
        $out   = [];
        if ( strlen( $q ) >= 2 && class_exists( '\\FluentCrm\\App\\Models\\Company' ) ) {
            global $wpdb;
            $rows = \FluentCrm\App\Models\Company::where( 'name', 'LIKE', '%' . $wpdb->esc_like( $q ) . '%' )->orderBy( 'name' )->limit( $limit )->get();
            foreach ( $rows as $c ) {
                $out[] = [ 'company_id' => (int) $c->id, 'name' => (string) $c->name ];
            }
        }
        return [ 'firms' => $out ];
    }

    /** @param array<string,mixed>|null $in */
    public static function list_firm_invoices( $in = null ): array {
        $company = (int) ( $in['company_id'] ?? 0 );
        $year    = (int) ( $in['dues_year'] ?? 0 );
        $live    = self::live( $in );
        $out     = [];
        foreach ( MyNJILGA_Dues_Invoice_Table::get_for_companies( [ $company ], $live ) as $row ) {
            if ( $year > 0 && (int) $row->dues_year !== $year ) {
                continue;
            }
            $out[] = self::invoice_summary( $row );
        }
        return [ 'invoices' => $out ];
    }

    /** @param array<string,mixed>|null $in */
    public static function get_invoice( $in = null ): array {
        $row     = null;
        $retired = false;
        $id      = (int) ( $in['invoice_id'] ?? 0 );
        $ref     = trim( (string) ( $in['stripe_invoice'] ?? '' ) );
        if ( $id > 0 ) {
            $row = MyNJILGA_Dues_Invoice_Table::get( $id );
        } elseif ( $ref !== '' ) {
            $row = MyNJILGA_Dues_Invoice_Table::get_by_order_id( $ref ) ?: self::find_by_number( $ref );
            if ( ! $row ) {
                // A Stripe invoice voided and then issued again no longer
                // sits on its row: trace it, but only if a reissue really
                // retired it, and only to a row that is still that firm's.
                [ $row, $retired ] = self::trace_retired( $ref );
            }
        }
        if ( ! $row ) {
            return [
                'found'   => false,
                'invoice' => null,
                'note'    => $retired
                    ? sprintf( 'Stripe invoice %s was voided and the firm\'s invoice was issued again, but it can no longer be traced to an invoice here: the row it belonged to is gone (for example, its dues year was wiped) or the webhook record of it has expired. Look the firm up with find-firm instead. It stays voided in Stripe.', $ref )
                    : null,
            ];
        }
        $detail = self::invoice_detail( $row );
        $why    = class_exists( 'MyNJILGA_Invoice_Reissue' ) ? MyNJILGA_Invoice_Reissue::refusal( $row ) : 'n/a';
        $detail['can_issue_again']        = $why === '';
        $detail['issue_again_reason']     = $why === '' ? null : $why;
        $detail['stripe_invoice_retired'] = $retired;
        return [
            'found'   => true,
            'invoice' => $detail,
            'note'    => $retired
                ? sprintf( 'Stripe invoice %s was voided and this firm\'s invoice was issued again. Shown: the firm\'s %d invoice as it is now (its new Stripe invoice, if created yet, is stripe_invoice). The old one stays voided in Stripe.', $ref, (int) $row->dues_year )
                : null,
        ];
    }

    /**
     * A Stripe invoice id or number a reissue left behind → the firm's
     * invoice row now (or null), and whether $ref is known to have been
     * retired at all. The retired map is asked first; for an id retired
     * before that map existed, the webhook history (invoice events only).
     * Anything a reissue never retired — a charge, a payment, an invoice
     * that simply isn't ours — is not traced. The row must still be the
     * one the invoice belonged to: a wiped year's deleted id can come
     * back as another firm's row.
     *
     * @return array{0:?object,1:bool}
     */
    private static function trace_retired( string $ref ): array {
        $trace = MyNJILGA_Dues_Invoice_Table::retired_trace( $ref );
        if ( ! $trace ) {
            if ( ! MyNJILGA_Dues_Invoice_Table::is_retired_invoice_id( $ref ) ) {
                return [ null, false ];
            }
            $trace = MyNJILGA_Stripe_Events_Table::invoice_trace( $ref );
            if ( ! $trace ) {
                return [ null, true ];
            }
        }
        $byId     = MyNJILGA_Dues_Invoice_Table::get( (int) $trace['row'] ) ?: null;
        $firmRows = ( ! self::row_fits_trace( $byId, $trace ) && (int) ( $trace['company'] ?? 0 ) > 0 )
            ? MyNJILGA_Dues_Invoice_Table::get_for_companies( [ (int) $trace['company'] ], ! empty( $trace['live'] ) )
            : [];
        return [ self::pick_traced_row( $trace, $byId, $firmRows ), true ];
    }

    /**
     * PURE: is $row still the invoice row $trace describes — same id, an
     * annual invoice (a join is never issued again), and, as far as the
     * trace knows them, the same mode, firm and year, created no later
     * than the trace (a row created after it is a deleted id reused)?
     *
     * @param array<string,mixed> $trace
     */
    public static function row_fits_trace( ?object $row, array $trace ): bool {
        if ( ! $row || (int) ( $row->id ?? 0 ) !== (int) ( $trace['row'] ?? 0 ) ) {
            return false;
        }
        if ( (string) ( $row->invoice_kind ?? '' ) === MyNJILGA_Dues_Snapshot::KIND_JOIN ) {
            return false;
        }
        if ( isset( $trace['live'] ) && ( empty( $row->livemode ) ? 0 : 1 ) !== (int) $trace['live'] ) {
            return false;
        }
        if ( isset( $trace['company'] ) && (int) ( $row->fluentcrm_company_id ?? 0 ) !== (int) $trace['company'] ) {
            return false;
        }
        if ( isset( $trace['year'] ) && (int) ( $row->dues_year ?? 0 ) !== (int) $trace['year'] ) {
            return false;
        }
        $created = (string) ( $row->created_at ?? '' );
        $at      = (string) ( $trace['at'] ?? '' );
        return $created === '' || $at === '' || strcmp( $created, $at ) <= 0;
    }

    /**
     * PURE: the row a trace leads to — the row it names, if that still
     * fits; otherwise, for a trace that knows the firm and year (the
     * retired map's), the firm's one annual invoice for that year and
     * mode — e.g. after Refresh Firms replaced the row for a new billing
     * contact. Null when neither, or when the firm has more than one.
     *
     * @param array<string,mixed> $trace
     * @param array<int,object>   $firmRows
     */
    public static function pick_traced_row( array $trace, ?object $byId, array $firmRows ): ?object {
        if ( self::row_fits_trace( $byId, $trace ) ) {
            return $byId;
        }
        if ( ! isset( $trace['company'], $trace['year'] ) || (int) $trace['company'] <= 0 ) {
            return null;
        }
        $live = (int) ( $trace['live'] ?? 1 );
        $hits = array_values( array_filter( $firmRows, static function ( $r ) use ( $trace, $live ) {
            return (int) ( $r->fluentcrm_company_id ?? 0 ) === (int) $trace['company']
                && (int) ( $r->dues_year ?? 0 ) === (int) $trace['year']
                && ( empty( $r->livemode ) ? 0 : 1 ) === $live
                && (string) ( $r->invoice_kind ?? '' ) !== MyNJILGA_Dues_Snapshot::KIND_JOIN;
        } ) );
        return count( $hits ) === 1 ? $hits[0] : null;
    }

    /** @param array<string,mixed>|null $in */
    public static function invoice_stripe_events( $in = null ): array {
        $id    = (int) ( $in['invoice_id'] ?? 0 );
        $limit = self::clamp( $in['limit'] ?? 50, 1, 100 );
        $row   = $id > 0 ? MyNJILGA_Dues_Invoice_Table::get( $id ) : null;
        $out   = [];
        if ( $row ) {
            foreach ( MyNJILGA_Stripe_Events_Table::for_invoice( $id, (string) ( $row->gateway_invoice_id ?? '' ), $limit ) as $e ) {
                $out[] = [
                    'type'          => (string) $e->type,
                    'status'        => (string) $e->status,
                    'received_at'   => self::nullable( $e->received_at ?? null ),
                    'processed_at'  => self::nullable( $e->processed_at ?? null ),
                    'message'       => self::nullable( isset( $e->message ) ? self::redact( (string) $e->message ) : null ),
                    'stripe_object' => self::nullable( $e->object_id ?? null ),
                ];
            }
        }
        return [ 'events' => $out ];
    }

    /** @param array<string,mixed>|null $in */
    public static function firm_notes( $in = null ): array {
        $company = (int) ( $in['company_id'] ?? 0 );
        $limit   = self::clamp( $in['limit'] ?? 30, 1, 100 );
        $out     = [];
        if ( $company > 0 && class_exists( '\\FluentCrm\\App\\Models\\CompanyNote' ) ) {
            $notes = \FluentCrm\App\Models\CompanyNote::where( 'subscriber_id', $company )->orderBy( 'id', 'DESC' )->limit( $limit )->get();
            foreach ( $notes as $n ) {
                $user  = ! empty( $n->created_by ) ? get_userdata( (int) $n->created_by ) : null;
                $out[] = [
                    'title' => self::redact( (string) ( $n->title ?? '' ) ),
                    'text'  => self::redact( wp_strip_all_tags( (string) ( $n->description ?? '' ) ) ),
                    'by'    => $user ? self::redact( (string) $user->display_name ) : null,
                    'at'    => self::nullable( $n->created_at ?? null ),
                ];
            }
        }
        return [ 'notes' => $out ];
    }

    /** @param array<string,mixed>|null $in */
    public static function year_summary( $in = null ): array {
        $year = (int) ( $in['dues_year'] ?? 0 );
        $live = self::live( $in );
        $run  = static function ( ?array $p ): ?array {
            return $p === null ? null : array_intersect_key( $p, array_flip( [ 'total', 'done', 'failed', 'pending', 'skipped', 'stalled', 'finished', 'started_at' ] ) );
        };
        return [
            'dues_year'    => $year,
            'mode'         => $live ? 'live' : 'test',
            'counts'       => (object) MyNJILGA_Dues_Invoice_Table::counts_by_status( $year, $live ),
            'totals_cents' => (object) MyNJILGA_Dues_Invoice_Table::totals_by_status( $year, $live ),
            'creation_run' => $run( class_exists( 'MyNJILGA_Create_Progress' ) ? MyNJILGA_Create_Progress::current( $year, $live ) : null ),
            'email_run'    => $run( class_exists( 'MyNJILGA_Email_Run' ) ? MyNJILGA_Email_Run::current( $year, $live ) : null ),
            'wiped'        => class_exists( 'MyNJILGA_Year_Wipe' ) && MyNJILGA_Year_Wipe::was_wiped( $year, $live ),
        ];
    }

    /** @param array<string,mixed>|null $in */
    public static function flagged_invoices( $in = null ): array {
        $rows  = MyNJILGA_Dues_Invoice_Table::get_flagged( self::live( $in ) );
        $limit = self::clamp( $in['limit'] ?? 50, 1, 200 );
        return [
            'invoices' => array_map( [ __CLASS__, 'invoice_summary' ], array_slice( $rows, 0, $limit ) ),
            'total'    => count( $rows ),
        ];
    }

    /** @param array<string,mixed>|null $in */
    public static function stripe_health( $in = null ): array {
        $modes = [];
        foreach ( [ MyNJILGA_Stripe_Connection::MODE_LIVE, MyNJILGA_Stripe_Connection::MODE_TEST ] as $mode ) {
            $state = MyNJILGA_Stripe_Connection::webhook_events_state( $mode );
            $modes[ $mode ] = [
                'connected'              => MyNJILGA_Stripe_Connection::is_connected( $mode ),
                'last_webhook_received'  => MyNJILGA_Stripe_Events_Table::last_received_at( $mode === MyNJILGA_Stripe_Connection::MODE_LIVE ),
                'webhook_endpoint'       => (string) $state['endpoint'],
                'webhook_events_ok'      => (bool) $state['synced'],
                'webhook_events_missing' => array_values( (array) $state['missing'] ),
                'webhook_api_version'    => (string) $state['api_version'],
            ];
        }
        return [ 'active_mode' => MyNJILGA_Stripe_Connection::active_mode(), 'modes' => (object) $modes ];
    }

    // =========================================================================
    // Shaping — PURE (unit-tested): what an assistant is shown of a row
    // =========================================================================

    /** One row, briefly. No links, emails or roster. */
    public static function invoice_summary( object $row ): array {
        $billTo = MyNJILGA_Dues_Snapshot::bill_to( $row );
        return [
            'invoice_id'     => (int) $row->id,
            'company_id'     => (int) ( $row->fluentcrm_company_id ?? 0 ),
            'firm'           => MyNJILGA_Dues_Snapshot::company_name( $row ),
            'dues_year'      => (int) ( $row->dues_year ?? 0 ),
            'kind'           => (string) ( $row->invoice_kind ?? '' ),
            'status'         => (string) ( $row->status ?? '' ),
            'stage'          => self::stage( $row ),
            // A contact with no name on file is named by their email: redacted like any other.
            'bill_to'        => self::redact( (string) $billTo['name'] ),
            'total_cents'    => (int) ( $row->total_amount_cents ?? 0 ),
            'stripe_invoice' => self::nullable( $row->gateway_invoice_id ?? null ),
            'stripe_number'  => self::nullable( $row->gateway_invoice_number ?? null ),
            'sent_at'        => self::nullable( $row->sent_at ?? null ),
            'paid_at'        => self::nullable( $row->paid_at ?? null ),
            'voided_at'      => self::nullable( $row->voided_at ?? null ),
            'error'          => self::nullable( isset( $row->last_error ) && $row->last_error !== null ? self::redact( (string) $row->last_error ) : null ),
        ];
    }

    /** One row in full — still no links, emails or roster names. */
    public static function invoice_detail( object $row ): array {
        $timestamps = [];
        foreach ( [ 'created_at', 'approved_at', 'queued_at', 'sent_at', 'finalized_at', 'due_date', 'processing_at', 'paid_at', 'downgraded_at', 'voided_at', 'last_synced_at' ] as $col ) {
            $timestamps[ $col ] = self::nullable( $row->$col ?? null );
        }
        return self::invoice_summary( $row ) + [
            'livemode'              => ! empty( $row->livemode ),
            'billing_mode'          => (string) ( $row->billing_mode ?? '' ),
            'bill_to_contact_id'    => (int) ( $row->bill_to_contact_id ?? 0 ),
            'members_on_invoice'    => count( MyNJILGA_Dues_Snapshot::members( $row ) ),
            'stripe_status'         => self::nullable( $row->stripe_status ?? null ),
            'stripe_customer'       => self::nullable( $row->gateway_customer_id ?? null ),
            'paid_cents'            => (int) ( $row->amount_paid_cents ?? 0 ),
            'due_cents'             => (int) ( $row->amount_due_cents ?? 0 ),
            'refunded_cents'        => (int) ( $row->amount_refunded_cents ?? 0 ),
            'paid_off_stripe_cents' => (int) ( $row->paid_off_stripe_cents ?? 0 ),
            'primary_method'        => self::nullable( $row->primary_method ?? null ),
            'has_payment_link'      => (string) ( $row->hosted_invoice_url ?? '' ) !== '',
            'timestamps'            => $timestamps,
        ];
    }

    /** The Invoicing page's stage for a row, in its tab names. */
    public static function stage( object $row ): string {
        $status = (string) ( $row->status ?? '' );
        if ( (string) ( $row->invoice_kind ?? '' ) === MyNJILGA_Dues_Snapshot::KIND_JOIN ) {
            return $status === MyNJILGA_Dues_Invoice_Table::STATUS_PAID ? 'Paid (online join)' : 'Needs Attention';
        }
        switch ( $status ) {
            case MyNJILGA_Dues_Invoice_Table::STATUS_DRAFT:
            case MyNJILGA_Dues_Invoice_Table::STATUS_APPROVED:
                return (string) ( $row->last_error ?? '' ) !== '' ? 'Needs Attention' : 'No Invoice';
            case MyNJILGA_Dues_Invoice_Table::STATUS_CREATED:
                return 'Invoice Created';
            case MyNJILGA_Dues_Invoice_Table::STATUS_SENT:
                return 'Email Sent';
            case MyNJILGA_Dues_Invoice_Table::STATUS_PROCESSING:
                return (string) ( $row->sent_at ?? '' ) !== '' ? 'Email Sent' : 'Invoice Created';
            case MyNJILGA_Dues_Invoice_Table::STATUS_PAID:
                return 'Paid';
            default: // excluded, voided, uncollectible, downgraded
                return 'Needs Attention';
        }
    }

    /** Email addresses out of free text (notes, errors, webhook messages), and a sane length. */
    public static function redact( string $text, int $max = 1000 ): string {
        $text = (string) preg_replace( '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[email]', $text );
        return mb_strlen( $text ) > $max ? mb_substr( $text, 0, $max ) . '…' : $text;
    }

    /** '' and '0000-00-00…' (MySQL's "no date") read as null. */
    public static function nullable( $value ): ?string {
        if ( $value === null ) {
            return null;
        }
        $s = (string) $value;
        return ( $s === '' || strpos( $s, '0000-00-00' ) === 0 ) ? null : $s;
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /** @param array<string,mixed>|null $in */
    private static function live( $in ): bool {
        return ( (string) ( $in['mode'] ?? 'live' ) ) !== 'test';
    }

    private static function clamp( $value, int $min, int $max ): int {
        return max( $min, min( $max, (int) $value ) );
    }

    private static function find_by_number( string $number ) {
        global $wpdb;
        $table = MyNJILGA_Dues_Invoice_Table::table_name();
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE gateway_invoice_number = %s ORDER BY id DESC LIMIT 1", $number ) ); // phpcs:ignore
    }

    // =========================================================================
    // Settings → Advanced: status and setup steps
    // =========================================================================

    public static function render_settings_card(): void {
        $abilities = function_exists( 'wp_register_ability' );
        $adapter   = class_exists( '\\WP\\MCP\\Core\\McpAdapter' );
        $role      = get_role( self::ROLE );
        $users     = $role ? count( get_users( [ 'role' => self::ROLE, 'fields' => 'ID', 'number' => 50 ] ) ) : 0;

        MyNJILGA_Admin_UI::section(
            'AI assistant access (MCP)',
            'Lets an AI assistant such as Claude look up invoices, their Stripe history and the firm notes, to help work out what happened. Read-only: it can\'t change, send or void anything, and never sees email addresses or payment links.'
        );
        echo '<div class="njilga-card njilga-table-boxed"><div class="njilga-tablewrap"><table class="njilga-table njilga-kv"><tbody>';
        printf( '<tr><th>WordPress Abilities API</th><td>%s</td></tr>', MyNJILGA_Admin_UI::validation( $abilities ? 'Available' : 'Not available — needs WordPress 6.9 or later', $abilities ) );
        printf(
            '<tr><th>MCP Adapter plugin</th><td>%s</td></tr>',
            $adapter
                ? MyNJILGA_Admin_UI::validation( 'Active', true )
                : MyNJILGA_Admin_UI::validation( 'Not active — install "MCP Adapter" from Plugins → Add New', false )
        );
        printf( '<tr><th>Endpoint</th><td><code>%s</code></td></tr>', esc_html( self::endpoint() ) );
        printf( '<tr><th>Users with the AI role</th><td>%s</td></tr>', $users > 0 ? MyNJILGA_Admin_UI::validation( (string) $users, true ) : MyNJILGA_Admin_UI::status( 'None yet', 'muted' ) );
        printf( '<tr><th>Tools</th><td>%s</td></tr>', implode( ' ', array_map( static function ( $n ) { return '<code>' . esc_html( str_replace( '/', '-', $n ) ) . '</code>'; }, self::ABILITIES ) ) );
        echo '</tbody></table></div></div>';

        echo '<details class="njilga-details">';
        printf( '<summary>%s Setting it up</summary>', MyNJILGA_Admin_UI::icon( 'wrench' ) );
        echo '<div class="njilga-card njilga-card-pad"><ol class="njilga-list">';
        echo '<li>Install and activate the <strong>MCP Adapter</strong> plugin (Plugins → Add New → search "MCP Adapter").</li>';
        echo '<li>Create a WordPress user for the assistant (Users → Add New) with the role <strong>NJILGA AI (read-only)</strong>. Don\'t use an administrator\'s login.</li>';
        echo '<li>On that user\'s profile, under <strong>Application Passwords</strong>, create one per computer or app, and keep it somewhere safe. You can revoke it there at any time.</li>';
        printf( '<li>Check it works: <code>curl --user "USERNAME:APPLICATION PASSWORD" %s</code> should answer with JSON, not a 401.</li>', esc_html( rest_url( 'wp/v2/users/me' ) ) );
        printf( '<li><strong>Claude Code:</strong> <code>claude mcp add --transport http njilga %s --header "Authorization: Basic BASE64"</code>, where BASE64 is <code>USERNAME:APPLICATION PASSWORD</code> base64-encoded.</li>', esc_html( self::endpoint() ) );
        echo '<li><strong>claude.ai:</strong> a custom connector needs either the "Request headers" option (to send the same Authorization header) or an OAuth sign-in, which WordPress doesn\'t provide on its own.</li>';
        echo '</ol></div></details>';
    }
}
