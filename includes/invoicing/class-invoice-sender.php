<?php
/**
 * Step 4 — manual send: emails the Owner the payment link, marks the row
 * sent, and logs a FluentCRM Company Note.
 */
class MyNJILGA_Invoice_Sender {

    /**
     * @return array{ok:bool, error?:string}
     */
    public static function send_for_row( object $invoiceRow ): array {
        if ( empty( $invoiceRow->fluentcart_order_uuid ) ) {
            return [ 'ok' => false, 'error' => 'No order on this row yet — create the invoice first.' ];
        }

        // Frozen snapshot identity, not a fresh Subscriber lookup — this
        // has to match who the order/customer was actually created for.
        $roster     = json_decode( (string) $invoiceRow->roster_snapshot, true );
        $ownerEmail = (string) ( $roster['owner_email'] ?? '' );
        $ownerName  = (string) ( $roster['owner_name'] ?? '' );
        if ( $ownerEmail === '' ) {
            $owner      = \FluentCrm\App\Models\Subscriber::find( (int) $invoiceRow->fluentcrm_owner_contact_id );
            $ownerEmail = $owner ? (string) ( $owner->email ?? '' ) : '';
            $ownerName  = $owner ? MyNJILGA_Members_Data::display_name( $owner ) : '';
        }
        if ( $ownerEmail === '' ) {
            return [ 'ok' => false, 'error' => 'Owner contact not found or has no email on file.' ];
        }

        $link = MyNJILGA_Invoice_Creator::payment_link( (string) $invoiceRow->fluentcart_order_uuid );
        if ( $link === '' ) {
            return [ 'ok' => false, 'error' => 'Could not build a payment link for this order.' ];
        }

        $duesYear = (int) $invoiceRow->dues_year;
        $total    = number_format( $invoiceRow->total_amount_cents / 100, 2 );

        $subject = sprintf( '%d NJILGA Membership Dues Invoice', $duesYear );
        $body    = sprintf(
            "Hi %s,\n\nYour firm's %d NJILGA membership dues invoice totals $%s.\n\nPay online here: %s\n\nThank you,\nNJILGA",
            $ownerName,
            $duesYear,
            $total,
            $link
        );

        if ( ! wp_mail( $ownerEmail, $subject, $body ) ) {
            return [ 'ok' => false, 'error' => "wp_mail() failed — check the site's mail configuration." ];
        }

        MyNJILGA_Dues_Invoice_Table::mark_sent( [ (int) $invoiceRow->id ] );

        MyNJILGA_Invoicing_Notes::log(
            (int) $invoiceRow->fluentcrm_company_id,
            'Dues invoice sent',
            sprintf( '%d invoice sent to %s (%s) — total $%s.', $duesYear, $ownerName, $ownerEmail, $total )
        );

        return [ 'ok' => true ];
    }
}
