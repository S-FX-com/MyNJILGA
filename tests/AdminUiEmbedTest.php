<?php
/**
 * MyNJILGA_Admin_UI::embed() — what the FluentCRM profile tabs are wrapped
 * in. Regression guard: the design-system stylesheet is a once-per-request
 * echo, and the helpers a panel calls (stat_cards(), callout()…) each ask
 * for it, so a naive capture ships the 35 KB stylesheet twice in a panel
 * that loads on every tab click.
 */
require_once dirname( __DIR__ ) . '/includes/class-admin-ui.php';

class AdminUiEmbedTest extends NJILGA_TestCase {

    private function styles_count( string $html ): int {
        return substr_count( $html, '<style>' );
    }

    public function testAnEmbeddedPanelCarriesTheStylesheetExactlyOnce(): void {
        $html = MyNJILGA_Admin_UI::embed( static function (): void {
            // What stat_cards() and callout() do first thing.
            MyNJILGA_Admin_UI::styles();
            echo '<p>one</p>';
            MyNJILGA_Admin_UI::styles();
            echo '<p>two</p>';
        } );

        $this->assertSame( 1, $this->styles_count( $html ), 'one stylesheet, however many helpers ask for it' );
        $this->assertTrue( strpos( $html, '.njilga-embed' ) !== false, 'and it is the design system\'s' );
        $this->assertTrue( strpos( $html, '<div class="njilga-ui njilga-embed"><p>one</p><p>two</p></div>' ) !== false, 'the body sits inside the scoping wrapper' );
    }

    public function testTheStylesheetComesBeforeTheMarkupItStyles(): void {
        $html = MyNJILGA_Admin_UI::embed( static function (): void {
            echo '<p>body</p>';
        } );
        $this->assertTrue( strpos( $html, '<style>' ) < strpos( $html, '<p>body</p>' ) );
    }

    /** The panel is injected into another page, so it needs its own copy even if this request already printed one. */
    public function testItStillCarriesTheStylesheetWhenThePageAlreadyPrintedOne(): void {
        ob_start();
        MyNJILGA_Admin_UI::styles(); // The admin page's own copy.
        $page = (string) ob_get_clean();
        $this->assertSame( 1, $this->styles_count( $page ) );

        $html = MyNJILGA_Admin_UI::embed( static function (): void {
            MyNJILGA_Admin_UI::styles();
            echo '<p>x</p>';
        } );
        $this->assertSame( 1, $this->styles_count( $html ) );
    }

    /** Embedding must not change what the rest of the request does. */
    public function testItLeavesThePerRequestStateAsItFoundIt(): void {
        // Already printed → stays printed: a later helper must not print a second copy to the page.
        MyNJILGA_Admin_UI::embed( static function (): void {
            echo 'x';
        } );
        ob_start();
        MyNJILGA_Admin_UI::styles();
        $this->assertSame( '', (string) ob_get_clean(), 'the page\'s own copy is not printed again' );
    }

    /**
     * A panel that throws must not leave its output buffer open: PHP would
     * flush the half-built HTML into the REST response FluentCRM is
     * sending, and break it.
     */
    public function testAnExceptionPropagatesAndLeavesNoBufferOpen(): void {
        $level = ob_get_level();
        try {
            MyNJILGA_Admin_UI::embed( static function (): void {
                echo '<p>half a panel';
                throw new RuntimeException( 'boom' );
            } );
            $this->fail( 'expected the exception to propagate' );
        } catch ( RuntimeException $e ) {
            $this->assertSame( 'boom', $e->getMessage() );
        }
        $this->assertSame( $level, ob_get_level(), 'no output buffer was left open' );

        // …and the per-request state is as it was: nothing printed twice later.
        ob_start();
        MyNJILGA_Admin_UI::styles();
        $this->assertSame( '', (string) ob_get_clean() );
    }

    /**
     * A button rendered as a link must keep readable text inside a host
     * app's page. FluentCRM styles `.fluentcrm-app a:not(.el-button)`, which
     * outranks a bare `.njilga-btn-primary`, so the primary button's text
     * went dark on dark. Each variant needs a rule naming both classes in
     * every state — checked in the stylesheet itself, since a CSS tidy-up
     * that "simplifies" it away would only show up inside FluentCRM.
     */
    public function testEveryLinkButtonVariantOutranksAHostsLinkRule(): void {
        $css = MyNJILGA_Admin_UI::embed( static function (): void {} );
        foreach ( [ 'primary', 'outline', 'ghost', 'danger', 'danger-outline' ] as $variant ) {
            foreach ( [ 'link', 'visited', 'hover', 'focus' ] as $state ) {
                $this->assertTrue(
                    strpos( $css, '.njilga-ui .njilga-btn.njilga-btn-' . $variant . ':' . $state ) !== false,
                    "no host-proof rule for .njilga-btn-$variant:$state"
                );
            }
        }
    }
}
