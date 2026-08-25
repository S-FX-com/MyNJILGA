<?php
/**
 * Shared, human-readable descriptions of who an invoice covers.
 *
 * Both the commerce line items and the bill-to email are built from this,
 * so the invoice and the email can never describe the same roster two
 * different ways.
 *
 * Paying an invoice settles EVERY member in its frozen snapshot (see
 * MyNJILGA_Payment_Listener), so a member at $0 is genuinely covered by
 * the payment and appears on the paperwork with the reason they're at $0.
 */
class MyNJILGA_Dues_Roster {

    /**
     * Invoice line title for a member's dues — including the $0 ones.
     *
     *   "Ann Brown — 2027 Professional Membership (1st Member)"
     *   "Sam Lee — 2027 Professional Membership (no charge, Members 6+)"
     *   "Pat Roe — 2027 Past President Membership (Exempt)"
     *   "Chris Poe — 2027 Membership Dues (no charge, inactive)"
     *
     * @param array<string,mixed> $member One snapshot member.
     */
    public static function dues_line_label( array $member, int $duesYear ): string {
        $name     = (string) ( $member['name'] ?? '' );
        $category = (string) ( $member['category_label'] ?? '' );
        if ( $category === '' ) {
            $category = 'Membership Dues';
        }
        $tier = (string) ( $member['tier_label'] ?? '' );
        $cents = (int) ( $member['dues_cents'] ?? 0 );

        if ( $cents > 0 ) {
            return sprintf( '%s — %d %s%s', $name, $duesYear, $category, $tier !== '' ? " ($tier)" : '' );
        }

        $reason = self::no_charge_reason( $member );
        // Don't say "(no charge, Past President Membership (Exempt))" when
        // the category label already says exempt/comped.
        if ( $reason !== '' && strcasecmp( $reason, $category ) !== 0 ) {
            return sprintf( '%s — %d %s (no charge, %s)', $name, $duesYear, $category, $reason );
        }
        return sprintf( '%s — %d %s', $name, $duesYear, $category );
    }

    /**
     * Invoice line title for a member's assessment. Only used when owed.
     */
    public static function assessment_line_label( array $member ): string {
        $label     = (string) ( $member['assessment_label'] ?? 'Assessment' );
        $qualifier = (string) ( $member['assessment_qualifier'] ?? '' );
        return sprintf(
            '%s — %s%s',
            (string) ( $member['name'] ?? '' ),
            $label,
            $qualifier !== '' ? " ($qualifier)" : ''
        );
    }

    /**
     * Why this member's dues are $0 (only meaningful when they are).
     */
    public static function no_charge_reason( array $member ): string {
        if ( ! empty( $member['unbilled_reason'] ) ) {
            return (string) $member['unbilled_reason'];
        }
        if ( ! empty( $member['dues_note'] ) ) {
            return (string) $member['dues_note'];
        }
        if ( ! empty( $member['tier_label'] ) ) {
            return (string) $member['tier_label'];
        }
        return (string) ( $member['category_label'] ?? 'no charge' );
    }

    /**
     * Sum of everything actually charged across the roster.
     *
     * @param array<int,array<string,mixed>> $members
     */
    public static function total_cents( array $members ): int {
        return MyNJILGA_Pricing_Engine::totals( $members )['total_cents'];
    }

    /**
     * Gateway-agnostic line items for an invoice (see
     * MyNJILGA_Invoice_Gateway for the shape): one dues line per member
     * (including the $0 ones — the payment covers them), plus an
     * assessment line only where one is owed.
     *
     * @param array<int,array<string,mixed>> $members
     * @return array<int,array<string,mixed>>
     */
    public static function line_items( array $members, int $duesYear, string $invoiceKind ): array {
        $items = [];
        foreach ( $members as $m ) {
            $contactId = (int) ( $m['contact_id'] ?? 0 );

            if ( $invoiceKind !== MyNJILGA_Dues_Snapshot::KIND_ASSESSMENT ) {
                $items[] = [
                    'title'            => self::dues_line_label( $m, $duesYear ),
                    'unit_price_cents' => (int) ( $m['dues_cents'] ?? 0 ),
                    'quantity'         => 1,
                    'product_id'       => (int) ( $m['dues_product_id'] ?? 0 ),
                    'variation_id'     => (int) ( $m['dues_variation_id'] ?? 0 ),
                    'line_meta'        => [
                        'contact_id' => $contactId,
                        'dues_year'  => $duesYear,
                        'kind'       => 'dues',
                        'category'   => (string) ( $m['category_key'] ?? '' ),
                        'tier'       => (string) ( $m['tier_key'] ?? '' ),
                        'rank'       => (int) ( $m['rank'] ?? 0 ),
                    ],
                ];
            }

            if ( (int) ( $m['assessment_cents'] ?? 0 ) > 0 ) {
                $items[] = [
                    'title'            => self::assessment_line_label( $m ),
                    'unit_price_cents' => (int) $m['assessment_cents'],
                    'quantity'         => 1,
                    'product_id'       => (int) ( $m['assessment_product_id'] ?? 0 ),
                    'variation_id'     => (int) ( $m['assessment_variation_id'] ?? 0 ),
                    'line_meta'        => [
                        'contact_id' => $contactId,
                        'dues_year'  => $duesYear,
                        'kind'       => 'assessment',
                        'qualifier'  => (string) ( $m['assessment_qualifier'] ?? '' ),
                    ],
                ];
            }
        }
        return $items;
    }

    /**
     * One line per person for the bill-to email — every member the
     * invoice covers, what they're charged, and why anyone at $0 is $0.
     *
     * @param array<int,array<string,mixed>> $members
     */
    public static function email_summary( array $members, int $duesYear, string $invoiceKind = MyNJILGA_Dues_Snapshot::KIND_COMBINED ): string {
        if ( empty( $members ) ) {
            return '';
        }

        $lines = [];
        foreach ( $members as $m ) {
            $dues = (int) ( $m['dues_cents'] ?? 0 );
            $fee  = (int) ( $m['assessment_cents'] ?? 0 );
            $parts = [];

            if ( $invoiceKind !== MyNJILGA_Dues_Snapshot::KIND_ASSESSMENT ) {
                $category = (string) ( $m['category_label'] ?? 'membership dues' );
                $parts[]  = $dues > 0
                    ? self::money( $dues ) . ' ' . $category . ( ! empty( $m['tier_label'] ) ? ' (' . $m['tier_label'] . ')' : '' )
                    : 'no charge (' . self::no_charge_reason( $m ) . ')';
            }
            if ( $fee > 0 ) {
                $parts[] = self::money( $fee ) . ' ' . (string) ( $m['assessment_label'] ?? 'assessment' )
                    . ( ! empty( $m['assessment_qualifier'] ) ? ' (' . $m['assessment_qualifier'] . ')' : '' );
            }

            $lines[] = sprintf( '  - %s — %s', (string) ( $m['name'] ?? '' ), implode( ' + ', $parts ) );
        }

        $count = count( $members );
        $closing = $invoiceKind === MyNJILGA_Dues_Snapshot::KIND_ASSESSMENT
            ? sprintf( 'Paying this invoice settles the %d assessment for everyone listed above.', $duesYear )
            : sprintf( 'Paying this invoice marks everyone listed above as current for %d.', $duesYear );

        return sprintf(
            "This invoice covers %d %s:\n\n%s\n\n  Total: %s\n\n%s",
            $count,
            $count === 1 ? 'person' : 'people',
            implode( "\n", $lines ),
            self::money( self::total_cents( $members ) ),
            $closing
        );
    }

    public static function money( int $cents ): string {
        return '$' . number_format( $cents / 100, 2 );
    }
}
