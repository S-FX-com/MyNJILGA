<?php
/**
 * The Launch Wizard's checks: is the site ready to open a dues year, and does
 * what the preview produced look right?
 *
 * Two halves, deliberately separate:
 *
 *   - PURE: evaluate( $facts ) turns a plain array of facts into a list of
 *     verdicts (ok / warn / fail / info), and review() / review_checks() do
 *     the same for the generated preview. No WordPress, so every verdict is
 *     unit-tested.
 *   - GLUE: gather_facts() reads the live site (Stripe health, Action
 *     Scheduler, settings, FluentCRM tags, membership figures). Every probe is
 *     isolated: one that throws yields `null` — "couldn't check" — and the
 *     page says so, instead of the whole checklist failing with it.
 *
 * Severity means one thing throughout:
 *   fail — something will go wrong if you carry on (money, or nobody billed).
 *   warn — probably wrong, or worth a person's eyes before launch.
 *   ok   — checked and fine.
 *   info — a fact to confirm by eye; can't be checked from here.
 */
class MyNJILGA_Launch_Checks {

    const OK   = 'ok';
    const WARN = 'warn';
    const FAIL = 'fail';
    const INFO = 'info';

    /** Unix time of the last "send me a test email" click. */
    const MAIL_TEST_OPTION = 'njilga_launch_mail_test_at';

    // -------------------------------------------------------------------------
    // Pure: the readiness checklist
    // -------------------------------------------------------------------------

    /**
     * @param array<string,mixed> $f Facts — see gather_facts() for what each is. A null fact means "couldn't check".
     * @return array<int,array{id:string,group:string,status:string,title:string,detail:string,fix:?array{label:string,url:string}}>
     */
    public static function evaluate( array $f ): array {
        $c    = [];
        $url  = (array) ( $f['urls'] ?? [] );
        $year = (int) ( $f['year'] ?? 0 );
        $add  = static function ( string $id, string $group, string $status, string $title, string $detail = '', ?array $fix = null ) use ( &$c ): void {
            $c[] = [ 'id' => $id, 'group' => $group, 'status' => $status, 'title' => $title, 'detail' => $detail, 'fix' => $fix ];
        };
        $fixTo = static function ( string $label, string $key ) use ( $url ): ?array {
            return isset( $url[ $key ] ) && $url[ $key ] !== '' ? [ 'label' => $label, 'url' => (string) $url[ $key ] ] : null;
        };

        // ---- Payments (Stripe) ----------------------------------------------
        $g = 'Payments (Stripe)';
        $mode = (string) ( $f['mode'] ?? '' );
        if ( $mode === 'live' ) {
            $add( 'mode', $g, self::OK, 'Stripe is in Live mode', 'Invoices created now are real and payable.' );
        } elseif ( $mode === 'test' ) {
            $add( 'mode', $g, self::WARN, 'Stripe is in Test mode', 'Fine for a rehearsal — but real firms need Live. In Test mode the wizard will not schedule emails to firms.', $fixTo( 'Switch mode (Settings → Payments)', 'payments' ) );
        } else {
            $add( 'mode', $g, self::WARN, 'Couldn\'t tell which Stripe mode is active', '', $fixTo( 'Settings → Payments', 'payments' ) );
        }

        if ( empty( $f['connected'] ) ) {
            $add( 'connected', $g, self::FAIL, 'Stripe is not connected in this mode', 'Invoices can\'t be created until a key is connected.', $fixTo( 'Connect Stripe (Settings → Payments)', 'payments' ) );
        } else {
            $health = $f['health'] ?? null;
            if ( $health === null ) {
                $add( 'health', $g, self::WARN, 'Couldn\'t check the Stripe connection just now', 'Stripe may be slow to answer. Re-run the checks in a moment.', $fixTo( 'Setup page', 'setup' ) );
            } elseif ( (array) $health ) {
                $add( 'health', $g, self::FAIL, 'Stripe connection needs attention', (string) reset( $health ), $fixTo( 'Settings → Payments', 'payments' ) );
            } else {
                $add( 'health', $g, self::OK, 'Stripe connection is healthy', 'The key works, the account can take charges and the webhook is in place.' );
            }
            foreach ( (array) ( $f['health_warnings'] ?? [] ) as $i => $w ) {
                $add( 'health_warning_' . $i, $g, self::WARN, 'Stripe: worth a look', (string) $w, $fixTo( 'Setup page', 'setup' ) );
            }
        }

        $add( 'encryption', $g, ! empty( $f['encryption'] ) ? self::OK : self::WARN,
            ! empty( $f['encryption'] ) ? 'Stripe keys are encrypted at rest' : 'Stripe keys are stored unencrypted',
            ! empty( $f['encryption'] ) ? '' : 'Optional hardening: define NJILGA_STRIPE_KEY in wp-config.php (steps are on Settings → Payments).',
            ! empty( $f['encryption'] ) ? null : $fixTo( 'Settings → Payments', 'payments' )
        );
        $add( 'stripe_emails', $g, self::INFO, 'Confirm Stripe\'s own invoice emails are off',
            'This plugin emails the invoices itself, on the schedule you choose. If Stripe is also set to email invoices to customers (Stripe Dashboard → Settings → Customer emails), firms could be emailed at creation time, ahead of your schedule, and twice. This can\'t be read from here — please check it once.' );

        // ---- Background jobs ------------------------------------------------
        $g = 'Background jobs';
        if ( empty( $f['as_available'] ) ) {
            $add( 'as', $g, self::FAIL, 'Action Scheduler is not available', 'It ships with FluentCRM. Without it invoices are created in one request and emails cannot be scheduled.', $fixTo( 'Setup page', 'setup' ) );
        } else {
            $add( 'as', $g, self::OK, 'Action Scheduler is available', 'Invoice creation and the scheduled sends run through it.' );
            $rs = $f['reconcile_scheduled'] ?? null;
            if ( $rs === null ) {
                $add( 'reconcile', $g, self::WARN, 'Couldn\'t check the daily Stripe reconcile' );
            } elseif ( $rs ) {
                $add( 'reconcile', $g, self::OK, 'The daily Stripe reconcile is scheduled', 'The safety net that catches a payment whose webhook went missing.' );
            } else {
                $add( 'reconcile', $g, self::WARN, 'The daily Stripe reconcile is not scheduled', 'It is queued after Action Scheduler starts; load an admin page once and re-run the checks. If it stays missing, payments rely on the webhook alone.', $fixTo( 'Setup page', 'setup' ) );
            }
        }
        $stuck = $f['events_stuck'] ?? null;
        if ( $stuck !== null ) {
            if ( (int) $stuck > 0 ) {
                $add( 'events', $g, self::WARN, sprintf( '%d Stripe event%s received but not processed', (int) $stuck, (int) $stuck === 1 ? ' was' : 's were' ), 'Run "Sync with Stripe" on the Invoicing page: it settles any invoice that was actually paid.', $fixTo( 'Invoicing', 'invoicing' ) );
            } else {
                $add( 'events', $g, self::OK, 'No stuck Stripe events' );
            }
        }

        // ---- Settings -------------------------------------------------------
        $g = 'Dues settings';
        $missing = [];
        $ladder  = [];
        $prices  = [];
        foreach ( (array) ( $f['categories'] ?? [] ) as $cat ) {
            $label = (string) ( $cat['label'] ?? $cat['key'] ?? '' );
            if ( (string) ( $cat['tag'] ?? '' ) !== '' && ( $cat['tag_exists'] ?? null ) === false ) {
                $missing[] = $label . ' — tag "' . (string) $cat['tag'] . '"';
            }
            foreach ( self::tier_problems( (array) $cat ) as $problem ) {
                $ladder[] = $label . ': ' . $problem;
            }
            $prices[] = $label . ' — ' . self::price_summary( (array) $cat );
        }
        if ( $missing ) {
            $add( 'tags', $g, self::FAIL, 'A category\'s tag doesn\'t exist in FluentCRM', implode( '; ', $missing ) . ' — pricing matches on these tags, so nobody would be put in that category.', $fixTo( 'Create it on the Setup page', 'setup' ) );
        } elseif ( ! empty( $f['categories'] ) ) {
            $add( 'tags', $g, self::OK, 'Every category\'s tag exists in FluentCRM' );
        }
        if ( $ladder ) {
            $add( 'tiers', $g, self::FAIL, 'A tier ladder is wrong', implode( '; ', $ladder ), $fixTo( 'Fix it in Settings', 'settings' ) );
        }
        if ( $prices ) {
            $add( 'prices', $g, self::INFO, 'What each category will be charged', implode( "\n", $prices ) . "\nTrustee Dinner Assessment — " . MyNJILGA_Invoicing::money( (int) ( $f['assessment_cents'] ?? 0 ) ) . ' (on top of dues, for officers and trustees)', $fixTo( 'Settings', 'settings' ) );
        }

        $defaultKey = (string) ( $f['default_category'] ?? '' );
        $defaultLbl = (string) ( $f['default_category_label'] ?? $defaultKey );
        $defaulted  = (int) ( $f['defaulted_total'] ?? 0 );
        if ( $defaultKey === '' ) {
            $add( 'default_category', $g, self::WARN, 'There is no default category', 'Contacts with no category tag will not be billed; they will be listed as exceptions.', $fixTo( 'Settings', 'settings' ) );
        } elseif ( ( $f['defaulted_total'] ?? null ) === null ) {
            $add( 'default_category', $g, self::INFO, 'Untagged contacts are billed as ' . $defaultLbl );
        } elseif ( $defaulted > 0 ) {
            $add( 'default_category', $g, self::WARN, sprintf( '%d contact%s hold no category tag', $defaulted, $defaulted === 1 ? '' : 's' ), sprintf( 'They will be billed as %s. If any are students, Emerging Professionals or dues-exempt, tag them in FluentCRM first — the price is read from the tag before anyone pays.', $defaultLbl ), $fixTo( 'See the counts (Setup)', 'setup' ) );
        } else {
            $add( 'default_category', $g, self::OK, 'Every member has a category tag' );
        }

        $joinYear = (int) ( $f['join_year_now'] ?? 0 );
        if ( ! empty( $f['join_enabled'] ) && $year > 0 && $joinYear > 0 ) {
            if ( $joinYear < $year ) {
                $add( 'join_year', $g, self::WARN, sprintf( 'Online joiners are currently paying %d, not %d', $joinYear, $year ), sprintf( 'Anyone who joins before the %d invoices are created would pay %d and then be billed %d as well. Set "Joins cover next year from" in step 2.', $year, $joinYear, $year ), [ 'label' => 'Set it in step 2', 'url' => (string) ( $url['step2'] ?? '' ) ] );
            } else {
                $add( 'join_year', $g, self::OK, sprintf( 'Online joiners now pay %d', $joinYear ) );
            }
        }

        $stripeBatch = (int) ( $f['batch_size'] ?? 0 );
        if ( $stripeBatch > 10 ) {
            $add( 'batch', $g, self::WARN, sprintf( 'Invoice creation batch size is %d', $stripeBatch ), 'Each firm takes several Stripe calls; a large batch can run into the server\'s time limit. 10 is a safer start.', $fixTo( 'Settings', 'settings' ) );
        }

        // ---- Invoice emails -------------------------------------------------
        $g = 'Invoice emails';
        $add( 'cc', $g, self::INFO, 'Who receives each invoice', (string) ( $f['cc_description'] ?? '' ), $fixTo( 'Settings', 'settings' ) );
        if ( trim( (string) ( $f['reply_to'] ?? '' ) ) === '' ) {
            $add( 'reply_to', $g, self::WARN, 'No Reply-To address is set', 'Replies to the invoice email will go to the site\'s From address, which may not be monitored.', $fixTo( 'Settings', 'settings' ) );
        } else {
            $add( 'reply_to', $g, self::OK, 'Replies go to ' . (string) $f['reply_to'] );
        }
        if ( trim( (string) ( $f['remittance'] ?? '' ) ) === '' ) {
            $add( 'remittance', $g, self::WARN, 'No mailing address for checks', 'The email offers online payment only. Add an address if firms may pay by check.', $fixTo( 'Settings → Payments', 'payments' ) );
        } else {
            $add( 'remittance', $g, self::OK, 'The email includes a mailing address for checks' );
        }
        $due = (int) ( $f['due_ts'] ?? 0 );
        if ( $due > 0 ) {
            $add( 'due', $g, self::INFO, 'Invoices created now are due ' . gmdate( 'F j, Y', $due ), 'Dues are due at the end of the year the invoice is raised in, with at least the "days until due" window.' );
        }
        $mailAt = $f['mail_test_at'] ?? null;
        if ( empty( $f['mail_ok'] ) ) {
            $add( 'mail', $g, self::FAIL, 'This site cannot send email', 'wp_mail() is unavailable.', null );
        } elseif ( $mailAt ) {
            $add( 'mail', $g, self::OK, 'A test email was sent on ' . gmdate( 'M j, Y H:i', (int) $mailAt ) . ' UTC', 'Confirm it arrived (and check spam) — that is the only proof deliverability works.', [ 'label' => 'Send another', 'url' => (string) ( $url['step5'] ?? '' ) ] );
        } else {
            $add( 'mail', $g, self::WARN, 'No test email has been sent yet', 'Send yourself one in step 5 and confirm it arrives before scheduling the real thing.', [ 'label' => 'Go to step 5', 'url' => (string) ( $url['step5'] ?? '' ) ] );
        }

        // ---- Members and firms ----------------------------------------------
        $g = 'Members & firms';
        $noOwner = $f['firms_no_owner'] ?? null;
        if ( $noOwner !== null ) {
            if ( (int) $noOwner > 0 ) {
                $add( 'owners', $g, self::WARN, sprintf( '%d firm%s no Owner', (int) $noOwner, (int) $noOwner === 1 ? ' has' : 's have' ), 'A firm needs an Owner to be invoiced. They will appear under "Needs attention" after the preview.', $fixTo( 'Firms report', 'firms' ) );
            } else {
                $add( 'owners', $g, self::OK, 'Every firm has an Owner' );
            }
        }
        $assumed = $f['legacy_paid_tag'] ?? null;
        if ( $assumed !== null ) {
            if ( (int) $assumed > 0 ) {
                $add( 'legacy_tag', $g, self::WARN, sprintf( '%d member%s only the old "dues-paid" tag', (int) $assumed, (int) $assumed === 1 ? ' carries' : 's carry' ), 'With no "Dues Paid {year}" tag they read as active "through this year" and will not show as expired on 1 January. Add the year tag for those who really paid (and remove the old tag from those who didn\'t).', $fixTo( 'Dashboard', 'dashboard' ) );
            } else {
                $add( 'legacy_tag', $g, self::OK, 'Every active member has a dated "Dues Paid" tag' );
            }
        }

        return $c;
    }

    /**
     * @param array<int,array<string,mixed>> $checks
     * @return array{fail:int,warn:int,ok:int,info:int}
     */
    public static function summarize( array $checks ): array {
        $n = [ self::FAIL => 0, self::WARN => 0, self::OK => 0, self::INFO => 0 ];
        foreach ( $checks as $c ) {
            $s = (string) ( $c['status'] ?? '' );
            if ( isset( $n[ $s ] ) ) {
                $n[ $s ]++;
            }
        }
        return $n;
    }

    /**
     * Problems with a tier-eligible category's price ladder. The engine ranks
     * members 1..n and prices each by the tier containing its rank; a gap or an
     * overlap, a ladder that doesn't start at 1, or one that stops short would
     * silently misprice a firm.
     *
     * @param array<string,mixed> $cat
     * @return array<int,string>
     */
    public static function tier_problems( array $cat ): array {
        if ( empty( $cat['tier_eligible'] ) ) {
            return [];
        }
        $tiers = array_values( (array) ( $cat['tiers'] ?? [] ) );
        if ( ! $tiers ) {
            return [ 'it is tier-eligible but has no tiers' ];
        }
        usort( $tiers, static function ( $a, $b ) { return (int) ( $a['from'] ?? 0 ) <=> (int) ( $b['from'] ?? 0 ); } );
        $problems = [];
        if ( (int) $tiers[0]['from'] !== 1 ) {
            $problems[] = 'the first tier starts at rank ' . (int) $tiers[0]['from'] . ', not 1';
        }
        $last = count( $tiers ) - 1;
        foreach ( $tiers as $i => $t ) {
            $from = (int) ( $t['from'] ?? 0 );
            $to   = (int) ( $t['to'] ?? 0 );
            if ( $to !== 0 && $to < $from ) {
                $problems[] = sprintf( 'tier "%s" ends (%d) before it starts (%d)', (string) ( $t['label'] ?? '' ), $to, $from );
            }
            if ( $i < $last ) {
                if ( $to === 0 ) {
                    $problems[] = sprintf( 'tier "%s" is open-ended but is not the last', (string) ( $t['label'] ?? '' ) );
                    continue;
                }
                $nextFrom = (int) $tiers[ $i + 1 ]['from'];
                if ( $nextFrom !== $to + 1 ) {
                    $problems[] = $nextFrom > $to + 1
                        ? sprintf( 'ranks %d–%d fall in no tier', $to + 1, $nextFrom - 1 )
                        : sprintf( 'tiers overlap around rank %d', $nextFrom );
                }
            } elseif ( $to !== 0 ) {
                $problems[] = sprintf( 'the last tier stops at rank %d — set To to 0 so larger firms are covered', $to );
            }
        }
        return $problems;
    }

    /**
     * "$125 first member, $75 members 2–5, free members 6+" or "$30 flat".
     *
     * @param array<string,mixed> $cat
     */
    public static function price_summary( array $cat ): string {
        $fmt = static function ( int $cents ): string {
            return $cents === 0 ? 'free' : MyNJILGA_Invoicing::money( $cents );
        };
        if ( ! empty( $cat['tier_eligible'] ) && ! empty( $cat['tiers'] ) ) {
            $parts = [];
            foreach ( (array) $cat['tiers'] as $t ) {
                $parts[] = $fmt( (int) ( $t['price_cents'] ?? 0 ) ) . ' ' . strtolower( (string) ( $t['label'] ?? '' ) );
            }
            return implode( ', ', $parts );
        }
        $cents = (int) ( $cat['price_cents'] ?? 0 );
        return $cents === 0 ? 'no charge' : $fmt( $cents ) . ' flat';
    }

    /**
     * How many of the recent events were received and then never processed.
     *
     * @param array<int,object> $events Rows with ->status and ->received_at.
     */
    public static function stuck_events( array $events, string $nowMysql, int $minutes = 10 ): int {
        $now = strtotime( $nowMysql );
        if ( $now === false ) {
            return 0;
        }
        $n = 0;
        foreach ( $events as $e ) {
            $at = strtotime( (string) ( $e->received_at ?? '' ) );
            if ( (string) ( $e->status ?? '' ) === 'received' && $at !== false && $now - $at > $minutes * 60 ) {
                $n++;
            }
        }
        return $n;
    }

    // -------------------------------------------------------------------------
    // Pure: what the generated preview looks like
    // -------------------------------------------------------------------------

    /**
     * Summarise a year's invoice rows (any status) for the Review and Create
     * steps. Join rows are online joins paid through Checkout, not firm
     * invoices, and are left out of every count.
     *
     * @param array<int,object> $rows
     * @return array<string,mixed>
     */
    public static function review( array $rows ): array {
        $s = [
            'total'            => 0,
            'by_status'        => [],
            'ready'            => 0,   // draft: reviewed, not yet created
            'ready_cents'      => 0,
            'creating'         => 0,   // approved + queued, or approved
            'created_unsent'   => 0,
            'sent'             => 0,
            'paid'             => 0,
            'excluded'         => 0,
            'excluded_by'      => [ 'no_owner' => 0, 'no_members' => 0, 'zero_total' => 0, 'other' => 0 ],
            'errors'           => 0,
            'no_bill_to_email' => 0,
            'attention'        => [],   // excluded rows, for the list
            'error_rows'       => [],
        ];
        foreach ( $rows as $r ) {
            if ( (string) ( $r->invoice_kind ?? '' ) === 'join' ) {
                continue;
            }
            $s['total']++;
            $st = (string) ( $r->status ?? '' );
            $s['by_status'][ $st ] = ( $s['by_status'][ $st ] ?? 0 ) + 1;

            $hasError = (string) ( $r->last_error ?? '' ) !== '' && in_array( $st, [ 'draft', 'approved', 'created' ], true );
            if ( $hasError ) {
                $s['errors']++;
                $s['error_rows'][] = [ 'id' => (int) $r->id, 'name' => MyNJILGA_Dues_Snapshot::company_name( $r ), 'error' => (string) $r->last_error ];
            }

            switch ( $st ) {
                case 'draft':
                    $s['ready']++;
                    $s['ready_cents'] += (int) ( $r->total_amount_cents ?? 0 );
                    $billTo = MyNJILGA_Dues_Snapshot::bill_to( $r );
                    if ( trim( (string) ( $billTo['email'] ?? '' ) ) === '' ) {
                        $s['no_bill_to_email']++;
                    }
                    break;
                case 'approved':
                    $s['creating']++;
                    break;
                case 'created':
                    $s['created_unsent']++;
                    break;
                case 'sent':
                case 'processing':
                    $s['sent']++;
                    break;
                case 'paid':
                    $s['paid']++;
                    break;
                case 'excluded':
                    $s['excluded']++;
                    $reason = (string) ( MyNJILGA_Dues_Snapshot::decode( $r )['exclusion_reason'] ?? '' );
                    $key    = [ 'excluded_no_owner' => 'no_owner', 'excluded_no_members' => 'no_members', 'excluded_zero_total' => 'zero_total' ][ $reason ] ?? 'other';
                    $s['excluded_by'][ $key ]++;
                    $s['attention'][] = [ 'id' => (int) $r->id, 'name' => MyNJILGA_Dues_Snapshot::company_name( $r ), 'reason' => $key ];
                    break;
            }
        }
        usort( $s['attention'], static function ( $a, $b ) { return strcasecmp( $a['name'], $b['name'] ); } );
        return $s;
    }

    /**
     * Verdicts on the preview.
     *
     * @param array<string,mixed> $review review()'s result.
     * @return array<int,array{id:string,group:string,status:string,title:string,detail:string,fix:?array{label:string,url:string}}>
     */
    public static function review_checks( array $review, int $year, string $invoicingUrl = '' ): array {
        $c   = [];
        $g   = 'The ' . $year . ' preview';
        $fix = $invoicingUrl !== '' ? [ 'label' => 'Open Invoicing', 'url' => $invoicingUrl ] : null;
        $add = static function ( string $id, string $status, string $title, string $detail = '', ?array $f = null ) use ( &$c, $g ): void {
            $c[] = [ 'id' => $id, 'group' => $g, 'status' => $status, 'title' => $title, 'detail' => $detail, 'fix' => $f ];
        };

        if ( (int) $review['total'] === 0 ) {
            $add( 'exists', self::INFO, 'No preview has been generated for ' . $year . ' yet', 'Step 2 generates it from FluentCRM. Nothing is billed or sent by generating a preview.' );
            return $c;
        }
        $add( 'exists', self::OK, sprintf( 'The preview covers %d firm invoice%s', (int) $review['total'], (int) $review['total'] === 1 ? '' : 's' ) );

        if ( (int) $review['errors'] > 0 ) {
            $add( 'errors', self::FAIL, sprintf( '%d invoice%s hit an error', (int) $review['errors'], (int) $review['errors'] === 1 ? '' : 's' ), 'Open each on the Invoicing page: the error is shown on the firm\'s row.', $fix );
        }
        $by = (array) $review['excluded_by'];
        if ( (int) $by['no_owner'] > 0 ) {
            $add( 'no_owner', self::WARN, sprintf( '%d firm%s no Owner and can\'t be invoiced', (int) $by['no_owner'], (int) $by['no_owner'] === 1 ? ' has' : 's have' ), 'Set an Owner on the FluentCRM company, then Refresh Firms.', $fix );
        }
        if ( (int) $by['no_members'] > 0 ) {
            $add( 'no_members', self::INFO, sprintf( '%d firm%s no members', (int) $by['no_members'], (int) $by['no_members'] === 1 ? ' has' : 's have' ), 'Nobody to bill. Usually an empty FluentCRM company.' );
        }
        if ( (int) $by['zero_total'] > 0 ) {
            $add( 'zero_total', self::INFO, sprintf( '%d firm%s nothing to bill', (int) $by['zero_total'], (int) $by['zero_total'] === 1 ? ' has' : 's have' ), 'Everyone on them is exempt, already paid for the year, or at no charge.' );
        }
        if ( (int) $by['other'] > 0 ) {
            $add( 'other', self::WARN, sprintf( '%d invoice%s excluded for another reason', (int) $by['other'], (int) $by['other'] === 1 ? ' is' : 's are' ), '', $fix );
        }
        if ( (int) $review['no_bill_to_email'] > 0 ) {
            $add( 'bill_to', self::WARN, sprintf( '%d invoice%s no bill-to email in the preview', (int) $review['no_bill_to_email'], (int) $review['no_bill_to_email'] === 1 ? ' has' : 's have' ), 'The email falls back to the contact\'s current address when it sends; check these firms have a reachable Owner.', $fix );
        }
        if ( (int) $review['ready'] === 0 && (int) $review['created_unsent'] === 0 && (int) $review['sent'] === 0 && (int) $review['paid'] === 0 ) {
            $add( 'nothing_ready', self::WARN, 'No invoice is ready to create', 'Everything was excluded. Check the lists above.', $fix );
        }
        return $c;
    }

    // -------------------------------------------------------------------------
    // Glue: reading the live site
    // -------------------------------------------------------------------------

    /**
     * Collect the facts evaluate() needs. Each probe is isolated: one that
     * fails is recorded as null ("couldn't check") and the rest carry on.
     *
     * @return array<string,mixed>
     */
    public static function gather_facts( int $year ): array {
        $f = [ 'year' => $year, 'urls' => self::urls( $year ) ];
        $probe = static function ( callable $fn, $fallback = null ) {
            try {
                return $fn();
            } catch ( \Throwable $e ) {
                return $fallback;
            }
        };

        $mode        = $probe( static function () { return MyNJILGA_Stripe_Connection::active_mode(); }, '' );
        $f['mode']   = (string) $mode;
        $livemode    = $mode === MyNJILGA_Stripe_Connection::MODE_LIVE;
        $f['connected'] = (bool) $probe( static function () use ( $mode ) { return MyNJILGA_Stripe_Connection::is_connected( $mode ); }, false );
        $f['health']          = $f['connected'] ? $probe( static function () use ( $mode ) { return MyNJILGA_Stripe_Connection::health( $mode ); }, null ) : [];
        $f['health_warnings'] = $f['connected'] ? (array) $probe( static function () use ( $mode ) { return MyNJILGA_Stripe_Connection::health_warnings( $mode ); }, [] ) : [];
        $f['encryption']      = (bool) $probe( static function () { return MyNJILGA_Stripe_Connection::encryption_active(); }, false );

        $f['as_available'] = function_exists( 'as_enqueue_async_action' );
        $f['reconcile_scheduled'] = $f['as_available']
            ? $probe( static function () { return function_exists( 'as_has_scheduled_action' ) ? (bool) as_has_scheduled_action( MyNJILGA_Stripe_Reconciler::HOOK_DAILY, null, MyNJILGA_Invoice_Creator::AS_GROUP ) : null; }, null )
            : false;
        $f['events_stuck'] = $probe( static function () use ( $livemode ) {
            return self::stuck_events( MyNJILGA_Stripe_Events_Table::recent( 200, $livemode ), current_time( 'mysql' ) );
        }, null );

        $settings   = (array) $probe( static function () { return MyNJILGA_Dues_Settings::get(); }, [] );
        $general    = (array) ( $settings['general'] ?? [] );
        $categories = [];
        $defaultLbl = '';
        foreach ( (array) ( $settings['categories'] ?? [] ) as $cat ) {
            $tag = (string) ( $cat['tag'] ?? '' );
            $cat['tag_exists'] = $tag === '' ? null : $probe( static function () use ( $tag ) { return MyNJILGA_Tags::resolve_slug( $tag ) !== null; }, null );
            $categories[] = $cat;
            if ( (string) ( $cat['key'] ?? '' ) === (string) ( $general['default_category'] ?? '' ) ) {
                $defaultLbl = (string) ( $cat['label'] ?? '' );
            }
        }
        $f['categories']             = $categories;
        $f['assessment_cents']       = (int) ( ( (array) ( $settings['assessment'] ?? [] ) )['price_cents'] ?? 0 );
        $f['default_category']       = (string) ( $general['default_category'] ?? '' );
        $f['default_category_label'] = $defaultLbl;
        $f['join_enabled']           = ! empty( $general['join_enabled'] );
        $f['join_year_now']          = (int) $probe( static function () use ( $livemode ) { return MyNJILGA_Join_Form::dues_year( $livemode ); }, 0 );
        $f['batch_size']             = (int) ( $general['batch_size'] ?? 0 );
        $f['reply_to']               = (string) ( $general['send_reply_to'] ?? '' );
        $f['cc_description']         = self::cc_description( $general );
        $f['remittance']             = (string) $probe( static function () { return MyNJILGA_Stripe_Connection::setting( 'remittance_address', '' ); }, '' );
        $days                        = (int) $probe( static function () { return MyNJILGA_Stripe_Connection::setting( 'days_until_due', 30 ); }, 30 );
        $f['due_ts']                 = (int) $probe( static function () use ( $days ) { return MyNJILGA_Invoicing::year_end_due_timestamp( $days ); }, 0 );

        $f['mail_ok']      = function_exists( 'wp_mail' );
        $f['mail_test_at'] = $probe( static function () { return (int) get_option( self::MAIL_TEST_OPTION, 0 ) ?: null; }, null );

        $stats = (array) $probe( static function () { return MyNJILGA_Membership_Stats::snapshot(); }, [] );
        if ( ! empty( $stats['available'] ) ) {
            $f['firms_no_owner']  = (int) ( $stats['firms']['no_owner'] ?? 0 );
            $f['legacy_paid_tag'] = (int) ( $stats['members']['assumed'] ?? 0 );
            $d = 0;
            foreach ( (array) ( $stats['categories'] ?? [] ) as $row ) {
                $d += (int) ( $row['defaulted'] ?? 0 );
            }
            $f['defaulted_total'] = $d;
        } else {
            $f['firms_no_owner']  = null;
            $f['legacy_paid_tag'] = null;
            $f['defaulted_total'] = null;
        }
        return $f;
    }

    /**
     * "The firm's Owner only" / "…and every member on the invoice" / "…plus a fixed list".
     *
     * @param array<string,mixed> $general
     */
    public static function cc_description( array $general ): string {
        $mode = (string) ( $general['send_cc_mode'] ?? '' );
        if ( $mode === 'all_members' ) {
            return 'The bill-to contact, with every member on the invoice copied.';
        }
        if ( $mode === 'custom' ) {
            $list = trim( (string) ( $general['send_cc_emails'] ?? '' ) );
            return 'The bill-to contact, with ' . ( $list !== '' ? $list : 'a fixed list (empty)' ) . ' copied.';
        }
        return 'The bill-to contact only — nobody is copied.';
    }

    /**
     * @return array<string,string>
     */
    private static function urls( int $year ): array {
        $menu = 'MyNJILGA_Admin_Menu';
        $page = static function ( string $slug, array $args = [] ): string {
            return add_query_arg( array_merge( [ 'page' => $slug ], $args ), admin_url( 'admin.php' ) );
        };
        return [
            'settings'  => $page( $menu::SLUG_SETTINGS ),
            'payments'  => $page( $menu::SLUG_SETTINGS, [ 'tab' => 'payments' ] ),
            'setup'     => $page( $menu::SLUG_SETUP ),
            'invoicing' => $page( $menu::SLUG_INVOICING, [ 'dues_year' => $year ] ),
            'firms'     => $page( $menu::SLUG_FIRMS ),
            'dashboard' => $page( $menu::SLUG_ROOT ),
            'step2'     => $page( $menu::SLUG_LAUNCH, [ 'step' => 2, 'dues_year' => $year ] ),
            'step5'     => $page( $menu::SLUG_LAUNCH, [ 'step' => 5, 'dues_year' => $year ] ),
        ];
    }
}
