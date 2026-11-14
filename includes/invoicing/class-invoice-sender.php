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
     * Build the email for an invoice row WITHOUT sending it: who it goes to,
     * the subject, the body and the headers (CC / Reply-To per Settings) — or
     * why it can't be built. send_for_row() sends exactly this, and the Launch
     * Wizard shows and samples exactly this, so what is previewed is what goes
     * out.
     *
     * $preview = true builds the email for an invoice that has not been
     * created yet, with a placeholder where the payment link will be (the
     * Launch Wizard's preview and sample). Sending never uses it.
     *
     * $override tries wording and recipient settings that are NOT saved yet
     * (subject, intro, closing, cc_mode, cc_emails, reply_to — any subset;
     * the rest come from Settings): the Invoice Email tab uses it so "Send
     * test" and "Preview" show unsaved edits through this very code path.
     *
     * @param array<string,string>|null $override
     * @return array{ok:bool,error?:string,to?:string,subject?:string,body?:string,headers?:array<int,string>,bill_to?:array<string,mixed>,dues_year?:int,assessment?:bool,total?:string,placeholder?:bool}
     */
    public static function compose( object $invoiceRow, bool $preview = false, ?array $override = null ): array {
        $placeholder = false;
        if ( empty( $invoiceRow->hosted_invoice_url ) ) {
            if ( ! $preview ) {
                return [ 'ok' => false, 'error' => 'No order on this row yet — create the invoice first.' ];
            }
            $placeholder = true;
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

        $link = $placeholder ? MyNJILGA_Invoice_Email::PLACEHOLDER_LINK : (string) ( $invoiceRow->hosted_invoice_url ?? '' );
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

        $tpl = array_merge( MyNJILGA_Invoice_Email::settings(), array_intersect_key( (array) $override, [ 'subject' => 1, 'intro' => 1, 'closing' => 1 ] ) );
        $msg = MyNJILGA_Invoice_Email::build( [
            'is_assessment'    => $isAssess,
            'year'             => $duesYear,
            'firm'             => $firm,
            'total'            => $total,
            'name'             => (string) $billTo['name'],
            'first_name'       => (string) $billTo['first_name'],
            'assessment_label' => (string) ( $members[0]['assessment_label'] ?? 'assessment' ),
            'covers'           => $covers,
            'link'             => $link,
            'remittance'       => (string) MyNJILGA_Stripe_Connection::setting( 'remittance_address', '' ),
            'subject_tpl'      => $tpl['subject'],
            'intro_tpl'        => $tpl['intro'],
            'closing_tpl'      => $tpl['closing'],
        ] );

        return [
            'ok'         => true,
            'to'         => (string) $billTo['email'],
            'subject'    => $msg['subject'],
            'body'       => $msg['body'],
            'headers'    => self::headers( $billTo['email'], $members, $override ),
            'bill_to'    => $billTo,
            'dues_year'  => $duesYear,
            'assessment' => $isAssess,
            'total'      => $total,
            'placeholder' => $placeholder,
        ];
    }

    /**
     * Email one invoice to its firm. Every path that sends goes through
     * here, and here the invoice is claimed atomically first
     * (MyNJILGA_Dues_Invoice_Table::claim_for_email()): of two senders that
     * read the same row at once, one sends and the other gets
     * claimed=false and sends nothing. $invoiceRow is the row as the caller
     * read it — the claim only succeeds if it is still exactly that.
     *
     * @return array{ok:bool, error?:string, claimed?:bool}
     */
    public static function send_for_row( object $invoiceRow ): array {
        $m = self::compose( $invoiceRow );
        if ( empty( $m['ok'] ) ) {
            return [ 'ok' => false, 'error' => (string) $m['error'] ];
        }

        try {
            $stamp = MyNJILGA_Dues_Invoice_Table::claim_for_email( $invoiceRow );
        } catch ( \Throwable $e ) {
            // A database failure: a real error for this invoice, not contention.
            return [ 'ok' => false, 'error' => $e->getMessage() ];
        }
        if ( $stamp === null ) {
            return [ 'ok' => false, 'claimed' => false, 'error' => 'Another send had this invoice at the same moment, or it changed (paid, voided, emailed) since it was read — it was not emailed again.' ];
        }

        // Whatever happens in the mail call — false, or an exception from a
        // mail plugin — an email that didn't go hands the claim back, so the
        // invoice is not left marked emailed.
        try {
            $mailed = wp_mail( $m['to'], $m['subject'], $m['body'], $m['headers'] );
        } catch ( \Throwable $e ) {
            MyNJILGA_Dues_Invoice_Table::release_email_claim( $invoiceRow, $stamp );
            return [ 'ok' => false, 'error' => 'The mail system failed while sending: ' . $e->getMessage() ];
        }
        if ( ! $mailed ) {
            MyNJILGA_Dues_Invoice_Table::release_email_claim( $invoiceRow, $stamp );
            return [ 'ok' => false, 'error' => "wp_mail() failed — check the site's mail configuration." ];
        }

        MyNJILGA_Dues_Invoice_Table::mark_sent( [ (int) $invoiceRow->id ] );
        MyNJILGA_Dues_Invoice_Table::clear_error( (int) $invoiceRow->id );

        $ccCount = 0;
        foreach ( $m['headers'] as $h ) {
            if ( stripos( $h, 'Cc:' ) === 0 ) {
                $ccCount = count( array_filter( explode( ',', substr( $h, 3 ) ) ) );
            }
        }
        $billTo = $m['bill_to'];
        MyNJILGA_Invoicing_Notes::log(
            (int) $invoiceRow->fluentcrm_company_id,
            'Dues invoice sent',
            sprintf(
                '%d %s invoice sent to %s (%s)%s — total %s.',
                (int) $m['dues_year'],
                $m['assessment'] ? 'assessment' : 'dues',
                $billTo['name'],
                $billTo['email'],
                $ccCount > 0 ? sprintf( ' with %d CC', $ccCount ) : '',
                $m['total']
            )
        );

        return [ 'ok' => true ];
    }

    /**
     * CC / Reply-To headers per Settings → Dues & Billing.
     *
     * @param array<int,array<string,mixed>> $members
     * @param array<string,string>|null      $override cc_mode / cc_emails / reply_to to use instead of the saved ones (a preview of unsaved edits)
     * @return array<int,string>
     */
    private static function headers( string $toEmail, array $members, ?array $override = null ): array {
        $headers = [];
        $mode    = (string) ( $override['cc_mode'] ?? MyNJILGA_Dues_Settings::general( 'send_cc_mode', MyNJILGA_Dues_Settings::CC_OWNER_ONLY ) );
        $cc      = [];

        if ( $mode === MyNJILGA_Dues_Settings::CC_ALL_MEMBERS ) {
            foreach ( $members as $m ) {
                $email = sanitize_email( (string) ( $m['email'] ?? '' ) );
                if ( $email !== '' && strcasecmp( $email, $toEmail ) !== 0 ) {
                    $cc[] = $email;
                }
            }
        } elseif ( $mode === MyNJILGA_Dues_Settings::CC_CUSTOM ) {
            foreach ( preg_split( '/[\s,;]+/', (string) ( $override['cc_emails'] ?? MyNJILGA_Dues_Settings::general( 'send_cc_emails', '' ) ) ) as $raw ) {
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

        $replyTo = sanitize_email( (string) ( $override['reply_to'] ?? MyNJILGA_Dues_Settings::general( 'send_reply_to', '' ) ) );
        if ( $replyTo !== '' ) {
            $headers[] = 'Reply-To: ' . $replyTo;
        }
        return $headers;
    }
}
