<?php
/**
 * Shared, human-readable descriptions of who a firm's invoice covers.
 *
 * Both the FluentCart line items and the Owner's email are built from
 * this, so the invoice and the email can never describe the same roster
 * two different ways.
 *
 * The reason this exists: paying a firm invoice settles EVERY member in
 * the frozen snapshot (see MyNJILGA_Payment_Listener — it tags every
 * roster entry, not just the ones with a price on them). So a member who
 * owes $0 is genuinely covered by the firm's payment, and leaving them
 * off the paperwork entirely is how a firm ends up asking whether their
 * 6th attorney is a member. Everyone on the roster appears; the ones at
 * $0 carry the reason they're at $0.
 */
class MyNJILGA_Dues_Roster {

    /**
     * Why this member's dues are $0. Only meaningful when their tier
     * price is 0 — mirrors the precedence in MyNJILGA_Dues_Preview:
     * Inactive is a blanket override, then dues-exempt (Senior Trustee /
     * Past President), and anything else at $0 got there by being 6th or
     * later in the firm's billing order.
     *
     * @param array<string,mixed> $member One entry from roster_snapshot.members
     */
    public static function no_charge_reason( array $member ): string {
        if ( ! empty( $member['inactive'] ) ) {
            return 'inactive';
        }
        if ( ! empty( $member['dues_exempt'] ) ) {
            return 'dues exempt';
        }
        return '6th or later member';
    }

    /**
     * Invoice line title for a member's dues — including the $0 ones.
     */
    public static function dues_line_label( array $member, int $duesYear ): string {
        $name = (string) ( $member['name'] ?? '' );

        if ( (int) ( $member['tier_price_cents'] ?? 0 ) > 0 ) {
            return sprintf( '%s — %d Membership Dues', $name, $duesYear );
        }

        return sprintf(
            '%s — %d Membership Dues (no charge, %s)',
            $name,
            $duesYear,
            self::no_charge_reason( $member )
        );
    }

    /**
     * Invoice line title for a member's Trustee Dinner Fee. Only ever
     * used when the fee is actually owed, so it carries no $0 variant.
     */
    public static function fee_line_label( array $member ): string {
        return sprintf( '%s — Trustee Dinner Fee', (string) ( $member['name'] ?? '' ) );
    }

    /**
     * Sum of everything actually charged across the roster. Used as the
     * "is there anything to invoice?" guard — see
     * MyNJILGA_Invoice_Creator::create_for_row().
     *
     * @param array<int,array<string,mixed>> $members
     */
    public static function total_cents( array $members ): int {
        $total = 0;
        foreach ( $members as $member ) {
            $total += (int) ( $member['tier_price_cents'] ?? 0 );
            $total += (int) ( $member['trustee_fee_cents'] ?? 0 );
        }
        return $total;
    }

    /**
     * One line per person for the Owner's email — every member the
     * invoice covers, what they're charged, and why anyone at $0 is at
     * $0. Grouped per person rather than per fee, so an attorney who owes
     * both dues and the dinner fee reads as one entry rather than two
     * disconnected lines.
     *
     * @param array<int,array<string,mixed>> $members
     */
    public static function email_summary( array $members, int $duesYear ): string {
        if ( empty( $members ) ) {
            return '';
        }

        $lines = [];
        foreach ( $members as $member ) {
            $tierCents = (int) ( $member['tier_price_cents'] ?? 0 );
            $feeCents  = (int) ( $member['trustee_fee_cents'] ?? 0 );

            $parts = [ $tierCents > 0
                ? self::money( $tierCents ) . ' membership dues'
                : 'no charge (' . self::no_charge_reason( $member ) . ')' ];

            if ( $feeCents > 0 ) {
                $parts[] = self::money( $feeCents ) . ' Trustee Dinner Fee';
            }

            $lines[] = sprintf(
                '  - %s — %s',
                (string) ( $member['name'] ?? '' ),
                implode( ' + ', $parts )
            );
        }

        $count = count( $members );

        return sprintf(
            "This invoice covers %d %s:\n\n%s\n\n  Total: %s\n\nPaying this invoice marks everyone listed above as current for %d.",
            $count,
            $count === 1 ? 'person' : 'people',
            implode( "\n", $lines ),
            self::money( self::total_cents( $members ) ),
            $duesYear
        );
    }

    public static function money( int $cents ): string {
        return '$' . number_format( $cents / 100, 2 );
    }
}
