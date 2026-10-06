<?php
/**
 * My NJILGA → Tools → Historical Invoice Import.
 *
 *   1. UPLOAD  a CSV or .xlsx of earlier years' invoices (admin-post, nonce).
 *              The file is parsed in that request and never stored; what is
 *              kept, for an hour and for that user only, is the parsed
 *              invoices — in a transient, so the next step needs no file.
 *   2. REVIEW  every invoice matched to a FluentCRM firm and contact, with
 *              what an import would do with it and anything that needs a
 *              look. Nothing is written.
 *   3. IMPORT  a nonce-protected POST that creates the history invoices.
 *
 * The matching is redone at import time from the stored invoices, so what
 * is written is always checked against FluentCRM as it is at that moment.
 */
class MyNJILGA_Page_Tool_Import {

    const ACTION_UPLOAD   = 'my_njilga_import_upload';
    const ACTION_COMMIT   = 'my_njilga_import_commit';
    const ACTION_TEMPLATE = 'my_njilga_import_template';

    /** Largest file accepted, in bytes. */
    const MAX_BYTES = 5242880; // 5 MB

    /** How long a parsed upload waits for its review/confirm. */
    const TOKEN_TTL = HOUR_IN_SECONDS;

    /** Rows each review table prints. */
    const SHOW = 100;

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Access denied.' );
        }

        MyNJILGA_Admin_UI::styles();
        echo '<div class="wrap njilga-ui">';
        MyNJILGA_Admin_Menu::render_back_to_tools();
        MyNJILGA_Admin_UI::page_header( 'Historical Invoice Import', 'Upload a spreadsheet of earlier years\' dues invoices so each contact and firm keeps its history.' );

        if ( MyNJILGA_Admin_Menu::require_fluentcrm() ) {
            MyNJILGA_Admin_UI::close();
            return;
        }
        MyNJILGA_Dues_History_Table::maybe_upgrade();

        self::done_notice();

        $token = isset( $_GET['token'] ) ? self::clean_token( wp_unslash( (string) $_GET['token'] ) ) : '';
        if ( $token !== '' ) {
            $payload = self::load( $token );
            if ( $payload === null ) {
                MyNJILGA_Admin_UI::callout( '<strong>That upload has expired.</strong> Uploads are held for an hour so you can review them. Please upload the file again.', 'warning' );
                self::render_upload_form();
            } elseif ( ! empty( $payload['error'] ) ) {
                MyNJILGA_Admin_UI::callout( '<strong>The file could not be imported.</strong> ' . wp_kses_post( (string) $payload['error'] ), 'error' );
                self::render_upload_form();
            } else {
                self::render_review( $token, $payload );
            }
            MyNJILGA_Admin_UI::close();
            return;
        }

        self::render_intro();
        self::render_upload_form();
        MyNJILGA_Admin_UI::close();
    }

    // -------------------------------------------------------------------------
    // Step 1 — upload
    // -------------------------------------------------------------------------

    private static function render_intro(): void {
        MyNJILGA_Admin_UI::callout(
            '<strong>Reference history only.</strong> Each invoice you import shows on the <em>Dues History</em> tab of its firm and contact. It does not touch Stripe, Invoicing, the Payments ledger, FluentCRM tags or WordPress roles. Re-uploading the same sheet is safe — invoices already imported are skipped — and any run can be undone from <a href="' . esc_url( MyNJILGA_Admin_Menu::url( MyNJILGA_Admin_Menu::SLUG_TOOLS ) ) . '">Tools</a>.',
            'info'
        );

        MyNJILGA_Admin_UI::section( 'How the spreadsheet should look', 'One row per invoice line. A firm\'s invoice for three members is three rows with the same invoice number; a one-member invoice is one row.' );
        echo '<div class="njilga-card njilga-card-pad">';
        echo '<p>The first row must be column headings. Order doesn\'t matter, and extra columns are ignored. Headings are matched loosely — <em>Invoice #</em>, <em>Invoice No</em> and <em>Invoice Number</em> all work.</p>';
        echo '<div class="njilga-card njilga-table-boxed"><div class="njilga-tablewrap"><table class="njilga-table njilga-table-compact"><thead><tr><th>Column</th><th>Needed?</th><th>What it\'s for</th></tr></thead><tbody>';
        $rows = [
            [ 'Amount', 'Required', 'What the line cost. Negative amounts are credits.' ],
            [ 'Dues Year (or Invoice Date)', 'Required', 'Which dues year the invoice belongs to. If there is no Dues Year column it is taken from the Invoice Date.' ],
            [ 'Firm and/or Member Email', 'One of them', 'How the invoice is matched. Firm is matched to a FluentCRM Company by name (<em>Smith &amp; Jones, LLP</em> = <em>Smith and Jones LLP</em>); Member Email to a contact, which also supplies the firm if the sheet has none.' ],
            [ 'Invoice Number', 'Recommended', 'Rows sharing a number (and year) become one invoice. It is also what stops a re-upload creating duplicates.' ],
            [ 'Status', 'Optional', 'Paid, Unpaid/Open, or Void. A row with none is recorded as you choose below.' ],
            [ 'Amount Paid, Date Paid, Payment Method, Reference / Check #', 'Optional', 'The payment. Without Amount Paid, a paid invoice is paid in full.' ],
            [ 'Invoice Date, Due Date, Member Name, Description, Notes', 'Optional', 'Shown on the invoice.' ],
        ];
        foreach ( $rows as $r ) {
            printf( '<tr><td><strong>%s</strong></td><td>%s</td><td>%s</td></tr>', esc_html( $r[0] ), esc_html( $r[1] ), wp_kses_post( $r[2] ) );
        }
        echo '</tbody></table></div></div>';
        echo '<p class="njilga-help">Upload a .csv or .xlsx (up to ' . esc_html( size_format( self::MAX_BYTES ) ) . ' and ' . esc_html( number_format_i18n( MyNJILGA_Historical_Import::MAX_INVOICES ) ) . ' invoices; split larger sheets). Only the first worksheet of an Excel file is read.</p>';
        echo MyNJILGA_Admin_UI::action_form( self::ACTION_TEMPLATE, 'Download a blank template', [], 'outline', 'download' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in action_form().
        echo '</div>';
    }

    private static function render_upload_form(): void {
        MyNJILGA_Admin_UI::section( 'Upload' );
        echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="njilga-card njilga-card-pad">';
        echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_UPLOAD ) . '">';
        wp_nonce_field( self::ACTION_UPLOAD );
        echo '<div class="njilga-field"><label for="njilga-sheet">Spreadsheet (.csv or .xlsx)</label><input type="file" id="njilga-sheet" name="sheet" accept=".csv,.xlsx,.txt,.tsv" required></div>';
        echo '<p><strong>A row with no status or payment information is…</strong></p><div class="njilga-radio-list">';
        echo '<label class="njilga-check-label"><input type="radio" name="default_status" value="paid" checked> a paid invoice (the usual case for a sheet of past payments)</label>';
        echo '<label class="njilga-check-label"><input type="radio" name="default_status" value="open"> an open (unpaid) invoice</label>';
        echo '</div><p><button type="submit" class="njilga-btn njilga-btn-primary">' . MyNJILGA_Admin_UI::icon( 'upload' ) . ' Upload and review</button></p>';
        echo '<p class="njilga-help">Nothing is imported yet — you\'ll see exactly what would be created first.</p>';
        echo '</form>';
    }

    public static function handle_upload(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Access denied.' );
        }
        check_admin_referer( self::ACTION_UPLOAD );

        if ( ! MyNJILGA_Members_Data::fluentcrm_active() ) {
            wp_die( 'FluentCRM is not active.' );
        }

        $default = isset( $_POST['default_status'] ) && sanitize_key( wp_unslash( (string) $_POST['default_status'] ) ) === 'open'
            ? MyNJILGA_Dues_History_Table::STATUS_OPEN
            : MyNJILGA_Dues_History_Table::STATUS_PAID;

        $file = isset( $_FILES['sheet'] ) && is_array( $_FILES['sheet'] ) ? $_FILES['sheet'] : [];
        $err  = isset( $file['error'] ) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
        $tmp  = isset( $file['tmp_name'] ) ? (string) $file['tmp_name'] : '';
        $name = isset( $file['name'] ) ? sanitize_file_name( wp_unslash( (string) $file['name'] ) ) : '';

        if ( $err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE ) {
            self::fail( 'The file is larger than this server allows uploading.' );
        }
        if ( $err !== UPLOAD_ERR_OK || $tmp === '' || ! is_uploaded_file( $tmp ) ) {
            self::fail( 'No file was uploaded.' );
        }
        if ( (int) ( $file['size'] ?? 0 ) > self::MAX_BYTES ) {
            self::fail( 'The file is larger than ' . esc_html( size_format( self::MAX_BYTES ) ) . '. Split it into smaller sheets.' );
        }

        $read = MyNJILGA_Spreadsheet_Reader::read( $tmp, $name );
        if ( $read['error'] !== '' ) {
            self::fail( esc_html( $read['error'] ) );
        }
        if ( count( $read['rows'] ) < 2 ) {
            self::fail( 'The sheet needs a heading row and at least one row of data.' );
        }

        $prep = MyNJILGA_Historical_Import::prepare( $read['rows'], $default );
        if ( $prep['missing'] ) {
            self::fail(
                'The sheet is missing ' . esc_html( implode( '; ', $prep['missing'] ) ) . '. Its headings were: <em>' . esc_html( implode( ', ', array_slice( array_map( 'strval', (array) $read['rows'][0] ), 0, 20 ) ) ) . '</em>. Download the template to see the expected headings.'
            );
        }

        $rowErrors = [];
        foreach ( $prep['records'] as $r ) {
            if ( ! empty( $r['errors'] ) ) {
                $rowErrors[] = [ 'line' => (int) $r['line'], 'message' => implode( ' ', $r['errors'] ) ];
            }
        }
        $invoices = MyNJILGA_Historical_Import::group( $prep['records'] );
        if ( count( $invoices ) > MyNJILGA_Historical_Import::MAX_INVOICES ) {
            self::fail( 'That sheet makes ' . esc_html( number_format_i18n( count( $invoices ) ) ) . ' invoices; the limit is ' . esc_html( number_format_i18n( MyNJILGA_Historical_Import::MAX_INVOICES ) ) . ' at a time. Split it.' );
        }
        if ( ! $invoices ) {
            self::fail( 'None of the ' . esc_html( number_format_i18n( count( $prep['records'] ) ) ) . ' data rows could be used — see the problems below.', $rowErrors );
        }

        $token = wp_generate_password( 20, false, false );
        self::store( $token, [
            'file'         => $name,
            'columns'      => $prep['columns'],
            'unrecognised' => $prep['unrecognised'],
            'has_status'   => $prep['has_status'],
            'default'      => $default,
            'rows'         => count( $prep['records'] ),
            'row_errors'   => $rowErrors,
            'invoices'     => $invoices,
        ] );
        self::redirect( [ 'token' => $token ] );
    }

    /**
     * Park an error where the review screen can show it, and go there.
     *
     * @param array<int,array{line:int,message:string}> $rowErrors
     */
    private static function fail( string $messageHtml, array $rowErrors = [] ): void {
        $token = wp_generate_password( 20, false, false );
        $html  = $messageHtml;
        if ( $rowErrors ) {
            $html .= '<br>' . implode( '<br>', array_map( static function ( $e ) {
                return 'Row ' . (int) $e['line'] . ': ' . esc_html( $e['message'] );
            }, array_slice( $rowErrors, 0, 10 ) ) );
        }
        self::store( $token, [ 'error' => $html ] );
        self::redirect( [ 'token' => $token ] );
    }

    // -------------------------------------------------------------------------
    // Step 2 — review
    // -------------------------------------------------------------------------

    /**
     * @param array<string,mixed> $p The stored upload.
     */
    private static function render_review( string $token, array $p ): void {
        $plan   = MyNJILGA_Historical_Import::plan( (array) $p['invoices'] );
        $counts = [ 'new' => 0, 'duplicate' => 0, 'blocked' => 0 ];
        $total  = 0;
        $warn   = [];
        foreach ( $plan as $x ) {
            $counts[ $x['state'] ]++;
            if ( $x['state'] === 'new' ) {
                $total += (int) $x['inv']['total_cents'];
            }
            foreach ( array_merge( (array) $x['inv']['warnings'], (array) $x['resolved']['warnings'] ) as $w ) {
                $warn[] = [ 'invoice' => (string) ( $x['inv']['invoice_number'] !== '' ? $x['inv']['invoice_number'] : 'row ' . (int) ( $x['inv']['rows'][0] ?? 0 ) ), 'message' => (string) $w ];
            }
        }

        MyNJILGA_Admin_UI::section( 'Review', sprintf( '%s — %s data rows, read as %s invoices. Nothing has been imported yet.', esc_html( (string) $p['file'] ), esc_html( number_format_i18n( (int) $p['rows'] ) ), esc_html( number_format_i18n( count( $plan ) ) ) ) );

        self::render_columns( $p );

        MyNJILGA_Admin_UI::stat_cards( [
            [ 'label' => 'Will be imported',  'value' => $counts['new'],       'variant' => $counts['new'] > 0 ? 'success' : 'default', 'icon' => 'check-circle', 'sub' => $counts['new'] > 0 ? MyNJILGA_Invoicing::money( $total ) . ' invoiced' : '' ],
            [ 'label' => 'Already imported',  'value' => $counts['duplicate'], 'variant' => 'info',    'icon' => 'history', 'sub' => $counts['duplicate'] > 0 ? 'Skipped' : '' ],
            [ 'label' => 'Can\'t be matched', 'value' => $counts['blocked'],   'variant' => $counts['blocked'] > 0 ? 'warning' : 'default', 'icon' => 'alert', 'sub' => $counts['blocked'] > 0 ? 'Not imported — listed below' : '' ],
            [ 'label' => 'Rows with problems', 'value' => count( (array) $p['row_errors'] ), 'variant' => count( (array) $p['row_errors'] ) > 0 ? 'warning' : 'default', 'icon' => 'file', 'sub' => count( (array) $p['row_errors'] ) > 0 ? 'Skipped — listed below' : '' ],
        ], 4 );

        if ( ! $p['has_status'] ) {
            MyNJILGA_Admin_UI::callout( 'The sheet has no Status or payment columns, so every invoice is recorded as <strong>' . ( $p['default'] === MyNJILGA_Dues_History_Table::STATUS_OPEN ? 'open (unpaid)' : 'paid in full' ) . '</strong>, as you chose.', 'info' );
        }

        if ( $counts['new'] > 0 ) {
            echo '<div class="njilga-card njilga-card-pad">';
            echo MyNJILGA_Admin_UI::action_form( // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in action_form().
                self::ACTION_COMMIT,
                sprintf( 'Import %s invoice%s', number_format_i18n( $counts['new'] ), $counts['new'] === 1 ? '' : 's' ),
                [ 'token' => $token ],
                'primary',
                'upload',
                sprintf( 'Create %d historical invoices? This only adds reference history; you can undo the run from Tools.', $counts['new'] )
            );
            echo ' <a class="njilga-btn njilga-btn-outline" href="' . esc_url( MyNJILGA_Admin_Menu::url( MyNJILGA_Admin_Menu::SLUG_TOOL_IMPORT ) ) . '">Start over</a>';
            echo '</div>';
        } else {
            MyNJILGA_Admin_UI::callout( '<strong>Nothing new to import.</strong> <a href="' . esc_url( MyNJILGA_Admin_Menu::url( MyNJILGA_Admin_Menu::SLUG_TOOL_IMPORT ) ) . '">Upload a different file</a>.', 'info' );
        }

        $blocked = array_values( array_filter( $plan, static function ( $x ) {
            return $x['state'] === 'blocked';
        } ) );
        if ( $blocked ) {
            self::render_blocked( $blocked );
        }
        if ( $p['row_errors'] ) {
            self::render_row_errors( (array) $p['row_errors'] );
        }
        if ( $warn ) {
            self::render_warnings( $warn );
        }
        self::render_invoices( $plan );
    }

    /**
     * What the sheet's headings were read as.
     *
     * @param array<string,mixed> $p
     */
    private static function render_columns( array $p ): void {
        echo '<details class="njilga-details"><summary>' . MyNJILGA_Admin_UI::icon( 'sliders' ) . ' How the columns were read</summary>';
        echo '<div class="njilga-card njilga-table-boxed"><div class="njilga-tablewrap"><table class="njilga-table njilga-table-compact"><thead><tr><th>Used as</th><th>Your column</th></tr></thead><tbody>';
        foreach ( (array) $p['columns'] as $label => $header ) {
            printf( '<tr><td>%s</td><td>%s</td></tr>', esc_html( (string) $label ), esc_html( (string) $header ) );
        }
        echo '</tbody></table></div></div>';
        if ( $p['unrecognised'] ) {
            echo '<p class="njilga-help">Ignored columns: ' . esc_html( implode( ', ', array_map( 'strval', (array) $p['unrecognised'] ) ) ) . '</p>';
        }
        echo '</details>';
    }

    /**
     * @param array<int,array<string,mixed>> $blocked
     */
    private static function render_blocked( array $blocked ): void {
        MyNJILGA_Admin_UI::section( 'Invoices that can\'t be matched', 'Not imported — with no firm or contact they would appear on no tab. Fix the firm name or email in the sheet (or add the firm or contact in FluentCRM) and upload it again; invoices already imported are skipped.', count( $blocked ) );
        echo '<div class="njilga-card njilga-table-boxed"><div class="njilga-tablewrap"><table class="njilga-table"><thead><tr><th>Invoice</th><th>Rows</th><th>Firm in sheet</th><th>Email</th><th>Why</th></tr></thead><tbody>';
        foreach ( array_slice( $blocked, 0, self::SHOW ) as $x ) {
            $inv = $x['inv'];
            printf(
                '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                $inv['invoice_number'] !== '' ? esc_html( (string) $inv['invoice_number'] ) : MyNJILGA_Admin_UI::blank(), // phpcs:ignore WordPress.Security.EscapeOutput -- escaped.
                esc_html( self::rows_label( (array) $inv['rows'] ) ),
                $inv['firm'] !== '' ? esc_html( (string) $inv['firm'] ) : MyNJILGA_Admin_UI::blank(), // phpcs:ignore WordPress.Security.EscapeOutput
                $inv['email'] !== '' ? esc_html( (string) $inv['email'] ) : MyNJILGA_Admin_UI::blank(), // phpcs:ignore WordPress.Security.EscapeOutput
                esc_html( implode( ' ', (array) $x['resolved']['problems'] ) )
            );
        }
        if ( count( $blocked ) > self::SHOW ) {
            printf( '<tr class="njilga-emptyrow"><td colspan="5">Showing the first %d of %d.</td></tr>', self::SHOW, count( $blocked ) );
        }
        echo '</tbody></table></div></div>';
    }

    /**
     * @param array<int,array{line:int,message:string}> $errors
     */
    private static function render_row_errors( array $errors ): void {
        MyNJILGA_Admin_UI::section( 'Rows that were skipped', 'These rows could not be read as invoice lines. Correct them in the sheet and upload it again.', count( $errors ) );
        echo '<div class="njilga-card njilga-table-boxed"><div class="njilga-tablewrap"><table class="njilga-table"><thead><tr><th>Sheet row</th><th>Problem</th></tr></thead><tbody>';
        foreach ( array_slice( $errors, 0, self::SHOW ) as $e ) {
            printf( '<tr><td>%d</td><td>%s</td></tr>', (int) $e['line'], esc_html( (string) $e['message'] ) );
        }
        if ( count( $errors ) > self::SHOW ) {
            printf( '<tr class="njilga-emptyrow"><td colspan="2">Showing the first %d of %d.</td></tr>', self::SHOW, count( $errors ) );
        }
        echo '</tbody></table></div></div>';
    }

    /**
     * @param array<int,array{invoice:string,message:string}> $warnings
     */
    private static function render_warnings( array $warnings ): void {
        MyNJILGA_Admin_UI::section( 'Worth a look', 'These invoices will import, but something in them was adjusted or left blank.', count( $warnings ) );
        echo '<div class="njilga-card njilga-table-boxed"><div class="njilga-tablewrap"><table class="njilga-table njilga-table-compact"><thead><tr><th>Invoice</th><th>Note</th></tr></thead><tbody>';
        foreach ( array_slice( $warnings, 0, 60 ) as $w ) {
            printf( '<tr><td>%s</td><td>%s</td></tr>', esc_html( $w['invoice'] ), esc_html( $w['message'] ) );
        }
        if ( count( $warnings ) > 60 ) {
            printf( '<tr class="njilga-emptyrow"><td colspan="2">Showing the first 60 of %d.</td></tr>', count( $warnings ) );
        }
        echo '</tbody></table></div></div>';
    }

    /**
     * @param array<int,array{inv:array<string,mixed>,resolved:array<string,mixed>,state:string}> $plan
     */
    private static function render_invoices( array $plan ): void {
        $shown = array_values( array_filter( $plan, static function ( $x ) {
            return $x['state'] !== 'blocked';
        } ) );
        if ( ! $shown ) {
            return;
        }
        MyNJILGA_Admin_UI::section( 'Invoices', count( $shown ) > self::SHOW ? sprintf( 'Showing the first %d, in sheet order.', self::SHOW ) : 'In sheet order.', count( $shown ) );
        echo '<div class="njilga-card njilga-table-boxed"><div class="njilga-tablewrap"><table class="njilga-table"><thead><tr>';
        echo '<th>Invoice</th><th>Year</th><th>Firm</th><th>Billed to</th><th class="njilga-col-num">Lines</th><th class="njilga-col-num">Total</th><th>Status</th><th>Paid on</th><th></th>';
        echo '</tr></thead><tbody>';
        foreach ( array_slice( $shown, 0, self::SHOW ) as $x ) {
            $inv = $x['inv'];
            $res = $x['resolved'];
            $statusPill = $inv['status'] === MyNJILGA_Dues_History_Table::STATUS_PAID
                ? MyNJILGA_Admin_UI::pill( 'Paid', 'success' )
                : ( $inv['status'] === MyNJILGA_Dues_History_Table::STATUS_VOID ? MyNJILGA_Admin_UI::pill( 'Void', 'muted' ) : MyNJILGA_Admin_UI::pill( 'Open', 'info' ) );
            printf(
                '<tr><td>%s</td><td>%d</td><td>%s</td><td>%s</td><td class="njilga-col-num">%d</td><td class="njilga-col-num">%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                $inv['invoice_number'] !== '' ? esc_html( (string) $inv['invoice_number'] ) : MyNJILGA_Admin_UI::blank(), // phpcs:ignore WordPress.Security.EscapeOutput -- escaped.
                (int) $inv['year'],
                $res['company_name'] !== '' ? esc_html( (string) $res['company_name'] ) : MyNJILGA_Admin_UI::status( 'no firm', 'warn' ), // phpcs:ignore WordPress.Security.EscapeOutput
                $res['contact_name'] !== '' ? esc_html( (string) $res['contact_name'] ) : MyNJILGA_Admin_UI::blank(), // phpcs:ignore WordPress.Security.EscapeOutput
                count( (array) $inv['lines'] ),
                esc_html( MyNJILGA_Invoicing::money( (int) $inv['total_cents'] ) ),
                $statusPill, // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in pill().
                $inv['paid_date'] !== '' ? esc_html( MyNJILGA_Dues_History_View::date( (string) $inv['paid_date'] ) ) : MyNJILGA_Admin_UI::blank(), // phpcs:ignore WordPress.Security.EscapeOutput
                $x['state'] === 'duplicate' ? MyNJILGA_Admin_UI::pill( 'Already imported', 'info' ) : MyNJILGA_Admin_UI::pill( 'New', 'success' ) // phpcs:ignore WordPress.Security.EscapeOutput
            );
        }
        echo '</tbody></table></div></div>';
    }

    /**
     * "row 4", "rows 4–6", "rows 4, 7, 9".
     *
     * @param array<int,int> $rows
     */
    public static function rows_label( array $rows ): string {
        $rows = array_values( array_map( 'intval', $rows ) );
        if ( ! $rows ) {
            return '';
        }
        if ( count( $rows ) === 1 ) {
            return 'row ' . $rows[0];
        }
        $contiguous = true;
        for ( $i = 1; $i < count( $rows ); $i++ ) {
            if ( $rows[ $i ] !== $rows[ $i - 1 ] + 1 ) {
                $contiguous = false;
                break;
            }
        }
        return $contiguous ? 'rows ' . $rows[0] . '–' . end( $rows ) : 'rows ' . implode( ', ', array_slice( $rows, 0, 6 ) ) . ( count( $rows ) > 6 ? '…' : '' );
    }

    // -------------------------------------------------------------------------
    // Step 3 — import
    // -------------------------------------------------------------------------

    public static function handle_commit(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Access denied.' );
        }
        check_admin_referer( self::ACTION_COMMIT );

        if ( ! MyNJILGA_Members_Data::fluentcrm_active() ) {
            wp_die( 'FluentCRM is not active.' );
        }
        MyNJILGA_Dues_History_Table::maybe_upgrade();

        $token   = isset( $_POST['token'] ) ? self::clean_token( wp_unslash( (string) $_POST['token'] ) ) : '';
        $payload = $token !== '' ? self::load( $token ) : null;
        if ( $payload === null || ! empty( $payload['error'] ) ) {
            self::redirect( [ 'token' => $token ] ); // The review screen explains an expired or failed upload.
        }

        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        }

        $plan   = MyNJILGA_Historical_Import::plan( (array) $payload['invoices'] );
        $batch  = MyNJILGA_Historical_Import::batch_id( MyNJILGA_Dues_History_Table::SOURCE_IMPORT );
        $result = MyNJILGA_Historical_Import::commit( $plan, $batch, get_current_user_id() );
        delete_transient( self::transient_key( $token ) );

        self::redirect( [
            'done'      => 1,
            'created'   => $result['created'],
            'duplicate' => $result['duplicate'],
            'blocked'   => $result['blocked'],
            'failed'    => $result['failed'],
        ] );
    }

    private static function done_notice(): void {
        if ( ! isset( $_GET['done'] ) ) {
            return;
        }
        $get = static function ( string $k ): int {
            return isset( $_GET[ $k ] ) ? absint( wp_unslash( (string) $_GET[ $k ] ) ) : 0;
        };
        $created = $get( 'created' );
        $msg     = sprintf( '<strong>Imported %s invoice%s.</strong> They now show on each firm\'s and contact\'s Dues History tab.', esc_html( number_format_i18n( $created ) ), $created === 1 ? '' : 's' );
        if ( $get( 'duplicate' ) > 0 ) {
            $msg .= ' ' . esc_html( number_format_i18n( $get( 'duplicate' ) ) ) . ' were already imported and skipped.';
        }
        if ( $get( 'blocked' ) > 0 ) {
            $msg .= ' ' . esc_html( number_format_i18n( $get( 'blocked' ) ) ) . ' could not be matched and were not imported.';
        }
        if ( $get( 'failed' ) > 0 ) {
            $msg .= ' ' . esc_html( number_format_i18n( $get( 'failed' ) ) ) . ' could not be written — upload the sheet again to retry them.';
        }
        MyNJILGA_Admin_UI::callout( $msg, $get( 'failed' ) > 0 ? 'warning' : 'success' );
    }

    // -------------------------------------------------------------------------
    // Template download
    // -------------------------------------------------------------------------

    public static function handle_template(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Access denied.' );
        }
        check_admin_referer( self::ACTION_TEMPLATE );

        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="njilga-historical-invoices-template.csv"' );
        echo MyNJILGA_Historical_Import::template_csv(); // phpcs:ignore WordPress.Security.EscapeOutput -- a CSV download.
        exit;
    }

    // -------------------------------------------------------------------------
    // The parked upload
    // -------------------------------------------------------------------------

    /**
     * Keep a parsed upload for the review and confirm steps. Scoped to
     * the user who uploaded it.
     *
     * @param array<string,mixed> $payload
     */
    private static function store( string $token, array $payload ): void {
        $payload['user'] = get_current_user_id();
        set_transient( self::transient_key( $token ), $payload, self::TOKEN_TTL );
    }

    /**
     * @return array<string,mixed>|null Null when missing, expired, or another user's.
     */
    private static function load( string $token ): ?array {
        $payload = get_transient( self::transient_key( $token ) );
        if ( ! is_array( $payload ) || (int) ( $payload['user'] ?? 0 ) !== get_current_user_id() ) {
            return null;
        }
        return $payload;
    }

    private static function transient_key( string $token ): string {
        return 'njilga_hist_import_' . $token;
    }

    private static function clean_token( string $raw ): string {
        return substr( (string) preg_replace( '/[^A-Za-z0-9]/', '', $raw ), 0, 32 );
    }

    /**
     * @param array<string,mixed> $args
     */
    private static function redirect( array $args ): void {
        wp_safe_redirect( add_query_arg( array_merge( [ 'page' => MyNJILGA_Admin_Menu::SLUG_TOOL_IMPORT ], $args ), admin_url( 'admin.php' ) ) );
        exit;
    }
}
