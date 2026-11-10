<?php
/**
 * The "Member tag" of a membership category: an optional FluentCRM tag,
 * chosen next to the category's WordPress role in Settings, that is added to
 * a member when they pay and taken away again when the downgrade sweep
 * lapses them.
 *
 * It is the OUTPUT of paying: pricing and the role are decided by the
 * category's own tag (the "Tag slug" column). Two rules keep the output from
 * feeding back into pricing:
 *
 *   1. A member tag may be the category's OWN tag ("make sure a paid
 *      Professional carries `professional`") — but not another category's,
 *      nor a paid/unpaid/inactive/assessment-qualifying tag, which would
 *      change who is charged what (e.g. hand every payer the $200 dinner
 *      assessment). reserved_slugs() lists those; Settings refuses them.
 *   2. This feature NEVER REMOVES a tag that pricing, billing or the role
 *      logic reads. Taking `law-student` off a lapsed student would bill
 *      them as the default category next year; taking `past-president` off
 *      would end their exemption. Only genuinely separate "member" tags are
 *      swapped or taken away.
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
     * @param array<int,string>              $protected  Tags that must never be removed (reserved_slugs()).
     * @return array{add:string,remove:array<int,string>} 'add' is '' when there is nothing to add.
     */
    public static function plan( array $categories, array $heldSlugs, string $defaultKey, array $protected = [] ): array {
        $cat = self::category_for( $categories, $heldSlugs, $defaultKey );
        $add = $cat ? (string) ( $cat['member_tag'] ?? '' ) : '';
        return [
            'add'    => $add,
            'remove' => array_values( array_diff( self::removable( $categories, $protected ), $add === '' ? [] : [ $add ] ) ),
        ];
    }

    /**
     * The member tags this feature is allowed to take away: every configured
     * one EXCEPT those pricing, billing or the role logic read (a category's
     * own tag used as its member tag is added, but never removed).
     *
     * @param array<int,array<string,mixed>> $categories
     * @param array<int,string>              $protected  From reserved_slugs().
     * @return array<int,string>
     */
    public static function removable( array $categories, array $protected ): array {
        return array_values( array_diff( self::managed( $categories ), $protected ) );
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
     * A category's OWN tag is not refused — it is the one reserved tag a
     * category may hand out, and it is never removed (see removable()).
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
            if ( $slug !== '' && isset( $reserved[ $slug ] ) && $slug !== (string) ( $cat['tag'] ?? '' ) ) {
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
            $plan = self::plan( $categories, $held, (string) MyNJILGA_Dues_Settings::general( 'default_category', '' ), self::protected_slugs( $categories ) );
            // Only touch a tag when the contact's state actually differs:
            // attaching what they already hold (their own category tag, in
            // the common case) would fire FluentCRM's tag events for nothing.
            foreach ( $plan['remove'] as $slug ) {
                if ( MyNJILGA_Tags::has_slug( $contact, $slug ) ) {
                    MyNJILGA_Tags::detach_slug( $contact, $slug );
                }
            }
            if ( $plan['add'] !== '' && ! MyNJILGA_Tags::has_slug( $contact, $plan['add'] ) ) {
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
            $categories = MyNJILGA_Dues_Settings::categories();
            foreach ( self::removable( $categories, self::protected_slugs( $categories ) ) as $slug ) {
                if ( MyNJILGA_Tags::has_slug( $contact, $slug ) ) {
                    MyNJILGA_Tags::detach_slug( $contact, $slug );
                }
            }
            return true;
        } catch ( \Throwable $e ) {
            return false;
        }
    }

    /**
     * The tags this feature must never remove, from the live settings.
     *
     * @param array<int,array<string,mixed>> $categories
     * @return array<int,string>
     */
    private static function protected_slugs( array $categories ): array {
        return self::reserved_slugs(
            $categories,
            (array) ( MyNJILGA_Dues_Settings::get()['general'] ?? [] ),
            (array) ( MyNJILGA_Dues_Settings::assessment()['qualifiers'] ?? [] )
        );
    }
}
