<?php
/**
 * Resend an updated invoice.
 *
 * After staff change what a firm owes — a member added or removed, a category
 * or a price changed — an invoice that already went out is wrong. Fixing it
 * used to take five steps in the right order (Void, Issue again, Refresh
 * Firms, Create, Generate emails). This does them for ONE firm, in one
 * guarded action, and tells the firm in the email that the new invoice
 * replaces the old one:
 *
 *   1. price the firm from today's data — before touching anything, so a firm
 *      that can't be priced is never voided;
 *   2. ask Stripe whether the old invoice is still open and unpaid (never
 *      trust our own copy: a firm may have paid a minute ago);
 *   3. void the old invoice in Stripe (Stripe itself refuses a paid one);
 *   4. put the row back to a draft (the existing "Issue again" mechanics —
 *      the old invoice id is retired, so a late Stripe event about it can't
 *      touch the new one), and store the new price on it, marked as
 *      replacing the old invoice;
 *   5. approve and create the new Stripe invoice;
 *   6. email it ("Updated: …", with the notice naming the invoice it replaces).
 *
 * Anything that fails before step 3 changes nothing. Anything that fails
 * after it stops there and says exactly where the firm stands and which
 * button finishes the job — the old invoice is already void by then, and
 * nothing here can bring it back.
 *
 * The review screen (Invoicing → "Update & resend") shows the change and the
 * email first; what it shows is fingerprinted, and run() refuses if the
 * firm's data moved since, so staff only ever confirm what they saw.
 */
class MyNJILGA_Invoice_Update {

    /** The statuses of an invoice that can be replaced: in Stripe, unpaid, not clearing. */
    const REPLACEABLE = [ 'created', 'sent' ];

    // -------------------------------------------------------------------------
    // PURE: may this invoice be replaced, and what would change
    // -------------------------------------------------------------------------

    /** PURE: why this row can't be replaced ('' when it can). */
    public static function refusal( object $row ): string {
        if ( (string) ( $row->invoice_kind ?? '' ) === 'join' ) {
            return 'An online join is someone\'s own payment — it isn\'t replaced.';
        }
        $status = (string) ( $row->status ?? '' );
        if ( ! in_array( $status, self::REPLACEABLE, true ) ) {
            $why = [
                'paid'       => 'This invoice is already paid, so it can\'t be replaced.',
                'processing' => 'A payment on this invoice is still clearing — wait for it to settle (or fail) before replacing it.',
                'voided'     => 'This invoice is voided — use Issue again instead.',
                'draft'      => 'This firm has no invoice in Stripe yet — Refresh Firms re-prices it, then Create.',
                'approved'   => 'This firm has no invoice in Stripe yet — Refresh Firms re-prices it, then Create.',
            ];
            return $why[ $status ] ?? 'Only an invoice that has been created or emailed, and is still unpaid, can be replaced.';
        }
        if ( (int) ( $row->amount_paid_cents ?? 0 ) > 0 || (int) ( $row->paid_off_stripe_cents ?? 0 ) > 0 ) {
            return 'Something was paid against this invoice — settle that in Stripe first; it can\'t be replaced.';
        }
        if ( (string) ( $row->gateway_invoice_id ?? '' ) === '' ) {
            return 'This invoice has no Stripe invoice on file, so there is nothing to replace.';
        }
        return '';
    }

    /**
     * PURE: what differs between the invoice as sent and as it would be re-priced.
     *
     * Members are matched by contact (falling back to name + email); each
     * line's amount is dues + assessment. A change is a member added, removed,
     * or whose name, amount, category/tier, reason for a $0, or assessment
     * wording differs — everything the invoice prints about them.
     *
     * @param array<string,mixed> $old snapshot of the invoice as sent
     * @param array<string,mixed> $new snapshot of the re-priced invoice
     * @return array{changes:array<int,array{kind:string,name:string,old_name:string,old_cents:int,new_cents:int,old_label:string,new_label:string}>,old_total:int,new_total:int,bill_to:array{changed:bool,old:string,new:string},firm:array{changed:bool,old:string,new:string},any:bool}
     */
    public static function diff( array $old, array $new ): array {
        $oldM = self::index_members( (array) ( $old['members'] ?? [] ) );
        $newM = self::index_members( (array) ( $new['members'] ?? [] ) );

        $changes = [];
        foreach ( $newM as $key => $m ) {
            if ( ! isset( $oldM[ $key ] ) ) {
                $changes[] = [ 'kind' => 'added', 'name' => self::member_name( $m ), 'old_name' => '', 'old_cents' => 0, 'new_cents' => self::member_cents( $m ), 'old_label' => '', 'new_label' => self::member_label( $m ) ];
                continue;
            }
            $o = $oldM[ $key ];
            // Everything the invoice prints about this person counts: their name, what they are
            // charged, the category and tier, why a $0 is $0, and the assessment's wording.
            if ( self::member_cents( $o ) !== self::member_cents( $m ) || self::member_label( $o ) !== self::member_label( $m ) || self::member_name( $o ) !== self::member_name( $m ) ) {
                $changes[] = [ 'kind' => 'changed', 'name' => self::member_name( $m ), 'old_name' => self::member_name( $o ), 'old_cents' => self::member_cents( $o ), 'new_cents' => self::member_cents( $m ), 'old_label' => self::member_label( $o ), 'new_label' => self::member_label( $m ) ];
            }
        }
        foreach ( $oldM as $key => $o ) {
            if ( ! isset( $newM[ $key ] ) ) {
                $changes[] = [ 'kind' => 'removed', 'name' => self::member_name( $o ), 'old_name' => self::member_name( $o ), 'old_cents' => self::member_cents( $o ), 'new_cents' => 0, 'old_label' => self::member_label( $o ), 'new_label' => '' ];
            }
        }

        $oldTo = (array) ( $old['bill_to'] ?? $old['owner'] ?? [] );
        $newTo = (array) ( $new['bill_to'] ?? $new['owner'] ?? [] );
        $toChanged = (int) ( $oldTo['contact_id'] ?? 0 ) !== (int) ( $newTo['contact_id'] ?? 0 )
            || strtolower( trim( (string) ( $oldTo['email'] ?? '' ) ) ) !== strtolower( trim( (string) ( $newTo['email'] ?? '' ) ) );

        $oldFirm = trim( (string) ( $old['company']['name'] ?? '' ) );
        $newFirm = trim( (string) ( $new['company']['name'] ?? '' ) );

        $oldTotal = 0;
        foreach ( $oldM as $m ) {
            $oldTotal += self::member_cents( $m );
        }
        $newTotal = 0;
        foreach ( $newM as $m ) {
            $newTotal += self::member_cents( $m );
        }

        $firmChanged = $oldFirm !== $newFirm;
        return [
            'changes'   => $changes,
            'old_total' => $oldTotal,
            'new_total' => $newTotal,
            'bill_to'   => [ 'changed' => $toChanged, 'old' => self::person_label( $oldTo ), 'new' => self::person_label( $newTo ) ],
            'firm'      => [ 'changed' => $firmChanged, 'old' => $oldFirm, 'new' => $newFirm ],
            'any'       => $changes !== [] || $toChanged || $firmChanged || $oldTotal !== $newTotal,
        ];
    }

    /**
     * PURE: a fingerprint of everything the review screen showed — the
     * invoice being replaced, and the invoice that would replace it. The
     * confirm button posts it; run() recomputes it and refuses on a mismatch.
     *
     * @param array<string,mixed> $newSnapshot
     */
    public static function fingerprint( object $row, array $newSnapshot ): string {
        $members = [];
        foreach ( (array) ( $newSnapshot['members'] ?? [] ) as $m ) {
            $members[] = [
                (int) ( $m['contact_id'] ?? 0 ),
                (int) ( $m['dues_cents'] ?? 0 ),
                (int) ( $m['assessment_cents'] ?? 0 ),
                self::member_label( (array) $m ),
                self::member_name( (array) $m ),
            ];
        }
        $to = (array) ( $newSnapshot['bill_to'] ?? [] );
        return hash( 'sha256', (string) json_encode( [
            (int) $row->id,
            (string) ( $row->status ?? '' ),
            (string) ( $row->gateway_invoice_id ?? '' ),
            (int) ( $row->total_amount_cents ?? 0 ),
            (int) ( $to['contact_id'] ?? 0 ),
            strtolower( (string) ( $to['email'] ?? '' ) ),
            (string) ( $newSnapshot['company']['name'] ?? '' ),
            $members,
        ] ) );
    }

    /** PURE: whether this invoice was ever emailed to the firm. */
    public static function was_emailed( object $row ): bool {
        return (string) ( $row->status ?? '' ) === 'sent' || (string) ( $row->sent_at ?? '' ) !== '';
    }

    /**
     * PURE: whether replacing this invoice emails the new one, and if not, why.
     *
     *  - Test mode: nothing is ever emailed to firms (a Test invoice's payment link can never
     *    collect money, and the bill-to is a real contact) — like every other sending path.
     *  - An invoice that was never emailed: the firm has never seen it, so there is nothing
     *    to "resend" and no earlier invoice for the notice to name. It is replaced quietly and
     *    goes out the way it would have — when it is emailed, or with its scheduled send.
     *
     * @return array{was_emailed:bool,will_email:bool,why_not:string} why_not: '' | 'test' | 'unsent'
     */
    public static function email_plan( object $row, bool $live ): array {
        $was = self::was_emailed( $row );
        if ( ! $live ) {
            return [ 'was_emailed' => $was, 'will_email' => false, 'why_not' => 'test' ];
        }
        return [ 'was_emailed' => $was, 'will_email' => $was, 'why_not' => $was ? '' : 'unsent' ];
    }

    /**
     * PURE: what the new invoice's notice should name. The invoice the firm actually received:
     * this one if it was emailed; otherwise the one this one itself replaced (a replacement whose
     * email failed, replaced again), or nothing.
     *
     * @return array<string,mixed> '' = no notice
     */
    public static function carry_replaces( object $row ): array {
        if ( self::was_emailed( $row ) ) {
            return self::replaces_from( $row );
        }
        $prior = MyNJILGA_Dues_Snapshot::decode( $row )['replaces'] ?? [];
        return is_array( $prior ) ? $prior : [];
    }

    /**
     * PURE: what the new invoice records about the one it replaces.
     *
     * @return array{number:string,id:string,total_cents:int}
     */
    public static function replaces_from( object $row ): array {
        return [
            'number'      => (string) ( $row->gateway_invoice_number ?? '' ),
            'id'          => (string) ( $row->gateway_invoice_id ?? '' ),
            'total_cents' => (int) ( $row->total_amount_cents ?? 0 ),
        ];
    }

    /**
     * PURE: what Stripe's own copy of the old invoice says about replacing it.
     * '' = open and unpaid (go ahead), 'void' = already void in Stripe (skip
     * the void), anything else is why to stop.
     *
     * @param array<string,mixed>|null $live gateway fetch_invoice()
     * @return array{go:bool,already_void:bool,error:string}
     */
    public static function stripe_verdict( ?array $live ): array {
        if ( $live === null ) {
            return [ 'go' => false, 'already_void' => false, 'error' => 'Stripe didn\'t return this invoice (no answer, or it isn\'t there) — nothing was changed. Try again in a moment; if it keeps happening, use Sync with Stripe.' ];
        }
        $status = (string) ( $live['status'] ?? '' );
        if ( (int) ( $live['amount_paid_cents'] ?? 0 ) > 0 || $status === 'paid' ) {
            return [ 'go' => false, 'already_void' => false, 'error' => 'Stripe shows this invoice as paid (or partly paid), so it was not replaced and nothing was changed. Click Sync with Stripe to bring this page up to date.' ];
        }
        // An ACH debit in flight leaves the invoice itself 'open' while its
        // payment intent is 'processing' — money is on its way, so don't void.
        $intent = $live['payment_intent'] ?? null;
        if ( $status === 'processing' || ( is_array( $intent ) && (string) ( $intent['status'] ?? '' ) === 'processing' ) ) {
            return [ 'go' => false, 'already_void' => false, 'error' => 'A payment on this invoice is clearing in Stripe, so it was not replaced and nothing was changed. Wait for it to settle, then Sync with Stripe.' ];
        }
        if ( $status === 'void' ) {
            return [ 'go' => true, 'already_void' => true, 'error' => '' ];
        }
        if ( $status !== 'open' ) {
            return [ 'go' => false, 'already_void' => false, 'error' => sprintf( 'Stripe lists this invoice as "%s", so it can\'t be replaced here and nothing was changed.', $status !== '' ? $status : 'unknown' ) ];
        }
        return [ 'go' => true, 'already_void' => false, 'error' => '' ];
    }

    /** @param array<int,array<string,mixed>> $members @return array<string,array<string,mixed>> */
    private static function index_members( array $members ): array {
        $out = [];
        foreach ( $members as $m ) {
            $m   = (array) $m;
            $key = (int) ( $m['contact_id'] ?? 0 ) > 0
                ? 'c' . (int) $m['contact_id']
                : 'n' . strtolower( trim( (string) ( $m['name'] ?? '' ) ) . '|' . trim( (string) ( $m['email'] ?? '' ) ) );
            $n = 1;
            $base = $key;
            while ( isset( $out[ $key ] ) ) {
                $key = $base . '#' . ( ++$n );
            }
            $out[ $key ] = $m;
        }
        return $out;
    }

    /** @param array<string,mixed> $m */
    private static function member_cents( array $m ): int {
        return (int) ( $m['dues_cents'] ?? 0 ) + (int) ( $m['assessment_cents'] ?? 0 );
    }

    /** @param array<string,mixed> $m */
    private static function member_name( array $m ): string {
        $name = trim( (string) ( $m['name'] ?? '' ) );
        return $name !== '' ? $name : 'Contact #' . (int) ( $m['contact_id'] ?? 0 );
    }

    /**
     * How the invoice describes this person's charge: category · tier, the reason a $0 is $0,
     * and the assessment's name and qualifier. Drives both the comparison and what the review shows.
     *
     * @param array<string,mixed> $m
     */
    private static function member_label( array $m ): string {
        $parts = array_filter( [ trim( (string) ( $m['category_label'] ?? '' ) ), trim( (string) ( $m['tier_label'] ?? '' ) ) ], static function ( $p ) { return $p !== ''; } );
        $label = implode( ' · ', $parts );
        $note  = trim( (string) ( ( $m['dues_note'] ?? '' ) !== '' ? $m['dues_note'] : ( $m['unbilled_reason'] ?? '' ) ) );
        if ( $note !== '' ) {
            $label .= ( $label !== '' ? ' — ' : '' ) . $note;
        }
        $assess = trim( (string) ( $m['assessment_label'] ?? '' ) );
        if ( (int) ( $m['assessment_cents'] ?? 0 ) > 0 && $assess !== '' ) {
            $qual   = trim( (string) ( $m['assessment_qualifier'] ?? '' ) );
            $label .= ( $label !== '' ? ' + ' : '' ) . $assess . ( $qual !== '' ? ' (' . $qual . ')' : '' );
        }
        return $label;
    }

    /** @param array<string,mixed> $p */
    private static function person_label( array $p ): string {
        $name  = trim( (string) ( $p['name'] ?? '' ) );
        $email = trim( (string) ( $p['email'] ?? '' ) );
        if ( $name !== '' && $email !== '' ) {
            return $name . ' (' . $email . ')';
        }
        return $name !== '' ? $name : ( $email !== '' ? $email : 'nobody' );
    }

    // -------------------------------------------------------------------------
    // Review: everything the confirmation screen shows
    // -------------------------------------------------------------------------

    /**
     * The payment gateway's reason it can't create an invoice right now ('' when it can).
     */
    private static function gateway_problem(): string {
        $gateway = MyNJILGA_Invoicing::gateway();
        if ( ! $gateway->is_available() ) {
            return $gateway->name() . ' is not active, so nothing was changed.';
        }
        $errs = $gateway->readiness_errors();
        return $errs ? $errs[0] . ' Nothing was changed.' : '';
    }

    /**
     * Price the firm from today's data and compare it to the invoice as sent.
     * No writes. 'ok' false carries the reason (and nothing else is set).
     *
     * 'email' is the updated invoice's email as compose() builds it (with a
     * placeholder where the payment link will be) — if it can't be built
     * (no address for the bill-to), that is a refusal here, before anything
     * is voided.
     *
     * 'plan' says whether the new invoice will be emailed, and if not, why (email_plan()).
     *
     * @return array{ok:bool,error:string,row:?object,candidate:?array<string,mixed>,snapshot:?array<string,mixed>,preview_row:?object,email:?array<string,mixed>,plan:?array<string,mixed>,diff:?array<string,mixed>,fingerprint:string}
     */
    public static function review( int $rowId ): array {
        $none = [ 'ok' => false, 'error' => '', 'row' => null, 'candidate' => null, 'snapshot' => null, 'preview_row' => null, 'email' => null, 'plan' => null, 'diff' => null, 'fingerprint' => '' ];
        $row  = $rowId > 0 ? MyNJILGA_Dues_Invoice_Table::get( $rowId ) : null;
        if ( ! $row ) {
            return [ 'error' => 'That invoice wasn\'t found.' ] + $none;
        }
        $why = self::refusal( $row );
        if ( $why !== '' ) {
            return [ 'error' => $why, 'row' => $row ] + $none;
        }
        $year = (int) $row->dues_year;
        $live = ! empty( $row->livemode );
        if ( $live !== ( MyNJILGA_Stripe_Connection::active_mode() === MyNJILGA_Stripe_Connection::MODE_LIVE ) ) {
            return [ 'error' => 'This invoice belongs to the other Stripe mode — switch modes to work on it.', 'row' => $row ] + $none;
        }
        if ( ! MyNJILGA_Invoicing::year_is_open_now( $year, $live ) ) {
            return [ 'error' => MyNJILGA_Invoicing::closed_year_message( $year, MyNJILGA_Year_Wipe::was_wiped( $year, $live ) ), 'row' => $row ] + $none;
        }
        // Waiting in a background email run: that run could email the new
        // invoice a second time. (An invoice waiting in a SCHEDULED send is
        // fine: it is emailed now, and the schedule skips what is already sent.)
        if ( MyNJILGA_Invoice_Creator::is_actively_queued( (string) ( $row->queued_at ?? '' ), current_time( 'mysql' ) ) ) {
            return [ 'error' => 'This invoice is waiting in an email run that is still going. Wait for it to finish, then try again.', 'row' => $row ] + $none;
        }
        $problem = self::gateway_problem();
        if ( $problem !== '' ) {
            return [ 'error' => $problem, 'row' => $row ] + $none;
        }

        $priced = MyNJILGA_Dues_Preview::candidate_for_row( $row );
        if ( ! $priced['ok'] ) {
            return [ 'error' => $priced['error'], 'row' => $row ] + $none;
        }
        $candidate = $priced['candidate'];
        $plan      = self::email_plan( $row, $live );
        $snapshot  = MyNJILGA_Dues_Preview::snapshot_for_candidate( $candidate, self::carry_replaces( $row ) ?: null );
        $diff      = self::diff( MyNJILGA_Dues_Snapshot::decode( $row ), $snapshot );
        if ( ! $diff['any'] ) {
            return [ 'error' => sprintf( 'Nothing about this firm\'s invoice has changed since it was created — priced from today\'s roster it comes to the same lines and total. To send the same invoice again, use %s.', $plan['was_emailed'] ? 'Email again' : 'Email' ), 'row' => $row ] + $none;
        }

        // The row as the new invoice will be: used to build the email preview
        // through the same compose() a real send uses (no link yet).
        $preview                       = clone $row;
        $preview->roster_snapshot      = MyNJILGA_Dues_Snapshot::encode( $snapshot );
        $preview->total_amount_cents   = (int) $candidate['totals']['total_cents'];
        $preview->hosted_invoice_url   = '';
        $preview->gateway_invoice_number = '';
        $preview->due_date             = null;

        $email = MyNJILGA_Invoice_Sender::compose( $preview, true );
        if ( empty( $email['ok'] ) ) {
            return [ 'error' => 'The updated invoice\'s email can\'t be built — ' . (string) ( $email['error'] ?? 'unknown reason' ) . ' Nothing was changed.', 'row' => $row ] + $none;
        }

        return [
            'ok'          => true,
            'error'       => '',
            'row'         => $row,
            'candidate'   => $candidate,
            'snapshot'    => $snapshot,
            'preview_row' => $preview,
            'email'       => $email,
            'plan'        => $plan,
            'diff'        => $diff,
            'fingerprint' => self::fingerprint( $row, $snapshot ),
        ];
    }

    // -------------------------------------------------------------------------
    // Run
    // -------------------------------------------------------------------------

    /**
     * Replace the firm's invoice with an updated one and email it.
     *
     * @param string $fingerprint what the review screen showed (review()['fingerprint'])
     * @return array{ok:bool,error:string,warning:string,year:int,number:string,voided:bool,mail:string} mail: ''
     *   (nothing was created) | 'sent' | 'failed' | 'test' | 'unsent' (created, deliberately not emailed — see email_plan())
     */
    public static function run( int $rowId, string $fingerprint ): array {
        $first = $rowId > 0 ? MyNJILGA_Dues_Invoice_Table::get( $rowId ) : null;
        $year  = $first ? (int) $first->dues_year : 0;
        $out   = static function ( bool $ok, string $error, string $warning = '', string $number = '', bool $voided = false, string $mail = '' ) use ( $year ): array {
            return [ 'ok' => $ok, 'error' => $error, 'warning' => $warning, 'year' => $year, 'number' => $number, 'voided' => $voided, 'mail' => $mail ];
        };

        // Once the old invoice is void there is no going back, and the steps after it make several
        // Stripe calls (each may wait 20 s) and send an email: don't let a closed browser tab or a
        // short web-server time limit stop it half way.
        if ( function_exists( 'ignore_user_abort' ) ) {
            ignore_user_abort( true );
        }
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        }

        // The lock FIRST, the look at the invoice second. It is the lock a scheduled send holds
        // while it emails, so this firm's invoice is neither mid-send nor about to be sent while
        // it is replaced — and it serialises two confirmations of the same page: the second one
        // gets the lock only after the first has finished, and then reads the invoice as it now is
        // (the first's replacement), so its fingerprint no longer matches and it does nothing.
        // Read before the lock, it would act on the old invoice, find it already void in Stripe,
        // and issue a second live invoice beside the first's.
        $lock = MyNJILGA_Launch_Schedule::acquire_lock();
        if ( $lock === '' ) {
            return $out( false, 'An invoice send or another update is running right now, so nothing was changed. Try again when it has finished.' );
        }
        // A fatal error (a time limit) skips `finally`; shutdown functions still run. Releasing is
        // idempotent and only ever deletes our own token.
        register_shutdown_function( static function () use ( $lock ) { MyNJILGA_Launch_Schedule::release_lock( $lock ); } );
        try {
            $rev = self::review( $rowId );
            if ( ! $rev['ok'] ) {
                return $out( false, $rev['error'] );
            }
            if ( $fingerprint === '' || ! hash_equals( $rev['fingerprint'], $fingerprint ) ) {
                return $out( false, 'The firm\'s roster, prices or invoice changed after you opened the review, so nothing was done. Open the review again to see the current change, then confirm it.' );
            }
            return self::replace( $rev, $out );
        } catch ( \Throwable $e ) {
            return $out( false, 'The update stopped unexpectedly (' . $e->getMessage() . '). Check the firm\'s row on the Invoicing page before doing anything else — it may have been voided already.' );
        } finally {
            MyNJILGA_Launch_Schedule::release_lock( $lock );
        }
    }

    /**
     * The steps, after every check has passed.
     *
     * @param array<string,mixed> $rev review()'s ok result
     * @return array{ok:bool,error:string,warning:string,year:int,number:string,voided:bool,mail:string}
     */
    private static function replace( array $rev, callable $out ): array {
        $row       = $rev['row'];
        $rowId     = (int) $row->id;
        $year      = (int) $row->dues_year;
        $company   = (int) $row->fluentcrm_company_id;
        $gateway   = MyNJILGA_Invoicing::gateway();
        $oldId     = (string) $row->gateway_invoice_id;
        $oldNumber = (string) ( $row->gateway_invoice_number ?? '' );
        $oldLabel  = $oldNumber !== '' ? $oldNumber : $oldId;
        $actor     = wp_get_current_user();
        $who       = ( $actor && ! empty( $actor->display_name ) ) ? (string) $actor->display_name : 'A staff member';

        // Stripe's own word on the old invoice — before anything changes.
        $verdict = self::stripe_verdict( $gateway->fetch_invoice( $oldId ) );
        if ( ! $verdict['go'] ) {
            return $out( false, $verdict['error'] );
        }

        // ---- Point of no return: void the old invoice. Stripe refuses a paid one. ----
        if ( ! $verdict['already_void'] ) {
            $voided = $gateway->void_invoice( $oldId );
            if ( empty( $voided['ok'] ) ) {
                return $out( false, sprintf( '%s would not void the old invoice, so nothing was changed: %s', $gateway->name(), (string) ( $voided['error'] ?? 'no reason given.' ) ) );
            }
        }
        MyNJILGA_Dues_Invoice_Table::update_gateway_fields( $rowId, [
            'status'    => MyNJILGA_Dues_Invoice_Table::STATUS_VOIDED,
            'voided_at' => current_time( 'mysql' ),
        ] );
        MyNJILGA_Invoicing_Notes::log(
            $company,
            'Invoice voided to be replaced',
            sprintf( 'The %d dues invoice %s was voided by %s so an updated invoice could replace it.', $year, $oldLabel, $who )
        );
        $stuck = sprintf( 'The old invoice %s IS voided in Stripe. ', $oldLabel );

        // ---- Back to a draft (the "Issue again" mechanics), then the new price on it ----
        // The old id is retired BEFORE the row is reopened: the reopen clears the row's invoice id,
        // and until the old id is retired the old invoice's own `invoice.voided` notice from Stripe
        // would match the (now id-less) row and write "voided" onto the draft. Retiring first is
        // safe: if the reopen then fails the row is voided already, and the old invoice is void.
        MyNJILGA_Dues_Invoice_Table::retire_invoice_id( $oldId, $oldNumber, $row );
        if ( ! MyNJILGA_Dues_Invoice_Table::reopen_voided( $rowId ) ) {
            return $out( false, $stuck . 'This site then could not put the firm back to ready, because the invoice changed while this ran. Reload the Invoicing page: if the firm shows as voided, click Issue again.', '', '', true );
        }
        if ( class_exists( 'MyNJILGA_Stripe_Invoice_Gateway' ) ) {
            MyNJILGA_Stripe_Invoice_Gateway::fresh_attempt( $rowId );
        }
        // If anything has put the firm back to "voided" since, Refresh Firms and Create can't
        // help: only Issue again can.
        $voidedAdvice = 'The firm shows as voided again, so click Issue again on its row, then Refresh Firms and Create.';
        $isVoided     = static function () use ( $rowId ): bool {
            $r = MyNJILGA_Dues_Invoice_Table::get( $rowId );
            return $r && (string) $r->status === MyNJILGA_Dues_Invoice_Table::STATUS_VOIDED;
        };

        $livemode = ! empty( $row->livemode );
        $savedId  = MyNJILGA_Dues_Preview::persist_replacement( $rev['candidate'], $livemode, self::carry_replaces( $row ) );
        if ( $savedId !== $rowId ) {
            return $out( false, $stuck . ( $isVoided() ? $voidedAdvice : 'The firm is back under No Invoice, but its new price could not be saved. Click Refresh Firms to re-price it, then Create.' ), '', '', true );
        }

        // ---- Approve and create the new invoice ----
        if ( MyNJILGA_Dues_Invoice_Table::mark_approved( [ $rowId ] ) < 1 ) {
            return $out( false, $stuck . ( $isVoided() ? $voidedAdvice : 'The firm was re-priced but could not be approved for creation. Click Create on its row under No Invoice.' ), '', '', true );
        }
        $fresh = MyNJILGA_Dues_Invoice_Table::get( $rowId );
        try {
            $created = $fresh ? MyNJILGA_Invoice_Creator::create_for_row( $fresh ) : [ 'ok' => false, 'error' => 'The invoice row disappeared.' ];
        } catch ( \Throwable $e ) {
            $created = [ 'ok' => false, 'error' => $e->getMessage() ];
        }
        if ( empty( $created['ok'] ) ) {
            $why = (string) ( $created['error'] ?? 'Unknown error' );
            MyNJILGA_Dues_Invoice_Table::set_error( $rowId, $why );
            $now  = MyNJILGA_Dues_Invoice_Table::get( $rowId );
            $next = ( $now && (string) $now->status === MyNJILGA_Dues_Invoice_Table::STATUS_APPROVED )
                ? 'Click Retry on its row to create it.'
                : 'The firm is back under No Invoice — do what that says, then Create.';
            return $out( false, $stuck . 'The firm was re-priced, but the new invoice could not be created: ' . $why . ' ' . $next, '', '', true );
        }

        $fresh  = MyNJILGA_Dues_Invoice_Table::get( $rowId );
        $number = $fresh ? (string) ( $fresh->gateway_invoice_number ?? '' ) : '';
        MyNJILGA_Invoicing_Notes::log(
            $company,
            'Updated dues invoice created',
            sprintf(
                'The %d dues invoice %s (%s) was replaced by %s (%s) after changes to the firm\'s invoicing. Done by %s.',
                $year,
                $oldLabel,
                MyNJILGA_Invoicing::money( (int) $row->total_amount_cents ),
                $number !== '' ? $number : 'a new invoice',
                MyNJILGA_Invoicing::money( (int) ( $fresh->total_amount_cents ?? 0 ) ),
                $who
            )
        );

        // ---- Email it — unless it should not be (Test mode; an invoice the firm never received) ----
        $plan = (array) $rev['plan'];
        if ( empty( $plan['will_email'] ) ) {
            return $out( true, '', '', $number, true, (string) ( $plan['why_not'] ?: 'unsent' ) );
        }
        try {
            $sent = $fresh ? MyNJILGA_Invoice_Sender::send_for_row( $fresh ) : [ 'ok' => false, 'error' => 'The invoice row disappeared.' ];
        } catch ( \Throwable $e ) {
            $sent = [ 'ok' => false, 'error' => $e->getMessage() ];
        }
        if ( empty( $sent['ok'] ) ) {
            $why = (string) ( $sent['error'] ?? 'Unknown error' );
            if ( isset( $sent['claimed'] ) && $sent['claimed'] === false ) {
                // Another sender emailed this very invoice in the same moment: it did go.
                return $out( true, '', 'The updated invoice was created, and another email send picked it up at the same moment — check the firm\'s row shows it as emailed.', $number, true, 'sent' );
            }
            MyNJILGA_Dues_Invoice_Table::set_error( $rowId, $why );
            return $out( true, '', 'The updated invoice was created, but its email did not go: ' . $why . ' Click Email on the firm\'s row to send it.', $number, true, 'failed' );
        }
        $warning = (string) ( $sent['warning'] ?? '' );
        return $out( true, '', $warning, $number, true, 'sent' );
    }
}
