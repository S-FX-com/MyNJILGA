<?php
/**
 * My NJILGA → Shortcodes: every shortcode the plugin provides, what it
 * does, how to paste it, and which pages use it now.
 *
 * The staff answer to "what do I put on the Membership page?". The lines
 * to paste are built from the live Settings (one [njilga_join] per
 * category an applicant may pick), so they stay right when categories
 * change — the old hand-written list on Setup named three keys and went
 * stale the moment one was renamed.
 *
 * "Used on" searches post_content: one query per shortcode across every
 * post type that stores content (pages, posts, synced patterns,
 * templates), then one more for the pages that insert a synced pattern
 * carrying one — never a query per post. A page builder that keeps its
 * content somewhere else (post meta) isn't seen; the page says so.
 */
class MyNJILGA_Page_Shortcodes {

    /** Statuses a use is worth listing for — not trash, revisions (inherit) or auto-drafts. */
    const STATUSES = [ 'publish', 'future', 'draft', 'pending', 'private' ];

    /**
     * Post types whose post_content isn't page content (or is a copy of
     * one, like a customizer changeset), so a match there says nothing
     * about where the shortcode renders.
     */
    const SKIP_TYPES = [ 'revision', 'nav_menu_item', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_global_styles', 'wp_navigation', 'wp_font_family', 'wp_font_face' ];

    /** Rendered wherever the theme uses them — there's no single page to view. */
    const TEMPLATE_TYPES = [ 'wp_template', 'wp_template_part' ];

    /** Most uses listed per shortcode (and pages found through synced patterns). */
    const MAX_USES = 200;

    /** @var int Numbers each copyable line's <code> id. */
    private static $lineId = 0;

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Access denied.' );
        }

        MyNJILGA_Admin_UI::open( 'Shortcodes', 'Paste these into a page to put a My NJILGA form or view on the site. Each line is ready to copy; "Used on" shows the pages that carry it now.' );

        MyNJILGA_Admin_UI::callout(
            sprintf(
                '<strong>Joining online?</strong> Put <code>[njilga_join]</code> on a Membership page for each category below — applicants pay in Stripe Checkout and are members as soon as the payment clears. <code>[njilga_membership_application]</code> is the older apply-then-approve form (no payment; staff approve each applicant in <a href="%s">Applications</a>); <code>[njilga_firm_dues_status]</code> is for signed-in members, not joiners.',
                esc_url( MyNJILGA_Admin_Menu::url( MyNJILGA_Admin_Menu::SLUG_APPLICATIONS ) )
            ),
            'info'
        );

        $uses = self::uses( [ MyNJILGA_Join_Form::SHORTCODE, MyNJILGA_Application_Form::SHORTCODE, MyNJILGA_Firm_Status_Page::SHORTCODE ] );

        self::render_how_to();
        self::render_join( $uses[ MyNJILGA_Join_Form::SHORTCODE ] );
        self::render_application( $uses[ MyNJILGA_Application_Form::SHORTCODE ] );
        self::render_firm_status( $uses[ MyNJILGA_Firm_Status_Page::SHORTCODE ] );

        self::scripts();
        MyNJILGA_Admin_UI::close();
    }

    // -------------------------------------------------------------------------
    // Sections
    // -------------------------------------------------------------------------

    private static function render_how_to(): void {
        MyNJILGA_Admin_UI::section( 'How to add one' );
        echo '<div class="njilga-card njilga-card-pad"><ul class="njilga-list">';
        echo '<li><strong>Block editor</strong> (the default): edit the page, add a <strong>Shortcode</strong> block (click <strong>+</strong> and search "shortcode", or type <code>/shortcode</code> on an empty line), paste the line into it, then <strong>Save</strong> or <strong>Publish</strong>.</li>';
        echo '<li><strong>Classic editor:</strong> paste the line on a line of its own, in either the Visual or the Text tab, then <strong>Update</strong>.</li>';
        echo '<li><strong>Page builders:</strong> use the builder\'s own Shortcode widget or module. A builder that stores its layout outside the page content won\'t show up under "Used on" here.</li>';
        echo '</ul></div>';
    }

    /**
     * @param array<int,array{post:WP_Post,via:?WP_Post,atts:array<int,string>}> $hits
     */
    private static function render_join( array $hits ): void {
        MyNJILGA_Admin_UI::section(
            'Online joining — [njilga_join]',
            sprintf(
                'Joining with payment. The applicant fills in the form and pays in Stripe Checkout; the membership applies as soon as the payment clears, with no staff step — except a join that comes to $0, which waits in <a href="%s">Online joins</a> for a decision. Colleagues the payer adds are emailed an invitation to create their account, and an invitation link works on any join page, whatever its category. Give each category its own page.',
                esc_url( add_query_arg( 'tab', 'joins', MyNJILGA_Admin_Menu::url( MyNJILGA_Admin_Menu::SLUG_APPLICATIONS ) ) )
            )
        );

        $categories = array_values( array_filter( MyNJILGA_Dues_Settings::categories(), static function ( $c ) {
            return ! empty( $c['applicant_selectable'] );
        } ) );

        // Everything but the category is the same for every page — say it
        // once, above the table, rather than on every row.
        $open = null;
        foreach ( $categories as $c ) {
            if ( (string) $c['tag'] !== '' ) {
                $open = $c;
                break;
            }
        }
        $siteWhy = $open ? MyNJILGA_Join_Form::unavailable_reason( $open ) : '';
        if ( ! $open ) {
            MyNJILGA_Admin_UI::callout( sprintf( '<strong>No category is open to online joining.</strong> Mark at least one category "applicant may pick" and give it a tag in <a href="%s">Settings</a>.', esc_url( MyNJILGA_Admin_Menu::url( MyNJILGA_Admin_Menu::SLUG_SETTINGS ) ) ), 'warning' );
        } elseif ( $siteWhy !== '' ) {
            // unavailable_reason() is worded for staff looking at a join
            // page ("…adding ?njilga_test=1 to this page's address"); the
            // test-mode note below says how from here.
            $prefix = 'Online joining is unavailable: ';
            $detail = strpos( $siteWhy, $prefix ) === 0 ? substr( $siteWhy, strlen( $prefix ) ) : $siteWhy;
            MyNJILGA_Admin_UI::callout(
                '<strong>Online joining is unavailable:</strong> ' . esc_html( $detail )
                . sprintf( ' Until that\'s fixed, visitors see "Online joining is temporarily unavailable" instead of the form. <a href="%s">Setup</a> checks the Stripe side.', esc_url( MyNJILGA_Admin_Menu::url( MyNJILGA_Admin_Menu::SLUG_SETUP ) ) ),
                'warning'
            );
        } else {
            MyNJILGA_Admin_UI::callout( sprintf( '<strong>Online joining is open.</strong> Visitors join and pay in Stripe <strong>Live</strong> mode, whichever way the admin Test/Live toggle is set. <a href="%s">Setup</a> checks the Stripe key and webhook behind it.', esc_url( MyNJILGA_Admin_Menu::url( MyNJILGA_Admin_Menu::SLUG_SETUP ) ) ), 'success' );
        }
        MyNJILGA_Admin_UI::callout(
            '<strong>Staff test mode:</strong> signed in as an administrator, add <code>?njilga_test=1</code> to a join page\'s address (the <strong>Test</strong> links below do that). The form then runs in Stripe Test mode — pay with a test card such as 4242 4242 4242 4242. No real money moves and the join\'s emails come to you, but the FluentCRM contacts, tags and firm changes are real. ' . self::test_mode_state(),
            'info'
        );

        echo '<div class="njilga-card"><div class="njilga-card-head"><h3 class="njilga-card-title">Attributes</h3></div><div class="njilga-tablewrap"><table class="njilga-table"><thead><tr><th>Attribute</th><th>What it does</th></tr></thead><tbody>';
        echo '<tr><td class="njilga-nowrap"><code>category</code></td><td>The category the page sells: its key from Settings (<code>law_student</code>) or its tag (<code>law-student</code>). It must be a category an applicant may pick, with a tag. Left out, it is <code>professional</code>.</td></tr>';
        printf(
            '<tr><td class="njilga-nowrap"><code>form</code> <span class="njilga-dim">(optional)</span></td><td>Which form to show: <code>%s</code> (firm, attorney details, mailing address) or <code>%s</code> (school, and for an enrolled student their ID or transcript). Left out, a category whose key contains "student" gets the student form and every other category the professional one — only set it to override that.</td></tr>',
            esc_html( MyNJILGA_Join_Orders_Table::FORM_PROFESSIONAL ),
            esc_html( MyNJILGA_Join_Orders_Table::FORM_STUDENT )
        );
        echo '</tbody></table></div></div>';

        // Which category each page's shortcode actually opens — resolved
        // the way the shortcode itself resolves it (key or tag, default
        // professional), so a page naming a renamed key, or a category
        // applicants can't pick, shows up as a problem here rather than
        // only as "unavailable" to the public.
        $byCategory = [];
        $problems   = [];
        foreach ( $hits as $hit ) {
            foreach ( $hit['atts'] as $raw ) {
                $atts  = shortcode_parse_atts( $raw );
                $value = is_array( $atts ) && isset( $atts['category'] ) ? (string) $atts['category'] : 'professional';
                $cat   = MyNJILGA_Join_Form::category_from_att( $value );
                $why   = self::category_problem( $cat, $value );
                if ( $why !== '' ) {
                    $problems[] = [ 'hit' => $hit, 'text' => '[' . MyNJILGA_Join_Form::SHORTCODE . $raw . ']', 'why' => $why ];
                    continue;
                }
                $byCategory[ (string) $cat['key'] ][ self::hit_key( $hit ) ] = $hit;
            }
        }

        echo '<div class="njilga-card"><div class="njilga-card-head"><h3 class="njilga-card-title">One line per category</h3></div>';
        printf(
            '<p class="njilga-section-desc njilga-card-pad">Every category an applicant may pick in <a href="%s">Settings</a>, with the price its form quotes.</p>',
            esc_url( MyNJILGA_Admin_Menu::url( MyNJILGA_Admin_Menu::SLUG_SETTINGS ) )
        );
        echo '<div class="njilga-tablewrap"><table class="njilga-table"><thead><tr><th>Category and price</th><th>Shortcode</th><th>Used on</th></tr></thead><tbody>';
        if ( ! $categories ) {
            echo '<tr class="njilga-emptyrow"><td colspan="3">No category is open to applicants.</td></tr>';
        }
        foreach ( $categories as $c ) {
            $key    = (string) $c['key'];
            $form   = MyNJILGA_Join_Form::form_for( $c );
            $why    = MyNJILGA_Join_Form::unavailable_reason( $c );
            $ladder = MyNJILGA_Join_Pricing::ladder( $c );

            $cell  = sprintf( '<strong>%s</strong><div>%s</div>', esc_html( (string) $c['label'] ), esc_html( self::ladder_summary( $ladder ) ) );
            $cell .= sprintf(
                '<span class="njilga-subline">%s form · key <code>%s</code>%s</span>',
                $form === MyNJILGA_Join_Orders_Table::FORM_STUDENT ? 'Student' : 'Professional',
                esc_html( $key ),
                (string) $c['tag'] !== '' ? ' · tag <code>' . esc_html( (string) $c['tag'] ) . '</code>' : ''
            );
            if ( MyNJILGA_Join_Form::offers_colleagues( $c, $form ) ) {
                $cell .= sprintf( '<span class="njilga-subline">The payer may add up to %d colleagues at the tier prices</span>', (int) MyNJILGA_Dues_Settings::general( 'join_max_colleagues', 10 ) );
            }
            if ( (int) ( $ladder[0]['price_cents'] ?? 0 ) === 0 ) {
                $cell .= '<span class="njilga-subline">A $0 join waits for staff in Online joins</span>';
            }
            if ( $why !== '' && $why !== $siteWhy ) {
                $cell .= '<span class="njilga-subline njilga-subline-warn">' . MyNJILGA_Admin_UI::icon( 'alert' ) . esc_html( (string) $c['tag'] === '' ? 'Has no tag, so its join page says joining is unavailable — give it one in Settings.' : $why ) . '</span>';
            }

            printf(
                '<tr><td>%s</td><td>%s</td><td>%s</td></tr>',
                $cell,
                self::copy_line( self::join_line( $key ) ),
                self::used_on( array_values( $byCategory[ $key ] ?? [] ), true, true )
            );
        }
        echo '</tbody></table></div></div>';

        if ( $problems ) {
            $lines = [];
            foreach ( $problems as $p ) {
                $lines[] = sprintf( '<code>%s</code> on %s — %s', esc_html( $p['text'] ), self::post_label_link( $p['hit'] ), esc_html( $p['why'] ) );
            }
            MyNJILGA_Admin_UI::callout( '<strong>These pages show "Online joining is temporarily unavailable" instead of a form:</strong><br>' . implode( '<br>', $lines ), 'warning' );
        }

        MyNJILGA_Admin_UI::callout(
            sprintf(
                '<strong>Keep join pages out of caches.</strong> The form carries a per-visitor security token, so exclude these pages (and addresses carrying <code>njilga_join</code> or <code>njilga_invite</code>) from any page or CDN cache — <a href="%s">Setup</a> has the full go-live checklist.',
                esc_url( MyNJILGA_Admin_Menu::url( MyNJILGA_Admin_Menu::SLUG_SETUP ) )
            ),
            'info'
        );
    }

    /**
     * @param array<int,array{post:WP_Post,via:?WP_Post,atts:array<int,string>}> $hits
     */
    private static function render_application( array $hits ): void {
        $policy = (string) MyNJILGA_Dues_Settings::general( 'mid_year_join_policy' );
        MyNJILGA_Admin_UI::section(
            'Membership application — [njilga_membership_application]',
            sprintf(
                'The older apply-then-approve path, with no payment. The applicant gives their name, email, phone and firm (a type-ahead over the FluentCRM Companies) and picks a category from every one an applicant may pick. They become a FluentCRM contact tagged <code>%s</code>, attached to no firm, so they can\'t be invoiced, and wait in <a href="%s">Applications</a>. Approving attaches them to the firm, applies the category tag, and runs the mid-year join policy — currently <strong>%s</strong> (<a href="%s">change</a>). Use it only where staff must vet each applicant before they are a member or billed; for joining with payment, use <code>[njilga_join]</code> — don\'t offer both for the same category. No attributes.',
                esc_html( (string) MyNJILGA_Dues_Settings::general( 'pending_tag', 'pending-approval' ) ),
                esc_url( MyNJILGA_Admin_Menu::url( MyNJILGA_Admin_Menu::SLUG_APPLICATIONS ) ),
                esc_html( MyNJILGA_Dues_Settings::join_policy_labels()[ $policy ] ?? $policy ),
                esc_url( MyNJILGA_Admin_Menu::url( MyNJILGA_Admin_Menu::SLUG_SETTINGS ) )
            )
        );
        self::single_line_table( '[' . MyNJILGA_Application_Form::SHORTCODE . ']', $hits );
    }

    /**
     * @param array<int,array{post:WP_Post,via:?WP_Post,atts:array<int,string>}> $hits
     */
    private static function render_firm_status( array $hits ): void {
        MyNJILGA_Admin_UI::section(
            'Firm dues status — [njilga_firm_dues_status]',
            'For members, not joiners. A signed-in member sees the dues invoices of every firm they belong to, newest year first: the roster, amounts, status and the payment link — every member of the firm, not just the Owner. Visitors who aren\'t signed in are asked to log in. It always shows Live-mode invoices, whichever way the admin Test/Live toggle is set. Put it on a page members reach once they\'ve logged in. No attributes.'
        );
        self::single_line_table( '[' . MyNJILGA_Firm_Status_Page::SHORTCODE . ']', $hits );
    }

    /**
     * @param array<int,array{post:WP_Post,via:?WP_Post,atts:array<int,string>}> $hits
     */
    private static function single_line_table( string $line, array $hits ): void {
        $unique = [];
        foreach ( $hits as $hit ) {
            $unique[ self::hit_key( $hit ) ] = $hit;
        }
        echo '<div class="njilga-card njilga-table-boxed"><div class="njilga-tablewrap"><table class="njilga-table"><thead><tr><th>Shortcode</th><th>Used on</th></tr></thead><tbody>';
        printf( '<tr><td>%s</td><td>%s</td></tr>', self::copy_line( $line ), self::used_on( array_values( $unique ), false, false ) );
        echo '</tbody></table></div></div>';
    }

    // -------------------------------------------------------------------------
    // Cells
    // -------------------------------------------------------------------------

    /**
     * The line itself, selectable as text, and a Copy button the script
     * reveals — without JavaScript the button stays hidden and the text
     * is still there to select.
     */
    private static function copy_line( string $line ): string {
        $id = 'njilga-sc-' . ( ++self::$lineId );
        return sprintf(
            '<div class="njilga-chips"><code id="%1$s" class="njilga-nowrap">%2$s</code><button type="button" class="njilga-btn njilga-btn-outline njilga-btn-sm" data-njilga-copy="%1$s" aria-live="polite" hidden>Copy</button></div>',
            esc_attr( $id ),
            esc_html( $line )
        );
    }

    /**
     * Where a shortcode is in use, or a pill saying it isn't. A join
     * category without a published page is a warning (nobody can join
     * in it); the other shortcodes are optional, so theirs is muted.
     *
     * @param array<int,array{post:WP_Post,via:?WP_Post,atts:array<int,string>}> $hits
     */
    private static function used_on( array $hits, bool $warn, bool $testLinks ): string {
        $live = false;
        foreach ( $hits as $hit ) {
            $live = $live || self::is_live( $hit['post'] );
        }
        $out = '';
        if ( ! $hits ) {
            $out = MyNJILGA_Admin_UI::pill( 'Not on any page yet', $warn ? 'warning' : 'muted' );
        } elseif ( ! $live ) {
            $out = MyNJILGA_Admin_UI::pill( 'No published page', $warn ? 'warning' : 'muted' );
        }
        if ( ! $hits ) {
            return $out;
        }
        $items = [];
        foreach ( $hits as $hit ) {
            $items[] = '<li>' . self::use_item( $hit, $testLinks ) . '</li>';
        }
        return $out . '<ul class="njilga-list">' . implode( '', $items ) . '</ul>';
    }

    /**
     * One "Used on" entry: title, status, type (when it isn't a page),
     * the synced pattern it comes through, and View / Edit (/ Test).
     *
     * @param array{post:WP_Post,via:?WP_Post,atts:array<int,string>} $hit
     */
    private static function use_item( array $hit, bool $testLink ): string {
        $post  = $hit['post'];
        $bits  = [ '<strong>' . esc_html( self::title( $post ) ) . '</strong>', self::status_pill( $post ) ];
        if ( $post->post_password !== '' ) {
            $bits[] = MyNJILGA_Admin_UI::pill( 'Password protected', 'outline' );
        }
        if ( $post->post_type !== 'page' ) {
            $bits[] = '<span class="njilga-dim">' . esc_html( self::type_label( $post->post_type ) ) . '</span>';
        }

        $links = [];
        $view  = self::view_url( $post );
        if ( $view !== '' ) {
            $links[] = sprintf( '<a href="%s" target="_blank" rel="noopener">View</a>', esc_url( $view ) );
        }
        $edit = (string) get_edit_post_link( $post, 'raw' );
        if ( $edit !== '' ) {
            $links[] = sprintf( '<a href="%s">Edit</a>', esc_url( $edit ) );
        }
        if ( $testLink && $view !== '' ) {
            $links[] = sprintf( '<a href="%s" target="_blank" rel="noopener">Test</a>', esc_url( add_query_arg( 'njilga_test', '1', $view ) ) );
        }
        $html = implode( ' ', $bits );
        if ( $links ) {
            $html .= '<span class="njilga-subline">' . implode( ' &middot; ', $links ) . '</span>';
        }
        if ( $hit['via'] ) {
            $html .= sprintf( '<span class="njilga-subline">Through the synced pattern "%s"</span>', esc_html( self::title( $hit['via'] ) ) );
        }
        return $html;
    }

    /**
     * "Page title" linked to its editor, for the problem list.
     *
     * @param array{post:WP_Post,via:?WP_Post,atts:array<int,string>} $hit
     */
    private static function post_label_link( array $hit ): string {
        $post  = $hit['via'] ?: $hit['post'];
        $edit  = (string) get_edit_post_link( $post, 'raw' );
        $label = esc_html( self::title( $post ) );
        $html  = $edit !== '' ? sprintf( '<a href="%s">%s</a>', esc_url( $edit ), $label ) : $label;
        if ( $hit['via'] ) {
            $html .= ' (the synced pattern on ' . esc_html( self::title( $hit['post'] ) ) . ')';
        }
        return $html;
    }

    /** Whether Test mode could take a staff rehearsal right now. */
    private static function test_mode_state(): string {
        $mode = MyNJILGA_Stripe_Connection::MODE_TEST;
        if ( ! MyNJILGA_Stripe_Connection::is_connected( $mode ) ) {
            return sprintf( 'Stripe Test mode isn\'t connected, so a test visit says joining is unavailable — connect it under <a href="%s">Settings → Payments</a>.', esc_url( add_query_arg( 'tab', 'payments', MyNJILGA_Admin_Menu::url( MyNJILGA_Admin_Menu::SLUG_SETTINGS ) ) ) );
        }
        $access = MyNJILGA_Stripe_Connection::checkout_access( $mode );
        if ( $access === false ) {
            return sprintf( 'The Test key can\'t create Checkout Sessions — give it "Checkout Sessions: Write", then re-check it on <a href="%s">Setup</a>.', esc_url( MyNJILGA_Admin_Menu::url( MyNJILGA_Admin_Menu::SLUG_SETUP ) ) );
        }
        return $access === true ? 'Stripe Test mode is connected and ready.' : 'Stripe Test mode is connected (whether its key may create checkouts couldn\'t be checked just now).';
    }

    private static function status_pill( WP_Post $post ): string {
        switch ( $post->post_status ) {
            case 'publish':
                return MyNJILGA_Admin_UI::pill( 'Published', 'success' );
            case 'future':
                return MyNJILGA_Admin_UI::pill( 'Scheduled', 'info' );
            case 'pending':
                return MyNJILGA_Admin_UI::pill( 'Pending review', 'warning' );
            case 'private':
                return MyNJILGA_Admin_UI::pill( 'Private', 'outline' );
            default:
                return MyNJILGA_Admin_UI::pill( 'Draft', 'muted' );
        }
    }

    // -------------------------------------------------------------------------
    // Where each shortcode is used
    // -------------------------------------------------------------------------

    /**
     * Every post whose content carries each shortcode — one LIKE query per
     * shortcode, then (only when a synced pattern carries one) one more for
     * the posts that insert those patterns. The LIKE is only a pre-filter:
     * find_shortcodes() then reads each match the way WordPress does, so
     * [[njilga_join]] (printed literally) and a longer tag name don't count.
     *
     * @param array<int,string> $tags
     * @return array<string,array<int,array{post:WP_Post,via:?WP_Post,atts:array<int,string>}>>
     */
    private static function uses( array $tags ): array {
        global $wpdb;
        $where = sprintf(
            'post_status IN (%s) AND post_type NOT IN (%s)',
            implode( ',', array_fill( 0, count( self::STATUSES ), '%s' ) ),
            implode( ',', array_fill( 0, count( self::SKIP_TYPES ), '%s' ) )
        );
        $order  = "ORDER BY post_type = 'page' DESC, post_title ASC, ID ASC LIMIT %d";
        $filter = array_merge( self::STATUSES, self::SKIP_TYPES, [ self::MAX_USES ] );

        $out      = [];
        $patterns = []; // wp_block id => WP_Post
        $inside   = []; // wp_block id => [ tag => atts ]
        foreach ( $tags as $tag ) {
            $out[ $tag ] = [];
            $rows = (array) $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT * FROM {$wpdb->posts} WHERE post_content LIKE %s AND {$where} {$order}",
                array_merge( [ '%' . $wpdb->esc_like( '[' . $tag ) . '%' ], $filter )
            ) );
            foreach ( self::posts( $rows ) as $post ) {
                $atts = self::find_shortcodes( (string) $post->post_content, $tag );
                if ( ! $atts ) {
                    continue;
                }
                $out[ $tag ][] = [ 'post' => $post, 'via' => null, 'atts' => $atts ];
                if ( $post->post_type === 'wp_block' ) {
                    $patterns[ (int) $post->ID ]         = $post;
                    $inside[ (int) $post->ID ][ $tag ] = $atts;
                }
            }
        }
        if ( ! $patterns ) {
            return $out;
        }

        // A synced pattern renders wherever it's inserted, as
        // <!-- wp:block {"ref":ID} /-->: list those posts too, as uses
        // "through" the pattern. The LIKE also matches "ref":12 inside
        // "ref":123 — pattern_refs() settles which ids a post really uses.
        $likes = implode( ' OR ', array_fill( 0, count( $patterns ), 'post_content LIKE %s' ) );
        $args  = [];
        foreach ( array_keys( $patterns ) as $id ) {
            $args[] = '%' . $wpdb->esc_like( '"ref":' . $id ) . '%';
        }
        $rows = (array) $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            "SELECT * FROM {$wpdb->posts} WHERE ( {$likes} ) AND {$where} {$order}",
            array_merge( $args, $filter )
        ) );
        foreach ( self::posts( $rows ) as $post ) {
            foreach ( self::pattern_refs( (string) $post->post_content ) as $ref ) {
                if ( ! isset( $patterns[ $ref ] ) || $ref === (int) $post->ID ) {
                    continue;
                }
                foreach ( $inside[ $ref ] as $tag => $atts ) {
                    $out[ $tag ][] = [ 'post' => $post, 'via' => $patterns[ $ref ], 'atts' => $atts ];
                }
            }
        }
        return $out;
    }

    /**
     * Rows from SELECT * as WP_Post objects, cached like any other load so
     * the permalink and edit-link calls that follow don't query again.
     *
     * @param array<int,object> $rows
     * @return array<int,WP_Post>
     */
    private static function posts( array $rows ): array {
        $posts = array_values( array_filter( array_map( 'get_post', $rows ) ) );
        update_post_cache( $posts );
        return $posts;
    }

    /**
     * Why a page's [njilga_join category="…"] can't open a form, or ''.
     * The category half of MyNJILGA_Join_Form::unavailable_reason(),
     * worded for the page that names it.
     */
    private static function category_problem( ?array $cat, string $value ): string {
        if ( ! $cat ) {
            return sprintf( '"%s" isn\'t a category key or tag in Settings.', $value );
        }
        if ( empty( $cat['applicant_selectable'] ) ) {
            return sprintf( '%s isn\'t open to applicants (Settings).', (string) $cat['label'] );
        }
        if ( (string) $cat['tag'] === '' ) {
            return sprintf( '%s has no tag (Settings).', (string) $cat['label'] );
        }
        return '';
    }

    /** Where a use can be looked at: the page, its preview while unpublished, or '' (patterns, templates). */
    private static function view_url( WP_Post $post ): string {
        if ( ! is_post_type_viewable( $post->post_type ) ) {
            return '';
        }
        $url = in_array( $post->post_status, [ 'publish', 'private' ], true ) ? get_permalink( $post ) : get_preview_post_link( $post );
        return is_string( $url ) ? $url : '';
    }

    /**
     * Whether visitors reach this use: a published, public, viewable post
     * with no password — or a published template, which renders wherever
     * the theme uses it.
     */
    private static function is_live( WP_Post $post ): bool {
        if ( $post->post_status !== 'publish' ) {
            return false;
        }
        if ( in_array( $post->post_type, self::TEMPLATE_TYPES, true ) ) {
            return true;
        }
        return $post->post_password === '' && is_post_type_viewable( $post->post_type );
    }

    private static function title( WP_Post $post ): string {
        $title = trim( (string) $post->post_title );
        return $title !== '' ? $title : '(no title)';
    }

    private static function type_label( string $type ): string {
        $obj = get_post_type_object( $type );
        return $obj && isset( $obj->labels->singular_name ) ? (string) $obj->labels->singular_name : $type;
    }

    /** @param array{post:WP_Post,via:?WP_Post,atts:array<int,string>} $hit */
    private static function hit_key( array $hit ): string {
        return (int) $hit['post']->ID . '|' . ( $hit['via'] ? (int) $hit['via']->ID : 0 );
    }

    // -------------------------------------------------------------------------
    // Pure helpers (tests/ShortcodesPageTest.php)
    // -------------------------------------------------------------------------

    /** The ready-to-paste join line for a category key. */
    public static function join_line( string $categoryKey ): string {
        return sprintf( '[%s category="%s"]', MyNJILGA_Join_Form::SHORTCODE, $categoryKey );
    }

    /**
     * The attribute text of every live use of [$tag] in $content, in
     * order: '' for a bare [tag], ' category="x"' for [tag category="x"].
     * The same shape WordPress's get_shortcode_regex() matches for the
     * opening tag — the tag name must end there ([njilga_join_x] is
     * another shortcode), and [[tag]] is WordPress's escape for printing
     * the text itself, so it doesn't count.
     *
     * @return array<int,string>
     */
    public static function find_shortcodes( string $content, string $tag ): array {
        if ( strpos( $content, '[' . $tag ) === false ) {
            return [];
        }
        $re = '/\[(\[?)' . preg_quote( $tag, '/' ) . '(?![\w-])([^\]\/]*(?:\/(?!\])[^\]\/]*)*?)(?:\/)?\](\]?)/';
        if ( ! preg_match_all( $re, $content, $m, PREG_SET_ORDER ) ) {
            return [];
        }
        $out = [];
        foreach ( $m as $match ) {
            if ( $match[1] === '[' && $match[3] === ']' ) {
                continue;
            }
            $out[] = $match[2];
        }
        return $out;
    }

    /**
     * The synced patterns a post inserts: the ids in its
     * <!-- wp:block {"ref":N} /--> comments, each once.
     *
     * @return array<int,int>
     */
    public static function pattern_refs( string $content ): array {
        if ( ! preg_match_all( '/<!--\s+wp:(?:core\/)?block\s+\{[^}]*?"ref"\s*:\s*(\d+)/', $content, $m ) ) {
            return [];
        }
        return array_values( array_unique( array_map( 'intval', $m[1] ) ) );
    }

    /**
     * "$30", or for a tiered category "1st Member $125 · Members 2–5 $75 ·
     * Members 6+ free" — from MyNJILGA_Join_Pricing::ladder(), so it is
     * what the form quotes.
     *
     * @param array<int,array{from:int,to:int,price_cents:int,label:string}> $ladder
     */
    public static function ladder_summary( array $ladder ): string {
        $price = static function ( int $cents ): string {
            return $cents > 0 ? MyNJILGA_Join_View::dollars( $cents ) : 'free';
        };
        if ( count( $ladder ) <= 1 ) {
            $cents = (int) ( $ladder[0]['price_cents'] ?? 0 );
            return $cents > 0 ? MyNJILGA_Join_View::dollars( $cents ) : 'Free';
        }
        $parts = [];
        foreach ( $ladder as $t ) {
            $from  = (int) $t['from'];
            $to    = (int) $t['to'];
            $label = trim( (string) $t['label'] );
            if ( $label === '' ) {
                $label = $to === 0 ? sprintf( 'Members %d+', $from ) : ( $from === $to ? sprintf( 'Member %d', $from ) : sprintf( 'Members %d–%d', $from, $to ) );
            }
            $parts[] = $label . ' ' . $price( (int) $t['price_cents'] );
        }
        return implode( ' · ', $parts );
    }

    // -------------------------------------------------------------------------
    // Copy buttons. All styling comes from MyNJILGA_Admin_UI; see design.md.
    // -------------------------------------------------------------------------

    private static function scripts(): void {
        echo <<<'JS'
<script>
(function(){
  var root=document.querySelector('.njilga-ui');
  if(!root) return;
  var mac=/Mac|iPhone|iPad/.test(navigator.platform||'');
  function select(el){
    var r=document.createRange(); r.selectNodeContents(el);
    var s=window.getSelection(); s.removeAllRanges(); s.addRange(r);
  }
  function say(btn,text){
    clearTimeout(btn._njilgaT);
    btn.textContent=text;
    btn._njilgaT=setTimeout(function(){btn.textContent='Copy';},2000);
  }
  // No clipboard API (a plain-http admin isn't a secure context): the
  // old copy command, and failing that, leave the text selected.
  function fallback(code,btn){
    select(code);
    var ok=false;
    try{ok=document.execCommand('copy');}catch(e){}
    say(btn,ok?'Copied':(mac?'Press ⌘C':'Press Ctrl+C'));
  }
  Array.prototype.forEach.call(root.querySelectorAll('[data-njilga-copy]'),function(btn){
    var code=document.getElementById(btn.getAttribute('data-njilga-copy'));
    if(!code) return;
    btn.hidden=false;
    code.addEventListener('click',function(){select(code);});
    btn.addEventListener('click',function(){
      var text=code.textContent;
      if(navigator.clipboard&&window.isSecureContext){
        navigator.clipboard.writeText(text).then(function(){select(code);say(btn,'Copied');},function(){fallback(code,btn);});
      }else{
        fallback(code,btn);
      }
    });
  });
})();
</script>
JS;
    }
}
