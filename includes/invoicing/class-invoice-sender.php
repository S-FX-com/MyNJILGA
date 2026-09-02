<?php
/**
 * Step 4 (spec §7) — manual send: emails the bill-to contact the payment
 * link (CC per the Settings policy: nobody / every member on the invoice
 * / a fixed list), marks the row sent, and logs a FluentCRM Company Note.
 *
 * Any recipient can pay — the link is the order's public pay-now URL,
 * not tied to a login.
 */
class MyNJILGA_Invoice_Sender {

    /**
     * @return array{ok:bool, error?:string}
     */
    public static function send_for_row( object $invoiceRow ): array {
        if ( empty( $invoiceRow->hosted_invoice_url ) ) {
            return [ 'ok' => false, 'error' => 'No order on this row yet — create the invoice first.' ];
        }

        $snapshot = MyNJILGA_Dues_Snapshot::decode( $invoiceRow );
        $billTo   = MyNJILGA_Dues_Snapshot::bill_to( $invoiceRow );
        if ( $billTo['email'] === '' && MyNJILGA_Members_Data::fluentcrm_active() ) {
            $contact = \FluentCrm\App\Models\Subscriber::find( (int) ( $invoiceRow->bill_to_contact_id ?: $invoiceRow->fluentcrm_owner_contact_id ) );
            if ( $contact ) {
                $billTo = MyNJILGA_Dues_Snapshot::person( [
                    'contact_id' => (int) $contact->id,
                    'name'       => MyNJILGA_Members_Data::display_name( $contact ),
                    'email'      => (string) ( $contact->email ?? '' ),
                ] );
            }
        }
        if ( $billTo['email'] === '' ) {
            return [ 'ok' => false, 'error' => 'Bill-to contact not found or has no email on file.' ];
        }

        $link = (string) ( $invoiceRow->hosted_invoice_url ?? '' );
        if ( $link === '' ) {
            return [ 'ok' => false, 'error' => 'Could not build a payment link for this order.' ];
        }

        $duesYear = (int) $invoiceRow->dues_year;
        $kind     = (string) ( $snapshot['invoice_kind'] ?? MyNJILGA_Dues_Snapshot::KIND_COMBINED );
        $members  = $snapshot['members'];
        $firm     = (string) ( $snapshot['company']['name'] ?? '' );
        $total    = MyNJILGA_Invoicing::money( (int) $invoiceRow->total_amount_cents );
        $isAssess = $kind === MyNJILGA_Dues_Snapshot::KIND_ASSESSMENT;

        $covers = MyNJILGA_Dues_Roster::email_summary( $members, $duesYear, $kind );

        $blocks = [ sprintf( 'Hi %s,', $billTo['name'] ) ];
        if ( $isAssess ) {
            $blocks[] = sprintf( 'Your %d NJILGA %s invoice totals %s.', $duesYear, (string) ( $members[0]['assessment_label'] ?? 'assessment' ), $total );
        } else {
            $blocks[] = sprintf(
                '%s %d NJILGA membership dues invoice totals %s.',
                $firm !== '' ? $firm . "'s" : 'Your',
                $duesYear,
                $total
            );
        }
        if ( $covers !== '' ) {
            $blocks[] = $covers;
        }
        $blocks[] = 'Pay online here: ' . $link;
        $blocks[] = "Thank you,\nNJILGA";

        $subject = $isAssess
            ? sprintf( '%d NJILGA %s Invoice', $duesYear, (string) ( $members[0]['assessment_label'] ?? 'Assessment' ) )
            : sprintf( '%d NJILGA Membership Dues Invoice%s', $duesYear, $firm !== '' ? ' — ' . $firm : '' );
        $body    = implode( "\n\n", $blocks );
        $headers = self::headers( $billTo['email'], $members );

        if ( ! wp_mail( $billTo['email'], $subject, $body, $headers ) ) {
            return [ 'ok' => false, 'error' => "wp_mail() failed — check the site's mail configuration." ];
        }

        MyNJILGA_Dues_Invoice_Table::mark_sent( [ (int) $invoiceRow->id ] );
        MyNJILGA_Dues_Invoice_Table::clear_error( (int) $invoiceRow->id );

        $ccCount = 0;
        foreach ( $headers as $h ) {
            if ( stripos( $h, 'Cc:' ) === 0 ) {
                $ccCount = count( array_filter( explode( ',', substr( $h, 3 ) ) ) );
            }
        }
        MyNJILGA_Invoicing_Notes::log(
            (int) $invoiceRow->fluentcrm_company_id,
            'Dues invoice sent',
            sprintf(
                '%d %s invoice sent to %s (%s)%s — total %s.',
                $duesYear,
                $isAssess ? 'assessment' : 'dues',
                $billTo['name'],
                $billTo['email'],
                $ccCount > 0 ? sprintf( ' with %d CC', $ccCount ) : '',
                $total
            )
        );

        return [ 'ok' => true ];
    }

    /**
     * CC / Reply-To headers per Settings → Dues & Billing.
     *
     * @param array<int,array<string,mixed>> $members
     * @return array<int,string>
     */
    private static function headers( string $toEmail, array $members ): array {
        $headers = [];
        $mode    = (string) MyNJILGA_Dues_Settings::general( 'send_cc_mode', MyNJILGA_Dues_Settings::CC_OWNER_ONLY );
        $cc      = [];

        if ( $mode === MyNJILGA_Dues_Settings::CC_ALL_MEMBERS ) {
            foreach ( $members as $m ) {
                $email = sanitize_email( (string) ( $m['email'] ?? '' ) );
                if ( $email !== '' && strcasecmp( $email, $toEmail ) !== 0 ) {
                    $cc[] = $email;
                }
            }
        } elseif ( $mode === MyNJILGA_Dues_Settings::CC_CUSTOM ) {
            foreach ( preg_split( '/[\s,;]+/', (string) MyNJILGA_Dues_Settings::general( 'send_cc_emails', '' ) ) as $raw ) {
                $email = sanitize_email( $raw );
                if ( $email !== '' && strcasecmp( $email, $toEmail ) !== 0 ) {
                    $cc[] = $email;
                }
            }
        }
        $cc = array_values( array_unique( array_map( 'strtolower', $cc ) ) );
        if ( $cc ) {
            $headers[] = 'Cc: ' . implode( ', ', $cc );
        }

        $replyTo = sanitize_email( (string) MyNJILGA_Dues_Settings::general( 'send_reply_to', '' ) );
        if ( $replyTo !== '' ) {
            $headers[] = 'Reply-To: ' . $replyTo;
        }
        return $headers;
    }
}
