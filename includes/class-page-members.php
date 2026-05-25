<?php
/**
 * Active Members — contacts carrying the "Dues Paid" tag.
 */
class MyNJILGA_Page_Members {

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Access denied.' );
        }

        echo '<div class="wrap"><h1>Active Members</h1>';

        if ( MyNJILGA_Admin_Menu::require_fluentcrm() ) {
            echo '</div>';
            return;
        }

        if ( MyNJILGA_Tags::id_for( MyNJILGA_Tags::SLUG_DUES_PAID ) === null ) {
            printf(
                '<div class="notice notice-warning"><p>The <strong>Dues Paid</strong> tag does not exist yet. <a href="%s">Open Setup</a> to create it.</p></div></div>',
                esc_url( MyNJILGA_Admin_Menu::url( MyNJILGA_Admin_Menu::SLUG_SETUP ) )
            );
            return;
        }

        $rows = MyNJILGA_Members_Data::get_active_members();

        printf( '<p style="color:#646970">%d member%s with the Dues Paid tag.</p>', count( $rows ), count( $rows ) === 1 ? '' : 's' );

        echo '<table class="widefat striped"><thead><tr>
                <th>Member</th><th>Firm</th><th>Trustee?</th><th>Payment Method</th>
              </tr></thead><tbody>';

        if ( empty( $rows ) ) {
            echo '<tr><td colspan="4" style="color:#999;font-style:italic">No paid members yet.</td></tr>';
        }

        foreach ( $rows as $r ) {
            printf(
                '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                esc_html( $r['member'] ),
                esc_html( $r['firm'] ),
                $r['is_trustee'] ? '<strong style="color:#1d6f42">Yes</strong>' : '<span style="color:#888">No</span>',
                esc_html( $r['payment_method'] )
            );
        }

        echo '</tbody></table></div>';
    }
}
