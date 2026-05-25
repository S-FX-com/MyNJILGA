<?php
/**
 * Trustees — contacts carrying the "Trustees" tag.
 */
class MyNJILGA_Page_Trustees {

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Access denied.' );
        }

        echo '<div class="wrap"><h1>Trustees</h1>';

        if ( MyNJILGA_Admin_Menu::require_fluentcrm() ) {
            echo '</div>';
            return;
        }

        if ( MyNJILGA_Tags::id_for( MyNJILGA_Tags::SLUG_TRUSTEES ) === null ) {
            printf(
                '<div class="notice notice-warning"><p>The <strong>Trustees</strong> tag does not exist yet. <a href="%s">Open Setup</a> to create it.</p></div></div>',
                esc_url( MyNJILGA_Admin_Menu::url( MyNJILGA_Admin_Menu::SLUG_SETUP ) )
            );
            return;
        }

        $rows = MyNJILGA_Members_Data::get_trustees();

        printf( '<p style="color:#646970">%d trustee%s.</p>', count( $rows ), count( $rows ) === 1 ? '' : 's' );

        echo '<table class="widefat striped"><thead><tr>
                <th>Trustee</th><th>Firm</th><th>Dues Paid?</th><th>Payment Method</th>
              </tr></thead><tbody>';

        if ( empty( $rows ) ) {
            echo '<tr><td colspan="4" style="color:#999;font-style:italic">No trustees yet.</td></tr>';
        }

        foreach ( $rows as $r ) {
            printf(
                '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                esc_html( $r['member'] ),
                esc_html( $r['firm'] ),
                $r['is_paid']
                    ? '<strong style="color:#1d6f42">Paid</strong>'
                    : '<strong style="color:#d63638">Unpaid</strong>',
                esc_html( $r['payment_method'] )
            );
        }

        echo '</tbody></table></div>';
    }
}
