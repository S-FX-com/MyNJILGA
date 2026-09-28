<?php
/**
 * The look the public shortcodes share — [njilga_join], the invite form,
 * [njilga_firm_dues_status] and [njilga_membership_application] — taken
 * from the NJILGA site's own stylesheet: its type (Playfair Display for
 * headings, Helvetica for body text, Inter for eyebrows, labels and
 * buttons) and its Automatic.css colour tokens (--secondary navy,
 * --primary blue, --accent gold, --btn-radius), each with the site's
 * value as the fallback so the forms still look right on a site without
 * Automatic.css.
 *
 * Sizes are px on purpose: Automatic.css sets the root font size to
 * 62.5%, so rem-based sizes would come out at 10px to the rem there.
 */
class MyNJILGA_Front_Style {

    /**
     * Inter isn't on the NJILGA site (it loads Playfair Display itself),
     * so it's loaded here, only on pages that show one of these forms.
     * Filter to '' to rely on the theme instead.
     */
    const FONT_URL = 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap';

    public static function enqueue_fonts(): void {
        $url = (string) apply_filters( 'my_njilga_front_font_url', self::FONT_URL );
        if ( $url !== '' && function_exists( 'wp_enqueue_style' ) ) {
            // Enqueued mid-page (a shortcode renders after wp_head on classic
            // themes) WordPress prints it in the footer instead — still fine.
            wp_enqueue_style( 'njilga-front-fonts', $url, [], null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
        }
    }

    /**
     * The shared custom properties, scoped to $selector.
     */
    public static function tokens( string $selector ): string {
        return $selector . '{'
            . '--nj-navy:var(--secondary,#1b2a41);'
            . '--nj-blue:var(--primary,#1f5493);'
            . '--nj-blue-dark:var(--primary-dark,#163c69);'
            . '--nj-blue-soft:var(--primary-ultra-light,#eaf1fb);'
            . '--nj-gold:var(--accent,#c5a559);'
            . '--nj-gold-soft:var(--accent-ultra-light,#f8f5ec);'
            . '--nj-ink:#1a1a1a;--nj-text:#333;--nj-muted:#5f6368;'
            . '--nj-line:#e3e5e8;--nj-field:#c4c8cd;--nj-soft:#f6f7f9;'
            . '--nj-danger:#b42318;--nj-danger-bg:#fef3f2;--nj-success:#067647;--nj-success-bg:#ecfdf3;--nj-warn:#93370d;--nj-warn-bg:#fffaeb;'
            . '--nj-radius:8px;--nj-radius-sm:var(--btn-radius,4px);'
            . '--nj-font-head:"Playfair Display",Georgia,"Times New Roman",serif;'
            . '--nj-font-body:Helvetica,"Helvetica Neue",Arial,sans-serif;'
            . '--nj-font-ui:Inter,"Helvetica Neue",Helvetica,Arial,sans-serif;'
            . 'font-family:var(--nj-font-body);font-size:16px;line-height:1.6;color:var(--nj-text)'
            . '}';
    }
}
