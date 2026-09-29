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

    const STATUS_CHANGED         = 'changed';
    const STATUS_UNCHANGED       = 'unchanged';
    const STATUS_ROLE_UNDEFINED  = 'role_undefined';
    const STATUS_ROLE_PRIVILEGED = 'role_privileged';
    const STATUS_NOT_PAID        = 'not_paid';
    const STATUS_NO_ACCOUNT      = 'no_account';
    const STATUS_FAILED          = 'failed';
    /** What sync_contact() can return — the keys of every per-status count. */
    const STATUSES = [ self::STATUS_CHANGED, self::STATUS_UNCHANGED, self::STATUS_ROLE_UNDEFINED, self::STATUS_ROLE_PRIVILEGED, self::STATUS_NOT_PAID, self::STATUS_NO_ACCOUNT, self::STATUS_FAILED ];

    /** settle()-only outcomes: a snapshot member with no CRM contact, and a category mapped to no role. */
    const STATUS_NO_CONTACT = 'no_contact';
    const STATUS_NO_ROLE    = 'no_role_configured';

    /** Misconfigurations the admin has to fix — the ones problem() reports. */
    const PROBLEM_STATUSES = [ self::STATUS_ROLE_PRIVILEGED, self::STATUS_ROLE_UNDEFINED ];

    /** The order outcomes are listed in, in counts and in the Company Note. */
    const OUTCOME_ORDER = [
        self::STATUS_CHANGED,
        self::STATUS_UNCHANGED,
        self::STATUS_NO_ACCOUNT,
        self::STATUS_NO_CONTACT,
        self::STATUS_NO_ROLE,
        self::STATUS_ROLE_UNDEFINED,
        self::STATUS_ROLE_PRIVILEGED,
        self::STATUS_FAILED,
    ];

    /**
     * A role holding ANY of these is administrator-level and never granted
     * by a payment: the ones that manage the site, its users, its code or
     * (on multisite) the network. A deny-list, not an allow-list, because
     * sites legitimately map categories to custom roles we cannot enumerate.
     */
    const PRIVILEGED_CAPS = [
        'manage_options',
        'promote_users',
        'edit_users',
        'create_users',
        'delete_users',
        'install_plugins',
        'activate_plugins',
        'edit_plugins',
        'edit_themes',
        'switch_themes',
        'update_core',
        'update_plugins',
        'update_themes',
        'delete_plugins',
        'delete_themes',
        'edit_files',
        'manage_network',
        'manage_sites',
    ];

    const HOOK_CHUNK     = 'njilga_role_sync_chunk';
    const AS_GROUP       = 'njilga-roles';
    const OPTION_HISTORY = 'njilga_role_sync_history';
    const OPTION_LAST    = 'njilga_role_sync_last';
    const OPTION_PROBLEM = 'njilga_role_sync_problem';

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
     * Roles only the history keeps managed — no longer in the map, not the
     * legacy role — which staff may stop managing from Setup.
     *
     * @param array<int,array<string,mixed>> $categories
     * @param array<int,string>              $history
     * @return array<int,string> Sorted, unique.
     */
    public static function forgettable_roles( array $categories, array $history ): array {
        $mapped = array_map( 'strval', array_column( $categories, 'role' ) );
        $roles  = array_diff( self::managed_roles( $categories, $history ), $mapped, [ self::LEGACY_ROLE ] );
        sort( $roles );
        return array_values( $roles );
    }

    /**
     * What a sync would do to one account. A desired role the site
     * doesn't define — or one that is administrator-level — changes
     * nothing at all: never strip a member's old role and leave them with
     * none over a typo in Settings, and never hand out `administrator`
     * because a category was mapped to it.
     *
     * @param array<int|string,string>      $userRoles    WP_User::$roles (keys may be sparse).
     * @param array<int,string>             $managed
     * @param array<int|string,bool|string> $capabilities The desired role's capabilities:
     *   WP_Role::$capabilities (cap => bool) or a plain list of capability names.
     * @return array{status:string,add:array<int,string>,remove:array<int,string>}
     */
    public static function plan( array $userRoles, string $desired, array $managed, bool $desiredDefined, array $capabilities = [] ): array {
        if ( $desired !== '' && ! $desiredDefined ) {
            return [ 'status' => self::STATUS_ROLE_UNDEFINED, 'add' => [], 'remove' => [] ];
        }
        // Reported even when the account already holds it: the mapping is
        // what is dangerous, and an admin who also pays dues must not hide
        // it behind "unchanged".
        if ( $desired !== '' && self::is_privileged( $capabilities ) ) {
            return [ 'status' => self::STATUS_ROLE_PRIVILEGED, 'add' => [], 'remove' => [] ];
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

    /**
     * Whether a role holding these capabilities is administrator-level
     * (PRIVILEGED_CAPS). A capability set to false is a denial, not a
     * grant, and does not count.
     *
     * @param array<int|string,bool|string> $capabilities cap => bool, or a list of names.
     */
    public static function is_privileged( array $capabilities ): bool {
        foreach ( $capabilities as $key => $value ) {
            if ( is_int( $key ) ) {
                $cap = (string) $value; // A plain list: the value is the name.
            } elseif ( $value ) {
                $cap = (string) $key;   // cap => granted.
            } else {
                continue;
            }
            if ( in_array( $cap, self::PRIVILEGED_CAPS, true ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Roll a batch of results up for reporting and the problem callout.
     * Each result needs a `status`; `role`, `created` and `message` are
     * read when present.
     *
     *   counts    status => number of results, nonzero ones only, in OUTCOME_ORDER
     *   granted   changed + unchanged: members who now hold their role
     *   problems  [ [status, role, count] ] for role_privileged / role_undefined,
     *             most serious first (privileged before undefined, then most
     *             members, then role name) so the callout is deterministic
     *   ok_roles  roles at least one member was granted or already held
     *   created   roles this batch created on the site
     *   errors    distinct error messages, first 3
     *
     * @param array<int,array<string,mixed>> $results
     * @return array{counts:array<string,int>,granted:int,problems:array<int,array{status:string,role:string,count:int}>,ok_roles:array<int,string>,created:array<int,string>,errors:array<int,string>}
     */
    public static function aggregate( array $results ): array {
        $tally   = [];
        $groups  = [];
        $ok      = [];
        $created = [];
        $errors  = [];
        foreach ( $results as $r ) {
            $status = (string) ( $r['status'] ?? '' );
            if ( $status === '' ) {
                continue;
            }
            $role             = (string) ( $r['role'] ?? '' );
            $tally[ $status ] = ( $tally[ $status ] ?? 0 ) + 1;

            if ( ( $status === self::STATUS_CHANGED || $status === self::STATUS_UNCHANGED ) && $role !== '' ) {
                $ok[ $role ] = true;
            }
            if ( in_array( $status, self::PROBLEM_STATUSES, true ) ) {
                $groupKey = $status . '|' . $role;
                if ( ! isset( $groups[ $groupKey ] ) ) {
                    $groups[ $groupKey ] = [ 'status' => $status, 'role' => $role, 'count' => 0 ];
                }
                $groups[ $groupKey ]['count']++;
            }
            if ( ! empty( $r['created'] ) && $role !== '' ) {
                $created[ $role ] = true;
            }
            if ( $status === self::STATUS_FAILED && (string) ( $r['message'] ?? '' ) !== '' ) {
                $errors[ (string) $r['message'] ] = true;
            }
        }

        $counts = [];
        foreach ( self::OUTCOME_ORDER as $status ) {
            if ( isset( $tally[ $status ] ) ) {
                $counts[ $status ] = $tally[ $status ];
                unset( $tally[ $status ] );
            }
        }
        foreach ( $tally as $status => $n ) { // A status this class does not know: keep it, last.
            $counts[ (string) $status ] = $n;
        }

        $problems = array_values( $groups );
        $rank     = array_flip( self::PROBLEM_STATUSES );
        usort( $problems, static function ( $a, $b ) use ( $rank ) {
            return [ $rank[ $a['status'] ], -$a['count'], $a['role'] ] <=> [ $rank[ $b['status'] ], -$b['count'], $b['role'] ];
        } );

        return [
            'counts'   => $counts,
            'granted'  => ( $counts[ self::STATUS_CHANGED ] ?? 0 ) + ( $counts[ self::STATUS_UNCHANGED ] ?? 0 ),
            'problems' => $problems,
            'ok_roles' => array_map( 'strval', array_keys( $ok ) ),
            'created'  => array_map( 'strval', array_keys( $created ) ),
            'errors'   => array_slice( array_map( 'strval', array_keys( $errors ) ), 0, 3 ),
        ];
    }

    /**
     * The plain-English role line for a Company Note, from aggregate()'s
     * counts: "WordPress role: 3 granted, 1 already had it, 2 have no
     * website account, 1 not granted (role not defined on this site)".
     *
     * @param array<string,int> $counts
     */
    public static function describe_outcomes( array $counts ): string {
        $phrase = [
            self::STATUS_CHANGED         => static function ( $n ) { return $n . ' granted'; },
            self::STATUS_UNCHANGED       => static function ( $n ) { return $n . ' already had it'; },
            self::STATUS_NO_ACCOUNT      => static function ( $n ) { return $n . ( $n === 1 ? ' has' : ' have' ) . ' no website account'; },
            self::STATUS_NO_CONTACT      => static function ( $n ) { return $n . ' not found in the CRM'; },
            self::STATUS_NO_ROLE         => static function ( $n ) { return $n . ( $n === 1 ? ' has' : ' have' ) . ' no role set for their category'; },
            self::STATUS_ROLE_UNDEFINED  => static function ( $n ) { return $n . ' not granted (role not defined on this site)'; },
            self::STATUS_ROLE_PRIVILEGED => static function ( $n ) { return $n . ' not granted (role is administrator-level, never granted by a payment)'; },
            self::STATUS_FAILED          => static function ( $n ) { return $n . ' failed (see the invoice\'s error note)'; },
        ];

        $parts = [];
        foreach ( $counts as $status => $n ) {
            $n = (int) $n;
            if ( $n <= 0 ) {
                continue;
            }
            $parts[] = isset( $phrase[ $status ] ) ? $phrase[ $status ]( $n ) : $n . ' ' . $status;
        }
        return $parts ? 'WordPress role: ' . implode( ', ', $parts ) : 'WordPress role: no members to update';
    }

    /**
     * The what-to-do sentences for a batch's problems and created roles —
     * the part of the Company Note that tells staff how to fix it.
     *
     * @param array<int,array{status:string,role:string,count:int}> $problems aggregate()['problems']
     * @param array<int,string>                                     $created  aggregate()['created']
     */
    public static function describe_problems( array $problems, array $created ): string {
        $out = [];
        foreach ( $problems as $p ) {
            $role = "'" . $p['role'] . "'";
            if ( $p['status'] === self::STATUS_ROLE_PRIVILEGED ) {
                $out[] = "Role $role has administrator-level capabilities, so a payment never grants it — check Settings → Membership categories.";
            } elseif ( $p['status'] === self::STATUS_ROLE_UNDEFINED ) {
                $out[] = "Role $role is mapped in Settings → Membership categories but is not defined on this site.";
            }
        }
        foreach ( $created as $role ) {
            $out[] = "The '$role' role did not exist on this site, so it was created (capability: read only).";
        }
        return implode( ' ', $out );
    }

    /**
     * Fold one more sighting of a problem into the stored one: the same
     * status + role keeps counting from the original `since`; a different
     * one replaces it and starts again.
     *
     * @param array<string,mixed>|null $stored
     * @return array{status:string,role:string,count:int,since:string,last:string}
     */
    public static function merge_problem( ?array $stored, string $status, string $role, int $count, string $now ): array {
        $count = max( 1, $count );
        if ( $stored && (string) ( $stored['status'] ?? '' ) === $status && (string) ( $stored['role'] ?? '' ) === $role ) {
            $since = (string) ( $stored['since'] ?? '' );
            return [
                'status' => $status,
                'role'   => $role,
                'count'  => max( 0, (int) ( $stored['count'] ?? 0 ) ) + $count,
                'since'  => $since !== '' ? $since : $now,
                'last'   => $now,
            ];
        }
        return [ 'status' => $status, 'role' => $role, 'count' => $count, 'since' => $now, 'last' => $now ];
    }

    /**
     * The problem to store after a batch, or null for none.
     *
     *   1. Resolved  — a member was granted, or already held, the very role
     *                  the stored problem is about.
     *   2. Fixed     — no category maps to that role any more (the mapping
     *                  was corrected; a privileged role never gets granted,
     *                  so rule 1 alone would leave that callout up forever).
     *   3. Recorded  — this batch's most serious problem, merged into what
     *                  survived 1 and 2.
     *
     * @param array<string,mixed>|null $stored      problem() as stored.
     * @param array<string,mixed>      $agg         aggregate()'s return.
     * @param array<int,string>        $mappedRoles Every role a category maps to right now.
     * @return array{status:string,role:string,count:int,since:string,last:string}|null
     */
    public static function next_problem( ?array $stored, array $agg, array $mappedRoles, string $now ): ?array {
        $problem = $stored;
        if ( $problem && in_array( (string) $problem['role'], (array) ( $agg['ok_roles'] ?? [] ), true ) ) {
            $problem = null;
        }
        if ( $problem && ! in_array( (string) $problem['role'], $mappedRoles, true ) ) {
            $problem = null;
        }
        $worst = ( (array) ( $agg['problems'] ?? [] ) )[0] ?? null;
        if ( $worst ) {
            $problem = self::merge_problem( $problem, (string) $worst['status'], (string) $worst['role'], (int) $worst['count'], $now );
        }
        return $problem;
    }

    /**
     * What is wrong with a mapped role RIGHT NOW: '' when a payment could
     * grant it, else the status a payment would report for it.
     *
     *   - Nothing maps to the role any more   -> ''  (the mapping was fixed)
     *   - The role is not defined             -> role_undefined
     *   - The role holds an administrator-
     *     level capability                    -> role_privileged
     *   - Otherwise                           -> ''  (grantable)
     *
     * Judged from the live role alone, never from what was stored: creating
     * a missing role that is itself privileged, or deleting a privileged role
     * that is still mapped, changes the problem, it does not fix it.
     *
     * @param array<int,string>             $mappedRoles  Every role a category maps to right now.
     * @param array<int|string,bool|string> $capabilities The role's capabilities now ([] when undefined).
     */
    public static function problem_status_now( string $role, array $mappedRoles, bool $roleDefined, array $capabilities = [] ): string {
        if ( $role === '' || ! in_array( $role, $mappedRoles, true ) ) {
            return '';
        }
        if ( ! $roleDefined ) {
            return self::STATUS_ROLE_UNDEFINED;
        }
        return self::is_privileged( $capabilities ) ? self::STATUS_ROLE_PRIVILEGED : '';
    }

    /**
     * Whether a stored problem's role is still not grantable. The stored
     * problem is a sighting from the last payment that met it; once the admin
     * has fixed the cause it must stop being shown, not wait for the next
     * paid batch to clear it (next_problem() only runs then).
     *
     * @param array<string,mixed>           $problem      problem() as stored.
     * @param array<int,string>             $mappedRoles  Every role a category maps to right now.
     * @param array<int|string,bool|string> $capabilities The role's capabilities now ([] when undefined).
     */
    public static function problem_still_applies( array $problem, array $mappedRoles, bool $roleDefined, array $capabilities = [] ): bool {
        return self::problem_status_now( (string) ( $problem['role'] ?? '' ), $mappedRoles, $roleDefined, $capabilities ) !== '';
    }

    // -------------------------------------------------------------------------
    // WordPress + FluentCRM
    // -------------------------------------------------------------------------

    /**
     * Must run on every request: the tag hooks fire from any FluentCRM
     * write, and the scheduler's worker has to find HOOK_CHUNK.
     */
    public static function register(): void {
        add_action( 'fluent_crm/contact_added_to_tags', [ __CLASS__, 'on_tags_changed' ], 20, 2 );
        add_action( 'fluent_crm/contact_removed_from_tags', [ __CLASS__, 'on_tags_changed' ], 20, 2 );
        add_action( self::HOOK_CHUNK, [ __CLASS__, 'run_chunk' ], 10, 1 );
    }

    /**
     * Bring one contact's account in line with their category.
     *
     * @param object $contact    A FluentCRM Subscriber.
     * @param bool   $assumePaid Skip the paid test — for a caller that just
     *                           settled a payment, or checked is_paid() itself.
     * @return array{status:string,role:string,user_id:int,added:array<int,string>,removed:array<int,string>,created:bool}
     */
    public static function sync_contact( $contact, bool $assumePaid = false ): array {
        $e    = self::evaluate( $contact, $assumePaid, self::current_managed_roles(), true );
        $user = $e['user'];
        if ( $user ) {
            // Add before removing, so the account is never left role-less mid-swap.
            foreach ( $e['add'] as $role ) {
                $user->add_role( $role );
            }
            foreach ( $e['remove'] as $role ) {
                $user->remove_role( $role );
            }
        }
        return [
            'status'  => $e['status'],
            'role'    => $e['role'],
            'user_id' => $user ? (int) $user->ID : 0,
            'added'   => $e['add'],
            'removed' => $e['remove'],
            'created' => $e['created'],
        ];
    }

    /**
     * Whether a sync_contact() result left the member holding their
     * category's role — for the "role granted to N" counts.
     *
     * @param array<string,mixed> $result
     */
    public static function holds_role( array $result ): bool {
        return (string) $result['role'] !== '' && in_array( $result['status'], [ self::STATUS_CHANGED, self::STATUS_UNCHANGED ], true );
    }

    /**
     * The downgrade sweep's removal: every managed role, plus any extra
     * roles the caller knows about (the one frozen into an old invoice
     * snapshot). Core roles are never touched.
     *
     * @param object            $contact    A FluentCRM Subscriber.
     * @param array<int,string> $extraRoles
     * @return bool True when at least one role was removed.
     */
    public static function remove_managed( $contact, array $extraRoles = [] ): bool {
        $user = self::user_for_contact( $contact );
        if ( ! $user ) {
            return false;
        }
        $managed = self::managed_roles( MyNJILGA_Dues_Settings::categories(), array_merge( self::history(), array_map( 'sanitize_key', $extraRoles ) ) );
        $removed = false;
        foreach ( array_intersect( array_values( (array) $user->roles ), $managed ) as $role ) {
            $user->remove_role( $role );
            $removed = true;
        }
        return $removed;
    }

    /**
     * Paid, for role purposes: the evergreen paid tag AND "Dues Paid
     * {year}" for the current or next dues year (the evergreen tag alone
     * can linger on a lapsed member who left their firm).
     *
     * @param object $contact A FluentCRM Subscriber.
     */
    public static function is_paid( $contact ): bool {
        return MyNJILGA_Tags::has_slug( $contact, (string) MyNJILGA_Dues_Settings::general( 'paid_tag', 'dues-paid' ) )
            && MyNJILGA_Payment_Listener::paid_for_current_year( $contact );
    }

    /**
     * fluent_crm/contact_added_to_tags and …_removed_from_tags. Acts only
     * when a tag that decides membership moved: a category tag, the paid
     * tag, or this/next year's "Dues Paid {year}".
     *
     * @param object         $subscriber A FluentCRM Subscriber.
     * @param array<int,int> $tagIds     The tags actually attached/detached.
     */
    public static function on_tags_changed( $subscriber, $tagIds ): void {
        try {
            if ( ! $subscriber || ! is_array( $tagIds ) || ! self::touches_membership( array_map( 'intval', $tagIds ) ) ) {
                return;
            }
            self::sync_contact( $subscriber );
        } catch ( \Throwable $e ) {
            // Never let a role hiccup break a FluentCRM operation.
        }
    }

    /** @return array<int,string> Every role the category map has ever used. */
    public static function history(): array {
        $h = get_option( self::OPTION_HISTORY, [] );
        return array_values( array_filter( array_map( 'sanitize_key', is_array( $h ) ? $h : [] ), 'strlen' ) );
    }

    /**
     * Add a mapping's roles to the history, so a role taken out of the map
     * can still be taken away from the members who hold it.
     *
     * @param array<int,array<string,mixed>> $categories
     */
    public static function remember_roles( array $categories ): void {
        $history = self::history();
        $merged  = array_values( array_unique( array_merge( $history, array_filter( array_map( 'strval', array_column( $categories, 'role' ) ), 'strlen' ) ) ) );
        if ( $merged !== $history ) {
            update_option( self::OPTION_HISTORY, $merged, false );
        }
    }

    /**
     * Stop managing a role only the history keeps managed: a sync no
     * longer removes it from anyone. False when the role isn't forgettable
     * (still mapped, the legacy role, or never managed).
     */
    public static function forget_role( string $role ): bool {
        $role = sanitize_key( $role );
        if ( ! in_array( $role, self::forgettable_roles( MyNJILGA_Dues_Settings::categories(), self::history() ), true ) ) {
            return false;
        }
        update_option( self::OPTION_HISTORY, array_values( array_diff( self::history(), [ $role ] ) ), false );
        return true;
    }

    /** @return array<int,string> */
    public static function current_managed_roles(): array {
        return self::managed_roles( MyNJILGA_Dues_Settings::categories(), self::history() );
    }

    public static function current_signature(): string {
        return self::mapping_signature( MyNJILGA_Dues_Settings::categories(), (string) MyNJILGA_Dues_Settings::general( 'default_category', '' ) );
    }

    /**
     * Settings save/reset: remember the old mapping's roles, and when the
     * mapping changed in a way that moves anyone's role, resync everyone.
     *
     * @param array<int,array<string,mixed>> $oldCategories
     * @return int|null Contacts queued, or null when nothing needed doing.
     */
    public static function after_settings_change( array $oldCategories, string $oldSignature ): ?int {
        self::remember_roles( $oldCategories );
        if ( self::current_signature() === $oldSignature || ! MyNJILGA_Members_Data::fluentcrm_active() ) {
            return null;
        }
        return self::queue_full_sync()['contacts'];
    }

    /**
     * Resync every contact carrying the paid tag: Action Scheduler chunks
     * of general.batch_size when it's available (it ships inside
     * FluentCRM), inline otherwise — the MyNJILGA_Invoice_Creator pattern.
     *
     * @return array{mode:string,contacts:int}
     */
    public static function queue_full_sync(): array {
        $ids  = self::paid_contact_ids();
        $mode = function_exists( 'as_enqueue_async_action' ) ? 'scheduled' : 'inline';
        self::start_run( count( $ids ), $mode );
        foreach ( array_chunk( $ids, max( 1, (int) MyNJILGA_Dues_Settings::general( 'batch_size', 25 ) ) ) as $chunk ) {
            if ( $mode !== 'scheduled' || ! as_enqueue_async_action( self::HOOK_CHUNK, [ 'contact_ids' => $chunk ], self::AS_GROUP ) ) {
                self::run_chunk( $chunk ); // No scheduler, or it refused — do it here rather than lose it.
            }
        }
        return [ 'mode' => $mode, 'contacts' => count( $ids ) ];
    }

    /**
     * Action Scheduler callback (also the inline path). One contact at a
     * time, each isolated so one bad record can't take down the chunk.
     *
     * @param array<int,int>|mixed $contactIds
     * @return array<string,int> Count per status.
     */
    public static function run_chunk( $contactIds ): array {
        $counts = array_fill_keys( self::STATUSES, 0 );
        if ( ! MyNJILGA_Members_Data::fluentcrm_active() ) {
            return $counts;
        }
        foreach ( (array) $contactIds as $id ) {
            try {
                $contact = \FluentCrm\App\Models\Subscriber::find( (int) $id );
                $status  = $contact ? self::sync_contact( $contact )['status'] : self::STATUS_FAILED;
            } catch ( \Throwable $e ) {
                $status = self::STATUS_FAILED;
            }
            $counts[ $status ]++;
        }
        self::add_to_run( $counts );
        return $counts;
    }

    /**
     * A dry run of queue_full_sync(), for the Setup confirmation screen.
     *
     * @return array{counts:array<string,int>,changes:array<int,array{name:string,email:string,add:array<int,string>,remove:array<int,string>}>}
     */
    public static function preview( int $limit = 50 ): array {
        $counts  = array_fill_keys( self::STATUSES, 0 );
        $changes = [];
        $managed = self::current_managed_roles();
        $query   = self::paid_contacts_query();
        foreach ( $query ? $query->orderBy( 'id' )->get() : [] as $contact ) {
            try {
                $e = self::evaluate( $contact, false, $managed );
            } catch ( \Throwable $ex ) {
                $counts[ self::STATUS_FAILED ]++;
                continue;
            }
            $counts[ $e['status'] ]++;
            if ( $e['status'] === self::STATUS_CHANGED && count( $changes ) < $limit ) {
                $changes[] = [
                    'name'   => trim( (string) $contact->first_name . ' ' . (string) $contact->last_name ),
                    'email'  => (string) $contact->email,
                    'add'    => $e['add'],
                    'remove' => $e['remove'],
                ];
            }
        }
        return [ 'counts' => $counts, 'changes' => $changes ];
    }

    /** @return array<string,mixed> The last full run, or [] if none. */
    public static function last_run(): array {
        $last = get_option( self::OPTION_LAST, [] );
        return is_array( $last ) ? $last : [];
    }

    /**
     * The stored role problem, or null when there is none.
     *
     * @return array{status:string,role:string,count:int,since:string,last:string}|null
     */
    public static function problem(): ?array {
        $p = get_option( self::OPTION_PROBLEM, [] );
        if ( ! is_array( $p ) || (string) ( $p['status'] ?? '' ) === '' ) {
            return null;
        }
        return [
            'status' => (string) $p['status'],
            'role'   => (string) ( $p['role'] ?? '' ),
            'count'  => max( 0, (int) ( $p['count'] ?? 0 ) ),
            'since'  => (string) ( $p['since'] ?? '' ),
            'last'   => (string) ( $p['last'] ?? '' ),
        ];
    }

    public static function clear_problem(): void {
        delete_option( self::OPTION_PROBLEM );
    }

    /**
     * problem_status_now() against the live Settings and roles: '' when the
     * stored problem's role is grantable again, else what is wrong with it
     * NOW — which can differ from the stored status.
     *
     * @param array<string,mixed> $problem problem() as stored.
     */
    public static function current_problem_status( array $problem ): string {
        $mapped = [];
        foreach ( MyNJILGA_Dues_Settings::categories() as $cat ) {
            if ( (string) ( $cat['role'] ?? '' ) !== '' ) {
                $mapped[] = (string) $cat['role'];
            }
        }
        $role   = (string) ( $problem['role'] ?? '' );
        $wpRole = $role !== '' ? get_role( $role ) : null;
        return self::problem_status_now( $role, $mapped, (bool) $wpRole, $wpRole ? (array) $wpRole->capabilities : [] );
    }

    /**
     * @param array<string,mixed> $problem problem() as stored.
     */
    public static function problem_is_current( array $problem ): bool {
        return self::current_problem_status( $problem ) !== '';
    }

    /**
     * Update the stored problem from one batch's aggregate() — record what
     * it met, clear what it resolved or what no longer applies
     * (next_problem() has the rules). Writes only when something changed.
     * Not autoloaded: one admin screen reads it, not every request.
     *
     * @param array<string,mixed> $agg aggregate()'s return.
     */
    public static function record_report( array $agg ): void {
        $stored = self::problem();
        $mapped = [];
        foreach ( MyNJILGA_Dues_Settings::categories() as $cat ) {
            if ( (string) ( $cat['role'] ?? '' ) !== '' ) {
                $mapped[] = (string) $cat['role'];
            }
        }
        $next = self::next_problem( $stored, $agg, $mapped, current_time( 'mysql' ) );
        if ( $next === null ) {
            if ( $stored !== null ) {
                self::clear_problem();
            }
        } elseif ( $next !== $stored ) {
            update_option( self::OPTION_PROBLEM, $next, false );
        }
    }

    /**
     * @param object            $contact
     * @param array<int,string> $managed
     * @param bool              $apply   False for a dry run (preview): the
     *   legacy role is then reported as it would be created, not created.
     * @return array{status:string,role:string,user:?\WP_User,add:array<int,string>,remove:array<int,string>,created:bool}
     */
    private static function evaluate( $contact, bool $assumePaid, array $managed, bool $apply = false ): array {
        $out = [ 'status' => self::STATUS_NOT_PAID, 'role' => '', 'user' => null, 'add' => [], 'remove' => [], 'created' => false ];
        if ( ! $contact || ( ! $assumePaid && ! self::is_paid( $contact ) ) ) {
            return $out;
        }
        $user = self::user_for_contact( $contact );
        if ( ! $user ) {
            $out['status'] = self::STATUS_NO_ACCOUNT;
            return $out;
        }
        $desired = self::desired_role( $contact );
        $wpRole  = $desired !== '' ? get_role( $desired ) : null;
        $created = false;
        $caps    = $wpRole ? (array) $wpRole->capabilities : [];
        if ( ! $wpRole && $desired === self::LEGACY_ROLE ) {
            // The role every seeded category maps to, and nothing else in
            // the plugin ever creates it: a site without it would never
            // grant anything. `read` and nothing else — what a member may
            // DO with it is the site owner's call to widen. Every other
            // undefined role is a decision for the site owner, not for a
            // payment.
            $caps = [ 'read' => true ];
            if ( $apply ) {
                $created = (bool) add_role( $desired, 'Professional', $caps );
                $wpRole  = get_role( $desired ); // Also covers another request creating it first.
            }
            $defined = $apply ? $wpRole !== null : true;
        } else {
            $defined = $desired === '' || $wpRole !== null;
        }
        $plan = self::plan( (array) $user->roles, $desired, $managed, $defined, $caps );
        return array_merge( $plan, [ 'role' => $desired, 'user' => $user, 'created' => $created ] );
    }

    /** @param object $contact A FluentCRM Subscriber. */
    private static function desired_role( $contact ): string {
        $categories = MyNJILGA_Dues_Settings::categories();
        $held       = [];
        foreach ( $categories as $cat ) {
            if ( (string) $cat['tag'] !== '' && MyNJILGA_Tags::has_slug( $contact, (string) $cat['tag'] ) ) {
                $held[] = (string) $cat['tag'];
                break; // The first match in Order decides.
            }
        }
        return self::resolve_role( $categories, $held, (string) MyNJILGA_Dues_Settings::general( 'default_category', '' ) );
    }

    /**
     * The contact's account: its linked user_id, else the user with its
     * email — the lookup the role grant has always used.
     *
     * @param object $contact A FluentCRM Subscriber.
     */
    private static function user_for_contact( $contact ): ?\WP_User {
        $userId = (int) ( $contact->user_id ?? 0 );
        $user   = $userId > 0 ? get_user_by( 'id', $userId ) : false;
        if ( ! $user && $userId <= 0 && ! empty( $contact->email ) ) {
            $user = get_user_by( 'email', (string) $contact->email );
        }
        return $user ?: null;
    }

    /** @param array<int,int> $tagIds */
    private static function touches_membership( array $tagIds ): bool {
        $ids = [ MyNJILGA_Tags::resolve_slug( (string) MyNJILGA_Dues_Settings::general( 'paid_tag', 'dues-paid' ) ) ];
        foreach ( MyNJILGA_Dues_Settings::categories() as $cat ) {
            if ( (string) $cat['tag'] !== '' ) {
                $ids[] = MyNJILGA_Tags::resolve_slug( (string) $cat['tag'] );
            }
        }
        if ( array_intersect( $tagIds, array_map( 'intval', array_filter( $ids ) ) ) ) {
            return true;
        }
        // Looked up fresh, not cached: settle() creates next year's
        // "Dues Paid {year}" moments before attaching it.
        $year = MyNJILGA_Invoicing::current_dues_year();
        foreach ( [ $year, $year + 1 ] as $y ) {
            $id = MyNJILGA_Tags::find_title_id( MyNJILGA_Dues_Settings::year_tag( 'year_paid_tag_pattern', $y ) );
            if ( $id && in_array( $id, $tagIds, true ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Every contact carrying the paid tag, in any status — unsubscribing
     * from email isn't leaving the association.
     *
     * @return object|null A FluentCRM query builder.
     */
    private static function paid_contacts_query() {
        if ( ! MyNJILGA_Members_Data::fluentcrm_active() ) {
            return null;
        }
        $paidId = MyNJILGA_Tags::resolve_slug( (string) MyNJILGA_Dues_Settings::general( 'paid_tag', 'dues-paid' ) );
        return $paidId ? \FluentCrm\App\Models\Subscriber::filterByTags( [ $paidId ] ) : null;
    }

    /** @return array<int,int> */
    private static function paid_contact_ids(): array {
        $query = self::paid_contacts_query();
        $ids   = [];
        foreach ( $query ? $query->orderBy( 'id' )->pluck( 'id' ) : [] as $id ) {
            $ids[] = (int) $id;
        }
        return $ids;
    }

    private static function start_run( int $contacts, string $mode ): void {
        update_option( self::OPTION_LAST, [
            'started_at' => current_time( 'mysql' ),
            'mode'       => $mode,
            'contacts'   => $contacts,
            'done'       => 0,
            'counts'     => array_fill_keys( self::STATUSES, 0 ),
        ], false );
    }

    /**
     * Fold one chunk's counts into the last run. Chunks can overlap a new
     * run started meanwhile; the counts are a progress display, not a ledger.
     *
     * @param array<string,int> $counts
     */
    private static function add_to_run( array $counts ): void {
        $last = self::last_run();
        if ( ! $last ) {
            return;
        }
        foreach ( $counts as $status => $n ) {
            $last['counts'][ $status ] = (int) ( $last['counts'][ $status ] ?? 0 ) + (int) $n;
            $last['done']              = (int) ( $last['done'] ?? 0 ) + (int) $n;
        }
        update_option( self::OPTION_LAST, $last, false );
    }
}
