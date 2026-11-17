<?php
/**
 * The rule behind the Invoicing page's "Not emailed yet" and "Email failed" filters: which invoices have
 * not been emailed, and which of those had an email attempt fail.
 */
declare( strict_types=1 );

class UnemailedFilterTest extends NJILGA_TestCase {

    private function row( string $status, string $error = '', string $kind = 'combined' ): array {
        return [ 'status' => $status, 'invoice_kind' => $kind, 'last_error' => $error === '' ? null : $error ];
    }

    public function testACreatedInvoiceIsNotEmailedYet(): void {
        $this->assertTrue( MyNJILGA_Dues_Invoice_Table::is_unemailed( $this->row( 'created' ) ) );
        $this->assertTrue( MyNJILGA_Dues_Invoice_Table::is_unemailed( (object) $this->row( 'created' ) ), 'an object reads the same' );
    }

    public function testAnythingElseIsNot(): void {
        foreach ( [ 'draft', 'approved', 'sent', 'processing', 'paid', 'voided', 'downgraded', 'excluded', 'uncollectible', '' ] as $status ) {
            $this->assertFalse( MyNJILGA_Dues_Invoice_Table::is_unemailed( $this->row( $status ) ), $status === '' ? 'no status' : $status );
        }
    }

    /** An online join was paid at Checkout; there is nothing to email, so it is never "not emailed". */
    public function testAnOnlineJoinNeverIs(): void {
        $this->assertFalse( MyNJILGA_Dues_Invoice_Table::is_unemailed( $this->row( 'created', '', 'join' ) ) );
    }

    public function testAFailedEmailIsAnUnemailedInvoiceCarryingTheError(): void {
        $this->assertTrue( MyNJILGA_Dues_Invoice_Table::email_failed( $this->row( 'created', 'wp_mail() failed — check the site\'s mail configuration.' ) ) );
        $this->assertFalse( MyNJILGA_Dues_Invoice_Table::email_failed( $this->row( 'created' ) ), 'created and never tried is not a failure' );
        $this->assertFalse( MyNJILGA_Dues_Invoice_Table::email_failed( $this->row( 'created', '   ' ) ), 'a blank error is none' );
    }

    /** A sent invoice with a leftover warning, or a draft that failed to CREATE, is not an email failure. */
    public function testAnErrorOnAnInvoiceThatIsNotUnemailedIsNotAnEmailFailure(): void {
        $this->assertFalse( MyNJILGA_Dues_Invoice_Table::email_failed( $this->row( 'sent', 'Sent, but delivery could not be confirmed' ) ) );
        $this->assertFalse( MyNJILGA_Dues_Invoice_Table::email_failed( $this->row( 'draft', 'Stripe said no' ) ) );
        $this->assertFalse( MyNJILGA_Dues_Invoice_Table::email_failed( $this->row( 'approved', 'Stripe said no' ) ) );
        $this->assertFalse( MyNJILGA_Dues_Invoice_Table::email_failed( $this->row( 'created', 'x', 'join' ) ) );
    }

    public function testEveryFailedEmailIsAlsoUnemailed(): void {
        foreach ( [ 'created', 'sent', 'draft', 'paid' ] as $status ) {
            foreach ( [ '', 'err' ] as $err ) {
                $r = $this->row( $status, $err );
                $this->assertTrue( ! MyNJILGA_Dues_Invoice_Table::email_failed( $r ) || MyNJILGA_Dues_Invoice_Table::is_unemailed( $r ), "$status/$err" );
            }
        }
    }
}
