<?php
/**
 * The "Member tag" of a membership category: an optional FluentCRM tag,
 * chosen next to the category's WordPress role in Settings, that is added to
 * a member when they pay and taken away again when the downgrade sweep
 * lapses them.
 *
 * It is the OUTPUT of paying, never an input: pricing and the role are
 * decided by the category's own tag (the "Tag slug" column). A member tag
 * that was also a category, paid/unpaid, inactive or assessment-qualifying
 * tag would feed straight back into pricing — hand the whole firm the $200
 * dinner assessment, say — so reserved_slugs() lists the tags that may not
 * be used, and Settings refuses them on save.
 *
 * Which category a contact is in follows MyNJILGA_Role_Sync exactly: the
 * first category, in Settings order, whose tag they carry; else the default
 * category. The plan (what to add, what to take away) is pure; grant() and
 * revoke() are the only parts that touch FluentCRM, and neither can throw —
 * a tag that cannot be applied must never stop a payment being settled.
 */
class MyNJILGA_Member_Tags {

    // -------------------------------------------------------------------------
    // Pure
    // -------------------------------------------------------------------------

    /**
     * The category a contact belongs to: the first, in Settings order, whose
     * tag they carry; else the default category; else none.
     *
     * @param array<int,array<string,mixed>> $categories Ordered category rows.
     * @param array<int,string>              $heldSlugs  Category tag slugs the contact carries.
     * @return array<string,mixed>|null
     */
    public static function category_for( array $categories, array $heldSlugs, string $defaultKey ): ?array {
        $held = array_flip( array_map( 'strval', $heldSlugs ) );
        foreach ( $categories as $cat ) {
            $tag = (string) ( $cat['tag'] ?? '' );
            if ( $tag !== '' && isset( $held[ $tag ] ) ) {
                return $cat;
            }
        }
        if ( $defaultKey !== '' ) {
            foreach ( $categories as $cat ) {
                if ( (string) ( $cat['key'] ?? '' ) === $defaultKey ) {
                    return $cat;
                }
            }
        }
        return null;
    }

    /**
     * Every member tag the plugin manages: all that are configured, once each.
     *
     * @param array<int,array<string,mixed>> $categories
     * @return array<int,string>
     */
    public static function managed( array $categories ): array {
        $slugs = [];
        foreach ( $categories as $cat ) {
            $slug = (string) ( $cat['member_tag'] ?? '' );
            if ( $slug !== '' ) {
                $slugs[ $slug ] = $slug;
            }
        }
        return array_values( $slugs );
    }

    /**
     * What to do for a contact who has paid: add their category's member tag
     * (if it has one) and take away every OTHER member tag the plugin manages,
     * so a member who moved category does not carry the old one as well.
     *
     * @param array<int,array<string,mixed>> $categories Ordered category rows.
     * @param array<int,string>              $heldSlugs  Category tag slugs the contact carries.
     * @return array{add:string,remove:array<int,string>} 'add' is '' when there is nothing to add.
     */
    public static function plan( array $categories, array $heldSlugs, string $defaultKey ): array {
        $cat = self::category_for( $categories, $heldSlugs, $defaultKey );
        $add = $cat ? (string) ( $cat['member_tag'] ?? '' ) : '';
        return [
            'add'    => $add,
            'remove' => array_values( array_diff( self::managed( $categories ), $add === '' ? [] : [ $add ] ) ),
        ];
    }

    /**
     * Tag slugs a member tag may NOT be, because pricing, billing or role
     * logic already reads them: every category's own tag, the paid / unpaid /
     * inactive / pending / rejected tags, the pre-law tag, and the tags that
     * make someone owe the assessment.
     *
     * @param array<int,array<string,mixed>> $categories
     * @param array<string,mixed>            $general    The "general" settings block.
     * @param array<int,array<string,mixed>> $qualifiers The assessment's qualifying tags.
     * @return array<int,string>
     */
    public static function reserved_slugs( array $categories, array $general, array $qualifiers ): array {
        $slugs = [];
        foreach ( $categories as $cat ) {
            $slugs[] = (string) ( $cat['tag'] ?? '' );
        }
        foreach ( [ 'paid_tag', 'unpaid_tag', 'inactive_tag', 'pending_tag', 'rejected_tag', 'join_prelaw_tag' ] as $k ) {
            $slugs[] = (string) ( $general[ $k ] ?? '' );
        }
        foreach ( $qualifiers as $q ) {
            $slugs[] = (string) ( $q['tag'] ?? '' );
        }
        return array_values( array_unique( array_filter( $slugs, 'strlen' ) ) );
    }

    /**
     * Blank every member tag that is reserved, and say which were refused.
     *
     * @param array<int,array<string,mixed>> $categories
     * @param array<int,string>              $reserved   From reserved_slugs().
     * @return array{categories:array<int,array<string,mixed>>,rejected:array<int,string>}
     */
    public static function screen( array $categories, array $reserved ): array {
        $reserved = array_flip( $reserved );
        $rejected = [];
        foreach ( $categories as $i => $cat ) {
            $slug = (string) ( $cat['member_tag'] ?? '' );
            if ( $slug !== '' && isset( $reserved[ $slug ] ) ) {
                $rejected[ $slug ]            = $slug;
                $categories[ $i ]['member_tag'] = '';
            }
        }
        return [ 'categories' => $categories, 'rejected' => array_values( $rejected ) ];
    }

    // -------------------------------------------------------------------------
    // FluentCRM
    // -------------------------------------------------------------------------

    /**
     * Give a paid member their category's member tag, and take away the
     * other categories' ones. Never throws.
     *
     * @param object $contact A FluentCRM Subscriber.
     * @return bool False when nothing could be applied (the caller carries on).
     */
    public static function grant( $contact ): bool {
        try {
            $categories = MyNJILGA_Dues_Settings::categories();
            if ( ! self::managed( $categories ) ) {
                return true; // No member tag configured anywhere: nothing to do.
            }
            $held = [];
            foreach ( $categories as $cat ) {
                if ( (string) ( $cat['tag'] ?? '' ) !== '' && MyNJILGA_Tags::has_slug( $contact, (string) $cat['tag'] ) ) {
                    $held[] = (string) $cat['tag'];
                    break; // The first match in Order decides.
                }
            }
            $plan = self::plan( $categories, $held, (string) MyNJILGA_Dues_Settings::general( 'default_category', '' ) );
            foreach ( $plan['remove'] as $slug ) {
                MyNJILGA_Tags::detach_slug( $contact, $slug );
            }
            if ( $plan['add'] !== '' ) {
                MyNJILGA_Tags::attach_slug( $contact, $plan['add'] );
            }
            return true;
        } catch ( \Throwable $e ) {
            return false;
        }
    }

    /**
     * Take every member tag away from a member who has lapsed. Never throws.
     *
     * @param object $contact A FluentCRM Subscriber.
     */
    public static function revoke( $contact ): bool {
        try {
            foreach ( self::managed( MyNJILGA_Dues_Settings::categories() ) as $slug ) {
                MyNJILGA_Tags::detach_slug( $contact, $slug );
            }
            return true;
        } catch ( \Throwable $e ) {
            return false;
        }
    }
}
