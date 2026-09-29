<?php
/**
 * The pure half of MyNJILGA_Role_Sync — which role a paying member gets,
 * what to do about it, and how a batch of outcomes is reported:
 *
 *   resolve_role()    first category in Settings order whose tag the contact
 *                     holds, else the default's, else '' — the very rule
 *                     pricing uses to pick a category
 *   plan()            ADD-ONLY: never a removal, whatever the input
 *   is_privileged()   the administrator-level deny-list
 *   aggregate() / describe_outcomes() / describe_problems()
 *                     the per-status tally and the Company Note wording
 *   merge_problem() / next_problem()
 *                     the "role problem" callout: accumulates, and clears
 *                     when a payment grants that role or the mapping is fixed
 *
 * The WordPress-facing methods (sync_contact, apply_role, settle()'s
 * accounting) need WordPress and FluentCRM and are not run here.
 */
declare( strict_types=1 );

require_once dirname( __DIR__ ) . '/includes/invoicing/class-role-sync.php';
// Loaded for the WP_ROLE constant the lazily created role is pinned to.
require_once dirname( __DIR__ ) . '/includes/invoicing/class-payment-listener.php';

class RoleSyncTest extends NJILGA_TestCase {

    private const NOW = '2026-09-29 12:00:00';

    /**
     * The seeded categories in their seeded order (past president, senior
     * trustee, law student, emerging professional, professional), with the
     * given key => role overrides. A key not listed keeps the seeded role.
     *
     * @param array<string,string> $roleByKey
     * @return array<int,array<string,mixed>>
     */
    private function categories( array $roleByKey = [] ): array {
        $cats = MyNJILGA_Dues_Settings::defaults()['categories'];
        foreach ( $cats as $i => $cat ) {
            if ( isset( $roleByKey[ $cat['key'] ] ) ) {
                $cats[ $i ]['role'] = $roleByKey[ $cat['key'] ];
            }
        }
        return $cats;
    }

    /** A site that maps students to their own role and professionals to `professional`. */
    private function studentSite(): array {
        return $this->categories( [ 'law_student' => 'student', 'professional' => 'professional' ] );
    }

    // -------------------------------------------------------------------
    // resolve_role — the spec's Rule 2
    // -------------------------------------------------------------------

    public function test_a_professional_tag_resolves_to_the_professional_role(): void {
        $this->assertSame( 'professional', MyNJILGA_Role_Sync::resolve_role( $this->studentSite(), [ 'professional' ], 'law_student' ) );
    }

    public function test_the_first_category_in_settings_order_wins_over_a_later_one(): void {
        // law_student comes before professional in Settings, so a contact
        // carrying both is a student — whichever tag was added last.
        $cats = $this->studentSite();
        $this->assertSame( 'student', MyNJILGA_Role_Sync::resolve_role( $cats, [ 'professional', 'law-student' ], 'professional' ) );
        $this->assertSame( 'student', MyNJILGA_Role_Sync::resolve_role( $cats, [ 'law-student', 'professional' ], 'professional' ) );

        // Put professional first and the same contact becomes a professional: order is the whole rule.
        $reordered = array_reverse( $cats );
        $this->assertSame( 'professional', MyNJILGA_Role_Sync::resolve_role( $reordered, [ 'law-student', 'professional' ], '' ) );
    }

    public function test_a_customised_mapping_is_followed(): void {
        $cats = $this->categories( [ 'law_student' => 'student' ] );
        $this->assertSame( 'student', MyNJILGA_Role_Sync::resolve_role( $cats, [ 'law-student' ], 'professional' ) );
        $this->assertSame( 'professional', MyNJILGA_Role_Sync::resolve_role( $cats, [ 'emerging-professional' ], 'professional' ) );
    }

    public function test_no_category_tag_falls_back_to_the_default_categorys_role(): void {
        $cats = $this->categories( [ 'law_student' => 'student', 'professional' => 'professional' ] );
        $this->assertSame( 'student', MyNJILGA_Role_Sync::resolve_role( $cats, [], 'law_student' ) );
        $this->assertSame( 'professional', MyNJILGA_Role_Sync::resolve_role( $cats, [ 'some-other-tag', 'dues-paid' ], 'professional' ) );
    }

    public function test_no_category_tag_and_no_default_resolves_to_nothing(): void {
        $this->assertSame( '', MyNJILGA_Role_Sync::resolve_role( $this->studentSite(), [], '' ) );
        $this->assertSame( '', MyNJILGA_Role_Sync::resolve_role( $this->studentSite(), [ 'dues-paid' ], '' ) );
    }

    public function test_a_default_that_names_no_category_resolves_to_nothing(): void {
        $this->assertSame( '', MyNJILGA_Role_Sync::resolve_role( $this->studentSite(), [], 'deleted_category' ) );
    }

    public function test_a_category_mapped_to_no_role_means_no_role_not_the_defaults(): void {
        // "— no role —" in Settings is a choice; falling through to the
        // default's role would grant the one the owner declined to give.
        $cats = $this->categories( [ 'law_student' => '', 'professional' => 'professional' ] );
        $this->assertSame( '', MyNJILGA_Role_Sync::resolve_role( $cats, [ 'law-student' ], 'professional' ) );
        $this->assertSame( '', MyNJILGA_Role_Sync::resolve_role( $cats, [], 'law_student' ) );
    }

    public function test_a_category_with_no_tag_never_matches_a_contact(): void {
        $cats = $this->studentSite();
        $cats[2]['tag'] = ''; // law_student loses its tag
        $this->assertSame( 'professional', MyNJILGA_Role_Sync::resolve_role( $cats, [ '' ], 'professional' ), 'an empty slug is not a tag anyone holds' );
    }

    public function test_resolve_role_is_exactly_the_category_pricing_would_pick(): void {
        // Every subset of the category tags (plus a stranger), against every
        // default: the role must be the role of category_for()'s category.
        $cats = $this->categories( [ 'past_president' => 'exempt', 'senior_trustee' => 'exempt', 'law_student' => 'student', 'emerging_professional' => 'emerging', 'professional' => 'professional' ] );
        $tags = [ 'past-president', 'senior-trustee', 'law-student', 'emerging-professional', 'professional', 'unrelated' ];
        $roleOf = static function ( string $key ) use ( $cats ): string {
            foreach ( $cats as $c ) {
                if ( $c['key'] === $key ) {
                    return (string) $c['role'];
                }
            }
            return '';
        };
        $checked = 0;
        for ( $mask = 0; $mask < ( 1 << count( $tags ) ); $mask++ ) {
            $held = [];
            foreach ( $tags as $i => $t ) {
                if ( $mask & ( 1 << $i ) ) {
                    $held[] = $t;
                }
            }
            foreach ( [ '', 'professional', 'law_student', 'nope' ] as $default ) {
                $key      = MyNJILGA_Pricing_Engine::category_for( $held, $cats, $default );
                $expected = $key === null ? '' : $roleOf( $key );
                $this->assertSame( $expected, MyNJILGA_Role_Sync::resolve_role( $cats, $held, $default ), 'held=' . implode( ',', $held ) . ' default=' . $default );
                $checked++;
            }
        }
        $this->assertSame( 256, $checked );
    }

    public function test_todays_behaviour_is_reproduced_under_the_seeded_settings(): void {
        // Seeded Settings: EVERY category and the default map to
        // 'professional', so the frozen snapshot role and the role from
        // current tags are the same string for any contact — settle()'s old
        // behaviour is unchanged for a site that never customised the mapping.
        $cats    = $this->categories();
        $default = (string) MyNJILGA_Dues_Settings::defaults()['general']['default_category'];
        foreach ( $cats as $cat ) {
            $this->assertSame( 'professional', (string) $cat['role'], $cat['key'] . ' is seeded to professional' );
            $this->assertSame( 'professional', MyNJILGA_Role_Sync::resolve_role( $cats, [ (string) $cat['tag'] ], $default ), $cat['key'] );
        }
        $this->assertSame( 'professional', MyNJILGA_Role_Sync::resolve_role( $cats, [], $default ), 'an untagged contact takes the default' );
        $this->assertSame( 'professional', MyNJILGA_Payment_Listener::WP_ROLE );
    }

    // -------------------------------------------------------------------
    // plan — add-only
    // -------------------------------------------------------------------

    public function test_plan_adds_the_role_a_member_lacks(): void {
        $this->assertSame( [ 'status' => 'changed', 'add' => [ 'professional' ] ], MyNJILGA_Role_Sync::plan( [ 'subscriber' ], 'professional', true, [ 'read' => true ] ) );
        $this->assertSame( [ 'status' => 'changed', 'add' => [ 'professional' ] ], MyNJILGA_Role_Sync::plan( [], 'professional', true ) );
    }

    public function test_plan_is_unchanged_when_the_role_is_already_held(): void {
        $this->assertSame( [ 'status' => 'unchanged', 'add' => [] ], MyNJILGA_Role_Sync::plan( [ 'subscriber', 'professional' ], 'professional', true ) );
    }

    public function test_plan_reports_a_role_the_site_does_not_define_and_adds_nothing(): void {
        $this->assertSame( [ 'status' => 'role_undefined', 'add' => [] ], MyNJILGA_Role_Sync::plan( [ 'subscriber' ], 'student', false ) );
        // Undefined is checked before "already held": a stale role slug in a user's meta must not hide the misconfiguration.
        $this->assertSame( [ 'status' => 'role_undefined', 'add' => [] ], MyNJILGA_Role_Sync::plan( [ 'student' ], 'student', false ) );
    }

    public function test_plan_with_no_role_wanted_reports_no_role_configured(): void {
        $this->assertSame( [ 'status' => 'no_role_configured', 'add' => [] ], MyNJILGA_Role_Sync::plan( [ 'subscriber' ], '', true ) );
        $this->assertSame( [ 'status' => 'no_role_configured', 'add' => [] ], MyNJILGA_Role_Sync::plan( [ 'subscriber' ], '', false ), 'nothing wanted beats nothing defined' );
    }

    public function test_plan_never_returns_a_removal_for_any_input(): void {
        $roleSets = [
            [], [ 'subscriber' ], [ 'professional' ], [ 'student' ], [ 'administrator' ], [ 'editor', 'professional' ],
            [ 'subscriber', 'professional', 'student' ], [ 'shop_manager' ], [ 'administrator', 'professional' ],
        ];
        $desired  = [ '', 'professional', 'student', 'administrator', 'editor', 'shop_manager', 'never_heard_of_it' ];
        $capSets  = [ [], [ 'read' => true ], [ 'manage_options' => true ], [ 'promote_users' => false ], [ 'edit_posts', 'edit_others_posts' ], [ 'read' => true, 'edit_users' => true ] ];

        $checked = 0;
        foreach ( $roleSets as $roles ) {
            foreach ( $desired as $want ) {
                foreach ( [ true, false ] as $defined ) {
                    foreach ( $capSets as $caps ) {
                        $plan = MyNJILGA_Role_Sync::plan( $roles, $want, $defined, $caps );

                        $this->assertSame( [ 'status', 'add' ], array_keys( $plan ), 'the plan has no remove list at all' );
                        foreach ( $plan['add'] as $added ) {
                            $this->assertSame( $want, $added, 'only the wanted role is ever added' );
                            $this->assertFalse( in_array( $added, $roles, true ), 'and never one the account already holds' );
                        }
                        $this->assertTrue( count( $plan['add'] ) <= 1 );
                        $this->assertTrue( in_array( $plan['status'], [ 'changed', 'unchanged', 'no_role_configured', 'role_undefined', 'role_privileged' ], true ), $plan['status'] );
                        // "changed" is the ONLY status that adds anything.
                        $this->assertSame( $plan['status'] === 'changed', $plan['add'] !== [] );
                        $checked++;
                    }
                }
            }
        }
        $this->assertSame( 9 * 7 * 2 * 6, $checked );
    }

    // -------------------------------------------------------------------
    // Privileged roles
    // -------------------------------------------------------------------

    public function test_the_administrator_role_is_refused(): void {
        $adminCaps = [ 'read' => true, 'edit_posts' => true, 'manage_options' => true, 'promote_users' => true, 'install_plugins' => true ];
        $this->assertTrue( MyNJILGA_Role_Sync::is_privileged( $adminCaps ) );
        $this->assertSame( [ 'status' => 'role_privileged', 'add' => [] ], MyNJILGA_Role_Sync::plan( [ 'subscriber' ], 'administrator', true, $adminCaps ) );
    }

    public function test_the_editor_role_is_allowed(): void {
        $editorCaps = [ 'read' => true, 'edit_posts' => true, 'edit_others_posts' => true, 'publish_posts' => true, 'moderate_comments' => true, 'upload_files' => true, 'manage_categories' => true ];
        $this->assertFalse( MyNJILGA_Role_Sync::is_privileged( $editorCaps ) );
        $this->assertSame( [ 'status' => 'changed', 'add' => [ 'editor' ] ], MyNJILGA_Role_Sync::plan( [ 'subscriber' ], 'editor', true, $editorCaps ) );
    }

    public function test_a_custom_role_holding_promote_users_is_refused(): void {
        $this->assertSame( 'role_privileged', MyNJILGA_Role_Sync::plan( [], 'hr_admin', true, [ 'read' => true, 'promote_users' => true ] )['status'] );
    }

    public function test_every_capability_on_the_deny_list_makes_a_role_privileged(): void {
        $deny = [ 'manage_options', 'promote_users', 'edit_users', 'create_users', 'delete_users', 'install_plugins', 'activate_plugins', 'edit_plugins', 'edit_themes', 'switch_themes', 'update_core', 'manage_network' ];
        $this->assertSame( $deny, MyNJILGA_Role_Sync::PRIVILEGED_CAPS );
        foreach ( $deny as $cap ) {
            $this->assertTrue( MyNJILGA_Role_Sync::is_privileged( [ 'read' => true, $cap => true ] ), "$cap as cap => true" );
            $this->assertTrue( MyNJILGA_Role_Sync::is_privileged( [ 'read', $cap ] ), "$cap in a plain list" );
        }
    }

    public function test_a_denied_capability_does_not_count_and_ordinary_ones_are_fine(): void {
        $this->assertFalse( MyNJILGA_Role_Sync::is_privileged( [ 'manage_options' => false, 'promote_users' => 0, 'read' => true ] ) );
        $this->assertFalse( MyNJILGA_Role_Sync::is_privileged( [] ) );
        $this->assertFalse( MyNJILGA_Role_Sync::is_privileged( [ 'read' => true, 'edit_posts' => true, 'edit_published_posts' => true, 'delete_posts' => true, 'list_users' => true ] ) );
    }

    public function test_a_privileged_role_is_reported_even_when_the_account_already_holds_it(): void {
        // The mapping is what is dangerous; an admin who also pays dues
        // must not hide it behind "unchanged".
        $this->assertSame( 'role_privileged', MyNJILGA_Role_Sync::plan( [ 'administrator' ], 'administrator', true, [ 'manage_options' => true ] )['status'] );
    }

    public function test_the_lazily_created_role_is_the_one_every_seeded_category_maps_to(): void {
        foreach ( MyNJILGA_Dues_Settings::defaults()['categories'] as $cat ) {
            $this->assertSame( MyNJILGA_Payment_Listener::WP_ROLE, $cat['role'] );
        }
    }

    // -------------------------------------------------------------------
    // aggregate / describe — the numbers and the Company Note
    // -------------------------------------------------------------------

    private function results( array $statuses ): array {
        $out = [];
        foreach ( $statuses as $s ) {
            $out[] = is_array( $s ) ? $s : [ 'status' => $s ];
        }
        return $out;
    }

    public function test_aggregate_counts_each_status_in_a_fixed_order(): void {
        $agg = MyNJILGA_Role_Sync::aggregate( $this->results( [
            'no_account', 'changed', 'error', 'unchanged', 'changed', 'role_undefined', 'no_account', 'changed', 'no_contact', 'no_role_configured', 'role_privileged',
        ] ) );
        $this->assertSame( [
            'changed' => 3, 'unchanged' => 1, 'no_account' => 2, 'no_contact' => 1, 'no_role_configured' => 1, 'role_undefined' => 1, 'role_privileged' => 1, 'error' => 1,
        ], $agg['counts'] );
        $this->assertSame( [ 'changed', 'unchanged', 'no_account', 'no_contact', 'no_role_configured', 'role_undefined', 'role_privileged', 'error' ], array_keys( $agg['counts'] ) );
        $this->assertSame( 4, $agg['granted'], 'granted = changed + unchanged' );
    }

    public function test_aggregate_of_nothing_is_empty(): void {
        $agg = MyNJILGA_Role_Sync::aggregate( [] );
        $this->assertSame( [], $agg['counts'] );
        $this->assertSame( 0, $agg['granted'] );
        $this->assertSame( [], $agg['problems'] );
        $this->assertSame( [], $agg['ok_roles'] );
    }

    public function test_aggregate_groups_problems_by_status_and_role_most_serious_first(): void {
        $agg = MyNJILGA_Role_Sync::aggregate( $this->results( [
            [ 'status' => 'role_undefined', 'role' => 'student' ],
            [ 'status' => 'role_undefined', 'role' => 'student' ],
            [ 'status' => 'role_undefined', 'role' => 'student' ],
            [ 'status' => 'role_undefined', 'role' => 'alumni' ],
            [ 'status' => 'role_privileged', 'role' => 'administrator' ],
            [ 'status' => 'no_account', 'role' => 'professional' ],
            [ 'status' => 'no_role_configured' ],
        ] ) );
        $this->assertSame( [
            [ 'status' => 'role_privileged', 'role' => 'administrator', 'count' => 1 ],
            [ 'status' => 'role_undefined', 'role' => 'student', 'count' => 3 ],
            [ 'status' => 'role_undefined', 'role' => 'alumni', 'count' => 1 ],
        ], $agg['problems'], 'privileged outranks undefined, then most members' );
    }

    public function test_aggregate_collects_ok_roles_created_roles_and_error_messages(): void {
        $agg = MyNJILGA_Role_Sync::aggregate( $this->results( [
            [ 'status' => 'changed', 'role' => 'professional', 'created' => true ],
            [ 'status' => 'changed', 'role' => 'professional', 'created' => true ],
            [ 'status' => 'unchanged', 'role' => 'student' ],
            [ 'status' => 'role_undefined', 'role' => 'alumni' ],
            [ 'status' => 'error', 'message' => 'hook exploded' ],
            [ 'status' => 'error', 'message' => 'hook exploded' ],
            [ 'status' => 'error', 'message' => 'second' ],
            [ 'status' => 'error', 'message' => 'third' ],
            [ 'status' => 'error', 'message' => 'fourth' ],
        ] ) );
        $this->assertSame( [ 'professional', 'student' ], $agg['ok_roles'], 'alumni was not granted, so it is not ok' );
        $this->assertSame( [ 'professional' ], $agg['created'] );
        $this->assertSame( [ 'hook exploded', 'second', 'third' ], $agg['errors'], 'distinct, first three' );
    }

    public function test_the_company_note_says_plainly_what_happened(): void {
        $counts = [ 'changed' => 3, 'unchanged' => 1, 'no_account' => 2, 'role_undefined' => 1 ];
        $this->assertSame(
            'WordPress role: 3 granted, 1 already had it, 2 have no website account, 1 not granted (role not defined on this site)',
            MyNJILGA_Role_Sync::describe_outcomes( $counts )
        );
    }

    public function test_the_company_note_wording_agrees_in_number_and_covers_every_status(): void {
        $this->assertSame( 'WordPress role: 1 has no website account', MyNJILGA_Role_Sync::describe_outcomes( [ 'no_account' => 1 ] ) );
        $this->assertSame( 'WordPress role: 1 has no role set for their category', MyNJILGA_Role_Sync::describe_outcomes( [ 'no_role_configured' => 1 ] ) );
        $this->assertSame( 'WordPress role: 4 have no role set for their category', MyNJILGA_Role_Sync::describe_outcomes( [ 'no_role_configured' => 4 ] ) );
        $this->assertSame( 'WordPress role: 2 not found in the CRM', MyNJILGA_Role_Sync::describe_outcomes( [ 'no_contact' => 2 ] ) );
        $this->assertSame( 'WordPress role: 1 not granted (role is administrator-level, never granted by a payment)', MyNJILGA_Role_Sync::describe_outcomes( [ 'role_privileged' => 1 ] ) );
        $this->assertSame( 'WordPress role: 1 failed (see the invoice\'s error note)', MyNJILGA_Role_Sync::describe_outcomes( [ 'error' => 1 ] ) );
        $this->assertSame( 'WordPress role: no members to update', MyNJILGA_Role_Sync::describe_outcomes( [] ) );
        $this->assertSame( 'WordPress role: no members to update', MyNJILGA_Role_Sync::describe_outcomes( [ 'changed' => 0 ] ), 'zero counts are not listed' );
    }

    public function test_the_problem_sentences_name_the_role_and_where_to_fix_it(): void {
        $text = MyNJILGA_Role_Sync::describe_problems(
            [
                [ 'status' => 'role_privileged', 'role' => 'administrator', 'count' => 2 ],
                [ 'status' => 'role_undefined', 'role' => 'student', 'count' => 1 ],
            ],
            [ 'professional' ]
        );
        $this->assertSame(
            "Role 'administrator' has administrator-level capabilities, so a payment never grants it — check Settings → Membership categories."
            . " Role 'student' is mapped in Settings → Membership categories but is not defined on this site."
            . " The 'professional' role did not exist on this site, so it was created (capability: read only).",
            $text
        );
        $this->assertSame( '', MyNJILGA_Role_Sync::describe_problems( [], [] ) );
    }

    // -------------------------------------------------------------------
    // The stored problem callout
    // -------------------------------------------------------------------

    public function test_a_first_problem_is_stored_with_its_first_and_last_sighting(): void {
        $this->assertSame(
            [ 'status' => 'role_undefined', 'role' => 'student', 'count' => 2, 'since' => self::NOW, 'last' => self::NOW ],
            MyNJILGA_Role_Sync::merge_problem( null, 'role_undefined', 'student', 2, self::NOW )
        );
    }

    public function test_the_same_problem_accumulates_and_keeps_its_since(): void {
        $stored = [ 'status' => 'role_undefined', 'role' => 'student', 'count' => 2, 'since' => '2026-09-01 08:00:00', 'last' => '2026-09-02 08:00:00' ];
        $this->assertSame(
            [ 'status' => 'role_undefined', 'role' => 'student', 'count' => 5, 'since' => '2026-09-01 08:00:00', 'last' => self::NOW ],
            MyNJILGA_Role_Sync::merge_problem( $stored, 'role_undefined', 'student', 3, self::NOW )
        );
    }

    public function test_a_different_problem_replaces_the_stored_one(): void {
        $stored = [ 'status' => 'role_undefined', 'role' => 'student', 'count' => 9, 'since' => '2026-09-01 08:00:00', 'last' => '2026-09-02 08:00:00' ];
        $this->assertSame(
            [ 'status' => 'role_privileged', 'role' => 'administrator', 'count' => 1, 'since' => self::NOW, 'last' => self::NOW ],
            MyNJILGA_Role_Sync::merge_problem( $stored, 'role_privileged', 'administrator', 1, self::NOW )
        );
        // Same status, different role: also a different problem — the count is not carried over.
        $this->assertSame(
            [ 'status' => 'role_undefined', 'role' => 'alumni', 'count' => 1, 'since' => self::NOW, 'last' => self::NOW ],
            MyNJILGA_Role_Sync::merge_problem( $stored, 'role_undefined', 'alumni', 1, self::NOW )
        );
    }

    public function test_a_count_below_one_still_records_one_sighting(): void {
        $this->assertSame( 1, MyNJILGA_Role_Sync::merge_problem( null, 'role_undefined', 'student', 0, self::NOW )['count'] );
    }

    private function batch( array $results ): array {
        return MyNJILGA_Role_Sync::aggregate( $this->results( $results ) );
    }

    public function test_a_batch_that_meets_a_problem_records_its_worst_one(): void {
        $next = MyNJILGA_Role_Sync::next_problem(
            null,
            $this->batch( [ [ 'status' => 'role_undefined', 'role' => 'student' ], [ 'status' => 'role_privileged', 'role' => 'administrator' ] ] ),
            [ 'student', 'administrator', 'professional' ],
            self::NOW
        );
        $this->assertSame( 'role_privileged', $next['status'] );
        $this->assertSame( 'administrator', $next['role'] );
    }

    public function test_a_recurring_problem_keeps_counting_across_payments(): void {
        $agg    = $this->batch( [ [ 'status' => 'role_undefined', 'role' => 'student' ], [ 'status' => 'role_undefined', 'role' => 'student' ] ] );
        $mapped = [ 'student', 'professional' ];
        $first  = MyNJILGA_Role_Sync::next_problem( null, $agg, $mapped, '2026-09-01 08:00:00' );
        $second = MyNJILGA_Role_Sync::next_problem( $first, $agg, $mapped, self::NOW );
        $this->assertSame( 4, $second['count'] );
        $this->assertSame( '2026-09-01 08:00:00', $second['since'] );
        $this->assertSame( self::NOW, $second['last'] );
    }

    public function test_a_payment_that_grants_the_role_clears_the_problem(): void {
        $stored = [ 'status' => 'role_undefined', 'role' => 'student', 'count' => 4, 'since' => '2026-09-01 08:00:00', 'last' => '2026-09-02 08:00:00' ];
        $this->assertSame( null, MyNJILGA_Role_Sync::next_problem( $stored, $this->batch( [ [ 'status' => 'changed', 'role' => 'student' ] ] ), [ 'student' ], self::NOW ) );
        $this->assertSame( null, MyNJILGA_Role_Sync::next_problem( $stored, $this->batch( [ [ 'status' => 'unchanged', 'role' => 'student' ] ] ), [ 'student' ], self::NOW ), 'finding it already held counts too' );
    }

    public function test_a_payment_of_some_other_role_does_not_clear_the_problem(): void {
        $stored = [ 'status' => 'role_undefined', 'role' => 'student', 'count' => 4, 'since' => '2026-09-01 08:00:00', 'last' => '2026-09-02 08:00:00' ];
        $this->assertSame( $stored, MyNJILGA_Role_Sync::next_problem( $stored, $this->batch( [ [ 'status' => 'changed', 'role' => 'professional' ], [ 'status' => 'no_account', 'role' => 'student' ] ] ), [ 'student', 'professional' ], self::NOW ) );
    }

    public function test_fixing_the_mapping_clears_a_problem_that_could_never_resolve_itself(): void {
        // A privileged role is never granted, so "a payment granted it"
        // cannot clear its callout; once no category maps to it, it goes.
        $stored = [ 'status' => 'role_privileged', 'role' => 'administrator', 'count' => 3, 'since' => '2026-09-01 08:00:00', 'last' => '2026-09-02 08:00:00' ];
        $this->assertSame( $stored, MyNJILGA_Role_Sync::next_problem( $stored, $this->batch( [] ), [ 'administrator', 'professional' ], self::NOW ), 'still mapped: stays' );
        $this->assertSame( null, MyNJILGA_Role_Sync::next_problem( $stored, $this->batch( [ [ 'status' => 'changed', 'role' => 'professional' ] ] ), [ 'professional' ], self::NOW ), 'no longer mapped: gone' );
    }

    public function test_a_problem_seen_again_in_the_batch_that_resolved_the_old_one_is_recorded_afresh(): void {
        $stored = [ 'status' => 'role_undefined', 'role' => 'student', 'count' => 1, 'since' => '2026-09-01 08:00:00', 'last' => '2026-09-01 08:00:00' ];
        // Contradictory (one member granted 'student', another told it is undefined — say another request
        // created the role mid-batch), but a reported problem must never be dropped for having a clearing sighting beside it.
        $agg  = $this->batch( [ [ 'status' => 'changed', 'role' => 'student' ], [ 'status' => 'role_undefined', 'role' => 'student' ] ] );
        $next = MyNJILGA_Role_Sync::next_problem( $stored, $agg, [ 'student' ], self::NOW );
        $this->assertSame( 'role_undefined', $next['status'] );
        $this->assertSame( 1, $next['count'], 'counted from what this batch saw, not stacked on the problem it just cleared' );
        $this->assertSame( self::NOW, $next['since'] );
    }

    public function test_no_problem_and_a_quiet_batch_stays_no_problem(): void {
        $this->assertSame( null, MyNJILGA_Role_Sync::next_problem( null, $this->batch( [ 'changed', 'no_account', 'unchanged', 'no_role_configured', 'error' ] ), [ 'professional' ], self::NOW ) );
    }
}
