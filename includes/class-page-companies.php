<?php
/**
 * Companies — bucketed by paid member count (1 / 2–5 / 6+).
 */
class MyNJILGA_Page_Companies {

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Access denied.' );
        }

        echo '<div class="wrap"><h1>Companies</h1>';

        if ( MyNJILGA_Admin_Menu::require_fluentcrm() ) {
            echo '</div>';
            return;
        }

        if ( ! MyNJILGA_Members_Data::companies_module_active() ) {
            echo '<div class="notice notice-warning"><p>The FluentCRM <strong>Companies</strong> module is not active on this site. Enable it under FluentCRM → Settings → Modules.</p></div></div>';
            return;
        }

        $data = MyNJILGA_Members_Data::get_companies_bucketed();
        $bucket_order = [ '1', '2-5', '6+', '0' ];

        echo '<p style="color:#646970">Companies are bucketed by how many of their FluentCRM contacts carry the <strong>Dues Paid</strong> tag.</p>';

        MyNJILGA_Admin_Menu::render_csv_button( 'companies', 'Download Companies CSV' );

        foreach ( $bucket_order as $key ) {
            $companies = $data['buckets'][ $key ] ?? [];
            $label     = $data['bucket_labels'][ $key ];

            printf(
                '<h2 style="margin-top:24px;%s">%s <span style="color:#888;font-weight:400">(%d)</span></h2>',
                $key === '0' ? 'color:#888' : '',
                esc_html( $label ),
                count( $companies )
            );

            if ( empty( $companies ) ) {
                echo '<p style="color:#999;font-style:italic">None.</p>';
                continue;
            }

            echo '<table class="widefat striped"><thead><tr>
                    <th>Company</th><th>Member</th><th>Status</th>
                  </tr></thead><tbody>';

            foreach ( $companies as $c ) {
                $rowspan = max( 1, count( $c['members'] ) );
                if ( empty( $c['members'] ) ) {
                    printf(
                        '<tr><td><strong>%s</strong> <span style="color:#888">(0 / 0)</span></td><td colspan="2" style="color:#999;font-style:italic">No contacts</td></tr>',
                        esc_html( $c['name'] )
                    );
                    continue;
                }
                $first = true;
                foreach ( $c['members'] as $m ) {
                    echo '<tr>';
                    if ( $first ) {
                        printf(
                            '<td rowspan="%d" style="vertical-align:top"><strong>%s</strong><br><span style="color:#888;font-size:11px">%d paid / %d total</span></td>',
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
                            ? '<strong style="color:#1d6f42">Paid</strong>'
                            : '<strong style="color:#d63638">Unpaid</strong>'
                    );
                }
            }

            echo '</tbody></table>';
        }

        echo '</div>';
    }
}
