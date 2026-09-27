<?php
/**
 * Dues & Billing settings — the single place the invoicing flow reads its
 * configuration from (spec §3). Stored as one WordPress option,
 * `njilga_dues_settings`, and always returned merged over the seed
 * defaults below, so a site that has never opened the Settings page
 * behaves exactly like the seeded configuration.
 *
 * Three blocks of configuration:
 *
 *   - general       global switches: default category for untagged
 *                   contacts, the inactive-override tag, evergreen paid/
 *                   unpaid tags, send CC policy, downgrade behaviour,
 *                   mid-year join policy, enrollment tags, batch size.
 *   - categories    the ORDERED category mapping table (§3.2) —
 *                   tag → product/variation → role → tier-eligible flag.
 *                   Order is precedence: a contact carrying two category
 *                   tags belongs to the first one listed.
 *   - assessment    the Trustee Dinner Assessment (§3.3): one product,
 *                   an ORDERED list of qualifying tags (first match is
 *                   the label used on the invoice line).
 *   - firm_overrides per-firm billing-mode override (§3.4):
 *                   company_id → 'individual' | 'split_assessment'.
 *
 * FluentCRM tags are the source of truth for who owes what; WordPress
 * roles are a downstream effect of payment, never an input to pricing.
 *
 * Prices live HERE (in cents) and are the only source of what the
 * invoice charges — Stripe invoices are built from inline line items, so
 * there is no catalog anywhere else that could disagree with the number
 * the admin can see on this page.
 */
class MyNJILGA_Dues_Settings {

    const OPTION = 'njilga_dues_settings';

    const MODE_FIRM             = 'firm';
    const MODE_INDIVIDUAL       = 'individual';
    const MODE_SPLIT_ASSESSMENT = 'split_assessment';

    const CC_OWNER_ONLY  = 'owner_only';
    const CC_ALL_MEMBERS = 'all_members';
    const CC_CUSTOM      = 'custom';

    const JOIN_FREE_UNTIL_NEXT_CYCLE = 'free_until_next_cycle';
    const JOIN_INVOICE_NOW           = 'invoice_now';
    const JOIN_MANUAL                = 'manual';

    /** @var array<string,mixed>|null */
    private static $cache = null;

    // -------------------------------------------------------------------------
    // Defaults (seed data, spec §3.2–§3.3)
    // -------------------------------------------------------------------------

    /**
     * @return array<string,mixed>
     */
    public static function defaults(): array {
        return [
            'version' => 1,
            'general' => [
                // Contacts carrying none of the category tags fall into this
                // category ('' = not billed, listed as an exception). Seeded to
                // Professional so a roster that predates category tagging keeps
                // billing the way it does today.
                'default_category'         => 'professional',
                'inactive_tag'             => 'inactive',
                'paid_tag'                 => 'dues-paid',
                'unpaid_tag'               => 'unpaid-dues',
                'year_paid_tag_pattern'    => 'Dues Paid {year}',
                'year_unpaid_tag_pattern'  => 'Unpaid Dues {year}',
                'assessment_paid_pattern'  => 'Assessment Paid {year}',
                'send_cc_mode'             => self::CC_OWNER_ONLY,
                'send_cc_emails'           => '',
                'send_reply_to'            => '',
                'downgrade_remove_roles'   => true,
                // Confirmed by NJILGA 2026-08: joining is an approval process and
                // the approved applicant is invoiced right away.
                'mid_year_join_policy'     => self::JOIN_INVOICE_NOW,
                'pending_tag'              => 'pending-approval',
                'rejected_tag'             => 'application-rejected',
                'application_notify_email' => '',
                'application_success_text' => 'Thank you — your application has been received. NJILGA staff will review it and be in touch.',
                'batch_size'               => 25,

                // Online joining — the [njilga_join] shortcode (includes/join/).
                // A paid join is membership on payment, no review: the money
                // is the proof of intent. A join that comes to $0 has no such
                // proof and waits for staff instead.
                'join_enabled'             => true,
                'join_notify_email'        => '', // '' = application_notify_email, then the admin email
                'join_max_colleagues'      => 10, // 4 paid colleagues + a handful of the free seats beyond 5; more → contact NJILGA
                // Existing firms: attach a joiner automatically only when their
                // email domain already appears at the firm ('domain'), or
                // always and review afterwards ('always').
                'join_firm_match'          => 'domain',
                // MM-DD from which a join pays for NEXT year (and covers the
                // rest of this one). '' = only once next year's invoices exist.
                'join_next_year_from'      => '',
                'join_allow_ach'           => true,
                'join_stripe_invoice'      => true, // Stripe's post-payment invoice PDF for each join
                'join_invite_expiry_days'  => 30,
                'join_prelaw_tag'          => 'pre-law', // added for "undergrad aspiring to law school" students
                'join_municipalities'      => '', // one per line; '' = free-text field
                'join_success_text'        => 'Welcome to NJILGA — your membership is active.',
                // FluentCRM custom field slugs the join form writes to. A slug
                // with no matching custom field is skipped (the Setup page
                // lists which exist); the join record keeps every answer
                // regardless.
                'cf_attorney_id'           => 'nj_attorney_id',
                'cf_bar_admission_date'    => 'nj_bar_admission_date',
                'cf_municipality'          => 'municipality',
                'cf_school'                => 'school',
                'cf_student_status'        => 'student_status',
                'cf_mailing_phone'         => 'mailing_phone',
            ],
            'categories' => [
                [
                    'key'                  => 'past_president',
                    'label'                => 'Past President Membership (Exempt)',
                    'tag'                  => 'past-president',
                    'price_cents'          => 0,
                    'role'                 => 'professional',
                    'tier_eligible'        => false,
                    'applicant_selectable' => false,
                    'tiers'                => [],
                ],
                [
                    'key'                  => 'senior_trustee',
                    'label'                => 'Senior Trustee Membership (Exempt)',
                    'tag'                  => 'senior-trustee',
                    'price_cents'          => 0,
                    'role'                 => 'professional',
                    'tier_eligible'        => false,
                    'applicant_selectable' => false,
                    'tiers'                => [],
                ],
                [
                    'key'                  => 'law_student',
                    'label'                => 'Law Student Membership',
                    'tag'                  => 'law-student',
                    'price_cents'          => 3000, // Confirmed by NJILGA 2026-08: students currently pay $30 (may become free later).
                    'role'                 => 'professional',
                    'tier_eligible'        => false,
                    'applicant_selectable' => true,
                    'tiers'                => [],
                ],
                [
                    'key'                  => 'emerging_professional',
                    'label'                => 'Emerging Professional Membership',
                    'tag'                  => 'emerging-professional',
                    'price_cents'          => 5000, // Confirmed by NJILGA 2026-08: $50, flat (non-tier).
                    'role'                 => 'professional',
                    'tier_eligible'        => false,
                    'applicant_selectable' => true,
                    'tiers'                => [],
                ],
                [
                    'key'                  => 'professional',
                    'label'                => 'Professional Membership',
                    'tag'                  => 'professional',
                    'price_cents'          => 12500,
                    'role'                 => 'professional',
                    'tier_eligible'        => true,
                    'applicant_selectable' => true,
                    'tiers'                => [
                        [ 'key' => 'first',  'label' => '1st Member',  'from' => 1, 'to' => 1, 'price_cents' => 12500 ],
                        [ 'key' => '2_to_5', 'label' => 'Members 2–5', 'from' => 2, 'to' => 5, 'price_cents' => 7500 ],
                        [ 'key' => '6_plus', 'label' => 'Members 6+',  'from' => 6, 'to' => 0, 'price_cents' => 0 ],
                    ],
                ],
            ],
            'assessment' => [
                'key'          => 'trustee_dinner',
                'label'        => 'Trustee Dinner Assessment',
                'price_cents'  => 20000,
                // Ordered: the first qualifying tag a contact carries is the
                // label shown on their assessment line.
                'qualifiers'   => [
                    [ 'tag' => 'officer',        'label' => 'Officer' ],
                    [ 'tag' => 'trustees',       'label' => 'Trustee' ],
                    [ 'tag' => 'senior-trustee', 'label' => 'Senior Trustee' ],
                    [ 'tag' => 'past-president', 'label' => 'Past President' ],
                ],
            ],
            'firm_overrides' => [],
        ];
    }

    // -------------------------------------------------------------------------
    // Read
    // -------------------------------------------------------------------------

    /**
     * Full settings, merged over defaults.
     *
     * @return array<string,mixed>
     */
    public static function get(): array {
        if ( self::$cache !== null ) {
            return self::$cache;
        }
        $stored   = get_option( self::OPTION, [] );
        $defaults = self::defaults();

        if ( ! is_array( $stored ) || empty( $stored ) ) {
            self::$cache = $defaults;
            return self::$cache;
        }

        $merged            = $defaults;
        $merged['general'] = array_merge( $defaults['general'], is_array( $stored['general'] ?? null ) ? $stored['general'] : [] );

        if ( isset( $stored['categories'] ) && is_array( $stored['categories'] ) ) {
            $merged['categories'] = array_values( array_map( [ __CLASS__, 'normalize_category' ], $stored['categories'] ) );
        }
        if ( isset( $stored['assessment'] ) && is_array( $stored['assessment'] ) ) {
            $merged['assessment'] = array_merge( $defaults['assessment'], $stored['assessment'] );
            $merged['assessment']['qualifiers'] = array_values( array_filter( array_map(
                static function ( $q ) {
                    return [ 'tag' => sanitize_title( (string) ( $q['tag'] ?? '' ) ), 'label' => (string) ( $q['label'] ?? '' ) ];
                },
                (array) ( $merged['assessment']['qualifiers'] ?? [] )
            ), static function ( $q ) { return $q['tag'] !== ''; } ) );
        }
        if ( isset( $stored['firm_overrides'] ) && is_array( $stored['firm_overrides'] ) ) {
            $overrides = [];
            foreach ( $stored['firm_overrides'] as $companyId => $mode ) {
                if ( (int) $companyId > 0 && in_array( $mode, [ self::MODE_INDIVIDUAL, self::MODE_SPLIT_ASSESSMENT ], true ) ) {
                    $overrides[ (int) $companyId ] = $mode;
                }
            }
            $merged['firm_overrides'] = $overrides;
        }

        self::$cache = $merged;
        return self::$cache;
    }

    public static function general( string $key, $default = null ) {
        $g = self::get()['general'];
        return array_key_exists( $key, $g ) ? $g[ $key ] : $default;
    }

    /**
     * @return array<int,array<string,mixed>> Ordered category rows.
     */
    public static function categories(): array {
        return self::get()['categories'];
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function category( string $key ): ?array {
        foreach ( self::categories() as $cat ) {
            if ( $cat['key'] === $key ) {
                return $cat;
            }
        }
        return null;
    }

    /**
     * @return array<string,mixed>
     */
    public static function assessment(): array {
        return self::get()['assessment'];
    }

    /**
     * Billing mode for one firm — 'firm' unless overridden (§3.4).
     */
    public static function billing_mode_for( int $companyId ): string {
        $overrides = self::get()['firm_overrides'];
        return $overrides[ $companyId ] ?? self::MODE_FIRM;
    }

    /**
     * The pure configuration array MyNJILGA_Pricing_Engine consumes. Kept
     * as a plain array (no models, no WordPress calls) so the engine can be
     * unit-tested with hand-written fixtures that look exactly like this.
     *
     * @return array<string,mixed>
     */
    public static function engine_config(): array {
        $s = self::get();
        return [
            'default_category' => (string) $s['general']['default_category'],
            'inactive_tag'     => (string) $s['general']['inactive_tag'],
            'categories'       => $s['categories'],
            'assessment'       => $s['assessment'],
        ];
    }

    /**
     * Every FluentCRM tag slug the configuration refers to — used by the
     * Setup page's tag audit and by the preview builder to resolve slugs
     * to ids once per run.
     *
     * @return array<int,string>
     */
    public static function referenced_tag_slugs(): array {
        $s     = self::get();
        $slugs = [
            $s['general']['inactive_tag'],
            $s['general']['paid_tag'],
            $s['general']['unpaid_tag'],
            $s['general']['pending_tag'],
            $s['general']['rejected_tag'],
            $s['general']['join_prelaw_tag'],
        ];
        foreach ( $s['categories'] as $cat ) {
            $slugs[] = $cat['tag'];
        }
        foreach ( $s['assessment']['qualifiers'] as $q ) {
            $slugs[] = $q['tag'];
        }
        return array_values( array_unique( array_filter( array_map( 'strval', $slugs ) ) ) );
    }

    /**
     * The FluentCRM custom field slug each join answer is written to.
     * '' = that answer is kept on the join record only.
     *
     * @return array<string,string> answer key => custom field slug
     */
    public static function join_custom_fields(): array {
        $g = self::get()['general'];
        return [
            'attorney_id'         => sanitize_key( (string) ( $g['cf_attorney_id'] ?? '' ) ),
            'bar_admission_date'  => sanitize_key( (string) ( $g['cf_bar_admission_date'] ?? '' ) ),
            'municipality'        => sanitize_key( (string) ( $g['cf_municipality'] ?? '' ) ),
            'school'              => sanitize_key( (string) ( $g['cf_school'] ?? '' ) ),
            'student_status'      => sanitize_key( (string) ( $g['cf_student_status'] ?? '' ) ),
            'mailing_phone'       => sanitize_key( (string) ( $g['cf_mailing_phone'] ?? '' ) ),
        ];
    }

    /**
     * Municipality choices for the join form, one per line in Settings.
     * Empty = the form shows a free-text field instead of a select.
     *
     * @return array<int,string>
     */
    public static function municipality_options(): array {
        $lines = preg_split( '/\r\n|\r|\n/', (string) self::general( 'join_municipalities', '' ) );
        return array_values( array_unique( array_filter( array_map( 'trim', (array) $lines ), 'strlen' ) ) );
    }

    public static function year_tag( string $patternKey, int $year ): string {
        $pattern = (string) self::general( $patternKey, '' );
        if ( $pattern === '' ) {
            $pattern = self::defaults()['general'][ $patternKey ] ?? '{year}';
        }
        return str_replace( '{year}', (string) $year, $pattern );
    }

    // -------------------------------------------------------------------------
    // Write
    // -------------------------------------------------------------------------

    /**
     * Persist a full settings array (already sanitized by the caller —
     * see MyNJILGA_Page_Settings::handle_save()).
     *
     * @param array<string,mixed> $settings
     */
    public static function save( array $settings ): void {
        $settings['version'] = 1;
        update_option( self::OPTION, $settings, false );
        self::$cache = null;
    }

    public static function reset_to_defaults(): void {
        delete_option( self::OPTION );
        self::$cache = null;
    }

    public static function set_firm_override( int $companyId, string $mode ): void {
        $s = self::get();
        if ( $mode === self::MODE_FIRM || $mode === '' ) {
            unset( $s['firm_overrides'][ $companyId ] );
        } else {
            $s['firm_overrides'][ $companyId ] = $mode;
        }
        self::save( $s );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Coerce one stored category row into the canonical shape (fills any
     * key a future version adds, drops unknown ones).
     *
     * @param array<string,mixed> $cat
     * @return array<string,mixed>
     */
    public static function normalize_category( $cat ): array {
        $cat   = is_array( $cat ) ? $cat : [];
        $tiers = [];
        foreach ( (array) ( $cat['tiers'] ?? [] ) as $t ) {
            if ( ! is_array( $t ) ) {
                continue;
            }
            $tiers[] = [
                'key'          => sanitize_key( (string) ( $t['key'] ?? '' ) ),
                'label'        => (string) ( $t['label'] ?? '' ),
                'from'         => max( 1, (int) ( $t['from'] ?? 1 ) ),
                'to'           => max( 0, (int) ( $t['to'] ?? 0 ) ), // 0 = open-ended
                'price_cents'  => max( 0, (int) ( $t['price_cents'] ?? 0 ) ),
            ];
        }
        usort( $tiers, static function ( $a, $b ) { return $a['from'] <=> $b['from']; } );

        return [
            'key'                  => sanitize_key( (string) ( $cat['key'] ?? '' ) ),
            'label'                => (string) ( $cat['label'] ?? '' ),
            'tag'                  => sanitize_title( (string) ( $cat['tag'] ?? '' ) ),
            'price_cents'          => max( 0, (int) ( $cat['price_cents'] ?? 0 ) ),
            'role'                 => sanitize_key( (string) ( $cat['role'] ?? '' ) ),
            'tier_eligible'        => ! empty( $cat['tier_eligible'] ),
            'applicant_selectable' => ! empty( $cat['applicant_selectable'] ),
            'tiers'                => $tiers,
        ];
    }

    /**
     * @return array<string,string> mode => human label
     */
    public static function billing_mode_labels(): array {
        return [
            self::MODE_FIRM             => 'Firm (one invoice to the Owner — default)',
            self::MODE_INDIVIDUAL       => 'Individual (one invoice per member, billed to each member)',
            self::MODE_SPLIT_ASSESSMENT => 'Split assessment (firm invoice for dues; each assessed member billed their own assessment)',
        ];
    }

    /**
     * @return array<string,string>
     */
    public static function join_policy_labels(): array {
        return [
            self::JOIN_FREE_UNTIL_NEXT_CYCLE => 'Free until next cycle — approved member is marked current for this year; first invoice is next year\'s batch',
            self::JOIN_INVOICE_NOW           => 'Invoice now — a draft individual invoice for the current year is created for review in Invoicing',
            self::JOIN_MANUAL                => 'Manual — only apply the category tag; staff handle any billing by hand',
        ];
    }

    /**
     * @return array<string,string>
     */
    public static function cc_mode_labels(): array {
        return [
            self::CC_OWNER_ONLY  => 'Bill-to contact only',
            self::CC_ALL_MEMBERS => 'Bill-to contact + CC every member on the invoice',
            self::CC_CUSTOM      => 'Bill-to contact + CC the fixed address list below',
        ];
    }
}
