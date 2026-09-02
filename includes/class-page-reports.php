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

        MyNJILGA_Admin_UI::open(
            'Reports',
            'Membership KPIs across every report, with per-report CSV and Excel exports.'
        );

        if ( MyNJILGA_Admin_Menu::require_fluentcrm() ) {
            MyNJILGA_Admin_UI::close();
            return;
        }

        MyNJILGA_Admin_Menu::render_stats_panel();

        echo '<div class="njilga-banner"><div>
                <div class="njilga-banner-title">Executive Summary</div>
                <div class="njilga-banner-desc">One Excel file combining every report below — overview KPIs, active paid members, trustees, companies, and membership by firm.</div>
              </div>';
        MyNJILGA_Admin_Menu::render_summary_export_button();
        echo '</div>';

        $cards = [
            [
                'title' => 'Active Paid Members',
                'desc'  => 'Every contact carrying the Dues Paid tag, with firm, email, trustee role, and payment method.',
                'icon'  => 'check-circle',
                'url'   => MyNJILGA_Admin_Menu::url( MyNJILGA_Admin_Menu::SLUG_MEMBERS ),
            ],
            [
                'title' => 'Trustees',
                'desc'  => 'Trustees, Senior Trustees, and Past Presidents, with dues status and payment method.',
                'icon'  => 'award',
                'url'   => MyNJILGA_Admin_Menu::url( MyNJILGA_Admin_Menu::SLUG_TRUSTEES ),
            ],
            [
                'title' => 'Companies',
                'desc'  => 'Firms bucketed by how many paid members they have (1 / 2–5 / 6+ / none).',
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
                'desc'  => 'Only firms that have active (Dues Paid) members, showing just those active members. Exports to Excel.',
                'icon'  => 'users',
                'url'   => add_query_arg( 'scope', 'active', MyNJILGA_Admin_Menu::url( MyNJILGA_Admin_Menu::SLUG_FIRMS ) ),
            ],
        ];

        echo '<div class="njilga-linkcards">';
        foreach ( $cards as $card ) {
            printf(
                '<a class="njilga-linkcard" href="%s"><span class="njilga-linkcard-icon">%s</span><span><span class="njilga-linkcard-title">%s &rarr;</span><span class="njilga-linkcard-desc">%s</span></span></a>',
                esc_url( $card['url'] ),
                MyNJILGA_Admin_UI::icon( $card['icon'] ),
                esc_html( $card['title'] ),
                esc_html( $card['desc'] )
            );
        }
        echo '</div>';

        MyNJILGA_Admin_UI::close();
    }
}
