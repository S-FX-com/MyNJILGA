<?php
/**
 * Dashboard — summary counts, missing-tag warnings, Excel download.
 */
class MyNJILGA_Page_Dashboard {

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Access denied.' );
        }

        echo '<div class="wrap"><h1>My NJILGA</h1>';

        if ( MyNJILGA_Admin_Menu::require_fluentcrm() ) {
            echo '</div>';
            return;
        }

        self::render_missing_tag_banner();

        $s = MyNJILGA_Members_Data::summary();
        ?>
        <div style="display:grid;grid-template-columns:repeat(3,minmax(160px,1fr));gap:16px;margin:16px 0 24px">
            <?php
            self::stat_card( 'Paid / Active Members', $s['paid'],     MyNJILGA_Admin_Menu::SLUG_MEMBERS );
            self::stat_card( 'Trustees',              $s['trustees'], MyNJILGA_Admin_Menu::SLUG_TRUSTEES );
            self::stat_card( 'Companies with Paid Members', $s['companies_with_paid'], MyNJILGA_Admin_Menu::SLUG_COMPANIES );
            ?>
        </div>

        <h2>Company distribution</h2>
        <ul style="list-style:disc;padding-left:24px">
            <li>1 Paid Member: <strong><?php echo (int) ( $s['bucket_counts']['1'] ?? 0 ); ?></strong></li>
            <li>2–5 Paid Members: <strong><?php echo (int) ( $s['bucket_counts']['2-5'] ?? 0 ); ?></strong></li>
            <li>6+ Paid Members: <strong><?php echo (int) ( $s['bucket_counts']['6+'] ?? 0 ); ?></strong></li>
            <li style="color:#666">No paid members: <strong><?php echo (int) ( $s['bucket_counts']['0'] ?? 0 ); ?></strong></li>
        </ul>

        <h2 style="margin-top:24px">Export</h2>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="my_njilga_export">
            <?php wp_nonce_field( 'my_njilga_export' ); ?>
            <?php submit_button( 'Download Excel Report (.xlsx)', 'primary', 'submit', false ); ?>
            <span style="color:#666;margin-left:8px">Three sheets: Active Members, Trustees, Companies.</span>
        </form>
        </div>
        <?php
    }

    private static function stat_card( string $label, int $value, string $link_slug ): void {
        printf(
            '<a href="%s" style="display:block;padding:16px;background:#fff;border:1px solid #c3c4c7;border-radius:4px;text-decoration:none;color:inherit">
                <div style="font-size:32px;font-weight:600;line-height:1.1">%d</div>
                <div style="color:#646970">%s</div>
             </a>',
            esc_url( MyNJILGA_Admin_Menu::url( $link_slug ) ),
            $value,
            esc_html( $label )
        );
    }

    private static function render_missing_tag_banner(): void {
        $missing = [];
        foreach ( MyNJILGA_Tags::DEFINITIONS as $slug => $def ) {
            if ( ! $def['required'] ) continue;
            if ( MyNJILGA_Tags::id_for( $slug ) === null ) {
                $missing[] = $def['title'];
            }
        }
        if ( ! $missing ) return;

        printf(
            '<div class="notice notice-warning"><p>Required FluentCRM tags missing: <strong>%s</strong>. <a href="%s">Open Setup</a> to create them.</p></div>',
            esc_html( implode( ', ', $missing ) ),
            esc_url( MyNJILGA_Admin_Menu::url( MyNJILGA_Admin_Menu::SLUG_SETUP ) )
        );
    }
}
