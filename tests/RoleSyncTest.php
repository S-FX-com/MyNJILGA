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
}
