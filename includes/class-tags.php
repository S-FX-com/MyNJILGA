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
 */
class MyNJILGA_Tags {

    const SLUG_DUES_PAID       = 'dues-paid';
    const SLUG_UNPAID_DUES     = 'unpaid-dues';
    const SLUG_TRUSTEES        = 'trustees';
    const SLUG_SENIOR_TRUSTEE  = 'senior-trustee';
    const SLUG_PAST_PRESIDENT  = 'past-president';
    const SLUG_PAID_BY_CHECK   = 'paid-by-check';
    const SLUG_PAID_BY_INVOICE = 'paid-by-invoice';

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
    ];

    /**
     * Slugs that qualify a contact as a trustee (any role). Used both for
     * the trustees report filter and for the boolean "Trustee?" column on
     * the Active Members report.
     */
    const TRUSTEE_SLUGS = [
        self::SLUG_PAST_PRESIDENT,
        self::SLUG_SENIOR_TRUSTEE,
        self::SLUG_TRUSTEES,
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
}
