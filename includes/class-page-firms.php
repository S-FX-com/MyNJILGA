<?php
/**
 * Membership by Firm — every FluentCRM Company with at least one attached
 * contact, listed alphabetically, with its contacts grouped underneath.
 *
 * Each firm is a section heading over its own contacts table carrying
 * First Name, Last Name, Email, Dues, Trustees, Past President, and
 * Payment columns. The export reproduces the same grouping as an .xls.
 */
class MyNJILGA_Page_Firms {

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Access denied.' );
        }

        MyNJILGA_Admin_UI::styles();
        echo '<div class="wrap njilga-ui">';
        MyNJILGA_Admin_Menu::render_back_to_reports();
        MyNJILGA_Admin_UI::page_header( 'Membership by Firm', 'Every firm with its attached contacts, dues status and roles.' );

        if ( MyNJILGA_Admin_Menu::require_fluentcrm() ) {
            MyNJILGA_Admin_UI::close();
            return;
        }

        if ( ! MyNJILGA_Members_Data::companies_module_active() ) {
            MyNJILGA_Admin_UI::callout( 'The FluentCRM <strong>Companies</strong> module is not active on this site. Enable it under FluentCRM → Settings → Modules.', 'warning' );
            MyNJILGA_Admin_UI::close();
            return;
        }

        MyNJILGA_Admin_Menu::render_firm_overview_panel();

        $scope = ( ( $_GET['scope'] ?? '' ) === 'active' ) ? 'active' : 'all';
        self::render_scope_tabs( $scope );

        $firms = MyNJILGA_Members_Data::get_membership_by_firm( $scope );

        printf(
            '<p class="njilga-section-desc">%s</p>',
            $scope === 'active'
                ? sprintf( '%d firm%s with at least one active (Dues Paid) member — only active members are shown.', count( $firms ), count( $firms ) === 1 ? '' : 's' )
                : sprintf( '%d firm%s with at least one attached FluentCRM contact, listed alphabetically.', count( $firms ), count( $firms ) === 1 ? '' : 's' )
        );

        MyNJILGA_Admin_Menu::render_firms_export_button( $scope );

        if ( empty( $firms ) ) {
            MyNJILGA_Admin_UI::callout(
                $scope === 'active' ? 'No firms with active members yet.' : 'No firms with attached contacts yet.',
                'info'
            );
            MyNJILGA_Admin_UI::close();
            return;
        }

        foreach ( $firms as $firm ) {
            MyNJILGA_Admin_UI::section( $firm['name'], '', count( $firm['contacts'] ) );

            echo '<div class="njilga-card njilga-table-boxed"><div class="njilga-tablewrap"><table class="njilga-table"><thead><tr>
                    <th>First Name</th><th>Last Name</th><th>Email</th>
                    <th>Dues</th><th>Trustees</th><th>Past President</th><th>Payment</th>
                  </tr></thead><tbody>';

            foreach ( $firm['contacts'] as $c ) {
                printf(
                    '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                    esc_html( $c['first_name'] ),
                    esc_html( $c['last_name'] ),
                    esc_html( $c['email'] ),
                    self::dues_cell( $c['dues'] ),
                    self::cell( $c['trustees'] ),
                    self::cell( $c['past_president'] ),
                    self::cell( $c['payment'] )
                );
            }

            echo '</tbody></table></div></div>';
        }

        MyNJILGA_Admin_UI::close();
    }

    /**
     * Two-variation switcher: "All Membership" vs "Active Membership Only".
     */
    private static function render_scope_tabs( string $scope ): void {
        $tabs = [];
        foreach ( [ 'all' => 'All Membership', 'active' => 'Active Membership Only' ] as $key => $label ) {
            $tabs[] = [
                'label'  => $label,
                'url'    => add_query_arg(
                    [ 'page' => MyNJILGA_Admin_Menu::SLUG_FIRMS, 'scope' => $key ],
                    admin_url( 'admin.php' )
                ),
                'active' => $scope === $key,
            ];
        }
        echo '<div class="njilga-tabs njilga-tabs-bare">';
        foreach ( $tabs as $t ) {
            printf(
                '<a class="njilga-tab%s" href="%s">%s</a>',
                $t['active'] ? ' active' : '',
                esc_url( $t['url'] ),
                esc_html( $t['label'] )
            );
        }
        echo '</div>';
    }

    /**
     * Renders a value, or a muted em-dash placeholder when blank, so empty
     * cells stay visually distinct from populated ones on screen.
     */
    private static function cell( string $value ): string {
        return $value !== '' ? esc_html( $value ) : MyNJILGA_Admin_UI::blank();
    }

    /**
     * Dues column: a green pill for "Dues Paid", red for "Unpaid Dues",
     * else the muted placeholder.
     */
    private static function dues_cell( string $dues ): string {
        $variant = MyNJILGA_Tags::dues_variant( $dues );
        return $variant !== ''
            ? MyNJILGA_Admin_UI::pill( $dues, $variant )
            : self::cell( $dues );
    }
}
