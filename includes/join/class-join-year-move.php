<?php
/**
 * Move a paid online join forward to the coming dues year.
 *
 * A join made once next year's invoices exist (or after the "Joins cover
 * next year from" cutover) is recorded against NEXT year and covers the
 * rest of this one — MyNJILGA_Join_Form::dues_year(). A late-year join
 * made before either was in place is recorded against THIS year instead,
 * so next year's batch would bill that person again. This puts it right
 * after the fact, exactly as if it had been taken for next year:
 *
 *   - the join and its paid invoice row move to the coming year, so
 *     MyNJILGA_Dues_Preview::join_coverage() lists its members at $0 on
 *     next year's invoices ("Paid through an online join");
 *   - its members are tagged Dues Paid {coming year}, keeping this year's
 *     tag (it still covers the rest of this year);
 *   - a note goes on the firm's Company record.
 *
 * Nothing changes in Stripe: the payment stands as it was. Firm invoices
 * for the coming year that are still drafts pick it up on the next
 * Refresh Firms; one already created would be caught by
 * MyNJILGA_Invoice_Creator's join check only before it is created, so the
 * screen says when such an invoice exists.
 */
class MyNJILGA_Join_Year_Move {

    /**
     * PURE: why this row can't be moved to $toYear ('' when it can).
     * Only a PAID online join, and only forward by exactly one year to the
     * coming dues year.
     */
    public static function refusal( object $row, int $toYear ): string {
        if ( (string) ( $row->invoice_kind ?? '' ) !== MyNJILGA_Dues_Snapshot::KIND_JOIN ) {
            return 'Only an online join can be moved to another year.';
        }
        if ( (string) ( $row->status ?? '' ) !== MyNJILGA_Dues_Invoice_Table::STATUS_PAID ) {
            return 'Only a paid online join can be moved.';
        }
        if ( (int) ( $row->dues_year ?? 0 ) !== $toYear - 1 ) {
            return sprintf( 'Only a %d join can be moved to %d.', $toYear - 1, $toYear );
        }
        return '';
    }

    /** Whether the Invoicing page should offer the move for this row. */
    public static function offered( object $row ): bool {
        return self::refusal( $row, MyNJILGA_Invoicing::default_dues_year() ) === '';
    }

    /**
     * Do it.
     *
     * @return array{ok:bool,error:string,to_year:int,members:int}
     */
    public static function move( int $rowId ): array {
        $toYear = MyNJILGA_Invoicing::default_dues_year();
        $out    = [ 'ok' => false, 'error' => '', 'to_year' => $toYear, 'members' => 0 ];

        $row = MyNJILGA_Dues_Invoice_Table::get( $rowId );
        if ( ! $row ) {
            $out['error'] = 'That online join wasn\'t found.';
            return $out;
        }
        $refusal = self::refusal( $row, $toYear );
        if ( $refusal !== '' ) {
            $out['error'] = $refusal;
            return $out;
        }

        $fromYear = (int) $row->dues_year;
        $live     = ! empty( $row->livemode );
        $clash    = MyNJILGA_Dues_Invoice_Table::find_row( (int) $row->fluentcrm_company_id, $toYear, MyNJILGA_Dues_Snapshot::KIND_JOIN, (int) $row->bill_to_contact_id, $live );
        if ( $clash ) {
            $out['error'] = sprintf( 'This person already has a %d online join on record, so this one can\'t be moved onto it.', $toYear );
            return $out;
        }

        $snapshot              = MyNJILGA_Dues_Snapshot::decode( $row );
        $snapshot['dues_year'] = $toYear;
        if ( ! MyNJILGA_Dues_Invoice_Table::move_join_year( $rowId, $fromYear, $toYear, MyNJILGA_Dues_Snapshot::encode( $snapshot ) ) ) {
            $out['error'] = 'The online join changed while this was running — reload the page and try again.';
            return $out;
        }

        $joinId = (int) ( $snapshot['join_id'] ?? 0 );
        if ( $joinId > 0 ) {
            MyNJILGA_Join_Orders_Table::update( $joinId, [ 'dues_year' => $toYear ] );
        }

        // Paid for the coming year, and still for the rest of this one.
        $tagId = MyNJILGA_Tags::get_or_create_by_title( MyNJILGA_Dues_Settings::year_tag( 'year_paid_tag_pattern', $toYear ) );
        $names = [];
        foreach ( MyNJILGA_Dues_Snapshot::members( $row ) as $m ) {
            $contact = \FluentCrm\App\Models\Subscriber::find( (int) ( $m['contact_id'] ?? 0 ) );
            if ( $contact && $tagId ) {
                $contact->attachTags( [ $tagId ] );
            }
            $names[] = (string) ( $m['name'] ?? '' );
            $out['members']++;
        }
        MyNJILGA_Membership_Stats::flush();

        $actor = wp_get_current_user();
        MyNJILGA_Invoicing_Notes::log(
            (int) $row->fluentcrm_company_id,
            sprintf( 'Online join moved to %d', $toYear ),
            sprintf(
                'The online join paid for %s now counts toward %d dues (it still covers the rest of %d), so the %d invoices won\'t bill for %s again. Moved by %s.',
                implode( ', ', array_filter( $names ) ),
                $toYear,
                $fromYear,
                $toYear,
                count( $names ) === 1 ? 'them' : 'any of them',
                ( $actor && $actor->display_name !== '' ) ? $actor->display_name : 'a staff member'
            )
        );

        $out['ok'] = true;
        return $out;
    }
}
