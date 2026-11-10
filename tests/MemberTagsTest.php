<?php
/**
 * The "Member tag" of a membership category (MyNJILGA_Member_Tags): what a
 * member is given when they pay, what is taken away, and which tags may not
 * be used because pricing already reads them.
 */
declare( strict_types=1 );

require_once dirname( __DIR__ ) . '/includes/invoicing/class-member-tags.php';

class MemberTagsTest extends NJILGA_TestCase {

    /** The seeded categories, in Order, with member tags the way a site might set them. */
    private function categories( array $memberTags = [] ): array {
        $base = [
            [ 'key' => 'past_president', 'tag' => 'past-president', 'role' => 'professional' ],
            [ 'key' => 'senior_trustee', 'tag' => 'senior-trustee', 'role' => 'professional' ],
            [ 'key' => 'law_student', 'tag' => 'law-student', 'role' => 'student' ],
            [ 'key' => 'emerging_professional', 'tag' => 'emerging-professional', 'role' => 'emerging' ],
            [ 'key' => 'professional', 'tag' => 'professional', 'role' => 'professional' ],
        ];
        foreach ( $base as $i => $c ) {
            $base[ $i ]['member_tag'] = $memberTags[ $c['key'] ] ?? '';
        }
        return $base;
    }

    private function sample(): array {
        return $this->categories( [
            'past_president'        => 'professional-member',
            'senior_trustee'        => 'professional-member',
            'law_student'           => 'student-member',
            'emerging_professional' => 'emerging-member',
            'professional'          => 'professional-member',
        ] );
    }

    // ----- which category -----------------------------------------------------

    public function testTheFirstCategoryInOrderDecides(): void {
        // Holds both the Past President and Professional tags: Past President is listed first.
        $cat = MyNJILGA_Member_Tags::category_for( $this->sample(), [ 'past-president', 'professional' ], 'professional' );
        $this->assertSame( 'past_president', $cat['key'] );
    }

    public function testNoCategoryTagFallsBackToTheDefaultCategory(): void {
        $cat = MyNJILGA_Member_Tags::category_for( $this->sample(), [], 'professional' );
        $this->assertSame( 'professional', $cat['key'] );
    }

    public function testNoCategoryTagAndNoDefaultIsNoCategory(): void {
        $this->assertSame( null, MyNJILGA_Member_Tags::category_for( $this->sample(), [], '' ) );
        $this->assertSame( null, MyNJILGA_Member_Tags::category_for( $this->sample(), [], 'no_such_key' ) );
    }

    // ----- the plan -----------------------------------------------------------

    public function testAPaidProfessionalGetsTheProfessionalMemberTagAndLosesTheOthers(): void {
        $plan = MyNJILGA_Member_Tags::plan( $this->sample(), [ 'professional' ], 'professional' );
        $this->assertSame( 'professional-member', $plan['add'] );
        $this->assertSame( [ 'student-member', 'emerging-member' ], $plan['remove'] );
    }

    /** Past President, Senior Trustee and Professional all map to ONE tag: it is added, never also removed. */
    public function testCategoriesSharingAMemberTagNeverRemoveIt(): void {
        $plan = MyNJILGA_Member_Tags::plan( $this->sample(), [ 'senior-trustee' ], 'professional' );
        $this->assertSame( 'professional-member', $plan['add'] );
        $this->assertFalse( in_array( 'professional-member', $plan['remove'], true ) );
    }

    /** A student who joins as a Professional next year must not keep the student tag as well. */
    public function testChangingCategorySwapsTheTag(): void {
        $asStudent      = MyNJILGA_Member_Tags::plan( $this->sample(), [ 'law-student' ], 'professional' );
        $nowProfessional = MyNJILGA_Member_Tags::plan( $this->sample(), [ 'professional' ], 'professional' );
        $this->assertSame( 'student-member', $asStudent['add'] );
        $this->assertTrue( in_array( 'professional-member', $asStudent['remove'], true ) );
        $this->assertSame( 'professional-member', $nowProfessional['add'] );
        $this->assertTrue( in_array( 'student-member', $nowProfessional['remove'], true ) );
    }

    public function testACategoryWithoutAMemberTagAddsNothingAndStillClearsTheOthers(): void {
        $cats = $this->categories( [ 'professional' => 'professional-member', 'law_student' => '' ] );
        $plan = MyNJILGA_Member_Tags::plan( $cats, [ 'law-student' ], 'professional' );
        $this->assertSame( '', $plan['add'] );
        $this->assertSame( [ 'professional-member' ], $plan['remove'] );
    }

    /** The default: nothing configured means nothing happens at all. */
    public function testNothingConfiguredMeansNothingToDo(): void {
        $plan = MyNJILGA_Member_Tags::plan( $this->categories(), [ 'professional' ], 'professional' );
        $this->assertSame( [ 'add' => '', 'remove' => [] ], $plan );
        $this->assertSame( [], MyNJILGA_Member_Tags::managed( $this->categories() ) );
    }

    public function testManagedListsEachConfiguredTagOnce(): void {
        $this->assertSame( [ 'professional-member', 'student-member', 'emerging-member' ], MyNJILGA_Member_Tags::managed( $this->sample() ) );
    }

    // ----- tags that may NOT be member tags -----------------------------------

    private function general(): array {
        return [ 'paid_tag' => 'dues-paid', 'unpaid_tag' => 'unpaid-dues', 'inactive_tag' => 'inactive', 'pending_tag' => 'pending-approval', 'rejected_tag' => 'application-rejected', 'join_prelaw_tag' => 'pre-law' ];
    }

    private function qualifiers(): array {
        return [ [ 'tag' => 'officer' ], [ 'tag' => 'trustees' ], [ 'tag' => 'senior-trustee' ], [ 'tag' => 'past-president' ] ];
    }

    public function testReservedTagsAreEverythingPricingOrBillingAlreadyReads(): void {
        $reserved = MyNJILGA_Member_Tags::reserved_slugs( $this->sample(), $this->general(), $this->qualifiers() );
        foreach ( [ 'professional', 'law-student', 'emerging-professional', 'past-president', 'senior-trustee', 'dues-paid', 'unpaid-dues', 'inactive', 'pending-approval', 'application-rejected', 'pre-law', 'officer', 'trustees' ] as $slug ) {
            $this->assertTrue( in_array( $slug, $reserved, true ), "$slug is reserved" );
        }
        $this->assertFalse( in_array( 'professional-member', $reserved, true ) );
    }

    /**
     * The point of the guard: picking `trustees` (an assessment qualifier) as a
     * member tag would hand every payer the dinner assessment next year; picking
     * the category's own tag would re-categorise everyone. Both are refused and
     * reported, the rest of the row's settings untouched.
     */
    public function testReservedMemberTagsAreRefusedAndBlanked(): void {
        $cats = $this->categories( [ 'professional' => 'trustees', 'law_student' => 'professional', 'emerging_professional' => 'emerging-member' ] );
        $out  = MyNJILGA_Member_Tags::screen( $cats, MyNJILGA_Member_Tags::reserved_slugs( $cats, $this->general(), $this->qualifiers() ) );

        $rejected = $out['rejected'];
        sort( $rejected );
        $this->assertSame( [ 'professional', 'trustees' ], $rejected );
        $byKey = array_column( $out['categories'], 'member_tag', 'key' );
        $this->assertSame( '', $byKey['professional'] );
        $this->assertSame( '', $byKey['law_student'] );
        $this->assertSame( 'emerging-member', $byKey['emerging_professional'], 'a good one is kept' );
        $this->assertSame( 'professional', array_column( $out['categories'], 'tag', 'key' )['professional'], 'the category tag itself is never touched' );
    }

    public function testAGoodConfigurationPassesUntouched(): void {
        $cats = $this->sample();
        $out  = MyNJILGA_Member_Tags::screen( $cats, MyNJILGA_Member_Tags::reserved_slugs( $cats, $this->general(), $this->qualifiers() ) );
        $this->assertSame( [], $out['rejected'] );
        $this->assertSame( $cats, $out['categories'] );
    }

    // ----- a category's OWN tag as its member tag -------------------------------

    /** Every category's member tag is its own tag: "make sure it is on them after they pay". */
    private function ownTags(): array {
        return $this->categories( [
            'past_president'        => 'past-president',
            'senior_trustee'        => 'senior-trustee',
            'law_student'           => 'law-student',
            'emerging_professional' => 'emerging-professional',
            'professional'          => 'professional',
        ] );
    }

    private function protected_for( array $cats ): array {
        return MyNJILGA_Member_Tags::reserved_slugs( $cats, $this->general(), $this->qualifiers() );
    }

    /**
     * What was typed into Settings: each category's own tag in its own row.
     * That is accepted — including the two that are also assessment
     * qualifiers (senior-trustee, past-president) — because granting a
     * category its OWN tag cannot change what anyone is charged.
     */
    public function testACategorysOwnTagIsAccepted(): void {
        $cats = $this->ownTags();
        $out  = MyNJILGA_Member_Tags::screen( $cats, $this->protected_for( $cats ) );
        $this->assertSame( [], $out['rejected'] );
        $this->assertSame( $cats, $out['categories'] );
    }

    /** ...but another category's tag is still refused, even when the row's own tag is fine elsewhere. */
    public function testAnotherCategorysTagIsStillRefused(): void {
        $cats = $this->ownTags();
        $cats[2]['member_tag'] = 'professional';        // Law Student asking to hand out Professional
        $cats[4]['member_tag'] = 'senior-trustee';      // Professional asking to hand out Senior Trustee (exempt + assessment!)
        $out  = MyNJILGA_Member_Tags::screen( $cats, $this->protected_for( $cats ) );
        $rejected = $out['rejected'];
        sort( $rejected );
        $this->assertSame( [ 'professional', 'senior-trustee' ], $rejected );
        $byKey = array_column( $out['categories'], 'member_tag', 'key' );
        $this->assertSame( '', $byKey['law_student'] );
        $this->assertSame( '', $byKey['professional'] );
        $this->assertSame( 'past-president', $byKey['past_president'], 'the rows that were fine are untouched' );
    }

    /** Granting the category's own tag adds it and takes NOTHING away. */
    public function testOwnTagsAreAddedAndNeverSwappedAway(): void {
        $cats = $this->ownTags();
        // Holds Past President AND Professional: Past President (first in Order) decides.
        $plan = MyNJILGA_Member_Tags::plan( $cats, [ 'past-president' ], 'professional', $this->protected_for( $cats ) );
        $this->assertSame( 'past-president', $plan['add'] );
        $this->assertSame( [], $plan['remove'], 'the other categories\' tags are pricing inputs, not ours to remove' );
    }

    /** An untagged contact billed under the default category gets that category's tag after paying. */
    public function testAnUntaggedContactIsGivenTheDefaultCategorysTag(): void {
        $cats = $this->ownTags();
        $plan = MyNJILGA_Member_Tags::plan( $cats, [], 'professional', $this->protected_for( $cats ) );
        $this->assertSame( 'professional', $plan['add'] );
        $this->assertSame( [], $plan['remove'] );
    }

    /**
     * The downgrade sweep: with own tags as member tags there is nothing it may
     * remove. Taking `law-student` off a lapsed student would bill them as the
     * default category next year; taking `past-president` off would end their
     * exemption.
     */
    public function testTheSweepNeverRemovesPricingTags(): void {
        $cats = $this->ownTags();
        $this->assertSame( [], MyNJILGA_Member_Tags::removable( $cats, $this->protected_for( $cats ) ) );
    }

    /** A mix: separate "member" tags are still swapped and swept; the pricing ones are not. */
    public function testSeparateMemberTagsAreStillSwappedWhileOwnTagsAreKept(): void {
        $cats = $this->categories( [ 'professional' => 'professional', 'law_student' => 'student-member' ] );
        $prot = $this->protected_for( $cats );
        $this->assertSame( [ 'student-member' ], MyNJILGA_Member_Tags::removable( $cats, $prot ) );

        $asProfessional = MyNJILGA_Member_Tags::plan( $cats, [ 'professional' ], 'professional', $prot );
        $this->assertSame( 'professional', $asProfessional['add'] );
        $this->assertSame( [ 'student-member' ], $asProfessional['remove'] );

        $asStudent = MyNJILGA_Member_Tags::plan( $cats, [ 'law-student' ], 'professional', $prot );
        $this->assertSame( 'student-member', $asStudent['add'] );
        $this->assertSame( [], $asStudent['remove'], '`professional` is a pricing tag: never removed' );
    }
}
