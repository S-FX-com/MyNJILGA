<?php
/**
 * Trustees — contacts carrying the "Trustees" tag.
 */
class MyNJILGA_Page_Trustees {

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Access denied.' );
        }

        MyNJILGA_Admin_UI::styles();
        echo '<div class="wrap njilga-ui">';
        MyNJILGA_Admin_Menu::render_back_to_reports();
        MyNJILGA_Admin_UI::page_header( 'Trustees', 'Trustees, Senior Trustees and Past Presidents, with dues status and payment method.' );

        if ( MyNJILGA_Admin_Menu::require_fluentcrm() ) {
            MyNJILGA_Admin_UI::close();
            return;
        }

        MyNJILGA_Admin_Menu::render_stats_panel();

        if ( MyNJILGA_Tags::id_for( MyNJILGA_Tags::SLUG_TRUSTEES ) === null ) {
            MyNJILGA_Admin_UI::callout(
                sprintf(
                    'The <strong>Trustees</strong> tag does not exist yet. <a href="%s">Open Setup</a> to create it.',
                    esc_url( MyNJILGA_Admin_Menu::url( MyNJILGA_Admin_Menu::SLUG_SETUP ) )
                ),
                'warning'
            );
            MyNJILGA_Admin_UI::close();
            return;
        }

        $rows = MyNJILGA_Members_Data::get_trustees();

        MyNJILGA_Admin_UI::section( 'Trustees', '', count( $rows ) );
        MyNJILGA_Admin_Menu::render_csv_button( 'trustees', 'Download Trustees CSV' );

        echo '<div class="njilga-card njilga-table-boxed"><div class="njilga-tablewrap"><table class="njilga-table"><thead><tr>
                <th>Name</th><th>Role</th><th>Firm</th><th>Dues Paid?</th><th>Payment Method</th>
              </tr></thead><tbody>';

        if ( empty( $rows ) ) {
            echo '<tr class="njilga-emptyrow"><td colspan="5">No trustees yet.</td></tr>';
        }

        foreach ( $rows as $r ) {
            printf(
                '<tr><td><a href="%s">%s</a></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                esc_url( $r['member_url'] ),
                esc_html( $r['member'] ),
                MyNJILGA_Admin_UI::pill( $r['trustee_status'], 'outline' ),
                esc_html( $r['firm'] ),
                $r['is_paid']
                    ? MyNJILGA_Admin_UI::pill( 'Paid', 'success' )
                    : MyNJILGA_Admin_UI::pill( 'Unpaid', 'destructive' ),
                esc_html( $r['payment_method'] )
            );
        }

        echo '</tbody></table></div></div>';
        MyNJILGA_Admin_UI::close();
    }
}
