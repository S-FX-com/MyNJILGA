<?php
/**
 * What an online join costs — the payer plus any colleagues they add.
 *
 * price() is PURE (plain arrays in, plain arrays out, no WordPress), so
 * tests/JoinPricingTest.php can run it without a site. quote() is the
 * thin wrapper that reads the live settings.
 *
 * Rules:
 *   - Everyone in the join is priced as the chosen category and nothing
 *     else: each roster entry carries only that category's tag. What the
 *     form shows is therefore exactly what Checkout charges — no stray
 *     tag on an existing contact can turn a $125 join into a free one
 *     halfway through, and no assessment is ever added to a join.
 *   - The join is priced on its own, like the existing mid-year rule
 *     (MyNJILGA_Dues_Preview::draft_individual_for_contact): the payer is
 *     the 1st member whatever the firm has already paid for this year.
 *   - Tier-eligible categories rank in the order given — payer first,
 *     then colleagues as entered — via the engine's rank_in_roster_order
 *     option, so the person paying is the one labelled "1st Member".
 *   - Colleagues are only offered on a tier-eligible category (the firm
 *     ladder: 1st $125, 2–5 $75, 6+ free). A flat category (student,
 *     emerging professional) prices the payer alone.
 */
class MyNJILGA_Join_Pricing {

    /**
     * @param array<string,mixed>            $category One normalized category row (MyNJILGA_Dues_Settings).
     * @param array<int,array<string,mixed>> $people   Payer first: [ first_name, last_name, email ] each.
     * @return array{members:array<int,array<string,mixed>>,totals:array<string,int>,lines:array<int,array<string,mixed>>}
     */
    public static function price( array $category, array $people, int $duesYear ): array {
        if ( empty( $category['tier_eligible'] ) ) {
            $people = array_slice( array_values( $people ), 0, 1 );
        }

        $roster = [];
        foreach ( array_values( $people ) as $i => $p ) {
            $first = (string) ( $p['first_name'] ?? '' );
            $last  = (string) ( $p['last_name'] ?? '' );
            $roster[] = [
                // No contact exists yet (fulfillment creates or finds them
                // once the money is in); the engine only needs a stable,
                // distinct tiebreaker.
                'contact_id' => 0,
                'first_name' => $first,
                'last_name'  => $last,
                'name'       => trim( $first . ' ' . $last ),
                'email'      => strtolower( trim( (string) ( $p['email'] ?? '' ) ) ),
                'tags'       => [ (string) ( $category['tag'] ?? '' ) ],
            ];
        }

        // Only the chosen category exists as far as this pricing run is
        // concerned, and nobody is inactive or assessed.
        $engineConfig = [
            'default_category'     => (string) ( $category['key'] ?? '' ),
            'inactive_tag'         => '',
            'categories'           => [ $category ],
            'assessment'           => [ 'label' => '', 'price_cents' => 0, 'qualifiers' => [] ],
            'rank_in_roster_order' => true,
        ];
        $priced  = MyNJILGA_Pricing_Engine::price( $roster, $engineConfig );
        $members = [];
        foreach ( $priced['members'] as $i => $m ) {
            $m['join_role'] = $i === 0 ? 'payer' : 'colleague';
            $members[]      = $m;
        }

        return [
            'members' => $members,
            'totals'  => MyNJILGA_Pricing_Engine::totals( $members ),
            'lines'   => MyNJILGA_Dues_Roster::line_items( $members, $duesYear, MyNJILGA_Dues_Snapshot::KIND_JOIN ),
        ];
    }

    /**
     * The price ladder a category's join page advertises — one row per
     * tier (or a single flat row) — for the form's live summary. Pure.
     *
     * @param array<string,mixed> $category
     * @return array<int,array{from:int,to:int,price_cents:int,label:string}>
     */
    public static function ladder( array $category ): array {
        if ( empty( $category['tier_eligible'] ) || empty( $category['tiers'] ) ) {
            return [ [ 'from' => 1, 'to' => 1, 'price_cents' => (int) ( $category['price_cents'] ?? 0 ), 'label' => (string) ( $category['label'] ?? '' ) ] ];
        }
        $out = [];
        foreach ( (array) $category['tiers'] as $t ) {
            $out[] = [
                'from'        => (int) ( $t['from'] ?? 1 ),
                'to'          => (int) ( $t['to'] ?? 0 ),
                'price_cents' => (int) ( $t['price_cents'] ?? 0 ),
                'label'       => (string) ( $t['label'] ?? '' ),
            ];
        }
        return $out;
    }

    /**
     * Live-settings wrapper around price().
     *
     * @param array<int,array<string,mixed>> $people Payer first.
     * @return array{members:array<int,array<string,mixed>>,totals:array<string,int>,lines:array<int,array<string,mixed>>}|null
     *   Null when the category doesn't exist.
     */
    public static function quote( string $categoryKey, array $people, int $duesYear ): ?array {
        $category = MyNJILGA_Dues_Settings::category( $categoryKey );
        if ( ! $category ) {
            return null;
        }
        return self::price( $category, $people, $duesYear );
    }
}
