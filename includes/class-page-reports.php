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

        echo '<div class="wrap"><h1>Reports</h1>';

        if ( MyNJILGA_Admin_Menu::require_fluentcrm() ) {
            echo '</div>';
            return;
        }

        MyNJILGA_Admin_Menu::render_stats_panel();

        $cards = [
            [
                'title' => 'Active Members',
                'desc'  => 'Every contact carrying the Dues Paid tag, with firm, trustee role, and payment method.',
                'url'   => MyNJILGA_Admin_Menu::url( MyNJILGA_Admin_Menu::SLUG_MEMBERS ),
            ],
            [
                'title' => 'Trustees',
                'desc'  => 'Trustees, Senior Trustees, and Past Presidents, with dues status and payment method.',
                'url'   => MyNJILGA_Admin_Menu::url( MyNJILGA_Admin_Menu::SLUG_TRUSTEES ),
            ],
            [
                'title' => 'Companies',
                'desc'  => 'Firms bucketed by how many paid members they have (1 / 2–5 / 6+ / none).',
                'url'   => MyNJILGA_Admin_Menu::url( MyNJILGA_Admin_Menu::SLUG_COMPANIES ),
            ],
            [
                'title' => 'Membership by Firm — All Membership',
                'desc'  => 'Every firm with at least one contact, grouped, with each contact’s dues and roles. Exports to Excel.',
                'url'   => add_query_arg( 'scope', 'all', MyNJILGA_Admin_Menu::url( MyNJILGA_Admin_Menu::SLUG_FIRMS ) ),
            ],
            [
                'title' => 'Membership by Firm — Active Membership Only',
                'desc'  => 'Only firms that have active (Dues Paid) members, showing just those active members. Exports to Excel.',
                'url'   => add_query_arg( 'scope', 'active', MyNJILGA_Admin_Menu::url( MyNJILGA_Admin_Menu::SLUG_FIRMS ) ),
            ],
        ];

        echo '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:16px;margin-top:8px">';
        foreach ( $cards as $card ) {
            printf(
                '<a href="%s" style="display:block;padding:20px;background:#fff;border:1px solid #c3c4c7;border-radius:6px;text-decoration:none;color:inherit">
                    <div style="font-size:16px;font-weight:600;margin-bottom:6px">%s &rarr;</div>
                    <div style="color:#646970;font-size:13px;line-height:1.5">%s</div>
                 </a>',
                esc_url( $card['url'] ),
                esc_html( $card['title'] ),
                esc_html( $card['desc'] )
            );
        }
        echo '</div>';

        echo '</div>';
    }
}
