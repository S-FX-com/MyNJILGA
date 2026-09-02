<?php
/**
 * Stripe implementation of MyNJILGA_Invoice_Gateway (Stripe migration
 * phase 2). This file, together with MyNJILGA_Stripe_Client, is the ONLY
 * place in the plugin allowed to construct a raw Stripe API call — every
 * other class talks in the plain array shapes the interface defines and
 * never names a Stripe endpoint path.
 *
 * Design notes:
 *
 *   - One Stripe Customer per FIRM, not per bill-to contact. See
 *     find_or_create_customer(): MyNJILGA_Stripe_Customer_Map keeps the
 *     (company_id, mode) -> Stripe Customer id mapping, backstopped by a
 *     metadata search so a re-provisioned site or a race between two
 *     requests can't create a duplicate Customer for the same firm.
 *
 *   - One Stripe Invoice per invoice row, built through the
 *     draft -> add_lines -> finalize sequence Stripe's own API requires
 *     (create_order()), rather than creating with line items inline —
 *     that's what lets a large roster be built up across several
 *     add_lines() calls (Stripe caps a single call and the invoice as a
 *     whole) before the invoice is finalized.
 *
 *   - Settlement (granting roles/tags on payment) is intentionally NOT
 *     performed anywhere in this class — see mark_paid_out_of_band().
 *     The `invoice.paid` webhook (a later phase) is the ONLY code path
 *     allowed to call MyNJILGA_Payment_Listener::settle(); this class
 *     only ever asks Stripe to mark something paid, never decides that
 *     dues are settled itself.
 *
 * Testability: create_order() is the one method exercised by
 * tests/StripeGatewayTest.php without WordPress loaded at all. Every
 * Stripe call in this class goes through the private client() helper,
 * which returns whatever MyNJILGA_Stripe_Client was injected via the
 * constructor (tests) or, in production, the real one built from the
 * active MyNJILGA_Stripe_Connection. create_order() also accepts
 * collection_method / days_until_due / currency / footer / mode as
 * optional overrides in $context so a test can supply already-resolved
 * values instead of triggering MyNJILGA_Stripe_Connection's
 * WordPress-dependent settings lookups; production callers (which never
 * populate those keys) get the live settings exactly as before.
 */
class MyNJILGA_Stripe_Invoice_Gateway implements MyNJILGA_Invoice_Gateway {

    /** @var MyNJILGA_Stripe_Client|null Injected transport for tests; null means "use the live connection". */
    private $client;

    public function __construct( ?MyNJILGA_Stripe_Client $client = null ) {
        $this->client = $client;
    }

    /**
     * Transport for every Stripe call this class makes: the client
     * injected at construction if there is one, else the real client for
     * the active connection/mode. Null means there's nothing usable to
     * call Stripe with right now — every caller must handle that rather
     * than assume a client.
     */
    private function client(): ?MyNJILGA_Stripe_Client {
        if ( $this->client !== null ) {
            return $this->client;
        }
        return MyNJILGA_Stripe_Connection::client_for_mode();
    }

    // -------------------------------------------------------------------------
    // Identity / readiness
    // -------------------------------------------------------------------------

    public function name(): string {
        return 'Stripe';
    }

    /**
     * Cheap, LOCAL-only check — no network call. Whether Stripe is
     * actually reachable/healthy right now is readiness_errors()'s job.
     */
    public function is_available(): bool {
        try {
            return MyNJILGA_Stripe_Connection::is_connected() && MyNJILGA_Stripe_Connection::client_for_mode() !== null;
        } catch ( \Throwable $e ) {
            return false;
        }
    }

    public function readiness_errors(): array {
        try {
            return MyNJILGA_Stripe_Connection::health();
        } catch ( \Throwable $e ) {
            return [ $e->getMessage() ];
        }
    }

    // -------------------------------------------------------------------------
    // Customers — one per FIRM
    // -------------------------------------------------------------------------

    /**
     * @param array{contact_id:int,email:string,first_name:string,last_name:string,company_id?:int,company_name?:string} $billTo
     */
    public function find_or_create_customer( array $billTo ): ?string {
        try {
            $client = $this->client();
            if ( $client === null ) {
                return null;
            }

            $email       = (string) ( $billTo['email'] ?? '' );
            $contactId   = (int) ( $billTo['contact_id'] ?? 0 );
            $companyId   = (int) ( $billTo['company_id'] ?? 0 );
            $companyName = (string) ( $billTo['company_name'] ?? '' );

            if ( $companyId <= 0 ) {
                // Should not happen in the normal dues-invoicing flow — a
                // firm invoice always carries a company_id — but degrade
                // gracefully rather than crash: a one-off customer lookup
                // by email, no customer-map bookkeeping.
                return $this->find_or_create_customer_by_email( $client, $email, $billTo );
            }

            $mode   = MyNJILGA_Stripe_Connection::active_mode();
            $mapped = MyNJILGA_Stripe_Customer_Map::get( $companyId, $mode );

            if ( $mapped !== null ) {
                $getResp = $client->request( 'GET', '/customers/' . rawurlencode( $mapped ) );
                if ( $getResp['ok'] && empty( $getResp['body']['deleted'] ) ) {
                    return $this->sync_customer_identity( $client, $mapped, $getResp['body'], $email, $companyName );
                }
                // Deleted in Stripe, or the GET itself failed — the
                // mapping is stale either way; clear it and fall through
                // to create fresh rather than keep returning a dead id.
                MyNJILGA_Stripe_Customer_Map::delete( $companyId, $mode );
            }

            // No (valid) map row: search Stripe by our own metadata before
            // creating, so a re-provisioned site or a race between two
            // requests can't duplicate this firm's Customer.
            $searchResp = $client->request( 'GET', '/customers/search', [
                'query' => "metadata['njilga_company_id']:'" . $companyId . "'",
                'limit' => 1,
            ] );
            if ( $searchResp['ok'] && ! empty( $searchResp['body']['data'][0]['id'] ) ) {
                $found   = $searchResp['body']['data'][0];
                $foundId = (string) $found['id'];
                MyNJILGA_Stripe_Customer_Map::set( $companyId, $mode, $foundId );
                return $this->sync_customer_identity( $client, $foundId, $found, $email, $companyName );
            }

            $createResp = $client->request( 'POST', '/customers', [
                'name'        => $companyName,
                'email'       => $email,
                'description' => 'NJILGA member firm',
                'metadata'    => [
                    'njilga_company_id'       => $companyId,
                    'njilga_owner_contact_id' => $contactId,
                    'source'                  => 'my-njilga',
                ],
            ] );
            if ( ! $createResp['ok'] || empty( $createResp['body']['id'] ) ) {
                return null;
            }

            $newId = (string) $createResp['body']['id'];
            MyNJILGA_Stripe_Customer_Map::set( $companyId, $mode, $newId );
            return $newId;
        } catch ( \Throwable $e ) {
            return null;
        }
    }

    /**
     * Fallback path for find_or_create_customer() when no company_id is
     * available — a one-off lookup/create by email, bypassing the
     * customer-map table entirely (there is no firm to key it on).
     *
     * @param array<string,mixed> $billTo
     */
    /**
     * Escapes a value for safe interpolation into a single-quoted literal
     * in Stripe's Search Query Language (used by /v1/customers/search and
     * /v1/invoices/search) — a raw value containing a single quote could
     * otherwise break out of the intended field:'value' filter and widen
     * the search (e.g. via an injected boolean operator) to match records
     * beyond the one intended. Backslash first, then the quote itself, so
     * an existing backslash in the value isn't misread as escaping the
     * character that follows it.
     */
    private static function escape_search_value( string $value ): string {
        return str_replace( [ '\\', "'" ], [ '\\\\', "\\'" ], $value );
    }

    private function find_or_create_customer_by_email( MyNJILGA_Stripe_Client $client, string $email, array $billTo ): ?string {
        if ( $email === '' ) {
            return null;
        }

        $searchResp = $client->request( 'GET', '/customers/search', [
            'query' => "email:'" . self::escape_search_value( $email ) . "'",
            'limit' => 1,
        ] );
        if ( $searchResp['ok'] && ! empty( $searchResp['body']['data'][0]['id'] ) ) {
            return (string) $searchResp['body']['data'][0]['id'];
        }

        $name       = trim( (string) ( $billTo['first_name'] ?? '' ) . ' ' . (string) ( $billTo['last_name'] ?? '' ) );
        $createResp = $client->request( 'POST', '/customers', [
            'name'        => $name,
            'email'       => $email,
            'description' => 'NJILGA member firm',
            'metadata'    => [
                'njilga_owner_contact_id' => (int) ( $billTo['contact_id'] ?? 0 ),
                'source'                  => 'my-njilga',
            ],
        ] );
        if ( ! $createResp['ok'] || empty( $createResp['body']['id'] ) ) {
            return null;
        }
        return (string) $createResp['body']['id'];
    }

    /**
     * PATCHes a found/mapped Customer when its stored email or name has
     * drifted from what we have now, then returns its id either way.
     *
     * @param array<string,mixed> $body Customer object as returned by Stripe.
     */
    private function sync_customer_identity( MyNJILGA_Stripe_Client $client, string $customerId, array $body, string $email, string $companyName ): string {
        $drift = [];
        if ( $email !== '' && (string) ( $body['email'] ?? '' ) !== $email ) {
            $drift['email'] = $email;
        }
        if ( $companyName !== '' && (string) ( $body['name'] ?? '' ) !== $companyName ) {
            $drift['name'] = $companyName;
        }
        if ( ! empty( $drift ) ) {
            $client->request( 'POST', '/customers/' . rawurlencode( $customerId ), $drift );
        }
        return $customerId;
    }

    // -------------------------------------------------------------------------
    // Invoice creation — draft -> add_lines -> finalize
    // -------------------------------------------------------------------------

    /**
     * @param array<int,array<string,mixed>> $lineItems
     * @param array<string,mixed>            $context Free-form: dues_year, company_id, company_name,
     *                                                 invoice_row_id, invoice_kind, bill_to_contact_id (optional),
     *                                                 plus test-only overrides mode/collection_method/
     *                                                 days_until_due/currency/footer.
     * @return array{ok:bool,invoice_id?:string,invoice_number?:string,hosted_url?:string,pdf_url?:string,due_date?:string,error?:string}
     */
    public function create_order( string $customerId, array $lineItems, array $context ): array {
        try {
            $count = count( $lineItems );
            if ( $count > 250 ) {
                return [
                    'ok'    => false,
                    'error' => sprintf( 'Too many line items for one Stripe invoice (250 max) — %d given.', $count ),
                ];
            }

            $client = $this->client();
            if ( $client === null ) {
                return [ 'ok' => false, 'error' => 'Stripe is not connected.' ];
            }

            // Every one of these falls back to the live, WordPress-backed
            // setting only when $context doesn't already supply it — the
            // seam that lets tests/StripeGatewayTest.php exercise this
            // method with zero WordPress loaded.
            $mode             = isset( $context['mode'] ) ? (string) $context['mode'] : MyNJILGA_Stripe_Connection::active_mode();
            $collectionMethod = isset( $context['collection_method'] ) ? (string) $context['collection_method'] : (string) MyNJILGA_Stripe_Connection::setting( 'collection_method', 'send_invoice' );
            $daysUntilDue     = isset( $context['days_until_due'] ) ? (int) $context['days_until_due'] : (int) MyNJILGA_Stripe_Connection::setting( 'days_until_due', 30 );
            $currency         = isset( $context['currency'] ) ? (string) $context['currency'] : (string) MyNJILGA_Stripe_Connection::setting( 'currency', 'usd' );
            $footer           = isset( $context['footer'] ) ? (string) $context['footer'] : (string) MyNJILGA_Stripe_Connection::setting( 'footer', '' );

            $duesYear        = (int) ( $context['dues_year'] ?? 0 );
            $companyId       = (int) ( $context['company_id'] ?? 0 );
            $companyName     = (string) ( $context['company_name'] ?? '' );
            $invoiceKind     = (string) ( $context['invoice_kind'] ?? '' );
            $invoiceRowId    = (int) ( $context['invoice_row_id'] ?? 0 );
            $billToContactId = (int) ( $context['bill_to_contact_id'] ?? 0 );

            // Mirrors MyNJILGA_Dues_Snapshot::settles_dues() exactly:
            // assessment-only invoices don't settle membership.
            $settlesDues = $invoiceKind !== MyNJILGA_Dues_Snapshot::KIND_ASSESSMENT;

            $description = sprintf( '%d NJILGA Membership Dues — %s', $duesYear, $companyName );

            $createParams = [
                'customer'                       => $customerId,
                'collection_method'              => $collectionMethod,
                'days_until_due'                 => $daysUntilDue,
                // ALWAYS false regardless of the stored auto_advance
                // setting (a possible future toggle) — this plugin
                // controls finalization itself, via the explicit
                // /finalize call below.
                'auto_advance'                   => false,
                'pending_invoice_items_behavior' => 'exclude',
                'currency'                       => $currency,
                'description'                    => $description,
                'footer'                         => $footer,
                'payment_settings'               => [
                    'payment_method_types' => [ 'card', 'us_bank_account' ],
                ],
                'metadata'                        => [
                    'njilga_row_id'             => $invoiceRowId,
                    'njilga_company_id'         => $companyId,
                    'njilga_dues_year'          => $duesYear,
                    'njilga_invoice_kind'       => $invoiceKind,
                    'njilga_bill_to_contact_id' => $billToContactId,
                    'njilga_settles_dues'       => $settlesDues ? '1' : '0',
                    'source'                    => 'my-njilga',
                ],
            ];

            $idempotencyKey = sprintf( 'njilga-inv-%d-%d-%s', $invoiceRowId, $duesYear, $mode );

            $createResp = $client->request( 'POST', '/invoices', $createParams, [
                'idempotency_key' => $idempotencyKey,
            ] );
            if ( ! $createResp['ok'] ) {
                return [ 'ok' => false, 'error' => $createResp['error'] !== '' ? $createResp['error'] : 'Stripe declined to create the invoice.' ];
            }

            $invoiceId = (string) ( $createResp['body']['id'] ?? '' );
            if ( $invoiceId === '' ) {
                return [ 'ok' => false, 'error' => 'Stripe did not return an invoice id.' ];
            }

            // Draft invoice now exists in Stripe — every failure from here
            // on leaves an orphaned draft unless we clean it up (see
            // abandon_draft()). Every call from here on carries its own
            // idempotency key — MyNJILGA_Stripe_Client retries once on a
            // 5xx/transport failure with the SAME request body, and
            // without a key a retried add_lines call would silently
            // duplicate that chunk's line items (doubling the invoice
            // total) rather than being recognized as the same attempt.
            foreach ( array_chunk( $lineItems, 50 ) as $i => $chunk ) {
                $addResp = $client->request( 'POST', '/invoices/' . rawurlencode( $invoiceId ) . '/add_lines', [
                    'lines' => $this->to_stripe_lines( $chunk ),
                ], [
                    'idempotency_key' => $idempotencyKey . '-lines-' . $i,
                ] );
                if ( ! $addResp['ok'] ) {
                    return $this->abandon_draft( $client, $invoiceId, $addResp['error'] !== '' ? $addResp['error'] : 'Stripe rejected one or more invoice line items.' );
                }
            }

            $finalizeResp = $client->request( 'POST', '/invoices/' . rawurlencode( $invoiceId ) . '/finalize', [], [
                'idempotency_key' => $idempotencyKey . '-finalize',
            ] );
            if ( ! $finalizeResp['ok'] ) {
                return $this->abandon_draft( $client, $invoiceId, $finalizeResp['error'] !== '' ? $finalizeResp['error'] : 'Stripe could not finalize the invoice.' );
            }

            $body    = $finalizeResp['body'];
            $dueDate = '';
            if ( ! empty( $body['due_date'] ) ) {
                $dueDate = gmdate( 'Y-m-d', (int) $body['due_date'] );
            }

            return [
                'ok'               => true,
                'invoice_id'       => $invoiceId,
                'invoice_number'   => (string) ( $body['number'] ?? '' ),
                'hosted_url'       => (string) ( $body['hosted_invoice_url'] ?? '' ),
                'pdf_url'          => (string) ( $body['invoice_pdf'] ?? '' ),
                'due_date'         => $dueDate,
                // A freshly finalized invoice owes its full amount — without
                // this, amount_due_cents sits at the DB default of 0 until
                // the next reconcile/sync, which would make a same-day
                // "mark paid by check" clamp to a $0 balance.
                'amount_due_cents' => (int) ( $body['amount_due'] ?? 0 ),
            ];
        } catch ( \Throwable $e ) {
            return [ 'ok' => false, 'error' => $e->getMessage() ];
        }
    }

    /**
     * Maps MyNJILGA_Dues_Roster::line_items()'s shape onto Stripe's
     * add_lines params: [ 'amount' => ..., 'description' => ..., 'metadata' => [...] ] per line.
     *
     * @param array<int,array<string,mixed>> $lineItems
     * @return array<int,array<string,mixed>>
     */
    private function to_stripe_lines( array $lineItems ): array {
        $lines = [];
        foreach ( $lineItems as $item ) {
            $lineMeta = (array) ( $item['line_meta'] ?? [] );

            $metadata = [
                'njilga_contact_id' => (int) ( $lineMeta['contact_id'] ?? 0 ),
                'njilga_kind'       => (string) ( $lineMeta['kind'] ?? '' ),
            ];
            if ( isset( $lineMeta['category'] ) ) {
                $metadata['njilga_category'] = (string) $lineMeta['category'];
            }
            if ( isset( $lineMeta['tier'] ) ) {
                $metadata['njilga_tier'] = (string) $lineMeta['tier'];
            }
            if ( isset( $lineMeta['rank'] ) ) {
                $metadata['njilga_rank'] = (int) $lineMeta['rank'];
            }

            $lines[] = [
                // 'title' is printed verbatim — MyNJILGA_Dues_Roster
                // already builds exactly the label that should appear on
                // the invoice; this class must not reformat it.
                'amount'      => (int) ( $item['unit_price_cents'] ?? 0 ),
                'description' => (string) ( $item['title'] ?? '' ),
                'metadata'    => $metadata,
            ];
        }
        return $lines;
    }

    /**
     * A draft invoice that can't be fully built (a failed add_lines or
     * finalize call) is left in a half-built state — delete it (a draft,
     * never-finalized invoice can be deleted outright, unlike a finalized
     * one, which can only be voided) so failed creations don't leave
     * orphaned drafts accumulating in the Stripe account. A failed
     * cleanup itself must never mask the real error — it's appended, not
     * substituted.
     *
     * @return array{ok:false,error:string}
     */
    private function abandon_draft( MyNJILGA_Stripe_Client $client, string $invoiceId, string $error ): array {
        $deleteResp = $client->request( 'DELETE', '/invoices/' . rawurlencode( $invoiceId ) );
        if ( ! $deleteResp['ok'] ) {
            $error .= sprintf( ' Additionally, automatic cleanup of the orphaned draft invoice (%s) failed — it may need to be deleted manually in the Stripe Dashboard.', $invoiceId );
        }
        return [ 'ok' => false, 'error' => $error ];
    }

    // -------------------------------------------------------------------------
    // Status / lifecycle
    // -------------------------------------------------------------------------

    /**
     * @return array{status:string,stripe_status:string,amount_due_cents:int,amount_paid_cents:int,total_cents:int}|null
     */
    public function invoice_status( string $invoiceId ): ?array {
        try {
            $client = $this->client();
            if ( $client === null || $invoiceId === '' ) {
                return null;
            }

            $resp = $client->request( 'GET', '/invoices/' . rawurlencode( $invoiceId ), [], [
                'expand' => [ 'payment_intent' ],
            ] );
            if ( ! $resp['ok'] ) {
                return null;
            }

            $body         = $resp['body'];
            $stripeStatus = (string) ( $body['status'] ?? '' );
            $status       = $stripeStatus;

            // ACH-in-flight signal: Stripe leaves the invoice itself
            // 'open' while its payment_intent is 'processing'. This is a
            // best-effort read for a caller that polls invoice_status()
            // directly — the durable source of truth for processing_at
            // is the payment_intent.processing webhook event (a later
            // phase), which this method does not depend on or update.
            if ( $stripeStatus === 'open'
                && is_array( $body['payment_intent'] ?? null )
                && (string) ( $body['payment_intent']['status'] ?? '' ) === 'processing'
            ) {
                $status = 'processing';
            }

            return [
                'status'            => $status,
                'stripe_status'     => $stripeStatus,
                'amount_due_cents'  => (int) ( $body['amount_due'] ?? 0 ),
                'amount_paid_cents' => (int) ( $body['amount_paid'] ?? 0 ),
                'total_cents'       => (int) ( $body['total'] ?? 0 ),
            ];
        } catch ( \Throwable $e ) {
            return null;
        }
    }

    // -------------------------------------------------------------------------
    // Payment
    // -------------------------------------------------------------------------

    /**
     * @param callable(string,array<string,mixed>):void $callback
     */
    public function on_invoice_paid( callable $callback ): void {
        add_action( 'njilga_stripe_invoice_paid', function ( $invoiceId, $payment ) use ( $callback ) {
            $callback( (string) $invoiceId, (array) $payment );
        }, 10, 2 );
    }

    /**
     * @param array<string,mixed> $meta
     * @return array{ok:bool,error?:string}
     */
    public function mark_paid_out_of_band( string $invoiceId, array $meta ): array {
        try {
            $client = $this->client();
            if ( $client === null || $invoiceId === '' ) {
                return [ 'ok' => false, 'error' => 'Stripe is not connected.' ];
            }

            $keyMap   = [
                'payment_method'             => 'njilga_payment_method',
                'check_number'               => 'njilga_check_number',
                'check_date'                 => 'njilga_check_date',
                'recorded_by'                => 'njilga_recorded_by',
                // Stripe migration run 4 (Mark Paid by check/cash/wire):
                // the balance BEFORE this payment, i.e. exactly what this
                // out-of-band payment covers — never Stripe's own
                // cumulative amount_paid, which the webhook would
                // otherwise log and double-count against any prior
                // manually-recorded partial. See
                // MyNJILGA_Page_Invoicing::handle_mark_paid() and
                // class-stripe-webhook.php's handle_invoice_paid().
                'final_payment_amount_cents' => 'njilga_final_payment_amount_cents',
            ];
            $metadata = [];
            foreach ( $keyMap as $metaKey => $stripeKey ) {
                if ( isset( $meta[ $metaKey ] ) && $meta[ $metaKey ] !== '' ) {
                    $metadata[ $stripeKey ] = (string) $meta[ $metaKey ];
                }
            }

            // Derived from the actual metadata content, not just the
            // invoice id: a client-level retry of THIS SAME attempt (lost
            // response, transport blip) reuses the key and Stripe returns
            // its cached result instead of erroring — which matters here,
            // since a false "could not mark paid" after the pay call
            // actually succeeded would otherwise send staff back to Mark
            // Paid believing nothing happened. A genuinely different
            // later attempt (corrected check number/amount) gets a fresh
            // key rather than colliding with a stale one.
            $idempotencySuffix = md5( (string) wp_json_encode( $metadata ) );

            if ( ! empty( $metadata ) ) {
                $updateResp = $client->request( 'POST', '/invoices/' . rawurlencode( $invoiceId ), [ 'metadata' => $metadata ], [
                    'idempotency_key' => 'njilga-payoob-meta-' . $invoiceId . '-' . $idempotencySuffix,
                ] );
                if ( ! $updateResp['ok'] ) {
                    return [ 'ok' => false, 'error' => $updateResp['error'] !== '' ? $updateResp['error'] : 'Could not record payment metadata on the invoice.' ];
                }
            }

            // Marks the Stripe invoice paid_out_of_band and stops here.
            // This method deliberately does NOT call
            // MyNJILGA_Payment_Listener::settle() or grant any role/tag —
            // per this migration's design, the resulting invoice.paid
            // webhook event (a later phase) is the ONLY code path allowed
            // to trigger settlement. Do not "helpfully" add a direct
            // settle() call here: it would double-settle once the webhook
            // catches up, or settle before Stripe has actually confirmed
            // the pay call succeeded.
            $payResp = $client->request( 'POST', '/invoices/' . rawurlencode( $invoiceId ) . '/pay', [ 'paid_out_of_band' => true ], [
                'idempotency_key' => 'njilga-payoob-pay-' . $invoiceId . '-' . $idempotencySuffix,
            ] );
            if ( ! $payResp['ok'] ) {
                return [ 'ok' => false, 'error' => $payResp['error'] !== '' ? $payResp['error'] : 'Stripe could not mark the invoice paid.' ];
            }

            return [ 'ok' => true ];
        } catch ( \Throwable $e ) {
            return [ 'ok' => false, 'error' => $e->getMessage() ];
        }
    }

    /**
     * @return array{ok:bool,error?:string}
     */
    public function void_invoice( string $invoiceId ): array {
        try {
            $client = $this->client();
            if ( $client === null || $invoiceId === '' ) {
                return [ 'ok' => false, 'error' => 'Stripe is not connected.' ];
            }

            $resp = $client->request( 'POST', '/invoices/' . rawurlencode( $invoiceId ) . '/void', [], [
                'idempotency_key' => 'njilga-void-' . $invoiceId,
            ] );
            if ( ! $resp['ok'] ) {
                return [ 'ok' => false, 'error' => $resp['error'] !== '' ? $resp['error'] : 'Stripe could not void the invoice.' ];
            }
            return [ 'ok' => true ];
        } catch ( \Throwable $e ) {
            return [ 'ok' => false, 'error' => $e->getMessage() ];
        }
    }

    // -------------------------------------------------------------------------
    // Reconciliation — a later phase's only consumer
    // -------------------------------------------------------------------------

    /**
     * @return array<string,mixed>|null
     */
    public function fetch_invoice( string $invoiceId ): ?array {
        try {
            $client = $this->client();
            if ( $client === null || $invoiceId === '' ) {
                return null;
            }

            // Only expand what we're confident about (payment_intent).
            // Stripe's Payment Record model exposes off-Stripe payment
            // totals under some response shapes, but guessing at an
            // expand path for that here risks a 400 for no real benefit —
            // whatever the raw body naturally contains is merged in
            // below, and paid_off_stripe_cents falls back to 0 when
            // nothing recognizable is present.
            $resp = $client->request( 'GET', '/invoices/' . rawurlencode( $invoiceId ), [], [
                'expand' => [ 'payment_intent' ],
            ] );
            if ( ! $resp['ok'] ) {
                return null;
            }

            $body = $resp['body'];

            $paidOffStripe = 0;
            foreach ( [ 'amount_paid_off_stripe', 'amount_paid_out_of_band' ] as $key ) {
                if ( isset( $body[ $key ] ) ) {
                    $paidOffStripe = (int) $body[ $key ];
                    break;
                }
            }

            return array_merge( $body, [
                'status'                 => (string) ( $body['status'] ?? '' ),
                'stripe_status'          => (string) ( $body['status'] ?? '' ),
                'amount_due_cents'       => (int) ( $body['amount_due'] ?? 0 ),
                'amount_paid_cents'      => (int) ( $body['amount_paid'] ?? 0 ),
                'amount_remaining_cents' => (int) ( $body['amount_remaining'] ?? 0 ),
                'paid_off_stripe_cents'  => $paidOffStripe,
                'total_cents'            => (int) ( $body['total'] ?? 0 ),
            ] );
        } catch ( \Throwable $e ) {
            return null;
        }
    }

    /**
     * @return array{invoices:array<int,array<string,mixed>>,has_more:bool,next_starting_after:?string}
     */
    public function list_our_invoices( int $duesYear, ?string $startingAfter ): array {
        $empty = [ 'invoices' => [], 'has_more' => false, 'next_starting_after' => null ];
        try {
            $client = $this->client();
            if ( $client === null ) {
                return $empty;
            }

            // Stripe's search query syntax requires the literal single
            // quotes shown here around string values — do not
            // url-encode them ourselves, request()'s own param encoding
            // handles the whole query string as one value.
            $params = [
                'query' => "metadata['source']:'my-njilga' AND metadata['njilga_dues_year']:'" . $duesYear . "'",
                'limit' => 100,
            ];
            if ( $startingAfter !== null && $startingAfter !== '' ) {
                $params['starting_after'] = $startingAfter;
            }

            $resp = $client->request( 'GET', '/invoices/search', $params );
            if ( ! $resp['ok'] ) {
                return $empty;
            }

            $data = array_values( (array) ( $resp['body']['data'] ?? [] ) );
            $last = ! empty( $data ) ? end( $data ) : null;

            return [
                'invoices'            => $data,
                'has_more'            => (bool) ( $resp['body']['has_more'] ?? false ),
                'next_starting_after' => ( is_array( $last ) && isset( $last['id'] ) ) ? (string) $last['id'] : null,
            ];
        } catch ( \Throwable $e ) {
            return $empty;
        }
    }
}
