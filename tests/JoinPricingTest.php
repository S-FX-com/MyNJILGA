<?php
/**
 * Unit tests for MyNJILGA_Join_Pricing::price() — what an online join
 * charges the payer and any colleagues they add. Built from the seeded
 * categories so the seed prices themselves are under test.
 */
class JoinPricingTest extends NJILGA_TestCase {

    private function category( string $key ): array {
        foreach ( MyNJILGA_Dues_Settings::defaults()['categories'] as $cat ) {
            if ( $cat['key'] === $key ) {
                return $cat;
            }
        }
        $this->fail( "No seeded category $key" );
        return [];
    }

    private function person( string $first, string $last ): array {
        return [ 'first_name' => $first, 'last_name' => $last, 'email' => strtolower( "$first.$last@example.com" ) ];
    }

    /** The payer alone at a professional join is the 1st member. */
    public function test_professional_alone_is_first_member(): void {
        $r = MyNJILGA_Join_Pricing::price( $this->category( 'professional' ), [ $this->person( 'Zed', 'Zulu' ) ], 2026 );

        $this->assertSame( 12500, $r['totals']['total_cents'] );
        $this->assertSame( 'payer', $r['members'][0]['join_role'] );
        $this->assertSame( '1st Member', $r['members'][0]['tier_label'] );
        $this->assertSame( 'Zed Zulu — 2026 Professional Membership (1st Member)', $r['lines'][0]['title'] );
    }

    /**
     * The upsell: members 2–5 are $75, beyond 5 free — and the PAYER
     * holds the $125 slot even when a colleague's surname sorts first.
     */
    public function test_colleagues_follow_the_firm_ladder_payer_first(): void {
        $people = [ $this->person( 'Zed', 'Zulu' ) ];
        foreach ( [ 'Adams', 'Baker', 'Clark', 'Davis', 'Evans', 'Frank' ] as $last ) {
            $people[] = $this->person( 'X', $last );
        }
        $r = MyNJILGA_Join_Pricing::price( $this->category( 'professional' ), $people, 2026 );

        $this->assertSame( [ 12500, 7500, 7500, 7500, 7500, 0, 0 ], array_column( $r['members'], 'dues_cents' ) );
        $this->assertSame( 'zed.zulu@example.com', $r['members'][0]['email'] );
        $this->assertSame( 'colleague', $r['members'][1]['join_role'] );
        $this->assertSame( 42500, $r['totals']['total_cents'] );
        $this->assertCount( 7, $r['lines'], 'Free colleagues still get a line — the payment covers them.' );
        $this->assertSame( 0, $r['lines'][6]['unit_price_cents'] );
        $this->assertTrue( strpos( $r['lines'][6]['title'], 'no charge' ) !== false );
    }

    /** Flat categories never take colleagues and never add an assessment. */
    public function test_flat_categories_price_the_payer_alone(): void {
        $student = MyNJILGA_Join_Pricing::price( $this->category( 'law_student' ), [ $this->person( 'Sam', 'Lee' ), $this->person( 'Tag', 'Along' ) ], 2026 );
        $this->assertCount( 1, $student['members'] );
        $this->assertSame( 3000, $student['totals']['total_cents'] );

        $emerging = MyNJILGA_Join_Pricing::price( $this->category( 'emerging_professional' ), [ $this->person( 'Em', 'Erg' ) ], 2026 );
        $this->assertSame( 5000, $emerging['totals']['total_cents'] );
        $this->assertSame( 0, $emerging['totals']['assessment_cents'] );
    }

    /** A category priced at $0 (NJILGA may make students free) quotes $0. */
    public function test_free_category_quotes_zero(): void {
        $cat                = $this->category( 'law_student' );
        $cat['price_cents'] = 0;
        $r = MyNJILGA_Join_Pricing::price( $cat, [ $this->person( 'Sam', 'Lee' ) ], 2026 );
        $this->assertSame( 0, $r['totals']['total_cents'] );
        $this->assertSame( '', $r['members'][0]['unbilled_reason'], 'Free is still covered, not an exception.' );
    }

    /** The advertised ladder mirrors the category's tiers. */
    public function test_ladder(): void {
        $this->assertSame( [ 12500, 7500, 0 ], array_column( MyNJILGA_Join_Pricing::ladder( $this->category( 'professional' ) ), 'price_cents' ) );
        $this->assertSame( [ 3000 ], array_column( MyNJILGA_Join_Pricing::ladder( $this->category( 'law_student' ) ), 'price_cents' ) );
    }
}
