<?php
/**
 * The username a new account is offered (first initial + last name,
 * letters and digits only, the first free variant) and the New Jersey
 * ZIP rule for Professional joins.
 */
require_once dirname( __DIR__ ) . '/includes/join/class-join-form.php';

class JoinUsernameTest extends NJILGA_TestCase {

    public function test_first_initial_and_last_name_letters_and_digits_only(): void {
        $this->assertSame( 'azulu', MyNJILGA_Join_Form::username_base( 'Ann', 'Zulu' ) );
        $this->assertSame( 'aoneilzulu', MyNJILGA_Join_Form::username_base( 'Ann', "O'Neil-Zulu" ) );
        $this->assertSame( 'mvandenberg', MyNJILGA_Join_Form::username_base( ' Mary Kate ', 'van den Berg' ) );
        $this->assertSame( 'jsmith3rd', MyNJILGA_Join_Form::username_base( 'J.', 'Smith 3rd' ), 'Digits are kept.' );
    }

    public function test_short_names_still_give_a_valid_username(): void {
        $this->assertSame( 'jli', MyNJILGA_Join_Form::username_base( 'Jo', 'Li' ) );
        $this->assertSame( 'abmember', MyNJILGA_Join_Form::username_base( 'A', 'B' ) );
        $this->assertSame( 'member', MyNJILGA_Join_Form::username_base( '', '' ) );
        $this->assertSame( 50, strlen( MyNJILGA_Join_Form::username_base( 'A', str_repeat( 'x', 80 ) ) ) );
    }

    public function test_next_free_variant(): void {
        $taken = static function ( string $u ): bool { return in_array( $u, [ 'azulu', 'azulu2' ], true ); };
        $this->assertSame( 'azulu3', MyNJILGA_Join_Form::next_free_username( 'azulu', $taken ) );
        $this->assertSame( 'bzulu', MyNJILGA_Join_Form::next_free_username( 'bzulu', $taken ) );
    }

    public function test_new_jersey_zip_codes_only(): void {
        foreach ( [ '07030', '08608', '07102-1234', ' 08901 ' ] as $zip ) {
            $this->assertTrue( MyNJILGA_Join_Form::is_nj_zip( $zip ), $zip );
        }
        foreach ( [ '10001', '19103', '0703', '070300', '06101', '09001', 'SW1A 2AA', '' ] as $zip ) {
            $this->assertFalse( MyNJILGA_Join_Form::is_nj_zip( $zip ), $zip );
        }
    }
}
