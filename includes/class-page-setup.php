<?php
/**
 * Setup — detects whether each required FluentCRM tag exists and offers
 * a one-click button to create any that are missing. Also reports on
 * FluentCRM core and the FluentCRM Companies module availability.
 */
class MyNJILGA_Page_Setup {

    const ACTION_CREATE_TAG = 'my_njilga_create_tag';

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Access denied.' );
        }

        echo '<div class="wrap"><h1>My NJILGA — Setup</h1>';

        if ( ! empty( $_GET['created'] ) ) {
            printf(
                '<div class="notice notice-success is-dismissible"><p>Created tag <strong>%s</strong>.</p></div>',
                esc_html( sanitize_text_field( wp_unslash( $_GET['created'] ) ) )
            );
        }
        if ( ! empty( $_GET['create_error'] ) ) {
            printf(
                '<div class="notice notice-error"><p>Could not create tag: %s</p></div>',
                esc_html( sanitize_text_field( wp_unslash( $_GET['create_error'] ) ) )
            );
        }

        self::render_environment_section();

        if ( MyNJILGA_Members_Data::fluentcrm_active() ) {
            self::render_tag_checklist();
        }

        echo '</div>';
    }

    /**
     * admin-post handler: validate, create the tag via the FluentCRM
     * Tags API, redirect back to the Setup page with a status query arg.
     */
    public static function handle_create_tag(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Access denied.' );
        }
        check_admin_referer( self::ACTION_CREATE_TAG );

        $slug   = sanitize_key( $_POST['slug'] ?? '' );
        $defs   = MyNJILGA_Tags::DEFINITIONS;
        $return = MyNJILGA_Admin_Menu::url( MyNJILGA_Admin_Menu::SLUG_SETUP );

        if ( ! isset( $defs[ $slug ] ) ) {
            wp_safe_redirect( add_query_arg( 'create_error', 'unknown tag', $return ) );
            exit;
        }

        if ( ! MyNJILGA_Members_Data::fluentcrm_active() || ! function_exists( 'FluentCrmApi' ) ) {
            wp_safe_redirect( add_query_arg( 'create_error', 'FluentCRM not active', $return ) );
            exit;
        }

        $tag = MyNJILGA_Tags::create( $slug );
        if ( ! $tag ) {
            wp_safe_redirect( add_query_arg( 'create_error', 'creation failed', $return ) );
            exit;
        }

        wp_safe_redirect( add_query_arg( 'created', $defs[ $slug ]['title'], $return ) );
        exit;
    }

    // -------------------------------------------------------------------------
    // Rendering
    // -------------------------------------------------------------------------

    private static function render_environment_section(): void {
        echo '<h2>Environment</h2>';
        echo '<table class="widefat striped" style="max-width:720px"><tbody>';

        $fcrm = MyNJILGA_Members_Data::fluentcrm_active();
        printf(
            '<tr><td style="width:280px">FluentCRM core</td><td>%s</td></tr>',
            $fcrm
                ? '<strong style="color:#1d6f42">Active</strong>'
                : '<strong style="color:#d63638">Not detected</strong> — install and activate FluentCRM.'
        );

        if ( $fcrm ) {
            $companies = MyNJILGA_Members_Data::companies_module_active();
            $company_count = 0;
            if ( $companies ) {
                $company_count = (int) \FluentCrm\App\Models\Company::count();
            }
            printf(
                '<tr><td>FluentCRM Companies module</td><td>%s</td></tr>',
                $companies
                    ? sprintf(
                        '<strong style="color:#1d6f42">Active</strong> <span style="color:#888">(%d compan%s)</span>',
                        $company_count,
                        $company_count === 1 ? 'y' : 'ies'
                    )
                    : '<strong style="color:#b26200">Not detected</strong> — enable Companies in FluentCRM → Settings → Modules.'
            );
        }

        echo '</tbody></table>';
    }

    private static function render_tag_checklist(): void {
        echo '<h2 style="margin-top:24px">Required tags</h2>';
        echo '<p style="color:#646970">The plugin looks these up by slug first, then by exact title. Tags created here will use the canonical slug below.</p>';
        echo '<table class="widefat striped" style="max-width:900px"><thead><tr>
                <th>Status</th><th>Title</th><th>Slug</th><th>Required?</th><th>Subscribers</th><th></th>
              </tr></thead><tbody>';

        foreach ( MyNJILGA_Tags::DEFINITIONS as $slug => $def ) {
            $tag_id = MyNJILGA_Tags::id_for( $slug );

            $status_cell  = $tag_id !== null
                ? '<strong style="color:#1d6f42">✓ Found</strong>'
                : '<strong style="color:#d63638">✗ Missing</strong>';

            $sub_count = '—';
            if ( $tag_id !== null ) {
                $tag = \FluentCrm\App\Models\Tag::find( $tag_id );
                if ( $tag && method_exists( $tag, 'subscribers' ) ) {
                    $sub_count = (int) $tag->subscribers()->count();
                }
            }

            $action_cell = '';
            if ( $tag_id === null ) {
                $action_cell = sprintf(
                    '<form method="post" action="%s" style="margin:0">
                        %s
                        <input type="hidden" name="action" value="%s">
                        <input type="hidden" name="slug" value="%s">
                        <button type="submit" class="button button-primary">Create</button>
                     </form>',
                    esc_url( admin_url( 'admin-post.php' ) ),
                    wp_nonce_field( self::ACTION_CREATE_TAG, '_wpnonce', true, false ),
                    esc_attr( self::ACTION_CREATE_TAG ),
                    esc_attr( $slug )
                );
            } else {
                $action_cell = sprintf( '<span style="color:#888">id %d</span>', $tag_id );
            }

            printf(
                '<tr><td>%s</td><td><strong>%s</strong></td><td><code>%s</code></td><td>%s</td><td>%s</td><td>%s</td></tr>',
                $status_cell,
                esc_html( $def['title'] ),
                esc_html( $def['slug'] ),
                $def['required'] ? 'Yes' : 'Optional',
                is_int( $sub_count ) ? esc_html( (string) $sub_count ) : esc_html( $sub_count ),
                $action_cell
            );
        }

        echo '</tbody></table>';
    }
}
