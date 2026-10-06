<?php
/**
 * My NJILGA → Tools — the landing page for one-off data jobs: the PMPro
 * Migrator and the Historical Invoice Import. Like Reports, it is a menu
 * item whose tools are hidden pages reached from here.
 *
 * It also holds the import log. Every run of either tool stamps the
 * history rows it creates with a batch id, so the log is just those rows
 * grouped, and each run can be undone as a unit.
 */
class MyNJILGA_Page_Tools {

    const ACTION_UNDO = 'my_njilga_history_undo';

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Access denied.' );
        }

        MyNJILGA_Admin_UI::open( 'Tools', 'One-off jobs for bringing earlier years\' dues into this site. Everything they create is reference history — it shows on the Dues History tab of a contact or firm and changes nothing else.' );

        if ( MyNJILGA_Admin_Menu::require_fluentcrm() ) {
            MyNJILGA_Admin_UI::close();
            return;
        }
        MyNJILGA_Dues_History_Table::maybe_upgrade();

        self::notices();

        echo '<div class="njilga-linkcards">';
        self::card( MyNJILGA_Admin_Menu::SLUG_TOOL_PMPRO, 'history', 'PMPro Migrator', 'Recreate last year\'s paid dues invoices from Paid Memberships Pro, matched to each contact and firm.' );
        self::card( MyNJILGA_Admin_Menu::SLUG_TOOL_IMPORT, 'upload', 'Historical Invoice Import', 'Upload a spreadsheet (CSV or Excel) of earlier years\' invoices and payments.' );
        echo '</div>';

        $t = MyNJILGA_Dues_History_Table::totals();
        MyNJILGA_Admin_UI::section( 'Dues history on file', 'Invoices recreated by the tools above. Live Stripe invoices are not counted here — they are read straight from Invoicing.' );
        MyNJILGA_Admin_UI::stat_cards( [
            [ 'label' => 'Historical invoices', 'value' => number_format_i18n( $t['invoices'] ), 'icon' => 'receipt' ],
            [ 'label' => 'Total invoiced',      'value' => MyNJILGA_Invoicing::money( $t['total_cents'] ), 'icon' => 'file' ],
            [ 'label' => 'Firms',               'value' => number_format_i18n( $t['firms'] ), 'icon' => 'building' ],
            [ 'label' => 'Contacts',            'value' => number_format_i18n( $t['contacts'] ), 'icon' => 'user' ],
        ], 4 );

        self::render_log();
        MyNJILGA_Admin_UI::close();
    }

    private static function card( string $slug, string $icon, string $title, string $desc ): void {
        printf(
            '<a class="njilga-linkcard" href="%s"><span class="njilga-linkcard-icon">%s</span><span><span class="njilga-linkcard-title">%s &rarr;</span><span class="njilga-linkcard-desc">%s</span></span></a>',
            esc_url( MyNJILGA_Admin_Menu::url( $slug ) ),
            MyNJILGA_Admin_UI::icon( $icon ), // phpcs:ignore WordPress.Security.EscapeOutput -- an inline SVG from the design system.
            esc_html( $title ),
            esc_html( $desc )
        );
    }

    private static function notices(): void {
        if ( isset( $_GET['undone'] ) ) {
            $n = absint( wp_unslash( (string) $_GET['undone'] ) );
            MyNJILGA_Admin_UI::callout(
                $n > 0
                    ? sprintf( '<strong>Undone.</strong> Removed %s historical invoice%s. Nothing else was touched.', esc_html( number_format_i18n( $n ) ), $n === 1 ? '' : 's' )
                    : '<strong>Nothing to undo.</strong> That run no longer has any invoices on file.',
                $n > 0 ? 'success' : 'info'
            );
        }
    }

    // -------------------------------------------------------------------------
    // Import log
    // -------------------------------------------------------------------------

    private static function render_log(): void {
        $batches = MyNJILGA_Dues_History_Table::batches();
        MyNJILGA_Admin_UI::section( 'Import log', 'Each run, newest first. Undo removes exactly the invoices that run created.', count( $batches ) );

        if ( ! $batches ) {
            echo '<div class="njilga-card njilga-empty"><div class="njilga-empty-icon">' . MyNJILGA_Admin_UI::icon( 'inbox' ) . '</div>';
            echo '<h2 class="njilga-empty-title">Nothing imported yet</h2><p class="njilga-empty-text">Runs of the PMPro Migrator and the Historical Invoice Import appear here.</p></div>';
            return;
        }

        echo '<div class="njilga-card njilga-table-boxed"><div class="njilga-tablewrap"><table class="njilga-table"><thead><tr>';
        echo '<th>When</th><th>Tool</th><th>Run by</th><th>Dues years</th><th class="njilga-col-num">Invoices</th><th class="njilga-col-num">Total</th><th class="njilga-col-actions"></th>';
        echo '</tr></thead><tbody>';
        foreach ( $batches as $b ) {
            $user  = (int) $b->created_by > 0 ? get_userdata( (int) $b->created_by ) : false;
            $years = (int) $b->first_year === (int) $b->last_year ? (string) (int) $b->first_year : (int) $b->first_year . '–' . (int) $b->last_year;
            printf(
                '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td class="njilga-col-num">%s</td><td class="njilga-col-num">%s</td><td class="njilga-col-actions">%s</td></tr>',
                esc_html( mysql2date( 'M j, Y g:i a', (string) $b->created_at ) ),
                MyNJILGA_Admin_UI::pill( (string) $b->source === MyNJILGA_Dues_History_Table::SOURCE_PMPRO ? 'PMPro Migrator' : 'Spreadsheet import', 'outline' ), // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in pill().
                $user ? esc_html( (string) $user->display_name ) : MyNJILGA_Admin_UI::blank(), // phpcs:ignore WordPress.Security.EscapeOutput
                esc_html( $years ),
                esc_html( number_format_i18n( (int) $b->invoices ) ),
                esc_html( MyNJILGA_Invoicing::money( (int) $b->total_cents ) ),
                MyNJILGA_Admin_UI::action_form( // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in action_form().
                    self::ACTION_UNDO,
                    'Undo',
                    [ 'batch' => (string) $b->batch_id ],
                    'danger-outline',
                    'undo',
                    sprintf( 'Remove the %d invoices this run created? They will disappear from every Dues History tab. This cannot be redone, but you can run the tool again.', (int) $b->invoices ),
                    'sm'
                )
            );
        }
        echo '</tbody></table></div></div>';
    }

    // -------------------------------------------------------------------------
    // Undo
    // -------------------------------------------------------------------------

    public static function handle_undo(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Access denied.' );
        }
        check_admin_referer( self::ACTION_UNDO );

        $batch   = self::clean_batch_id( isset( $_POST['batch'] ) ? wp_unslash( (string) $_POST['batch'] ) : '' );
        $removed = MyNJILGA_Dues_History_Table::delete_batch( $batch );

        wp_safe_redirect( add_query_arg( [ 'page' => MyNJILGA_Admin_Menu::SLUG_TOOLS, 'undone' => $removed ], admin_url( 'admin.php' ) ) );
        exit;
    }

    /**
     * A batch id is letters, digits and dashes (see
     * MyNJILGA_Historical_Import::batch_id()); anything else is dropped.
     */
    public static function clean_batch_id( string $raw ): string {
        return substr( (string) preg_replace( '/[^A-Za-z0-9_\-]/', '', $raw ), 0, 48 );
    }
}
