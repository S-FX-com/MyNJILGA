<?php
/**
 * Turns a paid online join into membership — the one place that does.
 *
 * Everything that reaches here is either a confirmed Stripe payment (the
 * Checkout Session the join paid through, re-fetched and checked against
 * the join record) or a staff approval of a join that came to $0. From
 * that, in order:
 *
 *   1. the firm — the Company chosen on the form, an exact-name match for
 *      one typed in, or a new Company with the payer as Owner;
 *   2. FluentCRM contacts for the payer and every colleague they paid for
 *      (found by email, created if new — never resubscribing anyone),
 *      attached to the firm, tagged with the category;
 *   3. an `njilga_dues_invoices` row (kind 'join') whose frozen snapshot
 *      names all of them, so the Payments ledger, the firm status page,
 *      the downgrade sweep and next year's batch all see the join exactly
 *      as they see an invoice;
 *   4. the payment ledger row, then MyNJILGA_Payment_Listener::settle() —
 *      the same tags/roles/Company Note every paid invoice gets;
 *   5. an invitation to each colleague to create a website account, a
 *      welcome email to the payer, and a note to staff.
 *
 * Idempotent and concurrency-safe: the webhook, the Checkout return page,
 * the daily sweep and staff buttons can all fire for the same join, at
 * the same moment. MyNJILGA_Join_Orders_Table::claim() lets exactly one
 * run proceed, and each side effect above is recorded in the join's
 * `progress` column as it completes, so a run that dies halfway resumes
 * where it stopped without sending anything twice.
 */
class MyNJILGA_Join_Fulfillment {

    /**
     * Public mailbox providers. An address at one says nothing about where
     * someone works, so these never count as a firm's own domain (see
     * domain_matches()). Kept broad on purpose: a firm domain wrongly
     * listed here only means its joiners wait for staff, while a public
     * domain missing from it lets anyone who opens a free mailbox there
     * attach themselves, unchecked, to any firm with a member using it.
     * Sites adjust it with the `my_njilga_free_mail_domains` filter.
     */
    const FREE_MAIL_DOMAINS = [
        // Google, Microsoft, Yahoo, AOL, Apple.
        'gmail.com', 'googlemail.com', 'outlook.com', 'hotmail.com', 'live.com', 'msn.com', 'passport.com', 'windowslive.com',
        'yahoo.com', 'ymail.com', 'rocketmail.com', 'aol.com', 'aim.com', 'icloud.com', 'me.com', 'mac.com', 'privaterelay.appleid.com',
        // US broadband and phone companies (many still issue mailboxes).
        'comcast.net', 'xfinity.com', 'verizon.net', 'att.net', 'sbcglobal.net', 'bellsouth.net', 'pacbell.net', 'swbell.net',
        'ameritech.net', 'flash.net', 'prodigy.net', 'snet.net', 'wans.net', 'nvbell.net', 'optonline.net', 'optimum.net',
        'cox.net', 'charter.net', 'spectrum.net', 'rr.com', 'twc.com', 'earthlink.net', 'mindspring.com', 'frontier.com',
        'frontiernet.net', 'centurylink.net', 'centurytel.net', 'embarqmail.com', 'q.com', 'windstream.net', 'suddenlink.net',
        'mediacombb.net', 'wowway.com', 'atlanticbb.net', 'rcn.com', 'erols.com', 'patmedia.net', 'ptd.net', 'epix.net',
        'juno.com', 'netzero.com', 'netzero.net', 'peoplepc.com', 'excite.com', 'lycos.com', 'usa.net', 'cs.com', 'compuserve.com',
        // Privacy and independent providers, and address-forwarding services.
        'protonmail.com', 'protonmail.ch', 'proton.me', 'pm.me', 'tutanota.com', 'tutanota.de', 'tutamail.com', 'tuta.io', 'tuta.com',
        'keemail.me', 'hushmail.com', 'hush.com', 'mailfence.com', 'posteo.de', 'posteo.net', 'runbox.com', 'startmail.com',
        'fastmail.com', 'fastmail.fm', 'hey.com', 'duck.com', 'mozmail.com', 'simplelogin.com', 'simplelogin.co', 'aleeas.com',
        'anonaddy.com', 'anonaddy.me', 'addy.io', 'disroot.org', 'riseup.net', 'zoho.com', 'zohomail.com', 'zohomail.eu',
        'inbox.com', 'mail2world.com',
        // mail.com and its free "vanity" domains.
        'mail.com', 'email.com', 'usa.com', 'post.com', 'myself.com', 'iname.com', 'writeme.com', 'cheerful.com', 'europe.com',
        'asia.com', 'dr.com', 'doctor.com', 'engineer.com', 'techie.com', 'consultant.com', 'accountant.com', 'lawyer.com',
        'counsellor.com', 'financier.com', 'workmail.com', 'contractor.net', 'graduate.org', 'teacher.com', 'politician.com',
        'minister.com', 'secretary.net', 'socialworker.net', 'representative.com', 'activist.com', 'musician.org', 'programmer.net',
        // GMX, Yandex and other European and international providers.
        'gmx.com', 'gmx.us', 'gmx.net', 'web.de', 't-online.de', 'freenet.de', 'arcor.de', 'yandex.com', 'yandex.ru', 'ya.ru',
        'mail.ru', 'bk.ru', 'inbox.ru', 'list.ru', 'rambler.ru', 'libero.it', 'virgilio.it', 'tiscali.it', 'alice.it', 'tin.it',
        'orange.fr', 'wanadoo.fr', 'free.fr', 'laposte.net', 'sfr.fr', 'neuf.fr', 'btinternet.com', 'sky.com', 'virginmedia.com',
        'blueyonder.co.uk', 'ntlworld.com', 'talktalk.net', 'seznam.cz', 'wp.pl', 'o2.pl', 'onet.pl', 'interia.pl',
        'shaw.ca', 'rogers.com', 'sympatico.ca', 'bell.net', 'telus.net', 'videotron.ca', 'bigpond.com', 'bigpond.net.au',
        'optusnet.com.au', 'xtra.co.nz', 'terra.com.br', 'uol.com.br', 'bol.com.br', 'rediffmail.com', 'qq.com', 'foxmail.com',
        '163.com', '126.com', 'yeah.net', 'sina.com', 'sohu.com', 'aliyun.com', 'naver.com', 'daum.net', 'hanmail.net',
    ];

    /**
     * Providers that run the same mailbox service under many country
     * domains (yahoo.co.uk, hotmail.fr, gmx.de, yandex.kz) — matched by
     * name, so none of those needs a list entry of its own.
     */
    const FREE_MAIL_FAMILIES = [ 'gmail', 'googlemail', 'yahoo', 'ymail', 'hotmail', 'outlook', 'live', 'windowslive', 'msn', 'aol', 'gmx', 'yandex', 'protonmail', 'tutanota' ];

    // -------------------------------------------------------------------------
    // Entry points
    // -------------------------------------------------------------------------

    /**
     * Check a join against Stripe and fulfill it if it has been paid.
     * Always re-reads the join's current Checkout Session from Stripe —
     * whatever woke this up (a webhook payload, a return URL) is only a
     * hint about which join to look at.
     *
     * @return array{ok:bool,status:string,message:string}
     */
    public static function sync( int $joinId, string $trigger ): array {
        $join = MyNJILGA_Join_Orders_Table::get( $joinId );
        if ( ! $join ) {
            return self::result( false, '', 'Join not found.' );
        }
        $T = 'MyNJILGA_Join_Orders_Table';

        if ( $join->status === $T::STATUS_FULFILLED ) {
            return self::result( true, $join->status, 'Already fulfilled.' );
        }
        // Closed by us, but its checkout may still have been paid (a
        // resume opening a new checkout while the old one was being
        // expired, say) — asked below, so that money is never dropped.
        $closed = in_array( $join->status, [ $T::STATUS_EXPIRED, $T::STATUS_ABANDONED, $T::STATUS_FAILED ], true );
        if ( ! $closed && ! in_array( $join->status, [ $T::STATUS_PENDING, $T::STATUS_PROCESSING, $T::STATUS_PAID, $T::STATUS_FULFILLING ], true ) ) {
            return self::result( false, $join->status, 'This join is closed (' . $join->status . ').' );
        }
        if ( (string) $join->stripe_session_id === '' ) {
            // A $0 join staff approved whose run died part-way (a fatal
            // error or timeout — run()'s catch never ran) is left claimed
            // with no checkout. Take the stale claim over, as
            // approve_review() would, and run() resumes from `progress`.
            if ( $join->status === $T::STATUS_FULFILLING && (int) $join->total_cents === 0 ) {
                if ( ! MyNJILGA_Join_Orders_Table::claim( $joinId, [] ) ) {
                    return self::result( false, $join->status, 'Another process is completing this join — try again in a few minutes.' );
                }
                return self::run( $joinId, $trigger, null );
            }
            return self::result( false, $join->status, $closed ? 'This join is closed (' . $join->status . ').' : 'No checkout on file for this join.' );
        }

        $mode = ! empty( $join->livemode ) ? MyNJILGA_Stripe_Connection::MODE_LIVE : MyNJILGA_Stripe_Connection::MODE_TEST;

        // Every decision below rests on Stripe's current word, with the
        // payment and invoice expanded.
        $gateway = self::checkout_gateway();
        if ( ! $gateway ) {
            return self::result( false, $join->status, 'The payment gateway cannot take checkouts.' );
        }
        $fresh = $gateway->fetch_checkout( (string) $join->stripe_session_id, $mode );
        if ( $fresh === null ) {
            return self::result( false, $join->status, 'Stripe could not be reached to check this payment — it will be retried.' );
        }

        $status  = (string) $fresh['status'];
        $payment = (string) $fresh['payment_status'];
        // Every transition below holds only while the join still carries
        // the checkout just fetched.
        $checked = [ 'stripe_session_id' => (string) $join->stripe_session_id ];

        if ( $closed ) {
            if ( $status !== 'complete' || $payment !== 'paid' ) {
                return self::result( false, $join->status, 'This join is closed (' . $join->status . ').' );
            }
            // Stripe took the money anyway. Reopen the join as paid, so it
            // is applied like any other (or, if it doesn't verify, waits
            // on the Online joins screen) and staff are told why.
            $reopened = MyNJILGA_Join_Orders_Table::transition( $joinId, [ $join->status ], $T::STATUS_PAID, [
                'paid_at'    => current_time( 'mysql' ),
                'last_error' => sprintf( 'Stripe took payment on this join\'s checkout after the join had been closed (%s).', $join->status ),
            ], $checked );
            if ( ! $reopened ) {
                $now = MyNJILGA_Join_Orders_Table::get( $joinId );
                return self::result( false, $now ? (string) $now->status : '', 'This join changed while it was being checked.' );
            }
            $progress                  = MyNJILGA_Join_Orders_Table::json( $join, 'progress' );
            $progress['reopened_from'] = (string) $join->status;
            MyNJILGA_Join_Orders_Table::update( $joinId, [ 'progress' => $progress ] );
            $join = MyNJILGA_Join_Orders_Table::get( $joinId );
            if ( ! $join ) {
                return self::result( false, '', 'Join not found.' );
            }
        }

        $problem = self::verify_session( $join, $fresh );
        if ( $problem !== '' ) {
            // Money that was taken is still money taken: mark the join
            // paid so staff see it as such, but apply nothing until a
            // human has looked at why it doesn't match.
            if ( $status === 'complete' && $payment === 'paid' ) {
                MyNJILGA_Join_Orders_Table::transition( $joinId, [ $T::STATUS_PENDING, $T::STATUS_PROCESSING ], $T::STATUS_PAID, [ 'paid_at' => current_time( 'mysql' ) ], $checked );
            }
            MyNJILGA_Join_Orders_Table::update( $joinId, [ 'last_error' => $problem ] );
            return self::result( false, $join->status, $problem );
        }

        if ( $status === 'expired' ) {
            MyNJILGA_Join_Orders_Table::transition( $joinId, [ $T::STATUS_PENDING ], $T::STATUS_EXPIRED, [], $checked );
            return self::result( false, $T::STATUS_EXPIRED, 'The checkout expired without payment.' );
        }
        if ( $status === 'open' ) {
            return self::result( false, $join->status, 'Waiting for payment.' );
        }
        if ( $payment === 'unpaid' ) {
            // Complete but not yet paid: an ACH debit is clearing — unless
            // the bank already refused it. That normally arrives as
            // checkout.session.async_payment_failed, but an endpoint set
            // up by hand may not carry that event, and without this the
            // join would sit "processing" for good, locking everyone on
            // it out of joining again.
            $failed = self::bank_payment_failure( $fresh );
            if ( $failed !== null ) {
                self::fail_bank_payment( $join, $failed );
                $now = MyNJILGA_Join_Orders_Table::get( $joinId );
                return self::result( false, $now ? (string) $now->status : $T::STATUS_FAILED, 'The bank payment failed.' );
            }
            MyNJILGA_Join_Orders_Table::transition( $joinId, [ $T::STATUS_PENDING ], $T::STATUS_PROCESSING, [
                'stripe_payment_intent_id' => (string) $fresh['payment_intent_id'],
                'stripe_customer_id'       => (string) $fresh['customer'],
            ], $checked );
            return self::result( true, $T::STATUS_PROCESSING, 'Bank payment is processing.' );
        }
        if ( $payment !== 'paid' && $payment !== 'no_payment_required' ) {
            return self::result( false, $join->status, 'Stripe reports payment status "' . $payment . '".' );
        }

        if ( ! MyNJILGA_Join_Orders_Table::claim( $joinId, [ $T::STATUS_PENDING, $T::STATUS_PROCESSING, $T::STATUS_PAID ] ) ) {
            $now = MyNJILGA_Join_Orders_Table::get( $joinId );
            return self::result( $now && $now->status === $T::STATUS_FULFILLED, $now ? (string) $now->status : '', 'Another process is completing this join.' );
        }

        $fields = [
            'paid_at'                  => $join->paid_at ?: current_time( 'mysql' ),
            'stripe_payment_intent_id' => (string) $fresh['payment_intent_id'],
            'stripe_customer_id'       => (string) $fresh['customer'],
            'stripe_invoice_id'        => (string) $fresh['invoice_id'],
        ];
        // The money is settled and checked against this join. Recorded,
        // so that if this run fails part-way the join, back at 'paid',
        // still counts as paying for its people — unlike a 'paid' join
        // held back by verify_session() above, which covers nobody until
        // staff decide (MyNJILGA_Join_Orders_Table::payment_settled()).
        $claimed = MyNJILGA_Join_Orders_Table::get( $joinId );
        if ( $claimed ) {
            $progress                      = MyNJILGA_Join_Orders_Table::json( $claimed, 'progress' );
            $progress['payment_confirmed'] = true;
            $fields['progress']            = $progress;
        }
        MyNJILGA_Join_Orders_Table::update( $joinId, $fields );

        return self::run( $joinId, $trigger, $fresh );
    }

    /**
     * Staff approved a join that came to $0 — nothing was charged, so
     * there is no session to check; the approval is the go-ahead.
     *
     * @return array{ok:bool,status:string,message:string}
     */
    public static function approve_review( int $joinId, int $byUserId, string $note ): array {
        $T = 'MyNJILGA_Join_Orders_Table';
        if ( ! MyNJILGA_Join_Orders_Table::claim( $joinId, [ $T::STATUS_REVIEW ] ) ) {
            return self::result( false, '', 'This join is no longer waiting for review.' );
        }
        MyNJILGA_Join_Orders_Table::update( $joinId, [ 'decided_by' => $byUserId, 'decision_note' => $note ] );
        return self::run( $joinId, 'staff approval', null );
    }

    /**
     * @return array{ok:bool,status:string,message:string}
     */
    public static function reject_review( int $joinId, int $byUserId, string $note ): array {
        $T    = 'MyNJILGA_Join_Orders_Table';
        $join = MyNJILGA_Join_Orders_Table::get( $joinId );
        if ( ! $join || ! MyNJILGA_Join_Orders_Table::transition( $joinId, [ $T::STATUS_REVIEW ], $T::STATUS_REJECTED, [ 'decided_by' => $byUserId, 'decision_note' => $note ] ) ) {
            return self::result( false, '', 'This join is no longer waiting for review.' );
        }
        self::join_mail(
            $join,
            (string) $join->email,
            'Your NJILGA membership',
            sprintf(
                "Hi %s,\n\nThank you for your interest in NJILGA. We're unable to confirm your membership at this time.%s\n\nIf you have questions, please reply to this email.\n\nNJILGA",
                $join->first_name,
                $note !== '' ? "\n\n" . $note : ''
            )
        );
        return self::result( true, $T::STATUS_REJECTED, sprintf( 'Rejected %s %s.', $join->first_name, $join->last_name ) );
    }

    /**
     * Webhook entry for checkout.session.* events. Returns the join id the
     * event concerned (null when it isn't one of ours).
     *
     * @param array<string,mixed> $object The event's Checkout Session.
     */
    public static function handle_checkout_event( string $type, array $object, bool $livemode ): ?int {
        $join = self::join_for_session_object( $object );
        if ( ! $join || (bool) $join->livemode !== $livemode ) {
            return null;
        }
        $T = 'MyNJILGA_Join_Orders_Table';

        switch ( $type ) {
            case 'checkout.session.async_payment_failed':
                self::fail_bank_payment( $join, '' );
                break;
            case 'checkout.session.expired':
                // Only while this is still the join's checkout — a resume
                // may have given it a new one since the event was sent.
                MyNJILGA_Join_Orders_Table::transition( (int) $join->id, [ $T::STATUS_PENDING ], $T::STATUS_EXPIRED, [], [ 'stripe_session_id' => (string) ( $object['id'] ?? '' ) ] );
                break;
            default: // completed / async_payment_succeeded
                self::sync( (int) $join->id, 'webhook' );
        }
        return (int) $join->id;
    }

    /**
     * The applicant's bank refused the ACH debit: close the join (only
     * while it still carries the checkout that failed) and tell them.
     * Reached from the async_payment_failed event, or from sync() seeing
     * the failure on the PaymentIntent when that event never arrives.
     */
    private static function fail_bank_payment( object $join, string $detail ): bool {
        $T      = 'MyNJILGA_Join_Orders_Table';
        $failed = MyNJILGA_Join_Orders_Table::transition(
            (int) $join->id,
            [ $T::STATUS_PENDING, $T::STATUS_PROCESSING ],
            $T::STATUS_FAILED,
            [ 'last_error' => 'The bank payment failed.' . ( $detail !== '' ? ' Stripe said: ' . $detail : '' ) ],
            [ 'stripe_session_id' => (string) $join->stripe_session_id ]
        );
        if ( $failed ) {
            self::join_mail(
                $join,
                (string) $join->email,
                'Your NJILGA membership payment did not go through',
                sprintf(
                    "Hi %s,\n\nYour bank reported that the payment for your NJILGA membership could not be completed, so your membership has not been activated.\n\nYou can try again, with a card or another account, here:\n%s\n\nNJILGA",
                    $join->first_name,
                    (string) $join->source_url
                )
            );
            try {
                self::flag_rows_priced_by_failed_join( $join );
            } catch ( \Throwable $e ) {
                // A courtesy to staff; the failure itself is recorded.
            }
        }
        return $failed;
    }

    /**
     * A join whose bank payment failed paid for nobody — but a firm
     * invoice priced while it was clearing may still list its people at
     * $0 as covered by it: 3.1.3 priced a clearing join as paid (nothing
     * does now — see MyNJILGA_Dues_Preview::join_coverage()). Paid, such
     * an invoice would make them members for free, so each one still open
     * is flagged on its row: an approved one also goes back to draft, for
     * Refresh Firms to re-price, and one already out says what is unpaid.
     * Anyone a payment that HAS settled covers after all is left alone.
     */
    private static function flag_rows_priced_by_failed_join( object $join ): void {
        $year     = (int) $join->dues_year;
        $livemode = ! empty( $join->livemode );
        $covered  = MyNJILGA_Dues_Preview::join_coverage( $year, $livemode )['covered'];
        $hits     = []; // row id => [ row, names[] ]
        foreach ( self::contact_ids_for( $join ) as $cid ) {
            if ( isset( $covered[ $cid ] ) ) {
                continue;
            }
            foreach ( MyNJILGA_Dues_Invoice_Table::open_rows_listing_contact( $cid, $year, $livemode ) as $row ) {
                $m = MyNJILGA_Dues_Invoice_Table::listed_member( $row, $cid );
                if ( $m && MyNJILGA_Dues_Preview::priced_as_join_covered( $m ) ) {
                    $hits[ (int) $row->id ]['row']     = $row;
                    $hits[ (int) $row->id ]['names'][] = (string) ( $m['name'] ?? '' ) !== '' ? (string) $m['name'] : 'Contact #' . $cid;
                }
            }
        }
        $T = 'MyNJILGA_Dues_Invoice_Table';
        foreach ( $hits as $rowId => $hit ) {
            $names  = implode( ', ', $hit['names'] );
            $status = (string) $hit['row']->status;
            if ( $status === $T::STATUS_APPROVED && ! MyNJILGA_Dues_Invoice_Table::return_to_draft( $rowId ) ) {
                // Moved on meanwhile (created, most likely): say it as it is now.
                $now    = MyNJILGA_Dues_Invoice_Table::get( $rowId );
                $status = $now ? (string) $now->status : $status;
            }
            if ( in_array( $status, [ $T::STATUS_DRAFT, $T::STATUS_APPROVED ], true ) ) {
                $message = sprintf( '%s %s listed at $0 as paid by online join #%d, whose bank payment has failed — click Refresh Firms to re-price this invoice before creating it.', $names, count( $hit['names'] ) === 1 ? 'is' : 'are', (int) $join->id );
            } else {
                $message = sprintf( 'This invoice went out listing %s at $0 as paid by online join #%d, whose bank payment has since failed: their %d dues are unpaid, and paying this invoice would still make them members. Bill them separately, or void it and create it again.', $names, (int) $join->id, $year );
            }
            MyNJILGA_Dues_Invoice_Table::set_error( $rowId, $message );
        }
    }

    /**
     * Whether a completed-but-unpaid checkout's bank debit has already
     * failed: its PaymentIntent went back to needing a payment method,
     * was canceled (microdeposit verification ran out, say), or carries
     * a payment error while no longer working on the debit. Null = not
     * failed (still clearing, or waiting on the payer to verify);
     * otherwise Stripe's reason, '' when it gave none. Pure — tested
     * directly.
     *
     * @param array<string,mixed> $session Normalized checkout.
     */
    public static function bank_payment_failure( array $session ): ?string {
        $pi = is_array( $session['payment_intent'] ?? null ) ? $session['payment_intent'] : null;
        if ( $pi === null ) {
            return null; // Not expanded — nothing to judge by.
        }
        $status = (string) ( $pi['status'] ?? '' );
        $error  = $pi['last_payment_error'] ?? null;
        $reason = is_array( $error ) ? (string) ( $error['message'] ?? '' ) : '';
        if ( in_array( $status, [ 'requires_payment_method', 'canceled' ], true ) ) {
            return $reason;
        }
        if ( ! empty( $error ) && ! in_array( $status, [ 'processing', 'requires_action', 'requires_capture', 'succeeded' ], true ) ) {
            return $reason;
        }
        return null;
    }

    /**
     * Webhook entry for invoice.paid on a join's post-payment invoice.
     * A join invoice is settled ONLY here — never through the generic
     * invoice.paid path, which would book it a second time.
     *
     * @param array<string,mixed> $invoice
     */
    public static function handle_invoice_paid( array $invoice, bool $livemode ): ?int {
        $joinId = (int) ( $invoice['metadata']['njilga_join_id'] ?? 0 );
        $join   = $joinId > 0 ? MyNJILGA_Join_Orders_Table::get( $joinId ) : null;
        if ( ! $join || (bool) $join->livemode !== $livemode ) {
            return null;
        }
        self::sync( (int) $join->id, 'webhook' );
        self::attach_invoice( (int) $join->id, $invoice );
        return (int) $join->id;
    }

    /**
     * Stripe makes a Checkout's post-payment invoice once the payment is
     * complete — which can be after fulfillment ran (an ACH join, or a
     * fast webhook). When it turns up, put its number and links on the
     * join's row in place of the PaymentIntent it was filed under.
     *
     * @param array<string,mixed> $invoice
     */
    public static function attach_invoice( int $joinId, array $invoice ): void {
        $join = MyNJILGA_Join_Orders_Table::get( $joinId );
        $id   = (string) ( $invoice['id'] ?? '' );
        if ( ! $join || $id === '' || (int) $join->invoice_row_id <= 0 ) {
            return;
        }
        $row = MyNJILGA_Dues_Invoice_Table::get( (int) $join->invoice_row_id );
        if ( ! $row || (string) $row->gateway_invoice_id === $id ) {
            return;
        }
        $fields = [ 'gateway_invoice_id' => $id ];
        foreach ( [ 'number' => 'gateway_invoice_number', 'hosted_invoice_url' => 'hosted_invoice_url', 'invoice_pdf' => 'invoice_pdf_url' ] as $from => $to ) {
            if ( ! empty( $invoice[ $from ] ) ) {
                $fields[ $to ] = (string) $invoice[ $from ];
            }
        }
        MyNJILGA_Dues_Invoice_Table::update_gateway_fields( (int) $row->id, $fields );
        MyNJILGA_Join_Orders_Table::update( $joinId, [ 'stripe_invoice_id' => $id ] );
    }

    /**
     * The daily safety net (run from the reconciler's daily job): anything
     * still waiting on Stripe, paid-but-unfinished, or claimed by a run
     * that died.
     *
     * @return array{checked:int,fulfilled:int}
     */
    public static function sweep(): array {
        $checked = 0; $fulfilled = 0;
        foreach ( MyNJILGA_Join_Orders_Table::get_for_sweep() as $join ) {
            $checked++;
            try {
                $r = self::sync( (int) $join->id, 'daily sweep' );
                if ( $r['status'] === MyNJILGA_Join_Orders_Table::STATUS_FULFILLED ) {
                    $fulfilled++;
                }
            } catch ( \Throwable $e ) {
                // Per-join isolation; the next sweep tries again.
            }
            // Seen today: whatever couldn't move goes to the back of the
            // queue, so the next sweep reaches the rows behind it.
            MyNJILGA_Join_Orders_Table::touch( (int) $join->id );
        }
        return [ 'checked' => $checked, 'fulfilled' => $fulfilled ];
    }

    /**
     * Student IDs and transcripts are kept only as long as they serve a
     * membership: a join that expired, failed, was abandoned or rejected
     * loses its document after 30 days.
     */
    public static function purge_documents(): int {
        $n = 0;
        foreach ( MyNJILGA_Join_Orders_Table::get_documents_to_purge( 30 ) as $join ) {
            MyNJILGA_Join_Documents::delete( (string) $join->document_path );
            MyNJILGA_Join_Orders_Table::update( (int) $join->id, [ 'document_path' => '', 'document_name' => '', 'document_mime' => '' ] );
            $n++;
        }
        return $n;
    }

    // -------------------------------------------------------------------------
    // Verification
    // -------------------------------------------------------------------------

    /**
     * Why this session can't be trusted as this join's payment ('' = it can).
     * Pure — tested directly.
     *
     * @param object              $join
     * @param array<string,mixed> $session Normalized checkout.
     */
    public static function verify_session( $join, array $session ): string {
        if ( (string) $session['id'] !== (string) $join->stripe_session_id ) {
            return 'Stripe returned a different checkout than the one on file.';
        }
        if ( (int) ( $session['metadata']['njilga_join_id'] ?? 0 ) !== (int) $join->id ) {
            return 'The checkout on file belongs to a different join.';
        }
        if ( (bool) $session['livemode'] !== (bool) $join->livemode ) {
            return 'The checkout was taken in a different Stripe mode than this join.';
        }
        // Only a checkout that has actually taken money is held to the
        // amount — an open or expired one hasn't charged anything yet.
        if ( (string) $session['status'] === 'complete' ) {
            if ( (int) $session['amount_total'] !== (int) $join->total_cents ) {
                return sprintf(
                    'Stripe charged %s but this join totals %s — review before applying membership.',
                    MyNJILGA_Invoicing::money( (int) $session['amount_total'] ),
                    MyNJILGA_Invoicing::money( (int) $join->total_cents )
                );
            }
            if ( (string) $session['currency'] !== '' && strtolower( (string) $session['currency'] ) !== strtolower( (string) $join->currency ) ) {
                return 'The checkout was paid in an unexpected currency.';
            }
        }
        return '';
    }

    // -------------------------------------------------------------------------
    // The work
    // -------------------------------------------------------------------------

    /**
     * Runs with the join already claimed (status 'fulfilling').
     *
     * @param array<string,mixed>|null $session Normalized, verified checkout; null for a $0 approval.
     * @return array{ok:bool,status:string,message:string}
     */
    private static function run( int $joinId, string $trigger, ?array $session ): array {
        $T = 'MyNJILGA_Join_Orders_Table';
        try {
            $join = MyNJILGA_Join_Orders_Table::get( $joinId );
            if ( ! $join ) {
                return self::result( false, '', 'Join not found.' );
            }
            if ( ! MyNJILGA_Members_Data::fluentcrm_active() || ! function_exists( 'FluentCrmApi' ) ) {
                throw new \RuntimeException( 'FluentCRM is not active.' );
            }

            $progress = MyNJILGA_Join_Orders_Table::json( $join, 'progress' );
            $save     = static function () use ( $joinId, &$progress ): void {
                MyNJILGA_Join_Orders_Table::update( $joinId, [ 'progress' => $progress ] );
            };

            $applicant  = MyNJILGA_Join_Orders_Table::json( $join, 'applicant' );
            $colleagues = MyNJILGA_Join_Orders_Table::json( $join, 'colleagues' );
            $priced     = MyNJILGA_Join_Orders_Table::json( $join, 'priced' );
            $category   = MyNJILGA_Dues_Settings::category( (string) $join->category_key );
            if ( ! $category ) {
                throw new \RuntimeException( 'The membership category "' . $join->category_key . '" no longer exists in Settings.' );
            }
            $isStudent = $join->form === $T::FORM_STUDENT;

            // 1. Payer contact.
            if ( empty( $progress['contact_id'] ) ) {
                [ $contactId, $otherAccount ] = self::upsert_contact( self::applicant_fields( $applicant ), self::applicant_custom_values( $applicant, $isStudent ), (int) $join->wp_user_id );
                $progress['contact_id'] = $contactId;
                if ( $otherAccount > 0 ) {
                    $progress['payer_other_account'] = $otherAccount; // Flagged in step 4.
                }
                $save();
            }
            $payerId = (int) $progress['contact_id'];

            // 2. Firm (none for a student).
            $companyId = 0; $companyName = ''; $newFirm = false;
            if ( ! $isStudent ) {
                if ( empty( $progress['company_id'] ) ) {
                    [ $progress['company_id'], $progress['new_firm'] ] = self::resolve_company( $join, $payerId );
                    $save();
                }
                $companyId = (int) $progress['company_id'];
                $newFirm   = ! empty( $progress['new_firm'] );
                $company   = \FluentCrm\App\Models\Company::find( $companyId );
                if ( ! $company ) {
                    throw new \RuntimeException( 'The firm (Company #' . $companyId . ') could not be found.' );
                }
                $companyName = (string) $company->name;
            }

            // 3. Colleague contacts. A brand-new address becomes a contact
            // that is NOT subscribed to marketing — someone else typed it
            // in; being paid for is not consent to NJILGA's newsletters.
            $colleagueIds = (array) ( $progress['colleague_ids'] ?? [] );
            foreach ( $colleagues as $c ) {
                $email = strtolower( (string) ( $c['email'] ?? '' ) );
                if ( $email === '' || ! empty( $colleagueIds[ $email ] ) ) {
                    continue;
                }
                $colleagueIds[ $email ] = self::find_or_create_colleague( $c );
                $progress['colleague_ids'] = $colleagueIds;
                $save();
            }

            // 4. Who joins the firm now, and who waits for staff. Paying
            // buys a membership; it does not by itself prove you work at a
            // firm that already exists — so (unless Settings say otherwise)
            // an existing firm takes on only people whose email domain it
            // already has, or who are already on it. Nobody else's CRM
            // record is rewritten on a payer's say-so either: a colleague
            // who belongs to another firm, carries another category, or is
            // marked inactive/unsubscribed waits for staff. Everyone held
            // is still a paid member — they are just not attached (nor,
            // when their own record is the issue, re-categorised) until
            // staff confirm on the Online joins screen.
            if ( empty( $progress['placed'] ) ) {
                $held     = [];
                $attach   = [];
                $checks   = [];
                $always   = (string) MyNJILGA_Dues_Settings::general( 'join_firm_match', 'domain' ) === 'always';
                $payerContact = \FluentCrm\App\Models\Subscriber::find( $payerId );

                // The payer's own category is theirs to choose — always applied.
                self::apply_category_tags( $payerId, $category, $isStudent && ( $applicant['student_status'] ?? '' ) === 'undergrad', true );

                $payerOn = true;
                if ( $companyId > 0 ) {
                    $domains = $newFirm ? [] : self::firm_domains( $companyId );
                    $payerOn = $newFirm || $always || self::is_attached( $payerContact, $companyId ) || self::domain_matches( (string) $join->email, $domains );
                    if ( $payerOn ) {
                        $attach[] = $payerId;
                        $mine = self::email_domain( (string) $join->email );
                        if ( $mine !== '' && ! self::free_mail( $mine ) ) {
                            $domains[] = $mine;
                        }
                        if ( $always && ! $newFirm && ! self::is_attached( $payerContact, $companyId ) && ! self::domain_matches( (string) $join->email, self::firm_domains( $companyId ) ) ) {
                            $checks[] = sprintf( '%s %s joined the existing firm %s without a matching email domain — check they belong there.', $join->first_name, $join->last_name, $companyName );
                        }
                    } else {
                        $held[ $payerId ] = [ 'email' => (string) $join->email, 'name' => trim( $join->first_name . ' ' . $join->last_name ), 'reason' => 'email domain not yet seen at this firm' ];
                    }
                    if ( $newFirm ) {
                        $checks[] = sprintf( 'This join created a new firm, "%s" — check it isn\'t a duplicate of an existing Company.', $companyName );
                    }

                    foreach ( $colleagues as $c ) {
                        $email = strtolower( (string) ( $c['email'] ?? '' ) );
                        $cid   = (int) ( $colleagueIds[ $email ] ?? 0 );
                        if ( $cid <= 0 ) {
                            continue;
                        }
                        $contact = \FluentCrm\App\Models\Subscriber::find( $cid );
                        $name    = trim( (string) ( $c['first_name'] ?? '' ) . ' ' . (string) ( $c['last_name'] ?? '' ) );
                        [ $reason, $firmOnly ] = self::colleague_hold_reason( $contact, $category, $companyId, $payerOn, $newFirm || $always, $domains, $email );
                        if ( $reason === '' ) {
                            $attach[] = $cid;
                        } else {
                            $held[ $cid ] = [ 'email' => $email, 'name' => $name, 'reason' => $reason ];
                            // Held off the firm only — nothing on their own
                            // record contradicts the category they were
                            // paid for, so it goes on now; if staff later
                            // dismiss the firm link, the membership stands.
                            if ( $firmOnly ) {
                                self::apply_category_tags( $cid, $category, false, false );
                            }
                        }
                    }

                    if ( $attach ) {
                        FluentCrmApi( 'companies' )->attachContactsByIds( array_values( array_unique( $attach ) ), [ $companyId ] );
                    }
                    foreach ( $attach as $cid ) {
                        if ( $cid !== $payerId ) {
                            self::apply_category_tags( $cid, $category, false, false );
                        }
                    }
                }

                // Anyone here who is also on a firm's own invoice for the
                // year — a frozen draft or approved row goes out exactly as
                // it was priced, and one already out may get paid too.
                foreach ( self::on_firm_invoices( $join, $payerId, $colleagues, $colleagueIds ) as $line ) {
                    $checks[] = $line;
                }
                // The payment went onto a contact another website account
                // owns (see upsert_contact()) — never silently.
                $otherAccount = (int) ( $progress['payer_other_account'] ?? 0 );
                if ( $otherAccount > 0 ) {
                    $checks[] = sprintf( 'The FluentCRM contact for %s (#%d) is linked to a different website account (user #%d) than the one that paid (user #%d). The membership was applied to that contact, whose details were only filled in where blank (this join\'s answers are on the Online joins screen) — check which account it belongs to, and whether it was already current.', (string) $join->email, $payerId, $otherAccount, (int) $join->wp_user_id );
                } elseif ( $payerContact && (int) $join->wp_user_id > 0 && ! empty( $payerContact->user_id ) && (int) $payerContact->user_id !== (int) $join->wp_user_id ) {
                    $checks[] = sprintf( 'The FluentCRM contact for %s is linked to a different website account (user #%d) than the one that paid (user #%d) — the membership is on that contact; check which account it belongs to.', (string) $join->email, (int) $payerContact->user_id, (int) $join->wp_user_id );
                }
                if ( ! empty( $progress['reopened_from'] ) ) {
                    $checks[] = sprintf( 'Stripe took this payment after the join had been closed (%s), so it was applied late — check the applicant hasn\'t also paid through a newer join.', (string) $progress['reopened_from'] );
                }

                if ( (string) $join->document_path !== '' ) {
                    $progress['document_unverified'] = true;
                }
                $progress['held']   = $held;
                $progress['checks'] = $checks;
                $progress['placed'] = true;
                $save();
            }

            // 5. The invoice row everything else in the plugin reads. A
            // payer held off the firm is filed as an individual membership
            // until staff confirm them (confirm_held() then moves it):
            // the firm status page shows every row filed under a firm to
            // all of its members, and this one would show an outsider's
            // name, colleagues, amount and receipt.
            if ( empty( $progress['invoice_row_id'] ) ) {
                $rowCompanyId = isset( $progress['held'][ $payerId ] ) ? 0 : $companyId;
                $progress['invoice_row_id'] = self::create_invoice_row( $join, $priced, $payerId, $colleagueIds, $rowCompanyId, $rowCompanyId > 0 ? $companyName : '', $session );
                $save();
                MyNJILGA_Join_Orders_Table::update( $joinId, [ 'invoice_row_id' => (int) $progress['invoice_row_id'] ] );
            }
            $rowId = (int) $progress['invoice_row_id'];

            // 6. Ledger, then settlement (tags Dues Paid {year}, roles, Company Note).
            if ( empty( $progress['settled'] ) ) {
                // Someone already current by the time the money cleared
                // (their firm's invoice was paid, or another join covered
                // them) has now been paid for twice — say so, for a refund.
                if ( ! isset( $progress['already_current'] ) ) {
                    $progress['already_current'] = self::already_current( $join, $priced, $payerId, $colleagueIds, $rowId );
                    $save();
                }

                if ( $session !== null ) {
                    self::record_payment( $rowId, $join, $session );
                }
                $row = MyNJILGA_Dues_Invoice_Table::get( $rowId );
                if ( ! $row ) {
                    throw new \RuntimeException( 'The join\'s invoice row #' . $rowId . ' disappeared.' );
                }
                try {
                    MyNJILGA_Payment_Listener::settle( $row, $session !== null ? 'online join' : 'online join, no charge — approved by staff' );
                } catch ( \Throwable $e ) {
                    // The money is in; the row must say paid so nothing
                    // offers to bill it again. The join stays retryable.
                    MyNJILGA_Dues_Invoice_Table::mark_paid( $rowId );
                    MyNJILGA_Dues_Invoice_Table::set_error( $rowId, 'Paid online, but applying membership failed: ' . $e->getMessage() );
                    throw $e;
                }
                // A join made once next year's invoices were out pays for
                // next year and covers the rest of this one — tag that too,
                // so this year's reports count them.
                $thisYear = MyNJILGA_Invoicing::current_dues_year();
                if ( (int) $join->dues_year > $thisYear ) {
                    $yearTagId = MyNJILGA_Tags::get_or_create_by_title( MyNJILGA_Dues_Settings::year_tag( 'year_paid_tag_pattern', $thisYear ) );
                    foreach ( MyNJILGA_Dues_Snapshot::members( MyNJILGA_Dues_Invoice_Table::get( $rowId ) ) as $m ) {
                        $contact = \FluentCrm\App\Models\Subscriber::find( (int) ( $m['contact_id'] ?? 0 ) );
                        if ( $contact && $yearTagId ) {
                            $contact->attachTags( [ $yearTagId ] );
                        }
                    }
                }
                $progress['settled'] = true;
                $save();
            }

            // Anyone joining online no longer needs the application they
            // may have filed through [njilga_membership_application] — an
            // approval would draft them a second invoice.
            if ( empty( $progress['applications_closed'] ) ) {
                foreach ( array_merge( [ (string) $join->email ], array_map( static function ( $c ) { return (string) ( $c['email'] ?? '' ); }, $colleagues ) ) as $email ) {
                    $app = $email !== '' ? MyNJILGA_Applications_Table::get_pending_by_email( strtolower( $email ) ) : null;
                    if ( $app ) {
                        MyNJILGA_Applications_Table::set_decision( (int) $app->id, MyNJILGA_Applications_Table::STATUS_SUPERSEDED, 0, sprintf( 'Superseded by online join #%d.', (int) $join->id ) );
                    }
                }
                $progress['applications_closed'] = true;
                $save();
            }

            // 7. A brand-new firm's first Stripe Customer is the one this checkout made.
            if ( $newFirm && $session !== null && empty( $progress['customer_adopted'] ) && (string) $session['customer'] !== '' ) {
                $gateway = self::checkout_gateway();
                if ( $gateway ) {
                    $gateway->adopt_customer_for_company( (string) $session['customer'], $companyId, $companyName, $payerId, ! empty( $join->livemode ) ? 'live' : 'test' );
                }
                $progress['customer_adopted'] = true;
                $save();
            }

            // 8. Invitations — one per colleague, each recorded as it goes out.
            $invited = (array) ( $progress['invited'] ?? [] );
            foreach ( $colleagues as $c ) {
                $email = strtolower( (string) ( $c['email'] ?? '' ) );
                if ( $email === '' || ! empty( $invited[ $email ] ) ) {
                    continue;
                }
                MyNJILGA_Join_Invites::send( $join, [
                    'contact_id' => (int) ( $colleagueIds[ $email ] ?? 0 ),
                    'email'      => $email,
                    'first_name' => (string) ( $c['first_name'] ?? '' ),
                    'last_name'  => (string) ( $c['last_name'] ?? '' ),
                ], $companyName );
                $invited[ $email ] = true;
                $progress['invited'] = $invited;
                $save();
            }

            // 9. Word to the payer and to staff, and the firm's audit note.
            if ( empty( $progress['emailed'] ) ) {
                self::welcome_email( $join, $colleagues, $companyName, $session, isset( $progress['held'][ $payerId ] ) );
                self::staff_email( $join, $colleagues, $companyName, $newFirm, $trigger, $rowId, $progress );
                if ( $companyId > 0 ) {
                    $note = self::note_text( $join, $colleagues, $session, $newFirm );
                    if ( ! empty( $progress['held'] ) ) {
                        $note .= ' Waiting for staff to confirm before joining this firm: ' . implode( ', ', array_map( static function ( $h ) { return (string) ( $h['name'] ?? '' ); }, (array) $progress['held'] ) ) . '.';
                    }
                    MyNJILGA_Invoicing_Notes::log( $companyId, 'Joined online', $note );
                }
                $progress['emailed'] = true;
                $save();
            }

            MyNJILGA_Join_Orders_Table::update( $joinId, [
                'status'               => $T::STATUS_FULFILLED,
                'fulfilled_at'         => current_time( 'mysql' ),
                'applicant_contact_id' => $payerId,
                'company_id'           => $companyId,
                'last_error'           => null,
            ] );

            return self::result( true, $T::STATUS_FULFILLED, sprintf( 'Membership applied for %s %s%s.', $join->first_name, $join->last_name, $colleagues ? sprintf( ' and %d colleague%s', count( $colleagues ), count( $colleagues ) === 1 ? '' : 's' ) : '' ) );
        } catch ( \Throwable $e ) {
            $join = MyNJILGA_Join_Orders_Table::get( $joinId );
            // A $0 approval that fails goes back to review; a paid one to
            // 'paid', where the sweep and the Retry button pick it up.
            $back = ( $join && (int) $join->total_cents === 0 && empty( $join->stripe_session_id ) ) ? $T::STATUS_REVIEW : $T::STATUS_PAID;
            MyNJILGA_Join_Orders_Table::update( $joinId, [ 'status' => $back, 'last_error' => $e->getMessage() ] );
            return self::result( false, $back, 'Could not finish applying membership: ' . $e->getMessage() );
        }
    }

    // -------------------------------------------------------------------------
    // Steps
    // -------------------------------------------------------------------------

    /**
     * The FluentCRM contact a website account is, with no side effects:
     * the contact linked to the account, else the one with the account's
     * email — but only if no other account owns it. FluentCRM links
     * contacts to users by user_id, and staff can change a contact's
     * email without touching the account, so the email alone would miss
     * a member (and let them pay again) or find someone else's record.
     * Unlike FluentCRM's getContactByUserRef(), this never re-points a
     * contact's user_id.
     *
     * @return \FluentCrm\App\Models\Subscriber|null
     */
    public static function contact_for_user( int $userId, string $email ) {
        if ( $userId <= 0 || ! class_exists( '\\FluentCrm\\App\\Models\\Subscriber' ) ) {
            return null;
        }
        $contact = \FluentCrm\App\Models\Subscriber::where( 'user_id', $userId )->orderBy( 'id', 'ASC' )->first();
        if ( $contact ) {
            return $contact;
        }
        $email = strtolower( trim( $email ) );
        if ( $email === '' ) {
            return null;
        }
        $contact = \FluentCrm\App\Models\Subscriber::where( 'email', $email )->first();
        return ( $contact && ( empty( $contact->user_id ) || (int) $contact->user_id === $userId ) ) ? $contact : null;
    }

    /**
     * Find the contact or create it; returns its id. The payer's account
     * decides first (contact_for_user()): the contact already linked to it
     * is the one updated — under its own email, which is staff's to
     * change — rather than a second contact made for the address typed on
     * the form. Otherwise the contact with that email, else a new one.
     *
     * Status is written ONLY for a brand-new contact. An existing contact
     * who once unsubscribed from NJILGA email stays unsubscribed —
     * joining is not consent to marketing they previously declined.
     *
     * The contact with that email may be linked to a DIFFERENT website
     * account than the payer's. The join form refuses such a payer before
     * they pay, but one can still get here (the contact was re-linked
     * while an ACH debit cleared, or the join predates that check). The
     * membership still goes on that contact — FluentCRM has one record per
     * address, and the money is in — but never silently: the other
     * account is returned for run() to flag, and that record's details
     * are only filled in where blank, as a colleague's are. They are
     * someone else's; the payer's answers stay on the join.
     *
     * @param array<string,string> $fields  email, first_name, last_name, plus optional phone/address fields.
     * @param array<string,string> $custom  FluentCRM custom field slug => value.
     * @return array{0:int,1:int} [contact id, the other website account that owns it (0 = none)]
     */
    private static function upsert_contact( array $fields, array $custom, int $userId ): array {
        $email    = strtolower( trim( (string) ( $fields['email'] ?? '' ) ) );
        $existing = self::contact_for_user( $userId, $email );
        if ( ! $existing ) {
            $existing = \FluentCrm\App\Models\Subscriber::where( 'email', $email )->first();
            if ( $existing && $userId > 0 && ! empty( $existing->user_id ) && (int) $existing->user_id !== $userId ) {
                $dirty = false;
                foreach ( $fields as $k => $v ) {
                    if ( $k !== 'email' && (string) $v !== '' && (string) ( $existing->$k ?? '' ) === '' ) {
                        $existing->$k = (string) $v;
                        $dirty        = true;
                    }
                }
                if ( $dirty ) {
                    $existing->save();
                }
                return [ (int) $existing->id, (int) $existing->user_id ];
            }
        }

        $data = array_filter( $fields, static function ( $v ) { return (string) $v !== ''; } );
        // createOrUpdate() matches on email, so the existing contact's own
        // address is what points it at that record (email is unique).
        $data['email'] = $existing ? (string) $existing->email : $email;
        if ( ! $existing ) {
            $data['status'] = 'subscribed';
            $data['source'] = 'NJILGA online join';
        } elseif ( (string) $existing->status === 'pending' ) {
            // Awaiting a double opt-in — typically FluentCRM's own user
            // sync reacting to the account made moments before payment.
            // They have now confirmed this address and joined. Never
            // touches an unsubscribed, bounced or complained contact.
            $data['status'] = 'subscribed';
        }
        $custom = self::known_custom_values( $custom );
        if ( $custom ) {
            $data['custom_values'] = $custom;
        }

        $contact = FluentCrmApi( 'contacts' )->createOrUpdate( $data );
        if ( ! $contact || empty( $contact->id ) ) {
            throw new \RuntimeException( 'Could not create or update the FluentCRM contact for ' . $email . '.' );
        }

        if ( $userId > 0 && empty( $contact->user_id ) ) {
            $contact->user_id = $userId;
            $contact->save();
        }
        return [ (int) $contact->id, 0 ];
    }

    /**
     * Only custom fields that actually exist in FluentCRM — anything else
     * would be silently dropped anyway, and the join record already keeps
     * every answer.
     *
     * @param array<string,string> $custom
     * @return array<string,string>
     */
    private static function known_custom_values( array $custom ): array {
        if ( ! $custom ) {
            return [];
        }
        $known = self::fluentcrm_custom_field_slugs();
        $out   = [];
        foreach ( $custom as $slug => $value ) {
            if ( $slug !== '' && (string) $value !== '' && in_array( $slug, $known, true ) ) {
                $out[ $slug ] = (string) $value;
            }
        }
        return $out;
    }

    /**
     * @return array<int,string> Every FluentCRM contact custom field slug on this site.
     */
    public static function fluentcrm_custom_field_slugs(): array {
        $fields = function_exists( 'fluentcrm_get_option' ) ? fluentcrm_get_option( 'contact_custom_fields', [] ) : [];
        $out    = [];
        foreach ( (array) $fields as $f ) {
            if ( is_array( $f ) && ! empty( $f['slug'] ) ) {
                $out[] = (string) $f['slug'];
            }
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $a The join's applicant answers.
     * @return array<string,string>
     */
    private static function applicant_fields( array $a ): array {
        return [
            'email'          => (string) ( $a['email'] ?? '' ),
            'first_name'     => (string) ( $a['first_name'] ?? '' ),
            'last_name'      => (string) ( $a['last_name'] ?? '' ),
            // +1 ###-###-####, the shape FluentCRM records here use — also
            // for joins recorded before the form normalised numbers.
            'phone'          => MyNJILGA_Phone::for_crm( (string) ( $a['phone'] ?? '' ) ),
            'address_line_1' => (string) ( $a['address_1'] ?? '' ),
            'address_line_2' => (string) ( $a['address_2'] ?? '' ),
            'city'           => (string) ( $a['city'] ?? '' ),
            'state'          => (string) ( $a['state'] ?? '' ),
            'postal_code'    => (string) ( $a['postal_code'] ?? '' ),
            'country'        => (string) ( $a['country'] ?? '' ),
        ];
    }

    /**
     * @param array<string,mixed> $a
     * @return array<string,string> custom field slug => value
     */
    private static function applicant_custom_values( array $a, bool $isStudent ): array {
        $map    = MyNJILGA_Dues_Settings::join_custom_fields();
        $values = $isStudent
            ? [
                'school'         => (string) ( $a['school'] ?? '' ),
                'student_status' => ( $a['student_status'] ?? '' ) === 'undergrad' ? 'Undergraduate (pre-law)' : 'Enrolled law student',
            ]
            : [
                'attorney_id'        => (string) ( $a['attorney_id'] ?? '' ),
                'bar_admission_date' => (string) ( $a['bar_admission_date'] ?? '' ),
                'municipality'       => (string) ( $a['municipality'] ?? '' ),
                'nj_county'          => (string) ( $a['nj_county'] ?? '' ),
            ];
        $mailing = (string) ( $a['mailing_phone'] ?? '' );
        if ( $mailing !== '' && ! MyNJILGA_Phone::same( $mailing, (string) ( $a['phone'] ?? '' ) ) ) {
            $values['mailing_phone'] = MyNJILGA_Phone::for_crm( $mailing );
        }
        $out = [];
        foreach ( $values as $key => $value ) {
            if ( ! empty( $map[ $key ] ) ) {
                $out[ $map[ $key ] ] = $value;
            }
        }
        return $out;
    }

    /**
     * The firm: the chosen Company, else an exact-name match (MySQL's
     * default collation makes that case-insensitive), else a new Company
     * with the payer as Owner.
     *
     * @return array{0:int,1:bool} [company id, created by this join]
     */
    private static function resolve_company( object $join, int $payerId ): array {
        if ( ! MyNJILGA_Members_Data::companies_module_active() ) {
            throw new \RuntimeException( 'The FluentCRM Companies module is not active.' );
        }
        if ( (int) $join->company_id > 0 && \FluentCrm\App\Models\Company::find( (int) $join->company_id ) ) {
            return [ (int) $join->company_id, false ];
        }
        $name = trim( (string) $join->new_company_name );
        if ( $name === '' ) {
            throw new \RuntimeException( 'No firm on this join.' );
        }
        $match = \FluentCrm\App\Models\Company::where( 'name', $name )->first();
        if ( $match ) {
            return [ (int) $match->id, false ];
        }
        // "Smith & Jones, LLP" and "Smith and Jones LLP" are one firm: a
        // second Company would get its own annual invoice and its own
        // $125 first member.
        $key = self::firm_key( $name );
        if ( $key !== '' ) {
            foreach ( \FluentCrm\App\Models\Company::select( [ 'id', 'name' ] )->get() as $c ) {
                if ( self::firm_key( (string) $c->name ) === $key ) {
                    return [ (int) $c->id, false ];
                }
            }
        }
        $company = FluentCrmApi( 'companies' )->createOrUpdate( [ 'name' => $name, 'owner_id' => $payerId ] );
        if ( ! $company || empty( $company->id ) ) {
            throw new \RuntimeException( 'Could not create the FluentCRM Company "' . $name . '".' );
        }
        return [ (int) $company->id, true ];
    }

    /**
     * The category tag on, everything that would contradict it off: the
     * pending-approval tag, any OTHER category an applicant could pick
     * (so a law student who joins as a Professional stops pricing as a
     * student), and — for the payer — the inactive override. Exempt
     * categories staff assign (Past President, Senior Trustee) are never
     * touched.
     */
    private static function apply_category_tags( int $contactId, array $category, bool $preLaw, bool $isPayer ): void {
        $contact = \FluentCrm\App\Models\Subscriber::find( $contactId );
        if ( ! $contact ) {
            return;
        }
        MyNJILGA_Tags::detach_slug( $contact, (string) MyNJILGA_Dues_Settings::general( 'pending_tag', 'pending-approval' ) );
        // Only the payer's own inactive flag goes — they just proved
        // otherwise. A colleague's is staff's call (see already_current()).
        if ( $isPayer ) {
            MyNJILGA_Tags::detach_slug( $contact, (string) MyNJILGA_Dues_Settings::general( 'inactive_tag', 'inactive' ) );
        }
        foreach ( MyNJILGA_Dues_Settings::categories() as $cat ) {
            if ( ! empty( $cat['applicant_selectable'] ) && $cat['key'] !== $category['key'] && $cat['tag'] !== '' && $cat['tag'] !== $category['tag'] ) {
                MyNJILGA_Tags::detach_slug( $contact, (string) $cat['tag'] );
            }
        }
        if ( (string) $category['tag'] !== '' ) {
            MyNJILGA_Tags::attach_slug( $contact, (string) $category['tag'], (string) $category['label'] );
        }
        $preLawTag = (string) MyNJILGA_Dues_Settings::general( 'join_prelaw_tag', '' );
        if ( $preLaw && $preLawTag !== '' ) {
            MyNJILGA_Tags::attach_slug( $contact, $preLawTag, 'Pre-Law' );
        }
    }

    /**
     * @param array<string,mixed>      $priced       The join's priced roster (from MyNJILGA_Join_Pricing).
     * @param array<string,int>        $colleagueIds email => contact id
     * @param array<string,mixed>|null $session
     */
    private static function create_invoice_row( object $join, array $priced, int $payerId, array $colleagueIds, int $companyId, string $companyName, ?array $session ): int {
        $members = [];
        foreach ( (array) ( $priced['members'] ?? [] ) as $m ) {
            $email           = strtolower( (string) ( $m['email'] ?? '' ) );
            $m['contact_id'] = ( $m['join_role'] ?? '' ) === 'payer' ? $payerId : (int) ( $colleagueIds[ $email ] ?? 0 );
            $members[]       = $m;
        }
        if ( ! $members ) {
            throw new \RuntimeException( 'The join has no priced members.' );
        }

        $payer = MyNJILGA_Dues_Snapshot::person( [
            'contact_id' => $payerId,
            'first_name' => (string) $join->first_name,
            'last_name'  => (string) $join->last_name,
            'email'      => (string) $join->email,
        ] );
        $snapshot = MyNJILGA_Dues_Snapshot::build(
            (int) $join->dues_year,
            MyNJILGA_Dues_Settings::MODE_INDIVIDUAL,
            MyNJILGA_Dues_Snapshot::KIND_JOIN,
            [ 'id' => $companyId, 'name' => $companyId > 0 ? $companyName : 'Individual member (no firm)' ],
            $payer,
            $payer,
            $members
        );
        $snapshot['source']  = 'online_join';
        $snapshot['join_id'] = (int) $join->id;

        $livemode = ! empty( $join->livemode );

        // A retry after a run that died between writing the row and
        // recording it in `progress` finds its own row here, rather than
        // tripping over it as a conflict.
        $mine  = MyNJILGA_Dues_Invoice_Table::find_row( $companyId, (int) $join->dues_year, MyNJILGA_Dues_Snapshot::KIND_JOIN, $payerId, $livemode );
        $rowId = ( $mine && (int) ( MyNJILGA_Dues_Snapshot::decode( $mine )['join_id'] ?? 0 ) === (int) $join->id ) ? (int) $mine->id : null;

        // Written straight in as 'paid': the money was confirmed before this
        // row existed, so it is never in a status the Invoicing page would
        // offer to create, send or void, that the downgrade sweep would
        // lapse, or that the reconciler would re-fetch — not even for the
        // moment between here and settle().
        $rowId = $rowId ?? MyNJILGA_Dues_Invoice_Table::upsert_draft( [
            'dues_year'                  => (int) $join->dues_year,
            'fluentcrm_company_id'       => $companyId,
            'fluentcrm_owner_contact_id' => $payerId,
            'bill_to_contact_id'         => $payerId,
            'billing_mode'               => MyNJILGA_Dues_Settings::MODE_INDIVIDUAL,
            'invoice_kind'               => MyNJILGA_Dues_Snapshot::KIND_JOIN,
            'status'                     => MyNJILGA_Dues_Invoice_Table::STATUS_PAID,
            'total_amount_cents'         => (int) $join->total_cents,
            'roster_snapshot'            => MyNJILGA_Dues_Snapshot::encode( $snapshot ),
            'livemode'                   => $livemode,
        ] );
        if ( ! $rowId ) {
            throw new \RuntimeException( sprintf( '%s already has a %d online-join record in this Stripe mode — review it before applying this one.', $join->email, (int) $join->dues_year ) );
        }

        if ( $session === null ) {
            // A $0 approval: no Stripe object exists to point at, and the
            // gateway id stays NULL rather than '' so nothing can ever
            // match it by id. settle() takes it straight to paid.
            return (int) $rowId;
        }

        // The Stripe objects this payment produced. With no Stripe invoice
        // (turned off in Settings) the PaymentIntent is the reference —
        // the row is settled straight away, so nothing ever asks Stripe
        // for an invoice by that id.
        $invoice = is_array( $session['invoice'] ?? null ) ? $session['invoice'] : [];
        $fields  = [
            'gateway_invoice_id' => (string) ( $session['invoice_id'] !== '' ? $session['invoice_id'] : $session['payment_intent_id'] ),
            'stripe_status'      => 'paid',
            'finalized_at'       => current_time( 'mysql' ),
            'amount_paid_cents'  => (int) $session['amount_total'],
            'amount_due_cents'   => 0,
            'last_synced_at'     => current_time( 'mysql' ),
        ];
        if ( (string) $session['customer'] !== '' ) {
            $fields['gateway_customer_id'] = (string) $session['customer'];
        }
        foreach ( [ 'number' => 'gateway_invoice_number', 'hosted_invoice_url' => 'hosted_invoice_url', 'invoice_pdf' => 'invoice_pdf_url' ] as $from => $to ) {
            if ( ! empty( $invoice[ $from ] ) ) {
                $fields[ $to ] = (string) $invoice[ $from ];
            }
        }
        MyNJILGA_Dues_Invoice_Table::update_gateway_fields( (int) $rowId, $fields );
        return (int) $rowId;
    }

    /**
     * The ledger row for the checkout's payment. stripe_object_id follows
     * the webhook's and reconciler's order — charge, else PaymentIntent —
     * so a later invoice.paid delivery or reconcile of the same money
     * lands on this row's duplicate-safe key instead of a second row.
     *
     * @param array<string,mixed> $session
     */
    private static function record_payment( int $rowId, object $join, array $session ): void {
        $pi     = is_array( $session['payment_intent'] ?? null ) ? $session['payment_intent'] : [];
        $charge = is_array( $pi['latest_charge'] ?? null ) ? $pi['latest_charge'] : [];
        $detail = $charge ? MyNJILGA_Stripe_Webhook::detail_from_charge( $charge ) : [ 'method' => '', 'card_brand' => null, 'last4' => null, 'bank_name' => null, 'receipt_url' => null ];
        $method = $detail['method'] !== '' ? $detail['method'] : 'other';

        $objectId = (string) ( $charge['id'] ?? '' );
        if ( $objectId === '' ) {
            $objectId = (string) $session['payment_intent_id'];
        }
        if ( $objectId === '' ) {
            return; // A no-cost checkout moved no money — nothing for the ledger.
        }

        MyNJILGA_Dues_Payments_Table::record( [
            'invoice_row_id'   => $rowId,
            'livemode'         => ! empty( $join->livemode ),
            'stripe_object_id' => $objectId,
            'kind'             => MyNJILGA_Dues_Payments_Table::KIND_PAYMENT,
            'method'           => $method,
            'amount_cents'     => (int) $session['amount_total'],
            'status'           => 'succeeded',
            'occurred_at'      => current_time( 'mysql' ),
            'reference'        => 'Online join #' . (int) $join->id,
            'card_brand'       => $detail['card_brand'],
            'last4'            => $detail['last4'],
            'bank_name'        => $detail['bank_name'],
            'receipt_url'      => $detail['receipt_url'],
            'raw'              => (string) wp_json_encode( [ 'checkout_session' => (string) $session['id'], 'payment_intent' => (string) $session['payment_intent_id'], 'invoice' => (string) $session['invoice_id'] ] ),
        ] );

        MyNJILGA_Dues_Invoice_Table::update_gateway_fields( $rowId, [ 'primary_method' => $method ] );
    }

    /**
     * A colleague's contact: found by email, or created with a status that
     * keeps them out of marketing sends. Their name is filled in only
     * where the existing record has none — someone else typed it.
     *
     * @param array<string,mixed> $c
     */
    private static function find_or_create_colleague( array $c ): int {
        $email    = strtolower( trim( (string) ( $c['email'] ?? '' ) ) );
        $existing = \FluentCrm\App\Models\Subscriber::where( 'email', $email )->first();
        if ( $existing ) {
            $dirty = false;
            foreach ( [ 'first_name', 'last_name' ] as $k ) {
                if ( (string) ( $existing->$k ?? '' ) === '' && (string) ( $c[ $k ] ?? '' ) !== '' ) {
                    $existing->$k = (string) $c[ $k ];
                    $dirty        = true;
                }
            }
            if ( $dirty ) {
                $existing->save();
            }
            return (int) $existing->id;
        }
        $contact = FluentCrmApi( 'contacts' )->createOrUpdate( [
            'email'      => $email,
            'first_name' => (string) ( $c['first_name'] ?? '' ),
            'last_name'  => (string) ( $c['last_name'] ?? '' ),
            'status'     => self::non_marketing_status(),
            'source'     => 'NJILGA online join (added by a colleague)',
        ] );
        if ( ! $contact || empty( $contact->id ) ) {
            throw new \RuntimeException( 'Could not create the FluentCRM contact for ' . $email . '.' );
        }
        return (int) $contact->id;
    }

    /**
     * Why a colleague waits for staff rather than being put on the firm
     * and given the join's category ('' = they don't), and whether the
     * wait is about the firm alone (their own record is fine, so the
     * category can go on now). Pure over the contact's current state.
     *
     * @param \FluentCrm\App\Models\Subscriber|null $contact
     * @param array<int,string>                     $domains Firm domains (plus the payer's, when the payer was placed).
     * @return array{0:string,1:bool} [reason, firm-only]
     */
    private static function colleague_hold_reason( $contact, array $category, int $companyId, bool $payerOn, bool $anyDomain, array $domains, string $email ): array {
        if ( ! $contact ) {
            return [ 'contact record not found', false ];
        }
        if ( in_array( (string) $contact->status, [ 'unsubscribed', 'bounced', 'complained', 'spammed' ], true ) ) {
            return [ sprintf( 'their FluentCRM record is marked %s', (string) $contact->status ), false ];
        }
        $inactive = (string) MyNJILGA_Dues_Settings::general( 'inactive_tag', 'inactive' );
        if ( $inactive !== '' && MyNJILGA_Tags::has_slug( $contact, $inactive ) ) {
            return [ sprintf( 'they carry the "%s" tag', $inactive ), false ];
        }
        foreach ( MyNJILGA_Dues_Settings::categories() as $cat ) {
            if ( ! empty( $cat['applicant_selectable'] ) && $cat['key'] !== $category['key'] && (string) $cat['tag'] !== '' && MyNJILGA_Tags::has_slug( $contact, (string) $cat['tag'] ) ) {
                return [ sprintf( 'already a %s member — the join paid the %s rate', (string) $cat['label'], (string) $category['label'] ), false ];
            }
        }
        // Already on this firm: attaching them changes nothing, whoever
        // paid. On another one: say so even when the payer is held too,
        // or confirming the payer would quietly add a second firm.
        if ( self::is_attached( $contact, $companyId ) ) {
            return [ '', false ];
        }
        if ( self::attached_elsewhere( $contact, $companyId ) ) {
            return [ 'already listed at another firm', true ];
        }
        if ( ! $payerOn ) {
            return [ 'waiting until the payer is confirmed at this firm', true ];
        }
        // A firm this join just created (or Settings saying "always") has
        // no-one else's records to protect by domain.
        if ( $anyDomain || self::domain_matches( $email, $domains ) ) {
            return [ '', false ];
        }
        return [ 'email domain doesn\'t match the firm', true ];
    }

    /**
     * A firm name reduced to what identifies it: case, punctuation, "&",
     * and the entity suffix ("LLP", "P.C.") don't make a different firm.
     */
    public static function firm_key( string $name ): string {
        $n = strtolower( html_entity_decode( $name, ENT_QUOTES ) );
        $n = str_replace( '&', ' and ', $n );
        $n = (string) preg_replace( '/[^a-z0-9 ]+/', ' ', $n );
        $n = (string) preg_replace( '/\b(the|llp|llc|pllc|pc|p c|pa|p a|esq|esqs|ltd|inc|co|company|professional corporation|attorneys at law|attorneys|counselors at law)\b/', ' ', $n );
        return trim( (string) preg_replace( '/\s+/', ' ', $n ) );
    }

    /**
     * FluentCRM's "transactional" status where this version has it (email
     * that isn't marketing), else "pending" — never "subscribed".
     */
    private static function non_marketing_status(): string {
        if ( function_exists( 'fluentcrm_subscriber_statuses' ) && in_array( 'transactional', (array) fluentcrm_subscriber_statuses(), true ) ) {
            return 'transactional';
        }
        return 'pending';
    }

    /**
     * Email domains the firm already has — its Owner's and its members'.
     * Free-mail domains never count: sharing gmail.com with a partner
     * proves nothing.
     *
     * @return array<int,string>
     */
    private static function firm_domains( int $companyId ): array {
        $company = \FluentCrm\App\Models\Company::with( [ 'owner' ] )->find( $companyId );
        if ( ! $company ) {
            return [];
        }
        $emails = [];
        if ( ! empty( $company->owner->email ) ) {
            $emails[] = (string) $company->owner->email;
        }
        try {
            foreach ( $company->subscribers()->limit( 300 )->get() as $sub ) {
                $emails[] = (string) $sub->email;
            }
        } catch ( \Throwable $e ) {
            // Owner alone, then.
        }
        $out = [];
        foreach ( $emails as $e ) {
            $d = self::email_domain( $e );
            if ( $d !== '' && ! self::free_mail( $d ) ) {
                $out[ $d ] = true;
            }
        }
        return array_keys( $out );
    }

    /**
     * @param array<int,string> $domains
     */
    private static function domain_matches( string $email, array $domains ): bool {
        $d = self::email_domain( $email );
        return $d !== '' && ! self::free_mail( $d ) && in_array( $d, $domains, true );
    }

    public static function email_domain( string $email ): string {
        $at = strrpos( $email, '@' );
        return $at === false ? '' : strtolower( trim( substr( $email, $at + 1 ) ) );
    }

    /**
     * Whether an address at this domain proves nothing about where
     * someone works: it's on $list (default FREE_MAIL_DOMAINS), a
     * subdomain of one (nj.rr.com), or a big provider's country domain
     * (yahoo.co.uk, hotmail.fr, gmx.de). Pure — tested directly; the
     * site's filtered list comes in through free_mail().
     *
     * @param array<int,string>|null $list
     */
    public static function is_free_mail( string $domain, ?array $list = null ): bool {
        $domain = strtolower( trim( $domain, " \t\n\r\0\x0B." ) );
        if ( $domain === '' ) {
            return false;
        }
        $list   = array_map( 'strtolower', $list ?? self::FREE_MAIL_DOMAINS );
        $labels = explode( '.', $domain );
        for ( $i = 0, $n = count( $labels ); $i < $n - 1; $i++ ) {
            if ( in_array( implode( '.', array_slice( $labels, $i ) ), $list, true ) ) {
                return true;
            }
        }
        return (bool) preg_match( '/^(?:' . implode( '|', self::FREE_MAIL_FAMILIES ) . ')\.(?:com|net|(?:(?:co|com)\.)?[a-z]{2})$/', $domain );
    }

    /**
     * is_free_mail() against this site's list: FREE_MAIL_DOMAINS as
     * filtered by `my_njilga_free_mail_domains` (add a provider NJILGA
     * members use, or take out a domain that really is one firm's). The
     * country-domain families always apply.
     */
    private static function free_mail( string $domain ): bool {
        static $list = null;
        if ( $list === null ) {
            $list = array_values( array_filter( array_map(
                static function ( $d ) { return strtolower( trim( (string) $d ) ); },
                (array) apply_filters( 'my_njilga_free_mail_domains', self::FREE_MAIL_DOMAINS )
            ) ) );
        }
        return self::is_free_mail( $domain, $list );
    }

    /**
     * @param \FluentCrm\App\Models\Subscriber|null $contact
     */
    private static function is_attached( $contact, int $companyId ): bool {
        return in_array( $companyId, self::company_ids_of( $contact ), true );
    }

    /**
     * @param \FluentCrm\App\Models\Subscriber|null $contact
     */
    private static function attached_elsewhere( $contact, int $companyId ): bool {
        foreach ( self::company_ids_of( $contact ) as $id ) {
            if ( $id !== $companyId ) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param \FluentCrm\App\Models\Subscriber|null $contact
     * @return array<int,int>
     */
    private static function company_ids_of( $contact ): array {
        if ( ! $contact ) {
            return [];
        }
        $ids = [];
        if ( ! empty( $contact->company_id ) ) {
            $ids[] = (int) $contact->company_id;
        }
        try {
            foreach ( $contact->companies ?? [] as $c ) {
                $ids[] = (int) $c->id;
            }
        } catch ( \Throwable $e ) {
            // Companies module off — the primary id is all there is.
        }
        return array_values( array_unique( array_filter( $ids ) ) );
    }

    /**
     * People on this join who were ALREADY current for its year before it
     * settled, or who carry the inactive override — for the staff note.
     *
     * @param array<string,mixed> $priced
     * @param array<string,int>   $colleagueIds
     * @param int                 $ownRowId     This join's own invoice row, already written as paid.
     * @return array<int,string> One line per person.
     */
    private static function already_current( object $join, array $priced, int $payerId, array $colleagueIds, int $ownRowId ): array {
        $yearTag  = MyNJILGA_Dues_Settings::year_tag( 'year_paid_tag_pattern', (int) $join->dues_year );
        $inactive = (string) MyNJILGA_Dues_Settings::general( 'inactive_tag', 'inactive' );
        $out      = [];
        foreach ( (array) ( $priced['members'] ?? [] ) as $m ) {
            $isPayer = ( $m['join_role'] ?? '' ) === 'payer';
            $cid     = $isPayer ? $payerId : (int) ( $colleagueIds[ strtolower( (string) ( $m['email'] ?? '' ) ) ] ?? 0 );
            $contact = $cid > 0 ? \FluentCrm\App\Models\Subscriber::find( $cid ) : null;
            if ( ! $contact ) {
                continue;
            }
            if ( MyNJILGA_Tags::has_title( $contact, $yearTag ) && self::current_before_join( $cid, $join, $ownRowId ) ) {
                $out[] = sprintf( '%s was already a %d member when this payment cleared — consider refunding their %s line.', (string) ( $m['name'] ?? '' ), (int) $join->dues_year, MyNJILGA_Invoicing::money( (int) ( $m['dues_cents'] ?? 0 ) ) );
            } elseif ( ! $isPayer && $inactive !== '' && MyNJILGA_Tags::has_slug( $contact, $inactive ) ) {
                $out[] = sprintf( '%s carries the "%s" tag — they are paid for %d, but next year\'s batch won\'t bill them until it is removed.', (string) ( $m['name'] ?? '' ), $inactive, (int) $join->dues_year );
            }
        }
        return $out;
    }

    /**
     * Whether a contact's "Dues Paid" tag for the join's year means they
     * were current without this join. Not when the only paid invoices
     * listing them priced them at $0 BECAUSE of an online join — a firm
     * invoice drafted while this join was clearing or waiting on a retry:
     * this join is what pays for them there, and a refund would leave the
     * membership that invoice granted paid for by nobody. Any other paid
     * row listing them — charging their dues, or covering them for free
     * as a 6th-or-later or exempt member — made them current on its own;
     * so does a tag no paid row explains (set by hand).
     */
    private static function current_before_join( int $contactId, object $join, int $ownRowId ): bool {
        $explained = false;
        $rows      = MyNJILGA_Dues_Invoice_Table::rows_listing_member( $contactId, (int) $join->dues_year, ! empty( $join->livemode ), [ MyNJILGA_Dues_Invoice_Table::STATUS_PAID ], true );
        foreach ( $rows as $row ) {
            if ( (int) $row->id === $ownRowId ) {
                continue;
            }
            $m = MyNJILGA_Dues_Invoice_Table::listed_member( $row, $contactId );
            if ( $m && ! MyNJILGA_Dues_Preview::priced_as_join_covered( $m ) ) {
                return true;
            }
            $explained = true;
        }
        return ! $explained;
    }

    /**
     * People on this join who are also on a firm's own dues invoice for
     * its year and Stripe mode that isn't paid — for the staff note, so
     * they can be taken off it (the join has paid their dues). A draft or
     * approved row goes out exactly as it was frozen when created; one
     * already out would charge them a second time.
     *
     * @param array<int,array<string,mixed>> $colleagues
     * @param array<string,int>              $colleagueIds email => contact id
     * @return array<int,string> One line per person and invoice.
     */
    private static function on_firm_invoices( object $join, int $payerId, array $colleagues, array $colleagueIds ): array {
        $people = [ $payerId => trim( $join->first_name . ' ' . $join->last_name ) ];
        foreach ( $colleagues as $c ) {
            $cid = (int) ( $colleagueIds[ strtolower( (string) ( $c['email'] ?? '' ) ) ] ?? 0 );
            if ( $cid > 0 && ! isset( $people[ $cid ] ) ) {
                $people[ $cid ] = trim( (string) ( $c['first_name'] ?? '' ) . ' ' . (string) ( $c['last_name'] ?? '' ) );
            }
        }
        $year = (int) $join->dues_year;
        $out  = [];
        foreach ( $people as $cid => $name ) {
            foreach ( MyNJILGA_Dues_Invoice_Table::open_rows_listing_contact( (int) $cid, $year, ! empty( $join->livemode ) ) as $row ) {
                // Listed there at $0 — priced as paid by this very join
                // before it was applied, or a 6th-or-later or exempt member —
                // that invoice charges them nothing, so it can't pay for
                // them twice: nothing to take off, nothing to refund.
                $m = MyNJILGA_Dues_Invoice_Table::listed_member( $row, (int) $cid );
                if ( ! $m || (int) ( $m['dues_cents'] ?? 0 ) <= 0 ) {
                    continue;
                }
                $firm = MyNJILGA_Dues_Snapshot::company_name( $row );
                if ( (string) $row->status === MyNJILGA_Dues_Invoice_Table::STATUS_DRAFT ) {
                    // A fresh preview prices them as paid via this join.
                    $out[] = sprintf( '%1$s is also on %2$s\'s draft %3$d invoice (row #%4$d) — run Generate Preview for %3$d again before creating invoices, or they will be billed a second time.', $name, $firm, $year, (int) $row->id );
                } elseif ( (string) $row->status === MyNJILGA_Dues_Invoice_Table::STATUS_APPROVED ) {
                    $out[] = sprintf( '%1$s is also on %2$s\'s approved %3$d invoice (row #%4$d), which isn\'t created in Stripe yet — take them off it before it is, or they will be billed a second time.', $name, $firm, $year, (int) $row->id );
                } else {
                    $out[] = sprintf( '%1$s is also on %2$s\'s %3$d invoice (row #%4$d), which has already gone out — adjust that invoice or refund one of the two so they aren\'t paid for twice.', $name, $firm, $year, (int) $row->id );
                }
            }
        }
        return $out;
    }

    /**
     * Every FluentCRM contact a join names — its payer and the colleagues
     * it pays for — as far as they can be found yet: the ids fulfillment
     * has already recorded, else by the payer's account and by email.
     * Nothing is created.
     *
     * @return array<int,int>
     */
    public static function contact_ids_for( object $join ): array {
        if ( ! class_exists( '\\FluentCrm\\App\\Models\\Subscriber' ) ) {
            return [];
        }
        $progress = MyNJILGA_Join_Orders_Table::json( $join, 'progress' );
        $ids      = [ (int) ( $progress['contact_id'] ?? 0 ), (int) $join->applicant_contact_id ];
        $known    = array_change_key_case( (array) ( $progress['colleague_ids'] ?? [] ), CASE_LOWER );
        $emails   = [];
        if ( empty( $progress['contact_id'] ) ) {
            $payer = self::contact_for_user( (int) $join->wp_user_id, (string) $join->email );
            if ( $payer ) {
                $ids[] = (int) $payer->id;
            } else {
                $emails[] = strtolower( (string) $join->email );
            }
        }
        foreach ( MyNJILGA_Join_Orders_Table::json( $join, 'colleagues' ) as $c ) {
            $email = strtolower( trim( (string) ( $c['email'] ?? '' ) ) );
            if ( ! empty( $known[ $email ] ) ) {
                $ids[] = (int) $known[ $email ];
            } elseif ( $email !== '' ) {
                $emails[] = $email;
            }
        }
        $emails = array_values( array_unique( array_filter( $emails ) ) );
        if ( $emails ) {
            foreach ( \FluentCrm\App\Models\Subscriber::whereIn( 'email', $emails )->get() as $sub ) {
                $ids[] = (int) $sub->id;
            }
        }
        return array_values( array_unique( array_filter( $ids ) ) );
    }

    /**
     * Staff confirmed the people a join left unattached: put them on the
     * firm (and give colleagues the join's category).
     *
     * @return array{ok:bool,message:string}
     */
    public static function confirm_held( int $joinId ): array {
        $join = MyNJILGA_Join_Orders_Table::get( $joinId );
        if ( ! $join || $join->status !== MyNJILGA_Join_Orders_Table::STATUS_FULFILLED || (int) $join->company_id <= 0 ) {
            return [ 'ok' => false, 'message' => 'Nothing to confirm on this join.' ];
        }
        $progress = MyNJILGA_Join_Orders_Table::json( $join, 'progress' );
        $held     = (array) ( $progress['held'] ?? [] );
        $category = MyNJILGA_Dues_Settings::category( (string) $join->category_key );
        if ( ! $held || ! $category || ! function_exists( 'FluentCrmApi' ) ) {
            return [ 'ok' => false, 'message' => 'Nothing to confirm on this join.' ];
        }
        $ids = array_map( 'intval', array_keys( $held ) );
        FluentCrmApi( 'companies' )->attachContactsByIds( $ids, [ (int) $join->company_id ] );
        foreach ( $ids as $cid ) {
            if ( $cid !== (int) $join->applicant_contact_id ) {
                self::apply_category_tags( $cid, $category, false, false );
            }
        }
        // The payer was held, so their join was filed as an individual
        // membership (see run(), step 5). Confirmed now: it belongs on
        // the firm's record, where its members see it.
        if ( isset( $held[ (int) $join->applicant_contact_id ] ) && (int) $join->invoice_row_id > 0 ) {
            self::file_join_row_under( (int) $join->invoice_row_id, (int) $join->company_id );
        }
        $names = array_map( static function ( $h ) { return (string) ( $h['name'] ?? $h['email'] ?? '' ); }, $held );
        $progress['held']           = [];
        $progress['held_confirmed'] = array_values( $names );
        MyNJILGA_Join_Orders_Table::update( $joinId, [ 'progress' => $progress ] );
        MyNJILGA_Invoicing_Notes::log( (int) $join->company_id, 'Online join confirmed', 'Staff confirmed as members of this firm: ' . implode( ', ', $names ) . '.' );
        return [ 'ok' => true, 'message' => 'Added to the firm: ' . implode( ', ', $names ) . '.' ];
    }

    /**
     * Re-file a join's invoice row, written as an individual membership
     * while its payer was held, under the firm staff confirmed them at.
     * Only ever from "no firm" onto one, and only a join's own row.
     */
    private static function file_join_row_under( int $rowId, int $companyId ): void {
        $row = MyNJILGA_Dues_Invoice_Table::get( $rowId );
        if ( ! $row || $companyId <= 0 || (int) $row->fluentcrm_company_id !== 0 || (string) $row->invoice_kind !== MyNJILGA_Dues_Snapshot::KIND_JOIN ) {
            return;
        }
        $company             = MyNJILGA_Members_Data::companies_module_active() ? \FluentCrm\App\Models\Company::find( $companyId ) : null;
        $snapshot            = MyNJILGA_Dues_Snapshot::decode( $row );
        $snapshot['company'] = [ 'id' => $companyId, 'name' => $company ? (string) $company->name : '' ];
        MyNJILGA_Dues_Invoice_Table::refile_join_row( $rowId, $companyId, MyNJILGA_Dues_Snapshot::encode( $snapshot ) );
    }

    /**
     * Staff decided the held people don't belong on the firm: they stay
     * paid members, just not attached to it.
     *
     * @return array{ok:bool,message:string}
     */
    public static function dismiss_held( int $joinId ): array {
        $join = MyNJILGA_Join_Orders_Table::get( $joinId );
        if ( ! $join ) {
            return [ 'ok' => false, 'message' => 'Join not found.' ];
        }
        $progress = MyNJILGA_Join_Orders_Table::json( $join, 'progress' );
        if ( empty( $progress['held'] ) ) {
            return [ 'ok' => false, 'message' => 'Nothing waiting on this join.' ];
        }
        $progress['held_dismissed'] = array_values( array_map( static function ( $h ) { return (string) ( $h['name'] ?? $h['email'] ?? '' ); }, (array) $progress['held'] ) );
        $progress['held']           = [];
        MyNJILGA_Join_Orders_Table::update( $joinId, [ 'progress' => $progress ] );
        return [ 'ok' => true, 'message' => 'Left off the firm; their memberships stand.' ];
    }

    // -------------------------------------------------------------------------
    // Messages
    // -------------------------------------------------------------------------

    /**
     * @param array<int,array<string,mixed>> $colleagues
     * @param array<string,mixed>|null       $session
     */
    private static function welcome_email( object $join, array $colleagues, string $companyName, ?array $session, bool $payerHeld ): void {
        $category = MyNJILGA_Dues_Settings::category( (string) $join->category_key );
        $receipt  = '';
        if ( $session !== null ) {
            $invoice = is_array( $session['invoice'] ?? null ) ? $session['invoice'] : [];
            $url     = (string) ( $invoice['hosted_invoice_url'] ?? '' );
            if ( $url === '' && is_array( $session['payment_intent']['latest_charge'] ?? null ) ) {
                $url = (string) ( $session['payment_intent']['latest_charge']['receipt_url'] ?? '' );
            }
            if ( $url !== '' ) {
                $receipt = "\n\nYour receipt: " . $url;
            }
        }
        $covered = '';
        if ( $colleagues ) {
            $names = array_map( static function ( $c ) {
                return trim( (string) ( $c['first_name'] ?? '' ) . ' ' . (string) ( $c['last_name'] ?? '' ) ) . ' <' . (string) ( $c['email'] ?? '' ) . '>';
            }, $colleagues );
            $covered = "\n\nYou also covered these colleagues — each has been emailed an invitation to create their own account:\n  - " . implode( "\n  - ", $names );
        }

        self::join_mail(
            $join,
            (string) $join->email,
            sprintf( 'Welcome to NJILGA — your %d membership is active', (int) $join->dues_year ),
            sprintf(
                "Hi %s,\n\nThank you for joining NJILGA. Your %d %s is now active%s.%s%s%s\n\nYou can sign in any time at %s with the username you chose.\n\nWelcome,\nNJILGA",
                $join->first_name,
                (int) $join->dues_year,
                $category ? $category['label'] : 'membership',
                ( $companyName !== '' && ! $payerHeld ) ? ' with ' . $companyName : '',
                $payerHeld ? sprintf( "\n\nNJILGA staff will confirm your listing with %s shortly — nothing more is needed from you.", $companyName ) : '',
                $covered,
                $receipt,
                wp_login_url()
            )
        );
    }

    /**
     * @param array<int,array<string,mixed>> $colleagues
     */
    private static function staff_email( object $join, array $colleagues, string $companyName, bool $newFirm, string $trigger, int $rowId, array $progress ): void {
        $to = (string) MyNJILGA_Dues_Settings::general( 'join_notify_email', '' );
        if ( trim( $to ) === '' ) {
            $to = (string) MyNJILGA_Dues_Settings::general( 'application_notify_email', '' );
        }
        $recipients = array_filter( array_map( 'sanitize_email', (array) preg_split( '/[\s,;]+/', $to ) ) );
        if ( ! $recipients ) {
            $recipients = [ (string) get_option( 'admin_email' ) ];
        }
        $category = MyNJILGA_Dues_Settings::category( (string) $join->category_key );
        $applicant = MyNJILGA_Join_Orders_Table::json( $join, 'applicant' );

        $lines = [
            sprintf( 'Name: %s %s <%s>', $join->first_name, $join->last_name, $join->email ),
            'Category: ' . ( $category ? $category['label'] : $join->category_key ),
            'Firm: ' . ( $companyName !== '' ? $companyName . ( $newFirm ? ' (NEW firm, created by this join)' : '' ) : '—' ),
            'Amount: ' . MyNJILGA_Invoicing::money( (int) $join->total_cents ) . ( (int) $join->total_cents === 0 ? ' (no charge — approved by staff)' : '' ),
        ];
        if ( $join->form === MyNJILGA_Join_Orders_Table::FORM_STUDENT ) {
            $lines[] = 'Student: ' . ( ( $applicant['student_status'] ?? '' ) === 'undergrad' ? 'Undergraduate, aspiring to law school' : 'Enrolled law student' ) . ( ! empty( $applicant['school'] ) ? ' — ' . $applicant['school'] : '' );
            if ( (string) $join->document_path !== '' ) {
                $lines[] = 'Student ID / transcript uploaded — view it on the Online joins screen.';
            }
        } else {
            $lines[] = 'NJ Attorney ID: ' . (string) ( $applicant['attorney_id'] ?? '' );
            $lines[] = 'Admitted to the NJ Bar: ' . (string) ( $applicant['bar_admission_date'] ?? '' );
            foreach ( [ 'nj_county' => 'NJ County', 'municipality' => 'Municipality' ] as $k => $label ) {
                if ( (string) ( $applicant[ $k ] ?? '' ) !== '' ) {
                    $lines[] = $label . ': ' . (string) $applicant[ $k ];
                }
            }
        }
        if ( (string) ( $applicant['phone'] ?? '' ) !== '' ) {
            $lines[] = 'Phone: ' . MyNJILGA_Phone::for_crm( (string) $applicant['phone'] );
        }
        if ( $colleagues ) {
            $lines[] = '';
            $lines[] = 'Colleagues covered (invited to create accounts):';
            foreach ( $colleagues as $c ) {
                $lines[] = sprintf( '  - %s %s <%s>', $c['first_name'] ?? '', $c['last_name'] ?? '', $c['email'] ?? '' );
            }
        }
        $held = (array) ( $progress['held'] ?? [] );
        if ( $held ) {
            $lines[] = '';
            $lines[] = sprintf( 'NEEDS YOUR CONFIRMATION — paid members, but NOT yet attached to %s:', $companyName );
            foreach ( $held as $h ) {
                $lines[] = sprintf( '  - %s <%s>: %s', $h['name'] ?? '', $h['email'] ?? '', $h['reason'] ?? '' );
            }
            $lines[] = 'Confirm or dismiss them on the Online joins screen.';
        }
        foreach ( array_merge( (array) ( $progress['already_current'] ?? [] ), (array) ( $progress['checks'] ?? [] ) ) as $warning ) {
            $lines[] = '';
            $lines[] = 'CHECK: ' . $warning;
        }
        if ( ! empty( $progress['document_unverified'] ) ) {
            $lines[] = '';
            $lines[] = 'CHECK: a student ID / transcript was uploaded — review it on the Online joins screen.';
        }

        self::join_mail(
            $join,
            $recipients,
            sprintf( '%sNew online member: %s %s (%s)', ! empty( $progress['held'] ) || ! empty( $progress['already_current'] ) || ! empty( $progress['checks'] ) || ! empty( $progress['document_unverified'] ) ? 'Needs attention — ' : '', $join->first_name, $join->last_name, $category ? $category['label'] : $join->category_key ),
            "A new NJILGA member just joined online — membership has been applied.\n\n" . implode( "\n", $lines )
                . sprintf( "\n\nConfirmed by: %s. Invoice row #%d.\nOnline joins: %s", $trigger, $rowId, add_query_arg( 'tab', 'joins', MyNJILGA_Admin_Menu::url( MyNJILGA_Admin_Menu::SLUG_APPLICATIONS ) ) ),
            true
        );
    }

    /**
     * @param array<int,array<string,mixed>> $colleagues
     * @param array<string,mixed>|null       $session
     */
    private static function note_text( object $join, array $colleagues, ?array $session, bool $newFirm ): string {
        $category = MyNJILGA_Dues_Settings::category( (string) $join->category_key );
        $names    = array_map( static function ( $c ) {
            return trim( (string) ( $c['first_name'] ?? '' ) . ' ' . (string) ( $c['last_name'] ?? '' ) );
        }, $colleagues );
        return sprintf(
            '%s %s (%s) joined online as %s for %d%s — %s.%s',
            $join->first_name,
            $join->last_name,
            $join->email,
            $category ? $category['label'] : $join->category_key,
            (int) $join->dues_year,
            $newFirm ? ', adding this firm' : '',
            $session !== null ? MyNJILGA_Invoicing::money( (int) $join->total_cents ) . ' paid through Stripe Checkout' : 'no charge, approved by staff',
            $names ? ' Also covered and invited: ' . implode( ', ', $names ) . '.' : ''
        );
    }

    /**
     * Mail about a join. A Test-mode join is a staff member trying the
     * flow with a test card — so nothing it sends may reach a real
     * colleague: every message goes to the tester instead, saying where
     * it would have gone.
     *
     * @param string|array<int,string> $to
     */
    public static function join_mail( object $join, $to, string $subject, string $body, bool $toStaff = false ): void {
        if ( empty( $join->livemode ) ) {
            $subject = '[TEST] ' . $subject;
            if ( ! $toStaff ) {
                $body = sprintf( "[Test mode — in Live mode this would have gone to: %s]\n\n", implode( ', ', (array) $to ) ) . $body;
                $to   = (string) $join->email;
            }
        }
        self::mail( $to, $subject, $body );
    }

    /**
     * Plain-text mail with the Settings Reply-To, like the invoice emails.
     *
     * @param string|array<int,string> $to
     */
    public static function mail( $to, string $subject, string $body ): void {
        $headers = [];
        $replyTo = (string) MyNJILGA_Dues_Settings::general( 'send_reply_to', '' );
        if ( is_email( $replyTo ) ) {
            $headers[] = 'Reply-To: ' . $replyTo;
        }
        wp_mail( $to, $subject, $body, $headers );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    public static function checkout_gateway(): ?MyNJILGA_Checkout_Gateway {
        $gateway = MyNJILGA_Invoicing::gateway();
        return $gateway instanceof MyNJILGA_Checkout_Gateway ? $gateway : null;
    }

    /**
     * @param array<string,mixed> $object A raw Checkout Session.
     */
    private static function join_for_session_object( array $object ) {
        $join = MyNJILGA_Join_Orders_Table::get_by_session( (string) ( $object['id'] ?? '' ) );
        if ( $join ) {
            return $join;
        }
        $joinId = (int) ( $object['metadata']['njilga_join_id'] ?? 0 );
        $join   = $joinId > 0 ? MyNJILGA_Join_Orders_Table::get( $joinId ) : null;
        // Only the session currently on file speaks for a join — an event
        // about an older, superseded session of the same join must not.
        return ( $join && (string) $join->stripe_session_id === (string) ( $object['id'] ?? '' ) ) ? $join : null;
    }

    /**
     * @return array{ok:bool,status:string,message:string}
     */
    private static function result( bool $ok, string $status, string $message ): array {
        return [ 'ok' => $ok, 'status' => $status, 'message' => $message ];
    }
}
