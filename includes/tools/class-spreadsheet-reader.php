<?php
/**
 * Reads an uploaded spreadsheet — CSV, or Excel's .xlsx — into plain rows
 * of text. No third-party library: a CSV is read with fgetcsv(), and an
 * .xlsx (a zip of XML files) with ZipArchive + DOMDocument.
 *
 * Everything comes back as strings, trimmed, in sheet order, with blank
 * rows dropped; the importer decides what each column means. Dates in an
 * .xlsx are stored as serial numbers, so a cell whose number format is a
 * date is converted to Y-m-d here, where the format is known, rather than
 * left as 45678 for the importer to guess at.
 *
 * Hard limits keep a hostile or just enormous file from exhausting the
 * server: a row cap, a cap on how large a worksheet may inflate to, and no
 * DOCTYPE at all (so no entities of any kind) with libxml's own nesting
 * limits left in force.
 *
 * Pure apart from reading the one file it is handed — no WordPress.
 */
class MyNJILGA_Spreadsheet_Reader {

    /** Most rows read from one sheet (header included). */
    const MAX_ROWS = 10000;

    /** Most bytes any one XML part may inflate to. */
    const MAX_XML_BYTES = 31457280; // 30 MB

    /** Most columns kept from a row. */
    const MAX_COLS = 60;

    /**
     * Read a spreadsheet by its filename's extension.
     *
     * @return array{rows:array<int,array<int,string>>,error:string}
     */
    public static function read( string $path, string $filename ): array {
        $ext = strtolower( (string) pathinfo( $filename, PATHINFO_EXTENSION ) );
        if ( ! is_readable( $path ) ) {
            return self::fail( 'The uploaded file could not be read.' );
        }
        if ( $ext === 'xlsx' ) {
            return self::read_xlsx( $path );
        }
        if ( $ext === 'csv' || $ext === 'txt' || $ext === 'tsv' ) {
            return self::read_csv( $path );
        }
        if ( $ext === 'xls' ) {
            return self::fail( 'Older .xls files can\'t be read. In Excel choose File → Save As → "CSV UTF-8" (or .xlsx) and upload that.' );
        }
        return self::fail( 'Upload a .csv or .xlsx file.' );
    }

    // -------------------------------------------------------------------------
    // CSV
    // -------------------------------------------------------------------------

    /**
     * @return array{rows:array<int,array<int,string>>,error:string}
     */
    public static function read_csv( string $path ): array {
        $raw = file_get_contents( $path, false, null, 0, 20971520 ); // 20 MB is far past any real sheet.
        if ( $raw === false ) {
            return self::fail( 'The uploaded file could not be read.' );
        }
        return [ 'rows' => self::csv_rows( $raw ), 'error' => '' ];
    }

    /**
     * Parse CSV text. Strips a UTF-8 byte-order mark, picks the delimiter
     * (comma, semicolon or tab) from the first line, and converts a
     * Windows-1252 file — what Excel's plain "CSV" save produces — to UTF-8.
     *
     * @return array<int,array<int,string>>
     */
    public static function csv_rows( string $text ): array {
        if ( strncmp( $text, "\xEF\xBB\xBF", 3 ) === 0 ) {
            $text = substr( $text, 3 );
        }
        if ( function_exists( 'mb_check_encoding' ) && ! mb_check_encoding( $text, 'UTF-8' ) ) {
            $text = (string) mb_convert_encoding( $text, 'UTF-8', 'Windows-1252' );
        }

        $firstLine = (string) strtok( $text, "\n" );
        $best      = ',';
        $bestCount = -1;
        foreach ( [ ',', ';', "\t" ] as $d ) {
            $n = substr_count( $firstLine, $d );
            if ( $n > $bestCount ) {
                $best      = $d;
                $bestCount = $n;
            }
        }

        $h = fopen( 'php://memory', 'r+' );
        if ( ! $h ) {
            return [];
        }
        fwrite( $h, $text );
        rewind( $h );

        $rows = [];
        while ( ( $cells = fgetcsv( $h, 0, $best, '"', '\\' ) ) !== false ) {
            $row = self::clean_row( array_map( static function ( $c ) {
                return (string) $c;
            }, $cells ) );
            if ( $row !== null ) {
                $rows[] = $row;
                if ( count( $rows ) >= self::MAX_ROWS ) {
                    break;
                }
            }
        }
        fclose( $h );
        return $rows;
    }

    // -------------------------------------------------------------------------
    // XLSX
    // -------------------------------------------------------------------------

    /**
     * @return array{rows:array<int,array<int,string>>,error:string}
     */
    public static function read_xlsx( string $path ): array {
        if ( ! class_exists( 'ZipArchive' ) || ! class_exists( 'DOMDocument' ) ) {
            return self::fail( 'This server can\'t open .xlsx files (the PHP zip extension is off). In Excel choose File → Save As → "CSV UTF-8" and upload that instead.' );
        }
        $zip = new ZipArchive();
        if ( $zip->open( $path ) !== true ) {
            return self::fail( 'That file is not a valid .xlsx workbook.' );
        }

        try {
            $sheetPath = self::first_sheet_path( $zip );
            $sheetXml  = self::zip_text( $zip, $sheetPath );
            if ( $sheetXml === null ) {
                return self::fail( 'That workbook has no readable worksheet.' );
            }

            $strings   = self::shared_strings( self::zip_text( $zip, 'xl/sharedStrings.xml' ) );
            $dateXfs   = self::date_styles( self::zip_text( $zip, 'xl/styles.xml' ) );
            $workbook  = (string) self::zip_text( $zip, 'xl/workbook.xml' );
            $date1904  = (bool) preg_match( '/<workbookPr[^>]*\bdate1904\s*=\s*"(?:1|true)"/i', $workbook );

            $rows = self::sheet_rows( $sheetXml, $strings, $dateXfs, $date1904 );
        } catch ( \Throwable $e ) {
            $zip->close();
            return self::fail( 'That workbook could not be read: ' . $e->getMessage() );
        }
        $zip->close();
        return [ 'rows' => $rows, 'error' => '' ];
    }

    /**
     * The first worksheet's path inside the zip: the first <sheet> in
     * workbook.xml resolved through its relationship, falling back to the
     * lowest-numbered worksheet part.
     */
    private static function first_sheet_path( ZipArchive $zip ): string {
        $workbook = self::zip_text( $zip, 'xl/workbook.xml' );
        $rels     = self::zip_text( $zip, 'xl/_rels/workbook.xml.rels' );
        if ( $workbook !== null && $rels !== null ) {
            $wb  = self::dom( $workbook );
            $rel = self::dom( $rels );
            $sheets = $wb->getElementsByTagNameNS( '*', 'sheet' );
            if ( $sheets->length > 0 ) {
                $first = $sheets->item( 0 );
                $rid   = '';
                foreach ( $first->attributes as $attr ) {
                    if ( $attr->localName === 'id' ) { // r:id
                        $rid = (string) $attr->value;
                    }
                }
                if ( $rid !== '' ) {
                    foreach ( $rel->getElementsByTagNameNS( '*', 'Relationship' ) as $r ) {
                        if ( $r->getAttribute( 'Id' ) === $rid ) {
                            $target = (string) $r->getAttribute( 'Target' );
                            $target = ltrim( $target, '/' );
                            return strpos( $target, 'xl/' ) === 0 ? $target : 'xl/' . $target;
                        }
                    }
                }
            }
        }
        $names = [];
        for ( $i = 0; $i < $zip->numFiles; $i++ ) {
            $name = (string) $zip->getNameIndex( $i );
            if ( preg_match( '#^xl/worksheets/sheet\d+\.xml$#', $name ) ) {
                $names[] = $name;
            }
        }
        natsort( $names );
        return $names ? (string) reset( $names ) : 'xl/worksheets/sheet1.xml';
    }

    /**
     * One part of the zip as text, or null when it is missing or would
     * inflate past the cap.
     */
    private static function zip_text( ZipArchive $zip, string $name ): ?string {
        $stat = $zip->statName( $name );
        if ( ! $stat ) {
            return null;
        }
        if ( (int) $stat['size'] > self::MAX_XML_BYTES ) {
            throw new \RuntimeException( 'the worksheet is too large' );
        }
        $data = $zip->getFromName( $name );
        return $data === false ? null : $data;
    }

    private static function dom( string $xml ): DOMDocument {
        // A spreadsheet's XML never has a DOCTYPE — a DOCTYPE is how
        // entity attacks (external files, "billion laughs" expansion) are
        // delivered, so it is refused outright rather than defused. A NUL
        // byte means UTF-16, whose DOCTYPE the check above could not see.
        if ( stripos( $xml, '<!DOCTYPE' ) !== false || stripos( $xml, '<!ENTITY' ) !== false ) {
            throw new \RuntimeException( 'the file contains a DOCTYPE declaration, which a spreadsheet never needs' );
        }
        if ( strpos( $xml, "\0" ) !== false ) {
            throw new \RuntimeException( 'the file is not UTF-8 XML' );
        }
        // PHP 8 never resolves external entities; 7.4 needs telling.
        if ( PHP_VERSION_ID < 80000 && function_exists( 'libxml_disable_entity_loader' ) ) {
            libxml_disable_entity_loader( true ); // phpcs:ignore PHPCompatibility.FunctionUse.RemovedFunctions
        }
        $prev = libxml_use_internal_errors( true );
        // No LIBXML_PARSEHUGE: libxml's own depth and node-size limits are
        // the protection against a pathologically nested document, and a
        // real sheet is a few levels deep.
        $doc = new DOMDocument();
        $ok  = $doc->loadXML( $xml, LIBXML_NONET | LIBXML_COMPACT );
        libxml_clear_errors();
        libxml_use_internal_errors( $prev );
        if ( ! $ok ) {
            throw new \RuntimeException( 'the file contains invalid XML' );
        }
        return $doc;
    }

    /**
     * @return array<int,string>
     */
    private static function shared_strings( ?string $xml ): array {
        if ( $xml === null ) {
            return [];
        }
        $out = [];
        foreach ( self::dom( $xml )->getElementsByTagNameNS( '*', 'si' ) as $si ) {
            $out[] = self::rich_text( $si );
        }
        return $out;
    }

    /**
     * The text of a shared string or inline string: its <t> runs joined,
     * leaving out phonetic (<rPh>) runs.
     */
    private static function rich_text( DOMNode $node ): string {
        $text = '';
        foreach ( $node->childNodes as $child ) {
            if ( ! $child instanceof DOMElement ) {
                continue;
            }
            if ( $child->localName === 't' ) {
                $text .= $child->textContent;
            } elseif ( $child->localName === 'r' ) {
                foreach ( $child->childNodes as $run ) {
                    if ( $run instanceof DOMElement && $run->localName === 't' ) {
                        $text .= $run->textContent;
                    }
                }
            }
        }
        return $text;
    }

    /**
     * Which cell-format indexes (the `s` attribute on a cell) are dates.
     *
     * @return array<int,bool> xf index => true
     */
    private static function date_styles( ?string $xml ): array {
        if ( $xml === null ) {
            return [];
        }
        $doc    = self::dom( $xml );
        $custom = [];
        foreach ( $doc->getElementsByTagNameNS( '*', 'numFmt' ) as $f ) {
            $custom[ (int) $f->getAttribute( 'numFmtId' ) ] = (string) $f->getAttribute( 'formatCode' );
        }
        $out = [];
        foreach ( $doc->getElementsByTagNameNS( '*', 'cellXfs' ) as $xfs ) {
            $i = 0;
            foreach ( $xfs->childNodes as $xf ) {
                if ( ! $xf instanceof DOMElement || $xf->localName !== 'xf' ) {
                    continue;
                }
                $id = (int) $xf->getAttribute( 'numFmtId' );
                if ( self::is_date_format( $id, $custom[ $id ] ?? '' ) ) {
                    $out[ $i ] = true;
                }
                $i++;
            }
            break; // Only the first cellXfs block is the cell formats.
        }
        return $out;
    }

    /**
     * @param array<int,string>  $strings
     * @param array<int,bool>    $dateXfs
     * @return array<int,array<int,string>>
     */
    private static function sheet_rows( string $xml, array $strings, array $dateXfs, bool $date1904 ): array {
        $rows = [];
        foreach ( self::dom( $xml )->getElementsByTagNameNS( '*', 'row' ) as $rowEl ) {
            $cells = [];
            $next  = 0;
            foreach ( $rowEl->childNodes as $c ) {
                if ( ! $c instanceof DOMElement || $c->localName !== 'c' ) {
                    continue;
                }
                $ref = (string) $c->getAttribute( 'r' );
                $col = $ref !== '' ? self::col_index( $ref ) : $next;
                $next = $col + 1;
                if ( $col >= self::MAX_COLS ) {
                    continue;
                }
                $cells[ $col ] = self::cell_value( $c, $strings, $dateXfs, $date1904 );
            }
            if ( ! $cells ) {
                continue;
            }
            $width = max( array_keys( $cells ) ) + 1;
            $row   = [];
            for ( $i = 0; $i < $width; $i++ ) {
                $row[ $i ] = $cells[ $i ] ?? '';
            }
            $row = self::clean_row( $row );
            if ( $row !== null ) {
                $rows[] = $row;
                if ( count( $rows ) >= self::MAX_ROWS ) {
                    break;
                }
            }
        }
        return $rows;
    }

    /**
     * @param array<int,string> $strings
     * @param array<int,bool>   $dateXfs
     */
    private static function cell_value( DOMElement $c, array $strings, array $dateXfs, bool $date1904 ): string {
        $type = (string) $c->getAttribute( 't' );
        $v    = '';
        $isEl = null;
        foreach ( $c->childNodes as $child ) {
            if ( ! $child instanceof DOMElement ) {
                continue;
            }
            if ( $child->localName === 'v' ) {
                $v = (string) $child->textContent;
            } elseif ( $child->localName === 'is' ) {
                $isEl = $child;
            }
        }

        switch ( $type ) {
            case 's':
                return $strings[ (int) $v ] ?? '';
            case 'inlineStr':
                return $isEl ? self::rich_text( $isEl ) : '';
            case 'str':
                return $v;
            case 'b':
                return $v === '1' ? 'TRUE' : 'FALSE';
            case 'e':
                return '';
        }

        if ( $v === '' || ! is_numeric( $v ) ) {
            return $v;
        }
        $style = (int) $c->getAttribute( 's' );
        if ( ! empty( $dateXfs[ $style ] ) ) {
            $date = self::serial_to_date( (float) $v, $date1904 );
            if ( $date !== '' ) {
                return $date;
            }
        }
        return self::clean_number( $v );
    }

    // -------------------------------------------------------------------------
    // Pure helpers (unit tested)
    // -------------------------------------------------------------------------

    /**
     * Column index from a cell reference: "A1" → 0, "B7" → 1, "AA3" → 26.
     */
    public static function col_index( string $ref ): int {
        if ( ! preg_match( '/^([A-Za-z]+)/', $ref, $m ) ) {
            return 0;
        }
        $n = 0;
        foreach ( str_split( strtoupper( $m[1] ) ) as $ch ) {
            $n = $n * 26 + ( ord( $ch ) - 64 );
        }
        return $n - 1;
    }

    /**
     * Whether a cell number format shows a date or a time. Excel's
     * built-in date ids are fixed numbers; anything else is a custom
     * format code, which is a date when it contains a d, m, y, h or s
     * outside quoted text and [bracketed] parts.
     */
    public static function is_date_format( int $numFmtId, string $formatCode ): bool {
        if ( ( $numFmtId >= 14 && $numFmtId <= 22 ) || ( $numFmtId >= 27 && $numFmtId <= 36 ) || ( $numFmtId >= 45 && $numFmtId <= 47 ) || ( $numFmtId >= 50 && $numFmtId <= 58 ) ) {
            return true;
        }
        if ( $formatCode === '' ) {
            return false;
        }
        $code = (string) preg_replace( '/"[^"]*"|\[[^\]]*\]|\\\\./', '', $formatCode );
        return (bool) preg_match( '/[dmyhs]/i', $code );
    }

    /**
     * An Excel serial date as Y-m-d (or Y-m-d H:i:s when it carries a
     * time). '' for serials before 1900-03-01, where Excel's own calendar
     * has a leap-year bug no real dues sheet reaches.
     */
    public static function serial_to_date( float $serial, bool $date1904 = false ): string {
        if ( $date1904 ) {
            $serial += 1462;
        }
        if ( $serial < 61 ) {
            return '';
        }
        $seconds = (int) round( ( $serial - 25569 ) * 86400 );
        $day     = gmdate( 'Y-m-d', $seconds );
        $time    = $seconds % 86400;
        return $time === 0 ? $day : $day . ' ' . gmdate( 'H:i:s', $seconds );
    }

    /**
     * A numeric cell's text without float noise: Excel stores 125.45 as
     * 125.45000000000001, and a person reading the sheet sees 125.45.
     */
    public static function clean_number( string $v ): string {
        if ( ! is_numeric( $v ) ) {
            return $v;
        }
        $f = (float) $v;
        if ( abs( $f ) >= 1e15 ) {
            return $v;
        }
        $s = rtrim( rtrim( number_format( $f, 8, '.', '' ), '0' ), '.' );
        return ( $s === '' || $s === '-0' ) ? '0' : $s;
    }

    /**
     * Trim a row's cells, cap its width, and drop trailing blank cells.
     * Null for a row with nothing in it.
     *
     * @param array<int,string> $cells
     * @return array<int,string>|null
     */
    public static function clean_row( array $cells ): ?array {
        $cells = array_slice( array_values( $cells ), 0, self::MAX_COLS );
        $cells = array_map( static function ( $c ) {
            return trim( (string) $c );
        }, $cells );
        while ( $cells && end( $cells ) === '' ) {
            array_pop( $cells );
        }
        return $cells ? $cells : null;
    }

    /**
     * @return array{rows:array<int,array<int,string>>,error:string}
     */
    private static function fail( string $message ): array {
        return [ 'rows' => [], 'error' => $message ];
    }
}
