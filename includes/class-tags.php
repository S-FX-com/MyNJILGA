<?php
/**
 * Tag resolution and per-subscriber helpers.
 *
 * Centralises every reference to the NJILGA tag taxonomy so the rest of
 * the plugin doesn't have to know about slugs or fallback titles. Tags
 * are resolved by slug first, then by exact title (FluentCRM auto-slugs
 * from title, but legacy slugs may differ).
 *
 * Required tags (see Setup page):
 *   - dues-paid       "Dues Paid"        — paid/active member
 *   - unpaid-dues     "Unpaid Dues"      — flagged as owing dues
 *   - trustees        "Trustees"         — has paid the trustee fee
 *   - senior-trustee  "Senior Trustee"   — senior trustee (rolls up under Trustees)
 *   - past-president  "Past President"   — past president (rolls up under Trustees)
 *   - paid-by-check   "Paid by Check"    — payment method override
 *   - paid-by-invoice "Paid by Invoice"  — payment method override
 *   - officer         "Officer"          — active officer (Dues Invoicing fee eligibility only)
 *   - inactive        "Inactive"         — not currently billed at all (Dues Invoicing only)
 */
class MyNJILGA_Tags {

    const SLUG_DUES_PAID       = 'dues-paid';
    const SLUG_UNPAID_DUES     = 'unpaid-dues';
    const SLUG_TRUSTEES        = 'trustees';
    const SLUG_SENIOR_TRUSTEE  = 'senior-trustee';
    const SLUG_PAST_PRESIDENT  = 'past-president';
    const SLUG_PAID_BY_CHECK   = 'paid-by-check';
    const SLUG_PAID_BY_INVOICE = 'paid-by-invoice';
    const SLUG_OFFICER         = 'officer';
    const SLUG_INACTIVE        = 'inactive';

    /**
     * @var array<string,array{slug:string,title:string,required:bool}>
     */
    const DEFINITIONS = [
        self::SLUG_DUES_PAID       => [ 'slug' => self::SLUG_DUES_PAID,       'title' => 'Dues Paid',       'required' => true  ],
        self::SLUG_UNPAID_DUES     => [ 'slug' => self::SLUG_UNPAID_DUES,     'title' => 'Unpaid Dues',     'required' => false ],
        self::SLUG_TRUSTEES        => [ 'slug' => self::SLUG_TRUSTEES,        'title' => 'Trustees',        'required' => true  ],
        self::SLUG_SENIOR_TRUSTEE  => [ 'slug' => self::SLUG_SENIOR_TRUSTEE,  'title' => 'Senior Trustee',  'required' => false ],
        self::SLUG_PAST_PRESIDENT  => [ 'slug' => self::SLUG_PAST_PRESIDENT,  'title' => 'Past President',  'required' => false ],
        self::SLUG_PAID_BY_CHECK   => [ 'slug' => self::SLUG_PAID_BY_CHECK,   'title' => 'Paid by Check',   'required' => false ],
        self::SLUG_PAID_BY_INVOICE => [ 'slug' => self::SLUG_PAID_BY_INVOICE, 'title' => 'Paid by Invoice', 'required' => false ],
        self::SLUG_OFFICER         => [ 'slug' => self::SLUG_OFFICER,         'title' => 'Officer',         'required' => false ],
        self::SLUG_INACTIVE        => [ 'slug' => self::SLUG_INACTIVE,        'title' => 'Inactive',        'required' => false ],
    ];

    /**
     * Slugs that qualify a contact as a trustee (any role). Used both for
     * the trustees report filter and for the boolean "Trustee?" column on
     * the Active Paid Members report. Does NOT include Officer — that tag
     * only matters to Dues Invoicing's fee eligibility (FEE_ELIGIBLE_SLUGS
     * below), not to this plugin's existing Trustees report, which
     * predates it and was never asked to include Officers.
     */
    const TRUSTEE_SLUGS = [
        self::SLUG_PAST_PRESIDENT,
        self::SLUG_SENIOR_TRUSTEE,
        self::SLUG_TRUSTEES,
    ];

    /**
     * Slugs that make a contact "exempt" from dues — Past Presidents and
     * Senior Trustees. Exempt contacts are never counted as Unpaid in the
     * report dashboards (they owe nothing, so a missing Dues Paid tag does
     * not make them delinquent). Also drives Dues Invoicing's base-dues
     * exemption (confirmed: unconditional — exempt regardless of active/
     * inactive status; only the trustee fee cares about Inactive).
     */
    const EXEMPT_SLUGS = [
        self::SLUG_PAST_PRESIDENT,
        self::SLUG_SENIOR_TRUSTEE,
    ];

    /**
     * Slugs that owe Dues Invoicing's $200 Trustee Dinner Fee when the
     * contact is active (not tagged Inactive) — Officer, Trustee, Senior
     * Trustee, Past President. Deliberately separate from TRUSTEE_SLUGS:
     * Officer is fee-eligible but was never asked to join the Trustees
     * report's population.
     */
    const FEE_ELIGIBLE_SLUGS = [
        self::SLUG_OFFICER,
        self::SLUG_TRUSTEES,
        self::SLUG_SENIOR_TRUSTEE,
        self::SLUG_PAST_PRESIDENT,
    ];

    /** @var array<string,int|null>|null */
    private static $slug_to_id_cache = null;

    /**
     * Returns the FluentCRM tag id for a slug, or null if not found.
     * Tries slug match first, then exact title match.
     */
    public static function id_for( string $slug ): ?int {
        $map = self::slug_to_id_map();
        return $map[ $slug ] ?? null;
    }

    /**
     * @return array<string,int>  Only includes slugs that resolved.
     */
    public static function resolved_ids(): array {
        return array_filter( self::slug_to_id_map(), static fn( $v ) => $v !== null );
    }

    /**
     * Forget the cached slug→id map (call after creating a tag).
     */
    public static function flush_cache(): void {
        self::$slug_to_id_cache = null;
        self::$generic_cache    = [];
    }

    // -------------------------------------------------------------------------
    // Generic (settings-driven) tag helpers — Dues & Billing settings refer
    // to tags by slug that aren't necessarily in DEFINITIONS above
    // (professional, law-student, pending-approval, …).
    // -------------------------------------------------------------------------

    /** @var array<string,int|null> */
    private static $generic_cache = [];

    /**
     * Resolve ANY slug to a FluentCRM tag id — slug match first, then an
     * exact-title match on the slug's natural title ("law-student" →
     * "Law Student"). Null when the tag doesn't exist.
     */
    public static function resolve_slug( string $slug ): ?int {
        $slug = sanitize_title( $slug );
        if ( $slug === '' ) {
            return null;
        }
        if ( array_key_exists( $slug, self::$generic_cache ) ) {
            return self::$generic_cache[ $slug ];
        }
        $title = isset( self::DEFINITIONS[ $slug ] ) ? self::DEFINITIONS[ $slug ]['title'] : self::title_for_slug( $slug );
        self::$generic_cache[ $slug ] = self::resolve_one( $slug, $title );
        return self::$generic_cache[ $slug ];
    }

    /**
     * "law-student" → "Law Student".
     */
    public static function title_for_slug( string $slug ): string {
        return ucwords( str_replace( [ '-', '_' ], ' ', $slug ) );
    }

    /**
     * Every FluentCRM tag on the install, for settings dropdowns and the
     * Setup page's slug audit.
     *
     * @return array<int,array{id:int,slug:string,title:string}>
     */
    public static function all_tags(): array {
        if ( ! class_exists( '\\FluentCrm\\App\\Models\\Tag' ) ) {
            return [];
        }
        $out = [];
        foreach ( \FluentCrm\App\Models\Tag::orderBy( 'title', 'asc' )->get() as $tag ) {
            $out[] = [ 'id' => (int) $tag->id, 'slug' => (string) $tag->slug, 'title' => (string) $tag->title ];
        }
        return $out;
    }

    /**
     * Attach a tag by ANY slug, creating it (titled from the slug, or
     * $title) when it doesn't exist yet. Used by the invoicing and
     * enrollment flows for settings-configured tags.
     */
    public static function attach_slug( $subscriber, string $slug, ?string $title = null ): void {
        if ( ! $subscriber || sanitize_title( $slug ) === '' ) {
            return;
        }
        $id = self::resolve_slug( $slug );
        if ( ! $id ) {
            $id = self::get_or_create_by_title( $title ?: self::title_for_slug( $slug ), sanitize_title( $slug ) );
            self::$generic_cache[ sanitize_title( $slug ) ] = $id;
        }
        if ( $id ) {
            $subscriber->attachTags( [ $id ] );
        }
    }

    /**
     * Detach a tag by ANY slug. No-op if it doesn't exist.
     */
    public static function detach_slug( $subscriber, string $slug ): void {
        if ( ! $subscriber ) {
            return;
        }
        $id = self::resolve_slug( $slug );
        if ( $id ) {
            $subscriber->detachTags( [ $id ] );
        }
    }

    /**
     * @return array<string,int|null>  Every required/optional slug → id (or null).
     */
    private static function slug_to_id_map(): array {
        if ( self::$slug_to_id_cache !== null ) {
            return self::$slug_to_id_cache;
        }

        $map = [];
        foreach ( self::DEFINITIONS as $slug => $def ) {
            $map[ $slug ] = self::resolve_one( $def['slug'], $def['title'] );
        }
        self::$slug_to_id_cache = $map;
        return $map;
    }

    private static function resolve_one( string $slug, string $title ): ?int {
        if ( ! class_exists( '\\FluentCrm\\App\\Models\\Tag' ) ) {
            return null;
        }
        $row = \FluentCrm\App\Models\Tag::where( 'slug', $slug )->first();
        if ( ! $row ) {
            $row = \FluentCrm\App\Models\Tag::where( 'title', $title )->first();
        }
        return $row ? (int) $row->id : null;
    }

    // -------------------------------------------------------------------------
    // Per-subscriber helpers
    // -------------------------------------------------------------------------

    /**
     * @param \FluentCrm\App\Models\Subscriber $subscriber
     */
    public static function is_paid( $subscriber ): bool {
        return self::has_tag( $subscriber, self::SLUG_DUES_PAID );
    }

    /**
     * True if the subscriber carries the "Unpaid Dues" tag (flagged as owing
     * dues — typically a member from the prior cycle who hasn't renewed).
     *
     * @param \FluentCrm\App\Models\Subscriber $subscriber
     */
    public static function is_unpaid( $subscriber ): bool {
        return self::has_tag( $subscriber, self::SLUG_UNPAID_DUES );
    }

    /**
     * True if the subscriber carries any of the trustee-family tags
     * (Trustees, Senior Trustee, Past President).
     *
     * @param \FluentCrm\App\Models\Subscriber $subscriber
     */
    public static function is_trustee( $subscriber ): bool {
        foreach ( self::TRUSTEE_SLUGS as $slug ) {
            if ( self::has_tag( $subscriber, $slug ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * True if the subscriber is exempt from dues — carries the Past President
     * or Senior Trustee tag. Exempt contacts must not be reported as Unpaid.
     *
     * @param \FluentCrm\App\Models\Subscriber $subscriber
     */
    public static function is_exempt( $subscriber ): bool {
        foreach ( self::EXEMPT_SLUGS as $slug ) {
            if ( self::has_tag( $subscriber, $slug ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * True if the subscriber carries the "Inactive" tag — Dues Invoicing
     * treats this as an unconditional override: not billed at all this
     * cycle (no base dues, no trustee fee), regardless of any other role
     * tags carried.
     *
     * @param \FluentCrm\App\Models\Subscriber $subscriber
     */
    public static function is_inactive( $subscriber ): bool {
        return self::has_tag( $subscriber, self::SLUG_INACTIVE );
    }

    /**
     * True if the subscriber holds a role that owes Dues Invoicing's $200
     * Trustee Dinner Fee (Officer, Trustee, Senior Trustee, or Past
     * President) AND isn't Inactive. Inactive is checked here (rather
     * than left to callers) so nothing can accidentally charge the fee to
     * an inactive contact by forgetting the exclusion.
     *
     * @param \FluentCrm\App\Models\Subscriber $subscriber
     */
    public static function owes_trustee_fee( $subscriber ): bool {
        if ( self::is_inactive( $subscriber ) ) {
            return false;
        }
        foreach ( self::FEE_ELIGIBLE_SLUGS as $slug ) {
            if ( self::has_tag( $subscriber, $slug ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Returns the most distinguished trustee role label, or "" if none.
     * Priority: Past President > Senior Trustee > Trustee.
     *
     * @param \FluentCrm\App\Models\Subscriber $subscriber
     */
    public static function trustee_status( $subscriber ): string {
        if ( self::has_tag( $subscriber, self::SLUG_PAST_PRESIDENT ) ) {
            return 'Past President';
        }
        if ( self::has_tag( $subscriber, self::SLUG_SENIOR_TRUSTEE ) ) {
            return 'Senior Trustee';
        }
        if ( self::has_tag( $subscriber, self::SLUG_TRUSTEES ) ) {
            return 'Trustee';
        }
        return '';
    }

    /**
     * Returns "Check", "Invoice", or "Credit Card" (the default).
     *
     * @param \FluentCrm\App\Models\Subscriber $subscriber
     */
    public static function payment_method( $subscriber ): string {
        if ( self::has_tag( $subscriber, self::SLUG_PAID_BY_CHECK ) ) {
            return 'Check';
        }
        if ( self::has_tag( $subscriber, self::SLUG_PAID_BY_INVOICE ) ) {
            return 'Invoice';
        }
        return 'Credit Card';
    }

    /**
     * Public test for whether a subscriber carries a specific NJILGA tag,
     * keyed by one of the SLUG_* constants. Returns false when the tag
     * doesn't exist on the install.
     *
     * @param \FluentCrm\App\Models\Subscriber $subscriber
     */
    public static function has( $subscriber, string $slug ): bool {
        return self::has_tag( $subscriber, $slug );
    }

    /**
     * Dues column for the Membership by Firm report:
     *   "Dues Paid"   if the dues-paid tag is present,
     *   "Unpaid Dues" if the unpaid-dues tag is present,
     *   ""            if neither (or both tags absent from the install).
     *
     * Dues Paid wins if a contact somehow carries both.
     *
     * @param \FluentCrm\App\Models\Subscriber $subscriber
     */
    public static function dues_label( $subscriber ): string {
        if ( self::has_tag( $subscriber, self::SLUG_DUES_PAID ) ) {
            return 'Dues Paid';
        }
        if ( self::has_tag( $subscriber, self::SLUG_UNPAID_DUES ) ) {
            return 'Unpaid Dues';
        }
        return '';
    }

    /**
     * Hex accent color for a dues_label() value — green for "Dues Paid",
     * red for "Unpaid Dues", "" otherwise. Matches the green/red used for
     * paid/unpaid status everywhere else in the plugin (KPI tiles, Trustees,
     * Companies), so the Membership by Firm Dues column reads the same way.
     */
    public static function dues_color( string $dues_label ): string {
        if ( $dues_label === 'Dues Paid' ) {
            return '#1d6f42';
        }
        if ( $dues_label === 'Unpaid Dues' ) {
            return '#d63638';
        }
        return '';
    }

    /**
     * Design-system badge variant for a dues_label() value — the on-screen
     * counterpart of dues_color(), which stays because the Excel exporter
     * needs a literal hex. Returns '' when the label isn't a dues verdict.
     */
    public static function dues_variant( string $dues_label ): string {
        if ( $dues_label === 'Dues Paid' ) {
            return 'success';
        }
        if ( $dues_label === 'Unpaid Dues' ) {
            return 'destructive';
        }
        return '';
    }

    /**
     * Payment column for the Membership by Firm report:
     *   "Paid by Invoice" if the paid-by-invoice tag is present,
     *   "Paid by Check"   if the paid-by-check tag is present,
     *   "Paid by Website" if dues-paid is present but neither override tag is,
     *   ""                otherwise.
     *
     * @param \FluentCrm\App\Models\Subscriber $subscriber
     */
    public static function dues_payment_method( $subscriber ): string {
        if ( self::has_tag( $subscriber, self::SLUG_PAID_BY_INVOICE ) ) {
            return 'Paid by Invoice';
        }
        if ( self::has_tag( $subscriber, self::SLUG_PAID_BY_CHECK ) ) {
            return 'Paid by Check';
        }
        if ( self::has_tag( $subscriber, self::SLUG_DUES_PAID ) ) {
            return 'Paid by Website';
        }
        return '';
    }

    private static function has_tag( $subscriber, string $slug ): bool {
        $id = self::id_for( $slug );
        if ( ! $id || ! $subscriber ) {
            return false;
        }
        return (bool) $subscriber->hasAnyTagId( [ $id ] );
    }

    /**
     * Creates a tag in FluentCRM with the given slug (title comes from the
     * definitions). No-op when the slug isn't one we know about, or when
     * FluentCRM isn't active. Returns the new Tag model, or null on failure.
     *
     * @return \FluentCrm\App\Models\Tag|null
     */
    public static function create( string $slug ) {
        if ( ! isset( self::DEFINITIONS[ $slug ] ) || ! function_exists( 'FluentCrmApi' ) ) {
            return null;
        }
        $def = self::DEFINITIONS[ $slug ];

        $result = FluentCrmApi( 'tags' )->importBulk( [
            [ 'title' => $def['title'], 'slug' => $def['slug'] ],
        ] );

        self::flush_cache();

        return is_array( $result ) ? ( $result[0] ?? null ) : null;
    }

    /**
     * Finds (or creates) a FluentCRM tag by exact title, for tags that
     * aren't part of the fixed DEFINITIONS list above — namely the
     * invoicing flow's year-specific "Dues Paid 2027" / "Unpaid Dues
     * 2027" tags, stamped on payment and on the end-of-year downgrade
     * sweep. Unlike id_for(), this can create the tag on demand (via the
     * same FluentCrmApi bulk-import used by create()), since these tags
     * don't exist until the first time a given year is actually invoiced.
     */
    public static function get_or_create_by_title( string $title, string $slug = '' ): ?int {
        if ( ! class_exists( '\\FluentCrm\\App\\Models\\Tag' ) ) {
            return null;
        }

        $slug = $slug !== '' ? sanitize_title( $slug ) : sanitize_title( $title );
        $tag  = \FluentCrm\App\Models\Tag::where( 'slug', $slug )->first();
        if ( ! $tag ) {
            $tag = \FluentCrm\App\Models\Tag::where( 'title', $title )->first();
        }
        if ( $tag ) {
            return (int) $tag->id;
        }

        if ( ! function_exists( 'FluentCrmApi' ) ) {
            return null;
        }
        $result = FluentCrmApi( 'tags' )->importBulk( [ [ 'title' => $title, 'slug' => $slug ] ] );
        $row    = is_array( $result ) ? ( $result[0] ?? null ) : null;
        return $row ? (int) $row->id : null;
    }

    /**
     * Attach one of the plugin's known tags (by SLUG_* constant) to a
     * subscriber, creating the tag first if it doesn't exist yet. Used by
     * the invoicing flow to keep the evergreen `dues-paid` / `unpaid-dues`
     * tags — the ones every report in this plugin already reads — in sync
     * whenever a firm invoice is paid or downgraded, alongside the
     * year-specific tag that records *which* year it was paid for.
     */
    public static function attach( $subscriber, string $slug ): void {
        if ( ! $subscriber ) {
            return;
        }
        $id = self::id_for( $slug );
        if ( ! $id ) {
            $tag = self::create( $slug );
            $id  = $tag ? (int) $tag->id : null;
        }
        if ( $id ) {
            $subscriber->attachTags( [ $id ] );
        }
    }

    /**
     * Detach one of the plugin's known tags (by SLUG_* constant) from a
     * subscriber. No-op if the tag doesn't exist on the install.
     */
    public static function detach( $subscriber, string $slug ): void {
        if ( ! $subscriber ) {
            return;
        }
        $id = self::id_for( $slug );
        if ( $id ) {
            $subscriber->detachTags( [ $id ] );
        }
    }
}
