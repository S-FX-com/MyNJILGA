<?php
/**
 * The `roster_snapshot` JSON column (spec §5.3) — one canonical shape,
 * one place that reads and writes it.
 *
 * Shape (version 2):
 *
 *   {
 *     "version": 2,
 *     "dues_year": 2027,
 *     "billing_mode": "firm" | "individual" | "split_assessment",
 *     "invoice_kind": "combined" | "dues" | "assessment",
 *     "company": { "id": 12, "name": "Smith & Jones LLP" },
 *     "owner":   { "contact_id": 5, "name": "...", "first_name": "...", "last_name": "...", "email": "..." },
 *     "bill_to": { "contact_id": 5, "name": "...", "first_name": "...", "last_name": "...", "email": "..." },
 *     "members": [
 *       {
 *         "contact_id": 5, "name": "Ann Brown", "first_name": "Ann", "last_name": "Brown", "email": "ann@...",
 *         "tags": ["professional","officer"],
 *         "inactive": false,
 *         "category_key": "professional", "category_label": "Professional Membership",
 *         "tier_eligible": true, "role": "professional",
 *         "rank": 1, "tier_key": "first", "tier_label": "1st Member",
 *         "dues_cents": 12500, "dues_note": "",
 *         "assessment_cents": 20000, "assessment_label": "Trustee Dinner Assessment",
 *         "assessment_qualifier": "Officer",
 *         "unbilled_reason": ""            // "" | "inactive" | "no category tag"
 *       }, ...
 *     ],
 *     "totals": { "dues_cents": 0, "assessment_cents": 0, "total_cents": 0, "billed_members": 0, "unbilled_members": 0 }
 *   }
 *
 * Member entries are exactly what MyNJILGA_Pricing_Engine::price() emits
 * (plus, for split-assessment invoices, dues zeroed on the assessment
 * row and assessment zeroed on the dues row — see MyNJILGA_Dues_Preview).
 *
 * Version 1 rows (written before the settings-driven engine existed:
 * top-level company_name/owner_*, members carrying tier_price_cents /
 * trustee_fee_cents / dues_exempt) are upgraded on read by decode(), so
 * nothing downstream ever sees two shapes.
 */
class MyNJILGA_Dues_Snapshot {

    const VERSION = 2;

    const KIND_COMBINED   = 'combined';   // dues + assessment on one invoice (firm / individual mode)
    const KIND_DUES       = 'dues';       // dues only (split_assessment: the firm invoice)
    const KIND_ASSESSMENT = 'assessment'; // assessment only (split_assessment: one per assessed member)

    /**
     * Build a v2 snapshot array.
     *
     * @param array<string,mixed>            $company  [id, name]
     * @param array<string,mixed>            $owner    [contact_id, name, first_name, last_name, email]
     * @param array<string,mixed>            $billTo   same shape as $owner
     * @param array<int,array<string,mixed>> $members  priced members
     * @return array<string,mixed>
     */
    public static function build( int $duesYear, string $billingMode, string $invoiceKind, array $company, array $owner, array $billTo, array $members ): array {
        return [
            'version'      => self::VERSION,
            'dues_year'    => $duesYear,
            'billing_mode' => $billingMode,
            'invoice_kind' => $invoiceKind,
            'company'      => [ 'id' => (int) ( $company['id'] ?? 0 ), 'name' => (string) ( $company['name'] ?? '' ) ],
            'owner'        => self::person( $owner ),
            'bill_to'      => self::person( $billTo ),
            'members'      => array_values( $members ),
            'totals'       => MyNJILGA_Pricing_Engine::totals( $members ),
        ];
    }

    public static function encode( array $snapshot ): string {
        return (string) wp_json_encode( $snapshot );
    }

    /**
     * Decode a row's roster_snapshot into the v2 shape, upgrading v1 on
     * the fly. Accepts the DB row object or the raw JSON string.
     *
     * @param object|string $rowOrJson
     * @return array<string,mixed>
     */
    public static function decode( $rowOrJson ): array {
        $json = is_object( $rowOrJson ) ? (string) ( $rowOrJson->roster_snapshot ?? '' ) : (string) $rowOrJson;
        $data = json_decode( $json, true );
        if ( ! is_array( $data ) ) {
            $data = [];
        }

        if ( (int) ( $data['version'] ?? 1 ) >= 2 ) {
            $data['members'] = array_values( (array) ( $data['members'] ?? [] ) );
            $data['totals']  = $data['totals'] ?? MyNJILGA_Pricing_Engine::totals( $data['members'] );
            return $data;
        }

        return self::upgrade_v1( $data, is_object( $rowOrJson ) ? $rowOrJson : null );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function members( $rowOrJson ): array {
        return self::decode( $rowOrJson )['members'];
    }

    public static function company_name( $rowOrJson ): string {
        $s = self::decode( $rowOrJson );
        $name = (string) ( $s['company']['name'] ?? '' );
        if ( $name === '' && is_object( $rowOrJson ) ) {
            $name = 'Company #' . (int) ( $rowOrJson->fluentcrm_company_id ?? 0 );
        }
        return $name;
    }

    /**
     * @return array{contact_id:int,name:string,first_name:string,last_name:string,email:string}
     */
    public static function bill_to( $rowOrJson ): array {
        $s = self::decode( $rowOrJson );
        return self::person( $s['bill_to'] ?? $s['owner'] ?? [] );
    }

    /**
     * @return array{contact_id:int,name:string,first_name:string,last_name:string,email:string}
     */
    public static function owner( $rowOrJson ): array {
        return self::person( self::decode( $rowOrJson )['owner'] ?? [] );
    }

    public static function invoice_kind( $rowOrJson ): string {
        return (string) ( self::decode( $rowOrJson )['invoice_kind'] ?? self::KIND_COMBINED );
    }

    /**
     * Whether paying this invoice settles membership (dues) — false only
     * for assessment-only invoices, which must not tag anyone Dues Paid.
     */
    public static function settles_dues( $rowOrJson ): bool {
        return self::invoice_kind( $rowOrJson ) !== self::KIND_ASSESSMENT;
    }

    /**
     * @param array<string,mixed> $p
     * @return array{contact_id:int,name:string,first_name:string,last_name:string,email:string}
     */
    public static function person( array $p ): array {
        $first = (string) ( $p['first_name'] ?? '' );
        $last  = (string) ( $p['last_name'] ?? '' );
        $name  = (string) ( $p['name'] ?? '' );
        if ( $name === '' ) {
            $name = trim( $first . ' ' . $last );
        }
        if ( $name === '' ) {
            $name = (string) ( $p['email'] ?? '' );
        }
        return [
            'contact_id' => (int) ( $p['contact_id'] ?? 0 ),
            'name'       => $name,
            'first_name' => $first,
            'last_name'  => $last,
            'email'      => (string) ( $p['email'] ?? '' ),
        ];
    }

    // -------------------------------------------------------------------------
    // v1 → v2
    // -------------------------------------------------------------------------

    /**
     * @param array<string,mixed> $v1
     * @param object|null         $row
     * @return array<string,mixed>
     */
    private static function upgrade_v1( array $v1, $row ): array {
        $members = [];
        foreach ( (array) ( $v1['members'] ?? [] ) as $m ) {
            $inactive = ! empty( $m['inactive'] );
            $exempt   = ! empty( $m['dues_exempt'] );
            $dues     = (int) ( $m['tier_price_cents'] ?? 0 );
            $fee      = (int) ( $m['trustee_fee_cents'] ?? 0 );

            $members[] = [
                'contact_id'              => (int) ( $m['contact_id'] ?? 0 ),
                'name'                    => (string) ( $m['name'] ?? '' ),
                'first_name'              => '',
                'last_name'               => '',
                'email'                   => '',
                'tags'                    => [],
                'inactive'                => $inactive,
                'category_key'            => $exempt ? 'exempt' : 'professional',
                'category_label'          => $exempt ? 'Exempt (Senior Trustee / Past President)' : 'Professional Membership',
                'tier_eligible'           => ! $exempt,
                'role'                    => 'professional',
                'rank'                    => 0,
                'tier_key'                => '',
                'tier_label'              => $dues === 12500 ? '1st Member' : ( $dues === 7500 ? 'Members 2–5' : ( ( ! $exempt && ! $inactive ) ? 'Members 6+' : '' ) ),
                'dues_cents'              => $dues,
                'dues_note'               => $dues > 0 ? '' : ( $inactive ? 'inactive' : ( $exempt ? 'dues exempt' : '6th or later member' ) ),
                'assessment_cents'        => $fee,
                'assessment_label'        => $fee > 0 ? 'Trustee Dinner Fee' : '',
                'assessment_qualifier'    => '',
                'unbilled_reason'         => $inactive ? 'inactive' : '',
            ];
        }

        $owner = [
            'contact_id' => (int) ( $row->fluentcrm_owner_contact_id ?? 0 ),
            'name'       => (string) ( $v1['owner_name'] ?? '' ),
            'first_name' => (string) ( $v1['owner_first_name'] ?? '' ),
            'last_name'  => (string) ( $v1['owner_last_name'] ?? '' ),
            'email'      => (string) ( $v1['owner_email'] ?? '' ),
        ];

        return [
            'version'      => self::VERSION,
            'dues_year'    => (int) ( $row->dues_year ?? 0 ),
            'billing_mode' => 'firm',
            'invoice_kind' => self::KIND_COMBINED,
            'company'      => [
                'id'   => (int) ( $row->fluentcrm_company_id ?? 0 ),
                'name' => (string) ( $v1['company_name'] ?? '' ),
            ],
            'owner'        => self::person( $owner ),
            'bill_to'      => self::person( $owner ),
            'members'      => $members,
            'totals'       => MyNJILGA_Pricing_Engine::totals( $members ),
        ];
    }
}
