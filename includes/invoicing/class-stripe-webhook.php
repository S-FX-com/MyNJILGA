<?php
/**
 * Stripe webhook receiver (Stripe migration phase 3) — the only path by
 * which a Stripe payment fact ever reaches WordPress in near-real-time.
 * A later phase's reconciler is the backstop for anything this endpoint
 * misses (a delivery that never arrives, a worker that never runs it).
 *
 * Two halves, deliberately separated:
 *
 *   - verify_signature() is PURE, WordPress-free string/hash logic —
 *     independently unit tested by tests/WebhookSignatureTest.php
 *     without any WordPress bootstrap. It must never call a WordPress
 *     function; every other method in this class may (and does), but
 *     only inside its own body, never at file-load time, so this file
 *     stays require()-able on its own.
 *
 *   - register()/handle()/process_event() are the actual REST endpoint:
 *     handle() authenticates the request BY SIGNATURE (never WordPress
 *     auth — this is Stripe's own model for webhook endpoints, see
 *     permission_callback below) and hands trusted events off to
 *     process_event(), either via an Action Scheduler background job
 *     (mirroring MyNJILGA_Invoice_Creator's schedule()/run_chunk()
 *     fallback pattern exactly) or inline when Action Scheduler isn't
 *     present, so Stripe always gets a prompt 2xx.
 *
 * Idempotency lives in MyNJILGA_Stripe_Events_Table: the same Stripe
 * event can (and does) arrive more than once, and record_received()
 * is the gate that makes a duplicate delivery a fast, harmless 200.
 *
 * This class never decides membership is settled — it only records
 * payment facts and fires the plugin-owned `njilga_stripe_invoice_paid`
 * action (see MyNJILGA_Stripe_Invoice_Gateway::on_invoice_paid()) for
 * MyNJILGA_Payment_Listener to act on, same as every other gateway path.
 */
class MyNJILGA_Stripe_Webhook {

    /**
     * Ledger `reference` for an invoice closed out with Stripe's own
     * "Mark as paid" — Stripe tells us nothing about how it was actually
     * paid, so this says where the record came from instead of implying
     * a cheque number nobody typed.
     */
    const MARKED_PAID_IN_STRIPE = 'Marked paid in Stripe';

    const HOOK_PROCESS = 'njilga_stripe_process_webhook_event';
    const AS_GROUP      = 'njilga-dues';

    // Stripe's own replay-protection window: a correctly-signed payload
    // whose t= is older/newer than this many seconds is rejected anyway.
    const SIGNATURE_TOLERANCE_SECONDS = 300;

    // -------------------------------------------------------------------------
    // PART A — signature verification (pure, no WordPress calls)
    // -------------------------------------------------------------------------

    /**
     * Verifies a Stripe-Signature header against $payload/$secret. Pure
     * string/hash logic only — no WordPress function is called here, so
     * this method (and only this method) is safe to unit test without
     * any WordPress bootstrap at all.
     *
     * Parses every `t=` and `v1=` entry out of $sigHeader (there can be
     * more than one `v1=` during a Stripe signing-secret rotation
     * window — ANY matching v1 is accepted) and deliberately ignores any
     * `v0=` scheme entirely: it exists only for Stripe's own test-event
     * tooling, and accepting it would be a signature-downgrade vector.
     *
     * @param string   $payload   Raw request body bytes, exactly as received.
     * @param string   $sigHeader The raw Stripe-Signature header value.
     * @param string   $secret    This mode's webhook signing secret.
     * @param int|null $now       Defaults to time() — always pass an explicit
     *                            value in tests for a deterministic clock.
     */
    public static function verify_signature( string $payload, string $sigHeader, string $secret, ?int $now = null ): bool {
        $now = $now ?? time();

        $timestamp = null;
        $v1Sigs    = [];

        foreach ( explode( ',', $sigHeader ) as $part ) {
            $part = trim( $part );
            if ( $part === '' ) {
                continue;
            }
            $eq = strpos( $part, '=' );
            if ( $eq === false ) {
                continue;
            }
            $key   = substr( $part, 0, $eq );
            $value = substr( $part, $eq + 1 );

            if ( $key === 't' ) {
                $timestamp = $value;
            } elseif ( $key === 'v1' ) {
                $v1Sigs[] = $value;
            }
            // v0= is deliberately never read — see docblock.
        }

        if ( $timestamp === null || $timestamp === '' || empty( $v1Sigs ) ) {
            return false;
        }

        $t = (int) $timestamp;

        // Replay protection: rejected regardless of whether any v1
        // matches, so a stale-but-correctly-signed payload still fails.
        if ( abs( $now - $t ) > self::SIGNATURE_TOLERANCE_SECONDS ) {
            return false;
        }

        $expected = hash_hmac( 'sha256', $t . '.' . $payload, $secret );
        foreach ( $v1Sigs as $sig ) {
            if ( hash_equals( $expected, $sig ) ) {
                return true;
            }
        }
        return false;
    }

    // -------------------------------------------------------------------------
    // PART B — REST route
    // -------------------------------------------------------------------------

    public static function register(): void {
        add_action( 'rest_api_init', static function () {
            register_rest_route( 'njilga/v1', '/stripe-webhook', [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'handle' ],
                // Authenticated by Stripe-Signature, not WordPress auth —
                // this IS Stripe's own model for webhook endpoints.
                'permission_callback' => '__return_true',
            ] );
        } );

        // So the Action Scheduler worker (a separate request entirely)
        // can find this callback, mirror MyNJILGA_Invoice_Creator::register().
        add_action( self::HOOK_PROCESS, [ __CLASS__, 'process_event' ], 10, 1 );
    }

    /**
     * The REST callback. Order matters — the raw body must be read
     * before anything else touches the request, and every check below
     * is deliberately sequenced signature-first so nothing about an
     * unverified payload is trusted a moment longer than necessary.
     */
    public static function handle( WP_REST_Request $request ): WP_REST_Response {
        // 1. Raw bytes — never the parsed/decoded params, any
        // re-serialization would break the signature.
        $rawBody = $request->get_body();

        // 2.
        $sigHeader = $request->get_header( 'stripe-signature' );
        $sigHeader = $sigHeader !== null ? (string) $sigHeader : '';
        if ( $sigHeader === '' ) {
            return new WP_REST_Response( [ 'error' => 'Missing signature.' ], 400 );
        }

        // 3. Peek at the event's own livemode flag to pick which secret
        // to verify against — not yet trusting the payload; the actual
        // cryptographic check happens against the raw bytes below
        // regardless of what's read here.
        $event = json_decode( $rawBody, true );
        if ( ! is_array( $event ) ) {
            return new WP_REST_Response( [ 'error' => 'Malformed payload.' ], 400 );
        }

        // 4.
        $livemode = ! empty( $event['livemode'] );
        $mode     = $livemode ? MyNJILGA_Stripe_Connection::MODE_LIVE : MyNJILGA_Stripe_Connection::MODE_TEST;

        $secret = MyNJILGA_Stripe_Connection::decrypted_webhook_secret( $mode );
        if ( $secret === null || $secret === '' ) {
            return new WP_REST_Response( [ 'error' => 'Webhook is not configured.' ], 400 );
        }

        // 5. Generic failure message — never leak WHY verification
        // failed on an unauthenticated endpoint.
        if ( ! self::verify_signature( $rawBody, $sigHeader, $secret ) ) {
            return new WP_REST_Response( [ 'error' => 'Signature verification failed.' ], 400 );
        }

        // 6. The payload is trusted from here on.
        $eventId    = (string) ( $event['id'] ?? '' );
        $type       = (string) ( $event['type'] ?? '' );
        $dataObject = ( isset( $event['data']['object'] ) && is_array( $event['data']['object'] ) ) ? $event['data']['object'] : [];
        $objectId   = isset( $dataObject['id'] ) ? (string) $dataObject['id'] : null;

        if ( $eventId === '' || $type === '' ) {
            return new WP_REST_Response( [ 'error' => 'Malformed event.' ], 400 );
        }

        // 7. Idempotency gate — a duplicate delivery is a fast 200, nothing
        // more. A database failure is NOT a duplicate: answering 200 would
        // lose the event (Stripe never resends an acknowledged delivery),
        // so it gets a 503 and Stripe retries with backoff.
        $recorded = MyNJILGA_Stripe_Events_Table::record( $eventId, $type, $livemode, $objectId );
        if ( $recorded === MyNJILGA_Stripe_Events_Table::RECORD_DUPLICATE ) {
            return new WP_REST_Response( [ 'ok' => true ], 200 );
        }
        if ( $recorded !== MyNJILGA_Stripe_Events_Table::RECORD_NEW ) {
            return new WP_REST_Response( [ 'error' => 'Could not record the event — please retry.' ], 503 );
        }

        // 8. Dispatch asynchronously so this response returns fast —
        // Stripe expects a prompt 2xx and retries for up to 3 days
        // otherwise. Mirrors MyNJILGA_Invoice_Creator::schedule()'s
        // exact Action-Scheduler-else-inline fallback.
        if ( function_exists( 'as_enqueue_async_action' ) ) {
            as_enqueue_async_action( self::HOOK_PROCESS, [ 'event' => $event ], self::AS_GROUP );
        } else {
            self::process_event( $event );
        }

        // 9.
        return new WP_REST_Response( [ 'ok' => true ], 200 );
    }

    // -------------------------------------------------------------------------
    // Event processing — run either from an Action Scheduler job or inline
    // -------------------------------------------------------------------------

    /**
     * @param array<string,mixed> $event Full decoded Stripe Event object.
     */
    public static function process_event( array $event ): void {
        $eventId = (string) ( $event['id'] ?? '' );
        try {
            $type       = (string) ( $event['type'] ?? '' );
            $livemode   = ! empty( $event['livemode'] );
            $dataObject = ( isset( $event['data']['object'] ) && is_array( $event['data']['object'] ) ) ? $event['data']['object'] : [];

            switch ( $type ) {
                case 'invoice.paid':
                    self::handle_invoice_paid( $eventId, $dataObject, $livemode );
                    break;
                case 'payment_intent.processing':
                    self::handle_payment_intent_processing( $eventId, $dataObject );
                    break;
                case 'invoice.payment_failed':
                    self::handle_invoice_payment_failed( $eventId, $dataObject );
                    break;
                case 'invoice.payment_action_required':
                    self::handle_invoice_payment_action_required( $eventId, $dataObject );
                    break;
                case 'invoice.finalized':
                    self::handle_invoice_finalized( $eventId, $dataObject );
                    break;
                case 'invoice.voided':
                    self::handle_invoice_voided( $eventId, $dataObject );
                    break;
                case 'invoice.marked_uncollectible':
                    self::handle_invoice_uncollectible( $eventId, $dataObject );
                    break;
                case 'invoice.sent':
                    self::handle_invoice_sent( $eventId, $dataObject );
                    break;
                case 'invoice.overpaid':
                    self::handle_invoice_overpaid( $eventId, $dataObject );
                    break;
                case 'charge.refunded':
                    self::handle_charge_refunded( $eventId, $dataObject, $livemode );
                    break;
                case 'credit_note.created':
                    self::handle_credit_note_created( $eventId, $dataObject, $livemode );
                    break;
                case 'customer.deleted':
                    self::handle_customer_deleted( $eventId, $dataObject, $livemode );
                    break;
                case 'checkout.session.completed':
                case 'checkout.session.async_payment_succeeded':
                case 'checkout.session.async_payment_failed':
                case 'checkout.session.expired':
                    // Online joins (includes/join/) — the only Checkout
                    // Sessions this plugin creates.
                    $joinId = MyNJILGA_Join_Fulfillment::handle_checkout_event( $type, $dataObject, $livemode );
                    if ( $joinId === null ) {
                        self::finish_ignored( $eventId, null, 'No matching online join (or a superseded checkout).' );
                    } else {
                        self::finish_processed( $eventId, self::join_row_id( $joinId ) );
                    }
                    break;
                case 'charge.dispute.created':
                    self::handle_dispute_created( $eventId, $dataObject );
                    break;
                default:
                    // Subscribed-list drift (an event type we don't
                    // (yet) have a handler for) — ack, don't error.
                    self::finish_ignored( $eventId, null, 'Unhandled event type.' );
            }
        } catch ( \Throwable $e ) {
            $row = MyNJILGA_Stripe_Events_Table::get_by_event_id( $eventId );
            if ( $row ) {
                MyNJILGA_Stripe_Events_Table::mark_processed( (int) $row->id, MyNJILGA_Stripe_Events_Table::STATUS_FAILED, $e->getMessage() );
            }
        }
    }

    // -------------------------------------------------------------------------
    // PART C — row resolution
    // -------------------------------------------------------------------------

    /**
     * Resolves the njilga_dues_invoices row an event concerns: first
     * metadata.njilga_row_id on the event's own object (present directly
     * on an Invoice; may or may not be copied onto a derived
     * Charge/PaymentIntent/CreditNote object by Stripe), then a
     * per-event-type fallback to the object's own reference to its
     * invoice. customer.deleted is handled entirely separately and never
     * reaches here.
     *
     * @param array<string,mixed> $dataObject
     * @return object|null
     */
    private static function resolve_invoice_row( string $type, array $dataObject ) {
        $rowId = 0;
        if ( isset( $dataObject['metadata']['njilga_row_id'] ) && (string) $dataObject['metadata']['njilga_row_id'] !== '' ) {
            $rowId = (int) $dataObject['metadata']['njilga_row_id'];
        }
        if ( $rowId > 0 ) {
            $row = MyNJILGA_Dues_Invoice_Table::get( $rowId );
            if ( $row ) {
                return $row;
            }
        }

        $invoiceId = '';
        if ( strpos( $type, 'invoice.' ) === 0 ) {
            // invoice.* events: data.object.id IS the invoice id directly.
            $invoiceId = (string) ( $dataObject['id'] ?? '' );
        } elseif ( in_array( $type, [ 'payment_intent.processing', 'charge.refunded', 'credit_note.created', 'charge.dispute.created' ], true ) ) {
            // Each carries an 'invoice' field pointing back at it — may be
            // a bare id string or an expanded object depending on account
            // expansion settings.
            $invoiceId = self::extract_ref_id( $dataObject['invoice'] ?? null );
        }

        $row = $invoiceId !== '' ? MyNJILGA_Dues_Invoice_Table::get_by_order_id( $invoiceId ) : null;
        if ( $row ) {
            return $row;
        }

        // An online join's own invoice (Checkout's post-payment invoice)
        // names the join, and the join knows its row.
        $joinId = (int) ( $dataObject['metadata']['njilga_join_id'] ?? 0 );
        if ( $joinId > 0 ) {
            $rowId = self::join_row_id( $joinId );
            if ( $rowId ) {
                return MyNJILGA_Dues_Invoice_Table::get( $rowId );
            }
        }

        // An online join's charge points at no invoice of ours (Checkout
        // made the payment first, the invoice after) — but its
        // PaymentIntent is on the join, and the join knows its row.
        $piId = self::extract_ref_id( $dataObject['payment_intent'] ?? null );
        if ( $piId === '' && strpos( (string) ( $dataObject['id'] ?? '' ), 'pi_' ) === 0 ) {
            $piId = (string) $dataObject['id'];
        }
        if ( $piId !== '' ) {
            $join = MyNJILGA_Join_Orders_Table::get_by_payment_intent( $piId );
            if ( $join && (int) $join->invoice_row_id > 0 ) {
                return MyNJILGA_Dues_Invoice_Table::get( (int) $join->invoice_row_id );
            }
        }
        return null;
    }

    private static function join_row_id( int $joinId ): ?int {
        $join = MyNJILGA_Join_Orders_Table::get( $joinId );
        return ( $join && (int) $join->invoice_row_id > 0 ) ? (int) $join->invoice_row_id : null;
    }

    /**
     * For the handlers that change a row's status: an online join's row is
     * written already paid, and its status is the join's to decide
     * (MyNJILGA_Join_Fulfillment), never an event's. Stripe doesn't
     * guarantee event order and the return page usually fulfills first,
     * so a late payment_intent.processing (or a void of an invoice
     * Checkout already marked paid) would flip a paid join back to
     * processing/voided — dropping its members out of the downgrade
     * sweep's protection and the preview's "paid via online join", and
     * the reconciler skips join rows, so nothing would ever put it back.
     * Such an event is recorded as ignored against the row instead.
     */
    private static function skip_join_row_status( string $eventId, object $row ): bool {
        if ( (string) ( $row->invoice_kind ?? '' ) !== MyNJILGA_Dues_Snapshot::KIND_JOIN ) {
            return false;
        }
        MyNJILGA_Dues_Invoice_Table::update_gateway_fields( (int) $row->id, [ 'last_synced_at' => current_time( 'mysql' ) ] );
        self::finish_ignored( $eventId, (int) $row->id, 'Online join — its status is set by the join, not by this event.' );
        return true;
    }

    /**
     * A dispute — for ACH, also how a debit that bounced AFTER succeeding
     * arrives. Same posture as a refund: flagged for a human, nothing
     * revoked automatically.
     *
     * @param array<string,mixed> $dataObject The Dispute object.
     */
    private static function handle_dispute_created( string $eventId, array $dataObject ): void {
        $row = self::resolve_invoice_row( 'charge.dispute.created', $dataObject );
        if ( ! $row ) {
            $rowId = MyNJILGA_Dues_Payments_Table::invoice_row_for_object( self::extract_ref_id( $dataObject['charge'] ?? null ) );
            $row   = $rowId > 0 ? MyNJILGA_Dues_Invoice_Table::get( $rowId ) : null;
        }
        if ( ! $row ) {
            self::finish_ignored( $eventId, null );
            return;
        }

        $message = sprintf(
            'Payment disputed (%s, %s) on %s — review membership; nothing was changed automatically.',
            str_replace( '_', ' ', (string) ( $dataObject['reason'] ?? 'unknown reason' ) ),
            MyNJILGA_Invoicing::money( (int) ( $dataObject['amount'] ?? 0 ) ),
            current_time( 'Y-m-d' )
        );
        MyNJILGA_Dues_Invoice_Table::update_gateway_fields( (int) $row->id, [
            'last_error'     => $message,
            'last_synced_at' => current_time( 'mysql' ),
        ] );
        self::flag_join( $row, $dataObject, $message );

        self::finish_processed( $eventId, (int) $row->id );
    }

    /**
     * Put a refund, credit-note or dispute note on the online join behind
     * a row as well. Staff work joins from Applications → Online joins,
     * which reads the join record (the Invoicing page keeps join rows out
     * of its attention counts), so a note left only on the row would
     * reach no-one.
     *
     * @param array<string,mixed> $dataObject The event's object (for its PaymentIntent).
     */
    private static function flag_join( object $row, array $dataObject, string $message ): void {
        $joinId = (string) ( $row->invoice_kind ?? '' ) === MyNJILGA_Dues_Snapshot::KIND_JOIN
            ? (int) ( MyNJILGA_Dues_Snapshot::decode( $row )['join_id'] ?? 0 )
            : 0;
        $join = $joinId > 0 ? MyNJILGA_Join_Orders_Table::get( $joinId ) : null;
        if ( ! $join ) {
            $piId = self::extract_ref_id( $dataObject['payment_intent'] ?? null );
            $join = $piId !== '' ? MyNJILGA_Join_Orders_Table::get_by_payment_intent( $piId ) : null;
        }
        if ( $join ) {
            MyNJILGA_Join_Orders_Table::update( (int) $join->id, [ 'last_error' => $message ] );
        }
    }

    /**
     * @param mixed $value A Stripe reference field: null, a bare id string, or an expanded object.
     */
    private static function extract_ref_id( $value ): string {
        if ( is_array( $value ) ) {
            return isset( $value['id'] ) ? (string) $value['id'] : '';
        }
        return $value !== null ? (string) $value : '';
    }

    // -------------------------------------------------------------------------
    // PART C — per-event-type handlers
    // -------------------------------------------------------------------------

    /**
     * @param array<string,mixed> $dataObject The Invoice object.
     */
    /**
     * How much money ONE out-of-band settlement actually moved.
     *
     * Stripe's amount_paid is CUMULATIVE for the invoice, so it is the
     * wrong number the moment any of the balance was already recorded
     * here: a $100 check logged in the admin followed by "Mark as paid"
     * in the Stripe Dashboard would otherwise book the full $200 again
     * on top of the $100, and the firm would show as having paid $300.
     * The balance still outstanding on our row immediately before the
     * event is exactly what this payment covered.
     *
     * Falls back to Stripe's figure only when we have no balance on file
     * to reason from (a row whose amounts were never populated).
     *
     * @param int $localBalanceCents  amount_due_cents on the row before this event.
     * @param int $stripeAmountPaid   The invoice's cumulative amount_paid.
     */
    public static function off_stripe_amount_cents( int $localBalanceCents, int $stripeAmountPaid ): int {
        return $localBalanceCents > 0 ? $localBalanceCents : max( 0, $stripeAmountPaid );
    }

    private static function handle_invoice_paid( string $eventId, array $dataObject, bool $livemode ): void {
        // An online join's post-payment invoice is settled by the join's
        // own fulfillment and nowhere else — this generic path would book
        // the same money a second time (and Stripe may mark a Checkout
        // invoice paid out of band, which below reads as a cheque).
        if ( ! empty( $dataObject['metadata']['njilga_join_id'] ) ) {
            $joinId = MyNJILGA_Join_Fulfillment::handle_invoice_paid( $dataObject, $livemode );
            if ( $joinId === null ) {
                self::finish_ignored( $eventId, null, 'No matching online join.' );
            } else {
                self::finish_processed( $eventId, self::join_row_id( $joinId ) );
            }
            return;
        }

        $row = self::resolve_invoice_row( 'invoice.paid', $dataObject );
        if ( ! $row ) {
            self::finish_ignored( $eventId, null );
            return;
        }

        $invoiceId = (string) ( $dataObject['id'] ?? '' );

        // The more specific charge/payment_intent id, when present, is
        // preferred as this ledger entry's object reference — falling
        // back to the invoice id itself when neither is present. For an
        // out-of-band payment (the branch below) both are always empty,
        // so this naturally resolves to $invoiceId either way.
        $chargeId = self::extract_ref_id( $dataObject['charge'] ?? null );
        $piId     = self::extract_ref_id( $dataObject['payment_intent'] ?? null );
        $objectId = $chargeId !== '' ? $chargeId : ( $piId !== '' ? $piId : $invoiceId );

        if ( ! empty( $dataObject['paid_out_of_band'] ) ) {
            // Closed out with Stripe's own "Mark as paid" — the way a
            // cheque that arrived in the post gets settled. There is no
            // charge or payment_intent to inspect, and Stripe records no
            // method detail of its own, so the honest record is "other,
            // settled off Stripe" rather than a guess at cheque/wire/cash.
            $detail = [ 'method' => 'other', 'card_brand' => null, 'last4' => null, 'bank_name' => null, 'receipt_url' => null ];

            $offStripeCents = self::off_stripe_amount_cents(
                (int) ( $row->amount_due_cents ?? 0 ),
                (int) ( $dataObject['amount_paid'] ?? 0 )
            );

            // Stripe's own timestamp for when it was marked paid, not
            // whenever this delivery happened to be processed.
            $paidAt     = (int) ( $dataObject['status_transitions']['paid_at'] ?? 0 );
            $occurredAt = $paidAt > 0 ? gmdate( 'Y-m-d H:i:s', $paidAt ) : current_time( 'mysql' );

            $payment = [
                'stripe_object_id' => $objectId,
                'kind'             => MyNJILGA_Dues_Payments_Table::KIND_PAYMENT,
                'method'           => 'other',
                'amount_cents'     => $offStripeCents,
                'status'           => 'succeeded',
                'occurred_at'      => $occurredAt,
                'reference'        => self::MARKED_PAID_IN_STRIPE,
                'raw'              => self::trimmed_json( $dataObject ),
            ];
        } else {
            $detail = self::resolve_payment_method_detail( $dataObject, $invoiceId );

            // Keys deliberately match MyNJILGA_Dues_Payments_Table::record()'s
            // shape (minus invoice_row_id/livemode) — MyNJILGA_Payment_Listener::
            // handle_invoice_paid(), which this do_action() cascades into, adds
            // those two (it already looks the row up itself) and performs the
            // actual ledger write, right alongside the settlement it already
            // performs — one place writes the ledger row AND decides settlement
            // for this event, so a duplicate fire can never do one without the
            // other.
            $payment = [
                'stripe_object_id' => $objectId,
                'kind'             => MyNJILGA_Dues_Payments_Table::KIND_PAYMENT,
                'method'           => $detail['method'],
                'amount_cents'     => (int) ( $dataObject['amount_paid'] ?? 0 ),
                'status'           => 'succeeded',
                'occurred_at'      => current_time( 'mysql' ),
                'card_brand'       => $detail['card_brand'],
                'last4'            => $detail['last4'],
                'bank_name'        => $detail['bank_name'],
                'receipt_url'      => $detail['receipt_url'],
                'raw'              => self::trimmed_json( $dataObject ),
            ];
        }

        do_action( 'njilga_stripe_invoice_paid', $invoiceId, $payment );

        // Not something handle_invoice_paid() does — the row update below
        // is this class's own responsibility.
        $rowFields = [
            'amount_paid_cents' => (int) ( $dataObject['amount_paid'] ?? 0 ),
            'amount_due_cents'  => (int) ( $dataObject['amount_remaining'] ?? 0 ),
            'primary_method'    => $detail['method'],
            'stripe_status'     => (string) ( $dataObject['status'] ?? '' ),
            'last_synced_at'    => current_time( 'mysql' ),
        ];

        // Money settled off Stripe needs recording as such, or a firm
        // closed out by cheque looks — to anyone reconciling this page
        // against a Stripe payout — like money Stripe should have sent
        // and didn't.
        if ( isset( $offStripeCents ) ) {
            $rowFields['paid_off_stripe_cents'] = (int) ( $row->paid_off_stripe_cents ?? 0 ) + $offStripeCents;
        }

        MyNJILGA_Dues_Invoice_Table::update_gateway_fields( (int) $row->id, $rowFields );

        self::finish_processed( $eventId, (int) $row->id );
    }

    /**
     * Best-effort card/bank/receipt detail for an invoice.paid event.
     * Tries the event's own (possibly expanded) payment_intent first;
     * falls back to fetch_invoice() enrichment; never blocks settlement
     * over missing detail — 'method' defaults to 'other'.
     *
     * @param array<string,mixed> $dataObject The Invoice object.
     * @return array{method:string,card_brand:?string,last4:?string,bank_name:?string,receipt_url:?string}
     */
    private static function resolve_payment_method_detail( array $dataObject, string $invoiceId ): array {
        $detail = [ 'method' => '', 'card_brand' => null, 'last4' => null, 'bank_name' => null, 'receipt_url' => null ];

        $pi = $dataObject['payment_intent'] ?? null;
        if ( is_array( $pi ) ) {
            $detail = self::detail_from_payment_intent( $pi );
        }

        if ( $detail['method'] === '' ) {
            try {
                $fetched = MyNJILGA_Invoicing::gateway()->fetch_invoice( $invoiceId );
                $fetchedPi = is_array( $fetched ) ? ( $fetched['payment_intent'] ?? null ) : null;
                if ( is_array( $fetchedPi ) ) {
                    $fromFetched = self::detail_from_payment_intent( $fetchedPi );
                    if ( $fromFetched['method'] !== '' ) {
                        $detail = $fromFetched;
                    }
                }
            } catch ( \Throwable $e ) {
                // Best-effort enrichment only — never block settlement over it.
            }
        }

        if ( $detail['method'] === '' ) {
            $detail['method'] = 'other';
        }

        return $detail;
    }

    /**
     * @param array<string,mixed> $pi An expanded PaymentIntent object.
     * @return array{method:string,card_brand:?string,last4:?string,bank_name:?string,receipt_url:?string}
     */
    private static function detail_from_payment_intent( array $pi ): array {
        $detail = [ 'method' => '', 'card_brand' => null, 'last4' => null, 'bank_name' => null, 'receipt_url' => null ];

        $charge = null;
        if ( isset( $pi['latest_charge'] ) && is_array( $pi['latest_charge'] ) ) {
            $charge = $pi['latest_charge'];
        } elseif ( isset( $pi['charges']['data'][0] ) && is_array( $pi['charges']['data'][0] ) ) {
            $charge = $pi['charges']['data'][0];
        }

        if ( is_array( $charge ) ) {
            $detail = self::detail_from_charge( $charge );
        }

        return $detail;
    }

    /**
     * Public (and pure) so the online join's fulfillment describes a
     * Checkout payment in exactly the words this handler would.
     *
     * @param array<string,mixed> $charge A Charge object.
     * @return array{method:string,card_brand:?string,last4:?string,bank_name:?string,receipt_url:?string}
     */
    public static function detail_from_charge( array $charge ): array {
        $detail = [ 'method' => '', 'card_brand' => null, 'last4' => null, 'bank_name' => null, 'receipt_url' => null ];

        $pmDetails = is_array( $charge['payment_method_details'] ?? null ) ? $charge['payment_method_details'] : [];
        $type      = (string) ( $pmDetails['type'] ?? '' );

        if ( $type === 'card' && is_array( $pmDetails['card'] ?? null ) ) {
            $detail['method']     = 'card';
            $detail['card_brand'] = isset( $pmDetails['card']['brand'] ) ? (string) $pmDetails['card']['brand'] : null;
            $detail['last4']      = isset( $pmDetails['card']['last4'] ) ? (string) $pmDetails['card']['last4'] : null;
        } elseif ( $type === 'us_bank_account' && is_array( $pmDetails['us_bank_account'] ?? null ) ) {
            $detail['method']    = 'us_bank_account';
            $detail['bank_name'] = isset( $pmDetails['us_bank_account']['bank_name'] ) ? (string) $pmDetails['us_bank_account']['bank_name'] : null;
            $detail['last4']     = isset( $pmDetails['us_bank_account']['last4'] ) ? (string) $pmDetails['us_bank_account']['last4'] : null;
        } elseif ( $type !== '' ) {
            $detail['method'] = $type;
        }

        if ( isset( $charge['receipt_url'] ) ) {
            $detail['receipt_url'] = (string) $charge['receipt_url'];
        }

        return $detail;
    }

    /**
     * @param array<string,mixed> $dataObject The PaymentIntent object.
     */
    private static function handle_payment_intent_processing( string $eventId, array $dataObject ): void {
        $row = self::resolve_invoice_row( 'payment_intent.processing', $dataObject );
        if ( ! $row ) {
            self::finish_ignored( $eventId, null );
            return;
        }
        if ( self::skip_join_row_status( $eventId, $row ) ) {
            return;
        }

        // ACH-in-flight — never settles membership, never touches the
        // payment ledger.
        MyNJILGA_Dues_Invoice_Table::update_gateway_fields( (int) $row->id, [
            'status'         => MyNJILGA_Dues_Invoice_Table::STATUS_PROCESSING,
            'processing_at'  => current_time( 'mysql' ),
            'stripe_status'  => (string) ( $dataObject['status'] ?? '' ),
            'last_synced_at' => current_time( 'mysql' ),
        ] );

        self::finish_processed( $eventId, (int) $row->id );
    }

    /**
     * @param array<string,mixed> $dataObject The Invoice object.
     */
    private static function handle_invoice_payment_failed( string $eventId, array $dataObject ): void {
        $row = self::resolve_invoice_row( 'invoice.payment_failed', $dataObject );
        if ( ! $row ) {
            self::finish_ignored( $eventId, null );
            return;
        }

        $reason  = self::best_effort_failure_reason( $dataObject );
        $message = $reason !== ''
            ? sprintf( 'Payment failed: %s', $reason )
            : sprintf( 'Stripe reported a failed payment attempt on %s.', current_time( 'Y-m-d' ) );

        MyNJILGA_Dues_Invoice_Table::update_gateway_fields( (int) $row->id, [
            'last_error'     => $message,
            'stripe_status'  => (string) ( $dataObject['status'] ?? '' ),
            'last_synced_at' => current_time( 'mysql' ),
        ] );

        self::finish_processed( $eventId, (int) $row->id );
    }

    /**
     * An Invoice object carries very little of its own about WHY a
     * payment attempt failed — the richer detail lives on the
     * underlying payment_intent's last_payment_error, present only when
     * the payload happens to carry an expanded payment_intent. '' means
     * "nothing specific available" and the caller falls back to a
     * generic message.
     *
     * @param array<string,mixed> $dataObject The Invoice object.
     */
    private static function best_effort_failure_reason( array $dataObject ): string {
        $pi = $dataObject['payment_intent'] ?? null;
        if ( is_array( $pi ) && isset( $pi['last_payment_error']['message'] ) ) {
            return (string) $pi['last_payment_error']['message'];
        }
        if ( isset( $dataObject['last_finalization_error']['message'] ) ) {
            return (string) $dataObject['last_finalization_error']['message'];
        }
        return '';
    }

    /**
     * @param array<string,mixed> $dataObject The Invoice object.
     */
    private static function handle_invoice_payment_action_required( string $eventId, array $dataObject ): void {
        $row = self::resolve_invoice_row( 'invoice.payment_action_required', $dataObject );
        if ( ! $row ) {
            self::finish_ignored( $eventId, null );
            return;
        }

        MyNJILGA_Dues_Invoice_Table::update_gateway_fields( (int) $row->id, [
            'last_error'     => 'This invoice requires additional action from the payer to complete payment (e.g. 3-D Secure authentication).',
            'stripe_status'  => (string) ( $dataObject['status'] ?? '' ),
            'last_synced_at' => current_time( 'mysql' ),
        ] );

        self::finish_processed( $eventId, (int) $row->id );
    }

    /**
     * Defensive sync only — hosted_invoice_url/invoice_pdf_url/
     * gateway_invoice_number should normally already be set from
     * create_order()'s own return value; this only backfills drift or
     * an invoice finalized outside the normal create_order() path.
     *
     * @param array<string,mixed> $dataObject The Invoice object.
     */
    private static function handle_invoice_finalized( string $eventId, array $dataObject ): void {
        $row = self::resolve_invoice_row( 'invoice.finalized', $dataObject );
        if ( ! $row ) {
            self::finish_ignored( $eventId, null );
            return;
        }

        $fields = [
            'stripe_status'  => (string) ( $dataObject['status'] ?? '' ),
            'last_synced_at' => current_time( 'mysql' ),
        ];

        if ( empty( $row->hosted_invoice_url ) && ! empty( $dataObject['hosted_invoice_url'] ) ) {
            $fields['hosted_invoice_url'] = (string) $dataObject['hosted_invoice_url'];
        }
        if ( empty( $row->invoice_pdf_url ) && ! empty( $dataObject['invoice_pdf'] ) ) {
            $fields['invoice_pdf_url'] = (string) $dataObject['invoice_pdf'];
        }
        if ( empty( $row->gateway_invoice_number ) && ! empty( $dataObject['number'] ) ) {
            $fields['gateway_invoice_number'] = (string) $dataObject['number'];
        }

        MyNJILGA_Dues_Invoice_Table::update_gateway_fields( (int) $row->id, $fields );

        self::finish_processed( $eventId, (int) $row->id );
    }

    /**
     * @param array<string,mixed> $dataObject The Invoice object.
     */
    private static function handle_invoice_voided( string $eventId, array $dataObject ): void {
        $row = self::resolve_invoice_row( 'invoice.voided', $dataObject );
        if ( ! $row ) {
            self::finish_ignored( $eventId, null );
            return;
        }
        if ( self::skip_join_row_status( $eventId, $row ) ) {
            return;
        }

        MyNJILGA_Dues_Invoice_Table::update_gateway_fields( (int) $row->id, [
            'status'         => MyNJILGA_Dues_Invoice_Table::STATUS_VOIDED,
            'voided_at'      => current_time( 'mysql' ),
            'stripe_status'  => (string) ( $dataObject['status'] ?? '' ),
            'last_synced_at' => current_time( 'mysql' ),
        ] );

        self::finish_processed( $eventId, (int) $row->id );
    }

    /**
     * @param array<string,mixed> $dataObject The Invoice object.
     */
    private static function handle_invoice_uncollectible( string $eventId, array $dataObject ): void {
        $row = self::resolve_invoice_row( 'invoice.marked_uncollectible', $dataObject );
        if ( ! $row ) {
            self::finish_ignored( $eventId, null );
            return;
        }
        if ( self::skip_join_row_status( $eventId, $row ) ) {
            return;
        }

        MyNJILGA_Dues_Invoice_Table::update_gateway_fields( (int) $row->id, [
            'status'         => MyNJILGA_Dues_Invoice_Table::STATUS_UNCOLLECTIBLE,
            'stripe_status'  => (string) ( $dataObject['status'] ?? '' ),
            'last_synced_at' => current_time( 'mysql' ),
        ] );

        self::finish_processed( $eventId, (int) $row->id );
    }

    /**
     * Informational only — this plugin never calls Stripe's own /send
     * endpoint, so this event firing at all means something unusual
     * happened server-side in the Stripe Dashboard; handled gracefully
     * rather than treated as unexpected.
     *
     * @param array<string,mixed> $dataObject The Invoice object.
     */
    private static function handle_invoice_sent( string $eventId, array $dataObject ): void {
        $row = self::resolve_invoice_row( 'invoice.sent', $dataObject );
        if ( ! $row ) {
            self::finish_ignored( $eventId, null );
            return;
        }

        MyNJILGA_Dues_Invoice_Table::update_gateway_fields( (int) $row->id, [
            'stripe_status'  => (string) ( $dataObject['status'] ?? '' ),
            'last_synced_at' => current_time( 'mysql' ),
        ] );

        self::finish_processed( $eventId, (int) $row->id );
    }

    /**
     * Per this migration's design: never auto-adjust on an unusual
     * payment event — always put it in front of a human. No ledger row
     * here — the invoice.paid event already recorded the actual
     * payment; overpaid is a follow-on signal about that SAME payment,
     * not a new one.
     *
     * @param array<string,mixed> $dataObject The Invoice object.
     */
    private static function handle_invoice_overpaid( string $eventId, array $dataObject ): void {
        $row = self::resolve_invoice_row( 'invoice.overpaid', $dataObject );
        if ( ! $row ) {
            self::finish_ignored( $eventId, null );
            return;
        }

        MyNJILGA_Dues_Invoice_Table::update_gateway_fields( (int) $row->id, [
            'last_error'     => 'Invoice shows an overpayment — review before taking any action.',
            'stripe_status'  => (string) ( $dataObject['status'] ?? '' ),
            'last_synced_at' => current_time( 'mysql' ),
        ] );

        self::finish_processed( $eventId, (int) $row->id );
    }

    /**
     * Never auto-revokes anything — row.status, tags, and roles are
     * untouched; the refund is only ever surfaced for a human to review.
     *
     * @param array<string,mixed> $dataObject The Charge object.
     */
    private static function handle_charge_refunded( string $eventId, array $dataObject, bool $livemode ): void {
        $row = self::resolve_invoice_row( 'charge.refunded', $dataObject );
        if ( ! $row ) {
            self::finish_ignored( $eventId, null );
            return;
        }

        // The Charge object's own amount_refunded is the CUMULATIVE total
        // refunded against this charge so far, not necessarily this one
        // refund's amount. When the charge's `refunds` list is present
        // (webhook payloads sometimes carry it), its first entry is
        // Stripe's most-recently-created refund on this charge — used
        // here as "this" refund's amount. Without that list, cumulative
        // amount_refunded is the best number available, and is exactly
        // right for the common case of one refund on the charge.
        $refundAmount = (int) ( $dataObject['amount_refunded'] ?? 0 );
        $refundId     = null;
        $refundsList  = $dataObject['refunds']['data'] ?? null;
        if ( is_array( $refundsList ) && isset( $refundsList[0] ) && is_array( $refundsList[0] ) ) {
            if ( isset( $refundsList[0]['amount'] ) ) {
                $refundAmount = (int) $refundsList[0]['amount'];
            }
            if ( isset( $refundsList[0]['id'] ) ) {
                $refundId = (string) $refundsList[0]['id'];
            }
        }

        $chargeId = (string) ( $dataObject['id'] ?? '' );
        // A charge can be refunded more than once (partial refunds) — a
        // bare charge id would collide with itself as this ledger's
        // (stripe_object_id, kind) key on the second refund, so prefer
        // the refund's OWN id when available, and fall back to a
        // charge-id + event-id pairing (unique per delivery) otherwise.
        $objectId = $refundId ?? ( $chargeId !== '' ? $chargeId . '_' . $eventId : null );

        $detail = self::detail_from_charge( $dataObject );

        $ledger = [
            'invoice_row_id'   => (int) $row->id,
            'livemode'         => $livemode,
            'stripe_object_id' => $objectId,
            'kind'             => MyNJILGA_Dues_Payments_Table::KIND_REFUND,
            'method'           => $detail['method'] !== '' ? $detail['method'] : 'other',
            'amount_cents'     => -1 * abs( $refundAmount ),
            'status'           => 'succeeded',
            'occurred_at'      => current_time( 'mysql' ),
            'reference'        => $chargeId,
            'receipt_url'      => $detail['receipt_url'],
            'raw'              => self::trimmed_json( $dataObject ),
        ];
        MyNJILGA_Dues_Payments_Table::record( $ledger );

        // Stripe's charge carries the CUMULATIVE refunded total, which is
        // what this column should hold — summing our own ledger rows would
        // drift the moment a refund arrived that we never saw.
        $message = sprintf(
            'Refunded %s on %s — review membership status.',
            MyNJILGA_Invoicing::money( abs( $refundAmount ) ),
            current_time( 'Y-m-d' )
        );
        $fields = [
            'last_error'     => $message,
            'stripe_status'  => (string) ( $dataObject['status'] ?? '' ),
            'last_synced_at' => current_time( 'mysql' ),
        ];
        if ( isset( $dataObject['amount_refunded'] ) ) {
            $fields['amount_refunded_cents'] = abs( (int) $dataObject['amount_refunded'] );
        }
        MyNJILGA_Dues_Invoice_Table::update_gateway_fields( (int) $row->id, $fields );
        self::flag_join( $row, $dataObject, $message );

        self::finish_processed( $eventId, (int) $row->id );
    }

    /**
     * Same posture as charge.refunded: recorded for review, never an
     * auto-revocation. Treated as KIND_REFUND rather than a distinct
     * ledger kind — a credit note reduces what the customer owes/paid on
     * this invoice in exactly the "money moved back toward the customer"
     * sense a refund does, and this migration's reporting only
     * distinguishes payment vs. refund vs. manual, not a credit note's
     * own accounting nuance. A credit note's own id (cn_...) is unique
     * per note, so no collision-avoidance trick is needed the way
     * charge.refunded's bare charge id requires.
     *
     * @param array<string,mixed> $dataObject The CreditNote object.
     */
    private static function handle_credit_note_created( string $eventId, array $dataObject, bool $livemode ): void {
        $row = self::resolve_invoice_row( 'credit_note.created', $dataObject );
        if ( ! $row ) {
            self::finish_ignored( $eventId, null );
            return;
        }

        $amount = (int) ( $dataObject['amount'] ?? 0 );
        $noteId = (string) ( $dataObject['id'] ?? '' );

        $ledger = [
            'invoice_row_id'   => (int) $row->id,
            'livemode'         => $livemode,
            'stripe_object_id' => $noteId !== '' ? $noteId : null,
            'kind'             => MyNJILGA_Dues_Payments_Table::KIND_REFUND,
            'method'           => 'other',
            'amount_cents'     => -1 * abs( $amount ),
            'status'           => 'succeeded',
            'occurred_at'      => current_time( 'mysql' ),
            'reference'        => $noteId,
            'receipt_url'      => isset( $dataObject['pdf'] ) ? (string) $dataObject['pdf'] : null,
            'raw'              => self::trimmed_json( $dataObject ),
        ];
        MyNJILGA_Dues_Payments_Table::record( $ledger );

        $message = sprintf(
            'A credit note for %s was issued on %s — review membership status.',
            MyNJILGA_Invoicing::money( abs( $amount ) ),
            current_time( 'Y-m-d' )
        );
        MyNJILGA_Dues_Invoice_Table::update_gateway_fields( (int) $row->id, [
            'last_error'     => $message,
            'stripe_status'  => (string) ( $dataObject['status'] ?? '' ),
            'last_synced_at' => current_time( 'mysql' ),
        ] );
        self::flag_join( $row, $dataObject, $message );

        self::finish_processed( $eventId, (int) $row->id );
    }

    /**
     * No invoice row involved — belt-and-suspenders cleanup only.
     * find_or_create_customer() already re-detects a deleted customer on
     * its own next use, so missing this event isn't catastrophic.
     *
     * @param array<string,mixed> $dataObject The deleted Customer object.
     */
    private static function handle_customer_deleted( string $eventId, array $dataObject, bool $livemode ): void {
        $customerId = (string) ( $dataObject['id'] ?? '' );
        if ( $customerId === '' ) {
            self::finish_ignored( $eventId, null );
            return;
        }

        $mode = $livemode ? MyNJILGA_Stripe_Connection::MODE_LIVE : MyNJILGA_Stripe_Connection::MODE_TEST;
        MyNJILGA_Stripe_Customer_Map::delete_by_customer_id( $customerId, $mode );

        self::finish_processed( $eventId, null );
    }

    // -------------------------------------------------------------------------
    // Small shared helpers
    // -------------------------------------------------------------------------

    private static function finish_processed( string $eventId, ?int $invoiceRowId ): void {
        $row = MyNJILGA_Stripe_Events_Table::get_by_event_id( $eventId );
        if ( $row ) {
            MyNJILGA_Stripe_Events_Table::mark_processed( (int) $row->id, MyNJILGA_Stripe_Events_Table::STATUS_PROCESSED, '', $invoiceRowId );
        }
    }

    private static function finish_ignored( string $eventId, ?int $invoiceRowId, string $message = 'No matching invoice row — likely created outside this plugin.' ): void {
        $row = MyNJILGA_Stripe_Events_Table::get_by_event_id( $eventId );
        if ( $row ) {
            MyNJILGA_Stripe_Events_Table::mark_processed( (int) $row->id, MyNJILGA_Stripe_Events_Table::STATUS_IGNORED, $message, $invoiceRowId );
        }
    }

    /**
     * A JSON-encoded, TRIMMED version of a Stripe object for the ledger's
     * `raw` audit column — not a source of truth, so heavy nested list
     * fields are dropped and a still-expanded sub-object is collapsed to
     * its id, with a hard length backstop against anything pathological.
     *
     * @param array<string,mixed> $dataObject
     */
    private static function trimmed_json( array $dataObject ): string {
        foreach ( [ 'lines', 'refunds', 'charges', 'discounts', 'total_discount_amounts', 'total_tax_amounts', 'status_transitions', 'automatic_tax' ] as $heavyKey ) {
            unset( $dataObject[ $heavyKey ] );
        }
        foreach ( [ 'payment_intent', 'customer', 'charge' ] as $key ) {
            if ( isset( $dataObject[ $key ] ) && is_array( $dataObject[ $key ] ) ) {
                $dataObject[ $key ] = isset( $dataObject[ $key ]['id'] ) ? (string) $dataObject[ $key ]['id'] : null;
            }
        }
        $json = wp_json_encode( $dataObject );
        if ( $json === false ) {
            $json = '{}';
        }
        return mb_substr( $json, 0, 8000 );
    }
}
