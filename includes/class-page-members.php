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

        MyNJILGA_Admin_Menu::render_back_to_reports();
        MyNJILGA_Admin_Menu::render_stats_panel();

        if ( MyNJILGA_Tags::id_for( MyNJILGA_Tags::SLUG_DUES_PAID ) === null ) {
            printf(
                '<div class="notice notice-warning"><p>The <strong>Dues Paid</strong> tag does not exist yet. <a href="%s">Open Setup</a> to create it.</p></div></div>',
                esc_url( MyNJILGA_Admin_Menu::url( MyNJILGA_Admin_Menu::SLUG_SETUP ) )
            );
            return;
        }

        $rows = MyNJILGA_Members_Data::get_active_members();

        printf( '<p style="color:#646970">%d member%s with the Dues Paid tag.</p>', count( $rows ), count( $rows ) === 1 ? '' : 's' );

        MyNJILGA_Admin_Menu::render_csv_button( 'members', 'Download Active Members CSV' );

        echo '<table class="widefat striped"><thead><tr>
                <th>Member</th><th>Firm</th><th>Trustee</th><th>Payment Method</th>
              </tr></thead><tbody>';

        if ( empty( $rows ) ) {
            echo '<tr><td colspan="4" style="color:#999;font-style:italic">No paid members yet.</td></tr>';
        }

        foreach ( $rows as $r ) {
            printf(
                '<tr><td><a href="%s">%s</a></td><td>%s</td><td>%s</td><td>%s</td></tr>',
                esc_url( $r['member_url'] ),
                esc_html( $r['member'] ),
                esc_html( $r['firm'] ),
                $r['trustee_status'] !== ''
                    ? '<strong style="color:#1d6f42">' . esc_html( $r['trustee_status'] ) . '</strong>'
                    : '<span style="color:#888">—</span>',
                esc_html( $r['payment_method'] )
            );
        }

        echo '</tbody></table></div>';
    }
}
