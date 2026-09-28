<?php
/**
 * Phone numbers written to FluentCRM: US numbers as +1 ###-###-####
 * however they were typed, other countries kept (tidied) as typed.
 */
require_once dirname( __DIR__ ) . '/includes/class-phone.php';

class PhoneTest extends NJILGA_TestCase {

    public function test_us_numbers_typed_any_way_come_out_the_same(): void {
        foreach ( [ '201-555-0100', '(201) 555-0100', '2015550100', '201.555.0100', ' 201 555 0100 ', '+1 201 555 0100', '1-201-555-0100', '+1 (201) 555-0100', '001 201 555 0100' ] as $typed ) {
            $this->assertSame( '201-555-0100', MyNJILGA_Phone::display( $typed ), $typed );
            $this->assertSame( '+1 201-555-0100', MyNJILGA_Phone::for_crm( $typed ), $typed );
        }
    }

    public function test_crm_shape_is_idempotent(): void {
        $once = MyNJILGA_Phone::for_crm( '(973) 555 0199' );
        $this->assertSame( '+1 973-555-0199', $once );
        $this->assertSame( $once, MyNJILGA_Phone::for_crm( $once ) );
        $this->assertSame( '973-555-0199', MyNJILGA_Phone::display( $once ) );
    }

    public function test_impossible_us_numbers_are_not_valid(): void {
        foreach ( [ '555-0100', '123-456-7890', '201-155-0100', '20155501000', '2015550100 ext 12', 'call me', '' ] as $bad ) {
            $this->assertSame( '', MyNJILGA_Phone::us_digits( $bad ), $bad );
            $this->assertFalse( MyNJILGA_Phone::valid( $bad ), $bad );
        }
    }

    public function test_other_countries_are_kept_tidied(): void {
        $this->assertTrue( MyNJILGA_Phone::is_international( '+44 20 7946 0000' ) );
        $this->assertSame( '+44 20 7946 0000', MyNJILGA_Phone::for_crm( '+44  20 7946 0000' ) );
        $this->assertSame( '+44 20 7946 0000', MyNJILGA_Phone::for_crm( '0044 20 7946 0000' ) );
        $this->assertSame( '+49 (30) 1234-5678', MyNJILGA_Phone::display( '+49 (30) 1234-5678' ) );
        $this->assertTrue( MyNJILGA_Phone::valid( '+33 1 23 45 67 89' ) );
        $this->assertFalse( MyNJILGA_Phone::valid( '+44 123' ), 'Too short to be a real number.' );
        $this->assertFalse( MyNJILGA_Phone::is_international( '+1 201 555 0100' ), '+1 is the US.' );
    }

    public function test_same_line_however_typed(): void {
        $this->assertTrue( MyNJILGA_Phone::same( '2015550100', '+1 (201) 555-0100' ) );
        $this->assertFalse( MyNJILGA_Phone::same( '2015550100', '2015550101' ) );
    }
}
