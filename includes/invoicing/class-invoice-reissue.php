<?php
/**
 * Issue a firm's invoice again after it was voided.
 *
 * A voided Stripe invoice can never be reopened, and the voided row holds
 * the firm's place for the year (the table's unique key), so Refresh Firms
 * can't draft it again either. This puts the row itself back to a draft:
 *
 *   - everything its Stripe invoice gave it (ids, links, amounts, dates)
 *     is cleared, and it shows under No Invoice as Ready, to Refresh
 *     (re-price from today's roster) and Create like any other firm;
 *   - its next create runs on a fresh Stripe idempotency key, so Stripe
 *     makes a new invoice rather than replaying the voided one;
 *   - the voided invoice's id is retired: a late Stripe event about it
 *     belongs to no row and can't touch the new invoice;
 *   - a note on the firm's Company record says which invoice was voided
 *     and that it was issued again.
 *
 * Only a voided annual invoice with nothing paid against it, for a dues
 * year still open to invoicing (not a wiped year).
 */
class MyNJILGA_Invoice_Reissue {

    /** PURE: why this row can't be issued again ('' when it can). */
    public static function refusal( object $row ): string {
        if ( (string) ( $row->invoice_kind ?? '' ) === MyNJILGA_Dues_Snapshot::KIND_JOIN ) {
            return 'An online join is someone\'s own payment — it isn\'t re-issued.';
        }
        if ( (string) ( $row->status ?? '' ) !== MyNJILGA_Dues_Invoice_Table::STATUS_VOIDED ) {
            return 'Only a voided invoice can be issued again.';
        }
        if ( (int) ( $row->amount_paid_cents ?? 0 ) > 0 || (int) ( $row->paid_off_stripe_cents ?? 0 ) > 0 ) {
            return 'Something was paid against this invoice before it was voided — settle that in Stripe first.';
        }
        return '';
    }

    /**
     * @return array{ok:bool,error:string,year:int}
     */
    public static function run( int $rowId ): array {
        $row = MyNJILGA_Dues_Invoice_Table::get( $rowId );
        if ( ! $row ) {
            return [ 'ok' => false, 'error' => 'That invoice wasn\'t found.', 'year' => 0 ];
        }
        $year = (int) $row->dues_year;
        $why  = self::refusal( $row );
        if ( $why !== '' ) {
            return [ 'ok' => false, 'error' => $why, 'year' => $year ];
        }
        $live = ! empty( $row->livemode );
        if ( ! MyNJILGA_Invoicing::year_is_open_now( $year, $live ) ) {
            return [ 'ok' => false, 'error' => MyNJILGA_Invoicing::closed_year_message( $year, MyNJILGA_Year_Wipe::was_wiped( $year, $live ) ), 'year' => $year ];
        }

        $oldId     = (string) ( $row->gateway_invoice_id ?? '' );
        $oldNumber = (string) ( $row->gateway_invoice_number ?? '' );
        if ( ! MyNJILGA_Dues_Invoice_Table::reopen_voided( $rowId ) ) {
            return [ 'ok' => false, 'error' => 'The invoice changed while this was running — reload the page and try again.', 'year' => $year ];
        }
        MyNJILGA_Dues_Invoice_Table::retire_invoice_id( $oldId );
        if ( class_exists( 'MyNJILGA_Stripe_Invoice_Gateway' ) ) {
            MyNJILGA_Stripe_Invoice_Gateway::fresh_attempt( $rowId );
        }

        $actor = wp_get_current_user();
        MyNJILGA_Invoicing_Notes::log(
            (int) $row->fluentcrm_company_id,
            'Voided invoice to be issued again',
            sprintf(
                'The %d dues invoice %s was voided in Stripe and stays voided there. %s put the firm back under No Invoice so a new invoice can be created.',
                $year,
                $oldNumber !== '' ? $oldNumber : ( $oldId !== '' ? $oldId : '' ),
                ( $actor && $actor->display_name !== '' ) ? $actor->display_name : 'A staff member'
            )
        );

        return [ 'ok' => true, 'error' => '', 'year' => $year ];
    }
}
