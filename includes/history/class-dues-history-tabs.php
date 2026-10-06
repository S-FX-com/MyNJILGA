<?php
/**
 * Adds a "Dues History" tab to the FluentCRM contact record and to the
 * FluentCRM company record.
 *
 * Both go through FluentCRM's own, supported extension point —
 * FluentCrmApi('extender')->addProfileSection() for contacts and
 * ->addCompanyProfileSection() for companies (FluentCRM 2.8+; the company
 * tab needs the Companies module). FluentCRM lists each tab, then calls
 * the callback below over its REST API when the tab is opened, and shows
 * the returned `content_html`. Nothing is injected into the page by script
 * and nothing about the FluentCRM app is patched.
 *
 * The callbacks run inside a REST request, where `admin_init` never fires,
 * so they make sure the history table exists themselves. They never let an
 * exception escape: a failure inside the tab shows a short message in the
 * tab and logs the cause; it must not break the contact record around it.
 */
class MyNJILGA_Dues_History_Tabs {

    const SECTION_KEY = 'njilga_dues_history';
    const TAB_TITLE   = 'Dues History';

    /**
     * Hook the registration. FluentCRM boots on `plugins_loaded` (priority
     * 10) and only then can `FluentCrmApi()` be called, so this waits for
     * priority 25.
     */
    public static function register(): void {
        add_action( 'plugins_loaded', [ __CLASS__, 'add_tabs' ], 25 );
    }

    public static function add_tabs(): void {
        if ( ! function_exists( 'FluentCrmApi' ) ) {
            return; // FluentCRM isn't active; nothing to extend.
        }
        $extender = FluentCrmApi( 'extender' );
        if ( ! is_object( $extender ) ) {
            return;
        }
        if ( method_exists( $extender, 'addProfileSection' ) ) {
            $extender->addProfileSection( self::SECTION_KEY, self::TAB_TITLE, [ __CLASS__, 'contact_section' ] );
        }
        // Older FluentCRM releases have no company sections; the contact
        // tab still works without it.
        if ( method_exists( $extender, 'addCompanyProfileSection' ) ) {
            $extender->addCompanyProfileSection( self::SECTION_KEY, self::TAB_TITLE, [ __CLASS__, 'company_section' ] );
        }
    }

    /**
     * @param array<string,mixed> $content    Starts as [ heading, content_html ].
     * @param object              $subscriber FluentCRM Subscriber model.
     * @return array<string,mixed>
     */
    public static function contact_section( $content, $subscriber ): array {
        $content = is_array( $content ) ? $content : [];
        $content['heading'] = self::TAB_TITLE;

        if ( ! self::allowed() ) {
            $content['content_html'] = self::notice( 'Dues History is limited to administrators.' );
            return $content;
        }

        try {
            MyNJILGA_Dues_History_Table::maybe_upgrade();
            $contactId = (int) $subscriber->id;
            $content['content_html'] = MyNJILGA_Dues_History_View::contact_html(
                $contactId,
                MyNJILGA_Members_Data::display_name( $subscriber ),
                self::firms_of( $subscriber )
            );
        } catch ( \Throwable $e ) {
            $content['content_html'] = self::failed( $e );
        }
        return $content;
    }

    /**
     * @param array<string,mixed> $content Starts as [ heading, content_html ].
     * @param object              $company FluentCRM Company model.
     * @return array<string,mixed>
     */
    public static function company_section( $content, $company ): array {
        $content = is_array( $content ) ? $content : [];
        $content['heading'] = self::TAB_TITLE;

        if ( ! self::allowed() ) {
            $content['content_html'] = self::notice( 'Dues History is limited to administrators.' );
            return $content;
        }

        try {
            MyNJILGA_Dues_History_Table::maybe_upgrade();
            $content['content_html'] = MyNJILGA_Dues_History_View::company_html( (int) $company->id );
        } catch ( \Throwable $e ) {
            $content['content_html'] = self::failed( $e );
        }
        return $content;
    }

    /**
     * Same gate as every other My NJILGA screen. FluentCRM lets anyone
     * with its own contact permission open a record, but this tab is
     * billing detail.
     */
    private static function allowed(): bool {
        return current_user_can( 'manage_options' );
    }

    /**
     * The firms a contact belongs to — the primary one first — as
     * company id => name.
     *
     * @param object $subscriber
     * @return array<int,string>
     */
    private static function firms_of( $subscriber ): array {
        $firms = [];
        if ( ! MyNJILGA_Members_Data::companies_module_active() ) {
            return $firms;
        }
        $ids = [];
        if ( ! empty( $subscriber->company_id ) ) {
            $ids[] = (int) $subscriber->company_id;
        }
        try {
            foreach ( $subscriber->companies ?? [] as $c ) {
                $ids[] = (int) $c->id;
            }
        } catch ( \Throwable $e ) {
            // The primary id (if any) is all there is.
        }
        foreach ( array_values( array_unique( array_filter( $ids ) ) ) as $id ) {
            $company = \FluentCrm\App\Models\Company::find( $id );
            if ( $company ) {
                $firms[ $id ] = (string) $company->name;
            }
        }
        return $firms;
    }

    /**
     * A plain notice for the tab. No design-system classes: this is the
     * fallback for when the panel itself cannot be built.
     */
    private static function notice( string $text ): string {
        return '<p>' . esc_html( $text ) . '</p>';
    }

    private static function failed( \Throwable $e ): string {
        error_log( 'My NJILGA Dues History: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        return self::notice( 'Dues History could not be loaded. The cause has been written to the PHP error log.' );
    }
}
