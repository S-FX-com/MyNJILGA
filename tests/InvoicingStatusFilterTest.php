<?php
/**
 * The Invoicing table's status filter: its keys and labels. Which options each tab shows, and what Select all
 * selects, is the page's script and is exercised in a browser; what can be pinned here is that every status a
 * row can have is a filter key with words, so no status can ever reach the table unlabeled.
 */
declare( strict_types=1 );

require_once dirname( __DIR__ ) . '/includes/class-page-invoicing.php';

class InvoicingStatusFilterTest extends NJILGA_TestCase {

    public function testAnOrdinaryRowIsFilteredByItsOwnStatus(): void {
        foreach ( [ 'ready', 'created', 'sent', 'processing', 'blocked', 'voided' ] as $status ) {
            $this->assertSame( $status, MyNJILGA_Page_Invoicing::status_key( [ 'status' => $status, 'join' => false ] ) );
            $this->assertSame( $status, MyNJILGA_Page_Invoicing::status_key( [ 'status' => $status ] ), 'no "join" key is an ordinary row' );
        }
    }

    /** An online join sits beside the invoices on the Paid tab but is not one, and the filter can say which. */
    public function testAnOnlineJoinIsPaidOnlineOrPending(): void {
        $this->assertSame( 'paid-online', MyNJILGA_Page_Invoicing::status_key( [ 'status' => 'paid', 'join' => true ] ) );
        $this->assertSame( 'join-pending', MyNJILGA_Page_Invoicing::status_key( [ 'status' => 'join', 'join' => true ] ) );
        $this->assertSame( 'paid', MyNJILGA_Page_Invoicing::status_key( [ 'status' => 'paid', 'join' => false ] ), 'an invoice paid is just Paid' );
    }

    /** Every status classify() can return, in the forms it writes them. */
    private function emitted(): array {
        $src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-page-invoicing.php' );
        $body = substr( $src, (int) strpos( $src, 'private static function classify(' ) );
        $body = substr( $body, 0, (int) strpos( $body, 'private static function emailable(' ) );
        preg_match_all( "/verdict\(\s*[^,]+,\s*'([a-z-]+)'/", $body, $m ); // a literal second argument
        $found = $m[1];
        // The ones written as a ternary or a variable, which the pattern above cannot read.
        return array_values( array_unique( array_merge( $found, [ 'paid', 'join', 'sent', 'created', 'emailing', 'scheduled', 'processing', 'error' ] ) ) );
    }

    public function testEveryStatusARowCanHaveHasWords(): void {
        $labels  = MyNJILGA_Page_Invoicing::status_labels();
        $emitted = $this->emitted();
        $this->assertTrue( count( $emitted ) >= 14, 'the scan found the statuses: ' . implode( ',', $emitted ) );
        foreach ( $emitted as $status ) {
            // A join is keyed apart; everything else is its own key.
            $key = $status === 'join' ? 'join-pending' : $status;
            $this->assertTrue( isset( $labels[ $key ] ), "status '$status' (filter key '$key') has no label" );
        }
        $this->assertTrue( isset( $labels['paid-online'] ) );
    }

    public function testNoLabelIsBlankAndNoneRepeats(): void {
        $labels = MyNJILGA_Page_Invoicing::status_labels();
        foreach ( $labels as $key => $text ) {
            $this->assertTrue( trim( $text ) !== '', "$key has words" );
        }
        $this->assertSame( count( $labels ), count( array_unique( $labels ) ), 'two statuses are never called the same thing' );
    }

    /** The order is the order a firm moves through the stages, so a tab's list reads the way the page does. */
    public function testTheListFollowsTheStages(): void {
        $keys = array_keys( MyNJILGA_Page_Invoicing::status_labels() );
        $this->assertTrue( array_search( 'ready', $keys, true ) < array_search( 'created', $keys, true ) );
        $this->assertTrue( array_search( 'created', $keys, true ) < array_search( 'sent', $keys, true ) );
        $this->assertTrue( array_search( 'sent', $keys, true ) < array_search( 'paid', $keys, true ) );
        $this->assertTrue( array_search( 'paid', $keys, true ) < array_search( 'voided', $keys, true ), 'exceptions after the stages' );
    }
}
