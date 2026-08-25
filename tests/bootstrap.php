<?php
/**
 * Test bootstrap — loads the plugin's PURE classes (no WordPress
 * dependency) and a minimal assertion base class.
 *
 * MyNJILGA_Dues_Settings::defaults() is pure and is loaded for its seed
 * data; its other methods call WordPress functions and are not exercised
 * here. MyNJILGA_Pricing_Engine has no external dependencies at all.
 */
declare( strict_types=1 );

require_once dirname( __DIR__ ) . '/includes/invoicing/class-dues-settings.php';
require_once dirname( __DIR__ ) . '/includes/invoicing/class-pricing-engine.php';

class NJILGA_Assertion_Failed extends Exception {}

abstract class NJILGA_TestCase {

    protected function assertSame( $expected, $actual, string $message = '' ): void {
        if ( $expected !== $actual ) {
            throw new NJILGA_Assertion_Failed( sprintf(
                "%sExpected %s, got %s",
                $message !== '' ? $message . ' — ' : '',
                $this->export( $expected ),
                $this->export( $actual )
            ) );
        }
    }

    protected function assertTrue( $actual, string $message = '' ): void {
        $this->assertSame( true, $actual, $message !== '' ? $message : 'Expected true' );
    }

    protected function assertFalse( $actual, string $message = '' ): void {
        $this->assertSame( false, $actual, $message !== '' ? $message : 'Expected false' );
    }

    protected function assertCount( int $expected, $actual, string $message = '' ): void {
        $this->assertSame( $expected, count( $actual ), $message !== '' ? $message : 'Count mismatch' );
    }

    protected function fail( string $message ): void {
        throw new NJILGA_Assertion_Failed( $message );
    }

    private function export( $value ): string {
        $s = var_export( $value, true );
        $s = preg_replace( '/\s+/', ' ', $s );
        return strlen( $s ) > 300 ? substr( $s, 0, 297 ) . '...' : $s;
    }
}
