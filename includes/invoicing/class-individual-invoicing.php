<?php
/**
 * Members who are on no firm — and the individual invoices that bill them directly.
 *
 * A firm's invoice is built from the firm's roster, so a member who is on no
 * firm's roster is on no invoice at all: nobody bills them, and they drift to
 * "expired" unnoticed. The Invoicing page lists those members (the same people the
 * Dashboard counts as "no firm": MyNJILGA_Membership_Stats' member definition, a
 * contact on no Company's roster) and offers each an INDIVIDUAL invoice — their own
 * dues, to their own email.
 *
 * What such an invoice is, in the table every other invoice lives in:
 *   fluentcrm_company_id   0          there is no firm (online joins are the same)
 *   billing_mode           individual
 *   invoice_kind           combined   one invoice for the person's dues (+ any assessment)
 *   bill_to / owner        the person
 *   roster_snapshot        the person alone, priced as a first member; the snapshot's
 *                          "company" name is the person's name, so every screen that
 *                          prints a firm name prints theirs
 * so it is created, emailed, paid, voided, re-issued, updated and swept by exactly the
 * code that does that for a firm. The unique key (company, year, kind, bill-to, mode)
 * keeps it to one per person per year.
 *
 * Adding them to Invoicing only makes the DRAFT; it shows up under "No Invoice" with
 * the firms' drafts, and nothing reaches Stripe until Create Invoice is pressed there.
 *
 * The rules (who is offered, why not) are pure and unit-tested; evaluate() and add() do
 * the reading and writing.
 */
class MyNJILGA_Individual_Invoicing {

    /** What an entry in the list can be. */
    const BILL     = 'bill';     // can be invoiced now
    const DRAFTED  = 'drafted';  // added already, not created yet — adding again refreshes it
    const INVOICED = 'invoiced'; // has an invoice past the draft: nothing to add
    const NOTHING  = 'nothing';  // owes nothing this year
    const NO_EMAIL = 'no_email'; // an invoice needs an address to go to
    const ON_FIRM  = 'on_firm';  // on a firm's roster now — the firm's invoice bills them

    /** Never read or write more people than this in one go. */
    const MAX_PER_ADD = 500;

    // -------------------------------------------------------------------------
    // PURE
    // -------------------------------------------------------------------------

    /**
     * The contacts of Membership Stats that are on no firm's roster, by name.
     *
     * @param array<int,array<string,mixed>> $contacts MyNJILGA_Membership_Stats::data()['contacts']
     * @return array<int,array<string,mixed>> contact id => contact
     */
    public static function without_firm( array $contacts ): array {
        $out = [];
        foreach ( $contacts as $id => $c ) {
            if ( empty( $c['firm_ids'] ) ) {
                $out[ (int) $id ] = $c;
            }
        }
        uasort( $out, static function ( $a, $b ) {
            $cmp = strcasecmp( (string) ( $a['name'] ?? '' ), (string) ( $b['name'] ?? '' ) );
            return $cmp !== 0 ? $cmp : ( (int) ( $a['id'] ?? 0 ) <=> (int) ( $b['id'] ?? 0 ) );
        } );
        return $out;
    }

    /**
     * Whether an invoice row is a member's own, filed under no firm: the rows this class makes.
     * (An online join is filed under no firm too, but it is paid before its row exists and is
     * its own kind.)
     *
     * @param object|array<string,mixed> $row
     */
    public static function is_self_billed( $row ): bool {
        $row = (array) $row;
        return (int) ( $row['fluentcrm_company_id'] ?? 0 ) <= 0
            && (string) ( $row['invoice_kind'] ?? '' ) === MyNJILGA_Dues_Snapshot::KIND_COMBINED
            && (string) ( $row['billing_mode'] ?? '' ) === MyNJILGA_Dues_Settings::MODE_INDIVIDUAL;
    }

    /**
     * Whether an invoice in this status can still be rewritten by adding the person again — a
     * draft, or the "nothing to bill" placeholder a draft becomes.
     */
    public static function is_open_draft( string $status ): bool {
        return in_array( $status, [ MyNJILGA_Dues_Invoice_Table::STATUS_DRAFT, MyNJILGA_Dues_Invoice_Table::STATUS_EXCLUDED ], true );
    }

    /**
     * Whether one person is offered an invoice, and if not why not.
     *
     * $f: on_firm (name of a firm whose roster they are on, '' for none), email, total_cents
     * (what they would be billed), reason (why that is $0, when it is), existing (status of
     * their individual invoice for the year, '' for none).
     *
     * Order matters: an invoice already past draft settles it; then the firm, whose invoice
     * bills them (a second, individual one would bill them twice); then an address; then $0.
     *
     * @param array<string,mixed> $f
     * @return array{code:string,can:bool,why:string}
     */
    public static function verdict( array $f ): array {
        $existing = (string) ( $f['existing'] ?? '' );
        $firm     = (string) ( $f['on_firm'] ?? '' );
        if ( $existing !== '' && ! self::is_open_draft( $existing ) ) {
            return [ 'code' => self::INVOICED, 'can' => false, 'why' => 'Already has an invoice for this year' ];
        }
        if ( $firm !== '' ) {
            return [ 'code' => self::ON_FIRM, 'can' => false, 'why' => sprintf( 'On %s\'s roster, so the firm\'s invoice bills them', $firm ) ];
        }
        if ( trim( (string) ( $f['email'] ?? '' ) ) === '' ) {
            return [ 'code' => self::NO_EMAIL, 'can' => false, 'why' => 'No email address on the contact' ];
        }
        if ( (int) ( $f['total_cents'] ?? 0 ) <= 0 ) {
            $why = trim( (string) ( $f['reason'] ?? '' ) );
            // An old draft can still be refreshed — to "nothing to bill" — so it does not stay Ready at a stale price.
            return [ 'code' => self::NOTHING, 'can' => $existing !== '', 'why' => $why !== '' ? $why : 'Nothing owed this year' ];
        }
        return [ 'code' => $existing !== '' ? self::DRAFTED : self::BILL, 'can' => true, 'why' => '' ];
    }

    /**
     * Why a person comes to $0, in words, from the priced member the pricing engine made.
     *
     * @param array<string,mixed> $member
     */
    public static function nothing_reason( array $member ): string {
        $unbilled = (string) ( $member['unbilled_reason'] ?? '' );
        if ( $unbilled === MyNJILGA_Pricing_Engine::UNBILLED_INACTIVE ) {
            return 'Inactive';
        }
        if ( $unbilled === MyNJILGA_Pricing_Engine::UNBILLED_NO_CATEGORY ) {
            return 'No category tag — add one in the CRM';
        }
        $note = trim( (string) ( $member['dues_note'] ?? '' ) );
        if ( $note === MyNJILGA_Dues_Preview::NOTE_YEAR_PAID ) {
            return 'Already paid for this year';
        }
        if ( $note === MyNJILGA_Dues_Preview::NOTE_JOIN_PAID || $note === MyNJILGA_Dues_Preview::NOTE_JOIN_CLEARING ) {
            return 'Paid through an online join';
        }
        return $note !== '' ? ucfirst( $note ) . ' — $0 dues' : 'Nothing owed this year';
    }

    /**
     * The invoice candidate for one person: they alone, priced as a first member, billed to
     * themselves, filed under no firm. The shape MyNJILGA_Dues_Preview stores a firm's in.
     *
     * @param array{contact_id:int,name:string,first_name:string,last_name:string,email:string} $person
     * @param array<int,array<string,mixed>> $members the priced member(s) — what Pricing_Engine::price() returned
     * @return array<string,mixed>
     */
    public static function candidate( int $duesYear, array $person, array $members ): array {
        $name  = $person['name'] !== '' ? $person['name'] : $person['email'];
        $total = MyNJILGA_Pricing_Engine::totals( $members )['total_cents'];
        return MyNJILGA_Dues_Preview::make_candidate(
            (object) [ 'id' => 0, 'name' => $name ],
            $duesYear,
            MyNJILGA_Dues_Settings::MODE_INDIVIDUAL,
            MyNJILGA_Dues_Snapshot::KIND_COMBINED,
            $person,
            $person,
            $members,
            $total > 0 ? 'draft' : MyNJILGA_Dues_Preview::EXCLUDED_ZERO_TOTAL
        );
    }

    /**
     * The sentence that sums up an add() run: "3 invoices added …".
     *
     * @param array{added:int,refreshed:int,skipped:array<int,string>} $r
     */
    public static function summary( array $r ): string {
        $parts = [];
        if ( $r['added'] > 0 ) {
            $parts[] = sprintf( '%d individual invoice%s added under No Invoice', $r['added'], $r['added'] === 1 ? '' : 's' );
        }
        if ( $r['refreshed'] > 0 ) {
            $parts[] = sprintf( '%d draft%s refreshed', $r['refreshed'], $r['refreshed'] === 1 ? '' : 's' );
        }
        if ( $r['skipped'] ) {
            $parts[] = sprintf( '%d skipped', count( $r['skipped'] ) );
        }
        return $parts ? implode( ', ', $parts ) . '.' : 'Nothing was added.';
    }

    // -------------------------------------------------------------------------
    // WP-facing
    // -------------------------------------------------------------------------

    /**
     * Everyone the page or the add step needs to judge, with what they would be billed.
     *
     * With $only null: the members on no firm. With ids: exactly those contacts, wherever they
     * are — so a person who has since joined a firm comes back as ON_FIRM instead of being
     * silently dropped.
     *
     * 'people' is contact id => [ id, name, email, state, through, category (label), cents (what
     * they would be billed), reason, verdict, row (their individual invoice for the year, or
     * null), member (the priced line), person (the bill-to), firm ('' or the firm they are on) ].
     *
     * 'conflicts' lists the people who have an open individual invoice AND are on a firm's roster now
     * (see conflicts()).
     *
     * @param array<int,int>|null $only
     * @return array{available:bool,warnings:array<int,string>,people:array<int,array<string,mixed>>,year:int,conflicts:array<int,array{id:int,name:string,firm:string,status:string}>}
     */
    public static function evaluate( int $duesYear, bool $live, ?array $only = null ): array {
        $out  = [ 'available' => false, 'warnings' => [], 'people' => [], 'year' => $duesYear, 'conflicts' => [] ];
        $data = MyNJILGA_Membership_Stats::data();
        if ( empty( $data['available'] ) ) {
            $out['warnings'] = (array) ( $data['warnings'] ?? [] );
            return $out;
        }
        $out['available'] = true;
        $out['warnings']  = (array) ( $data['warnings'] ?? [] );

        if ( $only === null ) {
            $set = self::without_firm( (array) $data['contacts'] );
        } else {
            $set = [];
            foreach ( array_slice( array_values( array_unique( array_map( 'intval', $only ) ) ), 0, self::MAX_PER_ADD ) as $id ) {
                if ( isset( $data['contacts'][ $id ] ) ) {
                    $set[ $id ] = $data['contacts'][ $id ];
                }
            }
        }
        $rows = [];
        foreach ( MyNJILGA_Dues_Invoice_Table::get_by_year( $duesYear, [], $live ) as $row ) {
            if ( self::is_self_billed( $row ) ) {
                $rows[ (int) $row->bill_to_contact_id ] = $row;
            }
        }
        $out['conflicts'] = self::conflicts( $rows, (array) $data['contacts'], (array) $data['firms'] );
        if ( ! $set ) {
            return $out;
        }

        $subs    = self::subscribers( array_keys( $set ) );
        $config  = MyNJILGA_Dues_Preview::config_for_year( $duesYear );
        $slugMap = MyNJILGA_Dues_Preview::resolve_configured_tags();

        foreach ( $set as $id => $c ) {
            $sub    = $subs[ $id ] ?? null;
            $person = MyNJILGA_Dues_Preview::person_from_contact( $sub, (int) $id );
            $priced = [ 'members' => [], 'totals' => [ 'total_cents' => 0 ] ];
            if ( $sub ) {
                $priced = MyNJILGA_Pricing_Engine::price( [ MyNJILGA_Dues_Preview::roster_entry( $sub, $slugMap ) ], $config );
                $priced['members'] = MyNJILGA_Dues_Preview::flag_join_pending( $priced['members'], $config );
            }
            $member = $priced['members'][0] ?? [];
            $cents  = (int) ( $priced['totals']['total_cents'] ?? 0 );
            $firm   = '';
            foreach ( (array) ( $c['firm_ids'] ?? [] ) as $fid ) {
                $firm = (string) ( $data['firms'][ (int) $fid ]['name'] ?? ( 'firm #' . (int) $fid ) );
                break;
            }
            $row    = $rows[ (int) $id ] ?? null;
            $reason = $sub ? self::nothing_reason( $member ) : 'Not found in FluentCRM';

            $out['people'][ (int) $id ] = [
                'id'       => (int) $id,
                'name'     => $person['name'] !== '' ? $person['name'] : (string) ( $c['name'] ?? '' ),
                'email'    => $person['email'],
                'state'    => (string) ( $c['state'] ?? '' ),
                'through'  => $c['through'] ?? null,
                'category' => (string) ( $member['category_label'] ?? '' ),
                'cents'    => $cents,
                'reason'   => $reason,
                'verdict'  => $sub
                    ? self::verdict( [ 'on_firm' => $firm, 'email' => $person['email'], 'total_cents' => $cents, 'reason' => $reason, 'existing' => $row ? (string) $row->status : '' ] )
                    : [ 'code' => self::NOTHING, 'can' => false, 'why' => $reason ],
                'row'      => $row,
                'member'   => $member,
                'person'   => $person,
                'firm'     => $firm,
            ];
        }
        return $out;
    }

    /**
     * Make the drafts: one individual invoice per person, for the ones who can have it. Nothing
     * reaches Stripe — they show up under No Invoice. A person who cannot have one (on a firm
     * now, nothing owed, already invoiced, no email) is skipped and told why; an old draft of
     * theirs is refreshed to today's price.
     *
     * @param array<int,int> $contactIds
     * @return array{added:int,refreshed:int,skipped:array<int,string>,rows:array<int,int>}
     */
    public static function add( array $contactIds, int $duesYear, bool $live ): array {
        $out = [ 'added' => 0, 'refreshed' => 0, 'skipped' => [], 'rows' => [] ];
        $ids = array_values( array_unique( array_filter( array_map( 'intval', $contactIds ) ) ) );
        if ( ! $ids ) {
            return $out;
        }
        $ev = self::evaluate( $duesYear, $live, $ids );
        if ( ! $ev['available'] ) {
            $out['skipped'][] = $ev['warnings'] ? (string) $ev['warnings'][0] : 'Membership figures could not be read from FluentCRM.';
            return $out;
        }
        foreach ( $ids as $id ) {
            $p = $ev['people'][ $id ] ?? null;
            if ( $p === null ) {
                $out['skipped'][] = sprintf( 'Contact #%d: not a member on record', $id );
                continue;
            }
            if ( ! $p['verdict']['can'] ) {
                $out['skipped'][] = sprintf( '%s: %s', $p['name'], $p['verdict']['why'] );
                continue;
            }
            $candidate = self::candidate( $duesYear, $p['person'], $p['member'] ? [ $p['member'] ] : [] );
            $rowId     = MyNJILGA_Dues_Preview::persist_candidate( $candidate, $live );
            if ( ! $rowId ) {
                $out['skipped'][] = sprintf( '%s: their invoice could not be saved — it may have just been created, so check the table', $p['name'] );
                continue;
            }
            $out['rows'][] = (int) $rowId;
            if ( $p['row'] ) {
                $out['refreshed']++;
            } else {
                $out['added']++;
            }
        }
        return $out;
    }

    /** Statuses of an individual invoice that still bills somebody: a firm invoice for the same person would bill them twice. */
    const OPEN_STATUSES = [ 'draft', 'approved', 'created', 'sent', 'processing' ];

    /**
     * PURE: the people who have an open individual invoice but are on a firm's roster, so the firm's
     * invoice bills them too. Nothing stops this from happening — a member can be added to a firm
     * after their own invoice was made — so the page says so, and staff void one of the two.
     *
     * @param array<int,object>              $rows     individual invoice rows by bill-to contact id
     * @param array<int,array<string,mixed>> $contacts MyNJILGA_Membership_Stats::data()['contacts']
     * @param array<int,array<string,mixed>> $firms    MyNJILGA_Membership_Stats::data()['firms']
     * @return array<int,array{id:int,name:string,firm:string,status:string}>
     */
    public static function conflicts( array $rows, array $contacts, array $firms ): array {
        $out = [];
        foreach ( $rows as $id => $row ) {
            if ( ! in_array( (string) $row->status, self::OPEN_STATUSES, true ) ) {
                continue;
            }
            $firmIds = (array) ( $contacts[ (int) $id ]['firm_ids'] ?? [] );
            if ( ! $firmIds ) {
                continue;
            }
            $fid   = (int) reset( $firmIds );
            $out[] = [
                'id'     => (int) $id,
                'name'   => MyNJILGA_Dues_Snapshot::company_name( $row ),
                'firm'   => (string) ( $firms[ $fid ]['name'] ?? ( 'firm #' . $fid ) ),
                'status' => (string) $row->status,
            ];
        }
        return $out;
    }

    /**
     * Refresh Firms found a firm invoice that bills these people: their own individual DRAFTS are
     * dropped, so the same dues are not created twice. Only drafts (and the "nothing to bill"
     * placeholders) — an invoice already created is a fact for staff to void, and is flagged by
     * conflicts() instead.
     *
     * @param array<int,int> $billedByFirms contact ids on a firm invoice that will exist
     * @return int rows removed
     */
    public static function retire_drafts_of( array $billedByFirms, int $duesYear, bool $live ): int {
        $billed = array_flip( array_map( 'intval', $billedByFirms ) );
        $ids    = [];
        foreach ( MyNJILGA_Dues_Invoice_Table::get_by_year( $duesYear, [ MyNJILGA_Dues_Invoice_Table::STATUS_DRAFT, MyNJILGA_Dues_Invoice_Table::STATUS_EXCLUDED ], $live ) as $row ) {
            if ( self::is_self_billed( $row ) && isset( $billed[ (int) $row->bill_to_contact_id ] ) ) {
                $ids[] = (int) $row->id;
            }
        }
        return $ids ? MyNJILGA_Dues_Invoice_Table::delete_individual_drafts( $ids, $duesYear, $live ) : 0;
    }

    /**
     * An individual invoice, priced again from today's data — the same answer MyNJILGA_Dues_Preview::
     * candidate_for_row() gives for a firm's. 'readdressed' is always false: this invoice goes to
     * the person it is about, or to nobody.
     *
     * @return array{ok:bool,error:string,candidate:?array<string,mixed>,readdressed:bool}
     */
    public static function candidate_for_row( object $row ): array {
        $fail = static function ( string $why ): array { return [ 'ok' => false, 'error' => $why, 'candidate' => null, 'readdressed' => false ]; };
        if ( ! self::is_self_billed( $row ) ) {
            return $fail( 'This invoice isn\'t filed under a firm and isn\'t a member\'s own invoice, so it can\'t be priced again.' );
        }
        $live = ! empty( $row->livemode );
        $id   = (int) $row->bill_to_contact_id;
        $ev   = self::evaluate( (int) $row->dues_year, $live, [ $id ] );
        if ( ! $ev['available'] ) {
            return $fail( 'Membership figures could not be read from FluentCRM, so this invoice can\'t be priced again. Please try again.' );
        }
        $p = $ev['people'][ $id ] ?? null;
        if ( $p === null ) {
            return $fail( 'This person is no longer a member on record in FluentCRM, so they can\'t be priced again. If nothing is owed any more, Void the invoice instead.' );
        }
        // Not the verdict: this row IS their invoice, so the verdict reads "invoiced" before it ever gets to the firm.
        if ( $p['firm'] !== '' ) {
            return $fail( sprintf( 'Priced from today\'s data, %s is now on %s\'s roster, so the firm\'s invoice should bill them. Void this one, then click Refresh Firms.', $p['name'], $p['firm'] ) );
        }
        if ( $p['cents'] <= 0 || $p['email'] === '' ) {
            return $fail( sprintf( 'Priced from today\'s data, %s would not be invoiced: %s. If nothing is owed any more, Void the invoice instead.', $p['name'], $p['email'] === '' ? 'there is no email address on the contact' : lcfirst( $p['reason'] ) ) );
        }
        return [ 'ok' => true, 'error' => '', 'candidate' => self::candidate( (int) $row->dues_year, $p['person'], [ $p['member'] ] ), 'readdressed' => false ];
    }

    /**
     * FluentCRM contacts with their tags, by id — a few queries however many.
     *
     * @param array<int,int> $ids
     * @return array<int,object> id => Subscriber
     */
    private static function subscribers( array $ids ): array {
        $out = [];
        foreach ( array_chunk( array_values( array_map( 'intval', $ids ) ), 200 ) as $chunk ) {
            foreach ( \FluentCrm\App\Models\Subscriber::with( 'tags' )->whereIn( 'id', $chunk )->get() as $sub ) {
                $out[ (int) $sub->id ] = $sub;
            }
        }
        return $out;
    }
}
