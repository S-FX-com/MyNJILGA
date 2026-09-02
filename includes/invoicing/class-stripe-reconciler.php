<?php
/**
 * Stripe reconciler (Stripe migration phase 4) — keeps
 * `njilga_dues_invoices` aligned with Stripe when the webhook path
 * (class-stripe-webhook.php) didn't or hasn't yet: a delivery that never
 * arrived, arrived out of order, or was misconfigured before this
 * migration's webhook auto-provisioning ran. This class is the SAFETY
 * NET, not a second source of truth — it never constructs a raw Stripe
 * API call itself, only ever going through
 * MyNJILGA_Invoicing::gateway()->fetch_invoice(), exactly like every
 * other non-gateway class in this plugin.
 *
 * One algorithm, three entry points:
 *   - sync_year() / sync_row() (Part A) — the actual reconciliation logic,
 *     shared by every caller below so there is exactly one place that
 *     decides what "in sync" means.
 *   - register()/run_daily() (Part B) — a daily Action Scheduler job that
 *     walks every dues year with anything still open in Stripe's active
 *     mode.
 *   - the Invoicing page's "Sync with Stripe" header button and per-row
 *     "Refresh" action (Part C, wired in includes/class-page-invoicing.php)
 *     — a synchronous POST + redirect + summary notice, same shape as
 *     every other bulk action on that page.
 *
 * Design notes:
 *
 *   - Only rows in created/sent/processing are ever re-fetched — nothing
 *     to reconcile before an invoice exists in Stripe (draft/approved/
 *     excluded), and paid/downgraded/voided/uncollectible are already
 *     terminal from our side. The one exception is the "Stripe reports
 *     LESS paid than we think" guard inside sync_row() itself, which is
 *     why that check lives per-row rather than as a second query over
 *     paid rows: sync_row() already has both sides of the comparison in
 *     hand for whatever row it was asked to check.
 *
 *   - "Stripe wins for money facts" EXCEPT that one direction: this class
 *     will happily raise amount_paid_cents/amount_due_cents/stripe_status
 *     up to match Stripe, and will fire the same
 *     `njilga_stripe_invoice_paid` action the webhook uses when Stripe
 *     shows paid and we don't yet — but it will never silently lower a
 *     recorded payment. That always stops at a human via last_error.
 *
 *   - Settlement is never decided here. The missed-payment branch fires
 *     do_action( 'njilga_stripe_invoice_paid', ... ) — the exact same
 *     action MyNJILGA_Stripe_Webhook::handle_invoice_paid() fires — so
 *     MyNJILGA_Payment_Listener stays the one place that both writes the
 *     payment ledger and decides settlement, regardless of which path
 *     noticed the payment.
 */
class MyNJILGA_Stripe_Reconciler {

    const HOOK_DAILY = 'njilga_stripe_reconcile_daily';

    // -------------------------------------------------------------------------
    // PART A — the sync algorithm
    // -------------------------------------------------------------------------

    /**
     * Reconciles every non-terminal (created/sent/processing) row for
     * $duesYear in the currently active Stripe mode against Stripe's own
     * view of each invoice.
     *
     * @param callable(string):void|null $progress Called after each
     *   invoice is checked with a short status string. Optional — a
     *   no-op default is used when omitted (the scheduled daily job has
     *   nothing to report progress to).
     * @param int|null $onlyRowId When given, restricts the sweep to this
     *   one invoice row (still subject to the same year/status/mode
     *   filter) — the Invoicing page's per-row "Refresh" action reuses
     *   this same method rather than duplicating sync_row()'s logic.
     * @return array{checked:int,updated:int,needs_attention:int,errors:array<int,string>}
     */
    public static function sync_year( int $duesYear, ?callable $progress = null, ?int $onlyRowId = null ): array {
        $result   = [ 'checked' => 0, 'updated' => 0, 'needs_attention' => 0, 'errors' => [] ];
        $progress = $progress ?? static function ( string $status ): void {};

        // Never worth re-fetching every non-terminal row against a Stripe
        // that isn't reachable right now — that would mass-flag every one
        // of them as "no longer exists" (fetch_invoice() returns null both
        // when an invoice is genuinely gone AND when there's no client to
        // ask), which is exactly the false alarm this guard avoids. A soft
        // readiness warning (e.g. no webhook on file) is deliberately NOT
        // checked here — a broken webhook is precisely the situation this
        // class exists to backstop.
        $gateway = MyNJILGA_Invoicing::gateway();
        if ( ! $gateway->is_available() ) {
            $result['errors'][] = $gateway->name() . ' is not connected — nothing to reconcile against.';
            return $result;
        }

        $livemode = ( MyNJILGA_Stripe_Connection::active_mode() === MyNJILGA_Stripe_Connection::MODE_LIVE );
        $rows     = MyNJILGA_Dues_Invoice_Table::get_by_year( $duesYear, [
            MyNJILGA_Dues_Invoice_Table::STATUS_CREATED,
            MyNJILGA_Dues_Invoice_Table::STATUS_SENT,
            MyNJILGA_Dues_Invoice_Table::STATUS_PROCESSING,
        ] );

        foreach ( $rows as $row ) {
            if ( (bool) $row->livemode !== $livemode ) {
                continue; // A test-mode row while live is active, or vice versa.
            }
            if ( $onlyRowId !== null && (int) $row->id !== $onlyRowId ) {
                continue;
            }

            $result['checked']++;

            try {
                $outcome = self::sync_row( $row );
                if ( $outcome['updated'] ) {
                    $result['updated']++;
                } elseif ( $outcome['needs_attention'] ) {
                    $result['needs_attention']++;
                }
                $progress( $outcome['note'] );
            } catch ( \Throwable $e ) {
                // One bad row's unexpected failure must never abort the
                // rest of the sweep.
                $message = sprintf(
                    '%s (invoice row #%d): %s',
                    MyNJILGA_Dues_Snapshot::company_name( $row ),
                    (int) $row->id,
                    $e->getMessage()
                );
                $result['errors'][] = $message;
                $progress( $message );
            }
        }

        return $result;
    }

    /**
     * Reconciles ONE invoice row against Stripe. Public so the per-row
     * "Refresh" path and sync_year() share exactly this logic — see the
     * class docblock's design notes for what each divergence does.
     *
     * @return array{updated:bool,needs_attention:bool,note:string}
     */
    public static function sync_row( object $invoiceRow ): array {
        $rowId = (int) $invoiceRow->id;
        $name  = MyNJILGA_Dues_Snapshot::company_name( $invoiceRow );

        $invoiceId = (string) ( $invoiceRow->gateway_invoice_id ?? '' );
        $fetched   = MyNJILGA_Invoicing::gateway()->fetch_invoice( $invoiceId );

        if ( $fetched === null ) {
            MyNJILGA_Dues_Invoice_Table::set_error(
                $rowId,
                'This invoice no longer exists in Stripe — it may have been deleted as an abandoned draft. Review and consider recreating it.'
            );
            return [
                'updated'         => false,
                'needs_attention' => true,
                'note'            => sprintf( '%s: invoice not found in Stripe — flagged for review.', $name ),
            ];
        }

        $stripeStatus  = (string) ( $fetched['stripe_status'] ?? '' );
        $stripeAmtPaid = (int) ( $fetched['amount_paid_cents'] ?? 0 );
        $stripeAmtDue  = (int) ( $fetched['amount_due_cents'] ?? 0 );

        $rowStatus     = (string) $invoiceRow->status;
        $rowStripeStat = (string) ( $invoiceRow->stripe_status ?? '' );
        $rowAmtPaid    = (int) $invoiceRow->amount_paid_cents;
        $rowAmtDue     = (int) $invoiceRow->amount_due_cents;

        $stripeSaysPaid = ( $stripeStatus === 'paid' );
        $missedPayment  = ( $stripeSaysPaid && $rowStatus !== MyNJILGA_Dues_Invoice_Table::STATUS_PAID );

        $diverges = $missedPayment
            || ( $stripeStatus !== $rowStripeStat )
            || ( $stripeAmtPaid !== $rowAmtPaid )
            || ( $stripeAmtDue !== $rowAmtDue );

        if ( ! $diverges ) {
            return [ 'updated' => false, 'needs_attention' => false, 'note' => sprintf( '%s: already in sync.', $name ) ];
        }

        // 3a — missed-webhook safety net: Stripe shows paid, we don't yet.
        if ( $missedPayment ) {
            // Mirrors class-stripe-webhook.php's handle_invoice_paid()
            // exactly: an out-of-band ("Mark Paid") settlement carries
            // njilga_payment_method in its Stripe metadata, and its
            // njilga_final_payment_amount_cents is the exact remainder
            // that payment covered — NOT Stripe's cumulative amount_paid,
            // which would double-count any prior manually-recorded
            // partial (the webhook and the reconciler are two different
            // code paths that can both notice the SAME settlement; they
            // must resolve the same amount or the ledger overstates it).
            $metadata        = (array) ( $fetched['metadata'] ?? [] );
            $offStripeMethod = isset( $metadata['njilga_payment_method'] ) ? (string) $metadata['njilga_payment_method'] : '';

            if ( $offStripeMethod !== '' ) {
                $finalAmountCents = isset( $metadata['njilga_final_payment_amount_cents'] ) ? (int) $metadata['njilga_final_payment_amount_cents'] : 0;
                $reference        = '';
                if ( isset( $metadata['njilga_check_number'] ) && (string) $metadata['njilga_check_number'] !== '' ) {
                    $reference = (string) $metadata['njilga_check_number'];
                } elseif ( isset( $metadata['njilga_wire_reference'] ) && (string) $metadata['njilga_wire_reference'] !== '' ) {
                    $reference = (string) $metadata['njilga_wire_reference'];
                }
                $payment = [
                    'stripe_object_id' => self::best_effort_object_id( $fetched, $invoiceId ),
                    'kind'             => MyNJILGA_Dues_Payments_Table::KIND_PAYMENT,
                    'method'           => $offStripeMethod,
                    'amount_cents'     => $finalAmountCents > 0 ? $finalAmountCents : $stripeAmtPaid,
                    'status'           => 'succeeded',
                    'occurred_at'      => current_time( 'mysql' ),
                    'reference'        => $reference !== '' ? $reference : null,
                    'raw'              => wp_json_encode( [ 'reconciled_by' => 'MyNJILGA_Stripe_Reconciler', 'at' => current_time( 'mysql' ) ] ),
                ];
            } else {
                $detail  = self::best_effort_payment_detail( $fetched );
                $payment = [
                    'stripe_object_id' => self::best_effort_object_id( $fetched, $invoiceId ),
                    'kind'             => MyNJILGA_Dues_Payments_Table::KIND_PAYMENT,
                    'method'           => $detail['method'],
                    'amount_cents'     => $stripeAmtPaid,
                    'status'           => 'succeeded',
                    'occurred_at'      => current_time( 'mysql' ),
                    'card_brand'       => $detail['card_brand'],
                    'last4'            => $detail['last4'],
                    'bank_name'        => $detail['bank_name'],
                    'receipt_url'      => $detail['receipt_url'],
                    'raw'              => wp_json_encode( [ 'reconciled_by' => 'MyNJILGA_Stripe_Reconciler', 'at' => current_time( 'mysql' ) ] ),
                ];
            }

            // settle() itself (reached through this action) doesn't set
            // last_synced_at — stamp it here so it ends up set either way.
            MyNJILGA_Dues_Invoice_Table::update_gateway_fields( $rowId, [ 'last_synced_at' => current_time( 'mysql' ) ] );

            // The SAME trigger surface the webhook uses — never call
            // MyNJILGA_Payment_Listener::settle() directly, so there
            // remains exactly one place that decides settlement.
            do_action( 'njilga_stripe_invoice_paid', $invoiceId, $payment );

            return [ 'updated' => true, 'needs_attention' => false, 'note' => sprintf( '%s: found a missed payment — settled.', $name ) ];
        }

        // 3b, EXCEPTION — never auto-lower a recorded payment. Stripe
        // wins for money facts in every OTHER direction; this is the one
        // deliberate holdout, forced in front of a human instead.
        if ( $stripeAmtPaid < $rowAmtPaid ) {
            MyNJILGA_Dues_Invoice_Table::set_error( $rowId, sprintf(
                'Stripe now reports %s paid on this invoice — less than the %s already recorded here. This was not applied automatically; review before making any change.',
                MyNJILGA_Invoicing::money( $stripeAmtPaid ),
                MyNJILGA_Invoicing::money( $rowAmtPaid )
            ) );
            return [
                'updated'         => false,
                'needs_attention' => true,
                'note'            => sprintf( '%s: Stripe reports less paid than recorded — flagged for review.', $name ),
            ];
        }

        // 3b — ordinary drift: still open, or paid on both sides with the
        // cents/status differing. Only the columns that actually changed
        // are written.
        $fields = [ 'last_synced_at' => current_time( 'mysql' ) ];
        if ( $stripeAmtPaid !== $rowAmtPaid ) {
            $fields['amount_paid_cents'] = $stripeAmtPaid;
        }
        if ( $stripeAmtDue !== $rowAmtDue ) {
            $fields['amount_due_cents'] = $stripeAmtDue;
        }
        if ( $stripeStatus !== $rowStripeStat ) {
            $fields['stripe_status'] = $stripeStatus;
        }
        $method = self::best_effort_payment_detail( $fetched )['method'];
        if ( $method !== '' && $method !== (string) ( $invoiceRow->primary_method ?? '' ) ) {
            $fields['primary_method'] = $method;
        }

        MyNJILGA_Dues_Invoice_Table::update_gateway_fields( $rowId, $fields );

        return [ 'updated' => true, 'needs_attention' => false, 'note' => sprintf( '%s: updated from Stripe.', $name ) ];
    }

    /**
     * Best-effort card/bank detail from fetch_invoice()'s return shape — a
     * reduced version of what the webhook's own invoice.paid handler
     * builds (class-stripe-webhook.php's resolve_payment_method_detail());
     * fetch_invoice() doesn't carry every field the raw webhook payload
     * does, so this makes do with whatever payment_intent/charge detail
     * happens to be present. 'method' defaults to 'other' when nothing
     * recognizable is present — it must never be left blank, since the
     * payment ledger's `method` column is NOT NULL.
     *
     * @param array<string,mixed> $fetched
     * @return array{method:string,card_brand:?string,last4:?string,bank_name:?string,receipt_url:?string}
     */
    private static function best_effort_payment_detail( array $fetched ): array {
        $detail = [ 'method' => 'other', 'card_brand' => null, 'last4' => null, 'bank_name' => null, 'receipt_url' => null ];

        $charge = self::best_effort_charge( $fetched );
        if ( $charge === null ) {
            return $detail;
        }

        $pmDetails = is_array( $charge['payment_method_details'] ?? null ) ? $charge['payment_method_details'] : [];
        $type      = (string) ( $pmDetails['type'] ?? '' );

        if ( $type === 'card' && is_array( $pmDetails['card'] ?? null ) ) {
            $detail['method']     = 'card';
            $detail['card_brand'] = isset( $pmDetails['card']['brand'] ) ? (string) $pmDetails['card']['brand'] : null;
            $detail['last4']      = isset( $pmDetails['card']['last4'] ) ? (string) $pmDetails['card']['last4'] : null;
        } elseif ( $type === 'us_bank_account' && is_array( $pmDetails['us_bank_account'] ?? null ) ) {
            $detail['method']    = 'ach';
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
     * The expanded Charge object behind fetch_invoice()'s payment_intent,
     * when there is one to find. null when nothing usable is present —
     * every caller must tolerate that (best-effort only).
     *
     * @param array<string,mixed> $fetched
     * @return array<string,mixed>|null
     */
    private static function best_effort_charge( array $fetched ): ?array {
        $pi = $fetched['payment_intent'] ?? null;
        if ( ! is_array( $pi ) ) {
            return null;
        }
        if ( isset( $pi['latest_charge'] ) && is_array( $pi['latest_charge'] ) ) {
            return $pi['latest_charge'];
        }
        if ( isset( $pi['charges']['data'][0] ) && is_array( $pi['charges']['data'][0] ) ) {
            return $pi['charges']['data'][0];
        }
        return null;
    }

    /**
     * Best identifier for the payment ledger's `stripe_object_id` column:
     * the charge id when we have one, else the payment_intent id, else
     * falling back to the invoice id itself — same preference order as
     * the webhook's own invoice.paid handler, so a payment recorded here
     * and later confirmed by a (delayed) webhook delivery collide on the
     * same duplicate-safe key instead of double-recording.
     *
     * @param array<string,mixed> $fetched
     */
    private static function best_effort_object_id( array $fetched, string $invoiceId ): string {
        $charge = self::best_effort_charge( $fetched );
        if ( is_array( $charge ) && isset( $charge['id'] ) && (string) $charge['id'] !== '' ) {
            return (string) $charge['id'];
        }

        $pi = $fetched['payment_intent'] ?? null;
        if ( is_array( $pi ) && isset( $pi['id'] ) && (string) $pi['id'] !== '' ) {
            return (string) $pi['id'];
        }
        if ( is_string( $pi ) && $pi !== '' ) {
            return $pi;
        }

        return $invoiceId;
    }

    // -------------------------------------------------------------------------
    // PART B — scheduled daily job
    // -------------------------------------------------------------------------

    public static function register(): void {
        add_action( self::HOOK_DAILY, [ __CLASS__, 'run_daily' ] );

        // Reuses MyNJILGA_Invoice_Creator's Action Scheduler group — the
        // same 'njilga-dues' string every other background job in this
        // plugin runs under — rather than retyping the literal.
        if ( function_exists( 'as_schedule_recurring_action' ) && ! as_has_scheduled_action( self::HOOK_DAILY, null, MyNJILGA_Invoice_Creator::AS_GROUP ) ) {
            as_schedule_recurring_action( time() + 300, DAY_IN_SECONDS, self::HOOK_DAILY, [], MyNJILGA_Invoice_Creator::AS_GROUP );
        }
        // No inline fallback when Action Scheduler isn't available at all
        // — there's no reasonable substitute for a genuinely recurring
        // job the way there is for a one-shot chunk, so this degrades to
        // "the manual Sync button is the only reconciliation path,"
        // matching how other Action-Scheduler-optional code in this
        // plugin already behaves.
    }

    /**
     * Action Scheduler callback: reconciles every dues year that still has
     * anything non-terminal in the active mode, then prunes the Stripe
     * event log.
     */
    public static function run_daily(): void {
        try {
            $livemode = ( MyNJILGA_Stripe_Connection::active_mode() === MyNJILGA_Stripe_Connection::MODE_LIVE );
            $years    = MyNJILGA_Dues_Invoice_Table::years_with_status( [
                MyNJILGA_Dues_Invoice_Table::STATUS_CREATED,
                MyNJILGA_Dues_Invoice_Table::STATUS_SENT,
                MyNJILGA_Dues_Invoice_Table::STATUS_PROCESSING,
            ], $livemode );

            foreach ( $years as $year ) {
                self::sync_year( $year );
            }
        } catch ( \Throwable $e ) {
            // sync_year() already isolates per-row failures into its own
            // errors array; reaching here means something broader (e.g.
            // the DB itself unavailable) — never let that break the
            // Action Scheduler worker.
        }

        // The spec's weekly event-log prune, folded into this existing
        // daily job rather than standing up a second cron for one cheap,
        // idempotent operation — pruning something already pruned
        // recently costs one no-op DELETE.
        try {
            MyNJILGA_Stripe_Events_Table::prune_older_than( 180 );
        } catch ( \Throwable $e ) {
            // Best-effort housekeeping only.
        }
    }
}
