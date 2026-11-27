<?php
/**
 * Reports — the landing page that gathers every My NJILGA report behind a
 * single menu item. Renders the cross-report KPI dashboard, then a grid of
 * cards that each click through to a report. The individual reports are no
 * longer listed in the admin menu (see MyNJILGA_Admin_Menu::register).
 */
class MyNJILGA_Page_Reports {

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Access denied.' );
        }

        $year = MyNJILGA_Report_Year::from_request();

        MyNJILGA_Admin_UI::open(
            'Reports',
            'Membership KPIs across every report, with per-report CSV and Excel exports.'
        );

        if ( MyNJILGA_Admin_Menu::require_fluentcrm() ) {
            MyNJILGA_Admin_UI::close();
            return;
        }

        $why = MyNJILGA_Members_Data::unavailable_reason( false, $year );
        if ( $why !== '' ) {
            MyNJILGA_Admin_UI::callout( esc_html( $why ), 'error' );
            MyNJILGA_Admin_UI::close();
            return;
        }

        MyNJILGA_Report_Year::render_picker( MyNJILGA_Admin_Menu::SLUG_REPORTS, $year );
        MyNJILGA_Admin_Menu::render_stats_panel( $year );

        printf(
            '<div class="njilga-banner"><div>
                <div class="njilga-banner-title">Executive Summary — %1$d</div>
                <div class="njilga-banner-desc">One Excel file combining every report below for %1$d dues — overview KPIs, active paid members, trustees, companies, and membership by firm. Preview it first, just below.</div>
              </div>',
            $year
        );
        MyNJILGA_Admin_Menu::render_summary_export_button( sprintf( 'Download %d Executive Summary (Excel)', $year ), $year );
        echo '</div>';

        $cards = [
            [
                'title' => 'Active Paid Members',
                'desc'  => sprintf( 'Every contact paid through %d or later — independent of email-subscription status — with firm, email, trustee role, and payment method.', $year ),
                'icon'  => 'check-circle',
                'url'   => MyNJILGA_Admin_Menu::url( MyNJILGA_Admin_Menu::SLUG_MEMBERS ),
            ],
            [
                'title' => 'Trustees',
                'desc'  => 'Trustees, Senior Trustees, and Past Presidents, with dues status (paid, unpaid or exempt) and payment method.',
                'icon'  => 'award',
                'url'   => MyNJILGA_Admin_Menu::url( MyNJILGA_Admin_Menu::SLUG_TRUSTEES ),
            ],
            [
                'title' => 'Companies',
                'desc'  => sprintf( 'Firms bucketed by how many active members (paid through %d or later) they have — 1 / 2–5 / 6+ / none.', $year ),
                'icon'  => 'building',
                'url'   => MyNJILGA_Admin_Menu::url( MyNJILGA_Admin_Menu::SLUG_COMPANIES ),
            ],
            [
                'title' => 'Membership by Firm — All Membership',
                'desc'  => 'Every firm with at least one contact, grouped, with each contact’s dues and roles. Exports to Excel.',
                'icon'  => 'users',
                'url'   => add_query_arg( 'scope', 'all', MyNJILGA_Admin_Menu::url( MyNJILGA_Admin_Menu::SLUG_FIRMS ) ),
            ],
            [
                'title' => 'Membership by Firm — Active Membership Only',
                'desc'  => sprintf( 'Only firms that have active members (paid through %d or later), showing just those active members. Exports to Excel.', $year ),
                'icon'  => 'users',
                'url'   => add_query_arg( 'scope', 'active', MyNJILGA_Admin_Menu::url( MyNJILGA_Admin_Menu::SLUG_FIRMS ) ),
            ],
        ];

        echo '<div class="njilga-linkcards">';
        foreach ( $cards as $card ) {
            printf(
                '<a class="njilga-linkcard" href="%s"><span class="njilga-linkcard-icon">%s</span><span><span class="njilga-linkcard-title">%s &rarr;</span><span class="njilga-linkcard-desc">%s</span></span></a>',
                esc_url( add_query_arg( MyNJILGA_Report_Year::PARAM, $year, $card['url'] ) ),
                MyNJILGA_Admin_UI::icon( $card['icon'] ),
                esc_html( $card['title'] ),
                esc_html( $card['desc'] )
            );
        }
        echo '</div>';

        self::render_preview( $year );

        MyNJILGA_Admin_UI::close();
    }

    /**
     * What the Executive Summary for $year will hold: each sheet's row count
     * and its first rows, with a link to the whole list on its own page
     * (every report page shows its full data above its download button).
     * Built from the same rows the export streams.
     */
    private static function render_preview( int $year ): void {
        $firmsAvailable = MyNJILGA_Members_Data::companies_module_active();
        $sheets         = MyNJILGA_Report_Preview::sheets(
            MyNJILGA_Members_Data::get_active_members( $year ),
            MyNJILGA_Members_Data::get_trustees( $year ),
            MyNJILGA_Members_Data::get_companies_bucketed( $year ),
            MyNJILGA_Members_Data::get_membership_by_firm( 'all', $year )
        );
        $pages = [
            'members'   => MyNJILGA_Admin_Menu::SLUG_MEMBERS,
            'trustees'  => MyNJILGA_Admin_Menu::SLUG_TRUSTEES,
            'companies' => MyNJILGA_Admin_Menu::SLUG_COMPANIES,
            'firms'     => MyNJILGA_Admin_Menu::SLUG_FIRMS,
        ];

        MyNJILGA_Admin_UI::section(
            sprintf( 'Preview — %d Executive Summary', $year ),
            sprintf( 'The first few rows of each part of the file, so you can check the %d figures before you download. Open a list to see all of it.', $year )
        );

        foreach ( $sheets as $sheet ) {
            if ( ! $firmsAvailable && in_array( $sheet['key'], [ 'companies', 'firms' ], true ) ) {
                continue; // No Companies module: the export has no rows here either.
            }
            MyNJILGA_Admin_UI::section( $sheet['title'], '', $sheet['count'] );

            echo '<div class="njilga-card njilga-table-boxed"><div class="njilga-tablewrap"><table class="njilga-table"><thead><tr>';
            foreach ( $sheet['columns'] as $col ) {
                echo '<th>' . esc_html( $col ) . '</th>';
            }
            echo '</tr></thead><tbody>';
            if ( ! $sheet['rows'] ) {
                printf( '<tr class="njilga-emptyrow"><td colspan="%d">Nothing for %d.</td></tr>', count( $sheet['columns'] ), $year );
            }
            foreach ( $sheet['rows'] as $row ) {
                echo '<tr>';
                foreach ( $row as $cell ) {
                    echo '<td>' . ( $cell !== '' ? esc_html( $cell ) : MyNJILGA_Admin_UI::blank() ) . '</td>';
                }
                echo '</tr>';
            }
            echo '</tbody></table></div></div>';

            $more = $sheet['count'] - count( $sheet['rows'] );
            printf(
                '<p class="njilga-dim">%s <a href="%s">Open the full %s list &rarr;</a></p>',
                esc_html( $more > 0
                    ? sprintf( 'Showing %d of %s.', count( $sheet['rows'] ), MyNJILGA_Report_Preview::count_label( $sheet['count'], $sheet['unit'] ) )
                    : MyNJILGA_Report_Preview::count_label( $sheet['count'], $sheet['unit'] ) . '.' ),
                esc_url( add_query_arg( [ 'page' => $pages[ $sheet['key'] ], MyNJILGA_Report_Year::PARAM => $year ], admin_url( 'admin.php' ) ) ),
                esc_html( $year )
            );
        }
    }
}
