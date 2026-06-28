<?php
/**
 * Membership by Firm — every FluentCRM Company with at least one attached
 * contact, listed alphabetically, with its contacts grouped underneath.
 *
 * The firm name is a bold heading; the contacts table carries First Name,
 * Last Name, Email, Dues, Trustees, Past President, and Payment columns.
 * The export reproduces the same grouping with formatting as an .xls.
 */
class MyNJILGA_Page_Firms {

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Access denied.' );
        }

        echo '<div class="wrap"><h1>Membership by Firm</h1>';

        if ( MyNJILGA_Admin_Menu::require_fluentcrm() ) {
            echo '</div>';
            return;
        }

        if ( ! MyNJILGA_Members_Data::companies_module_active() ) {
            echo '<div class="notice notice-warning"><p>The FluentCRM <strong>Companies</strong> module is not active on this site. Enable it under FluentCRM → Settings → Modules.</p></div></div>';
            return;
        }

        MyNJILGA_Admin_Menu::render_back_to_reports();
        MyNJILGA_Admin_Menu::render_firm_overview_panel();

        $scope = ( ( $_GET['scope'] ?? '' ) === 'active' ) ? 'active' : 'all';
        self::render_scope_tabs( $scope );

        $firms = MyNJILGA_Members_Data::get_membership_by_firm( $scope );

        if ( $scope === 'active' ) {
            printf(
                '<p style="color:#646970">%d firm%s with at least one active (Dues Paid) member — only active members are shown.</p>',
                count( $firms ),
                count( $firms ) === 1 ? '' : 's'
            );
        } else {
            printf(
                '<p style="color:#646970">%d firm%s with at least one attached FluentCRM contact, listed alphabetically.</p>',
                count( $firms ),
                count( $firms ) === 1 ? '' : 's'
            );
        }

        MyNJILGA_Admin_Menu::render_firms_export_button( $scope );

        if ( empty( $firms ) ) {
            $msg = $scope === 'active'
                ? 'No firms with active members yet.'
                : 'No firms with attached contacts yet.';
            echo '<p style="color:#999;font-style:italic">' . esc_html( $msg ) . '</p></div>';
            return;
        }

        foreach ( $firms as $firm ) {
            printf(
                '<h2 style="margin-top:28px;font-weight:700">%s <span style="color:#888;font-weight:400;font-size:13px">(%d)</span></h2>',
                esc_html( $firm['name'] ),
                count( $firm['contacts'] )
            );

            echo '<table class="widefat striped"><thead><tr>
                    <th>First Name</th><th>Last Name</th><th>Email</th>
                    <th>Dues</th><th>Trustees</th><th>Past President</th><th>Payment</th>
                  </tr></thead><tbody>';

            foreach ( $firm['contacts'] as $c ) {
                printf(
                    '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                    esc_html( $c['first_name'] ),
                    esc_html( $c['last_name'] ),
                    esc_html( $c['email'] ),
                    self::cell( $c['dues'] ),
                    self::cell( $c['trustees'] ),
                    self::cell( $c['past_president'] ),
                    self::cell( $c['payment'] )
                );
            }

            echo '</tbody></table>';
        }

        echo '</div>';
    }

    /**
     * Two-variation switcher: "All Membership" vs "Active Membership Only".
     * Renders as a pair of nav tabs; the active scope is highlighted.
     */
    private static function render_scope_tabs( string $scope ): void {
        $tabs = [
            'all'    => 'All Membership',
            'active' => 'Active Membership Only',
        ];

        echo '<h2 class="nav-tab-wrapper" style="margin-bottom:16px">';
        foreach ( $tabs as $key => $label ) {
            $url = add_query_arg(
                [ 'page' => MyNJILGA_Admin_Menu::SLUG_FIRMS, 'scope' => $key ],
                admin_url( 'admin.php' )
            );
            printf(
                '<a href="%s" class="nav-tab%s">%s</a>',
                esc_url( $url ),
                $scope === $key ? ' nav-tab-active' : '',
                esc_html( $label )
            );
        }
        echo '</h2>';
    }

    /**
     * Renders a value, or a muted em-dash placeholder when blank, so empty
     * cells stay visually distinct from populated ones on screen.
     */
    private static function cell( string $value ): string {
        return $value !== ''
            ? esc_html( $value )
            : '<span style="color:#bbb">—</span>';
    }
}
