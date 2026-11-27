<?php
/**
 * Reports for a chosen dues year (2026, 2027, …): which years are offered,
 * how a request resolves to one, how a contact reads for a coming year, the
 * per-year cache key, and the Executive Summary preview's shaping.
 */
declare( strict_types=1 );

require_once dirname( __DIR__ ) . '/includes/class-tags.php';
require_once dirname( __DIR__ ) . '/includes/class-my-membership.php';
require_once dirname( __DIR__ ) . '/includes/class-membership-stats.php';
require_once dirname( __DIR__ ) . '/includes/class-members-data.php';
require_once dirname( __DIR__ ) . '/includes/class-report-year.php';
require_once dirname( __DIR__ ) . '/includes/class-report-preview.php';

class ReportYearTest extends NJILGA_TestCase {

    // ---- which years are offered ------------------------------------------

    public function test_this_year_and_next_are_always_offered(): void {
        $this->assertSame( [ 2026, 2027 ], MyNJILGA_Report_Year::options( 2026, [] ) );
    }

    public function test_a_later_year_with_a_tag_is_offered_too(): void {
        $this->assertSame( [ 2026, 2027, 2028 ], MyNJILGA_Report_Year::options( 2026, [ 2028, 2027, 2026 ] ) );
    }

    public function test_past_years_and_far_future_years_are_not_offered(): void {
        $this->assertSame( [ 2026, 2027 ], MyNJILGA_Report_Year::options( 2026, [ 2019, 2025 ] ), 'a past year cannot be rebuilt from tags' );
        $this->assertSame( [ 2026, 2027 ], MyNJILGA_Report_Year::options( 2026, [ 2099 ] ) );
    }

    public function test_the_offer_rolls_forward_with_the_calendar(): void {
        $this->assertSame( [ 2027, 2028 ], MyNJILGA_Report_Year::options( 2027, [ 2026, 2027 ] ) );
    }

    // ---- reading the request ----------------------------------------------

    public function test_a_requested_year_is_used_when_offered(): void {
        $options = [ 2026, 2027 ];
        $this->assertSame( 2027, MyNJILGA_Report_Year::resolve( '2027', $options, 2026 ) );
        $this->assertSame( 2026, MyNJILGA_Report_Year::resolve( '2026', $options, 2026 ) );
    }

    public function test_anything_else_is_this_year(): void {
        $options = [ 2026, 2027 ];
        foreach ( [ null, '', '2025', '2030', '2027abc', ' 2027', '-2027', '20270', [ '2027' ], 2027 ] as $bad ) {
            $this->assertSame( 2026, MyNJILGA_Report_Year::resolve( $bad, $options, 2026 ), json_encode( $bad ) );
        }
    }

    public function test_labels_mark_this_year(): void {
        $this->assertSame( '2026 (this year)', MyNJILGA_Report_Year::label( 2026, 2026 ) );
        $this->assertSame( '2027', MyNJILGA_Report_Year::label( 2027, 2026 ) );
    }

    // ---- how a contact reads for a year -----------------------------------

    private function cfg( int $year, bool $assume ): array {
        $d = MyNJILGA_Dues_Settings::defaults();
        return [ 'year' => $year, 'assume_evergreen' => $assume, 'categories' => $d['categories'], 'default_category' => 'professional' ];
    }

    private function state( array $contact, int $year, bool $assume ): string {
        return MyNJILGA_Membership_Stats::classify( $contact + MyNJILGA_Membership_Stats::blank_contact( 1 ), $this->cfg( $year, $assume ) )['state'];
    }

    public function test_paid_2026_is_active_for_2026_but_not_for_2027(): void {
        $paid2026 = [ 'paid_years' => [ 2026 ], 'paid_tag' => true ];
        $this->assertSame( 'active', $this->state( $paid2026, 2026, true ) );
        $this->assertSame( 'expired', $this->state( $paid2026, 2027, false ) );
    }

    public function test_paid_2027_is_active_for_2027_and_for_2026(): void {
        $paid2027 = [ 'paid_years' => [ 2027, 2026 ], 'paid_tag' => true ];
        $this->assertSame( 'active', $this->state( $paid2027, 2027, false ) );
        $this->assertSame( 'active', $this->state( $paid2027, 2026, true ), 'paid ahead still counts this year' );
    }

    public function test_an_undated_evergreen_member_is_active_this_year_only(): void {
        $evergreen = [ 'paid_tag' => true ];
        $this->assertSame( 'active', $this->state( $evergreen, 2026, true ) );
        $this->assertSame( 'expired', $this->state( $evergreen, 2027, false ), 'not carried into a coming year' );
    }

    public function test_the_evergreen_rule_leaves_exempt_and_inactive_alone(): void {
        $this->assertSame( 'exempt', $this->state( [ 'paid_tag' => true, 'role_tags' => [ 'past-president' ] ], 2027, false ) );
        $this->assertSame( 'inactive', $this->state( [ 'paid_tag' => true, 'inactive' => true ], 2027, false ) );
    }

    public function test_assume_evergreen_defaults_to_on(): void {
        $cfg = [ 'year' => 2026 ];
        $k   = MyNJILGA_Membership_Stats::classify( [ 'paid_tag' => true ], $cfg );
        $this->assertSame( 'active', $k['state'] );
        $this->assertTrue( $k['assumed'] );
    }

    // ---- caching ----------------------------------------------------------

    public function test_this_years_snapshot_keeps_its_original_key(): void {
        $this->assertSame( MyNJILGA_Membership_Stats::TRANSIENT, MyNJILGA_Membership_Stats::transient_key( 2026, 2026 ) );
        $this->assertSame( MyNJILGA_Membership_Stats::TRANSIENT . '_2027', MyNJILGA_Membership_Stats::transient_key( 2027, 2026 ) );
        $this->assertTrue( MyNJILGA_Membership_Stats::transient_key( 2027, 2026 ) !== MyNJILGA_Membership_Stats::transient_key( 2028, 2026 ) );
    }

    // ---- preview ----------------------------------------------------------

    public function test_the_preview_counts_every_row_and_shows_only_a_sample(): void {
        $members = [];
        for ( $i = 1; $i <= 8; $i++ ) {
            $members[] = [ 'member' => "M$i", 'email' => "m$i@x.test", 'firm' => 'F', 'trustee_status' => '', 'payment_method' => 'Credit Card' ];
        }
        $trustees = [ [ 'member' => 'T1', 'trustee_status' => 'Trustee', 'firm' => 'F', 'state' => 'expired', 'is_exempt' => false, 'payment_method' => 'Check' ] ];
        $bucketed = [
            'bucket_labels' => [ '1' => '1 Paid Member', '2-5' => '2–5 Paid Members', '6+' => '6+ Paid Members', '0' => 'No Paid Members' ],
            'buckets'       => [ '1' => [ [ 'name' => 'A', 'paid_count' => 1, 'total_count' => 2, 'members' => [] ] ], '2-5' => [], '6+' => [], '0' => [ [ 'name' => 'B', 'paid_count' => 0, 'total_count' => 1, 'members' => [] ] ] ],
        ];
        $firms = [ [ 'name' => 'A', 'contacts' => [
            [ 'first_name' => 'Ann', 'last_name' => 'Lee', 'email' => 'a@x.test', 'dues' => 'Dues Paid', 'trustees' => '', 'past_president' => '', 'payment' => 'Paid by Website' ],
            [ 'first_name' => 'Bo', 'last_name' => 'Ray', 'email' => 'b@x.test', 'dues' => 'Unpaid Dues', 'trustees' => '', 'past_president' => '', 'payment' => '' ],
        ] ] ];

        $sheets = MyNJILGA_Report_Preview::sheets( $members, $trustees, $bucketed, $firms );
        $this->assertSame( [ 'members', 'trustees', 'companies', 'firms' ], array_column( $sheets, 'key' ) );
        $this->assertSame( [ 8, 1, 2, 2 ], array_column( $sheets, 'count' ) );
        $this->assertCount( 5, $sheets[0]['rows'], 'a sample, not the whole list' );
        $this->assertSame( [ 'M1', 'm1@x.test', 'F', '', 'Credit Card' ], $sheets[0]['rows'][0] );
        $this->assertSame( 'Unpaid', $sheets[1]['rows'][0][3], 'the same wording as the Trustees page' );
        $this->assertSame( [ '1 Paid Member', 'A', '1 paid / 2 total' ], $sheets[2]['rows'][0] );
        $this->assertSame( [ 'A', 'Ann Lee', 'a@x.test', 'Dues Paid', 'Paid by Website' ], $sheets[3]['rows'][0] );
        foreach ( $sheets as $sheet ) {
            foreach ( $sheet['rows'] as $row ) {
                $this->assertSame( count( $sheet['columns'] ), count( $row ), $sheet['key'] . ' row matches its columns' );
            }
        }
    }

    public function test_an_empty_report_previews_as_empty(): void {
        $sheets = MyNJILGA_Report_Preview::sheets( [], [], [ 'bucket_labels' => [], 'buckets' => [] ], [] );
        $this->assertSame( [ 0, 0, 0, 0 ], array_column( $sheets, 'count' ) );
        $this->assertSame( [], $sheets[0]['rows'] );
    }

    public function test_count_labels(): void {
        $this->assertSame( '1 member', MyNJILGA_Report_Preview::count_label( 1, 'member' ) );
        $this->assertSame( '12 members', MyNJILGA_Report_Preview::count_label( 12, 'member' ) );
        $this->assertSame( '0 companies', MyNJILGA_Report_Preview::count_label( 0, 'company' ) );
    }
}
