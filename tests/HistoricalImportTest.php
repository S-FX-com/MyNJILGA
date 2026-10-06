<?php
/**
 * Historical Invoice Import's pure core: reading headings, parsing money /
 * dates / status / method, folding rows into invoices, matching them to
 * FluentCRM, and building the row to store.
 */
declare( strict_types=1 );

require_once dirname( __DIR__ ) . '/includes/join/class-join-orders-table.php';
require_once dirname( __DIR__ ) . '/includes/join/class-join-fulfillment.php';

class HistoricalImportTest extends NJILGA_TestCase {

    private function prepare( array $rows, string $default = 'paid' ): array {
        return MyNJILGA_Historical_Import::prepare( $rows, $default );
    }

    // -------------------------------------------------------------------
    // Headings
    // -------------------------------------------------------------------

    public function testHeadingsAreMatchedLoosely(): void {
        $m = MyNJILGA_Historical_Import::map_headers( [ 'Invoice #', 'Year', 'Law Firm', 'E-mail Address', 'Total', 'Payment Status', 'Date Paid', 'Check No.', 'Memo' ] );
        $this->assertSame( [], $m['missing'] );
        $this->assertSame( 0, $m['map']['invoice_number'] );
        $this->assertSame( 1, $m['map']['dues_year'] );
        $this->assertSame( 2, $m['map']['firm'] );
        $this->assertSame( 3, $m['map']['email'] );
        $this->assertSame( 4, $m['map']['amount'] );
        $this->assertSame( 5, $m['map']['status'] );
        $this->assertSame( 6, $m['map']['paid_date'] );
        $this->assertSame( 7, $m['map']['reference'] );
        $this->assertSame( 8, $m['map']['notes'] );
    }

    public function testDatePaidIsNotTheInvoiceDate(): void {
        $m = MyNJILGA_Historical_Import::map_headers( [ 'Date', 'Date Paid', 'Firm', 'Amount' ] );
        $this->assertSame( 0, $m['map']['invoice_date'] );
        $this->assertSame( 1, $m['map']['paid_date'] );
    }

    public function testAHeadingIsClaimedByOnlyOneField(): void {
        $m = MyNJILGA_Historical_Import::map_headers( [ 'Firm', 'Amount', 'Year', 'Name' ] );
        $this->assertSame( 3, $m['map']['member_name'] );
        $this->assertFalse( isset( $m['map']['description'] ) );
    }

    public function testASheetMissingWhatItNeedsSaysSo(): void {
        $m = MyNJILGA_Historical_Import::map_headers( [ 'Notes', 'Color' ] );
        $this->assertCount( 3, $m['missing'], 'an amount, a firm or email, and a year' );
        $this->assertSame( [ 'Color' ], $m['unrecognised'], 'Notes is understood; Color is ignored' );

        // Amount + email + a date is enough.
        $ok = MyNJILGA_Historical_Import::map_headers( [ 'Email', 'Amount', 'Invoice Date' ] );
        $this->assertSame( [], $ok['missing'] );
        // A year alone, with nothing to match on, is not.
        $no = MyNJILGA_Historical_Import::map_headers( [ 'Year', 'Amount' ] );
        $this->assertCount( 1, $no['missing'] );
    }

    // -------------------------------------------------------------------
    // Cells
    // -------------------------------------------------------------------

    public function testMoney(): void {
        $p = [ 'MyNJILGA_Historical_Import', 'parse_money' ];
        $this->assertSame( 12500, $p( '125' ) );
        $this->assertSame( 12500, $p( '$125.00' ) );
        $this->assertSame( 125050, $p( '$1,250.50' ) );
        $this->assertSame( 125050, $p( '1.250,50' ), 'comma decimal' );
        $this->assertSame( 12550, $p( '125,50' ) );
        $this->assertSame( 125000, $p( '1,250' ) );
        $this->assertSame( -12500, $p( '(125.00)' ) );
        $this->assertSame( -12500, $p( '-125' ) );
        $this->assertSame( -12500, $p( '125-' ) );
        $this->assertSame( 12545, $p( '125.45000000000001' ) );
        $this->assertSame( 50, $p( '.5' ) );
        $this->assertSame( 0, $p( '0' ) );
        $this->assertSame( null, $p( '' ) );
        $this->assertSame( null, $p( 'n/a' ) );
        $this->assertSame( null, $p( '12abc' ) );
        $this->assertSame( null, $p( '1,2,3' ) );
    }

    public function testDates(): void {
        $p = [ 'MyNJILGA_Historical_Import', 'parse_date' ];
        $this->assertSame( '2025-03-04', $p( '2025-03-04' ) );
        $this->assertSame( '2025-03-04', $p( '2025-03-04 14:00:00' ) );
        $this->assertSame( '2025-03-04', $p( '3/4/2025' ), 'US order' );
        $this->assertSame( '2025-03-04', $p( '3/4/25' ) );
        $this->assertSame( '2025-03-25', $p( '25/3/2025' ), 'a first number over 12 can only be the day' );
        $this->assertSame( '2025-03-04', $p( 'March 4, 2025' ) );
        $this->assertSame( '2025-03-04', $p( 'Mar 4th 2025' ) );
        $this->assertSame( '2025-03-04', $p( '4 March 2025' ) );
        $this->assertSame( '2025-03-04', $p( '4-Mar-25' ) );
        $this->assertSame( '1999-12-31', $p( '12/31/99' ) );
        $this->assertSame( '', $p( '2/30/2025' ), 'not a real date' );
        $this->assertSame( '', $p( 'soon' ) );
        $this->assertSame( '', $p( '' ) );
        $this->assertSame( '', $p( '1850-01-01' ) );
    }

    public function testYears(): void {
        $p = [ 'MyNJILGA_Historical_Import', 'parse_year' ];
        $this->assertSame( 2025, $p( '2025' ) );
        $this->assertSame( 2025, $p( 'FY2025' ) );
        $this->assertSame( 2025, $p( '2025-26' ) );
        $this->assertSame( 2025, $p( '2025/2026' ) );
        $this->assertSame( 0, $p( '' ) );
        $this->assertSame( 0, $p( '125' ) );
    }

    public function testStatusWords(): void {
        $p = [ 'MyNJILGA_Historical_Import', 'parse_status' ];
        $this->assertSame( 'paid', $p( 'Paid' ) );
        $this->assertSame( 'paid', $p( 'PAID IN FULL' ) );
        $this->assertSame( 'paid', $p( 'Received' ) );
        $this->assertSame( 'open', $p( 'Unpaid' ), '"unpaid" must not read as paid' );
        $this->assertSame( 'open', $p( 'Not paid' ) );
        $this->assertSame( 'open', $p( 'Past due' ) );
        $this->assertSame( 'open', $p( 'Outstanding' ) );
        $this->assertSame( 'void', $p( 'Void' ) );
        $this->assertSame( 'void', $p( 'Cancelled' ) );
        $this->assertSame( 'paid', $p( 'Yes' ) );
        $this->assertSame( 'open', $p( 'No' ) );
        $this->assertSame( '', $p( '' ) );
        $this->assertSame( '', $p( 'banana' ) );

        // A paid invoice whose cell also says something about its history is still paid.
        foreach ( [ 'Paid - no balance', 'Paid, balance $0', 'Paid (sent 1/2)', 'Overdue - paid', 'Paid in full', 'PAID' ] as $paid ) {
            $this->assertSame( 'paid', $p( $paid ), $paid );
        }
        // …and "unpaid"/"not paid"/partial are open, whatever else they say.
        foreach ( [ 'Unpaid', 'Not paid', 'Not yet paid', 'Non-paid', 'Partially paid', 'Part paid', 'Deposit received', 'Balance due', 'Overdue' ] as $open ) {
            $this->assertSame( 'open', $p( $open ), $open );
        }
        // Placeholders say nothing; a lone letter is a flag.
        foreach ( [ 'N/A', 'n/a', 'NA', 'TBD', '-', '?', 'none', 'unknown' ] as $blank ) {
            $this->assertSame( '', $p( $blank ), $blank );
        }
        $this->assertSame( 'paid', $p( 'Y' ) );
        $this->assertSame( 'open', $p( 'n' ) );
        $this->assertSame( 'void', $p( 'Void' ) );
        $this->assertSame( 'void', $p( 'Paid - refunded' ), 'a refunded payment is void, not paid' );
    }

    public function testMethodWords(): void {
        $p = [ 'MyNJILGA_Historical_Import', 'parse_method' ];
        $this->assertSame( 'check', $p( 'Check' ) );
        $this->assertSame( 'check', $p( 'Cheque #4417' ) );
        $this->assertSame( 'card', $p( 'Visa' ) );
        $this->assertSame( 'card', $p( 'Credit Card' ) );
        $this->assertSame( 'ach', $p( 'ACH' ) );
        $this->assertSame( 'ach', $p( 'Bank transfer' ) );
        $this->assertSame( 'wire', $p( 'Wire' ) );
        $this->assertSame( 'cash', $p( 'Cash' ) );
        $this->assertSame( 'paypal', $p( 'PayPal' ) );
        $this->assertSame( 'other', $p( 'Carrier pigeon' ) );
        $this->assertSame( '', $p( '' ) );
    }

    // -------------------------------------------------------------------
    // Firm names
    // -------------------------------------------------------------------

    public function testFirmKeyTreatsPunctuationAndEntitySuffixesAsNoise(): void {
        $k = [ 'MyNJILGA_Historical_Import', 'firm_key' ];
        $this->assertSame( 'smith and jones', $k( 'Smith & Jones, LLP' ) );
        $this->assertSame( $k( 'Smith & Jones, LLP' ), $k( 'Smith and Jones LLP' ) );
        $this->assertSame( $k( 'The Roe Law Firm P.C.' ), $k( 'Roe Law Firm' ) );
    }

    /** One rule, two places that use it: it must not drift from the online join's. */
    public function testFirmKeyMatchesTheOnlineJoinsRule(): void {
        foreach ( [ 'Smith & Jones, LLP', 'The Roe Law Firm P.C.', 'Poe &amp; Roe, Attorneys at Law', 'ACME Co.', 'Doe, Doe & Doe PLLC', '  Spaced   Out  Inc ' ] as $name ) {
            $this->assertSame( MyNJILGA_Join_Fulfillment::firm_key( $name ), MyNJILGA_Historical_Import::firm_key( $name ), $name );
        }
    }

    // -------------------------------------------------------------------
    // Sheet → records → invoices
    // -------------------------------------------------------------------

    public function testTheDownloadableTemplateImportsCleanly(): void {
        $rows = MyNJILGA_Spreadsheet_Reader::csv_rows( MyNJILGA_Historical_Import::template_csv() );
        $prep = $this->prepare( $rows );

        $this->assertSame( [], $prep['missing'], 'the template has every heading it needs' );
        $this->assertSame( [], $prep['unrecognised'], 'and every heading is understood' );
        foreach ( $prep['records'] as $r ) {
            $this->assertSame( [], $r['errors'], 'row ' . $r['line'] );
        }

        $inv = MyNJILGA_Historical_Import::group( $prep['records'] );
        $this->assertCount( 2, $inv, 'two invoices: one single-line, one two-line' );
        $this->assertSame( '2025-0101', $inv[0]['invoice_number'] );
        $this->assertSame( 12500, $inv[0]['total_cents'] );
        $this->assertSame( 'paid', $inv[0]['status'] );
        $this->assertSame( 'check', $inv[0]['method'] );
        $this->assertSame( '4417', $inv[0]['reference'] );
        $this->assertSame( '2025-02-03', $inv[0]['paid_date'] );

        $this->assertCount( 2, $inv[1]['lines'] );
        $this->assertSame( 20000, $inv[1]['total_cents'] );
        $this->assertSame( 20000, $inv[1]['paid_cents'], 'the $200 paid once, on the first line, covers both' );
        $this->assertSame( 'paid', $inv[1]['status'] );
        $this->assertSame( '2025 membership dues — 2 members', $inv[1]['description'] );
    }

    public function testRowsWithProblemsAreReportedNotGrouped(): void {
        $rows = [
            [ 'Invoice', 'Year', 'Firm', 'Amount' ],
            [ '1', '2025', 'Smith & Jones', '125' ],
            [ '2', '2025', 'Smith & Jones', 'lots' ],
            [ '3', '', 'Smith & Jones', '125' ],
            [ '4', '2025', '', '125' ],
        ];
        $prep = $this->prepare( $rows );
        $this->assertCount( 4, $prep['records'] );
        $this->assertSame( 2, $prep['records'][0]['line'] );
        $this->assertSame( [], $prep['records'][0]['errors'] );
        $this->assertCount( 1, $prep['records'][1]['errors'] );
        $this->assertTrue( strpos( $prep['records'][1]['errors'][0], 'not a number' ) !== false );
        $this->assertTrue( strpos( $prep['records'][2]['errors'][0], 'dues year' ) !== false );
        $this->assertTrue( strpos( $prep['records'][3]['errors'][0], 'nothing to match' ) !== false );

        $this->assertCount( 1, MyNJILGA_Historical_Import::group( $prep['records'] ) );
        $this->assertSame( 5, $prep['records'][3]['line'], 'sheet row numbers count the heading row' );
    }

    public function testTheYearFallsBackToTheInvoiceDateThenThePaidDate(): void {
        $prep = $this->prepare( [
            [ 'Firm', 'Amount', 'Invoice Date', 'Date Paid' ],
            [ 'A', '10', '3/1/2024', '' ],
            [ 'B', '10', '', '2/1/2023' ],
        ] );
        $this->assertSame( 2024, $prep['records'][0]['year'] );
        $this->assertSame( 2023, $prep['records'][1]['year'] );
    }

    public function testRowsSharingAnInvoiceNumberAndYearAreOneInvoice(): void {
        $prep = $this->prepare( [
            [ 'Invoice', 'Year', 'Firm', 'Email', 'Amount', 'Description' ],
            [ '7', '2025', 'Poe & Roe', 'a@x.test', '125', 'Professional' ],
            [ '7', '2025', 'Poe & Roe', 'b@x.test', '75', 'Professional' ],
            [ '7', '2024', 'Poe & Roe', 'a@x.test', '125', 'Professional' ],
        ] );
        $inv = MyNJILGA_Historical_Import::group( $prep['records'] );
        $this->assertCount( 2, $inv, 'the same number in another year is another invoice' );
        $this->assertSame( 20000, $inv[0]['total_cents'] );
        $this->assertSame( [ 2, 3 ], $inv[0]['rows'] );
        $this->assertSame( '2025/7|poe and roe', $inv[0]['ref'], 'year, number and firm' );
        $this->assertSame( '2024/7|poe and roe', $inv[1]['ref'] );
    }

    public function testUnnumberedRowsAreEachTheirOwnInvoiceWithStableRefs(): void {
        $rows = [
            [ 'Year', 'Firm', 'Amount' ],
            [ '2025', 'Poe & Roe', '125' ],
            [ '2025', 'Poe & Roe', '125' ],
            [ '2025', 'Smith', '75' ],
        ];
        $a = MyNJILGA_Historical_Import::group( $this->prepare( $rows )['records'] );
        $b = MyNJILGA_Historical_Import::group( $this->prepare( $rows )['records'] );

        $this->assertCount( 3, $a );
        $this->assertSame( array_column( $a, 'ref' ), array_column( $b, 'ref' ), 'the same sheet always gets the same refs' );
        $this->assertCount( 3, array_unique( array_column( $a, 'ref' ) ), 'two identical rows are still two invoices' );
        $this->assertTrue( strpos( $a[0]['ref'], 'row-' ) === 0 );
        $this->assertTrue( substr( $a[1]['ref'], -2 ) === '-2' );
    }

    public function testRefsIgnoreCaseInAnInvoiceNumber(): void {
        $a = MyNJILGA_Historical_Import::group( $this->prepare( [ [ 'Invoice', 'Year', 'Firm', 'Amount' ], [ 'inv-9', '2025', 'X', '5' ] ] )['records'] );
        $b = MyNJILGA_Historical_Import::group( $this->prepare( [ [ 'Invoice', 'Year', 'Firm', 'Amount' ], [ 'INV-9', '2025', 'X', '5' ] ] )['records'] );
        $this->assertSame( $a[0]['ref'], $b[0]['ref'] );
    }

    /** "N/A", "TBD" or a number reused by two firms must never merge them into one invoice. */
    public function testRowsUnderOneNumberButDifferentFirmsAreDifferentInvoices(): void {
        $inv = MyNJILGA_Historical_Import::group( $this->prepare( [
            [ 'Invoice', 'Year', 'Firm', 'Amount' ],
            [ 'N/A', '2025', 'Alpha PC', '100' ],
            [ 'N/A', '2025', 'Beta LLC', '200' ],
            [ 'N/A', '2025', 'Gamma Inc', '300' ],
        ] )['records'] );

        $this->assertCount( 3, $inv );
        $this->assertSame( [ 10000, 20000, 30000 ], array_column( $inv, 'total_cents' ) );
        $this->assertSame( [ 'Alpha PC', 'Beta LLC', 'Gamma Inc' ], array_column( $inv, 'firm' ) );
        $this->assertCount( 3, array_unique( array_column( $inv, 'ref' ) ), 'and each is its own idempotency key' );
        $this->assertTrue( strpos( implode( ' ', $inv[0]['warnings'] ), 'used by 3 different firms' ) !== false );
    }

    public function testARowWithNoFirmJoinsTheFirmNamedUnderTheSameNumber(): void {
        $inv = MyNJILGA_Historical_Import::group( $this->prepare( [
            [ 'Invoice', 'Year', 'Firm', 'Email', 'Amount' ],
            [ '5', '2025', '', 'ed@x.test', '75' ],              // first, with no firm…
            [ '5', '2025', 'Alpha PC', 'ann@x.test', '125' ],    // …the firm is named on a later row
        ] )['records'] );

        $this->assertCount( 1, $inv, 'one invoice, not two' );
        $this->assertSame( 20000, $inv[0]['total_cents'] );
        $this->assertSame( 'Alpha PC', $inv[0]['firm'] );
        $this->assertSame( '2025/5|alpha', $inv[0]['ref'] );
    }

    public function testTheSameSheetAlwaysGetsTheSameRefs(): void {
        $rows = [ [ 'Invoice', 'Year', 'Firm', 'Amount' ], [ 'N/A', '2025', 'Alpha PC', '100' ], [ 'N/A', '2025', 'Beta LLC', '200' ] ];
        $a = MyNJILGA_Historical_Import::group( $this->prepare( $rows )['records'] );
        $b = MyNJILGA_Historical_Import::group( $this->prepare( $rows )['records'] );
        $this->assertSame( array_column( $a, 'ref' ), array_column( $b, 'ref' ) );

        // And Alpha's ref doesn't change just because another firm's "N/A" arrives in a later sheet.
        $later = MyNJILGA_Historical_Import::group( $this->prepare( [ [ 'Invoice', 'Year', 'Firm', 'Amount' ], [ 'N/A', '2025', 'Alpha PC', '100' ] ] )['records'] );
        $this->assertSame( $a[0]['ref'], $later[0]['ref'] );
    }

    /** One unreadable row inside a numbered invoice must stop the invoice, not import it short. */
    public function testANumberedInvoiceThatLostALineToABadRowIsBlocked(): void {
        $prep = $this->prepare( [
            [ 'Invoice', 'Year', 'Firm', 'Amount' ],
            [ '1001', '2025', 'Alpha', '100' ],
            [ '1001', '2025', 'Alpha', '7S.00' ],   // a typo
            [ '1001', '2025', 'Alpha', '100' ],
            [ '1002', '2025', 'Alpha', '50' ],      // a different invoice, unaffected
        ] );
        $inv = MyNJILGA_Historical_Import::group( $prep['records'] );

        $this->assertCount( 2, $inv );
        $this->assertCount( 1, $inv[0]['problems'], 'the invoice is stopped, not imported with a line missing' );
        $this->assertTrue( strpos( $inv[0]['problems'][0], 'Row 3' ) !== false, 'names the row to fix: ' . $inv[0]['problems'][0] );
        $this->assertSame( [], $inv[1]['problems'], 'the other invoice is fine' );
    }

    public function testABadRowOfAnUnnumberedInvoiceJustSkipsThatRow(): void {
        $inv = MyNJILGA_Historical_Import::group( $this->prepare( [
            [ 'Year', 'Firm', 'Amount' ],
            [ '2025', 'Alpha', '100' ],
            [ '2025', 'Alpha', 'oops' ],
        ] )['records'] );
        $this->assertCount( 1, $inv, 'the good row is its own invoice' );
        $this->assertSame( [], $inv[0]['problems'] );
    }

    public function testABadRowWithNoYearStillBlocksItsNumberedInvoice(): void {
        $inv = MyNJILGA_Historical_Import::group( $this->prepare( [
            [ 'Invoice', 'Year', 'Firm', 'Amount' ],
            [ '1001', '2025', 'Alpha', '100' ],
            [ '1001', '', 'Alpha', '100' ],   // no year, so no error-free home — but the same invoice
        ] )['records'] );
        $this->assertCount( 1, $inv );
        $this->assertCount( 1, $inv[0]['problems'] );
    }

    public function testSheetRowNumbersSurviveBlankRowsDroppedByTheReader(): void {
        // The reader drops blank rows and says which sheet row each survivor was.
        $parsed = MyNJILGA_Spreadsheet_Reader::csv_records( "Invoice,Year,Firm,Amount\n1,2025,A,10\n\n\n2,2025,B,oops\n" );
        $prep   = MyNJILGA_Historical_Import::prepare( $parsed['rows'], 'paid', $parsed['lines'] );
        $this->assertSame( [ 2, 5 ], array_column( $prep['records'], 'line' ), 'the bad row is on sheet row 5, not 3' );
        $this->assertTrue( strpos( $prep['records'][1]['errors'][0], 'not a number' ) !== false );
    }

    public function testNoStatusColumnMeansTheChosenDefault(): void {
        $rows = [ [ 'Invoice', 'Year', 'Firm', 'Amount' ], [ '1', '2025', 'X', '100' ] ];

        $paid = $this->prepare( $rows, 'paid' );
        $this->assertFalse( $paid['has_status'] );
        $inv = MyNJILGA_Historical_Import::group( $paid['records'] );
        $this->assertSame( 'paid', $inv[0]['status'] );
        $this->assertSame( 10000, $inv[0]['paid_cents'] );

        $open = MyNJILGA_Historical_Import::group( $this->prepare( $rows, 'open' )['records'] );
        $this->assertSame( 'open', $open[0]['status'] );
        $this->assertSame( 0, $open[0]['paid_cents'] );
        $this->assertSame( '', $open[0]['paid_date'] );
    }

    public function testAYesNoPaidColumnIsAStatusNotAnAmount(): void {
        $inv = MyNJILGA_Historical_Import::group( $this->prepare( [
            [ 'Invoice', 'Year', 'Firm', 'Amount', 'Paid' ],
            [ '1', '2025', 'X', '100', 'Yes' ],
            [ '2', '2025', 'X', '100', 'No' ],
        ] )['records'] );
        $this->assertSame( 'paid', $inv[0]['status'] );
        $this->assertSame( 'open', $inv[1]['status'] );
    }

    // -------------------------------------------------------------------
    // Settling status and payment
    // -------------------------------------------------------------------

    public function testSettle(): void {
        $s = [ 'MyNJILGA_Historical_Import', 'settle' ];

        $this->assertSame( [ 'paid', 10000, '' ], $s( 'paid', 'paid', 10000, null ) );
        $this->assertSame( [ 'paid', 10000, '' ], $s( '', 'paid', 10000, 10000 ) );
        $this->assertSame( [ 'void', 0, '' ], $s( 'void', 'paid', 10000, 10000 ), 'void wins' );
        $this->assertSame( [ 'open', 0, '' ], $s( 'open', 'paid', 10000, null ) );
        $this->assertSame( [ 'open', 0, '' ], $s( '', 'open', 10000, null ) );

        // Said paid, but the amount says otherwise: the amount is the harder fact.
        $r = $s( 'paid', 'paid', 10000, 4000 );
        $this->assertSame( 'open', $r[0] );
        $this->assertSame( 4000, $r[1] );
        $this->assertTrue( $r[2] !== '' );

        // Open with the whole amount paid is paid.
        $this->assertSame( [ 'paid', 10000, '' ], $s( 'open', 'paid', 10000, 10000 ) );
        // A part payment on an open invoice.
        $this->assertSame( [ 'open', 2500, '' ], $s( 'open', 'paid', 10000, 2500 ) );
    }

    public function testAnAmountPaidOnEveryLineIsSummedButOnceIsTakenAsTheTotal(): void {
        $perLine = MyNJILGA_Historical_Import::group( $this->prepare( [
            [ 'Invoice', 'Year', 'Firm', 'Amount', 'Amount Paid' ],
            [ '1', '2025', 'X', '125', '125' ],
            [ '1', '2025', 'X', '75', '75' ],
        ] )['records'] );
        $this->assertSame( 20000, $perLine[0]['paid_cents'] );
        $this->assertSame( 'paid', $perLine[0]['status'] );

        $once = MyNJILGA_Historical_Import::group( $this->prepare( [
            [ 'Invoice', 'Year', 'Firm', 'Amount', 'Amount Paid' ],
            [ '1', '2025', 'X', '125', '200' ],
            [ '1', '2025', 'X', '75', '' ],
        ] )['records'] );
        $this->assertSame( 20000, $once[0]['paid_cents'] );

        // The same invoice-level figure repeated on each row isn't doubled.
        $repeated = MyNJILGA_Historical_Import::group( $this->prepare( [
            [ 'Invoice', 'Year', 'Firm', 'Amount', 'Amount Paid' ],
            [ '1', '2025', 'X', '125', '200' ],
            [ '1', '2025', 'X', '75', '200' ],
        ] )['records'] );
        $this->assertSame( 20000, $repeated[0]['paid_cents'] );
    }

    public function testAPaidInvoiceWithNoPaidDateIsDatedByItsInvoiceDate(): void {
        $inv = MyNJILGA_Historical_Import::group( $this->prepare( [
            [ 'Invoice', 'Year', 'Firm', 'Amount', 'Invoice Date' ],
            [ '1', '2025', 'X', '125', '2025-01-15' ],
        ] )['records'] );
        $this->assertSame( '2025-01-15', $inv[0]['paid_date'] );
    }

    // -------------------------------------------------------------------
    // Matching
    // -------------------------------------------------------------------

    private function invoice( array $o = [] ): array {
        return array_merge( [
            'firm' => 'Smith & Jones, LLP', 'email' => 'ann@sj.test', 'member_name' => 'Ann Brown',
            'lines' => [ [ 'title' => 'Ann Brown — 2025 dues', 'amount' => 12500, 'email' => 'ann@sj.test', 'member' => 'Ann Brown' ] ],
        ], $o );
    }

    private function directory(): array {
        return [
            'contacts' => [
                'ann@sj.test' => [ 'id' => 5, 'name' => 'Ann Brown', 'company_id' => 42 ],
                'ed@sj.test'  => [ 'id' => 9, 'name' => 'Ed Fox', 'company_id' => 0 ],
            ],
            'keys'  => [ 'smith and jones' => [ 42 ], 'poe and roe' => [ 50, 51 ] ],
            'names' => [ 42 => 'Smith and Jones LLP', 50 => 'Poe & Roe PC', 51 => 'Poe and Roe LLP' ],
        ];
    }

    private function resolve( array $inv ): array {
        $d = $this->directory();
        return MyNJILGA_Historical_Import::resolve( $inv, $d['contacts'], $d['keys'], $d['names'] );
    }

    public function testAFirmIsFoundBySpellingInsensitiveName(): void {
        $r = $this->resolve( $this->invoice() );
        $this->assertSame( 42, $r['company_id'] );
        $this->assertSame( 'Smith and Jones LLP', $r['company_name'], 'the FluentCRM name, not the sheet\'s' );
        $this->assertSame( 5, $r['contact_id'] );
        $this->assertSame( 'Ann Brown', $r['contact_name'] );
        $this->assertSame( [ 0 => 5 ], $r['line_contacts'] );
        $this->assertSame( [], $r['problems'] );
    }

    public function testNoFirmInTheSheetUsesTheContactsOwnFirm(): void {
        $r = $this->resolve( $this->invoice( [ 'firm' => '' ] ) );
        $this->assertSame( 42, $r['company_id'] );
        $this->assertSame( [], $r['warnings'], 'nothing to warn about when the sheet named no firm' );
    }

    public function testAnUnknownFirmFallsBackToTheContactsFirmWithAWarning(): void {
        $r = $this->resolve( $this->invoice( [ 'firm' => 'Nobody & Sons' ] ) );
        $this->assertSame( 42, $r['company_id'] );
        $warn = implode( ' ', $r['warnings'] );
        $this->assertTrue( strpos( $warn, 'No FluentCRM Company named "Nobody & Sons"' ) !== false );
        $this->assertTrue( strpos( $warn, 'Filed under' ) !== false );
    }

    public function testAFirmWithNoOtherMatchIsBlocked(): void {
        $r = $this->resolve( $this->invoice( [ 'firm' => 'Nobody & Sons', 'email' => 'x@x.test', 'lines' => [ [ 'title' => 't', 'amount' => 1, 'email' => 'x@x.test', 'member' => '' ] ] ] ) );
        $this->assertSame( 0, $r['company_id'] );
        $this->assertSame( 0, $r['contact_id'] );
        $this->assertCount( 1, $r['problems'] );
    }

    public function testAnUnknownEmailStillImportsOnTheFirm(): void {
        $r = $this->resolve( $this->invoice( [ 'email' => 'new@sj.test', 'member_name' => 'New Person', 'lines' => [ [ 'title' => 't', 'amount' => 1, 'email' => 'new@sj.test', 'member' => 'New Person' ] ] ] ) );
        $this->assertSame( 42, $r['company_id'] );
        $this->assertSame( 0, $r['contact_id'] );
        $this->assertSame( 'New Person', $r['contact_name'], 'the sheet\'s name is kept' );
        $this->assertSame( [], $r['problems'] );
        $this->assertTrue( strpos( implode( ' ', $r['warnings'] ), 'new@sj.test' ) !== false );
    }

    public function testTwoFirmsWithTheSameKeyAreAmbiguousUnlessTheNameIsExact(): void {
        $noEmail = [ [ 'title' => 't', 'amount' => 1, 'email' => '', 'member' => '' ] ];

        // Two Companies share the key and neither is spelled exactly like the sheet: don't guess.
        $amb = $this->resolve( $this->invoice( [ 'firm' => 'Poe and Roe', 'email' => '', 'lines' => $noEmail ] ) );
        $this->assertSame( 0, $amb['company_id'] );
        $this->assertTrue( strpos( implode( ' ', $amb['problems'] ), 'More than one' ) !== false );

        // …but the sheet spelling one of them exactly (case aside) settles it.
        $exact = $this->resolve( $this->invoice( [ 'firm' => 'poe & roe pc', 'email' => '', 'lines' => $noEmail ] ) );
        $this->assertSame( 50, $exact['company_id'] );
        $this->assertSame( [], $exact['problems'] );
    }

    public function testAnInvoiceWithOnlyAnEmailMatchesThroughTheContact(): void {
        $r = $this->resolve( $this->invoice( [ 'firm' => '' ] ) );
        $this->assertSame( 5, $r['contact_id'] );

        $none = $this->resolve( $this->invoice( [ 'firm' => '', 'email' => 'zz@zz.test', 'lines' => [ [ 'title' => 't', 'amount' => 1, 'email' => 'zz@zz.test', 'member' => '' ] ] ] ) );
        $this->assertCount( 1, $none['problems'] );
    }

    // -------------------------------------------------------------------
    // The stored row
    // -------------------------------------------------------------------

    public function testTheHistoryRowCarriesEverythingAndEveryMember(): void {
        $prep = $this->prepare( [
            [ 'Invoice', 'Year', 'Firm', 'Email', 'Amount', 'Status', 'Date Paid', 'Method', 'Reference', 'Notes' ],
            [ '9', '2025', 'Smith & Jones', 'ann@sj.test', '125', 'Paid', '2/3/2025', 'Check', '4417', 'hand delivered' ],
            [ '9', '2025', 'Smith & Jones', 'ed@sj.test', '75', '', '', '', '', '' ],
        ] );
        $inv = MyNJILGA_Historical_Import::group( $prep['records'] )[0];
        $d   = $this->directory();
        $res = MyNJILGA_Historical_Import::resolve( $inv, $d['contacts'], $d['keys'], $d['names'] );
        $row = MyNJILGA_Historical_Import::history_row( $inv, $res, 'import-X', 3 );

        $this->assertSame( 'import', $row['source'] );
        $this->assertSame( '2025/9|smith and jones', $row['source_ref'] );
        $this->assertSame( 'import-X', $row['batch_id'] );
        $this->assertSame( 3, $row['created_by'] );
        $this->assertSame( 2025, $row['dues_year'] );
        $this->assertSame( 42, $row['company_id'] );
        $this->assertSame( 5, $row['contact_id'] );
        $this->assertSame( ',5,9,', $row['member_ids'], 'both people named on the invoice can find it' );
        $this->assertSame( 'paid', $row['status'] );
        $this->assertSame( 20000, $row['total_cents'] );
        $this->assertSame( 20000, $row['paid_cents'] );
        $this->assertSame( '2025-02-03', $row['paid_at'] );
        $this->assertSame( 'check', $row['method'] );
        $this->assertSame( '4417', $row['reference'] );
        $this->assertSame( 'hand delivered', $row['notes'] );

        $lines = json_decode( $row['line_items'], true );
        $this->assertCount( 2, $lines );
        $this->assertSame( [ 5, 9 ], array_column( $lines, 'contact_id' ) );
        $this->assertSame( 20000, array_sum( array_column( $lines, 'amount' ) ), 'lines add up to the total' );
    }

    /** What the importer writes is what the tab reads back. */
    public function testAnImportedRowRoundTripsThroughTheReadModel(): void {
        $inv = MyNJILGA_Historical_Import::group( $this->prepare( [
            [ 'Invoice', 'Year', 'Firm', 'Email', 'Amount', 'Status', 'Date Paid', 'Method', 'Reference' ],
            [ '9', '2025', 'Smith & Jones', 'ann@sj.test', '125', 'Paid', '2/3/2025', 'Check', '4417' ],
        ] )['records'] )[0];
        $d   = $this->directory();
        $row = MyNJILGA_Historical_Import::history_row( $inv, MyNJILGA_Historical_Import::resolve( $inv, $d['contacts'], $d['keys'], $d['names'] ), 'b', 1 );
        $row['id'] = 77;

        $e = MyNJILGA_Dues_History::hist_entry( (object) $row );
        $this->assertSame( 'import', $e['origin'] );
        $this->assertSame( 'paid', $e['status'] );
        $this->assertSame( 12500, $e['total'] );
        $this->assertSame( 'Check #4417', $e['payments'][0]['method'] );
        $this->assertSame( 12500, MyNJILGA_Dues_History::share_for( $e, 5 ) );
        $this->assertSame( 'Smith and Jones LLP', $e['company'] );
    }
}
