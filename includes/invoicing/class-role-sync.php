<?php
/**
 * Which WordPress role a paying member gets, and how it is applied — one
 * class that settle() (a paid invoice), the login / registration hook, an
 * approved application and a claimed invite all go through, so "a
 * Professional member has the `professional` role" has ONE definition
 * instead of four copies of a loop.
 *
 * The split follows MyNJILGA_My_Membership and MyNJILGA_Ledger_Totals: the
 * decisions are pure functions over plain arrays (no WordPress, no
 * FluentCRM, unit-tested in tests/RoleSyncTest.php); the methods that
 * touch WordPress and FluentCRM are thin glue underneath that only gather
 * the facts and carry out the decision.
 *
 * The rules (add-only by design — see the spec's "Implementation status";
 * the spec's own Rule numbers are given where one applies):
 *
 *   Wanted role (spec Rule 2)
 *     The role of the FIRST category, in Settings order, whose tag the
 *     contact carries; else the default category's role; else ''. That is
 *     exactly the rule pricing uses to pick a category
 *     (MyNJILGA_Pricing_Engine::category_for), so a member is billed and
 *     roled as the same category. It is read from the contact's CURRENT
 *     CRM tags at the moment of payment — never from the invoice's frozen
 *     roster snapshot, which was taken when the preview ran and misses a
 *     member tagged (or re-tagged) afterwards.
 *   Snapshot fallback
 *     Only when the current tags resolve to '' does the role frozen in the
 *     snapshot member apply, so what settle() did before this class is a
 *     strict subset of what it does now.
 *   Add-only
 *     plan() returns roles to ADD and nothing else: no removal, no swap. A
 *     member who changes category ends up holding both roles until the
 *     downgrade sweep (the only thing that ever removes one) runs;
 *     removing on a tag change would flap while the plugin swaps category
 *     tags as detach-then-attach, so it is deliberately left out.
 *   Privileged roles
 *     Never granted by a payment. Settings lets any role, `administrator`
 *     included, be mapped to a category, and a payment then grants it to
 *     everyone in that category — a $30 Law Student included. A role
 *     holding any capability in PRIVILEGED_CAPS is refused and reported as
 *     role_privileged.
 *   Undefined roles (spec Rule 5, widened)
 *     A reported miss, not a silent skip (role_undefined) — with one
 *     exception: the `professional` role (MyNJILGA_Payment_Listener::WP_ROLE,
 *     the role every seeded category maps to) is created on demand with the
 *     single capability `read`, because nothing else in the plugin ever
 *     creates it and a site without it would otherwise never grant
 *     anything. Every other undefined role is a decision for the site
 *     owner, not for a payment.
 *   Contact -> account (spec Rule 6)
 *     contact->user_id; if that user no longer exists, the account whose
 *     email is the contact's. A stale user_id used to block the email
 *     match altogether. No link is ever written here: an email match
 *     grants the role and leaves the contact alone.
 *
 * Outcomes (the `status` of every result):
 *   changed             the role was added
 *   unchanged           the account already held it
 *   no_role_configured  the wanted role is '' (category mapped to "no role",
 *                       or no category at all and no default)
 *   role_undefined      the role is not defined on this site
 *   role_privileged     the role holds administrator-level capabilities
 *   no_account          no WordPress account for this contact
 *   no_contact          there is no contact (not in FluentCRM, or FluentCRM is off)
 *   error               a Throwable while applying it (settle() only)
 *
 * role_undefined and role_privileged are misconfigurations, so the payment
 * that meets one leaves a callout for the admin (problem()) besides the
 * Company Note: a stored [status, role, count, since, last] that keeps
 * counting while the same problem recurs and clears itself once a payment
 * grants or finds that role, or once no category maps to it any more.
 */
class MyNJILGA_Role_Sync {

    const OPTION_PROBLEM = 'njilga_role_sync_problem';

    const STATUS_CHANGED    = 'changed';
    const STATUS_UNCHANGED  = 'unchanged';
    const STATUS_NO_ROLE    = 'no_role_configured';
    const STATUS_UNDEFINED  = 'role_undefined';
    const STATUS_PRIVILEGED = 'role_privileged';
    const STATUS_NO_ACCOUNT = 'no_account';
    const STATUS_NO_CONTACT = 'no_contact';
    const STATUS_ERROR      = 'error';

    /** Misconfigurations the admin has to fix — the ones problem() reports. */
    const PROBLEM_STATUSES = [ self::STATUS_PRIVILEGED, self::STATUS_UNDEFINED ];

    /** The order outcomes are listed in, in counts and in the Company Note. */
    const OUTCOME_ORDER = [
        self::STATUS_CHANGED,
        self::STATUS_UNCHANGED,
        self::STATUS_NO_ACCOUNT,
        self::STATUS_NO_CONTACT,
        self::STATUS_NO_ROLE,
        self::STATUS_UNDEFINED,
        self::STATUS_PRIVILEGED,
        self::STATUS_ERROR,
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
        'manage_network',
    ];

    // =========================================================================
    // Pure logic — no WordPress, no FluentCRM
    // =========================================================================

    /**
     * The wanted role for a contact who carries these category tags (spec Rule 2).
     * Delegates the choice of category to MyNJILGA_Pricing_Engine::category_for
     * so it can never drift from the category the member is billed as.
     *
     * A matched category whose role is '' ("— no role —" in Settings) wins
     * over the default: mapping a category to no role means no role, not
     * "fall through to the default's".
     *
     * @param array<int,array<string,mixed>> $categories Settings order, as MyNJILGA_Dues_Settings::categories().
     * @param array<int,string>              $heldSlugs  Category tag slugs the contact carries.
     */
    public static function resolve_role( array $categories, array $heldSlugs, string $defaultKey ): string {
        $key = MyNJILGA_Pricing_Engine::category_for( $heldSlugs, $categories, $defaultKey );
        if ( $key === null ) {
            return '';
        }
        foreach ( $categories as $cat ) {
            if ( (string) ( $cat['key'] ?? '' ) === $key ) {
                return (string) ( $cat['role'] ?? '' );
            }
        }
        return '';
    }

    /**
     * What to do for one account and one wanted role (add-only; spec Rules 4-5). ADD-ONLY:
     * the only thing this ever asks for is adding `$desired`; there is no
     * remove list to get wrong.
     *
     * Checked in this order, so the report names the real cause: nothing
     * wanted; not defined on the site; privileged (even when the account
     * already holds it — the mapping is still dangerous and worth
     * reporting); already held; otherwise add.
     *
     * @param array<int,string>            $userRoles      The account's current role slugs.
     * @param string                       $desired        The wanted role slug ('' = none).
     * @param bool                         $desiredDefined Whether the site defines that role.
     * @param array<int|string,bool|string> $capabilities  The role's capabilities: WP_Role::$capabilities
     *   (cap => bool) or a plain list of capability names.
     * @return array{status:string,add:array<int,string>}
     */
    public static function plan( array $userRoles, string $desired, bool $desiredDefined, array $capabilities = [] ): array {
        if ( $desired === '' ) {
            return [ 'status' => self::STATUS_NO_ROLE, 'add' => [] ];
        }
        if ( ! $desiredDefined ) {
            return [ 'status' => self::STATUS_UNDEFINED, 'add' => [] ];
        }
        if ( self::is_privileged( $capabilities ) ) {
            return [ 'status' => self::STATUS_PRIVILEGED, 'add' => [] ];
        }
        if ( in_array( $desired, array_map( 'strval', $userRoles ), true ) ) {
            return [ 'status' => self::STATUS_UNCHANGED, 'add' => [] ];
        }
        return [ 'status' => self::STATUS_CHANGED, 'add' => [ $desired ] ];
    }

    /**
     * Whether a role holding these capabilities is administrator-level
     * (see the class docblock). A capability set to false is a denial, not a grant, and
     * does not count.
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
     * Each result needs a `status`; `role`, `created` and `message` are read
     * when present.
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
            if ( $status === self::STATUS_ERROR && (string) ( $r['message'] ?? '' ) !== '' ) {
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
            self::STATUS_CHANGED    => static function ( $n ) { return $n . ' granted'; },
            self::STATUS_UNCHANGED  => static function ( $n ) { return $n . ' already had it'; },
            self::STATUS_NO_ACCOUNT => static function ( $n ) { return $n . ( $n === 1 ? ' has' : ' have' ) . ' no website account'; },
            self::STATUS_NO_CONTACT => static function ( $n ) { return $n . ' not found in the CRM'; },
            self::STATUS_NO_ROLE    => static function ( $n ) { return $n . ( $n === 1 ? ' has' : ' have' ) . ' no role set for their category'; },
            self::STATUS_UNDEFINED  => static function ( $n ) { return $n . ' not granted (role not defined on this site)'; },
            self::STATUS_PRIVILEGED => static function ( $n ) { return $n . ' not granted (role is administrator-level, never granted by a payment)'; },
            self::STATUS_ERROR      => static function ( $n ) { return $n . ' failed (see the invoice\'s error note)'; },
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
            if ( $p['status'] === self::STATUS_PRIVILEGED ) {
                $out[] = "Role $role has administrator-level capabilities, so a payment never grants it — check Settings → Membership categories.";
            } elseif ( $p['status'] === self::STATUS_UNDEFINED ) {
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
     * Rules 1 and 2 run before 3, so a batch that both clears the stored
     * problem and meets a problem of its own records the new one afresh
     * rather than dropping it.
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

    // =========================================================================
    // WordPress + FluentCRM glue
    // =========================================================================

    /**
     * The wanted role for a contact from their CURRENT CRM tags (spec Rule 2),
     * sanitized as a role slug. Stops at the first category the contact
     * holds — category_for() would pick that one whatever else they carry,
     * so asking about the rest is wasted lookups.
     *
     * @param \FluentCrm\App\Models\Subscriber $contact
     */
    public static function wanted_role( $contact ): string {
        $categories = MyNJILGA_Dues_Settings::categories();
        $held       = [];
        foreach ( $categories as $cat ) {
            $tag = (string) ( $cat['tag'] ?? '' );
            if ( $tag !== '' && MyNJILGA_Tags::has_slug( $contact, $tag ) ) {
                $held[] = $tag;
                break;
            }
        }
        return sanitize_key( self::resolve_role( $categories, $held, (string) MyNJILGA_Dues_Settings::general( 'default_category', '' ) ) );
    }

    /**
     * The WordPress account for a contact (spec Rule 6), looked up without side
     * effects: contact->user_id, and when that is unset OR points at a user
     * that has since been deleted, the account with the contact's email.
     *
     * @param \FluentCrm\App\Models\Subscriber $contact
     * @return \WP_User|null
     */
    public static function user_for_contact( $contact ) {
        $userId = (int) ( $contact->user_id ?? 0 );
        if ( $userId > 0 ) {
            $user = get_user_by( 'id', $userId );
            if ( $user ) {
                return $user;
            }
        }
        $email = (string) ( $contact->email ?? '' );
        if ( $email !== '' ) {
            $user = get_user_by( 'email', $email );
            if ( $user ) {
                return $user;
            }
        }
        return null;
    }

    /**
     * Give a contact the role their category calls for, as of right now.
     * `$fallbackRole` is used only when the current tags resolve to no role
     * (the snapshot fallback) — settle() passes the snapshot member's frozen role.
     *
     * @param \FluentCrm\App\Models\Subscriber|null $contact
     * @return array{status:string,user_id:int,role:string,added:array<int,string>,created:bool}
     */
    public static function sync_contact( $contact, string $fallbackRole = '' ): array {
        if ( ! $contact ) {
            return self::result( self::STATUS_NO_CONTACT );
        }
        $role = self::wanted_role( $contact );
        if ( $role === '' ) {
            $role = sanitize_key( $fallbackRole );
        }
        return self::apply_role( $contact, $role );
    }

    /**
     * Carry out a decision for one contact and one role: find the account,
     * create `professional` if that is the missing role, refuse a privileged
     * one, add the role if it is not held.
     *
     * The account is looked up BEFORE anything is created, so a contact
     * with no website account never causes a role to be minted for nobody.
     *
     * @param \FluentCrm\App\Models\Subscriber|null $contact
     * @return array{status:string,user_id:int,role:string,added:array<int,string>,created:bool}
     */
    public static function apply_role( $contact, string $role ): array {
        if ( ! $contact ) {
            return self::result( self::STATUS_NO_CONTACT );
        }
        $role = sanitize_key( $role );
        if ( $role === '' ) {
            return self::result( self::STATUS_NO_ROLE );
        }
        $user = self::user_for_contact( $contact );
        if ( ! $user ) {
            return self::result( self::STATUS_NO_ACCOUNT, 0, $role );
        }

        $wpRole  = get_role( $role );
        $created = false;
        if ( ! $wpRole && $role === MyNJILGA_Payment_Listener::WP_ROLE ) {
            // `read` and nothing else: the role marks membership, and what a
            // member may DO with it is the site owner's call to widen.
            $created = (bool) add_role( $role, 'Professional', [ 'read' => true ] );
            $wpRole  = get_role( $role ); // Also covers another request creating it first.
        }

        $plan = self::plan(
            (array) $user->roles,
            $role,
            (bool) $wpRole,
            $wpRole ? (array) $wpRole->capabilities : []
        );
        foreach ( $plan['add'] as $add ) {
            $user->add_role( $add );
        }
        return self::result( $plan['status'], (int) $user->ID, $role, $plan['add'], $created );
    }

    /**
     * Best-effort role grant for a role chosen by the caller (an approved
     * application's category, a claimed invite's). True when the account
     * ends up holding the role. Same guards as sync_contact().
     *
     * @param \FluentCrm\App\Models\Subscriber $contact
     */
    public static function grant( $contact, string $role ): bool {
        $status = self::apply_role( $contact, $role )['status'];
        return $status === self::STATUS_CHANGED || $status === self::STATUS_UNCHANGED;
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

    /**
     * Record `$count` more members hitting a role problem. Not autoloaded:
     * it is read by one admin screen, not by every request.
     */
    public static function record_problem( string $status, string $role, int $count ): void {
        if ( $status === '' ) {
            return;
        }
        $next = self::merge_problem( self::problem(), $status, sanitize_key( $role ), $count, current_time( 'mysql' ) );
        update_option( self::OPTION_PROBLEM, $next, false );
    }

    public static function clear_problem(): void {
        delete_option( self::OPTION_PROBLEM );
    }

    /**
     * Update the stored problem from one batch's aggregate() — record what
     * it met, clear what it resolved or what no longer applies
     * (next_problem() has the rules). Writes only when something changed.
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
     * @param array<int,string> $added
     * @return array{status:string,user_id:int,role:string,added:array<int,string>,created:bool}
     */
    private static function result( string $status, int $userId = 0, string $role = '', array $added = [], bool $created = false ): array {
        return [ 'status' => $status, 'user_id' => $userId, 'role' => $role, 'added' => $added, 'created' => $created ];
    }
}
