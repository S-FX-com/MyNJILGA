<?php
/**
 * The shared membership statistics (MyNJILGA_Membership_Stats) and the
 * report data reshaped from them (MyNJILGA_Members_Data's pure half).
 *
 * Everything here is the PURE half: hand-built contacts and firms go in,
 * figures and rows come out, and no WordPress or FluentCRM is needed. The
 * fixture in fixture() is small enough to count by hand — the expected
 * numbers in the tests are the hand count, worked out in that method's
 * comment — and it deliberately contains every awkward population: a stale
 * evergreen tag, paid-ahead, evergreen-only, both evergreen tags, exempt with
 * and without a paid year, inactive, a contact on no firm, a contact on two,
 * an empty firm, a newsletter subscriber who is no member at all, and
 * colleagues whose FluentCRM status is not 'subscribed'.
 *
 * The last test is the one this whole change exists for: the tiles and the
 * lists are derived from the same data, so they must agree number for number.
 */
declare( strict_types=1 );

require_once dirname( __DIR__ ) . '/includes/class-tags.php';
require_once dirname( __DIR__ ) . '/includes/class-my-membership.php';
require_once dirname( __DIR__ ) . '/includes/class-membership-stats.php';
require_once dirname( __DIR__ ) . '/includes/class-members-data.php';

class MembershipStatsTest extends NJILGA_TestCase {

    private const PATTERN = 'Dues Paid {year}';

    // -------------------------------------------------------------------
    // Builders
    // -------------------------------------------------------------------

    /** @return array<string,mixed> */
    private function cfg( int $year = 2026, string $default = 'professional' ): array {
        $d = MyNJILGA_Dues_Settings::defaults();
        return [ 'year' => $year, 'categories' => $d['categories'], 'default_category' => $default ];
    }

    /**
     * A contact fact. $o overrides any default; status defaults to
     * 'subscribed' and the display fields to something recognisable.
     *
     * @param array<string,mixed> $o
     * @return array<string,mixed>
     */
    private function c( int $id, array $o = [] ): array {
        return $o + [
            'id'         => $id,
            'status'     => 'subscribed',
            'first_name' => 'F' . $id,
            'last_name'  => 'L' . $id,
            'email'      => 'c' . $id . '@example.test',
            'name'       => 'F' . $id . ' L' . $id,
        ] + MyNJILGA_Membership_Stats::blank_contact( $id );
    }

    /**
     * @param array<int,int> $ids
     * @return array<string,mixed>
     */
    private function firm( int $id, array $ids, int $owner = 1, string $name = '' ): array {
        return [ 'id' => $id, 'name' => $name !== '' ? $name : 'Firm ' . $id, 'owner_id' => $owner, 'owner_name' => '', 'contact_ids' => $ids ];
    }

    private function state( array $contact, int $year = 2026 ): string {
        return MyNJILGA_Membership_Stats::classify( $contact, $this->cfg( $year ) )['state'];
    }

    /**
     * The hand-countable fixture (year 2026). Contacts, and what each is:
     *
     *    1  paid 2026 + evergreen; professional tag          active, dated      firm 10
     *    2  paid 2026+2027 + evergreen; Trustees tag         active, paid ahead firms 10 and 14 (primary 14)
     *    3  paid 2025 + evergreen + unpaid tag; no category  EXPIRED (stale evergreen, both tags), defaults to professional  firm 11
     *    4  evergreen only; no category                      active, ASSUMED    firm 11
     *    5  Senior Trustee, no dues; senior-trustee category EXEMPT             firms 12 and 16
     *    6  Past President, paid 2026 + evergreen, status unsubscribed          active (paid wins), exempt tag   firm 12
     *    7  paid 2026 + evergreen; law-student               active             NO firm
     *    8  paid 2026, NO evergreen; status transactional    active             firm 10
     *    9  paid 2025 + unpaid tag; emerging-professional    expired            firm 13
     *   10  professional tag only                            none (roster only) firm 13
     *   11  paid 2026 + evergreen; professional              active             NO firm
     *   12  newsletter subscriber, no tags                   not a member
     *   13  professional tag only, on no roster              not a member
     *   14  paid 2025 + Inactive tag                         inactive           NO firm
     *   15  Trustees tag, no dues                            none (trustee "other") NO firm
     *   16  Trustees + Officer, paid 2025 + unpaid tag       expired trustee, officer  NO firm
     *   17  Officer, paid 2026 + evergreen                   active, officer    firm 14
     *
     * Firms: 10 [1,2,8] · 11 [3,4] no owner · 12 [5,6] · 13 [9,10] ·
     * 14 [17,2] · 15 [] (empty, no owner) · 16 [5].
     *
     * Hand count for 2026: universe 15 = active 8 (dated 7, assumed 1) +
     * expired 3 + exempt 1 + inactive 1 + none 2. Firms: 6 with contacts,
     * 4 with an active member (sizes 1:{11,12} 2-5:{10,14}), 2 without
     * ({13}, and {16} which is exempt-only), 1 empty, 1 with no owner.
     *
     * @return array{contacts:array<int,array<string,mixed>>,firms:array<int,array<string,mixed>>}
     */
    private function fixture(): array {
        $list = [
            $this->c( 1, [ 'paid_years' => [ 2026 ], 'paid_tag' => true, 'category_tags' => [ 'professional' ] ] ),
            $this->c( 2, [ 'paid_years' => [ 2027, 2026 ], 'paid_tag' => true, 'category_tags' => [ 'professional' ], 'role_tags' => [ 'trustees' ], 'primary_firm_id' => 14 ] ),
            $this->c( 3, [ 'paid_years' => [ 2025 ], 'paid_tag' => true, 'unpaid_tag' => true ] ),
            $this->c( 4, [ 'paid_tag' => true ] ),
            $this->c( 5, [ 'role_tags' => [ 'senior-trustee' ], 'category_tags' => [ 'senior-trustee' ] ] ),
            $this->c( 6, [ 'status' => 'unsubscribed', 'paid_years' => [ 2026 ], 'paid_tag' => true, 'role_tags' => [ 'past-president' ], 'category_tags' => [ 'past-president' ] ] ),
            $this->c( 7, [ 'paid_years' => [ 2026 ], 'paid_tag' => true, 'category_tags' => [ 'law-student' ] ] ),
            $this->c( 8, [ 'status' => 'transactional', 'paid_years' => [ 2026 ], 'category_tags' => [ 'professional' ] ] ),
            $this->c( 9, [ 'paid_years' => [ 2025 ], 'unpaid_tag' => true, 'category_tags' => [ 'emerging-professional' ] ] ),
            $this->c( 10, [ 'category_tags' => [ 'professional' ] ] ),
            $this->c( 11, [ 'paid_years' => [ 2026 ], 'paid_tag' => true, 'category_tags' => [ 'professional' ] ] ),
            $this->c( 12 ),
            $this->c( 13, [ 'category_tags' => [ 'professional' ] ] ),
            $this->c( 14, [ 'paid_years' => [ 2025 ], 'inactive' => true ] ),
            $this->c( 15, [ 'role_tags' => [ 'trustees' ] ] ),
            $this->c( 16, [ 'paid_years' => [ 2025 ], 'unpaid_tag' => true, 'role_tags' => [ 'trustees', 'officer' ] ] ),
            $this->c( 17, [ 'paid_years' => [ 2026 ], 'paid_tag' => true, 'role_tags' => [ 'officer' ] ] ),
        ];
        $contacts = [];
        foreach ( $list as $c ) {
            $contacts[ $c['id'] ] = $c;
        }
        return [
            'contacts' => $contacts,
            'firms'    => [
                $this->firm( 10, [ 1, 2, 8 ], 1, 'Alpha LLP' ),
                $this->firm( 11, [ 3, 4 ], 0, 'Bravo LLP' ),
                $this->firm( 12, [ 5, 6 ], 5, 'Charlie LLP' ),
                $this->firm( 13, [ 9, 10 ], 9, 'Delta LLP' ),
                $this->firm( 14, [ 17, 2 ], 17, 'Zeta LLP' ),
                $this->firm( 15, [], 0, 'Echo LLP' ),
                $this->firm( 16, [ 5 ], 5, 'Foxtrot LLP' ),
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function aggregate_fixture( int $year = 2026 ): array {
        $f = $this->fixture();
        return MyNJILGA_Membership_Stats::aggregate( $f['contacts'], $f['firms'], $this->cfg( $year ) );
    }

    /** @return array<string,mixed> assemble()'s output for the fixture */
    private function data_fixture( int $year = 2026 ): array {
        $f = $this->fixture();
        return MyNJILGA_Membership_Stats::assemble( [ 'cfg' => $this->cfg( $year ), 'warnings' => [], 'contacts' => $f['contacts'], 'firms' => $f['firms'] ] );
    }

    private function url(): callable {
        return static function ( int $id ): string {
            return 'admin/contact/' . $id;
        };
    }

    private function fallback(): callable {
        return static function ( array $c ): string {
            return 'custom:' . $c['id'];
        };
    }

    // -------------------------------------------------------------------
    // Standing of one contact (classify) — the populations
    // -------------------------------------------------------------------

    public function test_paid_this_year_is_active_and_dated(): void {
        $k = MyNJILGA_Membership_Stats::classify( $this->c( 1, [ 'paid_years' => [ 2026 ], 'paid_tag' => true ] ), $this->cfg() );
        $this->assertSame( 'active', $k['state'] );
        $this->assertSame( 2026, $k['through'] );
        $this->assertFalse( $k['assumed'] );
    }

    public function test_paid_ahead_is_active_through_the_later_year(): void {
        $k = MyNJILGA_Membership_Stats::classify( $this->c( 1, [ 'paid_years' => [ 2027, 2026 ], 'paid_tag' => true ] ), $this->cfg() );
        $this->assertSame( 'active', $k['state'] );
        $this->assertSame( 2027, $k['through'] );
    }

    public function test_paid_ahead_only_with_no_current_year_tag_is_still_active(): void {
        $k = MyNJILGA_Membership_Stats::classify( $this->c( 1, [ 'paid_years' => [ 2027 ] ] ), $this->cfg() );
        $this->assertSame( 'active', $k['state'] );
        $this->assertSame( 2027, $k['through'] );
    }

    public function test_a_lapsed_year_with_a_lingering_evergreen_tag_is_expired(): void {
        // The whole point of the change: the old reports called this person Paid.
        $k = MyNJILGA_Membership_Stats::classify( $this->c( 1, [ 'paid_years' => [ 2025 ], 'paid_tag' => true ] ), $this->cfg() );
        $this->assertSame( 'expired', $k['state'] );
        $this->assertSame( 2025, $k['through'] );
    }

    public function test_evergreen_tag_alone_is_active_assumed_through_this_year(): void {
        foreach ( [ 2026, 2031 ] as $year ) {
            $k = MyNJILGA_Membership_Stats::classify( $this->c( 1, [ 'paid_tag' => true ] ), $this->cfg( $year ) );
            $this->assertSame( 'active', $k['state'] );
            $this->assertTrue( $k['assumed'] );
            $this->assertSame( $year, $k['through'], 'an assumed member is paid through whatever this year is' );
        }
    }

    public function test_both_evergreen_tags_paid_wins(): void {
        $this->assertSame( 'active', $this->state( $this->c( 1, [ 'paid_tag' => true, 'unpaid_tag' => true ] ) ) );
        $this->assertSame( 'active', $this->state( $this->c( 1, [ 'paid_years' => [ 2026 ], 'paid_tag' => true, 'unpaid_tag' => true ] ) ) );
    }

    public function test_unpaid_tag_alone_is_expired(): void {
        $k = MyNJILGA_Membership_Stats::classify( $this->c( 1, [ 'unpaid_tag' => true ] ), $this->cfg() );
        $this->assertSame( 'expired', $k['state'] );
        $this->assertSame( null, $k['through'] );
    }

    public function test_exempt_with_and_without_a_paid_year(): void {
        $pp = [ 'role_tags' => [ 'past-president' ] ];
        $this->assertSame( 'exempt', $this->state( $this->c( 1, $pp ) ), 'no payment on record' );
        $this->assertSame( 'active', $this->state( $this->c( 1, $pp + [ 'paid_years' => [ 2026 ] ] ) ), 'a firm invoice stamped them: paid wins' );
        $this->assertSame( 'exempt', $this->state( $this->c( 1, $pp + [ 'paid_years' => [ 2025 ], 'paid_tag' => true ] ) ), 'a lapsed year does not make an exempt member expired' );
        $this->assertSame( 'exempt', $this->state( $this->c( 1, [ 'role_tags' => [ 'senior-trustee' ] ] ) ), 'Senior Trustee is exempt too' );
        $this->assertSame( 'none', $this->state( $this->c( 1, [ 'role_tags' => [ 'trustees' ] ] ) ), 'a plain Trustee is not exempt' );
        $this->assertSame( 'none', $this->state( $this->c( 1, [ 'role_tags' => [ 'officer' ] ] ) ), 'nor is an Officer' );
    }

    public function test_inactive_and_none(): void {
        $this->assertSame( 'inactive', $this->state( $this->c( 1, [ 'inactive' => true, 'paid_years' => [ 2025 ] ] ) ) );
        $this->assertSame( 'active', $this->state( $this->c( 1, [ 'inactive' => true, 'paid_years' => [ 2026 ] ] ) ), 'payment beats the inactive tag' );
        $this->assertSame( 'none', $this->state( $this->c( 1 ) ) );
    }

    public function test_classify_hands_standing_the_facts_summarize_would_build(): void {
        // classify() must add no rule of its own: the state is exactly
        // standing() over these five facts, for every combination.
        $bools = [ false, true ];
        foreach ( [ [], [ 2025 ], [ 2026 ], [ 2027, 2026 ] ] as $years ) {
            foreach ( $bools as $paid ) {
                foreach ( $bools as $unpaid ) {
                    foreach ( $bools as $exempt ) {
                        foreach ( $bools as $inactive ) {
                            $want = MyNJILGA_My_Membership::standing( [ 'paid_years' => $years, 'paid_tag' => $paid, 'unpaid_tag' => $unpaid, 'exempt' => $exempt, 'inactive' => $inactive ], 2026 );
                            $got  = MyNJILGA_Membership_Stats::classify( $this->c( 1, [
                                'paid_years' => $years, 'paid_tag' => $paid, 'unpaid_tag' => $unpaid, 'inactive' => $inactive,
                                'role_tags'  => $exempt ? [ 'past-president' ] : [],
                            ] ), $this->cfg() );
                            $this->assertSame( $want, [ 'state' => $got['state'], 'through' => $got['through'], 'assumed' => $got['assumed'] ], json_encode( [ $years, $paid, $unpaid, $exempt, $inactive ] ) );
                        }
                    }
                }
            }
        }
    }

    public function test_year_rollover_flips_last_years_members_but_not_paid_ahead_or_assumed(): void {
        $c2026 = $this->c( 1, [ 'paid_years' => [ 2026 ], 'paid_tag' => true ] );
        $ahead = $this->c( 2, [ 'paid_years' => [ 2027, 2026 ], 'paid_tag' => true ] );
        $old   = $this->c( 3, [ 'paid_tag' => true ] );
        $this->assertSame( 'active', $this->state( $c2026, 2026 ) );
        $this->assertSame( 'expired', $this->state( $c2026, 2027 ), 'paid through 2026 is expired on 2027' );
        $this->assertSame( 'active', $this->state( $ahead, 2027 ) );
        $this->assertSame( 'expired', $this->state( $ahead, 2028 ) );
        $this->assertSame( 'active', $this->state( $old, 2027 ), 'an evergreen-only member has no date to lapse' );
    }

    public function test_year_rollover_moves_every_figure_of_the_fixture(): void {
        $y26 = $this->aggregate_fixture( 2026 );
        $y27 = $this->aggregate_fixture( 2027 );
        $this->assertSame( 2027, $y27['year'] );
        $this->assertSame( 2028, $y27['next_year'] );

        // Only 2 (paid through 2027) and 4 (evergreen, assumed) stay active. 6 is a
        // Past President whose 2026 payment no longer counts: exempt, not active.
        $this->assertSame( 2, $y27['members']['active'] );
        $this->assertSame( 1, $y27['members']['dated'] );
        $this->assertSame( 1, $y27['members']['assumed'] );
        $this->assertSame( 8, $y27['members']['expired'] );
        $this->assertSame( 2, $y27['members']['exempt'] );
        $this->assertSame( 0, $y27['members']['paid_ahead'] );
        $this->assertSame( 1, $y27['members']['renewing'] );
        $this->assertSame( 15, array_sum( array_map( static function ( $s ) use ( $y27 ) { return $y27['members'][ $s ]; }, [ 'active', 'expired', 'exempt', 'inactive', 'none' ] ) ) );

        // Firms follow their people. 10, 11 and 14 each still have an active member (2 or 4). Charlie LLP
        // held one only through 6's 2026 payment; now it is two exempt contacts, so it joins Foxtrot as exempt-only.
        $this->assertSame( 4, $y26['firms']['with_active'] );
        $this->assertSame( 3, $y27['firms']['with_active'] );
        $this->assertSame( 3, $y27['firms']['without_active'] );
        $this->assertSame( 2, $y27['firms']['exempt_only'] );
        $this->assertSame( [ 1 => 3, '2-5' => 0, '6+' => 0 ], $y27['firms']['size'] );

        // Same people, one year earlier: the 2026 numbers are untouched.
        $this->assertSame( 8, $y26['members']['active'] );
        $this->assertSame( 1, $y26['firms']['exempt_only'] );
    }

    // -------------------------------------------------------------------
    // Category
    // -------------------------------------------------------------------

    public function test_category_precedence_is_settings_order_not_tag_order(): void {
        $cfg = $this->cfg();
        $cat = static function ( array $tags ) use ( $cfg ) {
            return MyNJILGA_Membership_Stats::classify( [ 'category_tags' => $tags ], $cfg )['category'];
        };
        $this->assertSame( 'law_student', $cat( [ 'professional', 'law-student' ] ) );
        $this->assertSame( 'law_student', $cat( [ 'law-student', 'professional' ] ) );
        $this->assertSame( 'senior_trustee', $cat( [ 'professional', 'senior-trustee' ] ) );
        $this->assertSame( 'past_president', $cat( [ 'senior-trustee', 'past-president' ] ) );
    }

    public function test_category_defaults_when_no_category_tag_is_held(): void {
        $none = MyNJILGA_Membership_Stats::classify( [], $this->cfg() );
        $this->assertSame( 'professional', $none['category'] );
        $this->assertTrue( $none['defaulted'] );

        $held = MyNJILGA_Membership_Stats::classify( [ 'category_tags' => [ 'professional' ] ], $this->cfg() );
        $this->assertSame( 'professional', $held['category'] );
        $this->assertFalse( $held['defaulted'], 'holding the tag is not defaulting' );

        $blank = MyNJILGA_Membership_Stats::classify( [], $this->cfg( 2026, '' ) );
        $this->assertSame( null, $blank['category'], 'no default configured: not billed, no category' );
        $this->assertFalse( $blank['defaulted'] );
    }

    // -------------------------------------------------------------------
    // The member universe
    // -------------------------------------------------------------------

    public function test_universe_is_dues_signals_plus_roster_and_a_newsletter_contact_is_never_counted(): void {
        $a = $this->aggregate_fixture();
        $m = $a['members'];
        // 17 contacts are on file; 12 (nothing at all) and 13 (a category tag, on no roster) are no members.
        $this->assertSame( 15, $m['active'] + $m['expired'] + $m['exempt'] + $m['inactive'] + $m['none'] );

        $only = MyNJILGA_Membership_Stats::aggregate( [ $this->c( 12 ), $this->c( 13, [ 'category_tags' => [ 'professional' ], 'role_tags' => [ 'officer' ] ] ) ], [], $this->cfg() );
        $this->assertSame( 0, $only['members']['none'] );
        $this->assertSame( 0, $only['trustees']['officers'], 'an Officer tag alone does not make a member' );
        $this->assertSame( 0, $only['no_firm']['active'] );
    }

    public function test_has_dues_signal_for_each_signal_and_for_none(): void {
        $has = static function ( array $o ) {
            return MyNJILGA_Membership_Stats::has_dues_signal( $o );
        };
        $this->assertTrue( $has( [ 'paid_tag' => true ] ) );
        $this->assertTrue( $has( [ 'unpaid_tag' => true ] ) );
        $this->assertTrue( $has( [ 'inactive' => true ] ) );
        $this->assertTrue( $has( [ 'paid_years' => [ 2019 ] ] ) );
        $this->assertTrue( $has( [ 'role_tags' => [ 'past-president' ] ] ) );
        $this->assertTrue( $has( [ 'role_tags' => [ 'senior-trustee' ] ] ) );
        $this->assertTrue( $has( [ 'role_tags' => [ 'trustees' ] ] ) );
        $this->assertFalse( $has( [] ) );
        $this->assertFalse( $has( [ 'role_tags' => [ 'officer', 'paid-by-check', 'paid-by-invoice' ], 'category_tags' => [ 'professional' ] ] ) );
    }

    public function test_a_roster_contact_with_no_dues_signal_is_none_not_active_or_expired(): void {
        $a = MyNJILGA_Membership_Stats::aggregate( [ $this->c( 1 ) ], [ $this->firm( 10, [ 1 ] ) ], $this->cfg() );
        $this->assertSame( 1, $a['members']['none'] );
        $this->assertSame( 0, $a['members']['active'] + $a['members']['expired'] );
        $this->assertSame( 1, $a['firms']['total'] );
        $this->assertSame( 1, $a['firms']['without_active'] );
    }

    public function test_a_roster_id_with_no_fact_at_all_still_counts_as_a_none_member(): void {
        $a = MyNJILGA_Membership_Stats::aggregate( [], [ $this->firm( 10, [ 77 ] ) ], $this->cfg() );
        $this->assertSame( 1, $a['members']['none'] );
        $this->assertSame( 1, $a['firms']['without_active'] );
    }

    public function test_marketing_status_is_never_a_filter(): void {
        $statuses = [ 'subscribed', 'pending', 'transactional', 'unsubscribed', 'bounced', 'complained', '' ];
        $contacts = [];
        foreach ( $statuses as $i => $status ) {
            $contacts[] = $this->c( $i + 1, [ 'status' => $status, 'paid_years' => [ 2026 ], 'paid_tag' => true ] );
        }
        $a = MyNJILGA_Membership_Stats::aggregate( $contacts, [], $this->cfg() );
        $this->assertSame( 7, $a['members']['active'] );
        $this->assertSame( 6, $a['members']['not_emailable'], 'everything but subscribed cannot be emailed' );
    }

    public function test_not_emailable_counts_only_active_members_who_are_not_subscribed(): void {
        $a = $this->aggregate_fixture();
        // 6 (unsubscribed, active) and 8 (transactional, active). Nobody else in the fixture is unsubscribed.
        $this->assertSame( 2, $a['members']['not_emailable'] );

        $expired = MyNJILGA_Membership_Stats::aggregate( [ $this->c( 1, [ 'status' => 'unsubscribed', 'paid_years' => [ 2020 ] ] ) ], [], $this->cfg() );
        $this->assertSame( 0, $expired['members']['not_emailable'], 'an expired member is not an active one who cannot be emailed' );
    }

    // -------------------------------------------------------------------
    // Members block
    // -------------------------------------------------------------------

    public function test_members_states_dated_assumed_and_the_through_histogram(): void {
        $m = $this->aggregate_fixture()['members'];
        $this->assertSame( 8, $m['active'] );
        $this->assertSame( 7, $m['dated'] );
        $this->assertSame( 1, $m['assumed'] );
        $this->assertSame( 3, $m['expired'] );
        $this->assertSame( 1, $m['exempt'] );
        $this->assertSame( 1, $m['inactive'] );
        $this->assertSame( 2, $m['none'] );
        $this->assertSame( $m['active'], $m['dated'] + $m['assumed'] );
        // 6 dated 2026 + the assumed one (through = this year) = 7; contact 2 is paid through 2027. Oldest year first.
        $this->assertSame( [ 2026 => 7, 2027 => 1 ], $m['through'] );
    }

    public function test_paid_ahead_and_renewing(): void {
        $m = $this->aggregate_fixture()['members'];
        $this->assertSame( 1, $m['paid_ahead'], 'contact 2 is paid through 2027' );
        $this->assertSame( 6, $m['renewing'], 'dated, paid through exactly 2026: 1, 6, 7, 8, 11, 17 — the assumed member has no end date to renew' );
    }

    // -------------------------------------------------------------------
    // Categories
    // -------------------------------------------------------------------

    public function test_categories_count_active_expired_and_defaulted_in_settings_order(): void {
        $c = $this->aggregate_fixture()['categories'];
        $this->assertSame( [ 'past_president', 'senior_trustee', 'law_student', 'emerging_professional', 'professional' ], array_keys( $c ) );
        $this->assertSame( 'Professional Membership', $c['professional']['label'] );
        // Professional: active 1, 2, 4, 8, 11, 17; expired 3, 16. 3, 4, 16, 17 hold no category tag.
        $this->assertSame( [ 'label' => 'Professional Membership', 'active' => 6, 'expired' => 2, 'defaulted' => 4 ], $c['professional'] );
        $this->assertSame( 1, $c['past_president']['active'], 'contact 6: exempt tag but paid, so active' );
        $this->assertSame( 0, $c['senior_trustee']['active'] + $c['senior_trustee']['expired'], 'contact 5 is exempt: neither' );
        $this->assertSame( 1, $c['law_student']['active'] );
        $this->assertSame( 1, $c['emerging_professional']['expired'] );
        $this->assertSame( 0, $c['law_student']['defaulted'] );
    }

    // -------------------------------------------------------------------
    // Firms
    // -------------------------------------------------------------------

    public function test_a_contact_on_two_rosters_counts_once_in_members_and_once_in_each_firm(): void {
        $a = MyNJILGA_Membership_Stats::aggregate(
            [ $this->c( 1, [ 'paid_years' => [ 2026 ] ] ) ],
            [ $this->firm( 10, [ 1 ] ), $this->firm( 11, [ 1 ] ) ],
            $this->cfg()
        );
        $this->assertSame( 1, $a['members']['active'] );
        $this->assertSame( 2, $a['firms']['total'] );
        $this->assertSame( 2, $a['firms']['with_active'] );
        $this->assertSame( 0, $a['no_firm']['active'] );
    }

    public function test_firm_figures_of_the_fixture(): void {
        $f = $this->aggregate_fixture()['firms'];
        $this->assertSame( 6, $f['total'] );
        $this->assertSame( 4, $f['with_active'] );
        $this->assertSame( 2, $f['without_active'] );
        $this->assertSame( 1, $f['exempt_only'] );
        $this->assertSame( 1, $f['empty'] );
        $this->assertSame( 1, $f['no_owner'] );
        $this->assertSame( [ 1 => 2, '2-5' => 2, '6+' => 0 ], $f['size'] );
        $this->assertSame( $f['total'], $f['with_active'] + $f['without_active'] );
    }

    public function test_an_empty_firm_is_reported_apart_and_never_mixed_into_without_active(): void {
        $a = MyNJILGA_Membership_Stats::aggregate( [], [ $this->firm( 10, [], 0 ) ], $this->cfg() );
        $this->assertSame( 1, $a['firms']['empty'] );
        $this->assertSame( 0, $a['firms']['total'] );
        $this->assertSame( 0, $a['firms']['without_active'] );
        $this->assertSame( 0, $a['firms']['no_owner'], 'an empty firm is not a "firm with no owner" either' );
    }

    public function test_exempt_only_firm_needs_every_contact_exempt_and_none_active(): void {
        $pp   = [ 'role_tags' => [ 'past-president' ] ];
        $cfg  = $this->cfg();
        $only = MyNJILGA_Membership_Stats::aggregate( [ $this->c( 1, $pp ), $this->c( 2, [ 'role_tags' => [ 'senior-trustee' ] ] ) ], [ $this->firm( 10, [ 1, 2 ] ) ], $cfg );
        $this->assertSame( 1, $only['firms']['exempt_only'] );
        $this->assertSame( 1, $only['firms']['without_active'] );

        $mixed = MyNJILGA_Membership_Stats::aggregate( [ $this->c( 1, $pp ), $this->c( 2, [ 'paid_years' => [ 2020 ] ] ) ], [ $this->firm( 10, [ 1, 2 ] ) ], $cfg );
        $this->assertSame( 0, $mixed['firms']['exempt_only'], 'an expired member alongside the exempt one' );
        $this->assertSame( 1, $mixed['firms']['without_active'] );

        $noSignal = MyNJILGA_Membership_Stats::aggregate( [ $this->c( 1, $pp ), $this->c( 2 ) ], [ $this->firm( 10, [ 1, 2 ] ) ], $cfg );
        $this->assertSame( 0, $noSignal['firms']['exempt_only'], 'a roster contact with no dues signal is not exempt' );

        $active = MyNJILGA_Membership_Stats::aggregate( [ $this->c( 1, $pp + [ 'paid_years' => [ 2026 ] ] ) ], [ $this->firm( 10, [ 1 ] ) ], $cfg );
        $this->assertSame( 0, $active['firms']['exempt_only'], 'an exempt member paid by a firm invoice is active, and the firm has an active member' );
        $this->assertSame( 1, $active['firms']['with_active'] );
    }

    public function test_firm_size_buckets_count_active_contacts_at_their_boundaries(): void {
        $this->assertSame( '0', MyNJILGA_Membership_Stats::size_bucket( 0 ) );
        $this->assertSame( '1', MyNJILGA_Membership_Stats::size_bucket( 1 ) );
        $this->assertSame( '2-5', MyNJILGA_Membership_Stats::size_bucket( 2 ) );
        $this->assertSame( '2-5', MyNJILGA_Membership_Stats::size_bucket( 5 ) );
        $this->assertSame( '6+', MyNJILGA_Membership_Stats::size_bucket( 6 ) );
        $this->assertSame( '6+', MyNJILGA_Membership_Stats::size_bucket( 40 ) );

        // A firm of 7 contacts of whom only 5 are active is a 2-5 firm; the expired ones do not count.
        $contacts = [];
        $ids      = [];
        for ( $i = 1; $i <= 7; $i++ ) {
            $ids[]      = $i;
            $contacts[] = $this->c( $i, [ 'paid_years' => [ $i <= 5 ? 2026 : 2020 ] ] );
        }
        $a = MyNJILGA_Membership_Stats::aggregate( $contacts, [ $this->firm( 10, $ids ) ], $this->cfg() );
        $this->assertSame( [ 1 => 0, '2-5' => 1, '6+' => 0 ], $a['firms']['size'] );
    }

    public function test_firm_tally_treats_an_unknown_contact_as_none(): void {
        $t = MyNJILGA_Membership_Stats::firm_tally( [ 1 => 'active', 2 => 'exempt', 3 => 'expired' ], [ 1, 2, 3, 4 ] );
        $this->assertSame( [ 'total' => 4, 'active' => 1, 'exempt' => 1 ], $t );
    }

    // -------------------------------------------------------------------
    // No firm
    // -------------------------------------------------------------------

    public function test_active_members_on_no_roster_are_split_by_category(): void {
        $n = $this->aggregate_fixture()['no_firm'];
        // 7 (law student) and 11 (professional) are the active firmless ones; 14, 15, 16 are firmless but not active.
        $this->assertSame( 2, $n['active'] );
        $this->assertSame( [ 'past_president' => 0, 'senior_trustee' => 0, 'law_student' => 1, 'emerging_professional' => 0, 'professional' => 1 ], $n['by_category'] );

        $none = MyNJILGA_Membership_Stats::aggregate( [ $this->c( 1, [ 'paid_years' => [ 2026 ] ] ) ], [], $this->cfg( 2026, '' ) );
        $this->assertSame( 1, $none['no_firm']['by_category'][''], 'a category that resolves to nothing is kept under an empty key' );
    }

    // -------------------------------------------------------------------
    // Trustees
    // -------------------------------------------------------------------

    public function test_trustee_tiles_partition_the_trustee_family(): void {
        $t = $this->aggregate_fixture()['trustees'];
        // Family: 2 (Trustee, active), 5 (Senior, exempt), 6 (Past President, paid), 15 (Trustee, no dues), 16 (Trustee, expired).
        $this->assertSame( 5, $t['total'] );
        $this->assertSame( 3, $t['trustees'] );
        $this->assertSame( 1, $t['senior_trustees'] );
        $this->assertSame( 1, $t['past_presidents'] );
        $this->assertSame( 2, $t['exempt'], 'tag count: 6 is exempt although paid' );
        $this->assertSame( 1, $t['active'], 'non-exempt only: 6 is not counted here' );
        $this->assertSame( 1, $t['expired'] );
        $this->assertSame( 1, $t['other'] );
        $this->assertSame( $t['total'], $t['exempt'] + $t['active'] + $t['expired'] + $t['other'], 'the four tiles partition the family' );
        $this->assertSame( $t['total'], $t['trustees'] + $t['senior_trustees'] + $t['past_presidents'], 'and so do the three roles' );
        $this->assertSame( 2, $t['officers'], 'officers are counted apart: 16 also a Trustee, 17 not' );
    }

    public function test_trustee_role_priority_and_the_drift_guards_behind_it(): void {
        $this->assertSame( 'Past President', MyNJILGA_Membership_Stats::trustee_role( [ 'trustees', 'senior-trustee', 'past-president' ] ) );
        $this->assertSame( 'Senior Trustee', MyNJILGA_Membership_Stats::trustee_role( [ 'trustees', 'senior-trustee' ] ) );
        $this->assertSame( 'Trustee', MyNJILGA_Membership_Stats::trustee_role( [ 'trustees' ] ) );
        $this->assertSame( '', MyNJILGA_Membership_Stats::trustee_role( [ 'officer' ] ) );

        // The provider reads the priority off the order of TRUSTEE_SLUGS and the exempt set off
        // EXEMPT_SLUGS: if either list is ever reordered or widened, this says so.
        $this->assertSame( [ 'past-president', 'senior-trustee', 'trustees' ], MyNJILGA_Tags::TRUSTEE_SLUGS );
        $this->assertSame( [ 'past-president', 'senior-trustee' ], MyNJILGA_Tags::EXEMPT_SLUGS );
        $this->assertSame( MyNJILGA_Tags::TRUSTEE_SLUGS, array_keys( MyNJILGA_Membership_Stats::ROLE_LABELS ) );
        foreach ( MyNJILGA_Tags::EXEMPT_SLUGS as $slug ) {
            $this->assertTrue( in_array( $slug, MyNJILGA_Tags::TRUSTEE_SLUGS, true ), $slug . ' must be trustee-family' );
        }
    }

    public function test_past_president_who_also_holds_the_trustees_tag_is_one_past_president(): void {
        $a = MyNJILGA_Membership_Stats::aggregate( [ $this->c( 1, [ 'role_tags' => [ 'trustees', 'past-president' ] ] ) ], [], $this->cfg() );
        $this->assertSame( 1, $a['trustees']['total'] );
        $this->assertSame( 1, $a['trustees']['past_presidents'] );
        $this->assertSame( 0, $a['trustees']['trustees'] );
        $this->assertSame( 1, $a['trustees']['exempt'] );
    }

    public function test_an_inactive_trustee_is_other_not_expired(): void {
        $a = MyNJILGA_Membership_Stats::aggregate( [ $this->c( 1, [ 'role_tags' => [ 'trustees' ], 'inactive' => true, 'paid_years' => [ 2020 ] ] ) ], [], $this->cfg() );
        $this->assertSame( 1, $a['trustees']['other'] );
        $this->assertSame( 0, $a['trustees']['expired'] );
    }

    // -------------------------------------------------------------------
    // Reconcile
    // -------------------------------------------------------------------

    public function test_reconcile_counters_explain_the_gap_to_the_old_tag_only_numbers(): void {
        $r = $this->aggregate_fixture()['reconcile'];
        // Evergreen holders: 1, 2, 3, 4, 6, 7, 11, 17 = 8. Only 3 (paid 2025) is stale.
        $this->assertSame( 8, $r['paid_tag'] );
        $this->assertSame( 1, $r['stale_paid_tag'] );
        $this->assertSame( 1, $r['active_without_paid_tag'], 'contact 8: paid 2026 but no evergreen tag' );
        $this->assertSame( 1, $r['both_tags'], 'contact 3 holds paid and unpaid' );
    }

    public function test_a_stale_paid_tag_is_any_holder_who_is_not_active_whatever_they_are_instead(): void {
        $holders = [
            $this->c( 1, [ 'paid_tag' => true, 'paid_years' => [ 2025 ] ] ),                                 // expired
            $this->c( 2, [ 'paid_tag' => true, 'paid_years' => [ 2025 ], 'role_tags' => [ 'past-president' ] ] ), // exempt
            $this->c( 3, [ 'paid_tag' => true, 'paid_years' => [ 2025 ], 'inactive' => true ] ),                // inactive
            $this->c( 4, [ 'paid_tag' => true, 'paid_years' => [ 2026 ] ] ),                                 // active: not stale
            $this->c( 5, [ 'paid_tag' => true ] ),                                                            // assumed active: not stale
        ];
        $r = MyNJILGA_Membership_Stats::aggregate( $holders, [], $this->cfg() )['reconcile'];
        $this->assertSame( 5, $r['paid_tag'] );
        $this->assertSame( 3, $r['stale_paid_tag'] );
        $this->assertSame( 0, $r['active_without_paid_tag'] );
    }

    // -------------------------------------------------------------------
    // The contract's shape
    // -------------------------------------------------------------------

    public function test_aggregate_returns_exactly_the_contracted_keys_even_when_empty(): void {
        $a = MyNJILGA_Membership_Stats::aggregate( [], [], $this->cfg() );
        $this->assertSame( [ 'year', 'next_year', 'members', 'categories', 'firms', 'no_firm', 'trustees', 'reconcile' ], array_keys( $a ) );
        $this->assertSame( [ 'active', 'dated', 'assumed', 'expired', 'exempt', 'inactive', 'none', 'paid_ahead', 'renewing', 'not_emailable', 'through' ], array_keys( $a['members'] ) );
        $this->assertSame( [ 'total', 'with_active', 'without_active', 'exempt_only', 'empty', 'no_owner', 'size' ], array_keys( $a['firms'] ) );
        $this->assertSame( [ 'active', 'by_category' ], array_keys( $a['no_firm'] ) );
        $this->assertSame( [ 'total', 'trustees', 'senior_trustees', 'past_presidents', 'officers', 'active', 'expired', 'exempt', 'other' ], array_keys( $a['trustees'] ) );
        $this->assertSame( [ 'paid_tag', 'stale_paid_tag', 'active_without_paid_tag', 'both_tags' ], array_keys( $a['reconcile'] ) );
        $this->assertSame( [ 'label', 'active', 'expired', 'defaulted' ], array_keys( $a['categories']['professional'] ) );
        $this->assertSame( [], $a['members']['through'] );
        $this->assertSame( 0, $a['members']['active'] );
    }

    // -------------------------------------------------------------------
    // Reading FluentCRM without FluentCRM: the tag plan
    // -------------------------------------------------------------------

    /** @return array<int,array{id:int,slug:string,title:string}> */
    private function tags(): array {
        return [
            [ 'id' => 5,  'slug' => 'dues-paid',          'title' => 'Dues Paid' ],
            [ 'id' => 6,  'slug' => 'dues-paid-2026',     'title' => 'Dues Paid 2026' ],
            [ 'id' => 7,  'slug' => 'dues-paid-2027',     'title' => 'Dues Paid 2027' ],
            [ 'id' => 8,  'slug' => 'renamed-2027',       'title' => 'dues paid 2027' ],
            [ 'id' => 9,  'slug' => 'law-student-legacy', 'title' => 'Law Student' ],
            [ 'id' => 10, 'slug' => 'senior-trustee',     'title' => 'Senior Trustee' ],
            [ 'id' => 11, 'slug' => 'unpaid-dues-2026',   'title' => 'Unpaid Dues 2026' ],
        ];
    }

    /**
     * @param mixed $value
     * @return array<string,mixed>
     */
    private function spec( string $key, string $slug, string $title, $value, bool $warn = true, string $label = 'tag' ): array {
        return [ 'key' => $key, 'slug' => $slug, 'title' => $title, 'value' => $value, 'label' => $label, 'warn' => $warn ];
    }

    public function test_plan_resolves_slug_then_title_and_groups_year_tags(): void {
        $plan = MyNJILGA_Membership_Stats::plan_tags( $this->tags(), [
            $this->spec( 'paid', 'dues-paid', 'Dues Paid', true ),
            $this->spec( 'category', 'law-student', 'Law Student', 'law-student' ),
            $this->spec( 'category', 'senior-trustee', 'Senior Trustee', 'senior-trustee' ),
            $this->spec( 'role', 'senior-trustee', 'Senior Trustee', 'senior-trustee' ),
        ], self::PATTERN );

        $this->assertSame( [ [ 'key' => 'paid', 'value' => true ] ], $plan['by_id'][5] );
        $this->assertSame( [ [ 'key' => 'category', 'value' => 'law-student' ] ], $plan['by_id'][9], 'no slug match, so the exact title' );
        $this->assertSame( [ [ 'key' => 'category', 'value' => 'senior-trustee' ], [ 'key' => 'role', 'value' => 'senior-trustee' ] ], $plan['by_id'][10], 'one tag, two uses, queried once' );
        $this->assertSame( [ [ 'key' => 'year', 'value' => 2026 ] ], $plan['by_id'][6] );
        $this->assertSame( [ [ 'key' => 'year', 'value' => 2027 ] ], $plan['by_id'][8], 'a renamed tag whose title reads as a year tag is one too' );
        $this->assertSame( [ 2026 => [ 6 ], 2027 => [ 7, 8 ] ], $plan['year_ids'] );
        $this->assertFalse( isset( $plan['by_id'][11] ), '"Unpaid Dues 2026" is not a paid-year tag' );
        $this->assertSame( [], $plan['unresolved'] );
    }

    public function test_plan_reports_a_missing_tag_once_and_only_when_it_is_worth_a_warning(): void {
        $plan = MyNJILGA_Membership_Stats::plan_tags( $this->tags(), [
            $this->spec( 'unpaid', 'unpaid-dues', 'Unpaid Dues', true, true, 'unpaid tag' ),
            $this->spec( 'category', 'ghost', 'Ghost', 'ghost', true, 'category tag' ),
            $this->spec( 'role', 'ghost', 'Ghost', 'ghost', true, 'trustee tag' ),
            $this->spec( 'role', 'officer', 'Officer', 'officer', false ),
            $this->spec( 'inactive', '', '', true ),
        ], self::PATTERN );

        $this->assertSame( [ [ 'slug' => 'unpaid-dues', 'label' => 'unpaid tag' ], [ 'slug' => 'ghost', 'label' => 'category tag' ] ], $plan['unresolved'] );
    }

    public function test_plan_finds_no_year_tags_without_a_year_placeholder(): void {
        $plan = MyNJILGA_Membership_Stats::plan_tags( $this->tags(), [], 'Dues Paid' );
        $this->assertSame( [], $plan['year_ids'] );
        $this->assertSame( [], $plan['by_id'] );
    }

    public function test_resolve_tag_id_follows_resolve_one(): void {
        $tags = [
            [ 'id' => 3, 'slug' => 'x',           'title' => 'Law Student' ],
            [ 'id' => 9, 'slug' => 'law-student', 'title' => 'Other' ],
            [ 'id' => 4, 'slug' => 'y',           'title' => 'Law Student' ],
        ];
        $this->assertSame( 9, MyNJILGA_Membership_Stats::resolve_tag_id( $tags, 'law-student', 'Law Student' ), 'the slug wins even when a title matches a lower id' );
        $this->assertSame( 3, MyNJILGA_Membership_Stats::resolve_tag_id( $tags, 'nope', 'Law Student' ), 'the lowest id wins a title tie, like first()' );
        $this->assertSame( 9, MyNJILGA_Membership_Stats::resolve_tag_id( $tags, 'LAW-STUDENT', 'zzz' ), 'case-insensitive, like the database collation' );
        $this->assertSame( null, MyNJILGA_Membership_Stats::resolve_tag_id( $tags, 'nope', 'Nope' ) );
        $this->assertSame( null, MyNJILGA_Membership_Stats::resolve_tag_id( [ [ 'id' => 1, 'slug' => '', 'title' => '' ] ], '', '' ), 'a blank slug or title matches nothing' );
    }

    public function test_apply_tag_is_idempotent_and_keeps_years_newest_first(): void {
        $c = $this->c( 1 );
        $c = MyNJILGA_Membership_Stats::apply_tag( $c, 'year', 2026 );
        $c = MyNJILGA_Membership_Stats::apply_tag( $c, 'year', 2027 );
        $c = MyNJILGA_Membership_Stats::apply_tag( $c, 'year', 2026 );
        $c = MyNJILGA_Membership_Stats::apply_tag( $c, 'role', 'trustees' );
        $c = MyNJILGA_Membership_Stats::apply_tag( $c, 'role', 'trustees' );
        $c = MyNJILGA_Membership_Stats::apply_tag( $c, 'category', 'professional' );
        $c = MyNJILGA_Membership_Stats::apply_tag( $c, 'paid', true );
        $c = MyNJILGA_Membership_Stats::apply_tag( $c, 'unpaid', true );
        $c = MyNJILGA_Membership_Stats::apply_tag( $c, 'inactive', true );
        $this->assertSame( [ 2027, 2026 ], $c['paid_years'] );
        $this->assertSame( [ 'trustees' ], $c['role_tags'] );
        $this->assertSame( [ 'professional' ], $c['category_tags'] );
        $this->assertTrue( $c['paid_tag'] && $c['unpaid_tag'] && $c['inactive'] );
    }

    // -------------------------------------------------------------------
    // Warnings
    // -------------------------------------------------------------------

    public function test_a_healthy_setup_has_no_warnings(): void {
        $this->assertSame( [], MyNJILGA_Membership_Stats::build_warnings( [
            'unresolved' => [], 'year' => 2026, 'pattern' => self::PATTERN, 'year_ids' => [ 2026 => [ 6 ] ], 'companies_active' => true, 'paid_tag' => 'dues-paid',
        ] ) );
    }

    public function test_each_thing_the_figures_rest_on_gets_its_own_warning(): void {
        $base = [ 'unresolved' => [], 'year' => 2026, 'pattern' => self::PATTERN, 'year_ids' => [ 2026 => [ 6 ] ], 'companies_active' => true, 'paid_tag' => 'dues-paid' ];

        $w = MyNJILGA_Membership_Stats::build_warnings( [ 'unresolved' => [ [ 'slug' => 'law-student', 'label' => 'tag for the "Law Student Membership" category' ] ] ] + $base );
        $this->assertCount( 1, $w );
        $this->assertTrue( strpos( $w[0], 'law-student' ) !== false && strpos( $w[0], 'was not found' ) !== false, $w[0] );

        $w = MyNJILGA_Membership_Stats::build_warnings( [ 'year_ids' => [ 2025 => [ 5 ] ] ] + $base );
        $this->assertCount( 1, $w );
        $this->assertTrue( strpos( $w[0], '"Dues Paid 2026"' ) !== false, $w[0] );

        $w = MyNJILGA_Membership_Stats::build_warnings( [ 'companies_active' => false ] + $base );
        $this->assertCount( 1, $w );
        $this->assertTrue( strpos( $w[0], 'Companies module' ) !== false, $w[0] );

        $w = MyNJILGA_Membership_Stats::build_warnings( [ 'paid_tag' => 'members-paid' ] + $base );
        $this->assertCount( 1, $w );
        $this->assertTrue( strpos( $w[0], 'members-paid' ) !== false && strpos( $w[0], 'dues-paid' ) !== false, $w[0] );

        $w = MyNJILGA_Membership_Stats::build_warnings( [ 'pattern' => 'Dues Paid', 'year_ids' => [] ] + $base );
        $this->assertCount( 1, $w, 'a pattern with no {year} is one problem, not also "no tag exists"' );
        $this->assertTrue( strpos( $w[0], '{year}' ) !== false, $w[0] );
    }

    // -------------------------------------------------------------------
    // assemble() — what the report lists are built from
    // -------------------------------------------------------------------

    public function test_assemble_keeps_the_universe_and_classifies_every_contact(): void {
        $d = $this->data_fixture();
        $this->assertTrue( $d['available'] );
        $this->assertCount( 15, $d['contacts'] );
        $this->assertFalse( isset( $d['contacts'][12] ), 'the newsletter subscriber' );
        $this->assertFalse( isset( $d['contacts'][13] ), 'a category tag alone on no roster' );
        $this->assertSame( 'active', $d['contacts'][1]['state'] );
        $this->assertSame( 'none', $d['contacts'][10]['state'] );
        $this->assertSame( 'Trustee', $d['contacts'][2]['trustee_role'] );
        $this->assertTrue( $d['contacts'][5]['exempt'] );
    }

    public function test_assemble_lists_a_contacts_firms_primary_first_and_firms_alphabetically(): void {
        $d = $this->data_fixture();
        $this->assertSame( [ 14, 10 ], $d['contacts'][2]['firm_ids'], 'primary company 14 leads even though 10 sorts first' );
        $this->assertSame( [ 12, 16 ], $d['contacts'][5]['firm_ids'], 'no primary: alphabetical' );
        $this->assertSame( [], $d['contacts'][7]['firm_ids'] );
        $this->assertSame( [ 10, 11, 12, 13, 15, 16, 14 ], array_keys( $d['firms'] ), 'keyed by id, Alpha … Foxtrot … Zeta' );
    }

    public function test_assemble_fills_in_a_roster_contact_no_tag_query_returned_and_dedupes_rosters(): void {
        $d = MyNJILGA_Membership_Stats::assemble( [
            'cfg'      => $this->cfg(),
            'warnings' => [ 'w' ],
            'contacts' => [ 1 => $this->c( 1, [ 'paid_tag' => true ] ) ],
            'firms'    => [ $this->firm( 10, [ 1, 50, 50, 1 ] ) ],
        ] );
        $this->assertSame( [ 1, 50 ], $d['firms'][10]['contact_ids'] );
        $this->assertSame( 'none', $d['contacts'][50]['state'] );
        $this->assertSame( '(contact #50)', $d['contacts'][50]['name'] );
        $this->assertSame( [ 10 ], $d['contacts'][50]['firm_ids'] );
        $this->assertSame( [ 'w' ], $d['warnings'] );
    }

    // -------------------------------------------------------------------
    // MyNJILGA_Members_Data — the label helpers
    // -------------------------------------------------------------------

    public function test_report_stats_come_straight_from_the_snapshot(): void {
        $snap = [ 'available' => true, 'warnings' => [] ] + $this->aggregate_fixture();
        $this->assertSame(
            [ 'paid_members' => 8, 'unpaid_members' => 3, 'firms_with_paid' => 4, 'firms_without_paid' => 2, 'paid_trustees' => 1, 'unpaid_trustees' => 1, 'exempt' => 2 ],
            MyNJILGA_Members_Data::stats_from_snapshot( $snap )
        );
        $this->assertSame(
            [ 'paid_members' => 0, 'unpaid_members' => 0, 'firms_with_paid' => 0, 'firms_without_paid' => 0, 'paid_trustees' => 0, 'unpaid_trustees' => 0, 'exempt' => 0 ],
            MyNJILGA_Members_Data::stats_from_snapshot( [ 'available' => false, 'warnings' => [ 'no' ] ] ),
            'no FluentCRM: zeros, same keys'
        );
    }

    public function test_every_report_stat_reads_its_own_snapshot_field(): void {
        // Distinct numbers in every field, so a tile wired to the wrong one cannot pass by coincidence.
        $snap = [
            'available' => true,
            'members'   => [ 'active' => 11, 'expired' => 12, 'exempt' => 19, 'inactive' => 20, 'none' => 21 ],
            'firms'     => [ 'with_active' => 13, 'without_active' => 14, 'total' => 27, 'empty' => 28 ],
            'trustees'  => [ 'active' => 15, 'expired' => 16, 'exempt' => 17, 'other' => 18, 'total' => 66 ],
        ];
        $this->assertSame(
            [ 'paid_members' => 11, 'unpaid_members' => 12, 'firms_with_paid' => 13, 'firms_without_paid' => 14, 'paid_trustees' => 15, 'unpaid_trustees' => 16, 'exempt' => 17 ],
            MyNJILGA_Members_Data::stats_from_snapshot( $snap )
        );
    }

    public function test_dues_label_feeds_the_existing_colour_helpers(): void {
        $this->assertSame( 'Dues Paid', MyNJILGA_Members_Data::dues_label( 'active' ) );
        $this->assertSame( 'Unpaid Dues', MyNJILGA_Members_Data::dues_label( 'expired' ) );
        foreach ( [ 'exempt', 'inactive', 'none' ] as $state ) {
            $this->assertSame( '', MyNJILGA_Members_Data::dues_label( $state ), $state );
        }
        // Tags::dues_color()/dues_variant() key on these exact strings.
        $this->assertSame( 'success', MyNJILGA_Tags::dues_variant( MyNJILGA_Members_Data::dues_label( 'active' ) ) );
        $this->assertSame( 'destructive', MyNJILGA_Tags::dues_variant( MyNJILGA_Members_Data::dues_label( 'expired' ) ) );
    }

    public function test_payment_column_helpers_keep_the_tag_helpers_precedence(): void {
        $c = static function ( array $roles, string $state ) {
            return [ 'role_tags' => $roles, 'state' => $state ];
        };
        // dues_payment_method(): invoice, then check, then "Paid by Website" for an ACTIVE member, else blank.
        $this->assertSame( 'Paid by Invoice', MyNJILGA_Members_Data::dues_payment_method( $c( [ 'paid-by-check', 'paid-by-invoice' ], 'active' ) ) );
        $this->assertSame( 'Paid by Check', MyNJILGA_Members_Data::dues_payment_method( $c( [ 'paid-by-check' ], 'expired' ) ), 'a payment-method tag shows whatever the state' );
        $this->assertSame( 'Paid by Website', MyNJILGA_Members_Data::dues_payment_method( $c( [], 'active' ) ) );
        $this->assertSame( '', MyNJILGA_Members_Data::dues_payment_method( $c( [], 'expired' ) ) );
        $this->assertSame( '', MyNJILGA_Members_Data::dues_payment_method( $c( [ 'trustees' ], 'exempt' ) ) );

        // payment_method(): check FIRST, then invoice, else "Credit Card" (the opposite order — kept).
        $this->assertSame( 'Check', MyNJILGA_Members_Data::payment_method( $c( [ 'paid-by-invoice', 'paid-by-check' ], 'active' ) ) );
        $this->assertSame( 'Invoice', MyNJILGA_Members_Data::payment_method( $c( [ 'paid-by-invoice' ], 'active' ) ) );
        $this->assertSame( 'Credit Card', MyNJILGA_Members_Data::payment_method( $c( [], 'expired' ) ) );
    }

    public function test_dues_pill_by_state_and_the_exempt_override(): void {
        $this->assertSame( [ 'Paid', 'success' ], MyNJILGA_Members_Data::dues_pill( 'active' ) );
        $this->assertSame( [ 'Unpaid', 'destructive' ], MyNJILGA_Members_Data::dues_pill( 'expired' ) );
        $this->assertSame( [ 'Exempt', 'info' ], MyNJILGA_Members_Data::dues_pill( 'exempt' ) );
        $this->assertSame( [ 'Inactive', 'muted' ], MyNJILGA_Members_Data::dues_pill( 'inactive' ) );
        $this->assertSame( [ 'None', 'muted' ], MyNJILGA_Members_Data::dues_pill( 'none' ) );
        $this->assertSame( [ 'Exempt', 'info' ], MyNJILGA_Members_Data::dues_pill( 'active', true ), 'an exempt trustee paid by a firm invoice still reads Exempt' );
        $this->assertSame( [ 'Exempt', 'info' ], MyNJILGA_Members_Data::dues_pill( 'none', true ) );
    }

    public function test_firm_label_uses_the_roster_and_only_falls_back_off_it(): void {
        $firms = [ 10 => [ 'name' => 'Alpha LLP' ], 14 => [ 'name' => 'Zeta LLP' ], 20 => [ 'name' => '' ] ];
        $fb    = $this->fallback();
        $this->assertSame( 'Zeta LLP; Alpha LLP', MyNJILGA_Members_Data::firm_label( [ 'id' => 2, 'firm_ids' => [ 14, 10 ] ], $firms, $fb ) );
        $this->assertSame( 'Alpha LLP', MyNJILGA_Members_Data::firm_label( [ 'id' => 2, 'firm_ids' => [ 10 ] ], $firms, $fb ) );
        $this->assertSame( 'custom:7', MyNJILGA_Members_Data::firm_label( [ 'id' => 7, 'firm_ids' => [] ], $firms, $fb ), 'no roster: the custom-field fallback' );
        $this->assertSame( '', MyNJILGA_Members_Data::firm_label( [ 'id' => 7, 'firm_ids' => [ 20 ] ], $firms, $fb ), 'on a roster (of an unnamed firm): the fallback is not consulted' );
    }

    // -------------------------------------------------------------------
    // MyNJILGA_Members_Data — the lists
    // -------------------------------------------------------------------

    public function test_active_members_list_is_the_active_set_with_no_status_filter(): void {
        $rows = MyNJILGA_Members_Data::active_member_rows( $this->data_fixture(), $this->url(), $this->fallback() );
        $ids  = array_map( static function ( $r ) { return $r['subscriber_id']; }, $rows );
        sort( $ids );
        $this->assertSame( [ 1, 2, 4, 6, 7, 8, 11, 17 ], $ids );
        $this->assertTrue( in_array( 6, $ids, true ) && in_array( 8, $ids, true ), 'unsubscribed and transactional members are listed' );
        $this->assertSame(
            [ 'subscriber_id', 'member', 'member_url', 'first_name', 'last_name', 'email', 'firm', 'is_trustee', 'trustee_status', 'payment_method' ],
            array_keys( $rows[0] )
        );
    }

    public function test_active_members_rows_carry_firm_role_payment_and_sort_by_firm_then_name(): void {
        $rows = MyNJILGA_Members_Data::active_member_rows( $this->data_fixture(), $this->url(), $this->fallback() );
        $by   = [];
        foreach ( $rows as $r ) {
            $by[ $r['subscriber_id'] ] = $r;
        }
        $this->assertSame( 'Zeta LLP; Alpha LLP', $by[2]['firm'], 'primary firm first' );
        $this->assertSame( 'custom:7', $by[7]['firm'], 'on no roster: the custom field' );
        $this->assertSame( 'Charlie LLP', $by[6]['firm'] );
        $this->assertSame( 'Trustee', $by[2]['trustee_status'] );
        $this->assertTrue( $by[2]['is_trustee'] );
        $this->assertSame( 'Past President', $by[6]['trustee_status'] );
        $this->assertFalse( $by[1]['is_trustee'] );
        $this->assertSame( '', $by[1]['trustee_status'] );
        $this->assertSame( 'Credit Card', $by[1]['payment_method'] );
        $this->assertSame( 'admin/contact/1', $by[1]['member_url'] );
        $this->assertSame( 'F1 L1', $by[1]['member'] );

        $firms = array_map( static function ( $r ) { return $r['firm']; }, $rows );
        $sorted = $firms;
        usort( $sorted, 'strcasecmp' );
        $this->assertSame( $sorted, $firms, 'sorted by firm' );
    }

    public function test_trustee_list_is_the_whole_family_and_its_pills_match_the_tiles(): void {
        $rows = MyNJILGA_Members_Data::trustee_rows( $this->data_fixture(), $this->url(), $this->fallback() );
        $by   = [];
        foreach ( $rows as $r ) {
            $by[ $r['subscriber_id'] ] = $r;
        }
        $this->assertSame( [ 2, 5, 6, 15, 16 ], $this->sorted_keys( $by ), 'Trustee 2, Senior Trustee 5, Past President 6, Trustees 15 and 16 — whatever their status or dues' );
        $this->assertSame(
            [ 'subscriber_id', 'member', 'member_url', 'first_name', 'last_name', 'email', 'firm', 'is_paid', 'is_unpaid', 'is_exempt', 'state', 'trustee_status', 'payment_method' ],
            array_keys( $rows[0] )
        );

        $this->assertTrue( $by[2]['is_paid'] && ! $by[2]['is_unpaid'] && ! $by[2]['is_exempt'], 'plain trustee, paid' );
        $this->assertTrue( ! $by[16]['is_paid'] && $by[16]['is_unpaid'] && ! $by[16]['is_exempt'], 'plain trustee, expired' );
        $this->assertTrue( ! $by[15]['is_paid'] && ! $by[15]['is_unpaid'] && ! $by[15]['is_exempt'], 'plain trustee with no dues on record: neither' );
        $this->assertTrue( ! $by[5]['is_paid'] && ! $by[5]['is_unpaid'] && $by[5]['is_exempt'], 'Senior Trustee, no dues: exempt' );
        $this->assertTrue( $by[6]['is_paid'] && ! $by[6]['is_unpaid'] && $by[6]['is_exempt'], 'Past President paid by a firm invoice: paid AND exempt' );
        $this->assertSame( 'Senior Trustee', $by[5]['trustee_status'] );

        // Count the pills the Trustees page would print: they are the four tiles.
        $pills = [];
        foreach ( $rows as $r ) {
            $label            = MyNJILGA_Members_Data::dues_pill( $r['state'], $r['is_exempt'] )[0];
            $pills[ $label ]  = ( $pills[ $label ] ?? 0 ) + 1;
        }
        ksort( $pills );
        $this->assertSame( [ 'Exempt' => 2, 'None' => 1, 'Paid' => 1, 'Unpaid' => 1 ], $pills );
    }

    /** @param array<int,mixed> $by */
    private function sorted_keys( array $by ): array {
        $keys = array_keys( $by );
        sort( $keys );
        return $keys;
    }

    public function test_companies_are_bucketed_by_active_members_and_empties_are_reported_apart(): void {
        $r = MyNJILGA_Members_Data::bucket_companies( $this->data_fixture(), $this->url() );
        $this->assertSame( [ 'buckets', 'bucket_labels', 'empty_companies' ], array_keys( $r ) );
        $this->assertSame( 1, $r['empty_companies'] );
        $this->assertSame( [ 1, '2-5', '6+', 0 ], array_keys( $r['buckets'] ) );
        $names = static function ( array $rows ) {
            return array_map( static function ( $c ) { return $c['name']; }, $rows );
        };
        $this->assertSame( [ 'Bravo LLP', 'Charlie LLP' ], $names( $r['buckets']['1'] ) );
        $this->assertSame( [ 'Alpha LLP', 'Zeta LLP' ], $names( $r['buckets']['2-5'] ) );
        $this->assertSame( [], $r['buckets']['6+'] );
        $this->assertSame( [ 'Delta LLP', 'Foxtrot LLP' ], $names( $r['buckets']['0'] ), 'firms with contacts but none active — and not the empty Echo LLP' );
        $this->assertSame( '1 Paid Member', $r['bucket_labels']['1'] );
        $this->assertSame( 'No Paid Members', $r['bucket_labels']['0'] );

        $alpha = $r['buckets']['2-5'][0];
        $this->assertSame( 3, $alpha['paid_count'] );
        $this->assertSame( 3, $alpha['total_count'] );
        $this->assertSame( [ 'name', 'paid_count', 'total_count', 'members' ], array_keys( $alpha ) );

        $delta = $r['buckets']['0'][0];
        $this->assertSame( 0, $delta['paid_count'] );
        $this->assertSame( 2, $delta['total_count'] );
        $this->assertSame( [ 'name', 'url', 'is_paid', 'state' ], array_keys( $delta['members'][0] ) );
        $this->assertSame( [ 'expired', 'none' ], array_map( static function ( $m ) { return $m['state']; }, $delta['members'] ) );
        $this->assertFalse( $delta['members'][0]['is_paid'] );
        $this->assertSame( 'admin/contact/9', $delta['members'][0]['url'] );
    }

    public function test_companies_with_no_data_have_empty_buckets(): void {
        $r = MyNJILGA_Members_Data::bucket_companies( [], $this->url() );
        $this->assertSame( [ '1' => [], '2-5' => [], '6+' => [], '0' => [] ], $r['buckets'] );
        $this->assertSame( 0, $r['empty_companies'] );
        $this->assertCount( 4, $r['bucket_labels'] );
    }

    public function test_membership_by_firm_all_scope(): void {
        $firms = MyNJILGA_Members_Data::firm_rows( $this->data_fixture(), 'all' );
        $names = array_map( static function ( $f ) { return $f['name']; }, $firms );
        $this->assertSame( [ 'Alpha LLP', 'Bravo LLP', 'Charlie LLP', 'Delta LLP', 'Foxtrot LLP', 'Zeta LLP' ], $names, 'every firm with a contact, alphabetical; the empty one omitted' );

        $bravo = $firms[1]['contacts'];
        $this->assertSame( [ 'first_name', 'last_name', 'email', 'dues', 'trustees', 'past_president', 'payment' ], array_keys( $bravo[0] ) );
        $this->assertSame( [ 'Unpaid Dues', 'Dues Paid' ], array_column( $bravo, 'dues' ), 'expired then active (sorted L3, L4)' );
        $this->assertSame( [ '', 'Paid by Website' ], array_column( $bravo, 'payment' ) );

        $charlie = array_column( $firms[2]['contacts'], null, 'last_name' );
        $this->assertSame( '', $charlie['L5']['dues'], 'exempt: blank' );
        $this->assertSame( '', $charlie['L5']['trustees'] );
        $this->assertSame( '', $charlie['L5']['past_president'], 'a Senior Trustee has no role column, as before' );
        $this->assertSame( 'Past President', $charlie['L6']['past_president'] );
        $this->assertSame( 'Dues Paid', $charlie['L6']['dues'] );

        $delta = array_column( $firms[3]['contacts'], 'dues', 'last_name' );
        $this->assertSame( [ 'L10' => '', 'L9' => 'Unpaid Dues' ], $delta, 'an expired contact, and a roster contact with no dues signal (L10 sorts before L9)' );
    }

    public function test_membership_by_firm_active_scope_lists_only_active_members_of_firms_that_have_one(): void {
        $firms = MyNJILGA_Members_Data::firm_rows( $this->data_fixture(), 'active' );
        $this->assertSame( [ 'Alpha LLP', 'Bravo LLP', 'Charlie LLP', 'Zeta LLP' ], array_column( $firms, 'name' ) );
        foreach ( $firms as $firm ) {
            foreach ( $firm['contacts'] as $c ) {
                $this->assertSame( 'Dues Paid', $c['dues'], $firm['name'] );
            }
        }
        $this->assertSame( [ 'L4' ], array_column( $firms[1]['contacts'], 'last_name' ), 'Bravo: the expired contact is left out' );
        $this->assertSame( [ 'L6' ], array_column( $firms[2]['contacts'], 'last_name' ), 'Charlie: the exempt contact is left out' );
        $this->assertSame( 'Trustees', array_column( $firms[0]['contacts'], 'trustees', 'last_name' )['L2'] );
    }

    public function test_membership_by_firm_sorts_contacts_by_last_then_first_name(): void {
        $data = MyNJILGA_Membership_Stats::assemble( [
            'cfg'      => $this->cfg(),
            'warnings' => [],
            'contacts' => [
                1 => $this->c( 1, [ 'paid_years' => [ 2026 ], 'first_name' => 'Zed', 'last_name' => 'adams' ] ),
                2 => $this->c( 2, [ 'paid_years' => [ 2026 ], 'first_name' => 'Amy', 'last_name' => 'Brown' ] ),
                3 => $this->c( 3, [ 'paid_years' => [ 2026 ], 'first_name' => 'Al', 'last_name' => 'Adams' ] ),
            ],
            'firms'    => [ $this->firm( 10, [ 1, 2, 3 ] ) ],
        ] );
        $rows = MyNJILGA_Members_Data::firm_rows( $data, 'all' );
        $this->assertSame( [ 'Al Adams', 'Zed adams', 'Amy Brown' ], array_map( static function ( $c ) { return $c['first_name'] . ' ' . $c['last_name']; }, $rows[0]['contacts'] ) );
    }

    // -------------------------------------------------------------------
    // The point of the change
    // -------------------------------------------------------------------

    public function test_the_tiles_and_every_list_are_the_same_numbers(): void {
        $data = $this->data_fixture();
        $snap = [ 'available' => true, 'warnings' => [] ] + MyNJILGA_Membership_Stats::aggregate( $data['contacts'], $data['firms'], $data['cfg'] );
        $tile = MyNJILGA_Members_Data::stats_from_snapshot( $snap );

        // Members list = "Paid Members".
        $members = MyNJILGA_Members_Data::active_member_rows( $data, $this->url(), $this->fallback() );
        $this->assertSame( $tile['paid_members'], count( $members ) );
        $this->assertSame( $snap['members']['active'], count( $members ) );

        // Trustees list: its pills are the trustee tiles, and its length is the Dashboard's Trustees card.
        $trustees = MyNJILGA_Members_Data::trustee_rows( $data, $this->url(), $this->fallback() );
        $counts   = [ 'Paid' => 0, 'Unpaid' => 0, 'Exempt' => 0, 'other' => 0 ];
        foreach ( $trustees as $r ) {
            $label = MyNJILGA_Members_Data::dues_pill( $r['state'], $r['is_exempt'] )[0];
            $counts[ isset( $counts[ $label ] ) ? $label : 'other' ]++;
        }
        $this->assertSame( $tile['paid_trustees'], $counts['Paid'] );
        $this->assertSame( $tile['unpaid_trustees'], $counts['Unpaid'] );
        $this->assertSame( $tile['exempt'], $counts['Exempt'] );
        $this->assertSame( $snap['trustees']['other'], $counts['other'] );
        $this->assertSame( $snap['trustees']['total'], count( $trustees ) );
        $this->assertSame( $snap['trustees']['total'], $tile['paid_trustees'] + $tile['unpaid_trustees'] + $tile['exempt'] + $snap['trustees']['other'] );

        // Companies report = firm tiles, and its buckets are firms.size.
        $bucketed = MyNJILGA_Members_Data::bucket_companies( $data, $this->url() );
        $b        = $bucketed['buckets'];
        $this->assertSame( $tile['firms_with_paid'], count( $b['1'] ) + count( $b['2-5'] ) + count( $b['6+'] ) );
        $this->assertSame( $tile['firms_without_paid'], count( $b['0'] ) );
        $this->assertSame( $snap['firms']['size'], [ 1 => count( $b['1'] ), '2-5' => count( $b['2-5'] ), '6+' => count( $b['6+'] ) ] );
        $this->assertSame( $snap['firms']['empty'], $bucketed['empty_companies'] );
        $this->assertSame( $snap['firms']['total'] + $snap['firms']['empty'], count( $data['firms'] ) );

        // Membership by Firm.
        $this->assertSame( $snap['firms']['total'], count( MyNJILGA_Members_Data::firm_rows( $data, 'all' ) ) );
        $this->assertSame( $tile['firms_with_paid'], count( MyNJILGA_Members_Data::firm_rows( $data, 'active' ) ) );

        // And the same again a year later.
        $d27 = $this->data_fixture( 2027 );
        $s27 = MyNJILGA_Membership_Stats::aggregate( $d27['contacts'], $d27['firms'], $d27['cfg'] );
        $this->assertSame( $s27['members']['active'], count( MyNJILGA_Members_Data::active_member_rows( $d27, $this->url(), $this->fallback() ) ) );
        $b27 = MyNJILGA_Members_Data::bucket_companies( $d27, $this->url() );
        $this->assertSame( $s27['firms']['without_active'], count( $b27['buckets']['0'] ) );
    }

    // -------------------------------------------------------------------
    // Exports say what the screens say (Executive Summary, Companies CSV)
    // -------------------------------------------------------------------

    public function test_export_labels_match_the_on_screen_pills_for_every_state(): void {
        $expect = [
            'active'   => [ 'Paid',     '#1d6f42' ],
            'expired'  => [ 'Unpaid',   '#d63638' ],
            'exempt'   => [ 'Exempt',   '#2271b1' ],
            'inactive' => [ 'Inactive', '#999999' ],
            'none'     => [ 'None',     '#999999' ],
        ];
        foreach ( $expect as $state => $want ) {
            $this->assertSame( $want, MyNJILGA_Members_Data::dues_export( $state ), $state );
            $this->assertSame( $want[0], MyNJILGA_Members_Data::dues_pill( $state )[0], $state . ' label is the pill label' );
        }
    }

    public function test_an_exempt_trustee_reads_exempt_even_when_paid(): void {
        // The Trustees page and the Exempt tile call a paid Past President "Exempt", so must the export.
        $this->assertSame( [ 'Exempt', '#2271b1' ], MyNJILGA_Members_Data::dues_export( 'active', true ) );
        $this->assertSame( [ 'Exempt', '#2271b1' ], MyNJILGA_Members_Data::dues_export( 'expired', true ) );
    }
}
