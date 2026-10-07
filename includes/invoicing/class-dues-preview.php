<?php
/**
 * Step 1 (spec §7) — the preview builder. Runs the pricing engine across
 * every FluentCRM Company for a dues year, splits each firm into the
 * invoice rows its billing mode calls for, flags exceptions separately
 * from normal rows, and persists everything as frozen draft snapshots in
 * njilga_dues_invoices.
 *
 * This class does the I/O (FluentCRM reads, tag-slug resolution, DB
 * writes). The math is entirely in MyNJILGA_Pricing_Engine, which is pure
 * and unit-tested; nothing here re-implements a pricing rule.
 *
 * Exceptions (never silently skipped, never guessed at):
 *   excluded_no_members  Company has no attached contacts.
 *   excluded_no_owner    Company has contacts but no Owner → no bill-to.
 *   excluded_zero_total  Nothing on the roster is billable this year.
 * Per-member exceptions (row still generated, but called out on the
 * card): contacts with no category tag when no default category is set,
 * and members on an online join whose payment hasn't settled — billed
 * as usual, since that join may yet pay for nobody (join_coverage()).
 *
 * Billing modes (spec §3.4, per-firm override in Settings):
 *   firm              one 'combined' invoice to the Owner covering everyone.
 *   individual        one 'combined' invoice per billed member, to that
 *                     member. Members at $0 ride on the Owner's own
 *                     invoice if the Owner is a billed member, else on the
 *                     rank-1 member's, so paying it still covers them.
 *   split_assessment  one 'dues' invoice to the Owner (assessments zeroed)
 *                     + one 'assessment' invoice per assessed member, to
 *                     that member.
 * A member with no email can't be billed individually — their invoice is
 * addressed to the Owner instead and the card says so.
 */
class MyNJILGA_Dues_Preview {

    const EXCLUDED_NO_MEMBERS = 'excluded_no_members';
    const EXCLUDED_NO_OWNER   = 'excluded_no_owner';
    const EXCLUDED_ZERO_TOTAL = 'excluded_zero_total';

    /**
     * The dues_note on a member priced at $0 because an online join paid
     * their dues. NOTE_JOIN_CLEARING was written by 3.1.3 for a join whose
     * ACH debit was still clearing; nothing writes it now (only settled
     * money covers anyone — see join_coverage()), but drafts carrying it
     * may still exist, so it is still recognised.
     */
    const NOTE_JOIN_PAID     = 'paid via online join';
    const NOTE_JOIN_CLEARING = 'online join payment clearing';

    /**
     * The dues_note on a member priced at $0 because they already carry
     * the year's "Dues Paid {year}" tag (see year_paid_contacts()).
     */
    const NOTE_YEAR_PAID = 'already paid';

    /**
     * Compute (no DB writes) every invoice candidate for the year.
     *
     * @return array<int,array<string,mixed>> Candidate rows, sorted by company name.
     */
    public static function compute( int $duesYear ): array {
        if ( ! MyNJILGA_Members_Data::companies_module_active() ) {
            return [];
        }

        $config    = self::config_for_year( $duesYear );
        $slugMap   = self::resolve_configured_tags();
        $companies = \FluentCrm\App\Models\Company::with( [ 'subscribers.tags', 'owner' ] )->get();

        $candidates = [];
        foreach ( $companies as $company ) {
            foreach ( self::candidates_for_company( $company, $duesYear, $config, $slugMap ) as $candidate ) {
                $candidates[] = $candidate;
            }
        }

        usort( $candidates, static function ( $a, $b ) {
            $cmp = strcasecmp( $a['company_name'], $b['company_name'] );
            if ( $cmp !== 0 ) {
                return $cmp;
            }
            return strcmp( $a['invoice_kind'] . $a['bill_to']['name'], $b['invoice_kind'] . $b['bill_to']['name'] );
        } );

        return $candidates;
    }

    /**
     * compute() + persist. Draft/excluded rows for a firm that this run
     * didn't produce are deleted (a billing-mode change makes last run's
     * per-member drafts stale); anything approved or later is untouched.
     *
     * @return array{rows:int,drafts:int,excluded:int,blocked:int,stale_removed:int,total_cents:int}
     */
    public static function generate_and_persist( int $duesYear ): array {
        $candidates = self::compute( $duesYear );
        $livemode   = ( MyNJILGA_Stripe_Connection::active_mode() === MyNJILGA_Stripe_Connection::MODE_LIVE );

        $stats  = [ 'rows' => 0, 'drafts' => 0, 'excluded' => 0, 'blocked' => 0, 'stale_removed' => 0, 'total_cents' => 0 ];
        $keep   = []; // company_id => [row ids produced]

        foreach ( $candidates as $c ) {
            $rowId = self::persist_candidate( $c, $livemode );
            $keep[ $c['company_id'] ][] = $rowId;
            $stats['rows']++;
            if ( $rowId === null ) {
                $stats['blocked']++;
                continue;
            }
            if ( $c['status'] === 'draft' ) {
                $stats['drafts']++;
                $stats['total_cents'] += (int) $c['totals']['total_cents'];
            } else {
                $stats['excluded']++;
            }
        }

        foreach ( $keep as $companyId => $ids ) {
            // A null id means an approved+ row blocked the refresh; that row
            // must survive, and so must any other non-draft row — deletion is
            // limited to draft/excluded anyway. Scoped to THIS run's mode —
            // the other mode's stale drafts, if any, are untouched.
            $stats['stale_removed'] += MyNJILGA_Dues_Invoice_Table::delete_stale_drafts( (int) $companyId, $duesYear, array_filter( $ids ), $livemode );
        }

        return $stats;
    }

    /**
     * Enrollment (spec §10) "invoice now" policy: a draft individual
     * invoice for ONE contact of a company, priced as if they were the
     * only tier-eligible member (they're joining mid-year; the rest of
     * the firm was already billed in the annual batch).
     *
     * @return int|null Row id, or null if it couldn't be created.
     */
    public static function draft_individual_for_contact( int $contactId, int $companyId, int $duesYear ): ?int {
        if ( ! MyNJILGA_Members_Data::companies_module_active() ) {
            return null;
        }
        $company = \FluentCrm\App\Models\Company::with( 'owner' )->find( $companyId );
        $contact = \FluentCrm\App\Models\Subscriber::with( 'tags' )->find( $contactId );
        if ( ! $company || ! $contact ) {
            return null;
        }

        $config  = self::config_for_year( $duesYear );
        $slugMap = self::resolve_configured_tags();
        $priced  = MyNJILGA_Pricing_Engine::price( [ self::roster_entry( $contact, $slugMap ) ], $config );
        $members = self::flag_join_pending( $priced['members'], $config );
        if ( empty( $members ) ) {
            return null;
        }

        $owner  = self::person_from_contact( $company->owner ?? null, (int) ( $company->owner_id ?? 0 ) );
        $billTo = self::person_from_contact( $contact, $contactId );
        if ( $billTo['email'] === '' ) {
            $billTo = $owner;
        }

        $candidate = self::make_candidate(
            $company,
            $duesYear,
            MyNJILGA_Dues_Settings::MODE_INDIVIDUAL,
            MyNJILGA_Dues_Snapshot::KIND_COMBINED,
            $owner,
            $billTo,
            $members,
            $priced['totals']['total_cents'] > 0 ? 'draft' : self::EXCLUDED_ZERO_TOTAL
        );
        return self::persist_candidate( $candidate, MyNJILGA_Stripe_Connection::active_mode() === MyNJILGA_Stripe_Connection::MODE_LIVE );
    }

    /**
     * The engine config for a year's preview: the settings, plus everyone
     * whose dues for that year are already paid through an online join
     * (MyNJILGA_Join_Fulfillment) — they stay on the firm's roster at $0,
     * so re-running the preview for a year people have joined in never
     * bills them a second time, and nobody else's tier shifts — and, as
     * 'dues_pending' (which the engine ignores), everyone on a join whose
     * money hasn't settled, for flag_join_pending(). Plus, as 'dues_paid',
     * everyone already tagged as paid for the year some other way — they
     * stay on the roster at $0 and owe no assessment.
     *
     * @return array<string,mixed>
     */
    private static function config_for_year( int $duesYear ): array {
        $config   = MyNJILGA_Dues_Settings::engine_config();
        $coverage = self::join_coverage( $duesYear, MyNJILGA_Stripe_Connection::active_mode() === MyNJILGA_Stripe_Connection::MODE_LIVE );
        $config['dues_covered'] = $coverage['covered'];
        $config['dues_pending'] = $coverage['pending'];
        $config['dues_paid']    = self::year_paid_contacts( $duesYear );
        return $config;
    }

    /**
     * Everyone carrying the year's "Dues Paid {year}" tag — contact id =>
     * NOTE_YEAR_PAID. That tag is what a paid invoice or join writes, and
     * what staff (or an import) put on anyone who paid the year outside
     * Stripe — a PMPro renewal, a check — so a preview for that year must
     * not bill them again — nor the downgrade sweep lapse them for sitting
     * at $0 on their firm's unpaid invoice. The tag is looked up, never
     * created.
     *
     * @return array<int,string>
     */
    public static function year_paid_contacts( int $duesYear ): array {
        $tagId = MyNJILGA_Tags::find_title_id( MyNJILGA_Dues_Settings::year_tag( 'year_paid_tag_pattern', $duesYear ) );
        if ( ! $tagId ) {
            return [];
        }
        $out = [];
        foreach ( \FluentCrm\App\Models\Subscriber::filterByTags( [ $tagId ] )->pluck( 'id' ) as $id ) {
            $out[ (int) $id ] = self::NOTE_YEAR_PAID;
        }
        return $out;
    }

    /**
     * Who online joins cover for a year and Stripe mode, as of now.
     *
     *   covered    contact id => NOTE_JOIN_PAID — settled money only: a
     *              paid join's own invoice row, or a join whose payment is
     *              in and checked but not yet applied (a failed run
     *              waiting on a retry — it has no row or "Dues Paid" tag
     *              yet, and a preview in that window would otherwise
     *              freeze them onto the firm's invoice too).
     *   pending    contact id => what they are waiting on — a join whose
     *              ACH debit is still clearing, or that is paid but held
     *              for staff because its checkout didn't verify. These
     *              are NOT covered: the debit can still fail and a held
     *              payment be refunded, and a firm invoice that listed
     *              them at $0 would make them members for free when the
     *              firm pays. They are priced as the firm would price
     *              them and called out on the card instead.
     *   join_rows  contact id => the paid join invoice row covering them
     *              (MyNJILGA_Dues_Invoice_Table::join_paid_contacts()).
     *
     * Also what MyNJILGA_Invoice_Creator re-checks a frozen snapshot
     * against before it goes out.
     *
     * @return array{covered:array<int,string>,pending:array<int,string>,join_rows:array<int,int>}
     */
    public static function join_coverage( int $duesYear, bool $livemode ): array {
        $rows    = MyNJILGA_Dues_Invoice_Table::join_paid_contacts( $duesYear, $livemode );
        $covered = array_fill_keys( array_keys( $rows ), self::NOTE_JOIN_PAID );
        $pending = [];
        foreach ( MyNJILGA_Join_Orders_Table::get_committed_for_year( $duesYear, $livemode ) as $join ) {
            $settled = MyNJILGA_Join_Orders_Table::payment_settled( $join );
            $waiting = sprintf(
                'online join #%d, whose %s',
                (int) $join->id,
                $join->status === MyNJILGA_Join_Orders_Table::STATUS_PROCESSING
                    ? 'bank payment is still clearing'
                    : 'payment is held for staff review on the Online joins screen'
            );
            foreach ( MyNJILGA_Join_Fulfillment::contact_ids_for( $join ) as $cid ) {
                if ( $settled ) {
                    $covered[ $cid ] = self::NOTE_JOIN_PAID;
                } elseif ( ! isset( $pending[ $cid ] ) ) {
                    $pending[ $cid ] = $waiting;
                }
            }
        }
        return [
            'covered'   => $covered,
            'pending'   => array_diff_key( $pending, $covered ),
            'join_rows' => $rows,
        ];
    }

    /**
     * Mark the members an unsettled online join names (config
     * 'dues_pending', see join_coverage()) without changing their price —
     * make_candidate() turns the mark into a note on the card, so staff
     * can hold the invoice until the join settles one way or the other.
     * Pure — tested directly.
     *
     * @param array<int,array<string,mixed>> $members Priced members.
     * @param array<string,mixed>            $config
     * @return array<int,array<string,mixed>>
     */
    public static function flag_join_pending( array $members, array $config ): array {
        $pending = (array) ( $config['dues_pending'] ?? [] );
        foreach ( $members as $i => $m ) {
            $cid = (int) ( $m['contact_id'] ?? 0 );
            if ( $cid > 0 && isset( $pending[ $cid ] ) ) {
                $members[ $i ]['join_pending'] = (string) $pending[ $cid ];
            }
        }
        return $members;
    }

    /**
     * Whether a snapshot member is at $0 dues because an online join paid
     * for them — as opposed to a 6th-or-later member, an exempt category
     * or the inactive override. Pure — tested directly.
     *
     * @param array<string,mixed> $member
     */
    public static function priced_as_join_covered( array $member ): bool {
        return (int) ( $member['dues_cents'] ?? 0 ) <= 0
            && empty( $member['unbilled_reason'] )
            && in_array( (string) ( $member['dues_note'] ?? '' ), [ self::NOTE_JOIN_PAID, self::NOTE_JOIN_CLEARING ], true );
    }

    // -------------------------------------------------------------------------
    // Per-company
    // -------------------------------------------------------------------------

    /**
     * @param \FluentCrm\App\Models\Company $company
     * @param array<string,mixed>           $config
     * @param array<string,int>             $slugMap
     * @return array<int,array<string,mixed>>
     */
    private static function candidates_for_company( $company, int $duesYear, array $config, array $slugMap ): array {
        $companyId = (int) $company->id;
        $subs      = self::subscribers_of( $company );
        $owner     = self::person_from_contact( $company->owner ?? null, (int) ( $company->owner_id ?? 0 ) );
        $mode      = MyNJILGA_Dues_Settings::billing_mode_for( $companyId );

        if ( empty( $subs ) ) {
            return [ self::make_candidate( $company, $duesYear, $mode, MyNJILGA_Dues_Snapshot::KIND_COMBINED, $owner, $owner, [], self::EXCLUDED_NO_MEMBERS ) ];
        }
        if ( empty( $company->owner_id ) || $owner['email'] === '' ) {
            // Still price the roster so the card can show what WOULD be
            // billed once an Owner is assigned.
            $priced = MyNJILGA_Pricing_Engine::price( self::roster_entries( $subs, $slugMap ), $config );
            return [ self::make_candidate( $company, $duesYear, $mode, MyNJILGA_Dues_Snapshot::KIND_COMBINED, $owner, $owner, self::flag_join_pending( $priced['members'], $config ), self::EXCLUDED_NO_OWNER ) ];
        }

        $priced  = MyNJILGA_Pricing_Engine::price( self::roster_entries( $subs, $slugMap ), $config );
        $members = self::flag_join_pending( $priced['members'], $config );

        if ( (int) $priced['totals']['total_cents'] <= 0 ) {
            return [ self::make_candidate( $company, $duesYear, $mode, MyNJILGA_Dues_Snapshot::KIND_COMBINED, $owner, $owner, $members, self::EXCLUDED_ZERO_TOTAL ) ];
        }

        switch ( $mode ) {
            case MyNJILGA_Dues_Settings::MODE_INDIVIDUAL:
                return self::merge_same_bill_to( self::individual_candidates( $company, $duesYear, $owner, $members ) );
            case MyNJILGA_Dues_Settings::MODE_SPLIT_ASSESSMENT:
                return self::merge_same_bill_to( self::split_assessment_candidates( $company, $duesYear, $owner, $members ) );
            default:
                return [ self::make_candidate( $company, $duesYear, MyNJILGA_Dues_Settings::MODE_FIRM, MyNJILGA_Dues_Snapshot::KIND_COMBINED, $owner, $owner, $members, 'draft' ) ];
        }
    }

    /**
     * @param array<string,mixed>            $owner
     * @param array<int,array<string,mixed>> $members
     * @return array<int,array<string,mixed>>
     */
    private static function individual_candidates( $company, int $duesYear, array $owner, array $members ): array {
        $billed = []; $free = [];
        foreach ( $members as $m ) {
            if ( ( (int) $m['dues_cents'] + (int) $m['assessment_cents'] ) > 0 ) {
                $billed[] = $m;
            } else {
                $free[] = $m;
            }
        }

        // Where do the $0 members go? The Owner's own invoice if the Owner
        // is billed, else the rank-1 member's — so someone's payment still
        // settles them.
        $carrierIndex = 0;
        foreach ( $billed as $i => $m ) {
            if ( (int) $m['contact_id'] === (int) $owner['contact_id'] ) {
                $carrierIndex = $i;
                break;
            }
        }

        $out = [];
        foreach ( $billed as $i => $m ) {
            $rowMembers = [ $m ];
            if ( $i === $carrierIndex && $free ) {
                $rowMembers = array_merge( $rowMembers, $free );
            }
            $billTo = MyNJILGA_Dues_Snapshot::person( $m );
            $note   = '';
            if ( $billTo['email'] === '' ) {
                $billTo = $owner;
                $note   = 'Member has no email on file — invoice addressed to the Owner.';
            }
            $c = self::make_candidate( $company, $duesYear, MyNJILGA_Dues_Settings::MODE_INDIVIDUAL, MyNJILGA_Dues_Snapshot::KIND_COMBINED, $owner, $billTo, $rowMembers, 'draft' );
            if ( $note ) {
                $c['notes'][] = $note;
            }
            $out[] = $c;
        }
        return $out;
    }

    /**
     * @param array<string,mixed>            $owner
     * @param array<int,array<string,mixed>> $members
     * @return array<int,array<string,mixed>>
     */
    private static function split_assessment_candidates( $company, int $duesYear, array $owner, array $members ): array {
        $duesMembers = [];
        $assessed    = [];
        foreach ( $members as $m ) {
            $d = $m;
            if ( (int) $m['assessment_cents'] > 0 ) {
                $assessed[] = $m;
                $d['assessment_cents'] = 0;
                $d['assessment_label'] = '';
                $d['assessment_note']  = 'billed separately';
            }
            $duesMembers[] = $d;
        }

        $out = [];
        $duesTotal = MyNJILGA_Pricing_Engine::totals( $duesMembers )['total_cents'];
        $out[] = self::make_candidate( $company, $duesYear, MyNJILGA_Dues_Settings::MODE_SPLIT_ASSESSMENT, MyNJILGA_Dues_Snapshot::KIND_DUES, $owner, $owner, $duesMembers, $duesTotal > 0 ? 'draft' : self::EXCLUDED_ZERO_TOTAL );

        foreach ( $assessed as $m ) {
            $a = $m;
            $a['dues_cents']        = 0;
            $a['dues_note']         = 'billed on firm invoice';
            $billTo = MyNJILGA_Dues_Snapshot::person( $m );
            $note   = '';
            if ( $billTo['email'] === '' ) {
                $billTo = $owner;
                $note   = 'Member has no email on file — assessment invoice addressed to the Owner.';
            }
            $c = self::make_candidate( $company, $duesYear, MyNJILGA_Dues_Settings::MODE_SPLIT_ASSESSMENT, MyNJILGA_Dues_Snapshot::KIND_ASSESSMENT, $owner, $billTo, [ $a ], 'draft' );
            if ( $note ) {
                $c['notes'][] = $note;
            }
            $out[] = $c;
        }
        return $out;
    }

    /**
     * The invoice table is unique on (company, year, kind, bill-to). Two
     * candidates that resolve to the same bill-to — the Owner's own
     * individual invoice plus a member with no email who falls back to
     * the Owner, or two such members — would otherwise overwrite each
     * other in upsert_draft(). Merge them into one invoice covering all
     * their members instead, so nobody's dues silently vanish.
     *
     * @param array<int,array<string,mixed>> $candidates
     * @return array<int,array<string,mixed>>
     */
    private static function merge_same_bill_to( array $candidates ): array {
        $byKey = [];
        foreach ( $candidates as $c ) {
            $key = $c['invoice_kind'] . '|' . (int) $c['bill_to']['contact_id'];
            if ( ! isset( $byKey[ $key ] ) ) {
                $byKey[ $key ] = $c;
                continue;
            }
            $merged            = $byKey[ $key ];
            $merged['members'] = array_values( array_merge( $merged['members'], $c['members'] ) );
            $merged['totals']  = MyNJILGA_Pricing_Engine::totals( $merged['members'] );
            $merged['notes']   = array_values( array_unique( array_merge( $merged['notes'], $c['notes'], [ 'Several members share this bill-to contact — their charges are combined on one invoice.' ] ) ) );
            $merged['exceptions']['no_category'] = (int) $merged['exceptions']['no_category'] + (int) $c['exceptions']['no_category'];
            if ( $merged['status'] !== 'draft' && $c['status'] === 'draft' ) {
                $merged['status'] = 'draft';
            }
            $byKey[ $key ] = $merged;
        }
        return array_values( $byKey );
    }

    /**
     * @param array<int,array<string,mixed>> $members
     * @return array<string,mixed>
     */
    private static function make_candidate( $company, int $duesYear, string $mode, string $kind, array $owner, array $billTo, array $members, string $status ): array {
        $noCategory = 0;
        $notes      = [];
        foreach ( $members as $m ) {
            if ( ( $m['unbilled_reason'] ?? '' ) === MyNJILGA_Pricing_Engine::UNBILLED_NO_CATEGORY ) {
                $noCategory++;
            }
            // Only where this invoice bills their dues — a split-assessment
            // member's assessment invoice doesn't.
            if ( ! empty( $m['join_pending'] ) && (int) ( $m['dues_cents'] ?? 0 ) > 0 ) {
                $notes[] = sprintf(
                    '%s is on %s — billed in full here, since that payment may yet cover nobody. If it settles, Refresh Firms re-prices them at $0; creating this invoice before then would bill them twice.',
                    (string) ( $m['name'] ?? '' ) !== '' ? (string) $m['name'] : 'Contact #' . (int) ( $m['contact_id'] ?? 0 ),
                    (string) $m['join_pending']
                );
            }
        }
        return [
            'dues_year'    => $duesYear,
            'company_id'   => (int) $company->id,
            'company_name' => (string) ( $company->name ?? '' ),
            'status'       => $status, // 'draft' | excluded_*
            'billing_mode' => $mode,
            'invoice_kind' => $kind,
            'owner'        => $owner,
            'bill_to'      => $billTo,
            'members'      => array_values( $members ),
            'totals'       => MyNJILGA_Pricing_Engine::totals( $members ),
            'notes'        => $notes,
            'exceptions'   => [ 'no_category' => $noCategory ],
        ];
    }

    /**
     * @param array<string,mixed> $c
     */
    private static function persist_candidate( array $c, bool $livemode ): ?int {
        $isDraft  = $c['status'] === 'draft';
        $snapshot = MyNJILGA_Dues_Snapshot::build(
            (int) $c['dues_year'],
            $c['billing_mode'],
            $c['invoice_kind'],
            [ 'id' => $c['company_id'], 'name' => $c['company_name'] ],
            $c['owner'],
            $c['bill_to'],
            $c['members']
        );
        if ( ! $isDraft ) {
            $snapshot['exclusion_reason'] = $c['status'];
        }
        if ( ! empty( $c['notes'] ) ) {
            $snapshot['notes'] = $c['notes'];
        }

        return MyNJILGA_Dues_Invoice_Table::upsert_draft( [
            'dues_year'                  => (int) $c['dues_year'],
            'fluentcrm_company_id'       => (int) $c['company_id'],
            'fluentcrm_owner_contact_id' => (int) $c['owner']['contact_id'],
            'bill_to_contact_id'         => (int) $c['bill_to']['contact_id'],
            'billing_mode'               => $c['billing_mode'],
            'invoice_kind'               => $c['invoice_kind'],
            'status'                     => $isDraft ? MyNJILGA_Dues_Invoice_Table::STATUS_DRAFT : MyNJILGA_Dues_Invoice_Table::STATUS_EXCLUDED,
            'total_amount_cents'         => (int) $c['totals']['total_cents'],
            'roster_snapshot'            => MyNJILGA_Dues_Snapshot::encode( $snapshot ),
            'livemode'                   => $livemode,
        ] );
    }

    // -------------------------------------------------------------------------
    // FluentCRM → engine input
    // -------------------------------------------------------------------------

    /**
     * Configured slug → FluentCRM tag id, resolved once per run (slug
     * first, exact-title fallback, same rule MyNJILGA_Tags uses). Slugs
     * that don't resolve are dropped — the Setup page's tag audit is where
     * that gets surfaced.
     *
     * @return array<string,int>
     */
    public static function resolve_configured_tags(): array {
        $map = [];
        foreach ( MyNJILGA_Dues_Settings::referenced_tag_slugs() as $slug ) {
            $id = MyNJILGA_Tags::resolve_slug( $slug );
            if ( $id ) {
                $map[ $slug ] = $id;
            }
        }
        return $map;
    }

    /**
     * @param array<int,\FluentCrm\App\Models\Subscriber> $subs
     * @param array<string,int>                           $slugMap
     * @return array<int,array<string,mixed>>
     */
    private static function roster_entries( array $subs, array $slugMap ): array {
        $out = [];
        foreach ( $subs as $sub ) {
            $out[] = self::roster_entry( $sub, $slugMap );
        }
        return $out;
    }

    /**
     * One engine roster entry. The contact's tags are expressed as
     * CANONICAL configured slugs: a configured slug is present when the
     * contact carries the tag it resolved to (so a legacy tag whose slug
     * differs from its title still matches), plus the contact's raw slugs
     * as-is.
     *
     * @param \FluentCrm\App\Models\Subscriber $sub
     * @param array<string,int>                $slugMap
     * @return array<string,mixed>
     */
    public static function roster_entry( $sub, array $slugMap ): array {
        $tagIds = []; $slugs = [];
        foreach ( $sub->tags ?? [] as $tag ) {
            $tagIds[] = (int) $tag->id;
            if ( ! empty( $tag->slug ) ) {
                $slugs[] = (string) $tag->slug;
            }
        }
        foreach ( $slugMap as $slug => $id ) {
            if ( in_array( (int) $id, $tagIds, true ) ) {
                $slugs[] = $slug;
            }
        }

        return [
            'contact_id' => (int) $sub->id,
            'first_name' => (string) ( $sub->first_name ?? '' ),
            'last_name'  => (string) ( $sub->last_name ?? '' ),
            'name'       => MyNJILGA_Members_Data::display_name( $sub ),
            'email'      => (string) ( $sub->email ?? '' ),
            'tags'       => array_values( array_unique( $slugs ) ),
        ];
    }

    /**
     * @return array<int,\FluentCrm\App\Models\Subscriber>
     */
    private static function subscribers_of( $company ): array {
        $subs = $company->subscribers ?? [];
        if ( is_array( $subs ) ) {
            return array_values( $subs );
        }
        if ( is_object( $subs ) && method_exists( $subs, 'all' ) ) {
            return $subs->all();
        }
        return iterator_to_array( $subs );
    }

    /**
     * @param \FluentCrm\App\Models\Subscriber|null $contact
     * @return array{contact_id:int,name:string,first_name:string,last_name:string,email:string}
     */
    private static function person_from_contact( $contact, int $fallbackId ): array {
        if ( ! $contact ) {
            return MyNJILGA_Dues_Snapshot::person( [ 'contact_id' => $fallbackId ] );
        }
        return MyNJILGA_Dues_Snapshot::person( [
            'contact_id' => (int) $contact->id,
            'name'       => MyNJILGA_Members_Data::display_name( $contact ),
            'first_name' => (string) ( $contact->first_name ?? '' ),
            'last_name'  => (string) ( $contact->last_name ?? '' ),
            'email'      => (string) ( $contact->email ?? '' ),
        ] );
    }
}
