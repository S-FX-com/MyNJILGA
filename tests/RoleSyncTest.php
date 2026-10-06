<?php
/**
 * Unit tests for the pure half of MyNJILGA_Role_Sync — which role a paid
 * member should hold, which roles a sync may take away, and what a sync
 * would change on one account.
 */
class RoleSyncTest extends NJILGA_TestCase {

    /** Settings order: exempt first, then student, professional, a no-role category. */
    private function categories(): array {
        return [
            [ 'key' => 'senior_trustee', 'label' => 'Senior Trustee', 'tag' => 'senior-trustee', 'role' => 'trustee',      'price_cents' => 0 ],
            [ 'key' => 'law_student',    'label' => 'Law Student',    'tag' => 'law-student',    'role' => 'student',      'price_cents' => 3000 ],
            [ 'key' => 'professional',   'label' => 'Professional',   'tag' => 'professional',   'role' => 'professional', 'price_cents' => 12500 ],
            [ 'key' => 'honorary',       'label' => 'Honorary',       'tag' => 'honorary',       'role' => '',             'price_cents' => 0 ],
        ];
    }

    public function test_first_category_in_order_wins(): void {
        $role = MyNJILGA_Role_Sync::resolve_role( $this->categories(), [ 'professional', 'senior-trustee' ], 'professional' );
        $this->assertSame( 'trustee', $role, 'Senior Trustee is listed before Professional' );
    }

    public function test_no_category_tag_falls_back_to_the_default_category(): void {
        $this->assertSame( 'professional', MyNJILGA_Role_Sync::resolve_role( $this->categories(), [], 'professional' ) );
        $this->assertSame( 'student', MyNJILGA_Role_Sync::resolve_role( $this->categories(), [ 'unrelated-tag' ], 'law_student' ) );
    }

    public function test_no_category_tag_and_no_usable_default_resolves_to_no_role(): void {
        $this->assertSame( '', MyNJILGA_Role_Sync::resolve_role( $this->categories(), [], '' ) );
        $this->assertSame( '', MyNJILGA_Role_Sync::resolve_role( $this->categories(), [], 'deleted_key' ) );
    }

    /**
     * "— no role —" on a category means exactly that: a member matched to
     * it holds no membership role — NOT the default category's, which is
     * what the pre-3.6.0 login sync fell back to.
     */
    public function test_a_matched_category_with_no_role_means_no_role_not_the_default(): void {
        $this->assertSame( '', MyNJILGA_Role_Sync::resolve_role( $this->categories(), [ 'honorary' ], 'professional' ) );
    }

    /**
     * Roles that only history keeps managed (no longer in the map, not the
     * legacy role) are the ones staff may stop managing from Setup.
     */
    public function test_forgettable_roles_are_the_history_only_ones(): void {
        $forgettable = MyNJILGA_Role_Sync::forgettable_roles( $this->categories(), [ 'associate', 'student', 'professional', 'subscriber', 'shop_manager' ] );
        $this->assertSame( [ 'associate', 'shop_manager' ], $forgettable );
    }

    public function test_a_category_without_a_tag_never_matches(): void {
        $cats = [
            [ 'key' => 'blank',        'tag' => '',             'role' => 'wrong' ],
            [ 'key' => 'professional', 'tag' => 'professional', 'role' => 'professional' ],
        ];
        $this->assertSame( '', MyNJILGA_Role_Sync::resolve_role( $cats, [ '' ], '' ) );
    }

    public function test_managed_roles_cover_map_history_and_legacy_but_never_core_roles(): void {
        $cats   = $this->categories();
        $cats[] = [ 'key' => 'staff', 'tag' => 'staff', 'role' => 'editor' ];
        $managed = MyNJILGA_Role_Sync::managed_roles( $cats, [ 'associate', 'subscriber', '' ] );
        $this->assertSame( [ 'associate', 'professional', 'student', 'trustee' ], $managed );
    }

    public function test_plan_swaps_the_old_category_role_for_the_new_one(): void {
        $plan = MyNJILGA_Role_Sync::plan( [ 'subscriber', 'student' ], 'professional', [ 'professional', 'student', 'trustee' ], true );
        $this->assertSame( 'changed', $plan['status'] );
        $this->assertSame( [ 'professional' ], $plan['add'] );
        $this->assertSame( [ 'student' ], $plan['remove'] );
    }

    public function test_plan_is_a_no_op_when_the_member_already_holds_their_role(): void {
        $plan = MyNJILGA_Role_Sync::plan( [ 'subscriber', 'professional' ], 'professional', [ 'professional', 'student' ], true );
        $this->assertSame( [ 'status' => 'unchanged', 'add' => [], 'remove' => [] ], $plan );
    }

    public function test_plan_for_no_role_removes_every_managed_role(): void {
        $plan = MyNJILGA_Role_Sync::plan( [ 'subscriber', 'student', 'professional' ], '', [ 'professional', 'student' ], true );
        $this->assertSame( [], $plan['add'] );
        $this->assertSame( [ 'student', 'professional' ], $plan['remove'] );
    }

    public function test_plan_leaves_everyone_alone_when_the_role_is_not_defined_on_the_site(): void {
        $plan = MyNJILGA_Role_Sync::plan( [ 'student' ], 'professional', [ 'professional', 'student' ], false );
        $this->assertSame( [ 'status' => 'role_undefined', 'add' => [], 'remove' => [] ], $plan );
    }

    public function test_plan_never_touches_core_or_unmanaged_roles(): void {
        $managed = MyNJILGA_Role_Sync::managed_roles( $this->categories(), [] );
        $plan    = MyNJILGA_Role_Sync::plan( [ 'administrator', 'shop_manager', 'student' ], 'professional', $managed, true );
        $this->assertSame( [ 'professional' ], $plan['add'] );
        $this->assertSame( [ 'student' ], $plan['remove'], 'administrator and shop_manager must survive' );
    }

    public function test_plan_accepts_wordpress_keyed_role_arrays(): void {
        // WP_User::$roles can come back with non-sequential keys.
        $plan = MyNJILGA_Role_Sync::plan( [ 2 => 'student', 5 => 'subscriber' ], 'professional', [ 'professional', 'student' ], true );
        $this->assertSame( [ 'student' ], $plan['remove'] );
    }

    public function test_applying_a_plan_then_planning_again_changes_nothing(): void {
        $managed = [ 'professional', 'student', 'trustee' ];
        $roles   = [ 'subscriber', 'student', 'trustee' ];
        $first   = MyNJILGA_Role_Sync::plan( $roles, 'professional', $managed, true );
        $roles   = array_values( array_diff( array_merge( $roles, $first['add'] ), $first['remove'] ) );
        $second  = MyNJILGA_Role_Sync::plan( $roles, 'professional', $managed, true );
        $this->assertSame( 'unchanged', $second['status'] );
    }

    public function test_signature_ignores_label_and_price(): void {
        $a = $this->categories();
        $b = $a;
        $b[1]['label']       = 'Law Student (renamed)';
        $b[1]['price_cents'] = 0;
        $this->assertSame( MyNJILGA_Role_Sync::mapping_signature( $a, 'professional' ), MyNJILGA_Role_Sync::mapping_signature( $b, 'professional' ) );
    }

    public function test_signature_changes_with_role_tag_order_or_effective_default(): void {
        $a    = $this->categories();
        $base = MyNJILGA_Role_Sync::mapping_signature( $a, 'professional' );

        $role = $a;
        $role[1]['role'] = 'professional';
        $this->assertTrue( MyNJILGA_Role_Sync::mapping_signature( $role, 'professional' ) !== $base, 'role change' );

        $tag = $a;
        $tag[1]['tag'] = 'student';
        $this->assertTrue( MyNJILGA_Role_Sync::mapping_signature( $tag, 'professional' ) !== $base, 'tag change' );

        $order = [ $a[1], $a[0], $a[2], $a[3] ];
        $this->assertTrue( MyNJILGA_Role_Sync::mapping_signature( $order, 'professional' ) !== $base, 'order change' );

        $this->assertTrue( MyNJILGA_Role_Sync::mapping_signature( $a, 'law_student' ) !== $base, 'default with a different role' );
    }

    public function test_signature_ignores_a_default_switch_that_keeps_the_same_role(): void {
        $cats = [
            [ 'key' => 'a', 'tag' => 'a', 'role' => 'professional' ],
            [ 'key' => 'b', 'tag' => 'b', 'role' => 'professional' ],
        ];
        $this->assertSame( MyNJILGA_Role_Sync::mapping_signature( $cats, 'a' ), MyNJILGA_Role_Sync::mapping_signature( $cats, 'b' ) );
    }

    // -------------------------------------------------------------------
    // Privileged roles — never granted by a payment, never touched at all
    // -------------------------------------------------------------------

    public function test_the_administrator_role_is_refused(): void {
        $adminCaps = [ 'read' => true, 'edit_posts' => true, 'manage_options' => true, 'promote_users' => true, 'install_plugins' => true ];
        $this->assertTrue( MyNJILGA_Role_Sync::is_privileged( $adminCaps ) );
        $this->assertSame( [ 'status' => 'role_privileged', 'add' => [], 'remove' => [] ], MyNJILGA_Role_Sync::plan( [ 'subscriber' ], 'administrator', [ 'professional' ], true, $adminCaps ) );
    }

    public function test_the_editor_role_is_allowed(): void {
        $editorCaps = [ 'read' => true, 'edit_posts' => true, 'edit_others_posts' => true, 'publish_posts' => true, 'moderate_comments' => true, 'upload_files' => true, 'manage_categories' => true ];
        $this->assertFalse( MyNJILGA_Role_Sync::is_privileged( $editorCaps ) );
        $this->assertSame( [ 'status' => 'changed', 'add' => [ 'editor' ], 'remove' => [] ], MyNJILGA_Role_Sync::plan( [ 'subscriber' ], 'editor', [ 'professional' ], true, $editorCaps ) );
    }

    public function test_a_custom_role_holding_promote_users_is_refused(): void {
        $this->assertSame( 'role_privileged', MyNJILGA_Role_Sync::plan( [], 'hr_admin', [], true, [ 'read' => true, 'promote_users' => true ] )['status'] );
    }

    public function test_every_capability_on_the_deny_list_makes_a_role_privileged(): void {
        $deny = [ 'manage_options', 'promote_users', 'edit_users', 'create_users', 'delete_users', 'install_plugins', 'activate_plugins', 'edit_plugins', 'edit_themes', 'switch_themes', 'update_core', 'update_plugins', 'update_themes', 'delete_plugins', 'delete_themes', 'edit_files', 'manage_network', 'manage_sites' ];
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

    // -------------------------------------------------------------------
    // A stored problem stops showing once the cause is fixed
    // -------------------------------------------------------------------

    public function test_an_undefined_role_problem_ends_when_the_role_appears_safe_or_stops_being_mapped(): void {
        $p = [ 'status' => 'role_undefined', 'role' => 'member_plus', 'count' => 3 ];
        $this->assertTrue( MyNJILGA_Role_Sync::problem_still_applies( $p, [ 'member_plus' ], false ), 'still mapped, still missing' );
        $this->assertFalse( MyNJILGA_Role_Sync::problem_still_applies( $p, [ 'member_plus' ], true, [ 'read' => true ] ), 'the role now exists and is safe' );
        $this->assertFalse( MyNJILGA_Role_Sync::problem_still_applies( $p, [ 'professional' ], false ), 'no category maps to it any more' );
    }

    public function test_a_privileged_role_problem_ends_only_when_the_role_is_mapped_away_or_defanged(): void {
        $p = [ 'status' => 'role_privileged', 'role' => 'administrator', 'count' => 1 ];
        $admin = [ 'read' => true, 'manage_options' => true ];
        $this->assertTrue( MyNJILGA_Role_Sync::problem_still_applies( $p, [ 'administrator' ], true, $admin ), 'still dangerous' );
        $this->assertFalse( MyNJILGA_Role_Sync::problem_still_applies( $p, [ 'professional' ], true, $admin ), 'the mapping was corrected' );
        $this->assertFalse( MyNJILGA_Role_Sync::problem_still_applies( $p, [ 'administrator' ], true, [ 'read' => true ] ), 'the role was defanged' );
    }

    public function test_creating_a_missing_role_with_an_admin_capability_does_not_fix_the_problem(): void {
        // Stored: role_undefined. The admin creates the role — but with manage_options.
        // plan() will keep refusing it (role_privileged), so the callout must stay, and say so.
        $p = [ 'status' => 'role_undefined', 'role' => 'member_plus', 'count' => 3 ];
        $admin = [ 'read' => true, 'manage_options' => true ];
        $this->assertTrue( MyNJILGA_Role_Sync::problem_still_applies( $p, [ 'member_plus' ], true, $admin ) );
        $this->assertSame( 'role_privileged', MyNJILGA_Role_Sync::problem_status_now( 'member_plus', [ 'member_plus' ], true, $admin ) );
        $this->assertSame( 'role_privileged', MyNJILGA_Role_Sync::plan( [], 'member_plus', [], true, $admin )['status'], 'and that is what a payment would report' );
    }

    public function test_deleting_a_privileged_role_that_is_still_mapped_does_not_fix_the_problem(): void {
        // Stored: role_privileged. The role is deleted but a category still maps to it, so a
        // payment would now hit role_undefined — still nothing granted.
        $p = [ 'status' => 'role_privileged', 'role' => 'administrator', 'count' => 1 ];
        $this->assertTrue( MyNJILGA_Role_Sync::problem_still_applies( $p, [ 'administrator' ], false, [] ) );
        $this->assertSame( 'role_undefined', MyNJILGA_Role_Sync::problem_status_now( 'administrator', [ 'administrator' ], false ) );
        $this->assertSame( 'role_undefined', MyNJILGA_Role_Sync::plan( [], 'administrator', [], false, [] )['status'] );
    }

    public function test_the_status_now_is_judged_from_the_live_role_never_the_stored_one(): void {
        $safe = [ 'read' => true, 'edit_posts' => true ];
        $this->assertSame( '', MyNJILGA_Role_Sync::problem_status_now( 'x', [ 'x' ], true, $safe ), 'grantable' );
        $this->assertSame( '', MyNJILGA_Role_Sync::problem_status_now( 'x', [ 'y' ], false ), 'not mapped: nothing to grant, nothing wrong' );
        $this->assertSame( '', MyNJILGA_Role_Sync::problem_status_now( '', [ '' ], false ), 'no role at all' );
        $this->assertFalse( MyNJILGA_Role_Sync::problem_still_applies( [ 'status' => 'role_undefined' ], [ 'x' ], false ), 'a stored problem with no role never applies' );
    }

    public function test_a_privileged_role_is_reported_even_when_the_account_already_holds_it(): void {
        // The mapping is what is dangerous; an admin who also pays dues
        // must not hide it behind "unchanged".
        $this->assertSame( 'role_privileged', MyNJILGA_Role_Sync::plan( [ 'administrator' ], 'administrator', [], true, [ 'manage_options' => true ] )['status'] );
    }

    /** A privileged mapping is a misconfiguration like an undefined role: nothing is added AND nothing is removed. */
    public function test_a_privileged_mapping_leaves_the_old_role_in_place(): void {
        $plan = MyNJILGA_Role_Sync::plan( [ 'student' ], 'administrator', [ 'student', 'professional' ], true, [ 'manage_options' => true ] );
        $this->assertSame( [ 'status' => 'role_privileged', 'add' => [], 'remove' => [] ], $plan );
    }

    public function test_the_lazily_created_role_is_the_one_every_seeded_category_maps_to(): void {
        foreach ( MyNJILGA_Dues_Settings::defaults()['categories'] as $cat ) {
            $this->assertSame( MyNJILGA_Role_Sync::LEGACY_ROLE, $cat['role'] );
        }
    }

    // -------------------------------------------------------------------
    // aggregate / describe — the numbers and the Company Note
    // -------------------------------------------------------------------

    private const NOW = '2026-09-29 12:00:00';

    private function results( array $statuses ): array {
        $out = [];
        foreach ( $statuses as $s ) {
            $out[] = is_array( $s ) ? $s : [ 'status' => $s ];
        }
        return $out;
    }

    public function test_aggregate_counts_each_status_in_a_fixed_order(): void {
        $agg = MyNJILGA_Role_Sync::aggregate( $this->results( [
            'no_account', 'changed', 'failed', 'unchanged', 'changed', 'role_undefined', 'no_account', 'changed', 'no_contact', 'no_role_configured', 'role_privileged',
        ] ) );
        $this->assertSame( [
            'changed' => 3, 'unchanged' => 1, 'no_account' => 2, 'no_contact' => 1, 'no_role_configured' => 1, 'role_undefined' => 1, 'role_privileged' => 1, 'failed' => 1,
        ], $agg['counts'] );
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
            [ 'status' => 'failed', 'message' => 'hook exploded' ],
            [ 'status' => 'failed', 'message' => 'hook exploded' ],
            [ 'status' => 'failed', 'message' => 'second' ],
            [ 'status' => 'failed', 'message' => 'third' ],
            [ 'status' => 'failed', 'message' => 'fourth' ],
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
        $this->assertSame( 'WordPress role: 1 failed (see the invoice\'s error note)', MyNJILGA_Role_Sync::describe_outcomes( [ 'failed' => 1 ] ) );
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
    // The stored problem callout (Dashboard)
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
        $stored = [ 'status' => 'role_privileged', 'role' => 'administrator', 'count' => 3, 'since' => '2026-09-01 08:00:00', 'last' => '2026-09-02 08:00:00' ];
        $this->assertSame( $stored, MyNJILGA_Role_Sync::next_problem( $stored, $this->batch( [] ), [ 'administrator', 'professional' ], self::NOW ), 'still mapped: stays' );
        $this->assertSame( null, MyNJILGA_Role_Sync::next_problem( $stored, $this->batch( [ [ 'status' => 'changed', 'role' => 'professional' ] ] ), [ 'professional' ], self::NOW ), 'no longer mapped: gone' );
    }

    public function test_a_problem_seen_again_in_the_batch_that_resolved_the_old_one_is_recorded_afresh(): void {
        $stored = [ 'status' => 'role_undefined', 'role' => 'student', 'count' => 1, 'since' => '2026-09-01 08:00:00', 'last' => '2026-09-01 08:00:00' ];
        $agg  = $this->batch( [ [ 'status' => 'changed', 'role' => 'student' ], [ 'status' => 'role_undefined', 'role' => 'student' ] ] );
        $next = MyNJILGA_Role_Sync::next_problem( $stored, $agg, [ 'student' ], self::NOW );
        $this->assertSame( 'role_undefined', $next['status'] );
        $this->assertSame( 1, $next['count'], 'counted from what this batch saw, not stacked on the problem it just cleared' );
        $this->assertSame( self::NOW, $next['since'] );
    }

    public function test_no_problem_and_a_quiet_batch_stays_no_problem(): void {
        $this->assertSame( null, MyNJILGA_Role_Sync::next_problem( null, $this->batch( [ 'changed', 'no_account', 'unchanged', 'no_role_configured', 'failed' ] ), [ 'professional' ], self::NOW ) );
    }
}
