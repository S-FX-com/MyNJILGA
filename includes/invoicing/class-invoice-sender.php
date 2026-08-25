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

        // Who the invoice covers, read from the frozen snapshot — the same
        // roster the FluentCart line items were built from, so the email and
        // the invoice can't disagree. This is the plain-English half of the
        // answer to "which of our attorneys does this cover?"; the invoice
        // itself carries the same names as line items.
        $members = $roster['members'] ?? [];
        $covers  = MyNJILGA_Dues_Roster::email_summary( $members, $duesYear );
        $firm    = (string) ( $roster['company_name'] ?? '' );

        // Assembled in blocks rather than one format string so an empty
        // roster just drops its paragraph instead of leaving a blank gap.
        $blocks = [
            sprintf( 'Hi %s,', $ownerName ),
            sprintf(
                '%s %d NJILGA membership dues invoice totals $%s.',
                $firm !== '' ? $firm . "'s" : "Your firm's",
                $duesYear,
                $total
            ),
        ];
        if ( $covers !== '' ) {
            $blocks[] = $covers;
        }
        $blocks[] = 'Pay online here: ' . $link;
        $blocks[] = "Thank you,\nNJILGA";

        $subject = sprintf( '%d NJILGA Membership Dues Invoice', $duesYear );
        $body    = implode( "\n\n", $blocks );

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
