<?php
/**
 * An officer always owes the assessment on top of their dues: Officer qualifies whatever list of qualifying
 * tags has been saved (MyNJILGA_Dues_Settings::with_officer()).
 */
declare( strict_types=1 );

require_once dirname( __DIR__ ) . '/includes/class-tags.php';

class OfficerAssessmentTest extends NJILGA_TestCase {

    public function testTheOfficerTagIsTheOneTheRestOfThePluginKnows(): void {
        $this->assertSame( MyNJILGA_Tags::SLUG_OFFICER, MyNJILGA_Dues_Settings::OFFICER_TAG );
    }

    public function testASavedListThatLeavesOfficerOutGetsItBackFirst(): void {
        $saved = [ [ 'tag' => 'trustees', 'label' => 'Trustee' ], [ 'tag' => 'past-president', 'label' => 'Past President' ] ];
        $out   = MyNJILGA_Dues_Settings::with_officer( $saved );
        $this->assertSame( [ 'officer', 'trustees', 'past-president' ], array_column( $out, 'tag' ) );
        $this->assertSame( 'Officer', $out[0]['label'] );
    }

    public function testAnEmptyListStillHasOfficer(): void {
        $this->assertSame( [ [ 'tag' => 'officer', 'label' => 'Officer' ] ], MyNJILGA_Dues_Settings::with_officer( [] ) );
    }

    /** Where staff put Officer is kept — the order decides the label a person with two qualifying tags shows. */
    public function testWhereOfficerIsItStays(): void {
        $saved = [ [ 'tag' => 'trustees', 'label' => 'Trustee' ], [ 'tag' => 'officer', 'label' => 'Board Officer' ] ];
        $this->assertSame( $saved, MyNJILGA_Dues_Settings::with_officer( $saved ) );
    }

    public function testItIsIdempotent(): void {
        $once = MyNJILGA_Dues_Settings::with_officer( [ [ 'tag' => 'trustees', 'label' => 'Trustee' ] ] );
        $this->assertSame( $once, MyNJILGA_Dues_Settings::with_officer( $once ) );
    }

    public function testTheShippedDefaultsAlreadyHaveItFirst(): void {
        $this->assertSame( 'officer', MyNJILGA_Dues_Settings::defaults()['assessment']['qualifiers'][0]['tag'] );
    }

    /** End to end through the engine: a saved list without Officer, repaired, bills an officer the assessment on top of dues. */
    public function testAnOfficerIsBilledTheAssessmentOnTopOfDuesEvenFromASavedListWithoutOfficer(): void {
        $d     = MyNJILGA_Dues_Settings::defaults();
        $saved = $d['assessment'];
        $saved['qualifiers'] = [ [ 'tag' => 'trustees', 'label' => 'Trustee' ] ]; // what a saved list that dropped Officer looks like
        $cfg   = [ 'default_category' => $d['general']['default_category'], 'inactive_tag' => $d['general']['inactive_tag'], 'categories' => $d['categories'], 'assessment' => $saved ];
        $who   = [ [ 'contact_id' => 1, 'name' => 'Ann Officer', 'first_name' => 'Ann', 'last_name' => 'Officer', 'email' => 'a@x.test', 'tags' => [ 'professional', 'officer' ] ] ];

        $before = MyNJILGA_Pricing_Engine::price( $who, $cfg )['members'][0];
        $this->assertSame( 0, $before['assessment_cents'], 'the list as saved bills an officer no assessment' );

        $cfg['assessment']['qualifiers'] = MyNJILGA_Dues_Settings::with_officer( $cfg['assessment']['qualifiers'] );
        $after = MyNJILGA_Pricing_Engine::price( $who, $cfg )['members'][0];
        $this->assertSame( [ 12500, 20000, 'Officer' ], [ $after['dues_cents'], $after['assessment_cents'], $after['assessment_qualifier'] ], 'repaired: dues AND the assessment' );
    }
}
