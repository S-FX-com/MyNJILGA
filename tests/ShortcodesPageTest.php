<?php
/**
 * The pure parts of My NJILGA → Shortcodes: reading which uses of a
 * shortcode a page's content really holds (the way WordPress reads them),
 * the synced patterns a page inserts, the price line each join category
 * shows, and the ready-to-paste join line.
 */
require_once dirname( __DIR__ ) . '/includes/join/class-join-form.php';
require_once dirname( __DIR__ ) . '/includes/join/class-join-view.php';
require_once dirname( __DIR__ ) . '/includes/class-page-shortcodes.php';

class ShortcodesPageTest extends NJILGA_TestCase {

    public function test_finds_each_use_with_its_attribute_text(): void {
        $content = '<!-- wp:shortcode -->[njilga_join category="law_student"]<!-- /wp:shortcode --> and [njilga_join] and [njilga_join form=student category=professional /]';
        $this->assertSame(
            [ ' category="law_student"', '', ' form=student category=professional ' ],
            MyNJILGA_Page_Shortcodes::find_shortcodes( $content, 'njilga_join' )
        );
    }

    public function test_an_escaped_use_prints_itself_and_does_not_count(): void {
        $this->assertSame( [], MyNJILGA_Page_Shortcodes::find_shortcodes( 'Type [[njilga_join category="professional"]] to show it.', 'njilga_join' ) );
        $this->assertSame( [ '' ], MyNJILGA_Page_Shortcodes::find_shortcodes( '[[njilga_join]] then [njilga_join]', 'njilga_join' ) );
    }

    public function test_a_longer_tag_name_is_another_shortcode(): void {
        $this->assertSame( [], MyNJILGA_Page_Shortcodes::find_shortcodes( '[njilga_join_other] [njilga_join-x]', 'njilga_join' ) );
        $this->assertSame( [], MyNJILGA_Page_Shortcodes::find_shortcodes( 'No shortcode here.', 'njilga_join' ) );
    }

    public function test_pattern_refs_reads_each_inserted_synced_pattern_once(): void {
        $content = '<!-- wp:block {"ref":12} /--><p>x</p><!-- wp:block {"ref":123,"className":"a"} /--><!-- wp:block {"ref":12} /-->';
        $this->assertSame( [ 12, 123 ], MyNJILGA_Page_Shortcodes::pattern_refs( $content ) );
        $this->assertSame( [ 7 ], MyNJILGA_Page_Shortcodes::pattern_refs( '<!-- wp:core/block {"ref": 7} /-->' ) );
        $this->assertSame( [], MyNJILGA_Page_Shortcodes::pattern_refs( '<!-- wp:paragraph --><p>"ref":12</p><!-- /wp:paragraph -->' ), 'a "ref" outside a block comment is just text' );
    }

    public function test_a_flat_category_shows_its_one_price(): void {
        $flat = [ 'key' => 'law_student', 'label' => 'Law Student Membership', 'price_cents' => 3000, 'tier_eligible' => false, 'tiers' => [] ];
        $this->assertSame( '$30', MyNJILGA_Page_Shortcodes::ladder_summary( MyNJILGA_Join_Pricing::ladder( $flat ) ) );
        $this->assertSame( '$12.50', MyNJILGA_Page_Shortcodes::ladder_summary( [ [ 'from' => 1, 'to' => 1, 'price_cents' => 1250, 'label' => '' ] ] ) );
        $this->assertSame( 'Free', MyNJILGA_Page_Shortcodes::ladder_summary( [ [ 'from' => 1, 'to' => 1, 'price_cents' => 0, 'label' => 'X' ] ] ) );
    }

    public function test_a_tiered_category_shows_its_ladder_from_settings(): void {
        $professional = null;
        foreach ( MyNJILGA_Dues_Settings::defaults()['categories'] as $c ) {
            if ( $c['key'] === 'professional' ) {
                $professional = $c;
            }
        }
        $this->assertSame(
            '1st Member $125 · Members 2–5 $75 · Members 6+ free',
            MyNJILGA_Page_Shortcodes::ladder_summary( MyNJILGA_Join_Pricing::ladder( $professional ) )
        );
    }

    public function test_an_unlabelled_tier_is_named_from_its_range(): void {
        $ladder = [
            [ 'from' => 1, 'to' => 1, 'price_cents' => 10000, 'label' => '' ],
            [ 'from' => 2, 'to' => 4, 'price_cents' => 5000, 'label' => ' ' ],
            [ 'from' => 5, 'to' => 0, 'price_cents' => 0, 'label' => '' ],
        ];
        $this->assertSame( 'Member 1 $100 · Members 2–4 $50 · Members 5+ free', MyNJILGA_Page_Shortcodes::ladder_summary( $ladder ) );
    }

    public function test_the_join_line_names_the_category_key(): void {
        $this->assertSame( '[njilga_join category="emerging_professional"]', MyNJILGA_Page_Shortcodes::join_line( 'emerging_professional' ) );
    }
}
