<?php
/**
 * Step 3 (spec §7) — turns approved rows into real invoices through the
 * MyNJILGA_Invoice_Gateway: find-or-create the gateway customer for the
 * bill-to contact, build one line per fee from the frozen snapshot
 * (referencing the mapped catalog products, line_meta carrying
 * contact_id / dues_year), create the invoice, store its id/number and
 * whatever else create_order() returned (hosted URL, PDF URL, due date).
 *
 * Runs in Action Scheduler chunks (batch size from Settings, default 25
 * invoices per job) when Action Scheduler is present — it ships inside
 * FluentCRM — and inline otherwise. Each row is isolated: a failure is
 * recorded on THAT row's `last_error` and the loop moves on; nothing is
 * rolled back and nothing blocks the rest of the batch.
 *
 * This class never names a Stripe (or other gateway SDK) class; see the
 * gateway.
 */
class MyNJILGA_Invoice_Creator {

    const HOOK_CHUNK = 'njilga_dues_create_chunk';
    const AS_GROUP   = 'njilga-dues';

    public static function register(): void {
        add_action( self::HOOK_CHUNK, [ __CLASS__, 'run_chunk' ], 10, 2 );
    }

    public static function gateway(): MyNJILGA_Invoice_Gateway {
        return MyNJILGA_Invoicing::gateway();
    }

    // -------------------------------------------------------------------------
    // Batch scheduling
    // -------------------------------------------------------------------------

    /**
     * Queue creation for the given approved rows. Uses Action Scheduler
     * when available (chunked), else runs inline right now.
     *
     * @param array<int,int> $rowIds
     * @return array{mode:string,queued:int,chunks:int,ok:int,fail:int,skipped:int}
     */
    public static function schedule( array $rowIds, int $duesYear ): array {
        $rowIds = array_values( array_unique( array_filter( array_map( 'intval', $rowIds ) ) ) );

        // Only approved rows that aren't already queued qualify.
        $eligible = [];
        foreach ( $rowIds as $id ) {
            $row = MyNJILGA_Dues_Invoice_Table::get( $id );
            if ( $row && $row->status === MyNJILGA_Dues_Invoice_Table::STATUS_APPROVED && empty( $row->queued_at ) ) {
                $eligible[] = $id;
            }
        }

        $result = [ 'mode' => 'inline', 'queued' => 0, 'chunks' => 0, 'ok' => 0, 'fail' => 0, 'skipped' => count( $rowIds ) - count( $eligible ) ];
        if ( empty( $eligible ) ) {
            return $result;
        }

        $batchSize = max( 1, (int) MyNJILGA_Dues_Settings::general( 'batch_size', 25 ) );

        if ( function_exists( 'as_enqueue_async_action' ) ) {
            $chunks = array_chunk( $eligible, $batchSize );
            $queued = 0;
            foreach ( $chunks as $chunk ) {
                $actionId = as_enqueue_async_action( self::HOOK_CHUNK, [ 'row_ids' => $chunk, 'dues_year' => $duesYear ], self::AS_GROUP );
                if ( $actionId ) {
                    MyNJILGA_Dues_Invoice_Table::mark_queued( $chunk );
                    $queued += count( $chunk );
                    $result['chunks']++;
                } else {
                    // Scheduler refused — do this chunk right here rather than lose it.
                    $r = self::run_chunk( $chunk, $duesYear );
                    $result['ok']   += $r['ok'];
                    $result['fail'] += $r['fail'];
                }
            }
            $result['mode']   = 'scheduled';
            $result['queued'] = $queued;
            return $result;
        }

        $r = self::run_chunk( $eligible, $duesYear );
        $result['ok']   = $r['ok'];
        $result['fail'] = $r['fail'];
        return $result;
    }

    /**
     * Action Scheduler callback (also the inline path). One row at a
     * time, each wrapped so one bad firm can't take down the chunk.
     *
     * @param array<int,int>|mixed $rowIds
     * @return array{ok:int,fail:int}
     */
    public static function run_chunk( $rowIds, $duesYear = 0 ): array {
        $ok = 0; $fail = 0;
        foreach ( (array) $rowIds as $id ) {
            $id  = (int) $id;
            $row = MyNJILGA_Dues_Invoice_Table::get( $id );
            if ( ! $row || $row->status !== MyNJILGA_Dues_Invoice_Table::STATUS_APPROVED ) {
                continue; // Approved elsewhere / already created — skip quietly.
            }
            try {
                $result = self::create_for_row( $row );
            } catch ( \Throwable $e ) {
                $result = [ 'ok' => false, 'error' => $e->getMessage() ];
            }
            if ( $result['ok'] ) {
                $ok++;
            } else {
                $fail++;
                MyNJILGA_Dues_Invoice_Table::set_error( $id, (string) ( $result['error'] ?? 'Unknown error' ) );
            }
        }
        return [ 'ok' => $ok, 'fail' => $fail ];
    }

    /**
     * True while any creation job is still pending / running.
     */
    public static function has_pending_jobs(): bool {
        if ( ! function_exists( 'as_has_scheduled_action' ) ) {
            return false;
        }
        try {
            return (bool) as_has_scheduled_action( self::HOOK_CHUNK, null, self::AS_GROUP );
        } catch ( \Throwable $e ) {
            return false;
        }
    }

    // -------------------------------------------------------------------------
    // One row
    // -------------------------------------------------------------------------

    /**
     * @return array{ok:bool, error?:string}
     */
    public static function create_for_row( object $invoiceRow ): array {
        $gateway = self::gateway();
        if ( ! $gateway->is_available() ) {
            return [ 'ok' => false, 'error' => $gateway->name() . ' is not active.' ];
        }
        $ready = $gateway->readiness_errors();
        if ( $ready ) {
            return [ 'ok' => false, 'error' => $ready[0] ];
        }

        $snapshot = MyNJILGA_Dues_Snapshot::decode( $invoiceRow );
        $members  = $snapshot['members'];
        $duesYear = (int) $invoiceRow->dues_year;
        $kind     = (string) ( $snapshot['invoice_kind'] ?? MyNJILGA_Dues_Snapshot::KIND_COMBINED );

        if ( empty( $members ) ) {
            return [ 'ok' => false, 'error' => 'Empty roster snapshot.' ];
        }

        // Guard on the TOTAL, not on an empty item list: every member gets a
        // line (including $0 ones). Stripe does not auto-settle a $0
        // invoice the way FluentCart used to — this guard stays anyway
        // because an empty invoice is meaningless paperwork, not a
        // technical necessity: a firm that owes nothing this cycle
        // shouldn't get an invoice at all, regardless of gateway.
        if ( MyNJILGA_Dues_Roster::total_cents( $members ) <= 0 ) {
            return [ 'ok' => false, 'error' => 'Every roster member owes $0 — nothing to invoice.' ];
        }

        // Bill-to identity frozen at generation time — the customer match
        // has to use the email the invoice was actually addressed to, even
        // if the contact's live email has since changed.
        $billTo = MyNJILGA_Dues_Snapshot::bill_to( $invoiceRow );
        if ( $billTo['email'] === '' ) {
            $billTo = self::live_person( (int) ( $billTo['contact_id'] ?: $invoiceRow->bill_to_contact_id ?: $invoiceRow->fluentcrm_owner_contact_id ) );
        }
        if ( $billTo['email'] === '' ) {
            return [ 'ok' => false, 'error' => 'Bill-to contact not found or has no email on file.' ];
        }

        $customerId = $gateway->find_or_create_customer( $billTo );
        if ( ! $customerId ) {
            return [ 'ok' => false, 'error' => 'Could not find or create a ' . $gateway->name() . ' customer for ' . $billTo['email'] ];
        }

        $lineItems = MyNJILGA_Dues_Roster::line_items( $members, $duesYear, $kind );
        $result    = $gateway->create_order( $customerId, $lineItems, [
            'dues_year'      => $duesYear,
            'company_id'     => (int) $invoiceRow->fluentcrm_company_id,
            'invoice_row_id' => (int) $invoiceRow->id,
            'invoice_kind'   => $kind,
        ] );

        if ( empty( $result['ok'] ) ) {
            return [ 'ok' => false, 'error' => (string) ( $result['error'] ?? 'Order creation failed.' ) ];
        }

        $invoiceId     = (string) ( $result['invoice_id'] ?? '' );
        $invoiceNumber = (string) ( $result['invoice_number'] ?? '' );

        $extra = [];
        if ( isset( $result['hosted_url'] ) ) {
            $extra['hosted_invoice_url'] = (string) $result['hosted_url'];
        }
        if ( isset( $result['pdf_url'] ) ) {
            $extra['invoice_pdf_url'] = (string) $result['pdf_url'];
        }
        if ( isset( $result['due_date'] ) ) {
            $extra['due_date'] = (string) $result['due_date'];
        }

        MyNJILGA_Dues_Invoice_Table::mark_created(
            (int) $invoiceRow->id,
            $customerId,
            $invoiceId,
            $invoiceNumber,
            $extra
        );

        MyNJILGA_Invoicing_Notes::log(
            (int) $invoiceRow->fluentcrm_company_id,
            'Dues invoice created',
            sprintf(
                '%d %s invoice %s created in %s for %s (%s) — %s, %d member(s).',
                $duesYear,
                $kind === MyNJILGA_Dues_Snapshot::KIND_ASSESSMENT ? 'assessment' : 'dues',
                $invoiceNumber !== '' ? $invoiceNumber : $invoiceId,
                $gateway->name(),
                $billTo['name'],
                $billTo['email'],
                MyNJILGA_Invoicing::money( (int) $invoiceRow->total_amount_cents ),
                count( $members )
            )
        );

        return [ 'ok' => true ];
    }

    /**
     * @return array{contact_id:int,name:string,first_name:string,last_name:string,email:string}
     */
    private static function live_person( int $contactId ): array {
        if ( $contactId <= 0 || ! MyNJILGA_Members_Data::fluentcrm_active() ) {
            return MyNJILGA_Dues_Snapshot::person( [] );
        }
        $contact = \FluentCrm\App\Models\Subscriber::find( $contactId );
        if ( ! $contact ) {
            return MyNJILGA_Dues_Snapshot::person( [] );
        }
        return MyNJILGA_Dues_Snapshot::person( [
            'contact_id' => (int) $contact->id,
            'name'       => MyNJILGA_Members_Data::display_name( $contact ),
            'first_name' => (string) ( $contact->first_name ?? '' ),
            'last_name'  => (string) ( $contact->last_name ?? '' ),
            'email'      => (string) ( $contact->email ?? '' ),
        ] );
    }
}
