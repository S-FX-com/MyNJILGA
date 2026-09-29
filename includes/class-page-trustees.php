<?php
/**
 * Trustees — the whole trustee family (Trustees, Senior Trustees, Past
 * Presidents), each with their dues standing. A Past President or Senior
 * Trustee is dues-exempt and shows an Exempt pill; every other trustee is
 * Paid, Unpaid, or (inactive / no dues on record) a muted pill, so the rows
 * add up to the KPI tiles above them.
 */
class MyNJILGA_Page_Trustees {

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Access denied.' );
        }

        MyNJILGA_Admin_UI::styles();
        echo '<div class="wrap njilga-ui">';
        MyNJILGA_Admin_Menu::render_back_to_reports();
        MyNJILGA_Admin_UI::page_header( 'Trustees', 'Trustees, Senior Trustees and Past Presidents, with dues status (paid, unpaid or exempt) and payment method.' );

        if ( MyNJILGA_Admin_Menu::require_fluentcrm() ) {
            MyNJILGA_Admin_UI::close();
            return;
        }

        // The list below is always read live; refresh the cached figures first
        // so the tiles above it are computed from the very same contacts.
        $stats = MyNJILGA_Membership_Stats::snapshot( true );
        if ( empty( $stats['available'] ) ) {
            MyNJILGA_Admin_UI::callout( esc_html( implode( ' ', array_map( 'strval', (array) $stats['warnings'] ) ) ), 'error' );
            MyNJILGA_Admin_UI::close();
            return;
        }
        MyNJILGA_Admin_Menu::render_stats_panel();

        // The list works from any of the three trustee-family tags; only when
        // none exists is there nothing to show.
        $family = array_filter( array_map( [ 'MyNJILGA_Tags', 'id_for' ], MyNJILGA_Tags::TRUSTEE_SLUGS ) );
        if ( ! $family ) {
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
                <th>Name</th><th>Role</th><th>Firm</th><th>Dues</th><th>Payment Method</th>
              </tr></thead><tbody>';

        if ( empty( $rows ) ) {
            echo '<tr class="njilga-emptyrow"><td colspan="5">No trustees yet.</td></tr>';
        }

        foreach ( $rows as $r ) {
            [ $dues_label, $dues_variant ] = MyNJILGA_Members_Data::dues_pill( $r['state'], $r['is_exempt'] );
            printf(
                '<tr><td><a href="%s">%s</a></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                esc_url( $r['member_url'] ),
                esc_html( $r['member'] ),
                MyNJILGA_Admin_UI::pill( $r['trustee_status'], 'outline' ),
                esc_html( $r['firm'] ),
                MyNJILGA_Admin_UI::pill( $dues_label, $dues_variant ),
                esc_html( $r['payment_method'] )
            );
        }

        echo '</tbody></table></div></div>';
        MyNJILGA_Admin_UI::close();
    }
}
