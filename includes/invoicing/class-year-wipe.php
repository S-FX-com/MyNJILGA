<?php
/**
 * Wipe a past dues year's annual invoices — for a year that was billed
 * by mistake (the dues were already covered some other way) and that
 * the association is moving on from.
 *
 * What it does, in this Stripe mode only:
 *   - drafts, exceptions and approved-but-never-created rows are deleted
 *     (none of them exists in Stripe);
 *   - created and sent invoices are VOIDED in Stripe first, then deleted
 *     — Stripe keeps the voided invoice for its own records, so nothing
 *     can still be paid against it, but it is gone from every admin and
 *     member page here;
 *   - invoices already voided are deleted.
 *
 * What it never touches: online joins (real payments for real
 * memberships), and any annual invoice with money against it or already
 * acted on — paid, processing, partly paid, swept by the downgrade
 * sweep, or written off. Those are KEPT and listed, for staff to settle
 * in Stripe (a refund, say) — a wipe never loses a payment record.
 *
 * Afterwards the year is recorded as wiped (per mode), and no batch can
 * be generated for it again — not even from the invoices it kept.
 *
 * Only years BEFORE the coming dues year qualify, so a batch in progress
 * for next year can never be wiped from here.
 */
class MyNJILGA_Year_Wipe {

    /** Option: [ 'live' => int[], 'test' => int[] ] — the years wiped in each mode. */
    const OPTION_WIPED = 'njilga_wiped_dues_years';

    /** Whether $year was wiped in this mode (so it stays closed to new batches). */
    public static function was_wiped( int $year, bool $live ): bool {
        $all = get_option( self::OPTION_WIPED, [] );
        $key = $live ? 'live' : 'test';
        return is_array( $all ) && in_array( $year, array_map( 'intval', (array) ( $all[ $key ] ?? [] ) ), true );
    }

    private static function record_wiped( int $year, bool $live ): void {
        $all = get_option( self::OPTION_WIPED, [] );
        $all = is_array( $all ) ? $all : [];
        $key = $live ? 'live' : 'test';
        $all[ $key ] = array_values( array_unique( array_merge( array_map( 'intval', (array) ( $all[ $key ] ?? [] ) ), [ $year ] ) ) );
        update_option( self::OPTION_WIPED, $all, false );
    }

    /** Statuses deleted outright: nothing exists in Stripe that could be collected. */
    const DELETE_STATUSES = [
        MyNJILGA_Dues_Invoice_Table::STATUS_DRAFT,
        MyNJILGA_Dues_Invoice_Table::STATUS_EXCLUDED,
        MyNJILGA_Dues_Invoice_Table::STATUS_APPROVED,
        MyNJILGA_Dues_Invoice_Table::STATUS_VOIDED,
    ];

    /** Statuses voided in Stripe, then deleted. */
    const VOID_STATUSES = [
        MyNJILGA_Dues_Invoice_Table::STATUS_CREATED,
        MyNJILGA_Dues_Invoice_Table::STATUS_SENT,
    ];

    /** Whether the year is one this tool may wipe at all. */
    public static function year_qualifies( int $year ): bool {
        return $year < MyNJILGA_Invoicing::default_dues_year();
    }

    /**
     * PURE: sort a year's rows into what a wipe would do with each.
     *
     * @param array<int,object> $rows Every row of ONE year in ONE mode.
     * @return array{
     *   delete:array<int,object>, void:array<int,object>,
     *   kept:array<int,array{row:object,reason:string}>,
     *   joins:int, firms:int, open_cents:int
     * }  firms counts the firms the wipe changes (not those only kept).
     */
    public static function plan( array $rows ): array {
        $out   = [ 'delete' => [], 'void' => [], 'kept' => [], 'joins' => 0, 'firms' => 0, 'open_cents' => 0 ];
        $firms = [];

        foreach ( $rows as $row ) {
            if ( (string) ( $row->invoice_kind ?? '' ) === MyNJILGA_Dues_Snapshot::KIND_JOIN ) {
                $out['joins']++;
                continue;
            }

            $status = (string) ( $row->status ?? '' );
            $reason = self::keep_reason( $row );
            if ( $reason !== '' ) {
                $out['kept'][] = [ 'row' => $row, 'reason' => $reason ];
                continue;
            }
            if ( in_array( $status, self::VOID_STATUSES, true ) ) {
                $out['void'][]      = $row;
                $out['open_cents'] += (int) ( $row->total_amount_cents ?? 0 );
            } else {
                $out['delete'][] = $row;
            }
            $firms[ (int) ( $row->fluentcrm_company_id ?? 0 ) ] = true;
        }

        $out['firms'] = count( $firms );
        return $out;
    }

    /**
     * PURE: why a wipe keeps one annual row ('' when it goes).
     */
    public static function keep_reason( object $row ): string {
        $T      = 'MyNJILGA_Dues_Invoice_Table';
        $status = (string) ( $row->status ?? '' );

        switch ( $status ) {
            case $T::STATUS_PAID:
                return 'Paid';
            case $T::STATUS_PROCESSING:
                return 'A payment is processing';
            case $T::STATUS_DOWNGRADED:
                return 'Already swept by the downgrade sweep';
            case $T::STATUS_UNCOLLECTIBLE:
                return 'Written off in Stripe';
        }

        if ( in_array( $status, self::VOID_STATUSES, true ) ) {
            if ( (int) ( $row->amount_paid_cents ?? 0 ) > 0 || (int) ( $row->paid_off_stripe_cents ?? 0 ) > 0 ) {
                return 'Partly paid';
            }
            if ( (string) ( $row->gateway_invoice_id ?? '' ) === '' ) {
                return 'No Stripe invoice id on file, so it can\'t be voided — run Sync with Stripe';
            }
            return '';
        }

        return in_array( $status, self::DELETE_STATUSES, true ) ? '' : 'Unrecognised status "' . $status . '"';
    }

    /**
     * Everything the confirmation screen shows, and why it may not run.
     *
     * @return array<string,mixed> plan() plus 'year', 'live', 'stop' (reasons the wipe can't run now).
     */
    public static function preview( int $year, bool $live ): array {
        $rows = MyNJILGA_Dues_Invoice_Table::get_by_year( $year, [], $live );
        $plan = self::plan( $rows );

        $stop = [];
        if ( ! self::year_qualifies( $year ) ) {
            $stop[] = sprintf( 'Only a year before %d can be wiped.', MyNJILGA_Invoicing::default_dues_year() );
        }
        if ( MyNJILGA_Invoice_Creator::has_pending_jobs() ) {
            $stop[] = 'Invoices are being created in the background. Wait for that to finish, then reload this page.';
        }
        $schedule = MyNJILGA_Launch_Schedule::get( $year );
        if ( $schedule && MyNJILGA_Launch_Schedule::is_open( (string) ( $schedule['status'] ?? '' ) ) ) {
            $stop[] = sprintf( 'A scheduled email send for %d is still open. Cancel it in the Launch Wizard (step 5) first.', $year );
        }

        return $plan + [ 'year' => $year, 'live' => $live, 'stop' => $stop ];
    }

    /**
     * Do it. Re-plans from the database rather than trusting the screen,
     * and refuses on any of preview()'s stop reasons. Kept rows are left
     * exactly as they are.
     *
     * A void Stripe refuses (most often: the invoice was already voided
     * in the Stripe Dashboard) is checked against Stripe — already void
     * counts as done; anything else leaves that row in place and is
     * reported, while the rest of the wipe carries on.
     *
     * @return array{ok:bool,error:string,deleted:int,voided:int,failed:array<int,string>,firms:int,kept:int}
     */
    public static function run( int $year, bool $live ): array {
        $out = [ 'ok' => false, 'error' => '', 'deleted' => 0, 'voided' => 0, 'failed' => [], 'firms' => 0, 'kept' => 0 ];
        $p   = self::preview( $year, $live );
        if ( $p['stop'] ) {
            $out['error'] = (string) $p['stop'][0];
            return $out;
        }

        $gateway  = MyNJILGA_Invoicing::gateway();
        $deleteIds = array_map( static function ( $r ) { return (int) $r->id; }, $p['delete'] );
        $firms     = [];
        foreach ( $p['delete'] as $row ) {
            $firms[ (int) $row->fluentcrm_company_id ] = true;
        }

        foreach ( $p['void'] as $row ) {
            $invoiceId = (string) $row->gateway_invoice_id;
            $result    = $gateway->void_invoice( $invoiceId );
            if ( empty( $result['ok'] ) ) {
                $state = $gateway->invoice_status( $invoiceId );
                if ( ! $state || (string) ( $state['status'] ?? '' ) !== 'void' ) {
                    $out['failed'][] = sprintf(
                        '%s: %s',
                        MyNJILGA_Dues_Snapshot::company_name( $row ),
                        (string) ( $result['error'] ?? $gateway->name() . ' could not void the invoice.' )
                    );
                    continue;
                }
            }
            MyNJILGA_Dues_Invoice_Table::update_gateway_fields( (int) $row->id, [
                'status'    => MyNJILGA_Dues_Invoice_Table::STATUS_VOIDED,
                'voided_at' => current_time( 'mysql' ),
            ] );
            $out['voided']++;
            $deleteIds[] = (int) $row->id;
            $firms[ (int) $row->fluentcrm_company_id ] = true;
        }

        $out['deleted'] = MyNJILGA_Dues_Invoice_Table::delete_wiped( $deleteIds, $year, $live );

        $actor = wp_get_current_user();
        $who   = ( $actor && $actor->display_name !== '' ) ? $actor->display_name : 'a staff member';
        foreach ( array_keys( $firms ) as $companyId ) {
            MyNJILGA_Invoicing_Notes::log(
                (int) $companyId,
                sprintf( '%d invoices wiped', $year ),
                sprintf( 'All %d dues invoices for this firm were removed by %s (the year was billed in error). Any invoice already created in Stripe was voided there first.', $year, $who )
            );
        }

        // Closed for good in this mode, even though kept invoices (and any
        // that failed to void) still give the year rows.
        self::record_wiped( $year, $live );

        $out['ok']    = true;
        $out['firms'] = count( $firms );
        $out['kept']  = count( $p['kept'] );
        return $out;
    }
}
