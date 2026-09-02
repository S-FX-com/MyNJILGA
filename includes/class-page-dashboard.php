<?php
/**
 * Dashboard — summary counts, missing-tag warnings, Excel download.
 */
class MyNJILGA_Page_Dashboard {

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Access denied.' );
        }

        MyNJILGA_Admin_UI::open(
            'My NJILGA',
            'Membership at a glance — counts, firm distribution, and the report exports.'
        );

        if ( MyNJILGA_Admin_Menu::require_fluentcrm() ) {
            MyNJILGA_Admin_UI::close();
            return;
        }

        self::render_missing_tag_banner();

        $s = MyNJILGA_Members_Data::summary();

        MyNJILGA_Admin_UI::stat_cards( [
            [
                'label' => 'Active Paid Members',
                'value' => $s['paid'],
                'variant' => 'success',
                'icon' => 'check-circle',
                'url' => MyNJILGA_Admin_Menu::url( MyNJILGA_Admin_Menu::SLUG_MEMBERS ),
            ],
            [
                'label' => 'Trustees',
                'value' => $s['trustees'],
                'variant' => 'info',
                'icon' => 'award',
                'url' => MyNJILGA_Admin_Menu::url( MyNJILGA_Admin_Menu::SLUG_TRUSTEES ),
            ],
            [
                'label' => 'Companies with Paid Members',
                'value' => $s['companies_with_paid'],
                'variant' => 'default',
                'icon' => 'building',
                'url' => MyNJILGA_Admin_Menu::url( MyNJILGA_Admin_Menu::SLUG_COMPANIES ),
            ],
        ] );

        self::render_distribution( $s );
        self::render_report_links();

        MyNJILGA_Admin_UI::close();
    }

    /**
     * @param array<string,mixed> $s
     */
    private static function render_distribution( array $s ): void {
        MyNJILGA_Admin_UI::section( 'Company distribution', 'Firms bucketed by how many of their contacts carry the Dues Paid tag.' );

        $buckets = [
            [ '1 Paid Member',     (int) ( $s['bucket_counts']['1'] ?? 0 ),   'success' ],
            [ '2–5 Paid Members',  (int) ( $s['bucket_counts']['2-5'] ?? 0 ), 'success' ],
            [ '6+ Paid Members',   (int) ( $s['bucket_counts']['6+'] ?? 0 ),  'success' ],
            [ 'No Paid Members',   (int) ( $s['bucket_counts']['0'] ?? 0 ),   'destructive' ],
        ];

        echo '<div class="njilga-card njilga-table-boxed"><div class="njilga-tablewrap"><table class="njilga-table">';
        echo '<thead><tr><th>Bucket</th><th class="njilga-col-num">Firms</th></tr></thead><tbody>';
        foreach ( $buckets as [ $label, $count, $tone ] ) {
            printf(
                '<tr><td>%s</td><td class="njilga-col-num">%s</td></tr>',
                esc_html( $label ),
                MyNJILGA_Admin_UI::status( (string) $count, $count > 0 ? ( $tone === 'success' ? 'ok' : 'bad' ) : 'muted' )
            );
        }
        echo '</tbody></table></div></div>';
    }

    private static function render_report_links(): void {
        MyNJILGA_Admin_UI::section( 'Reports', 'Each report opens with its own CSV or Excel export.' );

        $links = [
            [ 'Active Paid Members', 'Contacts carrying the Dues Paid tag.', MyNJILGA_Admin_Menu::SLUG_MEMBERS,   'check-circle' ],
            [ 'Trustees',            'Trustees, Senior Trustees and Past Presidents.', MyNJILGA_Admin_Menu::SLUG_TRUSTEES,  'award' ],
            [ 'Companies',           'Firms bucketed by paid member count.', MyNJILGA_Admin_Menu::SLUG_COMPANIES, 'building' ],
            [ 'Membership by Firm',  'Every firm with its contacts, dues and roles.', MyNJILGA_Admin_Menu::SLUG_FIRMS,     'users' ],
        ];

        echo '<div class="njilga-linkcards">';
        foreach ( $links as [ $title, $desc, $slug, $icon ] ) {
            printf(
                '<a class="njilga-linkcard" href="%s"><span class="njilga-linkcard-icon">%s</span><span><span class="njilga-linkcard-title">%s &rarr;</span><span class="njilga-linkcard-desc">%s</span></span></a>',
                esc_url( MyNJILGA_Admin_Menu::url( $slug ) ),
                MyNJILGA_Admin_UI::icon( $icon ),
                esc_html( $title ),
                esc_html( $desc )
            );
        }
        echo '</div>';
    }

    private static function render_missing_tag_banner(): void {
        $missing = [];
        foreach ( MyNJILGA_Tags::DEFINITIONS as $slug => $def ) {
            if ( ! $def['required'] ) continue;
            if ( MyNJILGA_Tags::id_for( $slug ) === null ) {
                $missing[] = $def['title'];
            }
        }
        if ( ! $missing ) return;

        MyNJILGA_Admin_UI::callout(
            sprintf(
                'Required FluentCRM tags missing: <strong>%s</strong>. <a href="%s">Open Setup</a> to create them.',
                esc_html( implode( ', ', $missing ) ),
                esc_url( MyNJILGA_Admin_Menu::url( MyNJILGA_Admin_Menu::SLUG_SETUP ) )
            ),
            'warning'
        );
    }
}
