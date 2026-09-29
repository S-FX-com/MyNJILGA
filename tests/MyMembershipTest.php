<?php
/**
 * The pure half of [njilga_my_membership] (MyNJILGA_My_Membership): the
 * paid-through year read off tags, active/expired standing, the "does the
 * firm manage this membership" wording, which invoice statuses a member
 * sees, and the fee ledger built from frozen invoice snapshots — plus the
 * one new SQL read, MyNJILGA_Dues_Invoice_Table::rows_listing_contact().
 */
declare( strict_types=1 );

require_once dirname( __DIR__ ) . '/includes/invoicing/class-dues-preview.php';
require_once dirname( __DIR__ ) . '/includes/class-my-membership.php';

class MyMembershipTest extends NJILGA_TestCase {

    private const PATTERN = 'Dues Paid {year}';

    // -------------------------------------------------------------------
    // Paid-through year, from tags
    // -------------------------------------------------------------------

    private function tag( string $title, string $slug = '' ): array {
        return [ 'title' => $title, 'slug' => $slug !== '' ? $slug : strtolower( str_replace( ' ', '-', $title ) ) ];
    }

    public function test_paid_years_reads_each_year_tag_newest_first(): void {
        $tags = [ $this->tag( 'Dues Paid 2025' ), $this->tag( 'Professional' ), $this->tag( 'Dues Paid 2027' ), $this->tag( 'Dues Paid 2026' ) ];
        $this->assertSame( [ 2027, 2026, 2025 ], MyNJILGA_My_Membership::paid_years( $tags, self::PATTERN ) );
    }

    public function test_paid_years_ignores_tags_that_only_look_similar(): void {
        $tags = [
            $this->tag( 'Dues Paid' ),               // the evergreen tag — no year
            $this->tag( 'Unpaid Dues 2026' ),        // the lapsed year tag
            $this->tag( 'Assessment Paid 2026' ),    // a different fee
            $this->tag( 'Dues Paid 2026 Extra' ),
            $this->tag( 'Dues Paid 20266' ),
        ];
        $this->assertSame( [], MyNJILGA_My_Membership::paid_years( $tags, self::PATTERN ) );
    }

    public function test_paid_years_matches_case_insensitively_and_by_slug(): void {
        // A renamed title, but the slug FluentCRM minted from the original still fits.
        $tags = [ [ 'title' => 'dues paid 2026', 'slug' => 'x' ], [ 'title' => 'Renamed', 'slug' => 'dues-paid-2027' ] ];
        $this->assertSame( [ 2027, 2026 ], MyNJILGA_My_Membership::paid_years( $tags, self::PATTERN ) );
    }

    public function test_paid_years_follows_a_custom_pattern_and_needs_a_year_placeholder(): void {
        $tags = [ $this->tag( 'Member 2026' ), $this->tag( 'Dues Paid 2026' ) ];
        $this->assertSame( [ 2026 ], MyNJILGA_My_Membership::paid_years( $tags, 'Member {year}' ) );
        $this->assertSame( [], MyNJILGA_My_Membership::paid_years( $tags, 'Dues Paid' ), 'no {year} in the pattern = nothing to read' );
    }

    // -------------------------------------------------------------------
    // Standing
    // -------------------------------------------------------------------

    private function standing( array $facts, int $thisYear = 2026 ): array {
        return MyNJILGA_My_Membership::standing( $facts, $thisYear );
    }

    public function test_paid_through_this_year_is_active_until_that_year_end(): void {
        $s = $this->standing( [ 'paid_years' => [ 2026, 2025 ] ] );
        $this->assertSame( [ 'state' => 'active', 'through' => 2026, 'assumed' => false ], $s );
        $this->assertSame( '12/31/2026', MyNJILGA_My_Membership::expiry_text( $s ) );
    }

    public function test_paid_ahead_for_next_year_expires_next_year_end(): void {
        // The batch goes out (and is paid) before the year it covers.
        $s = $this->standing( [ 'paid_years' => [ 2027, 2026 ] ] );
        $this->assertSame( 'active', $s['state'] );
        $this->assertSame( '12/31/2027', MyNJILGA_My_Membership::expiry_text( $s ) );
    }

    public function test_paid_only_for_a_past_year_is_expired_with_that_date(): void {
        $s = $this->standing( [ 'paid_years' => [ 2025 ] ] );
        $this->assertSame( 'expired', $s['state'] );
        $this->assertSame( 2025, $s['through'] );
        $this->assertSame( 'Expired 12/31/2025', MyNJILGA_My_Membership::expiry_text( $s ) );
    }

    public function test_the_date_beats_a_lingering_evergreen_paid_tag(): void {
        // The downgrade sweep only strips the evergreen tag from people on an unpaid invoice.
        $s = $this->standing( [ 'paid_years' => [ 2025 ], 'paid_tag' => true ] );
        $this->assertSame( 'expired', $s['state'] );
    }

    public function test_an_evergreen_tag_with_no_year_tag_is_active_through_this_year(): void {
        $s = $this->standing( [ 'paid_years' => [], 'paid_tag' => true ] );
        $this->assertSame( [ 'state' => 'active', 'through' => 2026, 'assumed' => true ], $s );
        $this->assertSame( '12/31/2026', MyNJILGA_My_Membership::expiry_text( $s ) );
    }

    public function test_dues_paid_wins_when_a_contact_carries_both_evergreen_tags(): void {
        $s = $this->standing( [ 'paid_tag' => true, 'unpaid_tag' => true ] );
        $this->assertSame( 'active', $s['state'] );
    }

    public function test_the_unpaid_tag_alone_is_expired_with_no_date(): void {
        $s = $this->standing( [ 'unpaid_tag' => true ] );
        $this->assertSame( [ 'state' => 'expired', 'through' => null, 'assumed' => false ], $s );
        $this->assertSame( '', MyNJILGA_My_Membership::expiry_text( $s ) );
    }

    public function test_exempt_and_inactive_owe_nothing_so_are_never_expired(): void {
        $this->assertSame( 'exempt', $this->standing( [ 'exempt' => true, 'unpaid_tag' => true, 'paid_years' => [ 2024 ] ] )['state'] );
        $this->assertSame( 'inactive', $this->standing( [ 'inactive' => true, 'unpaid_tag' => true, 'paid_years' => [ 2024 ] ] )['state'] );
        // …but paid through this year is still active, exempt or not.
        $this->assertSame( 'active', $this->standing( [ 'exempt' => true, 'paid_years' => [ 2026 ] ] )['state'] );
    }

    public function test_nothing_on_record_is_none(): void {
        $this->assertSame( 'none', $this->standing( [] )['state'] );
    }

    public function test_the_year_rolls_the_verdict_over(): void {
        $facts = [ 'paid_years' => [ 2026 ] ];
        $this->assertSame( 'active', $this->standing( $facts, 2026 )['state'] );
        $this->assertSame( 'expired', $this->standing( $facts, 2027 )['state'] );
    }

    public function test_pills_use_the_words_members_expect(): void {
        $this->assertSame( [ 'Active member', 'paid' ], MyNJILGA_My_Membership::standing_pill( [ 'state' => 'active' ], true ) );
        $this->assertSame( [ 'Expired member', 'unpaid' ], MyNJILGA_My_Membership::standing_pill( [ 'state' => 'expired' ], true ) );
        $this->assertSame( [ 'Expired', 'unpaid' ], MyNJILGA_My_Membership::standing_pill( [ 'state' => 'expired' ] ) );
        $this->assertSame( '12/31/2030', MyNJILGA_My_Membership::expires_label( 2030 ) );
    }

    // -------------------------------------------------------------------
    // Does the firm manage the membership?
    // -------------------------------------------------------------------

    public function test_firm_mode_is_managed_and_the_owner_is_told_it_is_them(): void {
        $member = MyNJILGA_My_Membership::management( false, 'firm', 'Olive Owner' );
        $this->assertTrue( $member['firm_managed'] );
        $this->assertSame( 'Managed by your firm', $member['label'] );
        $this->assertTrue( strpos( $member['detail'], 'Olive Owner receives one invoice' ) !== false, $member['detail'] );

        $owner = MyNJILGA_My_Membership::management( true, 'firm', 'Olive Owner' );
        $this->assertTrue( $owner['firm_managed'] );
        $this->assertSame( 'You manage this firm\'s membership', $owner['label'] );
    }

    public function test_individual_mode_means_each_member_manages_their_own(): void {
        $member = MyNJILGA_My_Membership::management( false, 'individual', 'Olive Owner' );
        $this->assertFalse( $member['firm_managed'] );
        $this->assertSame( 'You manage your own membership', $member['label'] );

        $owner = MyNJILGA_My_Membership::management( true, 'individual', 'Olive Owner' );
        $this->assertFalse( $owner['firm_managed'] );
        $this->assertSame( 'Members are billed individually', $owner['label'] );
    }

    public function test_split_assessment_manages_dues_but_not_the_assessment(): void {
        $member = MyNJILGA_My_Membership::management( false, 'split_assessment', 'Olive Owner' );
        $this->assertTrue( $member['firm_managed'] );
        $this->assertTrue( strpos( $member['detail'], 'assessment is billed to you directly' ) !== false, $member['detail'] );
    }

    public function test_a_firm_with_no_owner_says_so(): void {
        $m = MyNJILGA_My_Membership::management( false, 'firm', '' );
        $this->assertTrue( strpos( $m['detail'], 'No Owner is on record' ) !== false, $m['detail'] );
    }

    // -------------------------------------------------------------------
    // Which invoices a member sees
    // -------------------------------------------------------------------

    public function test_drafts_and_excluded_rows_are_never_shown(): void {
        $this->assertFalse( MyNJILGA_My_Membership::invoice_state( 'draft' )['show'] );
        $this->assertFalse( MyNJILGA_My_Membership::invoice_state( 'excluded' )['show'] );
        $this->assertFalse( MyNJILGA_My_Membership::invoice_state( 'something-new' )['show'] );
    }

    public function test_only_created_and_sent_invoices_are_owed(): void {
        $buckets = [];
        foreach ( [ 'approved', 'created', 'sent', 'processing', 'paid', 'downgraded', 'voided', 'uncollectible' ] as $status ) {
            $state = MyNJILGA_My_Membership::invoice_state( $status );
            $this->assertTrue( $state['show'], $status );
            $buckets[ $status ] = $state['bucket'];
        }
        $this->assertSame(
            [ 'approved' => 'pending', 'created' => 'due', 'sent' => 'due', 'processing' => 'processing', 'paid' => 'paid', 'downgraded' => 'lapsed', 'voided' => 'void', 'uncollectible' => 'void' ],
            $buckets
        );
    }

    // -------------------------------------------------------------------
    // Fee lines
    // -------------------------------------------------------------------

    private function member( array $over = [] ): array {
        return $over + [
            'contact_id' => 5, 'name' => 'Ann Brown',
            'category_label' => 'Professional Membership', 'tier_label' => '1st Member',
            'dues_cents' => 12500, 'dues_note' => '',
            'assessment_cents' => 0, 'assessment_label' => '', 'assessment_qualifier' => '',
            'unbilled_reason' => '',
        ];
    }

    public function test_a_dues_line_carries_its_tier(): void {
        $lines = MyNJILGA_My_Membership::fee_lines( $this->member(), 'combined' );
        $this->assertCount( 1, $lines );
        $this->assertSame( [ 'type' => 'dues', 'label' => 'Professional Membership', 'detail' => '1st Member', 'cents' => 12500, 'note' => '' ], $lines[0] );
    }

    public function test_a_trustee_assessment_is_its_own_line(): void {
        $m     = $this->member( [ 'assessment_cents' => 20000, 'assessment_label' => 'Trustee Dinner Assessment', 'assessment_qualifier' => 'Officer' ] );
        $lines = MyNJILGA_My_Membership::fee_lines( $m, 'combined' );
        $this->assertCount( 2, $lines );
        $this->assertSame( 'assessment', $lines[1]['type'] );
        $this->assertSame( 'Trustee Dinner Assessment', $lines[1]['label'] );
        $this->assertSame( 'Officer', $lines[1]['detail'] );
        $this->assertSame( 20000, $lines[1]['cents'] );
    }

    public function test_an_assessment_only_invoice_has_no_dues_line(): void {
        $m     = $this->member( [ 'dues_cents' => 0, 'assessment_cents' => 20000, 'assessment_label' => 'Trustee Dinner Assessment' ] );
        $lines = MyNJILGA_My_Membership::fee_lines( $m, 'assessment' );
        $this->assertCount( 1, $lines );
        $this->assertSame( 'assessment', $lines[0]['type'] );
    }

    public function test_a_no_charge_line_says_why(): void {
        $tier = MyNJILGA_My_Membership::fee_lines( $this->member( [ 'dues_cents' => 0, 'tier_label' => 'Members 6+' ] ), 'combined' )[0];
        $this->assertSame( 0, $tier['cents'] );
        $this->assertSame( 'No charge — Members 6+', $tier['note'] );
        $this->assertSame( '', $tier['detail'] );

        $join = MyNJILGA_My_Membership::fee_lines( $this->member( [ 'dues_cents' => 0, 'dues_note' => 'paid via online join' ] ), 'combined' )[0];
        $this->assertSame( 'Paid through an online join', $join['note'] );

        $inactive = MyNJILGA_My_Membership::fee_lines( $this->member( [ 'dues_cents' => 0, 'tier_label' => '', 'unbilled_reason' => 'inactive' ] ), 'combined' )[0];
        $this->assertSame( 'No charge — inactive', $inactive['note'] );
    }

    public function test_an_exempt_label_is_not_repeated_as_the_reason(): void {
        $m    = $this->member( [ 'dues_cents' => 0, 'tier_label' => '', 'category_label' => 'Past President Membership (Exempt)' ] );
        $line = MyNJILGA_My_Membership::fee_lines( $m, 'combined' )[0];
        $this->assertSame( 'Past President Membership (Exempt)', $line['label'] );
        $this->assertSame( 'No charge', $line['note'] );
    }

    // -------------------------------------------------------------------
    // The ledger
    // -------------------------------------------------------------------

    /**
     * @param array<int,array<string,mixed>> $members
     */
    private function row( int $id, int $year, string $status, array $members, array $over = [] ): object {
        $snapshot = MyNJILGA_Dues_Snapshot::build(
            $year,
            $over['mode'] ?? 'firm',
            $over['kind'] ?? 'combined',
            [ 'id' => 12, 'name' => 'Smith & Jones LLP' ],
            [ 'contact_id' => 1, 'name' => 'Olive Owner', 'email' => 'olive@example.test' ],
            $over['bill_to'] ?? [ 'contact_id' => 1, 'name' => 'Olive Owner', 'email' => 'olive@example.test' ],
            $members
        );
        return (object) [
            'id'                 => $id,
            'dues_year'          => $year,
            'status'             => $status,
            'total_amount_cents' => MyNJILGA_Pricing_Engine::totals( $members )['total_cents'],
            'roster_snapshot'    => json_encode( $snapshot ),
            'hosted_invoice_url' => 'https://pay.example.test/' . $id,
            'invoice_pdf_url'    => 'https://pay.example.test/' . $id . '.pdf',
            'paid_at'            => $status === 'paid' ? '2026-03-04 10:00:00' : null,
        ];
    }

    public function test_the_ledger_groups_fee_lines_by_person_newest_year_first(): void {
        $ann = $this->member();
        $bo  = $this->member( [ 'contact_id' => 6, 'name' => 'Bo Chen', 'tier_label' => 'Members 2–5', 'dues_cents' => 7500, 'assessment_cents' => 20000, 'assessment_label' => 'Trustee Dinner Assessment', 'assessment_qualifier' => 'Trustee' ] );

        $ledger = MyNJILGA_My_Membership::fee_ledger( [
            $this->row( 10, 2026, 'paid', [ $ann, $bo ] ),
            $this->row( 11, 2027, 'sent', [ $ann, $bo ] ),
        ] );

        $this->assertSame( [ 11, 10 ], array_column( $ledger['invoices'], 'id' ), 'newest year first' );
        $this->assertSame( [ 5, 6 ], array_keys( $ledger['members'] ) );
        $this->assertSame( [ 2027, 2026 ], array_column( $ledger['members'][5]['fees'], 'year' ) );
        $this->assertCount( 4, $ledger['members'][6]['fees'], 'dues + assessment in each of two years' );
        $this->assertSame( [ 'dues', 'assessment', 'dues', 'assessment' ], array_column( $ledger['members'][6]['fees'], 'type' ) );
    }

    public function test_only_invoices_awaiting_payment_count_towards_the_balance(): void {
        $m = $this->member();
        $ledger = MyNJILGA_My_Membership::fee_ledger( [
            $this->row( 1, 2027, 'sent', [ $m ] ),        // owed
            $this->row( 2, 2026, 'paid', [ $m ] ),        // settled
            $this->row( 3, 2026, 'voided', [ $m ] ),      // closed out
            $this->row( 4, 2026, 'processing', [ $m ] ),  // in flight
            $this->row( 5, 2028, 'draft', [ $m ] ),       // a staff preview — invisible
        ] );
        $this->assertSame( 12500, $ledger['due_cents'] );
        $this->assertSame( 12500, $ledger['members'][5]['due_cents'] );
        $this->assertSame( 12500, $ledger['members'][5]['paid_cents'] );
        $this->assertCount( 4, $ledger['invoices'] );
        $this->assertFalse( in_array( 5, array_column( $ledger['invoices'], 'id' ), true ), 'the draft is not shown' );
    }

    public function test_only_an_owed_invoice_offers_a_pay_link(): void {
        $m = $this->member();
        $by = [];
        foreach ( MyNJILGA_My_Membership::fee_ledger( [
            $this->row( 1, 2027, 'created', [ $m ] ),
            $this->row( 2, 2026, 'paid', [ $m ] ),
            $this->row( 3, 2025, 'processing', [ $m ] ),
            $this->row( 4, 2024, 'voided', [ $m ] ),
        ] )['invoices'] as $inv ) {
            $by[ $inv['id'] ] = $inv;
        }
        $this->assertSame( 'https://pay.example.test/1', $by[1]['pay_url'] );
        $this->assertSame( '', $by[2]['pay_url'], 'nothing to pay on a paid invoice' );
        $this->assertSame( '', $by[3]['pay_url'], 'never invite a second payment while one is clearing' );
        $this->assertSame( '', $by[4]['pay_url'] );
        $this->assertSame( 'https://pay.example.test/2.pdf', $by[2]['pdf_url'] );
        $this->assertSame( '', $by[4]['pdf_url'] );
    }

    public function test_an_invoice_says_who_it_was_billed_to_and_what_it_is_for(): void {
        $bo   = $this->member( [ 'contact_id' => 6, 'name' => 'Bo Chen', 'assessment_cents' => 20000, 'assessment_label' => 'Trustee Dinner Assessment' ] );
        $rows = [
            $this->row( 1, 2027, 'sent', [ $bo ], [ 'bill_to' => [ 'contact_id' => 6, 'name' => 'Bo Chen', 'email' => 'bo@example.test' ], 'mode' => 'split_assessment', 'kind' => 'assessment' ] ),
            $this->row( 2, 2027, 'sent', [ $this->member() ] ),
        ];
        $inv = MyNJILGA_My_Membership::fee_ledger( $rows )['invoices'];
        $this->assertSame( 'Assessment', $inv[0]['kind_label'] );
        $this->assertSame( 'Bo Chen', $inv[0]['billed_to'] );
        $this->assertSame( 6, $inv[0]['billed_to_id'] );
        $this->assertSame( 'Membership dues', $inv[1]['kind_label'] );
        $this->assertSame( 'Olive Owner', $inv[1]['billed_to'] );
        $this->assertSame( 'Smith & Jones LLP', $inv[1]['firm'] );
    }

    public function test_a_combined_invoice_with_an_assessment_is_labelled_for_both(): void {
        $bo  = $this->member( [ 'assessment_cents' => 20000, 'assessment_label' => 'Trustee Dinner Assessment' ] );
        $inv = MyNJILGA_My_Membership::fee_ledger( [ $this->row( 1, 2027, 'sent', [ $bo ] ) ] )['invoices'];
        $this->assertSame( 'Dues & assessment', $inv[0]['kind_label'] );
        $this->assertSame( 32500, $inv[0]['total_cents'] );
    }

    public function test_a_person_with_no_contact_id_is_still_listed_by_name(): void {
        $ghost  = $this->member( [ 'contact_id' => 0, 'name' => 'Pat Roe' ] );
        $ledger = MyNJILGA_My_Membership::fee_ledger( [ $this->row( 1, 2026, 'paid', [ $ghost ] ) ] );
        $this->assertSame( [ 'n:pat roe' ], array_keys( $ledger['members'] ) );
        $this->assertSame( 'Pat Roe', $ledger['members']['n:pat roe']['name'] );
    }

    public function test_an_invoice_billed_to_someone_else_shows_only_your_own_lines(): void {
        // Ann (5) was on Olive's firm invoice, but no longer belongs to that firm.
        $ann = $this->member();
        $bo  = $this->member( [ 'contact_id' => 6, 'name' => 'Bo Chen', 'dues_cents' => 7500 ] );
        $ledger = MyNJILGA_My_Membership::fee_ledger( [ $this->row( 1, 2027, 'sent', [ $ann, $bo ] ) ], 5 );

        $this->assertSame( [ 5 ], array_keys( $ledger['members'] ), 'the rest of the roster is not hers to see' );
        $inv = $ledger['invoices'][0];
        $this->assertSame( null, $inv['total_cents'], 'nor the invoice total' );
        $this->assertSame( '', $inv['pay_url'], 'and it is not hers to pay' );
        $this->assertSame( '', $inv['pdf_url'] );
        $this->assertSame( 'Olive Owner', $inv['billed_to'], 'but she can see who it is billed to' );
        $this->assertSame( 0, $ledger['due_cents'], 'and it is not her balance' );
    }

    public function test_an_invoice_billed_to_you_is_yours_in_full(): void {
        // Ann paid an online join that covered her colleague Bo.
        $ann = $this->member();
        $bo  = $this->member( [ 'contact_id' => 6, 'name' => 'Bo Chen', 'dues_cents' => 7500 ] );
        $row = $this->row( 1, 2027, 'sent', [ $ann, $bo ], [ 'kind' => 'join', 'bill_to' => [ 'contact_id' => 5, 'name' => 'Ann Brown', 'email' => 'ann@example.test' ] ] );
        $ledger = MyNJILGA_My_Membership::fee_ledger( [ $row ], 5 );

        $this->assertSame( [ 5, 6 ], array_keys( $ledger['members'] ) );
        $this->assertSame( 20000, $ledger['invoices'][0]['total_cents'] );
        $this->assertSame( 'https://pay.example.test/1', $ledger['invoices'][0]['pay_url'] );
        $this->assertSame( 20000, $ledger['due_cents'] );
    }

    public function test_without_a_viewer_limit_the_whole_firm_invoice_shows(): void {
        $ledger = MyNJILGA_My_Membership::fee_ledger( [ $this->row( 1, 2027, 'sent', [ $this->member(), $this->member( [ 'contact_id' => 6, 'name' => 'Bo Chen' ] ) ] ) ] );
        $this->assertSame( [ 5, 6 ], array_keys( $ledger['members'] ) );
        $this->assertSame( 25000, $ledger['invoices'][0]['total_cents'] );
    }

    public function test_no_rows_is_an_empty_ledger(): void {
        $this->assertSame( [ 'invoices' => [], 'members' => [], 'due_cents' => 0 ], MyNJILGA_My_Membership::fee_ledger( [] ) );
    }

    // -------------------------------------------------------------------
    // The SQL read behind "every fee ever listed for me"
    // -------------------------------------------------------------------

    public function test_rows_listing_a_contact_are_read_in_the_mode_asked_for_and_any_year(): void {
        $saved = $GLOBALS['wpdb'];
        $GLOBALS['wpdb'] = new class extends NJILGA_Recording_Wpdb {
            public function esc_like( string $text ): string {
                return addcslashes( $text, '_%\\' );
            }
        };
        try {
            foreach ( [ true, false ] as $live ) {
                $GLOBALS['wpdb']->reset();
                MyNJILGA_Dues_Invoice_Table::rows_listing_contact( 42, $live );
                $sql = $GLOBALS['wpdb']->last_query();
                $this->assertTrue( strpos( $sql, $live ? 'livemode = 1' : 'livemode = 0' ) !== false, $sql );
                $this->assertTrue( strpos( $sql, $live ? 'livemode = 0' : 'livemode = 1' ) === false, $sql );
                $this->assertTrue( strpos( $sql, 'dues_year =' ) === false, 'no year filter: ' . $sql );
                $this->assertTrue( strpos( $sql, "LIKE '%\"contact\\_id\":42,%'" ) !== false, $sql );
            }

            $GLOBALS['wpdb']->reset();
            $this->assertSame( [], MyNJILGA_Dues_Invoice_Table::rows_listing_contact( 0, true ) );
            $this->assertCount( 0, $GLOBALS['wpdb']->queries, 'an unknown contact costs no query' );
        } finally {
            $GLOBALS['wpdb'] = $saved;
        }
    }
}
