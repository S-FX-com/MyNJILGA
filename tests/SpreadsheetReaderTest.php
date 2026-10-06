<?php
/**
 * The spreadsheet reader: CSV variants, and a real .xlsx built in the
 * test (shared strings, rich text, inline strings, a date-formatted cell,
 * Excel's float noise, a sparse row, a worksheet that isn't sheet1).
 */
declare( strict_types=1 );

class SpreadsheetReaderTest extends NJILGA_TestCase {

    /** @var array<int,string> */
    private $tmp = [];

    public function __destruct() {
        foreach ( $this->tmp as $f ) {
            @unlink( $f );
        }
    }

    private function tmpfile( string $ext ): string {
        $f = tempnam( sys_get_temp_dir(), 'njilga' ) . '.' . $ext;
        $this->tmp[] = $f;
        return $f;
    }

    private function xlsx( array $parts ): string {
        $f   = $this->tmpfile( 'xlsx' );
        $zip = new ZipArchive();
        $zip->open( $f, ZipArchive::CREATE | ZipArchive::OVERWRITE );
        foreach ( $parts as $name => $xml ) {
            $zip->addFromString( $name, $xml );
        }
        $zip->close();
        return $f;
    }

    private const NS = 'xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"';

    // -------------------------------------------------------------------
    // CSV
    // -------------------------------------------------------------------

    public function testCsvStripsABomAndHandlesQuotes(): void {
        $rows = MyNJILGA_Spreadsheet_Reader::csv_rows( "\xEF\xBB\xBFFirm,Amount\r\n\"Smith, Jones & Co\",\"1,250.00\"\r\n" );
        $this->assertSame( [ [ 'Firm', 'Amount' ], [ 'Smith, Jones & Co', '1,250.00' ] ], $rows );
    }

    public function testCsvDelimiterIsDetectedFromTheFirstLine(): void {
        $this->assertSame( [ [ 'a', 'b' ], [ '1', '2' ] ], MyNJILGA_Spreadsheet_Reader::csv_rows( "a;b\n1;2\n" ) );
        $this->assertSame( [ [ 'a', 'b' ], [ '1', '2' ] ], MyNJILGA_Spreadsheet_Reader::csv_rows( "a\tb\n1\t2\n" ) );
    }

    public function testBlankRowsAndTrailingEmptyCellsAreDropped(): void {
        $rows = MyNJILGA_Spreadsheet_Reader::csv_rows( "a,b,,\n\n , ,\n1,2,,\n" );
        $this->assertSame( [ [ 'a', 'b' ], [ '1', '2' ] ], $rows );
    }

    public function testAWindows1252CsvBecomesUtf8(): void {
        if ( ! function_exists( 'mb_convert_encoding' ) ) {
            return;
        }
        $cp1252 = mb_convert_encoding( "Firm\nCaf\u{e9} Law\n", 'Windows-1252', 'UTF-8' );
        $this->assertSame( [ [ 'Firm' ], [ "Caf\u{e9} Law" ] ], MyNJILGA_Spreadsheet_Reader::csv_rows( $cp1252 ) );
    }

    public function testCellsAreTrimmed(): void {
        $this->assertSame( [ [ 'a', 'b' ] ], MyNJILGA_Spreadsheet_Reader::csv_rows( "  a  ,\tb \n" ) );
    }

    public function testTheRowCapIsEnforced(): void {
        $csv  = "h\n" . implode( "\n", array_fill( 0, MyNJILGA_Spreadsheet_Reader::MAX_ROWS + 50, 'x' ) );
        $rows = MyNJILGA_Spreadsheet_Reader::csv_rows( $csv );
        $this->assertSame( MyNJILGA_Spreadsheet_Reader::MAX_ROWS, count( $rows ) );
    }

    public function testRefusedAndMissingFiles(): void {
        $r = MyNJILGA_Spreadsheet_Reader::read( '/no/such/file.csv', 'x.csv' );
        $this->assertSame( [], $r['rows'] );
        $this->assertTrue( $r['error'] !== '' );

        $f = $this->tmpfile( 'xls' );
        file_put_contents( $f, 'whatever' );
        $r = MyNJILGA_Spreadsheet_Reader::read( $f, 'old.xls' );
        $this->assertTrue( strpos( $r['error'], 'CSV' ) !== false, 'an old .xls is told how to save as CSV' );

        $r = MyNJILGA_Spreadsheet_Reader::read( $f, 'notes.pdf' );
        $this->assertTrue( strpos( $r['error'], '.csv or .xlsx' ) !== false );
    }

    public function testReadDispatchesCsvByExtension(): void {
        $f = $this->tmpfile( 'csv' );
        file_put_contents( $f, "Firm,Amount\nX,5\n" );
        $r = MyNJILGA_Spreadsheet_Reader::read( $f, 'Dues.CSV' );
        $this->assertSame( '', $r['error'] );
        $this->assertSame( [ [ 'Firm', 'Amount' ], [ 'X', '5' ] ], $r['rows'] );
    }

    // -------------------------------------------------------------------
    // Pure helpers
    // -------------------------------------------------------------------

    public function testColumnIndexFromACellReference(): void {
        $c = [ 'MyNJILGA_Spreadsheet_Reader', 'col_index' ];
        $this->assertSame( 0, $c( 'A1' ) );
        $this->assertSame( 1, $c( 'B7' ) );
        $this->assertSame( 25, $c( 'Z3' ) );
        $this->assertSame( 26, $c( 'AA3' ) );
        $this->assertSame( 27, $c( 'ab12' ) );
    }

    public function testWhichNumberFormatsAreDates(): void {
        $d = [ 'MyNJILGA_Spreadsheet_Reader', 'is_date_format' ];
        $this->assertTrue( $d( 14, '' ), 'the built-in short date' );
        $this->assertTrue( $d( 22, '' ) );
        $this->assertFalse( $d( 0, '' ), 'General' );
        $this->assertFalse( $d( 2, '' ), '0.00' );
        $this->assertFalse( $d( 164, '$#,##0.00' ) );
        $this->assertFalse( $d( 165, '0.00E+00' ) );
        $this->assertFalse( $d( 166, '[Red]0.00' ), 'a colour code is not a date' );
        $this->assertFalse( $d( 167, '"days" 0' ), 'quoted text is not a date' );
        $this->assertTrue( $d( 168, 'yyyy\\-mm\\-dd' ) );
        $this->assertTrue( $d( 169, 'mmm d, yyyy' ) );
        $this->assertTrue( $d( 170, '[$-409]h:mm AM/PM' ) );
    }

    public function testExcelSerialDates(): void {
        $s = [ 'MyNJILGA_Spreadsheet_Reader', 'serial_to_date' ];
        $this->assertSame( '2025-01-21', $s( 45678.0 ) );
        $this->assertSame( '2023-03-15 12:00:00', $s( 45000.5 ) );
        $this->assertSame( '2000-01-01', $s( 36526.0 ) );
        $this->assertSame( '', $s( 1.0 ), 'before the 1900 leap-year bug is ignored' );
        $this->assertSame( '2025-01-21', $s( 45678.0 - 1462, true ), 'the 1904 date system' );
    }

    public function testNumbersLoseTheirFloatNoise(): void {
        $n = [ 'MyNJILGA_Spreadsheet_Reader', 'clean_number' ];
        $this->assertSame( '125.45', $n( '125.45000000000001' ) );
        $this->assertSame( '125', $n( '125' ) );
        $this->assertSame( '125', $n( '125.0' ) );
        $this->assertSame( '0.1', $n( '0.10000000000000001' ) );
        $this->assertSame( '0', $n( '-0' ) );
        $this->assertSame( '-12.5', $n( '-12.5' ) );
        $this->assertSame( 'abc', $n( 'abc' ) );
    }

    // -------------------------------------------------------------------
    // XLSX
    // -------------------------------------------------------------------

    public function testAnXlsxIsReadWithStringsDatesAndNumbers(): void {
        if ( ! class_exists( 'ZipArchive' ) ) {
            return;
        }
        $ns = self::NS;
        $f  = $this->xlsx( [
            'xl/workbook.xml'            => '<?xml version="1.0"?><workbook ' . $ns . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Data" sheetId="1" r:id="rId7"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId7" Type="x" Target="worksheets/sheet3.xml"/></Relationships>',
            'xl/sharedStrings.xml'       => '<?xml version="1.0"?><sst ' . $ns . '><si><t>Firm</t></si><si><t>Date Paid</t></si><si><t>Amount</t></si><si><r><t>Smith &amp; </t></r><r><t>Jones LLP</t></r></si></sst>',
            'xl/styles.xml'              => '<?xml version="1.0"?><styleSheet ' . $ns . '><numFmts count="1"><numFmt numFmtId="164" formatCode="yyyy\\-mm\\-dd"/></numFmts><cellXfs count="3"><xf numFmtId="0"/><xf numFmtId="14"/><xf numFmtId="164"/></cellXfs></styleSheet>',
            'xl/worksheets/sheet3.xml'   => '<?xml version="1.0"?><worksheet ' . $ns . '><sheetData>'
                . '<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c><c r="C1" t="s"><v>2</v></c></row>'
                . '<row r="2"><c r="A2" t="s"><v>3</v></c><c r="B2" s="1"><v>45678</v></c><c r="C2"><v>125.45000000000001</v></c></row>'
                . '<row r="3"><c r="A3" t="inlineStr"><is><t>Inline Co</t></is></c><c r="C3" s="2"><v>45000</v></c><c r="E3" t="b"><v>1</v></c></row>'
                . '<row r="4"/>'
                . '<row r="5"><c r="A5" t="str"><v>Formula Co</v></c><c r="C5" t="e"><v>#DIV/0!</v></c></row>'
                . '</sheetData></worksheet>',
        ] );

        $r = MyNJILGA_Spreadsheet_Reader::read( $f, 'sheet.xlsx' );
        $this->assertSame( '', $r['error'] );
        $this->assertSame(
            [
                [ 'Firm', 'Date Paid', 'Amount' ],
                [ 'Smith & Jones LLP', '2025-01-21', '125.45' ],
                [ 'Inline Co', '', '2023-03-15', '', 'TRUE' ],
                [ 'Formula Co' ],
            ],
            $r['rows']
        );
    }

    public function testAnXlsxWithoutRelationshipsFallsBackToTheFirstWorksheet(): void {
        if ( ! class_exists( 'ZipArchive' ) ) {
            return;
        }
        $f = $this->xlsx( [
            'xl/worksheets/sheet2.xml' => '<?xml version="1.0"?><worksheet ' . self::NS . '><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>two</t></is></c></row></sheetData></worksheet>',
            'xl/worksheets/sheet10.xml' => '<?xml version="1.0"?><worksheet ' . self::NS . '><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>ten</t></is></c></row></sheetData></worksheet>',
        ] );
        $this->assertSame( [ [ 'two' ] ], MyNJILGA_Spreadsheet_Reader::read( $f, 'x.xlsx' )['rows'], 'natural order: sheet2 before sheet10' );
    }

    public function testACellWithNoReferenceFollowsTheOneBeforeIt(): void {
        if ( ! class_exists( 'ZipArchive' ) ) {
            return;
        }
        $f = $this->xlsx( [
            'xl/worksheets/sheet1.xml' => '<?xml version="1.0"?><worksheet ' . self::NS . '><sheetData><row><c t="inlineStr"><is><t>a</t></is></c><c t="inlineStr"><is><t>b</t></is></c></row></sheetData></worksheet>',
        ] );
        $this->assertSame( [ [ 'a', 'b' ] ], MyNJILGA_Spreadsheet_Reader::read( $f, 'x.xlsx' )['rows'] );
    }

    public function testNotAWorkbookIsRefusedWithAMessage(): void {
        if ( ! class_exists( 'ZipArchive' ) ) {
            return;
        }
        $f = $this->tmpfile( 'xlsx' );
        file_put_contents( $f, 'this is not a zip file' );
        $r = MyNJILGA_Spreadsheet_Reader::read( $f, 'fake.xlsx' );
        $this->assertSame( [], $r['rows'] );
        $this->assertTrue( strpos( $r['error'], 'not a valid' ) !== false );

        $empty = $this->xlsx( [ 'readme.txt' => 'no sheets here' ] );
        $r     = MyNJILGA_Spreadsheet_Reader::read( $empty, 'empty.xlsx' );
        $this->assertSame( [], $r['rows'] );
        $this->assertTrue( $r['error'] !== '' );
    }

    public function testMalformedXmlInsideAWorkbookIsAnErrorNotACrash(): void {
        if ( ! class_exists( 'ZipArchive' ) ) {
            return;
        }
        $f = $this->xlsx( [ 'xl/worksheets/sheet1.xml' => '<worksheet><sheetData><row></worksheet>' ] );
        $r = MyNJILGA_Spreadsheet_Reader::read( $f, 'bad.xlsx' );
        $this->assertSame( [], $r['rows'] );
        $this->assertTrue( strpos( $r['error'], 'could not be read' ) !== false );
    }

    /** An entity declaration must never be followed off the server — nor even parsed. */
    public function testExternalEntitiesAreRefusedAndNeverResolved(): void {
        if ( ! class_exists( 'ZipArchive' ) ) {
            return;
        }
        $secret = $this->tmpfile( 'txt' );
        file_put_contents( $secret, 'TOP-SECRET-VALUE' );
        $xml = '<?xml version="1.0"?><!DOCTYPE s [<!ENTITY x SYSTEM "file://' . $secret . '">]><worksheet ' . self::NS . '><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>&x;</t></is></c></row></sheetData></worksheet>';
        $f   = $this->xlsx( [ 'xl/worksheets/sheet1.xml' => $xml ] );

        $r = MyNJILGA_Spreadsheet_Reader::read( $f, 'xxe.xlsx' );
        $this->assertSame( [], $r['rows'] );
        $this->assertTrue( strpos( $r['error'], 'DOCTYPE' ) !== false, 'refused, with a reason' );
        $this->assertFalse( strpos( json_encode( $r ), 'TOP-SECRET-VALUE' ) !== false, 'the file the entity points at was read' );
    }

    /** "Billion laughs": the expansion must never even start. */
    public function testAnEntityExpansionBombIsRefusedImmediately(): void {
        if ( ! class_exists( 'ZipArchive' ) ) {
            return;
        }
        $ents = '<!ENTITY a0 "lol">';
        for ( $i = 1; $i <= 9; $i++ ) {
            $ents .= '<!ENTITY a' . $i . ' "' . str_repeat( '&a' . ( $i - 1 ) . ';', 10 ) . '">';
        }
        $xml = '<?xml version="1.0"?><!DOCTYPE s [' . $ents . ']><worksheet ' . self::NS . '><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>&a9;</t></is></c></row></sheetData></worksheet>';
        $f   = $this->xlsx( [ 'xl/worksheets/sheet1.xml' => $xml ] );

        $start = microtime( true );
        $r     = MyNJILGA_Spreadsheet_Reader::read( $f, 'laughs.xlsx' );
        $this->assertSame( [], $r['rows'] );
        $this->assertTrue( $r['error'] !== '' );
        $this->assertTrue( microtime( true ) - $start < 2.0, 'refused up front, not after expanding' );
    }

    /** A UTF-16 document would hide its DOCTYPE from a byte search; it is refused as not UTF-8. */
    public function testAUtf16WorksheetIsRefused(): void {
        if ( ! class_exists( 'ZipArchive' ) || ! function_exists( 'mb_convert_encoding' ) ) {
            return;
        }
        $xml = mb_convert_encoding( '<?xml version="1.0" encoding="UTF-16"?><!DOCTYPE s [<!ENTITY x "y">]><worksheet ' . self::NS . '><sheetData/></worksheet>', 'UTF-16LE', 'UTF-8' );
        $f   = $this->xlsx( [ 'xl/worksheets/sheet1.xml' => "\xFF\xFE" . $xml ] );
        $r   = MyNJILGA_Spreadsheet_Reader::read( $f, 'utf16.xlsx' );
        $this->assertSame( [], $r['rows'] );
        $this->assertTrue( strpos( $r['error'], 'could not be read' ) !== false );
    }

    /** A pathologically nested document hits libxml's depth limit instead of recursing without end. */
    public function testAPathologicallyNestedWorksheetIsAnErrorNotACrash(): void {
        if ( ! class_exists( 'ZipArchive' ) ) {
            return;
        }
        $deep = '<?xml version="1.0"?><worksheet ' . self::NS . '><sheetData>' . str_repeat( '<a>', 20000 ) . str_repeat( '</a>', 20000 ) . '</sheetData></worksheet>';
        $f    = $this->xlsx( [ 'xl/worksheets/sheet1.xml' => $deep ] );
        $r    = MyNJILGA_Spreadsheet_Reader::read( $f, 'deep.xlsx' );
        $this->assertSame( [], $r['rows'] );
        $this->assertTrue( strpos( $r['error'], 'could not be read' ) !== false );
    }

    public function testAnOversizedWorksheetIsRefused(): void {
        if ( ! class_exists( 'ZipArchive' ) ) {
            return;
        }
        // ~31 MB of XML compresses to almost nothing: the cap is on the inflated size.
        $big = '<?xml version="1.0"?><worksheet ' . self::NS . '><sheetData>' . str_repeat( ' ', MyNJILGA_Spreadsheet_Reader::MAX_XML_BYTES + 10 ) . '</sheetData></worksheet>';
        $f   = $this->xlsx( [ 'xl/worksheets/sheet1.xml' => $big ] );
        $r   = MyNJILGA_Spreadsheet_Reader::read( $f, 'bomb.xlsx' );
        $this->assertSame( [], $r['rows'] );
        $this->assertTrue( strpos( $r['error'], 'too large' ) !== false );
    }
}
