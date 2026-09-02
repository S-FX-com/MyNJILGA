<?php
/**
 * Pricing engine (spec §6) — a PURE function. Roster in, priced roster
 * out. No database, no WordPress, no FluentCRM, no globals, no
 * side effects. Everything it needs arrives as plain arrays, which is
 * what makes it unit-testable without a WordPress install
 * (see tests/PricingEngineTest.php).
 *
 * Input roster: one entry per contact —
 *   [ 'contact_id' => int, 'first_name' => string, 'last_name' => string,
 *     'name' => string, 'email' => string, 'tags' => string[] (canonical slugs) ]
 *
 * Input config: MyNJILGA_Dues_Settings::engine_config() —
 *   [ 'default_category' => string, 'inactive_tag' => string,
 *     'categories' => ordered rows (see MyNJILGA_Dues_Settings::defaults()),
 *     'assessment' => [ label, price_cents, qualifiers[] ] ]
 *
 * Rules, in the order they're applied to each contact:
 *
 *   1. Inactive override. A contact carrying the inactive tag is billed
 *      NOTHING this cycle — no dues, no assessment — regardless of any
 *      other tag. Still listed (unbilled_reason 'inactive').
 *   2. Category = the first category (in configured order) whose tag the
 *      contact carries; else the default category; else none. A contact
 *      with no category is listed but not billed (unbilled_reason
 *      'no category tag') — staff need to see them, not guess.
 *   3. Ranking partition. Tier-eligible, active members are ranked 1..n
 *      alphabetically (last name, first name, then contact_id as a
 *      deterministic tiebreaker) and priced by the tier their rank falls
 *      in. Everyone else — non-tier-eligible (comped/exempt) categories,
 *      inactive, uncategorised — is ranked AFTER them and never occupies
 *      a paid slot. This is the one place a plausible implementation
 *      silently produces the wrong total: an exempt Past President whose
 *      surname sorts first must not "use up" the $125 1st-member slot,
 *      and must not push a 5th paying member into the free 6+ bracket.
 *   4. Non-tier-eligible categories charge their flat price (normally $0).
 *   5. Assessment. An ACTIVE contact carrying any qualifying tag owes the
 *      assessment once (capped at one per person, labelled by the first
 *      qualifier in configured order), on top of whatever dues they owe —
 *      including $0 dues (an exempt Senior Trustee still owes the dinner).
 *
 * Output:
 *   [ 'members' => priced entries in billing order,
 *     'totals'  => [ 'dues_cents', 'assessment_cents', 'total_cents',
 *                    'billed_members', 'unbilled_members' ] ]
 */
class MyNJILGA_Pricing_Engine {

    const UNBILLED_INACTIVE    = 'inactive';
    const UNBILLED_NO_CATEGORY = 'no category tag';

    /**
     * @param array<int,array<string,mixed>> $roster
     * @param array<string,mixed>            $config
     * @return array{members:array<int,array<string,mixed>>,totals:array<string,int>}
     */
    public static function price( array $roster, array $config ): array {
        $categories  = self::indexed_categories( $config );
        $defaultKey  = (string) ( $config['default_category'] ?? '' );
        $inactiveTag = (string) ( $config['inactive_tag'] ?? '' );
        $assessment  = (array) ( $config['assessment'] ?? [] );

        // Pass 1 — classify every contact.
        $classified = [];
        foreach ( $roster as $entry ) {
            $tags     = array_map( 'strval', (array) ( $entry['tags'] ?? [] ) );
            $inactive = $inactiveTag !== '' && in_array( $inactiveTag, $tags, true );
            $catKey   = self::category_for( $tags, $config['categories'] ?? [], $defaultKey );
            $category = $catKey !== null ? ( $categories[ $catKey ] ?? null ) : null;

            $classified[] = [
                'entry'    => $entry,
                'tags'     => $tags,
                'inactive' => $inactive,
                'category' => $category,
            ];
        }

        // Pass 2 — partition. Group order IS billing order.
        $rankable = []; // active + tier-eligible category
        $flat     = []; // active + non-tier-eligible category
        $noCat    = []; // active, no category
        $inactive = [];
        foreach ( $classified as $c ) {
            if ( $c['inactive'] ) {
                $inactive[] = $c;
            } elseif ( $c['category'] === null ) {
                $noCat[] = $c;
            } elseif ( ! empty( $c['category']['tier_eligible'] ) ) {
                $rankable[] = $c;
            } else {
                $flat[] = $c;
            }
        }
        usort( $rankable, [ __CLASS__, 'compare' ] );
        usort( $flat, [ __CLASS__, 'compare' ] );
        usort( $noCat, [ __CLASS__, 'compare' ] );
        usort( $inactive, [ __CLASS__, 'compare' ] );

        // Pass 3 — price.
        $members = [];
        $rank    = 0;
        foreach ( $rankable as $c ) {
            $rank++;
            $tier = self::tier_for_rank( $c['category'], $rank );
            $m    = self::base_member( $c );

            $m['rank']               = $rank;
            $m['tier_key']           = (string) ( $tier['key'] ?? '' );
            $m['tier_label']         = (string) ( $tier['label'] ?? '' );
            $m['dues_cents']         = (int) ( $tier['price_cents'] ?? $c['category']['price_cents'] ?? 0 );
            $m['dues_note']          = $m['dues_cents'] > 0 ? '' : $m['tier_label'];
            self::apply_assessment( $m, $c['tags'], $assessment );
            $members[] = $m;
        }
        foreach ( $flat as $c ) {
            $m                      = self::base_member( $c );
            $m['dues_cents']        = (int) ( $c['category']['price_cents'] ?? 0 );
            $m['dues_note']         = $m['dues_cents'] > 0 ? '' : (string) $c['category']['label'];
            self::apply_assessment( $m, $c['tags'], $assessment );
            $members[] = $m;
        }
        foreach ( $noCat as $c ) {
            $m                    = self::base_member( $c );
            $m['unbilled_reason'] = self::UNBILLED_NO_CATEGORY;
            $m['dues_note']       = self::UNBILLED_NO_CATEGORY;
            $members[]            = $m;
        }
        foreach ( $inactive as $c ) {
            $m                    = self::base_member( $c );
            $m['unbilled_reason'] = self::UNBILLED_INACTIVE;
            $m['dues_note']       = self::UNBILLED_INACTIVE;
            $members[]            = $m;
        }

        return [
            'members' => $members,
            'totals'  => self::totals( $members ),
        ];
    }

    /**
     * Totals for any list of priced members (also used by the snapshot
     * reader after the fact, so it lives here rather than being recomputed
     * ad hoc).
     *
     * @param array<int,array<string,mixed>> $members
     * @return array{dues_cents:int,assessment_cents:int,total_cents:int,billed_members:int,unbilled_members:int}
     */
    public static function totals( array $members ): array {
        $dues = 0; $fees = 0; $billed = 0; $unbilled = 0;
        foreach ( $members as $m ) {
            $dues += (int) ( $m['dues_cents'] ?? 0 );
            $fees += (int) ( $m['assessment_cents'] ?? 0 );
            if ( ! empty( $m['unbilled_reason'] ) ) {
                $unbilled++;
            } else {
                $billed++;
            }
        }
        return [
            'dues_cents'       => $dues,
            'assessment_cents' => $fees,
            'total_cents'      => $dues + $fees,
            'billed_members'   => $billed,
            'unbilled_members' => $unbilled,
        ];
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * First category (configured order) whose tag the contact carries;
     * else the default; else null.
     *
     * @param array<int,string>              $tags
     * @param array<int,array<string,mixed>> $categories
     */
    private static function category_for( array $tags, array $categories, string $defaultKey ): ?string {
        foreach ( $categories as $cat ) {
            $tag = (string) ( $cat['tag'] ?? '' );
            if ( $tag !== '' && in_array( $tag, $tags, true ) ) {
                return (string) $cat['key'];
            }
        }
        if ( $defaultKey !== '' ) {
            foreach ( $categories as $cat ) {
                if ( (string) $cat['key'] === $defaultKey ) {
                    return $defaultKey;
                }
            }
        }
        return null;
    }

    /**
     * @return array<string,array<string,mixed>> key => category row
     */
    private static function indexed_categories( array $config ): array {
        $out = [];
        foreach ( (array) ( $config['categories'] ?? [] ) as $cat ) {
            if ( ! empty( $cat['key'] ) ) {
                $out[ (string) $cat['key'] ] = $cat;
            }
        }
        return $out;
    }

    /**
     * The tier whose [from, to] range contains the rank ('to' of 0 means
     * open-ended). Falls back to the last tier, then to a synthetic tier
     * at the category's flat price, so a misconfigured tier table still
     * produces a number rather than a notice.
     *
     * @return array<string,mixed>
     */
    private static function tier_for_rank( array $category, int $rank ): array {
        $tiers = (array) ( $category['tiers'] ?? [] );
        foreach ( $tiers as $tier ) {
            $from = (int) ( $tier['from'] ?? 1 );
            $to   = (int) ( $tier['to'] ?? 0 );
            if ( $rank >= $from && ( $to === 0 || $rank <= $to ) ) {
                return $tier;
            }
        }
        if ( ! empty( $tiers ) ) {
            return end( $tiers );
        }
        return [
            'key'         => '',
            'label'       => (string) ( $category['label'] ?? '' ),
            'price_cents' => (int) ( $category['price_cents'] ?? 0 ),
        ];
    }

    /**
     * @param array<string,mixed> $m    Member being priced (by reference).
     * @param array<int,string>   $tags
     * @param array<string,mixed> $assessment
     */
    private static function apply_assessment( array &$m, array $tags, array $assessment ): void {
        $price = (int) ( $assessment['price_cents'] ?? 0 );
        foreach ( (array) ( $assessment['qualifiers'] ?? [] ) as $q ) {
            $tag = (string) ( $q['tag'] ?? '' );
            if ( $tag !== '' && in_array( $tag, $tags, true ) ) {
                $m['assessment_cents']        = $price;
                $m['assessment_label']        = (string) ( $assessment['label'] ?? 'Assessment' );
                $m['assessment_qualifier']    = (string) ( $q['label'] ?? $tag );
                return; // Capped at one per person — first qualifier wins.
            }
        }
    }

    /**
     * The zero-charge skeleton every priced member starts from.
     *
     * @param array<string,mixed> $c Classified entry.
     * @return array<string,mixed>
     */
    private static function base_member( array $c ): array {
        $e   = $c['entry'];
        $cat = $c['category'];
        return [
            'contact_id'              => (int) ( $e['contact_id'] ?? 0 ),
            'name'                    => (string) ( $e['name'] ?? trim( ( $e['first_name'] ?? '' ) . ' ' . ( $e['last_name'] ?? '' ) ) ),
            'first_name'              => (string) ( $e['first_name'] ?? '' ),
            'last_name'               => (string) ( $e['last_name'] ?? '' ),
            'email'                   => (string) ( $e['email'] ?? '' ),
            'tags'                    => $c['tags'],
            'inactive'                => (bool) $c['inactive'],
            'category_key'            => $cat ? (string) $cat['key'] : '',
            'category_label'          => $cat ? (string) $cat['label'] : '',
            'tier_eligible'           => $cat ? ! empty( $cat['tier_eligible'] ) : false,
            'role'                    => $cat ? (string) ( $cat['role'] ?? '' ) : '',
            'rank'                    => 0,
            'tier_key'                => '',
            'tier_label'              => '',
            'dues_cents'              => 0,
            'dues_note'               => '',
            'assessment_cents'        => 0,
            'assessment_label'        => '',
            'assessment_qualifier'    => '',
            'unbilled_reason'         => '',
        ];
    }

    /**
     * Deterministic billing order: last name, first name, contact id.
     */
    private static function compare( array $a, array $b ): int {
        $ea = $a['entry']; $eb = $b['entry'];
        $cmp = strcasecmp( (string) ( $ea['last_name'] ?? '' ), (string) ( $eb['last_name'] ?? '' ) );
        if ( $cmp !== 0 ) {
            return $cmp;
        }
        $cmp = strcasecmp( (string) ( $ea['first_name'] ?? '' ), (string) ( $eb['first_name'] ?? '' ) );
        if ( $cmp !== 0 ) {
            return $cmp;
        }
        return ( (int) ( $ea['contact_id'] ?? 0 ) ) <=> ( (int) ( $eb['contact_id'] ?? 0 ) );
    }
}
