<?php
/**
 * Membership statistics — the ONE place that decides who counts as a
 * member and what state they are in. The Dashboard, every Reports tile,
 * list, CSV and Excel export (via MyNJILGA_Members_Data) and the
 * member-facing [njilga_my_membership] page all read the answer from here
 * or from the rule this class calls, so two screens can no longer show two
 * numbers for the same thing.
 *
 * The rules (decided, and pinned by tests/MembershipStatsTest.php):
 *
 *   Year          Y = MyNJILGA_Invoicing::current_dues_year().
 *   Standing      MyNJILGA_My_Membership::standing( facts, Y ), called
 *                 verbatim, with facts built exactly as
 *                 MyNJILGA_My_Membership::summarize() builds them: the
 *                 "Dues Paid {year}" tags (Settings → year tag pattern),
 *                 the paid / unpaid / inactive tags from Dues & Billing
 *                 settings (NOT the SLUG_* constants — payments stamp the
 *                 configured slugs), and dues-exempt = Past President or
 *                 Senior Trustee (MyNJILGA_Tags::EXEMPT_SLUGS). "Active" is
 *                 state 'active', split into dated (a year tag says so) and
 *                 assumed (the evergreen tag and no year tag at all).
 *   Status        A contact's FluentCRM marketing status (subscribed,
 *                 pending, transactional, unsubscribed…) is never a filter.
 *                 Online-join colleagues are 'transactional' or 'pending'
 *                 and are paid members; a member who unsubscribed from the
 *                 newsletter still owes and pays dues. members.not_emailable
 *                 reports how many active members cannot be emailed, so a
 *                 gap to the old subscribed-only numbers is explainable.
 *   Universe      Contacts carrying any dues signal (the paid, unpaid or
 *                 inactive tag, any "Dues Paid {year}" tag, a dues-exempt
 *                 tag, a trustee-family tag) UNION every contact on a firm
 *                 roster. A newsletter subscriber with none of that is not
 *                 a member and is never counted. A roster contact with no
 *                 dues signal is state 'none': counted in members.none,
 *                 neither active nor expired.
 *   Firms         Membership comes from the Company ROSTER
 *                 ($company->subscribers), never $sub->company_id or the
 *                 company_name custom field. A contact on two rosters counts
 *                 once in members and once in each firm. Companies with no
 *                 contacts are counted separately (firms.empty) and never
 *                 mixed into 'without_active'. A firm is "with_active" when
 *                 at least one of its contacts is active.
 *   Trustees      The family is the tags trustees / senior-trustee /
 *                 past-president (MyNJILGA_Tags::TRUSTEE_SLUGS), role
 *                 priority Past President > Senior Trustee > Trustee. The
 *                 tiles PARTITION it: exempt (a Past President or Senior
 *                 Trustee tag, whatever their dues state) + active + expired
 *                 + other (inactive, or no dues on record) = total, with
 *                 active/expired/other counting non-exempt trustees only.
 *                 Officer is fee-eligible but not trustee-family: counted
 *                 separately (trustees.officers), never in the partition.
 *
 * Shape of the work:
 *
 *   collect()    WordPress + FluentCRM, a CONSTANT number of queries whatever
 *                the membership size: all tags once (slug → id resolved
 *                locally by the same rule as MyNJILGA_Tags::resolve_one),
 *                one Subscriber::filterByTags([id]) per tag that matters,
 *                one Company::with(['subscribers','owner']) for rosters.
 *                No per-contact query, no hasAnyTagId().
 *   assemble()   PURE. Keeps the universe, classifies, puts each contact on
 *                its firms. That is what data() hands to the report lists.
 *   aggregate()  PURE. Tallies contacts and firms into the snapshot figures.
 *   snapshot()   aggregate() cached in a 10-minute transient; flush() drops it.
 *
 * Everything not marked WP-facing has no WordPress or FluentCRM dependency
 * and is covered by tests/MembershipStatsTest.php.
 */
class MyNJILGA_Membership_Stats {

    const TRANSIENT = 'njilga_membership_stats';
    const TTL       = 600;

    /**
     * Trustee role wording by tag slug — MyNJILGA_Tags::trustee_status()'s
     * labels. The order of MyNJILGA_Tags::TRUSTEE_SLUGS is the priority
     * (Past President > Senior Trustee > Trustee); a test pins both.
     *
     * @var array<string,string>
     */
    const ROLE_LABELS = [
        MyNJILGA_Tags::SLUG_PAST_PRESIDENT => 'Past President',
        MyNJILGA_Tags::SLUG_SENIOR_TRUSTEE => 'Senior Trustee',
        MyNJILGA_Tags::SLUG_TRUSTEES       => 'Trustee',
    ];

    /** @var array<string,mixed>|null Per-request memo of data(); flush() clears it. */
    private static $memo = null;

    // =========================================================================
    // Pure logic — no WordPress, no FluentCRM
    // =========================================================================

    /**
     * One contact, judged.
     *
     * $contact (the "fact" collect() builds; every key optional):
     *   paid_years    int[]     years of the "Dues Paid {year}" tags held
     *   paid_tag      bool      holds the configured evergreen paid tag
     *   unpaid_tag    bool      holds the configured evergreen unpaid tag
     *   inactive      bool      holds the configured inactive tag
     *   category_tags string[]  the category tag slugs (Settings → categories) held
     *   role_tags     string[]  MyNJILGA_Tags slugs held: trustees,
     *                           senior-trustee, past-president, officer,
     *                           paid-by-check, paid-by-invoice
     * $cfg: year (int, required), categories, default_category.
     *
     * 'defaulted' is true when the category is only the default_category
     * because the contact holds no category tag.
     *
     * @param array<string,mixed> $contact
     * @param array<string,mixed> $cfg
     * @return array{state:string,through:?int,assumed:bool,category:?string,defaulted:bool,exempt:bool,trustee_role:string,is_trustee:bool,officer:bool,signal:bool}
     */
    public static function classify( array $contact, array $cfg ): array {
        $roles  = array_map( 'strval', (array) ( $contact['role_tags'] ?? [] ) );
        $exempt = (bool) array_intersect( MyNJILGA_Tags::EXEMPT_SLUGS, $roles );

        $standing = MyNJILGA_My_Membership::standing( [
            'paid_years' => (array) ( $contact['paid_years'] ?? [] ),
            'paid_tag'   => ! empty( $contact['paid_tag'] ),
            'unpaid_tag' => ! empty( $contact['unpaid_tag'] ),
            'exempt'     => $exempt,
            'inactive'   => ! empty( $contact['inactive'] ),
        ], (int) ( $cfg['year'] ?? 0 ) );

        $categories = (array) ( $cfg['categories'] ?? [] );
        $held       = array_map( 'strval', (array) ( $contact['category_tags'] ?? [] ) );
        $category   = MyNJILGA_Pricing_Engine::category_for( $held, $categories, (string) ( $cfg['default_category'] ?? '' ) );

        $hasCategoryTag = false;
        foreach ( $categories as $cat ) {
            $tag = (string) ( $cat['tag'] ?? '' );
            if ( $tag !== '' && in_array( $tag, $held, true ) ) {
                $hasCategoryTag = true;
                break;
            }
        }

        $role = self::trustee_role( $roles );

        return [
            'state'        => $standing['state'],
            'through'      => $standing['through'],
            'assumed'      => $standing['assumed'],
            'category'     => $category,
            'defaulted'    => $category !== null && ! $hasCategoryTag,
            'exempt'       => $exempt,
            'trustee_role' => $role,
            'is_trustee'   => $role !== '',
            'officer'      => in_array( MyNJILGA_Tags::SLUG_OFFICER, $roles, true ),
            'signal'       => self::has_dues_signal( $contact ),
        ];
    }

    /**
     * Whether the contact carries anything that makes them a member of
     * NJILGA's books: the paid / unpaid / inactive tag, any dated payment
     * tag, or a trustee-family tag (which includes the dues-exempt Past
     * President and Senior Trustee). A firm-roster contact is a member
     * whether or not this is true — see the class docblock.
     *
     * @param array<string,mixed> $contact
     */
    public static function has_dues_signal( array $contact ): bool {
        if ( ! empty( $contact['paid_tag'] ) || ! empty( $contact['unpaid_tag'] ) || ! empty( $contact['inactive'] ) || ! empty( $contact['paid_years'] ) ) {
            return true;
        }
        $roles = array_map( 'strval', (array) ( $contact['role_tags'] ?? [] ) );
        return (bool) array_intersect( array_merge( MyNJILGA_Tags::EXEMPT_SLUGS, MyNJILGA_Tags::TRUSTEE_SLUGS ), $roles );
    }

    /**
     * The most distinguished trustee role among the tag slugs held, or ''
     * — MyNJILGA_Tags::trustee_status() over slugs instead of tag queries.
     *
     * @param array<int,string> $roleTags
     */
    public static function trustee_role( array $roleTags ): string {
        foreach ( MyNJILGA_Tags::TRUSTEE_SLUGS as $slug ) {
            if ( in_array( $slug, $roleTags, true ) ) {
                return self::ROLE_LABELS[ $slug ];
            }
        }
        return '';
    }

    /**
     * The firm-size bucket for a number of ACTIVE contacts: '0', '1',
     * '2-5' or '6+'. The Dashboard's distribution, the Companies report and
     * firms.size all bucket through this, so they cannot drift.
     */
    public static function size_bucket( int $active ): string {
        if ( $active <= 0 ) {
            return '0';
        }
        if ( $active === 1 ) {
            return '1';
        }
        return $active <= 5 ? '2-5' : '6+';
    }

    /**
     * One firm's people by state: how many contacts, how many active, how
     * many dues-exempt. A contact id with no known state counts as 'none'.
     *
     * @param array<int,string> $stateById contact id => standing state
     * @param array<int,int>    $contactIds
     * @return array{total:int,active:int,exempt:int}
     */
    public static function firm_tally( array $stateById, array $contactIds ): array {
        $tally = [ 'total' => 0, 'active' => 0, 'exempt' => 0 ];
        foreach ( $contactIds as $cid ) {
            $state = (string) ( $stateById[ (int) $cid ] ?? MyNJILGA_My_Membership::STATE_NONE );
            $tally['total']++;
            if ( $state === MyNJILGA_My_Membership::STATE_ACTIVE ) {
                $tally['active']++;
            } elseif ( $state === MyNJILGA_My_Membership::STATE_EXEMPT ) {
                $tally['exempt']++;
            }
        }
        return $tally;
    }

    /**
     * Every figure of the snapshot, from classified-on-the-fly contacts and
     * firm rosters. PURE: all it knows is what it is handed.
     *
     * $contacts: contact facts (see classify()), keyed by contact id or
     * carrying 'id', plus optionally 'status' (FluentCRM marketing status).
     * Contacts outside the universe are ignored, so this can be handed
     * everything collect() saw. $firms: [ id, owner_id, contact_ids[] ] each.
     *
     * Notes on the figures that are not obvious:
     *   members.exempt      standing 'exempt' — dues-exempt AND not already
     *                       active by payment. (trustees.exempt is the tag
     *                       count, whatever their dues state.)
     *   members.none        roster contacts with no dues signal, plus
     *                       trustee-family contacts with no dues tag.
     *   members.paid_ahead  active and paid through a year after Y.
     *   members.renewing    dated-active and paid through exactly Y: the
     *                       people whose membership ends 12/31/Y.
     *   members.not_emailable  ACTIVE members whose FluentCRM status is not
     *                       'subscribed'.
     *   categories.*.defaulted  of that category's active + expired, how many
     *                       hold no category tag and are here only because
     *                       default_category applies.
     *   firms.no_owner      among firms with at least one contact; a firm
     *                       with nobody on it is not invoiced regardless.
     *   firms.exempt_only   firms without an active member whose every
     *                       contact is dues-exempt.
     *   no_firm.by_category every category key (Settings order), zero
     *                       included; '' collects active members whose
     *                       category resolves to none.
     *
     * @param array<int|string,array<string,mixed>> $contacts
     * @param array<int|string,array<string,mixed>> $firms
     * @param array<string,mixed>                   $cfg      year, categories, default_category
     * @return array{year:int,next_year:int,members:array<string,mixed>,categories:array<string,array<string,mixed>>,firms:array<string,mixed>,no_firm:array<string,mixed>,trustees:array<string,int>,reconcile:array<string,int>}
     */
    public static function aggregate( array $contacts, array $firms, array $cfg ): array {
        $year       = (int) ( $cfg['year'] ?? 0 );
        $categories = (array) ( $cfg['categories'] ?? [] );

        $rosters  = self::rosters( $firms );
        $universe = self::universe( $contacts, $rosters );

        $members = [
            'active'        => 0,
            'dated'         => 0,
            'assumed'       => 0,
            'expired'       => 0,
            'exempt'        => 0,
            'inactive'      => 0,
            'none'          => 0,
            'paid_ahead'    => 0,
            'renewing'      => 0,
            'not_emailable' => 0,
            'through'       => [],
        ];

        $byCategory = [];
        $noFirmCats = [];
        foreach ( $categories as $cat ) {
            if ( ! empty( $cat['key'] ) ) {
                $byCategory[ (string) $cat['key'] ] = [ 'label' => (string) ( $cat['label'] ?? $cat['key'] ), 'active' => 0, 'expired' => 0, 'defaulted' => 0 ];
                $noFirmCats[ (string) $cat['key'] ] = 0;
            }
        }

        $trustees = [
            'total'           => 0,
            'trustees'        => 0,
            'senior_trustees' => 0,
            'past_presidents' => 0,
            'officers'        => 0,
            'active'          => 0,
            'expired'         => 0,
            'exempt'          => 0,
            'other'           => 0,
        ];
        $roleKey = [ 'Trustee' => 'trustees', 'Senior Trustee' => 'senior_trustees', 'Past President' => 'past_presidents' ];

        $reconcile = [ 'paid_tag' => 0, 'stale_paid_tag' => 0, 'active_without_paid_tag' => 0, 'both_tags' => 0 ];
        $noFirm    = [ 'active' => 0, 'by_category' => $noFirmCats ];
        $stateById = [];

        foreach ( $universe as $id => $c ) {
            $k     = self::classify( $c, $cfg );
            $state = $k['state'];
            $stateById[ $id ] = $state;
            $members[ $state ]++;

            $isActive  = $state === MyNJILGA_My_Membership::STATE_ACTIVE;
            $isExpired = $state === MyNJILGA_My_Membership::STATE_EXPIRED;

            if ( $isActive ) {
                if ( $k['assumed'] ) {
                    $members['assumed']++;
                } else {
                    $members['dated']++;
                }
                $through = (int) $k['through'];
                $members['through'][ $through ] = ( $members['through'][ $through ] ?? 0 ) + 1;
                if ( $through > $year ) {
                    $members['paid_ahead']++;
                } elseif ( ! $k['assumed'] && $through === $year ) {
                    $members['renewing']++;
                }
                if ( (string) ( $c['status'] ?? '' ) !== 'subscribed' ) {
                    $members['not_emailable']++;
                }
            }

            if ( $k['category'] !== null && isset( $byCategory[ $k['category'] ] ) && ( $isActive || $isExpired ) ) {
                $byCategory[ $k['category'] ][ $isActive ? 'active' : 'expired' ]++;
                if ( $k['defaulted'] ) {
                    $byCategory[ $k['category'] ]['defaulted']++;
                }
            }

            if ( ! empty( $c['paid_tag'] ) ) {
                $reconcile['paid_tag']++;
                if ( ! $isActive ) {
                    $reconcile['stale_paid_tag']++;
                }
                if ( ! empty( $c['unpaid_tag'] ) ) {
                    $reconcile['both_tags']++;
                }
            } elseif ( $isActive ) {
                $reconcile['active_without_paid_tag']++;
            }

            if ( $k['officer'] ) {
                $trustees['officers']++;
            }
            if ( $k['is_trustee'] ) {
                $trustees['total']++;
                $trustees[ $roleKey[ $k['trustee_role'] ] ]++;
                if ( $k['exempt'] ) {
                    $trustees['exempt']++;
                } elseif ( $isActive ) {
                    $trustees['active']++;
                } elseif ( $isExpired ) {
                    $trustees['expired']++;
                } else {
                    $trustees['other']++;
                }
            }

            if ( $isActive && empty( $rosters[ $id ] ) ) {
                $noFirm['active']++;
                $key = $k['category'] !== null ? $k['category'] : '';
                $noFirm['by_category'][ $key ] = ( $noFirm['by_category'][ $key ] ?? 0 ) + 1;
            }
        }
        ksort( $members['through'] );

        $firmStats = [
            'total'          => 0,
            'with_active'    => 0,
            'without_active' => 0,
            'exempt_only'    => 0,
            'empty'          => 0,
            'no_owner'       => 0,
            'size'           => [ '1' => 0, '2-5' => 0, '6+' => 0 ],
        ];
        foreach ( $firms as $firm ) {
            $ids = array_values( array_unique( array_map( 'intval', (array) ( $firm['contact_ids'] ?? [] ) ) ) );
            if ( ! $ids ) {
                $firmStats['empty']++;
                continue;
            }
            $firmStats['total']++;
            if ( empty( $firm['owner_id'] ) ) {
                $firmStats['no_owner']++;
            }
            $t = self::firm_tally( $stateById, $ids );
            if ( $t['active'] > 0 ) {
                $firmStats['with_active']++;
                $firmStats['size'][ self::size_bucket( $t['active'] ) ]++;
            } else {
                $firmStats['without_active']++;
                if ( $t['exempt'] === $t['total'] ) {
                    $firmStats['exempt_only']++;
                }
            }
        }

        return [
            'year'       => $year,
            'next_year'  => $year + 1,
            'members'    => $members,
            'categories' => $byCategory,
            'firms'      => $firmStats,
            'no_firm'    => $noFirm,
            'trustees'   => $trustees,
            'reconcile'  => $reconcile,
        ];
    }

    /**
     * collect()'s output → what data() serves: only the universe, each
     * contact classified and placed on its firms.
     *
     * A contact gains classify()'s keys plus 'firm_ids' — the rosters they
     * are on, their primary company (primary_firm_id) first, the rest
     * alphabetical. Roster contacts collect() did not see in a tag query
     * are filled in with empty facts (state 'none'). Firms come back keyed
     * by id, alphabetical (case-insensitive), contact_ids de-duplicated.
     *
     * @param array<string,mixed> $collected cfg, warnings, contacts, firms
     * @return array{available:bool,warnings:array<int,string>,cfg:array<string,mixed>,contacts:array<int,array<string,mixed>>,firms:array<int,array<string,mixed>>}
     */
    public static function assemble( array $collected ): array {
        $cfg = (array) ( $collected['cfg'] ?? [] );

        $firms = [];
        foreach ( (array) ( $collected['firms'] ?? [] ) as $firm ) {
            $id           = (int) ( $firm['id'] ?? 0 );
            $firms[ $id ] = [
                'id'          => $id,
                'name'        => (string) ( $firm['name'] ?? '' ),
                'owner_id'    => (int) ( $firm['owner_id'] ?? 0 ),
                'owner_name'  => (string) ( $firm['owner_name'] ?? '' ),
                'contact_ids' => array_values( array_unique( array_map( 'intval', (array) ( $firm['contact_ids'] ?? [] ) ) ) ),
            ];
        }
        uasort( $firms, static function ( $a, $b ) {
            $cmp = strcasecmp( $a['name'], $b['name'] );
            return $cmp !== 0 ? $cmp : $a['id'] <=> $b['id'];
        } );

        $rosters  = self::rosters( $firms );
        $universe = self::universe( (array) ( $collected['contacts'] ?? [] ), $rosters );

        $contacts = [];
        foreach ( $universe as $id => $c ) {
            $c       = $c + self::blank_contact( $id );
            $firmIds = $rosters[ $id ] ?? [];
            $primary = (int) $c['primary_firm_id'];
            $at      = $primary > 0 ? array_search( $primary, $firmIds, true ) : false;
            if ( $at !== false && $at > 0 ) {
                unset( $firmIds[ $at ] );
                array_unshift( $firmIds, $primary );
            }
            $contacts[ $id ] = $c + self::classify( $c, $cfg ) + [ 'firm_ids' => array_values( $firmIds ) ];
        }

        return [
            'available' => true,
            'warnings'  => array_values( (array) ( $collected['warnings'] ?? [] ) ),
            'cfg'       => $cfg,
            'contacts'  => $contacts,
            'firms'     => $firms,
        ];
    }

    /**
     * A contact with no tags and no display data — the starting point for a
     * roster contact no tag query returned, and for apply_tag().
     *
     * @return array<string,mixed>
     */
    public static function blank_contact( int $id ): array {
        return [
            'id'              => $id,
            'status'          => '',
            'first_name'      => '',
            'last_name'       => '',
            'email'           => '',
            'name'            => '(contact #' . $id . ')',
            'primary_firm_id' => 0,
            'paid_years'      => [],
            'paid_tag'        => false,
            'unpaid_tag'      => false,
            'inactive'        => false,
            'category_tags'   => [],
            'role_tags'       => [],
            'model'           => null,
        ];
    }

    /**
     * Record that a contact holds one tag the plan asked about. $key is a
     * plan_tags() use: 'paid' | 'unpaid' | 'inactive' (booleans), 'year'
     * (a dues year, kept newest first), 'category' | 'role' (a slug). Safe
     * to repeat: two tags that both read as "Dues Paid 2026" add one year.
     *
     * @param array<string,mixed> $contact
     * @param mixed               $value
     * @return array<string,mixed>
     */
    public static function apply_tag( array $contact, string $key, $value ): array {
        switch ( $key ) {
            case 'paid':
                $contact['paid_tag'] = true;
                break;
            case 'unpaid':
                $contact['unpaid_tag'] = true;
                break;
            case 'inactive':
                $contact['inactive'] = true;
                break;
            case 'year':
                $years = array_map( 'intval', (array) ( $contact['paid_years'] ?? [] ) );
                if ( ! in_array( (int) $value, $years, true ) ) {
                    $years[] = (int) $value;
                }
                rsort( $years );
                $contact['paid_years'] = $years;
                break;
            case 'category':
            case 'role':
                $field = $key === 'category' ? 'category_tags' : 'role_tags';
                $held  = array_map( 'strval', (array) ( $contact[ $field ] ?? [] ) );
                if ( ! in_array( (string) $value, $held, true ) ) {
                    $held[] = (string) $value;
                }
                $contact[ $field ] = $held;
                break;
        }
        return $contact;
    }

    /**
     * The tag → id half of collect(), with no FluentCRM in it. Resolves each
     * wanted tag the way MyNJILGA_Tags::resolve_one() does — the slug first,
     * then the exact title (both compared case-insensitively, as the
     * database collation does; the lowest id wins a tie, like first()) —
     * against the one list all_tags() returned, and adds every "Dues Paid
     * {year}" tag found in that list.
     *
     * $specs, each: key (paid|unpaid|inactive|category|role), slug (already
     * sanitised), title, value (what apply_tag() records), label (words for
     * a warning), warn (whether a miss is worth a warning).
     *
     * 'by_id' is one entry per tag to query: id => uses [ key, value ]
     * (several when two settings name the same tag, so it is queried once).
     * 'year_ids' is year => tag ids. 'unresolved' lists the slug + label of
     * each warn-worthy spec that matched nothing, once per slug.
     *
     * @param array<int,array{id:int,slug:string,title:string}> $allTags
     * @param array<int,array<string,mixed>>                    $specs
     * @return array{by_id:array<int,array<int,array{key:string,value:mixed}>>,year_ids:array<int,array<int,int>>,unresolved:array<int,array{slug:string,label:string}>}
     */
    public static function plan_tags( array $allTags, array $specs, string $pattern ): array {
        $byId       = [];
        $unresolved = [];
        $reported   = [];

        foreach ( $specs as $spec ) {
            $slug = (string) $spec['slug'];
            if ( $slug === '' ) {
                continue;
            }
            $id = self::resolve_tag_id( $allTags, $slug, (string) $spec['title'] );
            if ( $id === null ) {
                if ( ! empty( $spec['warn'] ) && ! isset( $reported[ $slug ] ) ) {
                    $reported[ $slug ] = true;
                    $unresolved[]      = [ 'slug' => $slug, 'label' => (string) $spec['label'] ];
                }
                continue;
            }
            $byId[ $id ][] = [ 'key' => (string) $spec['key'], 'value' => $spec['value'] ];
        }

        $yearIds = [];
        foreach ( $allTags as $tag ) {
            foreach ( MyNJILGA_My_Membership::paid_years( [ [ 'title' => (string) $tag['title'], 'slug' => (string) $tag['slug'] ] ], $pattern ) as $year ) {
                $yearIds[ $year ][]         = (int) $tag['id'];
                $byId[ (int) $tag['id'] ][] = [ 'key' => 'year', 'value' => $year ];
            }
        }
        ksort( $yearIds );

        return [ 'by_id' => $byId, 'year_ids' => $yearIds, 'unresolved' => $unresolved ];
    }

    /**
     * MyNJILGA_Tags::resolve_one() over an in-memory tag list: the slug,
     * else the exact title; null when neither matches.
     *
     * @param array<int,array{id:int,slug:string,title:string}> $allTags
     */
    public static function resolve_tag_id( array $allTags, string $slug, string $title ): ?int {
        foreach ( [ [ 'slug', $slug ], [ 'title', $title ] ] as $pair ) {
            $best = null;
            foreach ( $allTags as $tag ) {
                if ( $pair[1] !== '' && strcasecmp( (string) $tag[ $pair[0] ], $pair[1] ) === 0 ) {
                    $id   = (int) $tag['id'];
                    $best = $best === null ? $id : min( $best, $id );
                }
            }
            if ( $best !== null ) {
                return $best;
            }
        }
        return null;
    }

    /**
     * The words shown when the figures rest on something that is not there.
     *
     * $ctx: unresolved (plan_tags() output), year, pattern, year_ids
     * (plan_tags() output), companies_active, paid_tag (the configured slug).
     *
     * @param array<string,mixed> $ctx
     * @return array<int,string>
     */
    public static function build_warnings( array $ctx ): array {
        $warnings = [];

        foreach ( (array) ( $ctx['unresolved'] ?? [] ) as $u ) {
            $warnings[] = sprintf( 'The %s "%s" was not found in FluentCRM, so no contact counts as holding it.', $u['label'], $u['slug'] );
        }

        $year    = (int) ( $ctx['year'] ?? 0 );
        $pattern = (string) ( $ctx['pattern'] ?? '' );
        if ( strpos( $pattern, '{year}' ) === false ) {
            $warnings[] = sprintf( 'The year paid-tag pattern "%s" has no {year} in it, so dated payments cannot be read and only the evergreen paid tag counts.', $pattern );
        } elseif ( empty( $ctx['year_ids'][ $year ] ) ) {
            $warnings[] = sprintf( 'No "%s" tag exists yet, so no member has a dated payment for %d. Members still count as active from a later year\'s Dues Paid tag or, with no date, from the older Dues Paid tag.', str_replace( '{year}', (string) $year, $pattern ), $year );
        }

        if ( empty( $ctx['companies_active'] ) ) {
            $warnings[] = 'The FluentCRM Companies module is not active, so every firm figure is zero.';
        }

        $paid = (string) ( $ctx['paid_tag'] ?? MyNJILGA_Tags::SLUG_DUES_PAID );
        if ( $paid !== MyNJILGA_Tags::SLUG_DUES_PAID ) {
            $warnings[] = sprintf( 'The paid tag in Dues & Billing settings is "%s", not "%s". Figures follow the setting, so contacts carrying only "%s" are not counted as paid.', $paid, MyNJILGA_Tags::SLUG_DUES_PAID, MyNJILGA_Tags::SLUG_DUES_PAID );
        }

        return $warnings;
    }

    /**
     * Contact id → the ids of the firms whose roster it is on, in the order
     * the firms were given.
     *
     * @param array<int|string,array<string,mixed>> $firms
     * @return array<int,array<int,int>>
     */
    private static function rosters( array $firms ): array {
        $out = [];
        foreach ( $firms as $firm ) {
            $fid = (int) ( $firm['id'] ?? 0 );
            foreach ( (array) ( $firm['contact_ids'] ?? [] ) as $cid ) {
                $cid = (int) $cid;
                if ( empty( $out[ $cid ] ) || ! in_array( $fid, $out[ $cid ], true ) ) {
                    $out[ $cid ][] = $fid;
                }
            }
        }
        return $out;
    }

    /**
     * The member universe: contacts with a dues signal plus every roster
     * contact (an id no fact was given for gets an empty one), keyed by id.
     *
     * @param array<int|string,array<string,mixed>> $contacts
     * @param array<int,array<int,int>>             $rosters
     * @return array<int,array<string,mixed>>
     */
    private static function universe( array $contacts, array $rosters ): array {
        $out = [];
        foreach ( $contacts as $key => $c ) {
            $id = (int) ( $c['id'] ?? $key );
            if ( isset( $rosters[ $id ] ) || self::has_dues_signal( $c ) ) {
                $out[ $id ] = [ 'id' => $id ] + $c;
            }
        }
        foreach ( array_keys( $rosters ) as $id ) {
            if ( ! isset( $out[ $id ] ) ) {
                $out[ $id ] = [ 'id' => $id ];
            }
        }
        return $out;
    }

    // =========================================================================
    // WordPress + FluentCRM
    // =========================================================================

    /**
     * The figures, from a 10-minute transient unless $refresh. Never throws:
     * without FluentCRM, or if reading it fails, it says so instead —
     * [ 'available' => false, 'warnings' => [ reason ] ].
     *
     * A cached copy from before the year rolled over is not served, so on
     * 1 January UTC the first read recomputes rather than reporting last
     * year's members as active for up to ten minutes.
     *
     * @return array<string,mixed>
     */
    public static function snapshot( bool $refresh = false ): array {
        if ( ! MyNJILGA_Members_Data::fluentcrm_active() ) {
            return [ 'available' => false, 'warnings' => [ 'FluentCRM is not active.' ] ];
        }

        if ( ! $refresh ) {
            $cached = get_transient( self::TRANSIENT );
            if ( is_array( $cached ) && ! empty( $cached['available'] ) && (int) ( $cached['year'] ?? 0 ) === MyNJILGA_Invoicing::current_dues_year() ) {
                return $cached;
            }
        }

        $data = self::data();
        if ( empty( $data['available'] ) ) {
            return [ 'available' => false, 'warnings' => (array) $data['warnings'] ];
        }

        $snapshot = [
            'available' => true,
            'warnings'  => (array) $data['warnings'],
            'generated' => gmdate( 'Y-m-d H:i:s' ),
        ] + self::aggregate( $data['contacts'], $data['firms'], $data['cfg'] );

        set_transient( self::TRANSIENT, $snapshot, self::TTL );
        return $snapshot;
    }

    /**
     * Drop the cached snapshot and this request's memo, so the next read
     * sees what changed — call it after anything that moves a member from
     * one state to another (a payment settling, the downgrade sweep, an
     * approval).
     */
    public static function flush(): void {
        delete_transient( self::TRANSIENT );
        self::$memo = null;
    }

    /**
     * The contacts and firms the report lists are built from — collected
     * and classified once per request. Never throws (a failure reads as
     * available => false with the reason in warnings).
     *
     * Shape (assemble()'s return):
     *   available  bool
     *   warnings   string[]
     *   cfg        year, categories, default_category, pattern, paid_tag, unpaid_tag, inactive_tag
     *   contacts   [ contact id => [
     *                  id, status, first_name, last_name, email, name (never blank),
     *                  primary_firm_id, model (the FluentCRM Subscriber, or null),
     *                  paid_years, paid_tag, unpaid_tag, inactive, category_tags, role_tags,
     *                  + classify(): state, through, assumed, category, defaulted, exempt,
     *                    trustee_role, is_trustee, officer, signal,
     *                  firm_ids (rosters, primary first) ] ]   the member universe only
     *   firms      [ firm id => [ id, name, owner_id, owner_name, contact_ids[] ] ]  alphabetical
     *
     * @return array<string,mixed>
     */
    public static function data(): array {
        if ( self::$memo !== null ) {
            return self::$memo;
        }
        if ( ! MyNJILGA_Members_Data::fluentcrm_active() ) {
            return [ 'available' => false, 'warnings' => [ 'FluentCRM is not active.' ], 'cfg' => [], 'contacts' => [], 'firms' => [] ];
        }
        try {
            self::$memo = self::assemble( self::collect() );
        } catch ( \Throwable $e ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[My NJILGA] Membership stats failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            }
            // Memoised too, so one page does not repeat a failing read per list.
            self::$memo = [ 'available' => false, 'warnings' => [ 'Membership figures could not be read from FluentCRM. Please try again.' ], 'cfg' => [], 'contacts' => [], 'firms' => [] ];
        }
        return self::$memo;
    }

    /**
     * Read FluentCRM: every tag once, one query per tag that matters, one
     * for the firm rosters — a constant number of queries however many
     * members there are. It uses only calls this plugin already relies on
     * elsewhere: Tag::orderBy()->get() (MyNJILGA_Tags::all_tags()),
     * Subscriber::filterByTags([id])->get() and
     * Company::with(['subscribers','owner'])->get().
     *
     * @return array{cfg:array<string,mixed>,warnings:array<int,string>,contacts:array<int,array<string,mixed>>,firms:array<int,array<string,mixed>>}
     */
    public static function collect(): array {
        $cfg  = self::config();
        $plan = self::plan_tags( MyNJILGA_Tags::all_tags(), self::tag_specs( $cfg ), (string) $cfg['pattern'] );

        $contacts = [];
        foreach ( $plan['by_id'] as $tagId => $uses ) {
            foreach ( \FluentCrm\App\Models\Subscriber::filterByTags( [ (int) $tagId ] )->get() as $sub ) {
                $id = (int) $sub->id;
                if ( ! isset( $contacts[ $id ] ) ) {
                    $contacts[ $id ] = self::contact_from_model( $sub );
                }
                foreach ( $uses as $use ) {
                    $contacts[ $id ] = self::apply_tag( $contacts[ $id ], $use['key'], $use['value'] );
                }
            }
        }

        $companiesActive = MyNJILGA_Members_Data::companies_module_active();
        $firms           = [];
        if ( $companiesActive ) {
            foreach ( \FluentCrm\App\Models\Company::with( [ 'subscribers', 'owner' ] )->get() as $company ) {
                $ids = [];
                foreach ( self::subscribers_of( $company ) as $sub ) {
                    $id    = (int) $sub->id;
                    $ids[] = $id;
                    if ( ! isset( $contacts[ $id ] ) ) {
                        $contacts[ $id ] = self::contact_from_model( $sub );
                    }
                }
                $owner   = $company->owner ?? null;
                $firms[] = [
                    'id'          => (int) $company->id,
                    'name'        => (string) ( $company->name ?? '' ),
                    'owner_id'    => (int) ( $company->owner_id ?? 0 ),
                    'owner_name'  => $owner ? MyNJILGA_Members_Data::display_name( $owner ) : '',
                    'contact_ids' => array_values( array_unique( $ids ) ),
                ];
            }
        }

        return [
            'cfg'      => $cfg,
            'warnings' => self::build_warnings( [
                'unresolved'       => $plan['unresolved'],
                'year'             => $cfg['year'],
                'pattern'          => $cfg['pattern'],
                'year_ids'         => $plan['year_ids'],
                'companies_active' => $companiesActive,
                'paid_tag'         => $cfg['paid_tag'],
            ] ),
            'contacts' => $contacts,
            'firms'    => $firms,
        ];
    }

    /**
     * The settings the figures depend on, read the way
     * MyNJILGA_My_Membership::summarize() reads them.
     *
     * @return array<string,mixed>
     */
    private static function config(): array {
        $engine  = MyNJILGA_Dues_Settings::engine_config();
        $pattern = (string) MyNJILGA_Dues_Settings::general( 'year_paid_tag_pattern', '' );
        if ( $pattern === '' ) {
            $pattern = (string) MyNJILGA_Dues_Settings::defaults()['general']['year_paid_tag_pattern'];
        }
        return [
            'year'             => MyNJILGA_Invoicing::current_dues_year(),
            'categories'       => (array) $engine['categories'],
            'default_category' => (string) $engine['default_category'],
            'pattern'          => $pattern,
            'paid_tag'         => (string) MyNJILGA_Dues_Settings::general( 'paid_tag', MyNJILGA_Tags::SLUG_DUES_PAID ),
            'unpaid_tag'       => (string) MyNJILGA_Dues_Settings::general( 'unpaid_tag', MyNJILGA_Tags::SLUG_UNPAID_DUES ),
            'inactive_tag'     => (string) MyNJILGA_Dues_Settings::general( 'inactive_tag', MyNJILGA_Tags::SLUG_INACTIVE ),
        ];
    }

    /**
     * Every tag the figures read, as plan_tags() specs. A settings slug is
     * resolved the way MyNJILGA_Tags::resolve_slug() does (sanitised; the
     * title fallback is the slug's natural title), a fixed NJILGA tag by
     * its DEFINITIONS entry. Only tags the settings configure, plus the
     * trustee family, are worth a "not found" warning: the officer and
     * payment-method tags are optional and a missing one just counts zero.
     *
     * @param array<string,mixed> $cfg
     * @return array<int,array<string,mixed>>
     */
    private static function tag_specs( array $cfg ): array {
        $specs = [];
        $add   = static function ( string $key, string $slug, $value, string $label, bool $warn ) use ( &$specs ) {
            $clean = sanitize_title( $slug );
            if ( $clean === '' ) {
                return;
            }
            $specs[] = [
                'key'   => $key,
                'slug'  => $clean,
                'title' => isset( MyNJILGA_Tags::DEFINITIONS[ $clean ] ) ? MyNJILGA_Tags::DEFINITIONS[ $clean ]['title'] : MyNJILGA_Tags::title_for_slug( $clean ),
                'value' => $value,
                'label' => $label,
                'warn'  => $warn,
            ];
        };

        $add( 'paid', (string) $cfg['paid_tag'], true, 'paid tag (Dues & Billing settings)', true );
        $add( 'unpaid', (string) $cfg['unpaid_tag'], true, 'unpaid tag (Dues & Billing settings)', true );
        $add( 'inactive', (string) $cfg['inactive_tag'], true, 'inactive tag (Dues & Billing settings)', true );
        foreach ( (array) $cfg['categories'] as $cat ) {
            $tag = (string) ( $cat['tag'] ?? '' );
            $add( 'category', $tag, $tag, sprintf( 'tag for the "%s" category', (string) ( $cat['label'] ?? $cat['key'] ?? '' ) ), true );
        }
        foreach ( MyNJILGA_Tags::TRUSTEE_SLUGS as $slug ) {
            $add( 'role', $slug, $slug, 'trustee tag', true );
        }
        foreach ( [ MyNJILGA_Tags::SLUG_OFFICER, MyNJILGA_Tags::SLUG_PAID_BY_CHECK, MyNJILGA_Tags::SLUG_PAID_BY_INVOICE ] as $slug ) {
            $add( 'role', $slug, $slug, '', false );
        }
        return $specs;
    }

    /**
     * A contact's identity and display data from the FluentCRM model the
     * query returned. $sub->company_id is kept only to put the primary firm
     * first when listing a contact's firms; membership of a firm comes from
     * the roster alone.
     *
     * @param object $sub A FluentCRM Subscriber.
     * @return array<string,mixed>
     */
    private static function contact_from_model( $sub ): array {
        return [
            'id'              => (int) $sub->id,
            'status'          => (string) ( $sub->status ?? '' ),
            'first_name'      => (string) ( $sub->first_name ?? '' ),
            'last_name'       => (string) ( $sub->last_name ?? '' ),
            'email'           => (string) ( $sub->email ?? '' ),
            'name'            => MyNJILGA_Members_Data::display_name( $sub ),
            'primary_firm_id' => (int) ( $sub->company_id ?? 0 ),
            'model'           => $sub,
        ] + self::blank_contact( (int) $sub->id );
    }

    /**
     * @param object $company A FluentCRM Company with 'subscribers' loaded.
     * @return array<int,object>
     */
    private static function subscribers_of( $company ): array {
        $subs = $company->subscribers ?? [];
        if ( is_array( $subs ) ) {
            return array_values( $subs );
        }
        if ( is_object( $subs ) && method_exists( $subs, 'all' ) ) {
            return $subs->all();
        }
        return iterator_to_array( $subs );
    }
}
