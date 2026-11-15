<?php
/**
 * The read-only AI tools (MyNJILGA_Abilities): what an assistant is shown
 * of an invoice row — and what it never is — and that every result matches
 * the output schema the tool declares. WordPress 7.1 validates results
 * against those schemas and turns a mismatch into an error, so a wrong
 * type here would break the tool on the live site.
 */
declare( strict_types=1 );

require_once dirname( __DIR__ ) . '/includes/ai/class-abilities.php';

class AbilitiesTest extends NJILGA_TestCase {

    private function row( array $over = [] ): object {
        $snap = [
            'version' => 2, 'dues_year' => 2027, 'billing_mode' => 'firm', 'invoice_kind' => 'combined',
            'company' => [ 'id' => 12, 'name' => 'Law Office of J. Peter Jost, Esquire' ],
            'owner' => [ 'contact_id' => 5, 'name' => 'J Peter Jost', 'email' => 'jpj@firm.test' ],
            'bill_to' => [ 'contact_id' => 5, 'name' => 'J Peter Jost', 'email' => 'jpj@firm.test' ],
            'members' => [ [ 'contact_id' => 5, 'name' => 'J Peter Jost', 'email' => 'jpj@firm.test', 'dues_cents' => 0, 'assessment_cents' => 20000 ] ],
        ];
        return (object) ( $over + [
            'id' => 41, 'dues_year' => 2027, 'livemode' => 1, 'fluentcrm_company_id' => 12, 'invoice_kind' => 'combined',
            'billing_mode' => 'firm', 'bill_to_contact_id' => 5, 'status' => 'voided', 'total_amount_cents' => 20000,
            'gateway_invoice_id' => 'in_1Abc', 'gateway_invoice_number' => 'NJILGA-2290', 'gateway_customer_id' => 'cus_9',
            'hosted_invoice_url' => 'https://invoice.stripe.com/i/secret', 'invoice_pdf_url' => 'https://pay.stripe.com/pdf',
            'stripe_status' => 'void', 'amount_paid_cents' => 0, 'amount_due_cents' => 0, 'amount_refunded_cents' => 0, 'paid_off_stripe_cents' => 0,
            'primary_method' => null, 'roster_snapshot' => json_encode( $snap ),
            'last_error' => 'Email not sent: wp_mail() failed for jpj@firm.test', 'created_at' => '2026-11-01 10:00:00',
            'approved_at' => null, 'queued_at' => null, 'sent_at' => '0000-00-00 00:00:00', 'due_date' => '2026-12-31', 'finalized_at' => '2026-11-01 10:00:05',
            'processing_at' => null, 'paid_at' => null, 'downgraded_at' => null, 'voided_at' => '2026-11-13 18:02:11', 'last_synced_at' => null,
        ] );
    }

    // ---- what is shown ---------------------------------------------------

    public function testNoEmailsPaymentLinksOrRosterNamesLeave(): void {
        $json = json_encode( MyNJILGA_Abilities::invoice_detail( $this->row() ) );
        $this->assertTrue( strpos( $json, '@' ) === false, 'no email address: ' . $json );
        $this->assertTrue( strpos( $json, 'invoice.stripe.com' ) === false && strpos( $json, 'pay.stripe.com' ) === false, 'no payment links' );
        $this->assertTrue( strpos( $json, 'roster_snapshot' ) === false );
        $this->assertTrue( strpos( $json, '[email]' ) !== false, 'the email in the error is redacted, the error kept' );
    }

    /** A contact with no name on file is named by their email in the snapshot — it is redacted too. */
    public function testABillToNamedByEmailIsRedacted(): void {
        $snap = json_decode( $this->row()->roster_snapshot, true );
        $snap['bill_to']['name'] = 'nameless@firm.test';
        $s = MyNJILGA_Abilities::invoice_summary( $this->row( [ 'roster_snapshot' => json_encode( $snap ) ] ) );
        $this->assertSame( '[email]', $s['bill_to'] );
    }

    public function testTheDetailCarriesWhatADiagnosisNeeds(): void {
        $d = MyNJILGA_Abilities::invoice_detail( $this->row() );
        $this->assertSame( 'voided', $d['status'] );
        $this->assertSame( 'Needs Attention', $d['stage'] );
        $this->assertSame( 'Law Office of J. Peter Jost, Esquire', $d['firm'] );
        $this->assertSame( 'J Peter Jost', $d['bill_to'] );
        $this->assertSame( '2026-11-13 18:02:11', $d['timestamps']['voided_at'] );
        $this->assertSame( null, $d['timestamps']['sent_at'], 'MySQL\'s zero date reads as null' );
        $this->assertSame( 1, $d['members_on_invoice'] );
        $this->assertSame( true, $d['has_payment_link'], 'whether there is one — not what it is' );
    }

    public function testStageMatchesTheInvoicingTabs(): void {
        $cases = [
            'No Invoice'         => [ 'status' => 'draft', 'last_error' => null ],
            'Invoice Created'    => [ 'status' => 'created' ],
            'Email Sent'         => [ 'status' => 'sent' ],
            'Paid'               => [ 'status' => 'paid' ],
            'Paid (online join)' => [ 'status' => 'paid', 'invoice_kind' => 'join' ],
        ];
        foreach ( $cases as $stage => $over ) {
            $this->assertSame( $stage, MyNJILGA_Abilities::stage( $this->row( $over ) ), $stage );
        }
        $this->assertSame( 'Needs Attention', MyNJILGA_Abilities::stage( $this->row( [ 'status' => 'draft', 'last_error' => 'Stripe said no' ] ) ) );
        $this->assertSame( 'Email Sent', MyNJILGA_Abilities::stage( $this->row( [ 'status' => 'processing', 'sent_at' => '2026-11-02 09:00:00' ] ) ) );
    }

    public function testRedact(): void {
        $this->assertSame( 'Sent to J Doe ([email]) and [email].', MyNJILGA_Abilities::redact( 'Sent to J Doe (j.doe+x@firm-law.co.uk) and A@B.IO.' ) );
        $this->assertSame( 5 + 1, mb_strlen( MyNJILGA_Abilities::redact( 'abcdefgh', 5 ) ), 'cut, with an ellipsis' );
    }

    // ---- results match the declared output schemas --------------------

    // ---- tracing an invoice that was issued again ----------------------

    /** The row a retired invoice names is shown only while it is still that firm's invoice for that year. */
    public function testATracedRowMustStillBeTheSameFirmsInvoice(): void {
        $trace = [ 'row' => 41, 'company' => 12, 'year' => 2027, 'live' => 1, 'at' => '2026-11-14 09:00:00' ];
        $same  = $this->row( [ 'id' => 41 ] );
        $this->assertTrue( MyNJILGA_Abilities::row_fits_trace( $same, $trace ) );

        $this->assertFalse( MyNJILGA_Abilities::row_fits_trace( null, $trace ), 'the row is gone' );
        $this->assertFalse( MyNJILGA_Abilities::row_fits_trace( $this->row( [ 'id' => 42 ] ), $trace ), 'another row' );
        $this->assertFalse( MyNJILGA_Abilities::row_fits_trace( $this->row( [ 'id' => 41, 'fluentcrm_company_id' => 99 ] ), $trace ), 'the id now belongs to another firm (a wiped year\'s id reused)' );
        $this->assertFalse( MyNJILGA_Abilities::row_fits_trace( $this->row( [ 'id' => 41, 'dues_year' => 2028 ] ), $trace ), 'another year' );
        $this->assertFalse( MyNJILGA_Abilities::row_fits_trace( $this->row( [ 'id' => 41, 'livemode' => 0 ] ), $trace ), 'the other mode' );
        $this->assertFalse( MyNJILGA_Abilities::row_fits_trace( $this->row( [ 'id' => 41, 'created_at' => '2026-11-20 08:00:00' ] ), $trace ), 'created after the invoice was retired: a reused id' );
        $this->assertFalse( MyNJILGA_Abilities::row_fits_trace( $this->row( [ 'id' => 41, 'invoice_kind' => MyNJILGA_Dues_Snapshot::KIND_JOIN ] ), $trace ), 'a join is never issued again' );

        // The webhook history knows only the row, its mode and when the event arrived.
        $history = [ 'row' => 41, 'live' => 1, 'at' => '2026-11-13 18:02:12' ];
        $this->assertTrue( MyNJILGA_Abilities::row_fits_trace( $same, $history ) );
        $this->assertFalse( MyNJILGA_Abilities::row_fits_trace( $this->row( [ 'id' => 41, 'created_at' => '2027-01-05 08:00:00' ] ), $history ), 'a row created after the event is a reused id' );
    }

    /** If the named row no longer fits, the firm's one invoice of that kind for that year is shown — never a guess. */
    public function testATraceFallsBackToTheFirmsOneInvoiceForThatYear(): void {
        $trace    = [ 'row' => 41, 'company' => 12, 'year' => 2027, 'live' => 1, 'kind' => 'combined', 'bill_to' => 5, 'mode' => 'firm', 'at' => '2026-11-14 09:00:00' ];
        $same     = $this->row( [ 'id' => 41 ] );
        $replaced = $this->row( [ 'id' => 77, 'bill_to_contact_id' => 6, 'created_at' => '2026-11-20 08:00:00' ] ); // Refresh Firms, new billing contact
        $this->assertSame( $same, MyNJILGA_Abilities::pick_traced_row( $trace, $same, [ $replaced ] ), 'the named row first' );
        $this->assertSame( $replaced, MyNJILGA_Abilities::pick_traced_row( $trace, null, [ $replaced, $this->row( [ 'id' => 78, 'dues_year' => 2026 ] ), $this->row( [ 'id' => 79, 'invoice_kind' => MyNJILGA_Dues_Snapshot::KIND_JOIN ] ) ] ), 'billed as a whole: the firm\'s one 2027 invoice, whoever it is now addressed to' );
        $this->assertSame( null, MyNJILGA_Abilities::pick_traced_row( $trace, null, [ $replaced, $this->row( [ 'id' => 80, 'bill_to_contact_id' => 7 ] ) ] ), 'two candidates: no guess' );
        $this->assertSame( null, MyNJILGA_Abilities::pick_traced_row( $trace, null, [] ) );
        $this->assertSame( null, MyNJILGA_Abilities::pick_traced_row( [ 'row' => 41, 'live' => 1, 'at' => '2026-11-13 18:02:12' ], null, [ $replaced ] ), 'a webhook-history trace doesn\'t know the firm: no fallback' );
        $this->assertSame( null, MyNJILGA_Abilities::pick_traced_row( [ 'row' => 41, 'company' => 12, 'year' => 2027, 'live' => 1, 'at' => '2026-11-14 09:00:00' ], null, [ $replaced ] ), 'an entry that doesn\'t know the kind of invoice (3.25.4): no fallback' );
    }

    /** Billed per member, a voided assessment invoice never "becomes" the firm's dues invoice or another member's. */
    public function testThePerMemberFallbackNeedsTheSameKindAndPerson(): void {
        $split = [ 'row' => 50, 'company' => 9, 'year' => 2027, 'live' => 1, 'kind' => 'assessment', 'bill_to' => 21, 'mode' => 'split_assessment', 'at' => '2026-11-14 09:00:00' ];
        $dues  = $this->row( [ 'id' => 40, 'fluentcrm_company_id' => 9, 'invoice_kind' => 'dues', 'billing_mode' => 'split_assessment', 'bill_to_contact_id' => 20 ] );
        $other = $this->row( [ 'id' => 51, 'fluentcrm_company_id' => 9, 'invoice_kind' => 'assessment', 'billing_mode' => 'split_assessment', 'bill_to_contact_id' => 22 ] );
        $again = $this->row( [ 'id' => 52, 'fluentcrm_company_id' => 9, 'invoice_kind' => 'assessment', 'billing_mode' => 'split_assessment', 'bill_to_contact_id' => 21 ] );
        $this->assertSame( null, MyNJILGA_Abilities::pick_traced_row( $split, null, [ $dues ] ), 'not the firm\'s dues invoice' );
        $this->assertSame( null, MyNJILGA_Abilities::pick_traced_row( $split, null, [ $dues, $other ] ), 'not another member\'s assessment' );
        $this->assertSame( $again, MyNJILGA_Abilities::pick_traced_row( $split, null, [ $dues, $other, $again ] ), 'the same member\'s assessment' );

        $individual = [ 'row' => 60, 'company' => 9, 'year' => 2027, 'live' => 1, 'kind' => 'combined', 'bill_to' => 31, 'mode' => 'individual', 'at' => '2026-11-14 09:00:00' ];
        $owner      = $this->row( [ 'id' => 61, 'fluentcrm_company_id' => 9, 'billing_mode' => 'individual', 'bill_to_contact_id' => 30 ] );
        $this->assertSame( null, MyNJILGA_Abilities::pick_traced_row( $individual, null, [ $owner ] ), 'a member who left: not the Owner\'s invoice' );

        $this->assertFalse( MyNJILGA_Abilities::row_fits_trace( $this->row( [ 'id' => 50, 'fluentcrm_company_id' => 9, 'invoice_kind' => 'dues' ] ), $split ), 'the named row must still be the same kind of invoice' );
    }

    /** A reference no reissue retired — a charge, a payment, someone else's invoice — is not traced or labelled. */
    public function testAnUnknownReferenceIsNotTracedOrLabelledReissued(): void {
        global $wpdb;
        $wpdb->reset();
        foreach ( [ 'ch_3Refund', 'pi_3Join', 'in_NotOurs' ] as $ref ) {
            $this->assertSame( [ 'found' => false, 'invoice' => null, 'note' => null ], MyNJILGA_Abilities::get_invoice( [ 'stripe_invoice' => $ref ] ), $ref );
        }
        foreach ( $wpdb->queries as $sql ) {
            $this->assertTrue( strpos( $sql, 'stripe_events' ) === false, 'the webhook history is not asked: ' . $sql );
        }
    }

    public function testResultsMatchTheirOutputSchemas(): void {
        $defs = ( new ReflectionMethod( 'MyNJILGA_Abilities', 'definitions' ) );
        $defs->setAccessible( true );
        $schemas = $defs->invoke( null );

        $this->assertSame( MyNJILGA_Abilities::ABILITIES, array_keys( $schemas ), 'every listed tool is defined, in order' );
        foreach ( $schemas as $name => $def ) {
            foreach ( [ 'label', 'description', 'execute_callback', 'output_schema' ] as $k ) {
                $this->assertTrue( ! empty( $def[ $k ] ), "$name has $k" );
            }
            $this->assertTrue( preg_match( '#^[a-z0-9-]+/[a-z0-9-]+$#', $name ) === 1, "$name is a valid ability name" );
            $this->assertTrue( is_callable( $def['execute_callback'] ), "$name callback" );
        }

        $summary = MyNJILGA_Abilities::invoice_summary( $this->row() );
        $this->assertConforms( [ 'invoices' => [ $summary ] ], $schemas['my-njilga/list-firm-invoices']['output_schema'], 'list-firm-invoices' );
        $this->assertConforms( [ 'invoices' => [ $summary ], 'total' => 1 ], $schemas['my-njilga/flagged-invoices']['output_schema'], 'flagged-invoices' );
        $this->assertConforms( [ 'found' => true, 'invoice' => MyNJILGA_Abilities::invoice_detail( $this->row() ) ], $schemas['my-njilga/get-invoice']['output_schema'], 'get-invoice' );
        $this->assertConforms( [ 'found' => false, 'invoice' => null, 'note' => null ], $schemas['my-njilga/get-invoice']['output_schema'], 'get-invoice, not found' );
        $this->assertConforms( [ 'found' => false, 'invoice' => null, 'note' => 'Stripe invoice in_OLD was voided … can no longer be traced' ], $schemas['my-njilga/get-invoice']['output_schema'], 'get-invoice, retired but untraceable' );
        $this->assertConforms( [ 'found' => true, 'invoice' => MyNJILGA_Abilities::invoice_detail( $this->row() ) + [ 'stripe_invoice_retired' => true ], 'note' => 'Stripe invoice in_OLD was voided…' ], $schemas['my-njilga/get-invoice']['output_schema'], 'get-invoice, traced from a retired invoice' );
        $this->assertConforms( [ 'firms' => [ [ 'company_id' => 12, 'name' => 'Jost' ] ] ], $schemas['my-njilga/find-firm']['output_schema'], 'find-firm' );
        $this->assertConforms( [ 'events' => [ [ 'type' => 'invoice.voided', 'status' => 'processed', 'received_at' => '2026-11-13 18:02:11', 'processed_at' => null, 'message' => null, 'stripe_object' => 'in_1Abc' ] ] ], $schemas['my-njilga/invoice-stripe-events']['output_schema'], 'invoice-stripe-events' );
        $this->assertConforms( [ 'notes' => [ [ 'title' => 'Invoice voided', 'text' => 'x', 'by' => null, 'at' => '2026-11-13 18:02:11' ] ] ], $schemas['my-njilga/firm-notes']['output_schema'], 'firm-notes' );
        $this->assertConforms( [ 'dues_year' => 2027, 'mode' => 'live', 'counts' => (object) [ 'created' => 166 ], 'totals_cents' => (object) [], 'creation_run' => null, 'email_run' => [ 'total' => 3, 'done' => 1, 'failed' => 0, 'pending' => 2, 'skipped' => 0, 'stalled' => 0, 'finished' => false ], 'wiped' => false ], $schemas['my-njilga/year-summary']['output_schema'], 'year-summary' );
        $this->assertConforms( [ 'active_mode' => 'live', 'modes' => (object) [ 'live' => [ 'connected' => true ] ] ], $schemas['my-njilga/stripe-health']['output_schema'], 'stripe-health' );
    }

    /**
     * A small JSON-Schema type check, the subset these schemas use (type —
     * single or a list — properties, items), mirroring how WordPress's
     * rest_validate_value_from_schema() treats PHP values: an object may be
     * an associative array or stdClass, an integer must be an int.
     */
    private function assertConforms( $value, array $schema, string $path ): void {
        $types = (array) ( $schema['type'] ?? [] );
        if ( $types ) {
            $ok = false;
            foreach ( $types as $t ) {
                $ok = $ok || $this->is_type( $value, $t );
            }
            $this->assertTrue( $ok, "$path: expected " . implode( '|', $types ) . ', got ' . gettype( $value ) . ' ' . json_encode( $value ) );
        }
        if ( isset( $schema['properties'] ) && is_array( $schema['properties'] ) && ( is_array( $value ) || is_object( $value ) ) ) {
            foreach ( $schema['properties'] as $key => $sub ) {
                $arr = (array) $value;
                if ( array_key_exists( $key, $arr ) ) {
                    $this->assertConforms( $arr[ $key ], (array) $sub, "$path.$key" );
                }
            }
        }
        if ( isset( $schema['items'] ) && is_array( $value ) ) {
            foreach ( $value as $i => $item ) {
                $this->assertConforms( $item, (array) $schema['items'], "$path[$i]" );
            }
        }
    }

    private function is_type( $v, string $t ): bool {
        switch ( $t ) {
            case 'null':    return $v === null;
            case 'string':  return is_string( $v );
            case 'integer': return is_int( $v );
            case 'boolean': return is_bool( $v );
            case 'array':   return is_array( $v ) && ( $v === [] || array_keys( $v ) === range( 0, count( $v ) - 1 ) );
            case 'object':  return is_object( $v ) || ( is_array( $v ) && ( $v === [] || array_keys( $v ) !== range( 0, count( $v ) - 1 ) ) );
        }
        return false;
    }
}
