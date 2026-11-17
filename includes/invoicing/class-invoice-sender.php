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

    /** compose() / send_for_row() $variant: the invoice emailed again as a reminder. */
    const KIND_REMINDER = 'reminder';

    /** How many times an email the mail system refused is tried: the first, and one more after a pause. */
    const SEND_ATTEMPTS = 2;

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
     * $variant 'reminder' builds the invoice as a reminder that it is unpaid ("Reminder:" subject and
     * a line saying so) — for an invoice already emailed, sent again. Anything else is the invoice itself.
     *
     * @param array<string,string>|null $override
     * @return array{ok:bool,error?:string,to?:string,subject?:string,body?:string,headers?:array<int,string>,bill_to?:array<string,mixed>,dues_year?:int,assessment?:bool,total?:string,placeholder?:bool,reminder?:bool}
     */
    public static function compose( object $invoiceRow, bool $preview = false, ?array $override = null, string $variant = '' ): array {
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

        $tpl = array_merge( MyNJILGA_Invoice_Email::settings(), array_intersect_key( (array) $override, array_flip( array_keys( MyNJILGA_Invoice_Email::FIELDS ) ) ) );
        $in  = [
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
        ];
        // An invoice that replaces an earlier one (MyNJILGA_Invoice_Update) says so —
        // read from the frozen snapshot, so every path that emails it carries the notice.
        $replaces = is_array( $snapshot['replaces'] ?? null ) ? $snapshot['replaces'] : [];
        if ( $replaces ) {
            $in['replaces'] = [
                'number' => (string) ( $replaces['number'] ?? '' ),
                'total'  => isset( $replaces['total_cents'] ) ? MyNJILGA_Invoicing::money( (int) $replaces['total_cents'] ) : '',
            ];
        }
        if ( $variant === self::KIND_REMINDER ) {
            $in['reminder'] = [ 'number' => (string) ( $invoiceRow->gateway_invoice_number ?? '' ), 'total' => $total ];
        }
        $msg = MyNJILGA_Invoice_Email::build( $in );

        // The HTML version: the same content, plus the invoice's lines as a
        // table, its number and due date, the header image and the custom heading
        // and button wording.
        $lines   = MyNJILGA_Dues_Roster::email_rows( $members, $duesYear, $kind );
        $htmlIn  = $in + [
            'rows'           => $lines['rows'],
            'covers_note'    => $lines['note'],
            'invoice_number' => (string) ( $invoiceRow->gateway_invoice_number ?? '' ),
            'due_date'       => self::due_date( (string) ( $invoiceRow->due_date ?? '' ) ),
            'logo_url'       => self::logo_url( $override ),
            'site_name'      => function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'name' ) : '',
            'site_url'       => function_exists( 'home_url' ) ? (string) home_url( '/' ) : '',
            'heading_tpl'    => (string) ( $tpl['heading'] ?? '' ),
            'button_tpl'     => (string) ( $tpl['button'] ?? '' ),
        ];

        return [
            'ok'         => true,
            'to'         => (string) $billTo['email'],
            'subject'    => $msg['subject'],
            'body'       => $msg['body'],
            'html'       => MyNJILGA_Invoice_Email::build_html( $htmlIn ),
            'html_in'    => $htmlIn,
            'headers'    => self::headers( $billTo['email'], $members, $override ),
            'bill_to'    => $billTo,
            'dues_year'  => $duesYear,
            'assessment' => $isAssess,
            'total'      => $total,
            'placeholder' => $placeholder,
            'reminder'    => $variant === self::KIND_REMINDER,
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
     * @return array{ok:bool, error?:string, claimed?:bool, transport?:bool, warning?:string}
     *   claimed=false: another sender had it — nothing sent, not an error of this invoice's.
     *   transport=true: the mail system failed — nothing went; the claim was handed back.
     */
    public static function send_for_row( object $invoiceRow, string $variant = '' ): array {
        $m = self::compose( $invoiceRow, false, null, $variant );
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

        // The mail call. A claim is handed back only when the email clearly
        // did NOT reach the firm — a duplicate can't be taken back, so when
        // in doubt the invoice stays marked emailed, with a note saying so:
        //  - wp_mail() false because the relay rejected only some CC
        //    addresses (PHPMailer still delivered to the rest, the bill-to
        //    included): it went. Kept, with a warning naming the problem.
        //  - an exception AFTER the message was handed off (a
        //    wp_mail_succeeded listener, a replacement wp_mail()): it went.
        //
        // Pacing and the one retry. Mail services cap how fast they accept messages (Emailit: 2 a
        // second, answered with "Too many requests"), and WordPress sees that as wp_mail() failing.
        // Each attempt first takes a slot from the shared pacer (MyNJILGA_Mail_Pacer), so the
        // emails of a scheduled batch, of background email runs and of a hand-send are spaced
        // out together. An email refused anyway, with nothing to suggest it was delivered, is
        // tried once more after a pause: a refusal like that sent nothing, and a second try is
        // exactly what the person would otherwise do by hand.
        $attempt = 0;
        do {
            $attempt++;
            MyNJILGA_Mail_Pacer::wait();
            $failure  = null;
            $listener = static function ( $error ) use ( &$failure ): void { $failure = $error; };
            add_action( 'wp_mail_failed', $listener );
            $handedOffBefore = function_exists( 'did_action' ) ? did_action( 'wp_mail_succeeded' ) : 0;
            $thrown          = null;
            try {
                $mailed = self::deliver( (string) $m['to'], (string) $m['subject'], $m, (array) $m['headers'] );
            } catch ( \Throwable $e ) {
                $mailed = false;
                $thrown = $e;
            }
            remove_action( 'wp_mail_failed', $listener );
            $handedOff = function_exists( 'did_action' ) && did_action( 'wp_mail_succeeded' ) > $handedOffBefore;
            $failText  = is_object( $failure ) && method_exists( $failure, 'get_error_message' ) ? (string) $failure->get_error_message() : '';
            $retry     = ! $mailed && $thrown === null && ! $handedOff && ! self::partly_delivered( $failText, (string) $m['to'] ) && $attempt < self::SEND_ATTEMPTS;
            if ( $retry ) {
                MyNJILGA_Mail_Pacer::pause_before_retry();
            }
        } while ( $retry );

        $warning = '';
        if ( ! $mailed ) {
            if ( $thrown !== null && $handedOff ) {
                $warning = 'Emailed, but something on the site failed right after sending: ' . $thrown->getMessage();
            } elseif ( $thrown === null && self::partly_delivered( $failText, (string) $m['to'] ) ) {
                $warning = 'Emailed, but the mail server refused some CC addresses — ' . $failText;
            } else {
                MyNJILGA_Dues_Invoice_Table::release_email_claim( $invoiceRow, $stamp );
                return [
                    'ok'        => false,
                    'transport' => true,
                    'error'     => $thrown !== null
                        ? 'The mail system failed while sending: ' . $thrown->getMessage()
                        : "wp_mail() failed — check the site's mail configuration." . ( $failText !== '' ? ' (' . $failText . ')' : '' ),
                ];
            }
        }

        // The claim already marked it emailed (status sent, sent_at = the
        // claim's stamp); all that's left is to drop the claim's
        // "not confirmed" marker — or replace it with the warning.
        if ( $warning !== '' ) {
            MyNJILGA_Dues_Invoice_Table::set_error( (int) $invoiceRow->id, $warning );
        } else {
            MyNJILGA_Dues_Invoice_Table::clear_error( (int) $invoiceRow->id );
        }

        $ccCount = 0;
        foreach ( $m['headers'] as $h ) {
            if ( stripos( $h, 'Cc:' ) === 0 ) {
                $ccCount = count( array_filter( explode( ',', substr( $h, 3 ) ) ) );
            }
        }
        $billTo = $m['bill_to'];
        MyNJILGA_Invoicing_Notes::log(
            (int) $invoiceRow->fluentcrm_company_id,
            $variant === self::KIND_REMINDER ? 'Dues invoice reminder sent' : 'Dues invoice sent',
            sprintf(
                '%d %s invoice%s sent to %s (%s)%s — total %s.',
                (int) $m['dues_year'],
                $m['assessment'] ? 'assessment' : 'dues',
                $variant === self::KIND_REMINDER ? ' reminder' : '',
                $billTo['name'],
                $billTo['email'],
                $ccCount > 0 ? sprintf( ' with %d CC', $ccCount ) : '',
                $m['total']
            )
        );

        return $warning !== '' ? [ 'ok' => true, 'warning' => $warning ] : [ 'ok' => true ];
    }

    /**
     * Send a composed invoice email: the HTML version, with the plain-text
     * version attached for mail apps that don't show HTML. Every path uses
     * this — the real send, and the tests and samples staff send
     * themselves, which pass a $banner ("TEST — …") shown at the top of
     * both versions. May throw (a mail plugin); callers handle that.
     *
     * @param array<string,mixed> $m       compose()'s result
     * @param array<int,string>   $headers
     */
    public static function deliver( string $to, string $subject, array $m, array $headers, string $banner = '' ): bool {
        $html = isset( $m['html_in'] ) ? MyNJILGA_Invoice_Email::build_html( (array) $m['html_in'] + [ 'banner' => $banner ] ) : '';
        $text = ( $banner !== '' ? $banner . "\n\n" : '' ) . (string) ( $m['body'] ?? '' );
        if ( $html === '' ) {
            return wp_mail( $to, $subject, $text, $headers );
        }
        $headers[] = 'Content-Type: text/html; charset=UTF-8';
        // wp_mail() clears AltBody before it fires phpmailer_init, so this is
        // the moment to add the plain-text part — for this message only.
        $alt = static function ( $phpmailer ) use ( $text ): void {
            $phpmailer->AltBody = $text;
        };
        add_action( 'phpmailer_init', $alt );
        try {
            return wp_mail( $to, $subject, $html, $headers );
        } finally {
            remove_action( 'phpmailer_init', $alt );
        }
    }

    /** "December 31, 2026" from a Y-m-d, or '' when there's none. */
    private static function due_date( string $ymd ): string {
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}/', $ymd ) || strpos( $ymd, '0000' ) === 0 ) {
            return '';
        }
        $ts = strtotime( substr( $ymd, 0, 10 ) . ' 12:00:00' );
        if ( ! $ts ) {
            return '';
        }
        return function_exists( 'date_i18n' ) ? date_i18n( 'F j, Y', $ts ) : gmdate( 'F j, Y', $ts );
    }

    /**
     * The header image across the top of the HTML email: the one set on
     * the Invoice Email tab, else NJILGA's header image.
     *
     * @param array<string,string>|null $override unsaved Invoice Email edits (logo_url)
     */
    public static function logo_url( ?array $override = null ): string {
        $set = trim( (string) ( $override['logo_url'] ?? ( class_exists( 'MyNJILGA_Dues_Settings' ) ? MyNJILGA_Dues_Settings::general( 'email_logo_url', '' ) : '' ) ) );
        return $set !== '' ? $set : MyNJILGA_Invoice_Email::DEFAULT_HEADER_IMAGE;
    }

    /**
     * PURE: whether a failed wp_mail() still reached the firm — PHPMailer's
     * "recipients failed" error (the relay refused some addresses at RCPT
     * TO, and DATA went to the rest) that does not name the bill-to
     * address among those refused.
     */
    public static function partly_delivered( string $errorMessage, string $to ): bool {
        if ( $errorMessage === '' || $to === '' || stripos( $errorMessage, 'The following recipients failed' ) === false ) {
            return false;
        }
        return stripos( $errorMessage, $to ) === false;
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
