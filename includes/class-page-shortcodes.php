<?php
/**
 * My NJILGA → Shortcodes: every shortcode the plugin provides, what it
 * does, how to paste it, and which pages use it now.
 */
class MyNJILGA_Page_Shortcodes {

    public static function render(): void {
        MyNJILGA_Admin_UI::open( 'Shortcodes', 'Paste these into a page to put a My NJILGA form or view on the site.' );
        MyNJILGA_Admin_UI::close();
    }
}
