<?php
/**
 * Active Paid Members — contacts carrying the "Dues Paid" tag.
 */
class MyNJILGA_Page_Members {

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Access denied.' );
        }

        MyNJILGA_Admin_UI::styles();
        echo '<div class="wrap njilga-ui">';
        MyNJILGA_Admin_Menu::render_back_to_reports();
        MyNJILGA_Admin_UI::page_header( 'Active Paid Members', 'Every contact carrying the Dues Paid tag, with firm, trustee role and payment method.' );

        if ( MyNJILGA_Admin_Menu::require_fluentcrm() ) {
            MyNJILGA_Admin_UI::close();
            return;
        }

        MyNJILGA_Admin_Menu::render_stats_panel();

        if ( MyNJILGA_Tags::id_for( MyNJILGA_Tags::SLUG_DUES_PAID ) === null ) {
            MyNJILGA_Admin_UI::callout(
                sprintf(
                    'The <strong>Dues Paid</strong> tag does not exist yet. <a href="%s">Open Setup</a> to create it.',
                    esc_url( MyNJILGA_Admin_Menu::url( MyNJILGA_Admin_Menu::SLUG_SETUP ) )
                ),
                'warning'
            );
            MyNJILGA_Admin_UI::close();
            return;
        }

        $rows = MyNJILGA_Members_Data::get_active_members();

        MyNJILGA_Admin_UI::section( 'Members', '', count( $rows ) );
        MyNJILGA_Admin_Menu::render_csv_button( 'members', 'Download Active Paid Members CSV' );

        echo '<div class="njilga-card njilga-table-boxed"><div class="njilga-tablewrap"><table class="njilga-table"><thead><tr>
                <th>Member</th><th>Email</th><th>Firm</th><th>Trustee</th><th>Payment Method</th><th>Paid</th>
              </tr></thead><tbody>';

        if ( empty( $rows ) ) {
            echo '<tr class="njilga-emptyrow"><td colspan="6">No paid members yet.</td></tr>';
        }

        foreach ( $rows as $r ) {
            printf(
                '<tr><td><a href="%s">%s</a></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                esc_url( $r['member_url'] ),
                esc_html( $r['member'] ),
                esc_html( $r['email'] ),
                esc_html( $r['firm'] ),
                $r['trustee_status'] !== ''
                    ? MyNJILGA_Admin_UI::status( $r['trustee_status'], 'ok' )
                    : MyNJILGA_Admin_UI::blank(),
                esc_html( $r['payment_method'] ),
                MyNJILGA_Admin_UI::pill( 'Paid', 'success' )
            );
        }

        echo '</tbody></table></div></div>';
        MyNJILGA_Admin_UI::close();
    }
}
