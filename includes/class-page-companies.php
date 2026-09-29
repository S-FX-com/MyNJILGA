<?php
/**
 * Companies — bucketed by active member count (1 / 2–5 / 6+ / none). A
 * firm's members are its Company roster; "active" is the same rule as every
 * other figure (MyNJILGA_Membership_Stats).
 */
class MyNJILGA_Page_Companies {

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Access denied.' );
        }

        MyNJILGA_Admin_UI::styles();
        echo '<div class="wrap njilga-ui">';
        MyNJILGA_Admin_Menu::render_back_to_reports();
        MyNJILGA_Admin_UI::page_header( 'Companies', 'Firms bucketed by how many of their contacts are active members — paid through this year or later, whatever their email-subscription status.' );

        if ( MyNJILGA_Admin_Menu::require_fluentcrm() ) {
            MyNJILGA_Admin_UI::close();
            return;
        }

        if ( ! MyNJILGA_Members_Data::companies_module_active() ) {
            MyNJILGA_Admin_UI::callout( 'The FluentCRM <strong>Companies</strong> module is not active on this site. Enable it under FluentCRM → Settings → Modules.', 'warning' );
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
                $first   = true;
                foreach ( $c['members'] as $m ) {
                    echo '<tr>';
                    if ( $first ) {
                        printf(
                            '<td rowspan="%d" class="njilga-rowhead"><strong>%s</strong><span class="njilga-subline">%d paid / %d total</span></td>',
                            $rowspan,
                            esc_html( $c['name'] ),
                            $c['paid_count'],
                            $c['total_count']
                        );
                        $first = false;
                    }
                    [ $dues_label, $dues_variant ] = MyNJILGA_Members_Data::dues_pill( $m['state'] );
                    printf(
                        '<td><a href="%s">%s</a></td><td>%s</td></tr>',
                        esc_url( $m['url'] ),
                        esc_html( $m['name'] ),
                        MyNJILGA_Admin_UI::pill( $dues_label, $dues_variant )
                    );
                }
            }

            echo '</tbody></table></div></div>';
        }

        // Companies with no contacts belong in no bucket (they neither have nor
        // lack an active member); say how many were left out.
        $empty = (int) ( $data['empty_companies'] ?? 0 );
        if ( $empty > 0 ) {
            printf(
                '<p class="njilga-dim">%s</p>',
                esc_html( sprintf( '%d compan%s with no contacts %s not listed above.', $empty, $empty === 1 ? 'y' : 'ies', $empty === 1 ? 'is' : 'are' ) )
            );
        }

        MyNJILGA_Admin_UI::close();
    }
}
