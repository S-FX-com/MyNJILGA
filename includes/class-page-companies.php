<?php
/**
 * Companies — bucketed by paid member count (1 / 2–5 / 6+).
 */
class MyNJILGA_Page_Companies {

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Access denied.' );
        }

        MyNJILGA_Admin_UI::styles();
        echo '<div class="wrap njilga-ui">';
        MyNJILGA_Admin_Menu::render_back_to_reports();
        MyNJILGA_Admin_UI::page_header( 'Companies', 'Firms bucketed by how many of their FluentCRM contacts carry the Dues Paid tag.' );

        if ( MyNJILGA_Admin_Menu::require_fluentcrm() ) {
            MyNJILGA_Admin_UI::close();
            return;
        }

        if ( ! MyNJILGA_Members_Data::companies_module_active() ) {
            MyNJILGA_Admin_UI::callout( 'The FluentCRM <strong>Companies</strong> module is not active on this site. Enable it under FluentCRM → Settings → Modules.', 'warning' );
            MyNJILGA_Admin_UI::close();
            return;
        }

        MyNJILGA_Admin_Menu::render_stats_panel();

        $data         = MyNJILGA_Members_Data::get_companies_bucketed();
        $bucket_order = [ '1', '2-5', '6+', '0' ];

        MyNJILGA_Admin_Menu::render_csv_button( 'companies', 'Download Companies CSV' );

        foreach ( $bucket_order as $key ) {
            $companies = $data['buckets'][ $key ] ?? [];
            $label     = $data['bucket_labels'][ $key ];

            MyNJILGA_Admin_UI::section( $label, '', count( $companies ) );

            if ( empty( $companies ) ) {
                echo '<p class="njilga-dim"><em>None.</em></p>';
                continue;
            }

            echo '<div class="njilga-card njilga-table-boxed"><div class="njilga-tablewrap"><table class="njilga-table"><thead><tr>
                    <th>Company</th><th>Member</th><th>Status</th>
                  </tr></thead><tbody>';

            foreach ( $companies as $c ) {
                $rowspan = max( 1, count( $c['members'] ) );
                if ( empty( $c['members'] ) ) {
                    printf(
                        '<tr><td><strong>%s</strong> <span class="njilga-dim">(0 / 0)</span></td><td colspan="2" class="njilga-dim"><em>No contacts</em></td></tr>',
                        esc_html( $c['name'] )
                    );
                    continue;
                }
                $first = true;
                foreach ( $c['members'] as $m ) {
                    echo '<tr>';
                    if ( $first ) {
                        printf(
                            '<td rowspan="%d" class="njilga-rowhead"><strong>%s</strong><br><span class="njilga-dim" style="font-size:12px">%d paid / %d total</span></td>',
                            $rowspan,
                            esc_html( $c['name'] ),
                            $c['paid_count'],
                            $c['total_count']
                        );
                        $first = false;
                    }
                    printf(
                        '<td><a href="%s">%s</a></td><td>%s</td></tr>',
                        esc_url( $m['url'] ),
                        esc_html( $m['name'] ),
                        $m['is_paid']
                            ? MyNJILGA_Admin_UI::pill( 'Paid', 'success' )
                            : MyNJILGA_Admin_UI::pill( 'Unpaid', 'destructive' )
                    );
                }
            }

            echo '</tbody></table></div></div>';
        }

        MyNJILGA_Admin_UI::close();
    }
}
