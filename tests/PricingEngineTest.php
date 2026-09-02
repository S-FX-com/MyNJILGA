<?php
/**
 * Unit tests for MyNJILGA_Pricing_Engine (spec §6).
 *
 * Run with any PHP 7.4+ CLI, no WordPress and no PHPUnit needed:
 *
 *   php tests/run.php
 *
 * The engine is a pure function, so the fixtures below are exactly the
 * arrays MyNJILGA_Dues_Settings::engine_config() would hand it on a live
 * site — built here from the same seed defaults so the seed data itself
 * is under test.
 */
class MyNJILGA_Pricing_Engine_Test extends NJILGA_TestCase {

    // -------------------------------------------------------------------------
    // Fixtures
    // -------------------------------------------------------------------------

    /** The seeded settings, as the engine receives them. */
    private function config( array $overrides = [] ): array {
        $defaults = MyNJILGA_Dues_Settings::defaults();

        return array_merge( [
            'default_category' => $defaults['general']['default_category'],
            'inactive_tag'     => $defaults['general']['inactive_tag'],
            'categories'       => $defaults['categories'],
            'assessment'       => $defaults['assessment'],
        ], $overrides );
    }

    private function contact( int $id, string $first, string $last, array $tags = [] ): array {
        return [
            'contact_id' => $id,
            'first_name' => $first,
            'last_name'  => $last,
            'name'       => "$first $last",
            'email'      => strtolower( "$first.$last@example.com" ),
            'tags'       => $tags,
        ];
    }

    /** @return array<int,int> dues per member, keyed by contact id */
    private function dues_by_id( array $result ): array {
        $out = [];
        foreach ( $result['members'] as $m ) {
            $out[ $m['contact_id'] ] = $m['dues_cents'];
        }
        return $out;
    }

    private function member( array $result, int $contactId ): array {
        foreach ( $result['members'] as $m ) {
            if ( $m['contact_id'] === $contactId ) {
                return $m;
            }
        }
        $this->fail( "Contact #$contactId not in result" );
        return [];
    }

    // -------------------------------------------------------------------------
    // §6 test cases
    // -------------------------------------------------------------------------

    /** 1. A lone professional is the 1st member at $125. */
    public function test_single_professional_is_first_member(): void {
        $r = MyNJILGA_Pricing_Engine::price( [ $this->contact( 1, 'Ann', 'Brown', [ 'professional' ] ) ], $this->config() );

        $this->assertCount( 1, $r['members'] );
        $m = $r['members'][0];
        $this->assertSame( 1, $m['rank'] );
        $this->assertSame( '1st Member', $m['tier_label'] );
        $this->assertSame( 12500, $m['dues_cents'] );
        $this->assertSame( 0, $m['assessment_cents'] );
        $this->assertSame( 12500, $r['totals']['total_cents'] );
    }

    /** 2. Five professionals: $125 + 4 × $75 = $425. */
    public function test_five_professionals_are_tiered(): void {
        $roster = [];
        foreach ( [ 'Adams', 'Baker', 'Clark', 'Davis', 'Evans' ] as $i => $last ) {
            $roster[] = $this->contact( $i + 1, 'X', $last, [ 'professional' ] );
        }
        $r = MyNJILGA_Pricing_Engine::price( $roster, $this->config() );

        $this->assertSame( [ 1 => 12500, 2 => 7500, 3 => 7500, 4 => 7500, 5 => 7500 ], $this->dues_by_id( $r ) );
        $this->assertSame( 42500, $r['totals']['total_cents'] );
        $this->assertSame( 'Members 2–5', $this->member( $r, 5 )['tier_label'] );
    }

    /** 3. The 6th member and beyond are free and labelled as such. */
    public function test_sixth_and_later_members_are_free(): void {
        $roster = [];
        foreach ( [ 'Adams', 'Baker', 'Clark', 'Davis', 'Evans', 'Frank', 'Green' ] as $i => $last ) {
            $roster[] = $this->contact( $i + 1, 'X', $last, [ 'professional' ] );
        }
        $r = MyNJILGA_Pricing_Engine::price( $roster, $this->config() );

        $this->assertSame( 0, $this->member( $r, 6 )['dues_cents'] );
        $this->assertSame( 0, $this->member( $r, 7 )['dues_cents'] );
        $this->assertSame( 'Members 6+', $this->member( $r, 7 )['tier_label'] );
        $this->assertSame( 'Members 6+', $this->member( $r, 7 )['dues_note'] );
        $this->assertSame( 7, $this->member( $r, 7 )['rank'] );
        $this->assertSame( 42500, $r['totals']['total_cents'] ); // same as five members
        $this->assertSame( 7, $r['totals']['billed_members'] );  // free members are still "billed" (covered), not unbilled
    }

    /**
     * 4. RANKING PARTITION: an exempt Past President whose surname sorts
     * first must NOT take the $125 1st-member slot. The lone professional
     * is still ranked #1 and pays $125; the PP pays $0 and is ranked
     * after.
     */
    public function test_exempt_member_never_displaces_first_paid_slot(): void {
        $r = MyNJILGA_Pricing_Engine::price( [
            $this->contact( 1, 'Aaron', 'Aardvark', [ 'past-president' ] ),
            $this->contact( 2, 'Zed', 'Zulu', [ 'professional' ] ),
        ], $this->config() );

        $pro = $this->member( $r, 2 );
        $pp  = $this->member( $r, 1 );

        $this->assertSame( 1, $pro['rank'] );
        $this->assertSame( 12500, $pro['dues_cents'] );
        $this->assertSame( 0, $pp['dues_cents'] );
        $this->assertSame( 0, $pp['rank'] );
        $this->assertFalse( $pp['tier_eligible'] );
        $this->assertSame( 'past_president', $pp['category_key'] );
        // Billing order: paid slots first, comped/exempt after.
        $this->assertSame( [ 2, 1 ], array_column( $r['members'], 'contact_id' ) );
        // PP is still active → still owes the assessment.
        $this->assertSame( 20000, $pp['assessment_cents'] );
        $this->assertSame( 32500, $r['totals']['total_cents'] );
    }

    /**
     * 5. RANKING PARTITION: exempt/comped members must not push a paying
     * member into the free 6+ bracket. Five professionals + a Senior
     * Trustee + a Law Student = five PAID slots, none free.
     */
    public function test_exempt_members_do_not_push_paying_members_into_free_bracket(): void {
        $roster = [
            $this->contact( 10, 'Aaron', 'Aardvark', [ 'senior-trustee' ] ),
            $this->contact( 11, 'Abe',   'Abbott',   [ 'law-student' ] ),
        ];
        foreach ( [ 'Clark', 'Davis', 'Evans', 'Frank', 'Green' ] as $i => $last ) {
            $roster[] = $this->contact( $i + 1, 'X', $last, [ 'professional' ] );
        }
        $r = MyNJILGA_Pricing_Engine::price( $roster, $this->config() );

        $dues = $this->dues_by_id( $r );
        $this->assertSame( 12500, $dues[1] );
        $this->assertSame( 7500, $dues[2] );
        $this->assertSame( 7500, $dues[5] ); // 5th paying member is NOT free
        $this->assertSame( 0, $dues[10] );    // exempt
        $this->assertSame( 3000, $dues[11] ); // law student: flat $30, not ranked
        $this->assertSame( 5, $this->member( $r, 5 )['rank'] );
        $this->assertSame( 0, $this->member( $r, 11 )['rank'] );
        // Dues 425 + 30 flat, plus one senior-trustee assessment 200.
        $this->assertSame( 45500, $r['totals']['dues_cents'] );
        $this->assertSame( 20000, $r['totals']['assessment_cents'] );
    }

    /** 6. An active Officer owes normal dues AND the $200 assessment. */
    public function test_active_officer_owes_dues_plus_assessment(): void {
        $r = MyNJILGA_Pricing_Engine::price( [ $this->contact( 1, 'Ann', 'Brown', [ 'professional', 'officer' ] ) ], $this->config() );
        $m = $r['members'][0];

        $this->assertSame( 12500, $m['dues_cents'] );
        $this->assertSame( 20000, $m['assessment_cents'] );
        $this->assertSame( 'Officer', $m['assessment_qualifier'] );
        $this->assertSame( 'Trustee Dinner Assessment', $m['assessment_label'] );
        $this->assertSame( 32500, $r['totals']['total_cents'] );
    }

    /** 7. Inactive is a blanket override — nothing billed, whatever else they carry. */
    public function test_inactive_override_bills_nothing(): void {
        $r = MyNJILGA_Pricing_Engine::price( [
            $this->contact( 1, 'Ann', 'Brown', [ 'professional', 'officer', 'inactive' ] ),
            $this->contact( 2, 'Pat', 'Roe',   [ 'past-president', 'inactive' ] ),
            $this->contact( 3, 'Zed', 'Zulu',  [ 'professional' ] ),
        ], $this->config() );

        foreach ( [ 1, 2 ] as $id ) {
            $m = $this->member( $r, $id );
            $this->assertTrue( $m['inactive'] );
            $this->assertSame( 0, $m['dues_cents'] );
            $this->assertSame( 0, $m['assessment_cents'] );
            $this->assertSame( MyNJILGA_Pricing_Engine::UNBILLED_INACTIVE, $m['unbilled_reason'] );
        }
        // The inactive professional didn't consume rank 1: Zed is #1 at $125.
        $this->assertSame( 1, $this->member( $r, 3 )['rank'] );
        $this->assertSame( 12500, $this->member( $r, 3 )['dues_cents'] );
        $this->assertSame( 12500, $r['totals']['total_cents'] );
        $this->assertSame( 2, $r['totals']['unbilled_members'] );
        // Inactive members are listed last.
        $this->assertSame( 3, $r['members'][0]['contact_id'] );
    }

    /** 8. An exempt Senior Trustee owes $0 dues but the assessment. */
    public function test_exempt_senior_trustee_owes_assessment_only(): void {
        $r = MyNJILGA_Pricing_Engine::price( [ $this->contact( 1, 'Sam', 'Lee', [ 'senior-trustee' ] ) ], $this->config() );
        $m = $r['members'][0];

        $this->assertSame( 'senior_trustee', $m['category_key'] );
        $this->assertSame( 0, $m['dues_cents'] );
        $this->assertSame( 20000, $m['assessment_cents'] );
        $this->assertSame( 'Senior Trustee', $m['assessment_qualifier'] );
        $this->assertSame( '', $m['unbilled_reason'] ); // covered by the invoice, not an exception
        $this->assertSame( 20000, $r['totals']['total_cents'] );
    }

    /**
     * 9. Flat-priced categories (Law Student $30, Emerging Professional $50)
     * charge their flat price regardless of how many there are, never take
     * a rank, and never affect the Professional tiers.
     */
    public function test_flat_price_categories_charge_flat_and_never_rank(): void {
        $r = MyNJILGA_Pricing_Engine::price( [
            $this->contact( 1, 'Lee', 'Law',    [ 'law-student' ] ),
            $this->contact( 2, 'Em',  'Erging', [ 'emerging-professional' ] ),
            $this->contact( 3, 'Al',  'Law',    [ 'law-student' ] ),
            $this->contact( 4, 'Zed', 'Zulu',   [ 'professional' ] ),
        ], $this->config() );

        $this->assertSame( 3000, $this->member( $r, 1 )['dues_cents'] );
        $this->assertSame( 3000, $this->member( $r, 3 )['dues_cents'] ); // second student still $30, not a "2nd member"
        $this->assertSame( 5000, $this->member( $r, 2 )['dues_cents'] );
        $this->assertFalse( $this->member( $r, 1 )['tier_eligible'] );
        $this->assertSame( 0, $this->member( $r, 1 )['rank'] );
        $this->assertSame( '', $this->member( $r, 1 )['dues_note'] );
        $this->assertSame( 1, $this->member( $r, 4 )['rank'] );        // the lone professional is still #1
        $this->assertSame( 12500, $this->member( $r, 4 )['dues_cents'] );
        $this->assertSame( 23500, $r['totals']['total_cents'] );
        $this->assertSame( 4, $r['totals']['billed_members'] );

        // A $0 flat category still reads as "no charge (label)".
        $zero = $this->config();
        foreach ( $zero['categories'] as &$cat ) {
            if ( $cat['key'] === 'law_student' ) {
                $cat['price_cents'] = 0;
            }
        }
        unset( $cat );
        $r0 = MyNJILGA_Pricing_Engine::price( [ $this->contact( 1, 'Lee', 'Law', [ 'law-student' ] ) ], $zero );
        $this->assertSame( 0, $r0['totals']['total_cents'] );
        $this->assertSame( 'Law Student Membership', $r0['members'][0]['dues_note'] );
    }

    /** 10. The assessment is capped at one per person; the first configured qualifier labels it. */
    public function test_assessment_capped_once_with_first_qualifier_label(): void {
        $r = MyNJILGA_Pricing_Engine::price( [
            $this->contact( 1, 'Ann', 'Brown', [ 'professional', 'trustees', 'officer' ] ),
        ], $this->config() );
        $m = $r['members'][0];

        $this->assertSame( 20000, $m['assessment_cents'] );
        $this->assertSame( 'Officer', $m['assessment_qualifier'] ); // officer is listed before trustees
    }

    // -------------------------------------------------------------------------
    // Additional guards
    // -------------------------------------------------------------------------

    /** Ranking is alphabetical by last name, then first name, then contact id — case-insensitive. */
    public function test_ranking_is_deterministic_with_tiebreakers(): void {
        $r = MyNJILGA_Pricing_Engine::price( [
            $this->contact( 30, 'Zoe',  'smith', [ 'professional' ] ),
            $this->contact( 20, 'Adam', 'Smith', [ 'professional' ] ),
            $this->contact( 10, 'Adam', 'Smith', [ 'professional' ] ),
            $this->contact( 40, 'Bob',  'Jones', [ 'professional' ] ),
        ], $this->config() );

        $this->assertSame( [ 40, 10, 20, 30 ], array_column( $r['members'], 'contact_id' ) );
        $this->assertSame( 12500, $this->member( $r, 40 )['dues_cents'] );
        $this->assertSame( 7500, $this->member( $r, 10 )['dues_cents'] );
    }

    /** Untagged contacts: billed as the default category, or listed as an exception when there is none. */
    public function test_untagged_contact_uses_default_category_or_is_flagged(): void {
        $with = MyNJILGA_Pricing_Engine::price( [ $this->contact( 1, 'No', 'Tags', [] ) ], $this->config() );
        $this->assertSame( 'professional', $with['members'][0]['category_key'] );
        $this->assertSame( 12500, $with['members'][0]['dues_cents'] );

        $without = MyNJILGA_Pricing_Engine::price(
            [ $this->contact( 1, 'No', 'Tags', [ 'officer' ] ), $this->contact( 2, 'Zed', 'Zulu', [ 'professional' ] ) ],
            $this->config( [ 'default_category' => '' ] )
        );
        $flagged = $this->member( $without, 1 );
        $this->assertSame( MyNJILGA_Pricing_Engine::UNBILLED_NO_CATEGORY, $flagged['unbilled_reason'] );
        $this->assertSame( 0, $flagged['dues_cents'] );
        $this->assertSame( 0, $flagged['assessment_cents'] ); // unknown membership → nothing billed, even the assessment
        $this->assertSame( 1, $this->member( $without, 2 )['rank'] );
        $this->assertSame( 1, $without['totals']['unbilled_members'] );
    }

    /** Category precedence: first configured category whose tag the contact carries wins. */
    public function test_category_precedence_is_configured_order(): void {
        $r = MyNJILGA_Pricing_Engine::price( [
            $this->contact( 1, 'Pat', 'Roe', [ 'professional', 'past-president' ] ),
        ], $this->config() );
        $m = $r['members'][0];

        $this->assertSame( 'past_president', $m['category_key'] );
        $this->assertSame( 0, $m['dues_cents'] );
        $this->assertSame( 20000, $m['assessment_cents'] );
    }

    /** The category's WordPress role rides along on every priced member (it is what payment grants). */
    public function test_role_propagates_from_category(): void {
        $r = MyNJILGA_Pricing_Engine::price( [
            $this->contact( 1, 'Ann', 'Brown', [ 'professional' ] ),
            $this->contact( 3, 'Lee', 'Law',   [ 'law-student' ] ),
        ], $this->config() );

        $this->assertSame( 'professional', $this->member( $r, 1 )['role'] );
        $this->assertSame( 'professional', $this->member( $r, 3 )['role'] );
    }

    /** Output order is the billing order: ranked, then flat-priced, then uncategorised, then inactive. */
    public function test_output_is_in_billing_order(): void {
        $r = MyNJILGA_Pricing_Engine::price( [
            $this->contact( 1, 'A', 'Inactive', [ 'professional', 'inactive' ] ),
            $this->contact( 2, 'A', 'Nocat',    [] ),
            $this->contact( 3, 'A', 'Exempt',   [ 'past-president' ] ),
            $this->contact( 4, 'Z', 'Paying',   [ 'professional' ] ),
        ], $this->config( [ 'default_category' => '' ] ) );

        $this->assertSame( [ 4, 3, 2, 1 ], array_column( $r['members'], 'contact_id' ) );
    }

    /** The engine never mutates or depends on anything but its arguments. */
    public function test_is_pure_and_idempotent(): void {
        $roster = [ $this->contact( 1, 'Ann', 'Brown', [ 'professional', 'officer' ] ), $this->contact( 2, 'Pat', 'Roe', [ 'past-president' ] ) ];
        $config = $this->config();
        $copyR  = $roster; $copyC = $config;

        $a = MyNJILGA_Pricing_Engine::price( $roster, $config );
        $b = MyNJILGA_Pricing_Engine::price( $roster, $config );

        $this->assertSame( $a, $b );
        $this->assertSame( $copyR, $roster );
        $this->assertSame( $copyC, $config );
    }

    /** totals() agrees with the per-member numbers. */
    public function test_totals_are_consistent(): void {
        $r = MyNJILGA_Pricing_Engine::price( [
            $this->contact( 1, 'Ann', 'Brown', [ 'professional', 'officer' ] ),
            $this->contact( 2, 'Bob', 'Clark', [ 'professional' ] ),
            $this->contact( 3, 'Pat', 'Roe',   [ 'past-president' ] ),
            $this->contact( 4, 'Ida', 'Idle',  [ 'inactive' ] ),
        ], $this->config() );

        $this->assertSame( 20000, $r['totals']['dues_cents'] );
        $this->assertSame( 40000, $r['totals']['assessment_cents'] );
        $this->assertSame( 60000, $r['totals']['total_cents'] );
        $this->assertSame( 3, $r['totals']['billed_members'] );
        $this->assertSame( 1, $r['totals']['unbilled_members'] );
        $this->assertSame( $r['totals'], MyNJILGA_Pricing_Engine::totals( $r['members'] ) );
    }
}
