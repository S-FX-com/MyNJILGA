<?php
/**
 * Step 1 — computes the dues roster/pricing for every FluentCRM Company,
 * and persists it as a frozen draft snapshot in njilga_dues_invoices.
 *
 * Pricing tiers are computed fresh each cycle by headcount — no
 * persistent "this person is member #3" designation anywhere. For a
 * firm's paying (non-exempt) contacts: 1st (alphabetical by last name,
 * then first) = $125, 2nd-5th = $75 each, 6th+ = free. Trustee/Past
 * President assessment = $200 flat, additive, capped at one per person.
 *
 * Senior Trustees and Past Presidents are dues-exempt (confirmed with
 * NJILGA): they owe $0 base membership dues but still owe the $200
 * assessment when they qualify for it. They still count toward the
 * firm's roster — they're sorted to the END of the billing order rather
 * than excluded outright, so they never occupy the (paid) 1st-member
 * slot, and every non-exempt member's own tier position is decided
 * purely among the other non-exempt members. See sorted_members().
 */
class MyNJILGA_Dues_Preview {

    const TIER_FIRST_CENTS  = 12500;
    const TIER_2_TO_5_CENTS = 7500;
    const TIER_6PLUS_CENTS  = 0;
    const TRUSTEE_FEE_CENTS = 20000;

    /**
     * Computes the roster/pricing for every Company, without touching the
     * database — pure read + math.
     *
     * @return array<int,array{
     *   company_id:int, company_name:string, status:string,
     *   owner_contact_id:int, owner_name:string, owner_email:string,
     *   roster:array<int,array{contact_id:int,name:string,tier_price_cents:int,trustee_fee_cents:int,dues_exempt:bool}>,
     *   total_cents:int
     * }>
     */
    public static function compute( int $duesYear ): array {
        if ( ! MyNJILGA_Members_Data::companies_module_active() ) {
            return [];
        }

        $companies = \FluentCrm\App\Models\Company::with( [ 'subscribers', 'owner' ] )->get();
        $preview   = [];

        foreach ( $companies as $company ) {
            $companyId   = (int) $company->id;
            $companyName = (string) ( $company->name ?? '' );

            if ( empty( $company->owner_id ) ) {
                $preview[] = [
                    'company_id'       => $companyId,
                    'company_name'     => $companyName,
                    'status'           => 'excluded_no_owner',
                    'owner_contact_id' => 0,
                    'owner_name'       => '',
                    'owner_first_name' => '',
                    'owner_last_name'  => '',
                    'owner_email'      => '',
                    'roster'           => [],
                    'total_cents'      => 0,
                ];
                continue;
            }

            $members = self::sorted_members( $company );
            $roster  = [];
            foreach ( $members as $i => $contact ) {
                $isExempt  = MyNJILGA_Tags::is_exempt( $contact );
                $roster[] = [
                    'contact_id'        => (int) $contact->id,
                    'name'              => MyNJILGA_Members_Data::display_name( $contact ),
                    'tier_price_cents'  => $isExempt ? 0 : self::tier_for_position( $i ),
                    'trustee_fee_cents' => self::contact_owes_trustee_fee( $contact ) ? self::TRUSTEE_FEE_CENTS : 0,
                    'dues_exempt'       => $isExempt,
                ];
            }

            $owner = $company->owner ?? null;

            $preview[] = [
                'company_id'        => $companyId,
                'company_name'      => $companyName,
                'status'            => 'draft',
                'owner_contact_id'  => (int) $company->owner_id,
                'owner_name'        => $owner ? MyNJILGA_Members_Data::display_name( $owner ) : '',
                'owner_first_name'  => $owner ? (string) ( $owner->first_name ?? '' ) : '',
                'owner_last_name'   => $owner ? (string) ( $owner->last_name ?? '' ) : '',
                'owner_email'       => $owner ? (string) ( $owner->email ?? '' ) : '',
                'roster'            => $roster,
                'total_cents'       => array_sum( array_map(
                    static function ( $m ) { return $m['tier_price_cents'] + $m['trustee_fee_cents']; },
                    $roster
                ) ),
            ];
        }

        usort( $preview, static function ( $a, $b ) { return strcasecmp( $a['company_name'], $b['company_name'] ); } );

        return $preview;
    }

    /**
     * compute() + persist every row into njilga_dues_invoices as a draft
     * (or 'excluded' for no-owner firms). Rows that have already moved
     * past draft/excluded are left untouched by
     * MyNJILGA_Dues_Invoice_Table::upsert_draft() — re-running the preview
     * can never clobber an already-billed roster.
     *
     * @return array<int,array<string,mixed>> Same shape as compute(), plus 'row_id' (null if blocked by an existing non-draft row).
     */
    public static function generate_and_persist( int $duesYear ): array {
        $preview = self::compute( $duesYear );

        foreach ( $preview as &$row ) {
            $status = $row['status'] === 'excluded_no_owner'
                ? MyNJILGA_Dues_Invoice_Table::STATUS_EXCLUDED
                : MyNJILGA_Dues_Invoice_Table::STATUS_DRAFT;

            // company_name/owner_name/owner_email are frozen into the
            // snapshot alongside members, for the same reason the roster
            // itself is frozen: if the Company gets renamed or the Owner's
            // email changes after this invoice was generated, the
            // dashboard and audit trail should keep showing what was
            // actually billed, not today's live FluentCRM data.
            $row['row_id'] = MyNJILGA_Dues_Invoice_Table::upsert_draft( [
                'dues_year'                  => $duesYear,
                'fluentcrm_company_id'       => $row['company_id'],
                'fluentcrm_owner_contact_id' => $row['owner_contact_id'],
                'status'                     => $status,
                'total_amount_cents'         => $row['total_cents'],
                'roster_snapshot'            => wp_json_encode( [
                    'company_name'     => $row['company_name'],
                    'owner_name'       => $row['owner_name'],
                    'owner_first_name' => $row['owner_first_name'],
                    'owner_last_name'  => $row['owner_last_name'],
                    'owner_email'      => $row['owner_email'],
                    'members'          => $row['roster'],
                ] ),
            ] );
        }
        unset( $row );

        return $preview;
    }

    private static function tier_for_position( int $i ): int {
        if ( $i === 0 ) {
            return self::TIER_FIRST_CENTS;
        }
        if ( $i >= 1 && $i <= 4 ) {
            return self::TIER_2_TO_5_CENTS;
        }
        return self::TIER_6PLUS_CENTS;
    }

    /**
     * Who owes the $200 Trustee/Past President assessment — confirmed
     * with NJILGA: anyone carrying any trustee-family tag (Trustees,
     * Senior Trustee, or Past President — i.e. MyNJILGA_Tags::is_trustee()).
     * This is additive on top of whatever base dues they owe (which is
     * $0 for the two exempt roles — see contact-level dues_exempt above).
     *
     * @param \FluentCrm\App\Models\Subscriber $contact
     */
    private static function contact_owes_trustee_fee( $contact ): bool {
        return MyNJILGA_Tags::is_trustee( $contact );
    }

    /**
     * Firm roster ordered for billing: non-exempt (paying) members first,
     * sorted alphabetically by last name then first name — same sort
     * used on the Membership by Firm report, and the deterministic rule
     * that decides which paying name gets the 1st-member vs. 2nd-5th
     * price. Dues-exempt members (Senior Trustee / Past President,
     * MyNJILGA_Tags::is_exempt()) are sorted among themselves and
     * appended at the end — confirmed with NJILGA: they still count
     * toward the roster, but must never land in the (paid) 1st-member
     * slot, and their presence must never bump a paying member into a
     * more expensive tier than they'd otherwise get.
     *
     * @param \FluentCrm\App\Models\Company $company
     * @return array<int,\FluentCrm\App\Models\Subscriber>
     */
    private static function sorted_members( $company ): array {
        $subs = $company->subscribers ?? [];
        if ( is_array( $subs ) ) {
            $subs = array_values( $subs );
        } elseif ( is_object( $subs ) && method_exists( $subs, 'all' ) ) {
            $subs = $subs->all();
        } else {
            $subs = iterator_to_array( $subs );
        }

        $byName = static function ( $a, $b ) {
            $cmp = strcasecmp( (string) ( $a->last_name ?? '' ), (string) ( $b->last_name ?? '' ) );
            return $cmp !== 0 ? $cmp : strcasecmp( (string) ( $a->first_name ?? '' ), (string) ( $b->first_name ?? '' ) );
        };

        $paying = array_values( array_filter( $subs, static function ( $c ) { return ! MyNJILGA_Tags::is_exempt( $c ); } ) );
        $exempt = array_values( array_filter( $subs, static function ( $c ) { return MyNJILGA_Tags::is_exempt( $c ); } ) );

        usort( $paying, $byName );
        usort( $exempt, $byName );

        return array_merge( $paying, $exempt );
    }
}
