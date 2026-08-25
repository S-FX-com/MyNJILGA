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
            $gateway = new MyNJILGA_FluentCart_Invoice_Gateway();
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
}
