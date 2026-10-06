<?php
/**
 * My NJILGA → Tools → PMPro Migrator.
 *
 * Three steps on one screen:
 *   1. choose the window of PMPro orders (last calendar year by default);
 *   2. PREVIEW — every paid order matched to a contact and firm, with
 *      anything that can't be matched listed with the reason (nothing is
 *      written);
 *   3. MIGRATE — a nonce-protected POST that creates the history invoices.
 *
 * The preview is a plain GET (it only reads); the migration is a POST to
 * admin-post.php. Running it again is safe: orders already migrated are
 * skipped, so a window can be widened or an unmatched contact fixed and the
 * tool re-run.
 */
class MyNJILGA_Page_Tool_Pmpro {

    const ACTION_RUN = 'my_njilga_pmpro_migrate';

    /** Rows each preview table prints. */
    const SHOW = 40;

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Access denied.' );
        }

        MyNJILGA_Admin_UI::styles();
        echo '<div class="wrap njilga-ui">';
        MyNJILGA_Admin_Menu::render_back_to_tools();
        MyNJILGA_Admin_UI::page_header( 'PMPro Migrator', 'Recreate paid dues invoices from Paid Memberships Pro, so each contact and firm keeps its payment history.' );

        if ( MyNJILGA_Admin_Menu::require_fluentcrm() ) {
            MyNJILGA_Admin_UI::close();
            return;
        }
        MyNJILGA_Dues_History_Table::maybe_upgrade();

        MyNJILGA_Admin_UI::callout(
            '<strong>Reference history only.</strong> This reads PMPro\'s order table and never changes it. What it creates shows on the <em>Dues History</em> tab of the contact and the firm — it does not touch Stripe, Invoicing, the Payments ledger, FluentCRM tags or WordPress roles. Only <strong>paid</strong> orders are migrated; test (sandbox) orders never are. It is safe to run again: orders already migrated are skipped. Undo any run from <a href="' . esc_url( MyNJILGA_Admin_Menu::url( MyNJILGA_Admin_Menu::SLUG_TOOLS ) ) . '">Tools</a>.',
            'info'
        );

        self::result_notice();

        if ( ! MyNJILGA_PMPro_Migrator::available() ) {
            MyNJILGA_Admin_UI::callout( '<strong>Paid Memberships Pro\'s order table was not found</strong> in this site\'s database (<code>' . esc_html( MyNJILGA_PMPro_Migrator::orders_table() ) . '</code>). This tool reads PMPro\'s data directly, so that data has to be here — PMPro itself does not need to be active.', 'warning' );
            MyNJILGA_Admin_UI::close();
            return;
        }

        self::render_years();

        $opts = MyNJILGA_PMPro_Migrator::parse_opts( self::request_values(), MyNJILGA_PMPro_Migrator::default_window() );
        self::render_form( $opts );

        if ( isset( $_GET['preview'] ) ) {
            self::render_preview( $opts );
        }
        MyNJILGA_Admin_UI::close();
    }

    // -------------------------------------------------------------------------
    // Parts
    // -------------------------------------------------------------------------

    private static function render_years(): void {
        $years = MyNJILGA_PMPro_Migrator::years();
        MyNJILGA_Admin_UI::section( 'Paid orders in PMPro', 'By the calendar year the order was placed.', count( $years ) );
        if ( ! $years ) {
            echo '<p class="njilga-dim">PMPro has no paid orders.</p>';
            return;
        }
        echo '<div class="njilga-card njilga-table-boxed"><div class="njilga-tablewrap"><table class="njilga-table njilga-table-compact"><thead><tr><th>Year</th><th class="njilga-col-num">Paid orders</th><th class="njilga-col-num">Total</th></tr></thead><tbody>';
        foreach ( $years as $y ) {
            printf(
                '<tr><td>%d</td><td class="njilga-col-num">%s</td><td class="njilga-col-num">%s</td></tr>',
                (int) $y['year'],
                esc_html( number_format_i18n( $y['orders'] ) ),
                esc_html( MyNJILGA_Invoicing::money( $y['total_cents'] ) )
            );
        }
        echo '</tbody></table></div></div>';
    }

    /**
     * @param array{from:string,to:string,year:int,skip_zero:bool} $opts
     */
    private static function render_form( array $opts ): void {
        $firstLoad = ! isset( $_GET['preview'] );
        $skipZero  = $firstLoad ? true : $opts['skip_zero'];

        MyNJILGA_Admin_UI::section( 'Choose the orders to migrate' );
        echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="njilga-card njilga-card-pad">';
        echo '<input type="hidden" name="page" value="' . esc_attr( MyNJILGA_Admin_Menu::SLUG_TOOL_PMPRO ) . '"><input type="hidden" name="preview" value="1">';
        echo '<div class="njilga-inline-fields">';
        printf( '<div class="njilga-field"><label for="njilga-from">Orders placed from</label><input type="date" id="njilga-from" name="from" value="%s"></div>', esc_attr( $opts['from'] ) );
        printf( '<div class="njilga-field"><label for="njilga-to">through</label><input type="date" id="njilga-to" name="to" value="%s"></div>', esc_attr( $opts['to'] ) );
        printf(
            '<div class="njilga-field"><label for="njilga-year">Record under dues year</label><select id="njilga-year" name="year"><option value="0"%s>The year each order was placed</option>',
            $opts['year'] === 0 ? ' selected' : ''
        );
        $thisYear = (int) current_time( 'Y' );
        for ( $y = $thisYear + 1; $y >= $thisYear - 12; $y-- ) {
            printf( '<option value="%1$d"%2$s>%1$d</option>', $y, $opts['year'] === $y ? ' selected' : '' );
        }
        echo '</select></div></div>';
        printf(
            '<p><label class="njilga-check-label"><input type="checkbox" name="skip_zero" value="1"%s> Skip $0 orders (free levels and comped memberships)</label></p>',
            $skipZero ? ' checked' : ''
        );
        echo '<p class="njilga-help">The window defaults to last calendar year. If PMPro memberships ran on a different cycle (say, November to October), set the dates to match and pick the dues year they belong to.</p>';
        echo '<button type="submit" class="njilga-btn njilga-btn-primary">' . MyNJILGA_Admin_UI::icon( 'search' ) . ' Preview migration</button>';
        echo '</form>';
    }

    /**
     * @param array{from:string,to:string,year:int,skip_zero:bool} $opts
     */
    private static function render_preview( array $opts ): void {
        $plan   = MyNJILGA_PMPro_Migrator::plan( $opts );
        $counts = $plan['counts'];
        $window = MyNJILGA_PMPro_Migrator::window_counts( $opts['from'], $opts['to'] );

        $other = 0;
        foreach ( $window['by_status'] as $status => $n ) {
            if ( $status !== 'success' ) {
                $other += $n;
            }
        }

        MyNJILGA_Admin_UI::section( 'Preview', sprintf( 'Paid PMPro orders placed %s through %s. Nothing has been written yet.', esc_html( MyNJILGA_Dues_History_View::date( $opts['from'] ) ), esc_html( MyNJILGA_Dues_History_View::date( $opts['to'] ) ) ) );

        if ( $plan['truncated'] ) {
            MyNJILGA_Admin_UI::callout( 'That window holds more than ' . esc_html( number_format_i18n( MyNJILGA_PMPro_Migrator::MAX_PREVIEW ) ) . ' paid orders, so only the first are shown. Narrow the dates and migrate in pieces.', 'warning' );
        }

        MyNJILGA_Admin_UI::stat_cards( [
            [ 'label' => 'Will be migrated',  'value' => $counts['new'],       'variant' => $counts['new'] > 0 ? 'success' : 'default', 'icon' => 'check-circle', 'sub' => $counts['no_firm'] > 0 ? sprintf( '%d with no firm', $counts['no_firm'] ) : '' ],
            [ 'label' => 'Already migrated',  'value' => $counts['duplicate'], 'variant' => 'info',    'icon' => 'history' ],
            [ 'label' => 'No contact found',  'value' => $counts['unmatched'], 'variant' => $counts['unmatched'] > 0 ? 'warning' : 'default', 'icon' => 'alert', 'sub' => $counts['unmatched'] > 0 ? 'Not migrated — listed below' : '' ],
            [ 'label' => 'Skipped',           'value' => $counts['zero'] + $window['sandbox'] + $other, 'variant' => 'default', 'icon' => 'file', 'sub' => self::skipped_sub( $counts['zero'], $window['sandbox'], $other ) ],
        ], 4 );

        $new = array_values( array_filter( $plan['rows'], static function ( $p ) {
            return $p['state'] === 'new';
        } ) );

        if ( $new ) {
            self::render_new_table( $new );
            $run = array_slice( $new, 0, MyNJILGA_PMPro_Migrator::MAX_PER_RUN );
            echo '<div class="njilga-card njilga-card-pad">';
            if ( count( $new ) > MyNJILGA_PMPro_Migrator::MAX_PER_RUN ) {
                echo '<p class="njilga-help">One run migrates up to ' . esc_html( number_format_i18n( MyNJILGA_PMPro_Migrator::MAX_PER_RUN ) ) . ' orders. Run it again afterwards for the rest — orders already migrated are skipped.</p>';
            }
            echo MyNJILGA_Admin_UI::action_form( // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in action_form().
                self::ACTION_RUN,
                sprintf( 'Migrate %s invoice%s', number_format_i18n( count( $run ) ), count( $run ) === 1 ? '' : 's' ),
                [ 'from' => $opts['from'], 'to' => $opts['to'], 'year' => $opts['year'], 'skip_zero' => $opts['skip_zero'] ? 1 : 0 ],
                'primary',
                'upload',
                sprintf( 'Create %d historical invoices from PMPro? This only adds reference history; you can undo the run from Tools.', count( $run ) )
            );
            echo '</div>';
        } else {
            MyNJILGA_Admin_UI::callout( '<strong>Nothing new to migrate</strong> in this window.', 'info' );
        }

        $unmatched = array_values( array_filter( $plan['rows'], static function ( $p ) {
            return $p['state'] === 'unmatched';
        } ) );
        if ( $unmatched ) {
            self::render_unmatched_table( $unmatched );
        }
    }

    /**
     * @param array<int,array<string,mixed>> $new
     */
    private static function render_new_table( array $new ): void {
        MyNJILGA_Admin_UI::section( 'Invoices that will be created', count( $new ) > self::SHOW ? sprintf( 'Showing the first %d.', self::SHOW ) : '', count( $new ) );
        echo '<div class="njilga-card njilga-table-boxed"><div class="njilga-tablewrap"><table class="njilga-table"><thead><tr>';
        echo '<th>PMPro order</th><th>Date</th><th>Contact</th><th>Firm</th><th>Level</th><th>Method</th><th class="njilga-col-num">Total</th>';
        echo '</tr></thead><tbody>';
        foreach ( array_slice( $new, 0, self::SHOW ) as $p ) {
            $row = $p['row'];
            printf(
                '<tr><td><span class="njilga-mono">%s</span></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td class="njilga-col-num">%s</td></tr>',
                esc_html( (string) $row['invoice_number'] ),
                esc_html( MyNJILGA_Dues_History_View::date( (string) $row['invoice_date'] ) ),
                esc_html( (string) $row['contact_name'] ),
                $row['company_name'] !== '' ? esc_html( (string) $row['company_name'] ) : MyNJILGA_Admin_UI::status( 'no firm', 'warn' ), // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in status().
                esc_html( (string) $row['description'] ),
                esc_html( MyNJILGA_Dues_History::full_method( (string) $row['method'], (string) $row['method_detail'], (string) $row['reference'] ) ),
                esc_html( MyNJILGA_Invoicing::money( (int) $row['total_cents'] ) )
            );
        }
        echo '</tbody></table></div></div>';
    }

    /**
     * @param array<int,array<string,mixed>> $unmatched
     */
    private static function render_unmatched_table( array $unmatched ): void {
        MyNJILGA_Admin_UI::section(
            'Orders with no contact',
            'These are not migrated — with no contact they would appear on no tab. Add or relink the contact in FluentCRM, then run the migration again.',
            count( $unmatched )
        );
        echo '<div class="njilga-card njilga-table-boxed"><div class="njilga-tablewrap"><table class="njilga-table"><thead><tr>';
        echo '<th>PMPro order</th><th>Date</th><th>Billing name</th><th>Why</th><th class="njilga-col-num">Total</th>';
        echo '</tr></thead><tbody>';
        foreach ( array_slice( $unmatched, 0, 100 ) as $p ) {
            $o = $p['order'];
            printf(
                '<tr><td><span class="njilga-mono">%s</span></td><td>%s</td><td>%s</td><td>%s</td><td class="njilga-col-num">%s</td></tr>',
                esc_html( (string) ( $o['code'] ?? $o['id'] ) ),
                esc_html( MyNJILGA_Dues_History_View::date( MyNJILGA_Dues_History::date_part( (string) ( $o['timestamp'] ?? '' ) ) ) ),
                esc_html( (string) ( $o['billing_name'] ?? '' ) ),
                esc_html( (string) $p['reason'] ),
                esc_html( MyNJILGA_Invoicing::money( MyNJILGA_PMPro_Migrator::cents( $o['total'] ?? '' ) ) )
            );
        }
        if ( count( $unmatched ) > 100 ) {
            printf( '<tr class="njilga-emptyrow"><td colspan="5">Showing the first 100 of %d.</td></tr>', count( $unmatched ) );
        }
        echo '</tbody></table></div></div>';
    }

    private static function skipped_sub( int $zero, int $sandbox, int $other ): string {
        $bits = [];
        if ( $zero > 0 ) {
            $bits[] = $zero . ' at $0';
        }
        if ( $sandbox > 0 ) {
            $bits[] = $sandbox . ' test';
        }
        if ( $other > 0 ) {
            $bits[] = $other . ' not paid';
        }
        return implode( ', ', $bits );
    }

    private static function result_notice(): void {
        if ( ! isset( $_GET['ran'] ) ) {
            return;
        }
        $created   = isset( $_GET['created'] ) ? absint( wp_unslash( (string) $_GET['created'] ) ) : 0;
        $failed    = isset( $_GET['failed'] ) ? absint( wp_unslash( (string) $_GET['failed'] ) ) : 0;
        $remaining = isset( $_GET['remaining'] ) ? absint( wp_unslash( (string) $_GET['remaining'] ) ) : 0;

        $msg = sprintf( '<strong>Migrated %s invoice%s.</strong> They now show on each contact\'s and firm\'s Dues History tab.', esc_html( number_format_i18n( $created ) ), $created === 1 ? '' : 's' );
        if ( $remaining > 0 ) {
            $msg .= ' ' . esc_html( number_format_i18n( $remaining ) ) . ' more are waiting — run the migration again to continue.';
        }
        if ( $failed > 0 ) {
            $msg .= ' ' . esc_html( number_format_i18n( $failed ) ) . ' could not be written; run it again to retry them.';
        }
        MyNJILGA_Admin_UI::callout( $msg, $failed > 0 ? 'warning' : 'success' );
    }

    /**
     * @return array<string,mixed>
     */
    private static function request_values(): array {
        $out = [];
        foreach ( [ 'from', 'to', 'year', 'skip_zero' ] as $k ) {
            if ( isset( $_REQUEST[ $k ] ) ) {
                $out[ $k ] = sanitize_text_field( wp_unslash( (string) $_REQUEST[ $k ] ) );
            }
        }
        return $out;
    }

    // -------------------------------------------------------------------------
    // Run
    // -------------------------------------------------------------------------

    public static function handle_run(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Access denied.' );
        }
        check_admin_referer( self::ACTION_RUN );

        if ( ! MyNJILGA_Members_Data::fluentcrm_active() || ! MyNJILGA_PMPro_Migrator::available() ) {
            wp_die( 'FluentCRM and the PMPro order table are both required.' );
        }
        MyNJILGA_Dues_History_Table::maybe_upgrade();

        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        }

        $opts   = MyNJILGA_PMPro_Migrator::parse_opts( self::request_values(), MyNJILGA_PMPro_Migrator::default_window() );
        $result = MyNJILGA_PMPro_Migrator::run( $opts, get_current_user_id() );

        wp_safe_redirect( add_query_arg( [
            'page'      => MyNJILGA_Admin_Menu::SLUG_TOOL_PMPRO,
            'ran'       => 1,
            'created'   => $result['created'],
            'failed'    => $result['failed'],
            'remaining' => $result['remaining'],
        ], admin_url( 'admin.php' ) ) );
        exit;
    }
}
