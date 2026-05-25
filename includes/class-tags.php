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
 *   - trustees        "Trustees"         — has paid the trustee fee
 *   - paid-by-check   "Paid by Check"    — payment method override
 *   - paid-by-invoice "Paid by Invoice"  — payment method override
 */
class MyNJILGA_Tags {

    const SLUG_DUES_PAID       = 'dues-paid';
    const SLUG_TRUSTEES        = 'trustees';
    const SLUG_PAID_BY_CHECK   = 'paid-by-check';
    const SLUG_PAID_BY_INVOICE = 'paid-by-invoice';

    /**
     * @var array<string,array{slug:string,title:string,required:bool}>
     */
    const DEFINITIONS = [
        self::SLUG_DUES_PAID       => [ 'slug' => self::SLUG_DUES_PAID,       'title' => 'Dues Paid',       'required' => true  ],
        self::SLUG_TRUSTEES        => [ 'slug' => self::SLUG_TRUSTEES,        'title' => 'Trustees',        'required' => true  ],
        self::SLUG_PAID_BY_CHECK   => [ 'slug' => self::SLUG_PAID_BY_CHECK,   'title' => 'Paid by Check',   'required' => false ],
        self::SLUG_PAID_BY_INVOICE => [ 'slug' => self::SLUG_PAID_BY_INVOICE, 'title' => 'Paid by Invoice', 'required' => false ],
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
     * @param \FluentCrm\App\Models\Subscriber $subscriber
     */
    public static function is_trustee( $subscriber ): bool {
        return self::has_tag( $subscriber, self::SLUG_TRUSTEES );
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
