<?php
/**
 * Active Paid Members — every contact paid through this dues year or later,
 * whatever their FluentCRM email-subscription status. "Active" is decided by
 * MyNJILGA_Membership_Stats, the same rule the Dashboard, the KPI tiles and
 * the member-facing My Membership page use.
 */
class MyNJILGA_Page_Members {

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Access denied.' );
        }

        MyNJILGA_Admin_UI::styles();
        echo '<div class="wrap njilga-ui">';
        MyNJILGA_Admin_Menu::render_back_to_reports();
        MyNJILGA_Admin_UI::page_header( 'Active Paid Members', 'Every contact paid through this year or later — independent of email-subscription status — with firm, trustee role and payment method.' );

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

        // Members are recognised by "Dues Paid {year}" tags and by the paid tag
        // in Dues & Billing settings. A missing paid tag only loses the members
        // paid before invoicing (no year tag), so it warns instead of stopping.
        if ( MyNJILGA_Tags::resolve_slug( (string) MyNJILGA_Dues_Settings::general( 'paid_tag', MyNJILGA_Tags::SLUG_DUES_PAID ) ) === null ) {
            MyNJILGA_Admin_UI::callout(
                sprintf(
                    'The <strong>Dues Paid</strong> tag does not exist yet, so members paid before invoicing (with no year tag) cannot be recognised. <a href="%s">Open Setup</a> to create it.',
                    esc_url( MyNJILGA_Admin_Menu::url( MyNJILGA_Admin_Menu::SLUG_SETUP ) )
                ),
                'warning'
            );
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
