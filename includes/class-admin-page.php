<?php
/**
 * Admin page: Tools → Membership Report
 *
 * Reads FluentCRM and Paid Memberships Pro directly from the local
 * WordPress install — no API credentials required.
 *
 * Provides:
 *  1. A live preview table of the current report data.
 *  2. A download button to export the full .xlsx report.
 */
class NJILGA_Admin_Page {

    public static function register(): void {
        add_management_page(
            'NJILGA Membership Report',
            'Membership Report',
            'manage_options',
            'njilga-membership-report',
            [ __CLASS__, 'render' ]
        );
    }

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Access denied.' );
        }

        $data     = NJILGA_Report_Data::get();
        $data_err = null;
        if ( is_wp_error( $data ) ) {
            $data_err = $data->get_error_message();
            $data     = null;
        }
        ?>
        <div class="wrap">
            <h1>NJILGA Membership Report</h1>

            <?php if ( $data_err ) : ?>
                <div class="notice notice-error"><p><?php echo esc_html( $data_err ); ?></p></div>

            <?php else : ?>
                <h2><?php echo esc_html( $data['year'] ); ?> Report Summary</h2>
                <p>
                    Total members: <strong><?php echo esc_html( $data['summary']['total'] ); ?></strong> &mdash;
                    <span style="color:green">Paid: <?php echo esc_html( $data['summary']['paid'] ); ?></span> /
                    <span style="color:red">Unpaid: <?php echo esc_html( $data['summary']['unpaid'] ); ?></span> /
                    <span style="color:orange">Partial: <?php echo esc_html( $data['summary']['partial'] ); ?></span> /
                    $0: <?php echo esc_html( $data['summary']['zero'] ); ?>
                </p>
                <p style="color:#555;font-size:12px">
                    Reading FluentCRM directly from this WordPress install. Payment data source:
                    <?php if ( ! empty( $data['pmpro_available'] ) ) : ?>
                        <strong>Paid Memberships Pro</strong> (with FluentCRM custom-field fallback for contacts not linked to a WP user).
                    <?php else : ?>
                        <strong>FluentCRM custom fields</strong> &mdash; PMPro tables not detected.
                    <?php endif; ?>
                </p>

                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="njilga_export_report">
                    <?php wp_nonce_field( 'njilga_export_report' ); ?>
                    <?php submit_button( 'Download Excel Report (.xlsx)', 'primary', 'submit', false ); ?>
                </form>

                <hr>
                <h2>Preview</h2>
                <?php self::render_preview_table( $data ); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    public static function handle_export(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Access denied.' );
        }
        check_admin_referer( 'njilga_export_report' );

        $autoload = NJILGA_REPORT_DIR . 'vendor/autoload.php';
        if ( ! file_exists( $autoload ) ) {
            wp_die( 'PhpSpreadsheet not found. Run <code>composer install</code> in the plugin directory.' );
        }
        require_once $autoload;

        $data = NJILGA_Report_Data::get();
        if ( is_wp_error( $data ) ) {
            wp_die( esc_html( $data->get_error_message() ) );
        }

        NJILGA_Report_Xlsx::stream( $data );
    }

    private static function render_preview_table( array $data ): void {
        echo '<div style="overflow-x:auto">';
        echo '<table class="widefat striped" style="font-size:12px;min-width:700px">';
        echo '<thead><tr>
                <th>Firm</th><th>Member</th><th>Status</th>
                <th>Open Balance</th><th>Amount Paid</th><th>Qty</th><th>Invoiced Total</th>
              </tr></thead><tbody>';

        foreach ( $data['tiers'] as $label => $tier ) {
            printf(
                '<tr style="background:#d9e1f2"><td colspan="7"><strong>%s</strong></td></tr>',
                esc_html( $label )
            );

            if ( empty( $tier['members'] ) ) {
                echo '<tr><td colspan="7" style="color:#999;font-style:italic">No members in this tier</td></tr>';
                continue;
            }

            foreach ( $tier['members'] as $m ) {
                $color = match ( strtolower( $m['status'] ) ) {
                    'paid'    => 'green',
                    'partial' => 'darkorange',
                    default   => 'red',
                };
                printf(
                    '<tr>
                       <td>%s</td><td>%s</td>
                       <td style="color:%s;font-weight:bold">%s</td>
                       <td>%s</td><td>%s</td><td>%s</td><td>%s</td>
                     </tr>',
                    esc_html( $m['firm'] ),
                    esc_html( $m['member'] ),
                    $color,
                    esc_html( $m['status'] ),
                    $m['open_balance']   ? '$' . number_format( $m['open_balance'] )   : '',
                    $m['amount_paid']    ? '$' . number_format( $m['amount_paid'] )    : '',
                    esc_html( $m['qty'] ),
                    $m['invoiced_total'] ? '$' . number_format( $m['invoiced_total'] ) : ''
                );
            }

            $t = $tier['totals'];
            printf(
                '<tr style="background:#e2efda;font-weight:bold">
                   <td colspan="3">Total %s</td>
                   <td>%s</td><td>%s</td><td>%s</td><td></td></tr>',
                esc_html( $label ),
                $t['open_balance'] ? '$' . number_format( $t['open_balance'] ) : '',
                $t['amount_paid']  ? '$' . number_format( $t['amount_paid'] )  : '',
                esc_html( $t['qty'] )
            );
        }

        echo '</tbody></table></div>';
    }
}
