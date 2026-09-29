<?php
/**
 * WordPress role sync — a paid member's role follows their category
 * (Settings → Membership categories): granted on payment, swapped when
 * their category tag or the mapping changes, removed by the downgrade
 * sweep. This is the one place that decides and applies it; the payment
 * listener, login/registration, application approval, invite claim, the
 * FluentCRM tag hooks, Settings and the Setup page all call in here.
 *
 * Only MANAGED roles are ever removed: every role the category map uses
 * or has used (history, recorded on each Settings save/reset), plus the
 * legacy `professional` — never a WordPress core role. Contacts who
 * aren't paid for the current or next dues year are left alone; taking a
 * role away for non-payment is the downgrade sweep's job.
 *
 * The top half is pure (tests/RoleSyncTest.php); the bottom half talks to
 * WordPress and FluentCRM.
 * Spec: docs/superpowers/specs/2026-09-29-role-sync-design.md
 */
class MyNJILGA_Role_Sync {

    /** Granted when a category maps to one, never removed by a sync. */
    const CORE_ROLES = [ 'administrator', 'editor', 'author', 'contributor', 'subscriber' ];

    /** The role every category used before roles were configurable — always managed. */
    const LEGACY_ROLE = 'professional';

    const STATUS_CHANGED        = 'changed';
    const STATUS_UNCHANGED      = 'unchanged';
    const STATUS_ROLE_UNDEFINED = 'role_undefined';
    const STATUS_NOT_PAID       = 'not_paid';
    const STATUS_NO_ACCOUNT     = 'no_account';
    const STATUS_FAILED         = 'failed';
    const STATUSES              = [ self::STATUS_CHANGED, self::STATUS_UNCHANGED, self::STATUS_ROLE_UNDEFINED, self::STATUS_NOT_PAID, self::STATUS_NO_ACCOUNT, self::STATUS_FAILED ];

    const HOOK_CHUNK     = 'njilga_role_sync_chunk';
    const AS_GROUP       = 'njilga-roles';
    const OPTION_HISTORY = 'njilga_role_sync_history';
    const OPTION_LAST    = 'njilga_role_sync_last';

    // -------------------------------------------------------------------------
    // Pure
    // -------------------------------------------------------------------------

    /**
     * The role a contact should hold: the first category, in Settings
     * order, whose tag they carry; else the default category's; else ''.
     *
     * @param array<int,array<string,mixed>> $categories Ordered category rows.
     * @param array<int,string>              $heldSlugs  Category tag slugs the contact carries.
     */
    public static function resolve_role( array $categories, array $heldSlugs, string $defaultKey ): string {
        $held = array_flip( array_map( 'strval', $heldSlugs ) );
        foreach ( $categories as $cat ) {
            $tag = (string) ( $cat['tag'] ?? '' );
            if ( $tag !== '' && isset( $held[ $tag ] ) ) {
                return (string) ( $cat['role'] ?? '' );
            }
        }
        if ( $defaultKey !== '' ) {
            foreach ( $categories as $cat ) {
                if ( (string) ( $cat['key'] ?? '' ) === $defaultKey ) {
                    return (string) ( $cat['role'] ?? '' );
                }
            }
        }
        return '';
    }

    /**
     * Every role a sync may take away: mapped now, mapped before, and the
     * legacy role — minus WordPress core roles.
     *
     * @param array<int,array<string,mixed>> $categories
     * @param array<int,string>              $history
     * @return array<int,string> Sorted, unique.
     */
    public static function managed_roles( array $categories, array $history ): array {
        $roles = array_map( 'strval', array_merge( array_column( $categories, 'role' ), $history, [ self::LEGACY_ROLE ] ) );
        $roles = array_diff( array_unique( array_filter( $roles, 'strlen' ) ), self::CORE_ROLES );
        sort( $roles );
        return array_values( $roles );
    }

    /**
     * What a sync would do to one account. A desired role the site
     * doesn't define changes nothing at all — never strip a member's old
     * role and leave them with none over a typo in Settings.
     *
     * @param array<int|string,string> $userRoles WP_User::$roles (keys may be sparse).
     * @param array<int,string>        $managed
     * @return array{status:string,add:array<int,string>,remove:array<int,string>}
     */
    public static function plan( array $userRoles, string $desired, array $managed, bool $desiredDefined ): array {
        if ( $desired !== '' && ! $desiredDefined ) {
            return [ 'status' => self::STATUS_ROLE_UNDEFINED, 'add' => [], 'remove' => [] ];
        }
        $userRoles = array_values( array_map( 'strval', $userRoles ) );
        $remove    = array_values( array_diff( array_intersect( $userRoles, $managed ), [ $desired ] ) );
        $add       = ( $desired !== '' && ! in_array( $desired, $userRoles, true ) ) ? [ $desired ] : [];
        return [
            'status' => ( $add || $remove ) ? self::STATUS_CHANGED : self::STATUS_UNCHANGED,
            'add'    => $add,
            'remove' => $remove,
        ];
    }

    /**
     * A fingerprint of everything that decides a member's role: the
     * ordered tag → role pairs and the default category's role. Labels,
     * prices and tiers don't move anyone's role, so they're left out.
     *
     * @param array<int,array<string,mixed>> $categories
     */
    public static function mapping_signature( array $categories, string $defaultKey ): string {
        $pairs = [];
        foreach ( $categories as $cat ) {
            $pairs[] = [ (string) ( $cat['tag'] ?? '' ), (string) ( $cat['role'] ?? '' ) ];
        }
        return md5( (string) json_encode( [ $pairs, self::resolve_role( $categories, [], $defaultKey ) ] ) );
    }
}
