<?php
/**
 * Historical Invoice Import — turns a spreadsheet of previous years' dues
 * invoices into njilga_dues_history rows.
 *
 * The pipeline, each step a small function that can be tested on its own:
 *
 *   rows from MyNJILGA_Spreadsheet_Reader
 *     → prepare()      find the header row, map its columns to fields,
 *                      parse every row (money, dates, status, method)
 *     → group()        rows that share an invoice number become ONE invoice
 *                      with several lines; an unnumbered row is its own
 *     → resolve()      match each invoice's firm and member emails to
 *                      FluentCRM Companies and contacts
 *     → history_row()  the row to store
 *
 * The sheet is "one row per invoice line". A firm's invoice for three
 * members is three rows with the same invoice number; a single-member
 * invoice is one row. Invoice-level columns (status, paid date, method…)
 * may be filled on every row or only the first — the first value found
 * wins.
 *
 * Everything except load_directory() and commit() is PURE (no WordPress,
 * no database) and unit tested in tests/HistoricalImportTest.php. A re-run
 * of the same sheet can never duplicate an invoice: each one has a
 * deterministic source_ref, and the table's UNIQUE (source, source_ref)
 * key is the backstop.
 */
class MyNJILGA_Historical_Import {

    /** Most invoices one upload may create. */
    const MAX_INVOICES = 5000;

    const FIELD_LABELS = [
        'invoice_number' => 'Invoice Number',
        'dues_year'      => 'Dues Year',
        'invoice_date'   => 'Invoice Date',
        'due_date'       => 'Due Date',
        'firm'           => 'Firm',
        'email'          => 'Member Email',
        'member_name'    => 'Member Name',
        'description'    => 'Description',
        'amount'         => 'Amount',
        'status'         => 'Status',
        'paid_amount'    => 'Amount Paid',
        'paid_date'      => 'Date Paid',
        'method'         => 'Payment Method',
        'reference'      => 'Reference / Check #',
        'notes'          => 'Notes',
    ];

    /**
     * Header spellings each field answers to, compared after lowercasing
     * and dropping everything but letters and digits ("Invoice #" and
     * "invoice_no" both read as a number). Order matters twice: a header
     * is claimed by the first field that lists it, and a field takes the
     * first matching header in the sheet.
     */
    const ALIASES = [
        'invoice_number' => [ 'invoicenumber', 'invoiceno', 'invoicenum', 'invoiceid', 'invoice', 'inv', 'invno', 'invnumber', 'number', 'no' ],
        'dues_year'      => [ 'duesyear', 'membershipyear', 'invoiceyear', 'fiscalyear', 'year', 'fy', 'period', 'duesperiod' ],
        'invoice_date'   => [ 'invoicedate', 'dateissued', 'issuedate', 'issued', 'billingdate', 'billdate', 'date' ],
        'due_date'       => [ 'duedate', 'datedue', 'paymentdue', 'paymentduedate' ],
        'firm'           => [ 'firm', 'firmname', 'lawfirm', 'company', 'companyname', 'organization', 'organisation', 'employer' ],
        'email'          => [ 'memberemail', 'contactemail', 'billtoemail', 'emailaddress', 'email', 'useremail', 'mail' ],
        'member_name'    => [ 'membername', 'contactname', 'billtoname', 'fullname', 'member', 'attorney', 'payer', 'contact', 'name' ],
        'description'    => [ 'description', 'itemdescription', 'item', 'membershiptype', 'membershiplevel', 'membership', 'level', 'category', 'product', 'service', 'details', 'lineitem' ],
        'amount'         => [ 'invoiceamount', 'invoicetotal', 'duesamount', 'linetotal', 'amount', 'total', 'price', 'charge', 'fee', 'dues', 'amountdue' ],
        'status'         => [ 'paymentstatus', 'invoicestatus', 'paidstatus', 'status', 'state' ],
        'paid_amount'    => [ 'amountpaid', 'paidamount', 'paymentamount', 'amountreceived', 'received', 'paid', 'payment' ],
        'paid_date'      => [ 'datepaid', 'paiddate', 'paymentdate', 'datereceived', 'receiveddate', 'paidon', 'dateofpayment' ],
        'method'         => [ 'paymentmethod', 'paymenttype', 'paytype', 'paidvia', 'paidby', 'method', 'tender' ],
        'reference'      => [ 'referencecheck', 'paymentreference', 'referencenumber', 'checknumber', 'checkno', 'check', 'chk', 'transactionid', 'transaction', 'txn', 'confirmation', 'reference', 'ref', 'receipt' ],
        'notes'          => [ 'notes', 'note', 'memo', 'comments', 'comment', 'remarks' ],
    ];

    /** A sheet needs these to be importable: an amount, a way to find the firm or person, and a year. */
    const REQUIRED = [
        'amount' => 'an Amount column',
    ];

    // -------------------------------------------------------------------------
    // The blank sheet
    // -------------------------------------------------------------------------

    /**
     * The downloadable template: the headers, plus two example rows that
     * show a single-member invoice and a firm invoice split over two lines.
     */
    public static function template_csv(): string {
        $header = [ 'Invoice Number', 'Dues Year', 'Invoice Date', 'Firm', 'Member Email', 'Member Name', 'Description', 'Amount', 'Status', 'Amount Paid', 'Date Paid', 'Payment Method', 'Reference / Check #', 'Notes' ];
        $rows   = [
            [ '2025-0101', '2025', '2025-01-15', 'Smith & Jones LLP', 'ann@smithjones.example', 'Ann Brown', 'Professional Membership', '125.00', 'Paid', '125.00', '2025-02-03', 'Check', '4417', '' ],
            [ '2025-0102', '2025', '2025-01-15', 'Poe & Roe PC', 'chris@poeroe.example', 'Chris Poe', 'Professional Membership', '125.00', 'Paid', '200.00', '2025-02-10', 'Card', '', 'Paid with the next line' ],
            [ '2025-0102', '2025', '2025-01-15', 'Poe & Roe PC', 'pat@poeroe.example', 'Pat Roe', 'Professional Membership', '75.00', '', '', '', '', '', '' ],
        ];
        $h = fopen( 'php://memory', 'r+' );
        fputcsv( $h, $header, ',', '"', '\\' );
        foreach ( $rows as $r ) {
            fputcsv( $h, $r, ',', '"', '\\' );
        }
        rewind( $h );
        $csv = (string) stream_get_contents( $h );
        fclose( $h );
        return "\xEF\xBB\xBF" . $csv; // BOM so Excel opens it as UTF-8.
    }

    // -------------------------------------------------------------------------
    // Headers
    // -------------------------------------------------------------------------

    /**
     * "Invoice #" → "invoice", "Date Paid" → "datepaid".
     */
    public static function norm_header( string $header ): string {
        return (string) preg_replace( '/[^a-z0-9]+/', '', strtolower( $header ) );
    }

    /**
     * Match a header row's columns to fields.
     *
     * @param array<int,string> $header
     * @return array{map:array<string,int>,columns:array<string,string>,unrecognised:array<int,string>,missing:array<int,string>}
     *   map: field → column index; columns: field label → the sheet's own header;
     *   unrecognised: headers nothing claimed (ignored); missing: what the sheet lacks.
     */
    public static function map_headers( array $header ): array {
        $normed  = array_map( [ __CLASS__, 'norm_header' ], array_values( $header ) );
        $claimed = [];
        $map     = [];

        foreach ( self::ALIASES as $field => $aliases ) {
            foreach ( $aliases as $alias ) {
                $alias = self::norm_header( $alias );
                foreach ( $normed as $i => $n ) {
                    if ( $n === $alias && $n !== '' && ! isset( $claimed[ $i ] ) ) {
                        $map[ $field ] = $i;
                        $claimed[ $i ] = true;
                        break 2;
                    }
                }
            }
        }

        $columns = [];
        foreach ( $map as $field => $i ) {
            $columns[ self::FIELD_LABELS[ $field ] ] = (string) $header[ $i ];
        }
        $unrecognised = [];
        foreach ( array_values( $header ) as $i => $h ) {
            if ( ! isset( $claimed[ $i ] ) && trim( (string) $h ) !== '' ) {
                $unrecognised[] = (string) $h;
            }
        }

        $missing = [];
        foreach ( self::REQUIRED as $field => $what ) {
            if ( ! isset( $map[ $field ] ) ) {
                $missing[] = $what;
            }
        }
        if ( ! isset( $map['firm'] ) && ! isset( $map['email'] ) ) {
            $missing[] = 'a Firm or Member Email column (to match each invoice to FluentCRM)';
        }
        if ( ! isset( $map['dues_year'] ) && ! isset( $map['invoice_date'] ) && ! isset( $map['paid_date'] ) ) {
            $missing[] = 'a Dues Year (or Invoice Date) column';
        }

        return [ 'map' => $map, 'columns' => $columns, 'unrecognised' => $unrecognised, 'missing' => $missing ];
    }

    // -------------------------------------------------------------------------
    // Cell parsing
    // -------------------------------------------------------------------------

    /**
     * Money as whole cents. Accepts "$1,250.00", "125", "(125.00)" and
     * "125-" as negatives, "1.250,50" (comma decimal). Null for blank or
     * anything that isn't a number.
     */
    public static function parse_money( string $raw ): ?int {
        $s = trim( $raw );
        if ( $s === '' ) {
            return null;
        }
        $negative = false;
        if ( preg_match( '/^\((.*)\)$/', $s, $m ) ) {
            $negative = true;
            $s        = $m[1];
        }
        $s = (string) preg_replace( '/\b(usd|us\$)\b/i', '', $s );
        $s = str_replace( [ '$', ' ', "\xC2\xA0" ], '', $s );
        if ( substr( $s, -1 ) === '-' ) {
            $negative = true;
            $s        = substr( $s, 0, -1 );
        }
        if ( $s !== '' && $s[0] === '-' ) {
            $negative = ! $negative;
            $s        = substr( $s, 1 );
        }
        if ( $s === '' ) {
            return null;
        }

        $hasDot   = strpos( $s, '.' ) !== false;
        $hasComma = strpos( $s, ',' ) !== false;
        if ( $hasDot && $hasComma ) {
            // The last separator is the decimal point.
            if ( strrpos( $s, ',' ) > strrpos( $s, '.' ) ) {
                $s = str_replace( '.', '', $s );
                $s = str_replace( ',', '.', $s );
            } else {
                $s = str_replace( ',', '', $s );
            }
        } elseif ( $hasComma ) {
            if ( preg_match( '/^\d{1,3}(,\d{3})+$/', $s ) ) {
                $s = str_replace( ',', '', $s );          // 1,250
            } elseif ( preg_match( '/^\d+,\d{1,2}$/', $s ) ) {
                $s = str_replace( ',', '.', $s );         // 125,50
            } else {
                return null;
            }
        }
        if ( ! preg_match( '/^\d+(\.\d+)?$|^\.\d+$/', $s ) ) {
            return null;
        }
        $cents = (int) round( (float) $s * 100 );
        return $negative ? -$cents : $cents;
    }

    /**
     * A date as Y-m-d, or '' when it isn't one. US order for the
     * ambiguous numeric forms (3/4/2025 is March 4th), unless the first
     * number can only be a day.
     */
    public static function parse_date( string $raw ): string {
        $s = trim( $raw );
        if ( $s === '' ) {
            return '';
        }
        // ISO, with or without a time part.
        if ( preg_match( '/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})(?:[ T].*)?$/', $s, $m ) ) {
            return self::ymd( (int) $m[1], (int) $m[2], (int) $m[3] );
        }
        // 3/4/2025, 3-4-25, 3.4.2025 — month first.
        if ( preg_match( '/^(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{2}|\d{4})(?:[ T].*)?$/', $s, $m ) ) {
            $a    = (int) $m[1];
            $b    = (int) $m[2];
            $year = self::full_year( (int) $m[3] );
            if ( $a > 12 && $b <= 12 ) {
                return self::ymd( $year, $b, $a ); // 25/3/2025 can only be day-first.
            }
            return self::ymd( $year, $a, $b );
        }
        // "March 4, 2025", "Mar 4th 2025".
        if ( preg_match( '/^([A-Za-z]{3,9})\.?\s+(\d{1,2})(?:st|nd|rd|th)?,?\s+(\d{4})$/', $s, $m ) ) {
            $mon = self::month_number( $m[1] );
            return $mon > 0 ? self::ymd( (int) $m[3], $mon, (int) $m[2] ) : '';
        }
        // "4 March 2025", "4-Mar-25".
        if ( preg_match( '/^(\d{1,2})[\s\-]([A-Za-z]{3,9})\.?[\s,\-]+(\d{2}|\d{4})$/', $s, $m ) ) {
            $mon = self::month_number( $m[2] );
            return $mon > 0 ? self::ymd( self::full_year( (int) $m[3] ), $mon, (int) $m[1] ) : '';
        }
        return '';
    }

    /**
     * A dues year from "2025", "FY2025", "2025-26", "2025/2026" (the
     * first year named). 0 when there isn't one.
     */
    public static function parse_year( string $raw ): int {
        // Not \b: "FY2025" has no word boundary between the Y and the 2.
        if ( preg_match( '/(?<!\d)(19\d{2}|20\d{2})(?!\d)/', trim( $raw ), $m ) ) {
            return (int) $m[1];
        }
        return 0;
    }

    /**
     * A status word as paid | open | void, or '' when it says nothing.
     *
     * Order matters, because a status cell is free text: "Unpaid" and "Not
     * paid" are checked before "paid" (they contain it); "paid" is checked
     * before the words that merely describe an invoice's history ("Paid -
     * no balance", "Overdue - paid", "Paid (sent 1/2)" are all paid). A
     * partial payment is open — the Amount Paid column carries the rest.
     * "N/A", "TBD" and "-" say nothing, and a lone Y/N is a yes/no flag.
     */
    public static function parse_status( string $raw ): string {
        $s = strtolower( trim( $raw ) );
        if ( $s === '' || preg_match( '#^(n/?a|none|nil|tbd|unknown|\?+|-+|\x{2014}+)$#u', $s ) ) {
            return '';
        }
        if ( in_array( $s, [ 'y', 'yes', 'true', '1' ], true ) ) {
            return MyNJILGA_Dues_History_Table::STATUS_PAID;
        }
        if ( in_array( $s, [ 'n', 'no', 'false', '0' ], true ) ) {
            return MyNJILGA_Dues_History_Table::STATUS_OPEN;
        }
        if ( preg_match( '/\b(unpaid|not\s+(yet\s+)?paid|non-?paid|partial(ly)?|part\s+paid|deposit)\b/', $s ) ) {
            return MyNJILGA_Dues_History_Table::STATUS_OPEN;
        }
        if ( preg_match( '/\b(void|voided|cancel|cancelled|canceled|written off|write off|uncollectible|refunded)\b/', $s ) ) {
            return MyNJILGA_Dues_History_Table::STATUS_VOID;
        }
        if ( preg_match( '/\b(paid|complete|completed|settled|received|closed|success|successful)\b/', $s ) ) {
            return MyNJILGA_Dues_History_Table::STATUS_PAID;
        }
        if ( preg_match( '/\b(open|outstanding|pending|overdue|past\s+due|due|balance|sent|owed)\b/', $s ) ) {
            return MyNJILGA_Dues_History_Table::STATUS_OPEN;
        }
        return '';
    }

    /**
     * A payment-method description as card | check | ach | cash | wire |
     * paypal | other ('' when blank).
     */
    public static function parse_method( string $raw ): string {
        $s = strtolower( trim( $raw ) );
        if ( $s === '' ) {
            return '';
        }
        if ( preg_match( '/\b(check|cheque|chk)\b/', $s ) ) {
            return 'check';
        }
        if ( preg_match( '/\b(paypal)\b/', $s ) ) {
            return 'paypal';
        }
        if ( preg_match( '/\b(ach|bank|eft|direct debit|e-?check)\b/', $s ) ) {
            return 'ach';
        }
        if ( preg_match( '/\b(wire)\b/', $s ) ) {
            return 'wire';
        }
        if ( preg_match( '/\b(cash)\b/', $s ) ) {
            return 'cash';
        }
        if ( preg_match( '/\b(card|visa|mastercard|master card|amex|american express|discover|credit|debit|stripe)\b/', $s ) ) {
            return 'card';
        }
        return 'other';
    }

    /**
     * A firm name reduced to what makes it the same firm: "Smith & Jones,
     * LLP" and "Smith and Jones LLP" share a key. The same rule the online
     * join uses to avoid a duplicate Company.
     */
    public static function firm_key( string $name ): string {
        $n = strtolower( html_entity_decode( $name, ENT_QUOTES ) );
        $n = str_replace( '&', ' and ', $n );
        $n = (string) preg_replace( '/[^a-z0-9 ]+/', ' ', $n );
        $n = (string) preg_replace( '/\b(the|llp|llc|pllc|pc|p c|pa|p a|esq|esqs|ltd|inc|co|company|professional corporation|attorneys at law|attorneys|counselors at law)\b/', ' ', $n );
        return trim( (string) preg_replace( '/\s+/', ' ', $n ) );
    }

    private static function ymd( int $y, int $m, int $d ): string {
        if ( $y < 1990 || $y > 2100 || ! checkdate( $m, $d, $y ) ) {
            return '';
        }
        return sprintf( '%04d-%02d-%02d', $y, $m, $d );
    }

    private static function full_year( int $y ): int {
        if ( $y >= 100 ) {
            return $y;
        }
        return $y < 70 ? 2000 + $y : 1900 + $y;
    }

    private static function month_number( string $name ): int {
        $names = [ 'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12 ];
        return $names[ strtolower( substr( $name, 0, 3 ) ) ] ?? 0;
    }

    // -------------------------------------------------------------------------
    // Sheet → records
    // -------------------------------------------------------------------------

    /**
     * Read a sheet: the first row is the header, every later row a record.
     *
     * @param array<int,array<int,string>> $rows          From MyNJILGA_Spreadsheet_Reader.
     * @param string                       $defaultStatus What a row with no status (and no payment) means: paid | open.
     * @param array<int,int>               $lines         The sheet row number of each of $rows (the reader drops blank rows, so a row's position is not its number); defaults to counting from 1.
     * @return array{map:array<string,int>,columns:array<string,string>,unrecognised:array<int,string>,missing:array<int,string>,records:array<int,array<string,mixed>>,has_status:bool}
     */
    public static function prepare( array $rows, string $defaultStatus, array $lines = [] ): array {
        $rows   = array_values( $rows );
        $lines  = array_values( $lines );
        $header = $rows ? (array) array_shift( $rows ) : [];
        $m      = self::map_headers( $header );
        $m['records']    = [];
        $m['has_status'] = isset( $m['map']['status'] ) || isset( $m['map']['paid_amount'] ) || isset( $m['map']['paid_date'] );
        if ( $m['missing'] ) {
            return $m;
        }

        foreach ( $rows as $i => $row ) {
            // $rows had its heading row removed: row $i is $lines[ $i + 1 ].
            $m['records'][] = self::record( (array) $row, $m['map'], (int) ( $lines[ $i + 1 ] ?? $i + 2 ), $defaultStatus );
        }
        return $m;
    }

    /**
     * One sheet row as a record. Problems that make the row unusable
     * land in 'errors'; things worth a look but not fatal in 'warnings'.
     *
     * @param array<int,string>   $row
     * @param array<string,int>   $map
     * @return array<string,mixed>
     */
    public static function record( array $row, array $map, int $line, string $defaultStatus ): array {
        $get = static function ( string $field ) use ( $row, $map ): string {
            return isset( $map[ $field ] ) ? trim( (string) ( $row[ $map[ $field ] ] ?? '' ) ) : '';
        };

        $errors   = [];
        $warnings = [];

        $amountRaw = $get( 'amount' );
        $amount    = self::parse_money( $amountRaw );
        if ( $amount === null ) {
            $errors[] = $amountRaw === '' ? 'Amount is blank.' : 'Amount "' . $amountRaw . '" is not a number.';
        }

        $invoiceDate = self::parse_date( $get( 'invoice_date' ) );
        $dueDate     = self::parse_date( $get( 'due_date' ) );
        $paidDate    = self::parse_date( $get( 'paid_date' ) );
        foreach ( [ 'invoice_date' => $invoiceDate, 'due_date' => $dueDate, 'paid_date' => $paidDate ] as $field => $parsed ) {
            if ( $parsed === '' && $get( $field ) !== '' ) {
                $warnings[] = self::FIELD_LABELS[ $field ] . ' "' . $get( $field ) . '" was not understood as a date and was left blank.';
            }
        }

        $year = self::parse_year( $get( 'dues_year' ) );
        if ( $year === 0 && $invoiceDate !== '' ) {
            $year = (int) substr( $invoiceDate, 0, 4 );
        }
        if ( $year === 0 && $paidDate !== '' ) {
            $year = (int) substr( $paidDate, 0, 4 );
        }
        if ( $year === 0 ) {
            $errors[] = 'No dues year — add a Dues Year or Invoice Date.';
        }

        $firm  = $get( 'firm' );
        $email = strtolower( $get( 'email' ) );
        if ( $email !== '' && ! preg_match( '/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $email ) ) {
            $warnings[] = 'Email "' . $email . '" does not look like an email address.';
            $email      = '';
        }
        if ( $firm === '' && $email === '' ) {
            $errors[] = 'Neither a firm nor an email — nothing to match this row to.';
        }

        // "Paid" columns are sometimes a Yes/No flag rather than an amount.
        $paidRaw    = $get( 'paid_amount' );
        $paidAmount = self::parse_money( $paidRaw );
        $status     = self::parse_status( $get( 'status' ) );
        if ( $paidAmount === null && $paidRaw !== '' ) {
            $flag = self::parse_status( $paidRaw );
            if ( $status === '' && $flag !== '' ) {
                $status = $flag;
            }
        }

        return [
            'line'           => $line,
            'errors'         => $errors,
            'warnings'       => $warnings,
            'invoice_number' => $get( 'invoice_number' ),
            'year'           => $year,
            'invoice_date'   => $invoiceDate,
            'due_date'       => $dueDate,
            'firm'           => $firm,
            'email'          => $email,
            'member_name'    => $get( 'member_name' ),
            'description'    => $get( 'description' ),
            'amount'         => $amount === null ? 0 : $amount,
            'status'         => $status,
            'paid_amount'    => $paidAmount,
            'paid_date'      => $paidDate,
            'method'         => self::parse_method( $get( 'method' ) ),
            'reference'      => $get( 'reference' ),
            'notes'          => $get( 'notes' ),
            'default_status' => $defaultStatus === MyNJILGA_Dues_History_Table::STATUS_OPEN ? MyNJILGA_Dues_History_Table::STATUS_OPEN : MyNJILGA_Dues_History_Table::STATUS_PAID,
        ];
    }

    // -------------------------------------------------------------------------
    // Records → invoices
    // -------------------------------------------------------------------------

    /**
     * Fold the usable records into invoices. Rows with the same invoice
     * number AND dues year are lines of one invoice; a row with no number
     * is an invoice by itself. Records with errors are not grouped — they
     * are reported by the caller.
     *
     * @param array<int,array<string,mixed>> $records
     * @return array<int,array<string,mixed>>
     */
    public static function group( array $records ): array {
        // A numbered invoice that has a row with an error is incomplete: it
        // must not import with a line missing (and then be skipped as
        // "already imported" when the corrected sheet comes back).
        $damaged = [];
        foreach ( $records as $r ) {
            $number = strtolower( trim( (string) $r['invoice_number'] ) );
            if ( ! empty( $r['errors'] ) && $number !== '' ) {
                $damaged[ $number ][] = (int) $r['line'];
            }
        }

        // The firms named under each (year, number), in sheet order.
        $firmsOf = [];
        foreach ( $records as $r ) {
            $number = trim( (string) $r['invoice_number'] );
            $fk     = self::firm_key( (string) $r['firm'] );
            if ( empty( $r['errors'] ) && $number !== '' && $fk !== '' ) {
                $firmsOf[ (int) $r['year'] . '|' . strtolower( $number ) ][ $fk ] = true;
            }
        }

        $buckets = [];
        $order   = [];
        $shared  = [];
        foreach ( $records as $r ) {
            if ( ! empty( $r['errors'] ) ) {
                continue;
            }
            $number = trim( (string) $r['invoice_number'] );
            if ( $number === '' ) {
                $key = 'row|' . (int) $r['line'];
            } else {
                // Rows under one number but different firms are different
                // invoices — "N/A", "TBD" or a reused number must not merge
                // three firms into one. A row naming no firm belongs to the
                // first firm named under that number.
                $base = (int) $r['year'] . '|' . strtolower( $number );
                $fk   = self::firm_key( (string) $r['firm'] );
                if ( $fk === '' && ! empty( $firmsOf[ $base ] ) ) {
                    $fk = (string) key( $firmsOf[ $base ] );
                }
                $key = $base . '|' . $fk;
                if ( count( $firmsOf[ $base ] ?? [] ) > 1 ) {
                    $shared[ $key ] = count( $firmsOf[ $base ] );
                }
            }
            if ( ! isset( $buckets[ $key ] ) ) {
                $buckets[ $key ] = [];
                $order[]         = $key;
            }
            $buckets[ $key ][] = $r;
        }

        $invoices = [];
        $seen     = [];
        foreach ( $order as $key ) {
            $inv = self::invoice( $buckets[ $key ] );
            if ( isset( $shared[ $key ] ) ) {
                $inv['warnings'][] = 'Invoice number ' . $inv['invoice_number'] . ' is used by ' . $shared[ $key ] . ' different firms; each is imported as its own invoice.';
            }
            $num = strtolower( $inv['invoice_number'] );
            if ( $num !== '' && isset( $damaged[ $num ] ) ) {
                $inv['problems'][] = 'Row' . ( count( $damaged[ $num ] ) > 1 ? 's ' : ' ' ) . implode( ', ', $damaged[ $num ] ) . ' of invoice ' . $inv['invoice_number'] . ' could not be read, so importing it would record the invoice incomplete — fix ' . ( count( $damaged[ $num ] ) > 1 ? 'them' : 'it' ) . ' and upload again.';
            }
            // Two identical unnumbered rows are two invoices; number them in
            // sheet order so the same sheet always yields the same refs.
            $base = $inv['ref'];
            $seen[ $base ] = ( $seen[ $base ] ?? 0 ) + 1;
            if ( $seen[ $base ] > 1 ) {
                $inv['ref'] = $base . '-' . $seen[ $base ];
            }
            $invoices[] = $inv;
        }
        return $invoices;
    }

    /**
     * One invoice from its rows.
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array<string,mixed>
     */
    private static function invoice( array $rows ): array {
        $first = static function ( string $field ) use ( $rows ) {
            foreach ( $rows as $r ) {
                if ( isset( $r[ $field ] ) && $r[ $field ] !== '' && $r[ $field ] !== null ) {
                    return $r[ $field ];
                }
            }
            return '';
        };

        $year   = (int) $rows[0]['year'];
        $lines  = [];
        $total  = 0;
        $firmKeys = [];
        $warnings = [];
        foreach ( $rows as $r ) {
            $title = trim( (string) $r['description'] ) !== '' ? (string) $r['description'] : $year . ' membership dues';
            if ( (string) $r['member_name'] !== '' ) {
                $title = $r['member_name'] . ' — ' . $title;
            }
            $lines[] = [ 'title' => $title, 'amount' => (int) $r['amount'], 'email' => (string) $r['email'], 'member' => (string) $r['member_name'] ];
            $total  += (int) $r['amount'];
            if ( (string) $r['firm'] !== '' ) {
                $firmKeys[ self::firm_key( (string) $r['firm'] ) ] = (string) $r['firm'];
            }
            foreach ( (array) $r['warnings'] as $w ) {
                $warnings[] = 'Row ' . $r['line'] . ': ' . $w;
            }
        }
        if ( count( $firmKeys ) > 1 ) {
            $warnings[] = 'Rows for invoice ' . $rows[0]['invoice_number'] . ' name different firms (' . implode( ' / ', $firmKeys ) . '); the first is used.';
        }

        // Amount paid: filled on every line (sum them) or once (take it).
        $paidValues = [];
        foreach ( $rows as $r ) {
            if ( $r['paid_amount'] !== null ) {
                $paidValues[] = (int) $r['paid_amount'];
            }
        }
        $paid = null;
        if ( $paidValues ) {
            $sum  = array_sum( $paidValues );
            $paid = ( count( $paidValues ) === count( $rows ) && count( $rows ) > 1 && $sum <= $total ) ? $sum : $paidValues[0];
        }

        $explicit = (string) $first( 'status' );
        $default  = (string) $rows[0]['default_status'];
        [ $status, $paidCents, $note ] = self::settle( $explicit, $default, $total, $paid );
        if ( $note !== '' ) {
            $warnings[] = $note;
        }

        $invoiceDate = (string) $first( 'invoice_date' );
        $paidDate    = (string) $first( 'paid_date' );
        $number      = trim( (string) $rows[0]['invoice_number'] );

        $members = [];
        foreach ( $lines as $l ) {
            $members[ $l['email'] !== '' ? $l['email'] : $l['member'] ] = true;
        }
        unset( $members[''] );
        if ( count( $lines ) === 1 ) {
            $description = trim( (string) $rows[0]['description'] ) !== '' ? (string) $rows[0]['description'] : $year . ' membership dues';
        } else {
            $description = sprintf( '%d membership dues — %d lines', $year, count( $lines ) );
            if ( count( $members ) > 1 ) {
                $description = sprintf( '%d membership dues — %d members', $year, count( $members ) );
            }
        }

        return [
            'ref'            => self::source_ref( $number, $year, $rows ),
            'invoice_number' => $number,
            'year'           => $year,
            'invoice_date'   => $invoiceDate,
            'due_date'       => (string) $first( 'due_date' ),
            'firm'           => (string) $first( 'firm' ),
            'email'          => (string) $first( 'email' ),
            'member_name'    => (string) $first( 'member_name' ),
            'description'    => $description,
            'lines'          => $lines,
            'total_cents'    => $total,
            'paid_cents'     => $paidCents,
            'status'         => $status,
            'paid_date'      => $status === MyNJILGA_Dues_History_Table::STATUS_PAID || $paidCents > 0 ? ( $paidDate !== '' ? $paidDate : $invoiceDate ) : '',
            'method'         => (string) $first( 'method' ),
            'reference'      => (string) $first( 'reference' ),
            'notes'          => (string) $first( 'notes' ),
            'rows'           => array_map( static function ( $r ) {
                return (int) $r['line'];
            }, $rows ),
            'warnings'       => $warnings,
            'problems'       => [],
        ];
    }

    /**
     * What an invoice's status and paid amount come to, from what the
     * sheet said.
     *
     *   • "void" → void, nothing paid.
     *   • "paid" (or no status and the default is paid): paid in full,
     *     unless an Amount Paid column says less — then it is recorded
     *     as part-paid (open) with a note, because the amount is the
     *     harder fact.
     *   • "open" (or no status and the default is open): open, with
     *     whatever was paid so far; if that covers the total, it is paid.
     *
     * @return array{0:string,1:int,2:string} [ status, paid cents, note ]
     */
    public static function settle( string $explicit, string $default, int $total, ?int $paid ): array {
        $effective = $explicit !== '' ? $explicit : $default;

        if ( $explicit === MyNJILGA_Dues_History_Table::STATUS_VOID ) {
            return [ MyNJILGA_Dues_History_Table::STATUS_VOID, 0, '' ];
        }
        if ( $effective === MyNJILGA_Dues_History_Table::STATUS_PAID ) {
            if ( $paid !== null && $paid < $total ) {
                return [ MyNJILGA_Dues_History_Table::STATUS_OPEN, max( 0, $paid ), 'Marked paid but the amount paid is less than the total — recorded as partly paid (open).' ];
            }
            return [ MyNJILGA_Dues_History_Table::STATUS_PAID, $paid !== null ? $paid : $total, '' ];
        }
        $paidSoFar = max( 0, (int) $paid );
        if ( $total > 0 && $paidSoFar >= $total ) {
            return [ MyNJILGA_Dues_History_Table::STATUS_PAID, $paidSoFar, '' ];
        }
        return [ MyNJILGA_Dues_History_Table::STATUS_OPEN, $paidSoFar, '' ];
    }

    /**
     * The idempotency key for an invoice. Numbered: year/number, plus the
     * firm when the sheet names one — an invoice number is only unique
     * within a firm in a hand-kept sheet ("N/A", a number reused by two
     * firms), and a re-upload must find the same invoice again. Unnumbered:
     * a hash of what the row says, so the same sheet uploaded twice
     * produces the same keys.
     *
     * @param array<int,array<string,mixed>> $rows
     */
    public static function source_ref( string $number, int $year, array $rows ): string {
        if ( $number !== '' ) {
            $fk = '';
            foreach ( $rows as $r ) {
                $fk = self::firm_key( (string) $r['firm'] );
                if ( $fk !== '' ) {
                    break;
                }
            }
            return substr( $year . '/' . strtolower( $number ) . ( $fk !== '' ? '|' . $fk : '' ), 0, 100 );
        }
        $parts = [ (string) $year ];
        foreach ( $rows as $r ) {
            $parts[] = implode( '|', [ self::firm_key( (string) $r['firm'] ), (string) $r['email'], (string) $r['invoice_date'], (string) $r['paid_date'], (string) $r['amount'], strtolower( (string) $r['description'] ), strtolower( (string) $r['member_name'] ) ] );
        }
        return 'row-' . substr( md5( implode( "\n", $parts ) ), 0, 20 );
    }

    // -------------------------------------------------------------------------
    // Matching to FluentCRM
    // -------------------------------------------------------------------------

    /**
     * Match one invoice to a firm and people. PURE: the FluentCRM data
     * arrives as arrays (see load_directory()).
     *
     * @param array<string,mixed>                              $inv
     * @param array<string,array{id:int,name:string,company_id:int}> $contactsByEmail lowercase email → contact
     * @param array<string,array<int,int>>                     $companyIdsByKey   firm_key → company ids
     * @param array<int,string>                                $companyNames      company id → name
     * @return array{company_id:int,company_name:string,contact_id:int,contact_name:string,contact_email:string,line_contacts:array<int,int>,problems:array<int,string>,warnings:array<int,string>}
     */
    public static function resolve( array $inv, array $contactsByEmail, array $companyIdsByKey, array $companyNames ): array {
        $problems = [];
        $warnings = [];

        // People first: they can also settle which firm.
        $lineContacts = [];
        $billTo       = null;
        foreach ( $inv['lines'] as $i => $line ) {
            $email = (string) $line['email'];
            if ( $email !== '' && isset( $contactsByEmail[ $email ] ) ) {
                $lineContacts[ $i ] = (int) $contactsByEmail[ $email ]['id'];
                if ( $billTo === null ) {
                    $billTo = $contactsByEmail[ $email ];
                }
            }
        }
        $emailsNotFound = [];
        foreach ( $inv['lines'] as $line ) {
            if ( $line['email'] !== '' && ! isset( $contactsByEmail[ $line['email'] ] ) ) {
                $emailsNotFound[ $line['email'] ] = true;
            }
        }
        if ( $emailsNotFound ) {
            $warnings[] = 'No FluentCRM contact for ' . implode( ', ', array_keys( $emailsNotFound ) ) . '.';
        }

        // The firm.
        $companyId = 0;
        $firm      = trim( (string) $inv['firm'] );
        if ( $firm !== '' ) {
            $candidates = $companyIdsByKey[ self::firm_key( $firm ) ] ?? [];
            if ( count( $candidates ) > 1 ) {
                $exact = array_values( array_filter( $candidates, static function ( $id ) use ( $companyNames, $firm ) {
                    return strcasecmp( (string) ( $companyNames[ $id ] ?? '' ), $firm ) === 0;
                } ) );
                $candidates = count( $exact ) === 1 ? $exact : $candidates;
            }
            if ( count( $candidates ) === 1 ) {
                $companyId = (int) $candidates[0];
            } elseif ( count( $candidates ) > 1 ) {
                $problems[] = 'More than one FluentCRM Company matches the firm "' . $firm . '" — rename one so they differ.';
            } else {
                $warnings[] = 'No FluentCRM Company named "' . $firm . '".';
            }
        }
        // No (usable) firm in the sheet: the person's own firm.
        if ( $companyId === 0 && ! $problems && $billTo !== null && (int) $billTo['company_id'] > 0 ) {
            $companyId = (int) $billTo['company_id'];
            if ( $firm !== '' ) {
                $warnings[] = 'Filed under ' . ( $companyNames[ $companyId ] ?? 'their firm' ) . ', the firm FluentCRM has for ' . $billTo['name'] . '.';
            }
        }

        $contactId = $billTo !== null ? (int) $billTo['id'] : 0;
        if ( $companyId === 0 && $contactId === 0 && ! $problems ) {
            $problems[] = $firm !== ''
                ? 'Could not match the firm "' . $firm . '" or any email to FluentCRM.'
                : 'Could not match any email to a FluentCRM contact.';
        }

        $name = $billTo !== null ? (string) $billTo['name'] : (string) $inv['member_name'];
        return [
            'company_id'    => $companyId,
            'company_name'  => $companyId > 0 ? (string) ( $companyNames[ $companyId ] ?? $firm ) : $firm,
            'contact_id'    => $contactId,
            'contact_name'  => $name,
            'contact_email' => (string) $inv['email'],
            'line_contacts' => $lineContacts,
            'problems'      => $problems,
            'warnings'      => $warnings,
        ];
    }

    /**
     * The njilga_dues_history row for a matched invoice.
     *
     * @param array<string,mixed> $inv
     * @param array<string,mixed> $resolved From resolve().
     * @return array<string,mixed>
     */
    public static function history_row( array $inv, array $resolved, string $batchId, int $userId ): array {
        $lines = [];
        $ids   = [ (int) $resolved['contact_id'] ];
        foreach ( $inv['lines'] as $i => $l ) {
            $cid = (int) ( $resolved['line_contacts'][ $i ] ?? 0 );
            $ids[] = $cid;
            $lines[] = [ 'title' => (string) $l['title'], 'amount' => (int) $l['amount'], 'contact_id' => $cid ];
        }
        return [
            'source'         => MyNJILGA_Dues_History_Table::SOURCE_IMPORT,
            'source_ref'     => (string) $inv['ref'],
            'batch_id'       => $batchId,
            'dues_year'      => (int) $inv['year'],
            'company_id'     => (int) $resolved['company_id'],
            'company_name'   => (string) $resolved['company_name'],
            'contact_id'     => (int) $resolved['contact_id'],
            'contact_name'   => (string) $resolved['contact_name'],
            'contact_email'  => (string) $resolved['contact_email'],
            'member_ids'     => MyNJILGA_Dues_History::member_ids_column( $ids ),
            'invoice_number' => (string) $inv['invoice_number'],
            'description'    => (string) $inv['description'],
            'line_items'     => (string) json_encode( $lines ),
            'status'         => (string) $inv['status'],
            'total_cents'    => (int) $inv['total_cents'],
            'paid_cents'     => (int) $inv['paid_cents'],
            'invoice_date'   => (string) $inv['invoice_date'],
            'due_date'       => (string) $inv['due_date'],
            'paid_at'        => (string) $inv['paid_date'],
            'method'         => (string) $inv['method'],
            'method_detail'  => '',
            'reference'      => (string) $inv['reference'],
            'notes'          => (string) $inv['notes'],
            'created_by'     => $userId,
        ];
    }

    // -------------------------------------------------------------------------
    // Database / FluentCRM — thin; the logic above is what is tested
    // -------------------------------------------------------------------------

    /**
     * Load what resolve() needs for these invoices: the contacts behind
     * every email in the sheet, and every FluentCRM Company.
     *
     * @param array<int,array<string,mixed>> $invoices
     * @return array{contacts:array<string,array{id:int,name:string,company_id:int}>,companyKeys:array<string,array<int,int>>,companyNames:array<int,string>}
     */
    public static function load_directory( array $invoices ): array {
        $emails = [];
        foreach ( $invoices as $inv ) {
            foreach ( $inv['lines'] as $l ) {
                if ( $l['email'] !== '' ) {
                    $emails[ $l['email'] ] = true;
                }
            }
        }

        $contacts = [];
        foreach ( array_chunk( array_keys( $emails ), 200 ) as $chunk ) {
            foreach ( \FluentCrm\App\Models\Subscriber::whereIn( 'email', $chunk )->get() as $s ) {
                $contacts[ strtolower( (string) $s->email ) ] = [
                    'id'         => (int) $s->id,
                    'name'       => MyNJILGA_Members_Data::display_name( $s ),
                    'company_id' => (int) $s->company_id,
                ];
            }
        }

        $keys  = [];
        $names = [];
        if ( MyNJILGA_Members_Data::companies_module_active() ) {
            foreach ( \FluentCrm\App\Models\Company::select( [ 'id', 'name' ] )->get() as $c ) {
                $names[ (int) $c->id ] = (string) $c->name;
                $key                   = self::firm_key( (string) $c->name );
                if ( $key !== '' ) {
                    $keys[ $key ][] = (int) $c->id;
                }
            }
        }
        return [ 'contacts' => $contacts, 'companyKeys' => $keys, 'companyNames' => $names ];
    }

    /**
     * Match every invoice and say what an import would do with it.
     *
     * state: new | duplicate (already imported) | blocked (cannot be
     * matched, so it would be invisible on every tab).
     *
     * @param array<int,array<string,mixed>> $invoices
     * @return array<int,array{inv:array<string,mixed>,resolved:array<string,mixed>,state:string}>
     */
    public static function plan( array $invoices ): array {
        $dir      = self::load_directory( $invoices );
        $existing = MyNJILGA_Dues_History_Table::existing_refs( MyNJILGA_Dues_History_Table::SOURCE_IMPORT, array_column( $invoices, 'ref' ) );
        $out      = [];
        foreach ( $invoices as $inv ) {
            $resolved = self::resolve( $inv, $dir['contacts'], $dir['companyKeys'], $dir['companyNames'] );
            // An invoice that lost a line to an unreadable row is blocked too.
            $resolved['problems'] = array_merge( (array) ( $inv['problems'] ?? [] ), $resolved['problems'] );
            $state    = $resolved['problems'] ? 'blocked' : ( isset( $existing[ $inv['ref'] ] ) ? 'duplicate' : 'new' );
            $out[]    = [ 'inv' => $inv, 'resolved' => $resolved, 'state' => $state ];
        }
        return $out;
    }

    /**
     * Write the plan's new invoices.
     *
     * @param array<int,array{inv:array<string,mixed>,resolved:array<string,mixed>,state:string}> $plan
     * @return array{created:int,duplicate:int,blocked:int,failed:int}
     */
    public static function commit( array $plan, string $batchId, int $userId ): array {
        $out = [ 'created' => 0, 'duplicate' => 0, 'blocked' => 0, 'failed' => 0 ];
        foreach ( $plan as $p ) {
            if ( $p['state'] === 'blocked' ) {
                $out['blocked']++;
                continue;
            }
            if ( $p['state'] === 'duplicate' ) {
                $out['duplicate']++;
                continue;
            }
            $id = MyNJILGA_Dues_History_Table::insert( self::history_row( $p['inv'], $p['resolved'], $batchId, $userId ) );
            if ( $id > 0 ) {
                $out['created']++;
            } else {
                $out['failed']++;
            }
        }
        return $out;
    }

    /**
     * A fresh, readable id for one import run.
     */
    public static function batch_id( string $source ): string {
        return $source . '-' . gmdate( 'Ymd-His' ) . '-' . substr( md5( uniqid( '', true ) ), 0, 4 );
    }
}
