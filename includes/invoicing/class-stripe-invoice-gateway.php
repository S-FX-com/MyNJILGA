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
 *     performed anywhere in this class. For an invoice, the `invoice.paid`
 *     webhook is the ONLY code path allowed to call
 *     MyNJILGA_Payment_Listener::settle(), however it came to be paid —
 *     online, or closed out with Stripe's own "Mark as paid". The one
 *     other caller is an online join (MyNJILGA_Join_Fulfillment), and only
 *     once it has re-read its Checkout Session from Stripe as paid.
 *
 *   - Checkout Sessions (MyNJILGA_Checkout_Gateway) are the online join's
 *     way of taking a payment while the applicant waits — create, read,
 *     expire, and adopting the Customer a join's checkout made for a
 *     brand-new firm.
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
class MyNJILGA_Stripe_Invoice_Gateway implements MyNJILGA_Invoice_Gateway, MyNJILGA_Checkout_Gateway {

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

    /**
     * Same as client(), but for an explicit mode rather than whichever is
     * active — the checkout methods below act on a join pinned to the mode
     * it was started in.
     */
    private function client_for( string $mode ): ?MyNJILGA_Stripe_Client {
        if ( $this->client !== null ) {
            return $this->client;
        }
        return MyNJILGA_Stripe_Connection::client_for_mode( $mode );
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
                    return $this->sync_customer_identity( $client, $mapped, $getResp['body'], $email, $companyName, $contactId );
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
                return $this->sync_customer_identity( $client, $foundId, $found, $email, $companyName, $contactId );
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
     * PATCHes a found/mapped Customer when its stored email, name or
     * owner-contact metadata has drifted from what we have now, then
     * returns its id either way.
     *
     * @param array<string,mixed> $body   Customer object as returned by Stripe.
     * @param int                 $ownerContactId 0 when unknown — never written as 0 over a real value.
     */
    private function sync_customer_identity( MyNJILGA_Stripe_Client $client, string $customerId, array $body, string $email, string $companyName, int $ownerContactId = 0 ): string {
        $drift = [];
        if ( $email !== '' && (string) ( $body['email'] ?? '' ) !== $email ) {
            $drift['email'] = $email;
        }
        if ( $companyName !== '' && (string) ( $body['name'] ?? '' ) !== $companyName ) {
            $drift['name'] = $companyName;
        }
        // The firm keeps one Customer for life, so the contact billing for
        // it changes over the years — this is the metadata that would
        // otherwise still name whoever was Owner the day it was created.
        if ( $ownerContactId > 0 ) {
            $storedOwner = (int) ( $body['metadata']['njilga_owner_contact_id'] ?? 0 );
            if ( $storedOwner !== $ownerContactId ) {
                $drift['metadata'] = [ 'njilga_owner_contact_id' => $ownerContactId ];
            }
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
     *                                                 due_timestamp (optional — an explicit due date, which
     *                                                 replaces days_until_due), plus test-only overrides
     *                                                 mode/collection_method/days_until_due/currency/footer.
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
            // Stripe accepts days_until_due OR due_date on a send_invoice
            // invoice, never both — sending both is a 400. A caller that
            // knows the actual date (MyNJILGA_Invoice_Creator does: dues
            // fall due at year end) passes due_timestamp and wins; anyone
            // who doesn't still gets the rolling N-day window.
            $dueTimestamp     = isset( $context['due_timestamp'] ) ? (int) $context['due_timestamp'] : 0;
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

            if ( $dueTimestamp > 0 ) {
                $createParams['due_date'] = $dueTimestamp;
            } else {
                $createParams['days_until_due'] = $daysUntilDue;
            }

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
                'expand' => [ 'payment_intent.latest_charge' ],
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
    // Checkout (MyNJILGA_Checkout_Gateway) — online joins
    // -------------------------------------------------------------------------

    /**
     * Metadata `source` on everything a join creates in Stripe. Distinct
     * from create_order()'s 'my-njilga' on purpose: the reconciler's
     * orphan scan searches for 'my-njilga' invoices with no row here, and
     * a join's invoice legitimately has no row until the payment clears
     * (an ACH join can sit for days) — it must never read as an orphan.
     */
    const JOIN_SOURCE = 'my-njilga-join';

    /**
     * @param array<int,array<string,mixed>> $lineItems
     * @param array<string,mixed>            $context
     * @return array{ok:bool,session_id?:string,url?:string,expires_at?:int,ach_offered?:bool,error?:string}
     */
    public function create_checkout( array $lineItems, array $context ): array {
        try {
            $joinId = (int) ( $context['join_id'] ?? 0 );
            if ( $joinId <= 0 ) {
                return [ 'ok' => false, 'error' => 'A checkout needs the join it belongs to.' ];
            }
            if ( empty( $lineItems ) ) {
                return [ 'ok' => false, 'error' => 'Nothing to charge.' ];
            }
            // Stripe's own cap for payment-mode Checkout.
            if ( count( $lineItems ) > 100 ) {
                return [ 'ok' => false, 'error' => sprintf( 'Too many people for one checkout (100 max) — %d given.', count( $lineItems ) ) ];
            }

            $mode   = isset( $context['mode'] ) ? (string) $context['mode'] : MyNJILGA_Stripe_Connection::active_mode();
            $client = $this->client_for( $mode );
            if ( $client === null ) {
                return [ 'ok' => false, 'error' => 'Stripe is not connected.' ];
            }

            $currency = isset( $context['currency'] ) ? (string) $context['currency'] : (string) MyNJILGA_Stripe_Connection::setting( 'currency', 'usd' );
            $footer   = isset( $context['footer'] ) ? (string) $context['footer'] : (string) MyNJILGA_Stripe_Connection::setting( 'footer', '' );
            $attempt  = max( 1, (int) ( $context['attempt'] ?? 1 ) );
            $duesYear = (int) ( $context['dues_year'] ?? 0 );
            $company  = (string) ( $context['company_name'] ?? '' );
            $desc     = (string) ( $context['description'] ?? sprintf( '%d NJILGA Membership', $duesYear ) );

            $metadata = [
                'njilga_join_id'   => $joinId,
                'njilga_dues_year' => $duesYear,
                'source'           => self::JOIN_SOURCE,
            ];

            $lines = [];
            foreach ( $lineItems as $item ) {
                $lines[] = [
                    'price_data' => [
                        'currency'     => $currency,
                        // Checkout accepts $0 lines on this API version —
                        // a free "Members 6+" colleague stays on the
                        // receipt, same as on a dues invoice.
                        'unit_amount'  => max( 0, (int) ( $item['unit_price_cents'] ?? 0 ) ),
                        'product_data' => [ 'name' => mb_substr( (string) ( $item['title'] ?? 'NJILGA Membership' ), 0, 250 ) ],
                    ],
                    'quantity'   => max( 1, (int) ( $item['quantity'] ?? 1 ) ),
                ];
            }

            $params = [
                'mode'                => 'payment',
                'line_items'          => $lines,
                'customer_email'      => (string) ( $context['customer_email'] ?? '' ) !== '' ? (string) $context['customer_email'] : null,
                // A Customer is needed for ACH and for the post-payment
                // invoice either way; 'always' makes that explicit.
                'customer_creation'   => 'always',
                'client_reference_id' => 'njilga-join-' . $joinId,
                'success_url'         => (string) ( $context['success_url'] ?? '' ),
                'cancel_url'          => (string) ( $context['cancel_url'] ?? '' ),
                'submit_type'         => 'pay',
                'metadata'            => $metadata,
                'payment_intent_data' => [
                    'description' => mb_substr( $desc, 0, 1000 ),
                    'metadata'    => $metadata,
                ],
            ];

            if ( ! empty( $context['create_invoice'] ) ) {
                $invoiceData = [
                    'description' => mb_substr( $desc, 0, 1500 ),
                    'metadata'    => $metadata,
                    'footer'      => $footer !== '' ? mb_substr( $footer, 0, 5000 ) : null,
                ];
                if ( $company !== '' ) {
                    $invoiceData['custom_fields'] = [ [ 'name' => 'Firm', 'value' => mb_substr( $company, 0, 140 ) ] ];
                }
                $params['invoice_creation'] = [ 'enabled' => true, 'invoice_data' => $invoiceData ];
            }

            // A per-site salt: a staging copy sharing this Stripe test
            // account has the same join ids, and must never be handed this
            // site's session back by Stripe's idempotency cache.
            $salt = isset( $context['key_salt'] ) ? (string) $context['key_salt'] : self::key_salt();
            $key  = sprintf( 'njilga-join-%s-%d-%d-%s', $salt, $joinId, $attempt, $mode );

            $allowAch = ! empty( $context['allow_ach'] );
            $params['payment_method_types'] = $allowAch ? [ 'card', 'us_bank_account' ] : [ 'card' ];
            $resp = $client->request( 'POST', '/checkout/sessions', $params, [ 'idempotency_key' => $key . ( $allowAch ? '' : '-card' ) ] );

            // An account without ACH activated rejects the whole session
            // when us_bank_account is requested — take the card-only
            // session rather than turn the applicant away. A different
            // body needs a different idempotency key, or Stripe refuses it.
            if ( ! $resp['ok'] && $allowAch && stripos( $resp['error'], 'us_bank_account' ) !== false ) {
                $allowAch = false;
                $params['payment_method_types'] = [ 'card' ];
                $resp = $client->request( 'POST', '/checkout/sessions', $params, [ 'idempotency_key' => $key . '-card' ] );
            }

            if ( ! $resp['ok'] || empty( $resp['body']['id'] ) || empty( $resp['body']['url'] ) ) {
                return [ 'ok' => false, 'error' => $resp['error'] !== '' ? $resp['error'] : 'Stripe did not return a checkout page.' ];
            }

            return [
                'ok'          => true,
                'session_id'  => (string) $resp['body']['id'],
                'url'         => (string) $resp['body']['url'],
                'expires_at'  => (int) ( $resp['body']['expires_at'] ?? 0 ),
                'ach_offered' => $allowAch,
            ];
        } catch ( \Throwable $e ) {
            return [ 'ok' => false, 'error' => $e->getMessage() ];
        }
    }

    /**
     * Random, per-site, generated once.
     */
    private static function key_salt(): string {
        $salt = (string) get_option( 'njilga_join_key_salt', '' );
        if ( $salt === '' ) {
            $salt = bin2hex( random_bytes( 6 ) );
            add_option( 'njilga_join_key_salt', $salt, '', false );
            $salt = (string) get_option( 'njilga_join_key_salt', $salt );
        }
        return $salt;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function fetch_checkout( string $sessionId, string $mode ): ?array {
        try {
            $client = $this->client_for( $mode );
            if ( $client === null || $sessionId === '' ) {
                return null;
            }
            $resp = $client->request( 'GET', '/checkout/sessions/' . rawurlencode( $sessionId ), [], [
                'expand' => [ 'payment_intent.latest_charge', 'invoice' ],
            ] );
            if ( ! $resp['ok'] || empty( $resp['body']['id'] ) ) {
                return null;
            }
            return self::normalize_checkout( $resp['body'] );
        } catch ( \Throwable $e ) {
            return null;
        }
    }

    /**
     * The normalized shape fetch_checkout() returns — pure, so the join
     * fulfillment tests can feed it a raw session fixture.
     *
     * @param array<string,mixed> $s A raw Checkout Session object.
     * @return array<string,mixed>
     */
    public static function normalize_checkout( array $s ): array {
        $pi      = $s['payment_intent'] ?? null;
        $invoice = $s['invoice'] ?? null;
        $cust    = $s['customer'] ?? null;
        return [
            'id'                  => (string) ( $s['id'] ?? '' ),
            'status'              => (string) ( $s['status'] ?? '' ),
            'payment_status'      => (string) ( $s['payment_status'] ?? '' ),
            'amount_total'        => (int) ( $s['amount_total'] ?? 0 ),
            'currency'            => strtolower( (string) ( $s['currency'] ?? '' ) ),
            'livemode'            => ! empty( $s['livemode'] ),
            'customer'            => is_array( $cust ) ? (string) ( $cust['id'] ?? '' ) : (string) $cust,
            'customer_email'      => (string) ( $s['customer_details']['email'] ?? ( $s['customer_email'] ?? '' ) ),
            'metadata'            => is_array( $s['metadata'] ?? null ) ? $s['metadata'] : [],
            'client_reference_id' => (string) ( $s['client_reference_id'] ?? '' ),
            'url'                 => (string) ( $s['url'] ?? '' ),
            'expires_at'          => (int) ( $s['expires_at'] ?? 0 ),
            'payment_intent_id'   => is_array( $pi ) ? (string) ( $pi['id'] ?? '' ) : (string) $pi,
            'payment_intent'      => is_array( $pi ) ? $pi : null,
            'invoice_id'          => is_array( $invoice ) ? (string) ( $invoice['id'] ?? '' ) : (string) $invoice,
            'invoice'             => is_array( $invoice ) ? $invoice : null,
        ];
    }

    /**
     * @return array{ok:bool,status?:string,error?:string}
     */
    public function expire_checkout( string $sessionId, string $mode ): array {
        try {
            $client = $this->client_for( $mode );
            if ( $client === null || $sessionId === '' ) {
                return [ 'ok' => false, 'error' => 'Stripe is not connected.' ];
            }
            $resp = $client->request( 'POST', '/checkout/sessions/' . rawurlencode( $sessionId ) . '/expire' );
            if ( $resp['ok'] ) {
                return [ 'ok' => true, 'status' => (string) ( $resp['body']['status'] ?? 'expired' ) ];
            }
            // Only an OPEN session can be expired. Whatever it is now, say
            // so — 'complete' means it may already have been paid, and the
            // caller must not start a second one on top of it.
            $current = $this->fetch_checkout( $sessionId, $mode );
            return [
                'ok'     => false,
                'status' => $current ? (string) $current['status'] : '',
                'error'  => $resp['error'] !== '' ? $resp['error'] : 'Stripe could not expire the checkout.',
            ];
        } catch ( \Throwable $e ) {
            return [ 'ok' => false, 'error' => $e->getMessage() ];
        }
    }

    public function adopt_customer_for_company( string $customerId, int $companyId, string $companyName, int $ownerContactId, string $mode ): bool {
        try {
            if ( $customerId === '' || $companyId <= 0 ) {
                return false;
            }
            // A firm keeps one Customer for life — never repoint one that
            // already has it.
            if ( MyNJILGA_Stripe_Customer_Map::get( $companyId, $mode ) !== null ) {
                return false;
            }
            $client = $this->client_for( $mode );
            if ( $client === null ) {
                return false;
            }
            $resp = $client->request( 'POST', '/customers/' . rawurlencode( $customerId ), [
                'name'        => $companyName !== '' ? $companyName : null,
                'description' => 'NJILGA member firm',
                'metadata'    => [
                    'njilga_company_id'       => $companyId,
                    'njilga_owner_contact_id' => $ownerContactId,
                    'source'                  => 'my-njilga',
                ],
            ] );
            if ( ! $resp['ok'] ) {
                return false;
            }
            MyNJILGA_Stripe_Customer_Map::set( $companyId, $mode, $customerId );
            return true;
        } catch ( \Throwable $e ) {
            return false;
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

            // payment_intent WITH its latest_charge: on this API version a
            // PaymentIntent no longer carries `charges`, and latest_charge
            // is a bare id unless expanded — without it the reconciler
            // keys a missed payment by the PaymentIntent id while the
            // webhook keys the same payment by its charge id, and the
            // ledger's duplicate guard never sees them as one payment.
            // Off-Stripe payment totals are deliberately NOT read back
            // from Stripe: there is no dependable field for them on the
            // Invoice object, and the plugin already knows that number
            // exactly — it writes paid_off_stripe_cents itself when staff
            // record a check or wire.
            $resp = $client->request( 'GET', '/invoices/' . rawurlencode( $invoiceId ), [], [
                'expand' => [ 'payment_intent.latest_charge' ],
            ] );
            if ( ! $resp['ok'] ) {
                return null;
            }

            $body = $resp['body'];

            return array_merge( $body, [
                'status'                 => (string) ( $body['status'] ?? '' ),
                'stripe_status'          => (string) ( $body['status'] ?? '' ),
                'amount_due_cents'       => (int) ( $body['amount_due'] ?? 0 ),
                'amount_paid_cents'      => (int) ( $body['amount_paid'] ?? 0 ),
                'amount_remaining_cents' => (int) ( $body['amount_remaining'] ?? 0 ),
                'total_cents'            => (int) ( $body['total'] ?? 0 ),
            ] );
        } catch ( \Throwable $e ) {
            return null;
        }
    }

    /**
     * @return array{ok:bool,invoices:array<int,array<string,mixed>>,has_more:bool,next_cursor:?string}
     */
    public function list_our_invoices( int $duesYear, ?string $cursor ): array {
        // ok:false, never an empty page: a caller comparing what Stripe
        // holds against what we hold MUST be able to tell "Stripe says
        // there is nothing" from "Stripe didn't answer", or a rate limit
        // reads as a clean bill of health.
        $empty = [ 'ok' => false, 'invoices' => [], 'has_more' => false, 'next_cursor' => null ];
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
            // Search pages with an opaque `page` token echoed back as
            // `next_page` — NOT with the `starting_after` object id that
            // Stripe's plain list endpoints use. Sending starting_after
            // here is silently ignored, which would re-request page one
            // forever.
            if ( $cursor !== null && $cursor !== '' ) {
                $params['page'] = $cursor;
            }

            $resp = $client->request( 'GET', '/invoices/search', $params );
            if ( ! $resp['ok'] ) {
                return $empty;
            }

            $data     = array_values( (array) ( $resp['body']['data'] ?? [] ) );
            $nextPage = isset( $resp['body']['next_page'] ) ? (string) $resp['body']['next_page'] : '';

            return [
                'ok'          => true,
                'invoices'    => $data,
                'has_more'    => (bool) ( $resp['body']['has_more'] ?? false ),
                'next_cursor' => $nextPage !== '' ? $nextPage : null,
            ];
        } catch ( \Throwable $e ) {
            return $empty;
        }
    }
}
