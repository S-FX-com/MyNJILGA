<?php
/**
 * CSV cells that a spreadsheet would run as formulas are neutralised;
 * everything else, including negative amounts, passes through.
 */
require_once dirname( __DIR__ ) . '/includes/class-report-csv.php';

class ReportCsvCellTest extends NJILGA_TestCase {

    public function test_formula_prefixes_are_neutralised(): void {
        $this->assertSame( "'=HYPERLINK(\"http://x\",\"Firm\")", MyNJILGA_Report_Csv::cell( '=HYPERLINK("http://x","Firm")' ) );
        $this->assertSame( "'+1+cmd", MyNJILGA_Report_Csv::cell( '+1+cmd' ) );
        $this->assertSame( "'@SUM(A1)", MyNJILGA_Report_Csv::cell( '@SUM(A1)' ) );
        $this->assertSame( "'-2+3", MyNJILGA_Report_Csv::cell( '-2+3' ) );
        $this->assertSame( "'\tx", MyNJILGA_Report_Csv::cell( "\tx" ) );
    }

    public function test_ordinary_values_pass_through(): void {
        $this->assertSame( 'Smith & Jones, LLP', MyNJILGA_Report_Csv::cell( 'Smith & Jones, LLP' ) );
        $this->assertSame( '-125.00', MyNJILGA_Report_Csv::cell( '-125.00' ), 'A negative amount is a number, not a formula.' );
        $this->assertSame( 42, MyNJILGA_Report_Csv::cell( 42 ) );
        $this->assertSame( '', MyNJILGA_Report_Csv::cell( '' ) );
        $this->assertSame( null, MyNJILGA_Report_Csv::cell( null ) );
    }
}
