<?php
/**
 * Service locator for the invoicing layer — hands out the one
 * MyNJILGA_Invoice_Gateway the rest of the plugin talks to. Swappable via
 * the `my_njilga_invoice_gateway` filter (a test double, a different
 * commerce plugin), which is the whole point of the gateway seam.
 */
class MyNJILGA_Invoicing {

    /** @var MyNJILGA_Invoice_Gateway|null */
    private static $gateway = null;

    public static function gateway(): MyNJILGA_Invoice_Gateway {
        if ( self::$gateway === null ) {
            $gateway = new MyNJILGA_Stripe_Invoice_Gateway();
            /**
             * Replace the invoice gateway.
             *
             * @param MyNJILGA_Invoice_Gateway $gateway
             */
            $filtered = apply_filters( 'my_njilga_invoice_gateway', $gateway );
            self::$gateway = $filtered instanceof MyNJILGA_Invoice_Gateway ? $filtered : $gateway;
        }
        return self::$gateway;
    }

    /**
     * Money formatting used across admin pages and the front-end status
     * page.
     */
    public static function money( int $cents ): string {
        return '$' . number_format( $cents / 100, 2 );
    }

    /**
     * Default dues year shown in the admin: next calendar year (batches
     * are generated ahead of the year they cover).
     */
    public static function default_dues_year(): int {
        return (int) gmdate( 'Y' ) + 1;
    }

    /**
     * The dues year a mid-year join falls into: the current calendar year.
     */
    public static function current_dues_year(): int {
        return (int) gmdate( 'Y' );
    }

    /**
     * When a dues invoice falls due: December 31 of the calendar year the
     * invoice is RAISED IN, not the dues year it covers.
     *
     * Batches are generated ahead of the year they cover
     * (default_dues_year() is next year), so a 2027 invoice raised in
     * September 2026 is due 31 December 2026 — pay before the membership
     * year starts, which is what makes the January downgrade sweep mean
     * anything. The same rule reads correctly for a mid-year join, where
     * the invoice is raised inside the year it covers and falls due at
     * the end of it.
     *
     * $minimumDays is a floor, not a target: an invoice raised in late
     * December would otherwise be due in days, so it falls back to that
     * many days out (the "Days until due" setting) rather than issuing an
     * invoice nobody could reasonably pay in time.
     *
     * @param int      $minimumDays Smallest acceptable window, in days.
     * @param int|null $now         Unix timestamp; defaults to now (injectable for tests).
     */
    public static function year_end_due_timestamp( int $minimumDays = 30, ?int $now = null ): int {
        $now  = $now ?? time();
        $year = (int) gmdate( 'Y', $now );

        // Noon UTC, deliberately: Stripe renders the due date from this
        // timestamp, and midnight either side of it prints as January 1
        // for somebody. Noon is December 31 everywhere from UTC-11 to
        // UTC+11.
        $yearEnd = gmmktime( 12, 0, 0, 12, 31, $year );
        $floor   = $now + ( max( 1, $minimumDays ) * 86400 );

        return $yearEnd >= $floor ? $yearEnd : $floor;
    }
}
