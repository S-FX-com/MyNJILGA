<?php
/**
 * Admin page: Tools → Membership Report
 *
 * Provides:
 *  1. A settings form to save FluentCRM API credentials.
 *  2. A live preview table of the current report data.
 *  3. A download button to export the full .xlsx report.
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

        // Handle credential save.
        if ( isset( $_POST['njilga_save_credentials'] ) ) {
            check_admin_referer( 'njilga_save_credentials' );
            update_option( 'njilga_fcrm_base_url',  sanitize_url( $_POST['fcrm_base_url'] ?? '' ) );
            update_option( 'njilga_fcrm_api_user',  sanitize_text_field( $_POST['fcrm_api_user'] ?? '' ) );
            if ( ! empty( $_POST['fcrm_api_pass'] ) ) {
                update_option( 'njilga_fcrm_api_pass', sanitize_text_field( $_POST['fcrm_api_pass'] ) );
            }
            echo '<div class="notice notice-success"><p>Credentials saved.</p></div>';
        }

        $base_url   = get_option( 'njilga_fcrm_base_url', home_url() );
        $api_user   = get_option( 'njilga_fcrm_api_user', '' );
        $has_pass   = (bool) get_option( 'njilga_fcrm_api_pass', '' );

        // Only load report data if credentials exist.
        $data     = null;
        $data_err = null;
        if ( $api_user && $has_pass ) {
            $data = NJILGA_Report_Data::get();
            if ( is_wp_error( $data ) ) {
                $data_err = $data->get_error_message();
                $data     = null;
            }
        }
        ?>
        <div class="wrap">
            <h1>NJILGA Membership Report</h1>

            <h2>FluentCRM API Credentials</h2>
            <p>
                Create a dedicated FluentCRM Manager account at
                <strong>FluentCRM → Settings → Managers</strong>, then generate
                an API key at <strong>FluentCRM → Settings → Rest API</strong>.
            </p>

            <form method="post">
                <?php wp_nonce_field( 'njilga_save_credentials' ); ?>
                <table class="form-table">
                    <tr>
                        <th>Site URL</th>
                        <td><input type="url" name="fcrm_base_url" value="<?php echo esc_attr( $base_url ); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th>API Username</th>
                        <td><input type="text" name="fcrm_api_user" value="<?php echo esc_attr( $api_user ); ?>" class="regular-text" autocomplete="off"></td>
                    </tr>
                    <tr>
                        <th>Application Password</th>
                        <td>
                            <input type="password" name="fcrm_api_pass" value="" class="regular-text" autocomplete="new-password"
                                   placeholder="<?php echo $has_pass ? '(saved — leave blank to keep)' : 'Enter app password'; ?>">
                        </td>
                    </tr>
                </table>
                <input type="hidden" name="njilga_save_credentials" value="1">
                <?php submit_button( 'Save Credentials', 'secondary' ); ?>
            </form>

            <hr>

            <?php if ( ! $api_user || ! $has_pass ) : ?>
                <p class="notice notice-warning" style="padding:8px">Enter credentials above to load the report.</p>

            <?php elseif ( $data_err ) : ?>
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

                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="njilga_export_report">
                    <?php wp_nonce_field( 'njilga_export_report' ); ?>
                    <?php submit_button( '⬇ Download Excel Report (.xlsx)', 'primary', 'submit', false ); ?>
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
