<?php
/**
 * My NJILGA → Settings → Invoice Email.
 *
 * Everything about the dues invoice email in one place: who receives it
 * (CC policy, Reply-To), what it says (subject, opening, sign-off with merge
 * tags — see MyNJILGA_Invoice_Email), a preview, and a way to send yourself a
 * test of exactly what you have typed.
 *
 * One form, three buttons:
 *
 *   Save changes      stores the recipients and wording.
 *   Preview           shows the email for the chosen firm using what is in the
 *                     boxes now — saved or not.
 *   Send test email   emails that same message to any address you type, marked
 *                     [TEST]; never to a firm, and with no Cc.
 *
 * Preview and the test are built by MyNJILGA_Invoice_Sender::compose() with
 * the unsaved settings passed as an override, i.e. through the very code a
 * real send uses — so what you test is what firms get — against either an
 * invented example firm or one of the year's real invoices.
 *
 * Edits survive the redirect after Preview / Send test (and after a refused
 * save) in a short-lived per-user draft, so a test never costs you your typing.
 * Nothing in a draft is ever sent to a firm or applied until you Save.
 */
class MyNJILGA_Page_Invoice_Email {

    const ACTION_SUBMIT = 'my_njilga_email_submit';

    /** The `general` settings this tab owns (tests/SettingsTabsTest.php checks every setting has exactly one home). */
    const KEYS = [ 'send_cc_mode', 'send_cc_emails', 'send_reply_to', 'email_subject', 'email_intro', 'email_closing' ];

    /** How long unsaved edits are kept, in seconds. */
    const DRAFT_TTL = 1800;

    // =========================================================================
    // Pure
    // =========================================================================

    /**
     * Merge tags in the three wording fields that aren't ones we know — as
     * "field:tag" entries (e.g. "intro:frim"). A typo would be emailed to firms
     * as literal text, so Save and Send test refuse until it is fixed.
     *
     * @param array<string,string> $v subject / intro / closing (others ignored)
     * @return array<int,string>
     */
    public static function wording_problems( array $v ): array {
        $out = [];
        foreach ( array_keys( MyNJILGA_Invoice_Email::FIELDS ) as $field ) {
            foreach ( MyNJILGA_Invoice_Email::unknown_tags( (string) ( $v[ $field ] ?? '' ) ) as $tag ) {
                $out[] = $field . ':' . $tag;
            }
        }
        return $out;
    }

    /**
     * The test message's banner: it can never be mistaken for a real invoice email.
     *
     * @param array<string,mixed> $m compose()'s result
     */
    public static function test_banner( string $to, array $m, bool $sample ): string {
        $lines = [ '*** TEST — sent only to ' . $to . '. No firm received this. ***' ];
        if ( $sample ) {
            $lines[] = '*** It uses an example firm and a placeholder payment link. ***';
        } else {
            $lines[] = '*** It uses a real firm\'s invoice details; the real email would go to ' . (string) ( $m['to'] ?? '' ) . '. ***';
            if ( ! empty( $m['placeholder'] ) ) {
                $lines[] = '*** That invoice has not been created yet, so the payment link is a placeholder. ***';
            }
        }
        return implode( "\n", $lines );
    }

    // =========================================================================
    // Values and drafts
    // =========================================================================

    /**
     * What is saved.
     *
     * @return array<string,string>
     */
    private static function saved_values(): array {
        $g = MyNJILGA_Dues_Settings::get()['general'];
        return [
            'cc_mode'   => (string) $g['send_cc_mode'],
            'cc_emails' => (string) $g['send_cc_emails'],
            'reply_to'  => (string) $g['send_reply_to'],
            'subject'   => (string) $g['email_subject'],
            'intro'     => (string) $g['email_intro'],
            'closing'   => (string) $g['email_closing'],
            'to'        => '',
            'firm'      => MyNJILGA_Invoice_Email::SAMPLE_FIRM,
        ];
    }

    private static function draft_key(): string {
        return 'njilga_email_draft_' . (int) get_current_user_id();
    }

    /** @return array<string,string>|null */
    private static function load_draft(): ?array {
        $d = get_transient( self::draft_key() );
        return is_array( $d ) ? array_merge( self::saved_values(), array_map( 'strval', $d ) ) : null;
    }

    /** @param array<string,string> $v */
    private static function store_draft( array $v ): void {
        set_transient( self::draft_key(), $v, self::DRAFT_TTL );
    }

    private static function url( array $args = [] ): string {
        return MyNJILGA_Page_Settings::tab_url( 'email', $args );
    }

    // =========================================================================
    // The tab
    // =========================================================================

    public static function render_tab(): void {
        $year  = MyNJILGA_Invoicing::default_dues_year();
        $live  = MyNJILGA_Stripe_Connection::active_mode() === MyNJILGA_Stripe_Connection::MODE_LIVE;
        $draft = ! empty( $_GET['draft'] ) ? self::load_draft() : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $v     = $draft ?? self::saved_values();
        if ( isset( $_GET['firm'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $v['firm'] = sanitize_key( wp_unslash( (string) $_GET['firm'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        }

        self::render_notices();
        if ( $draft ) {
            MyNJILGA_Admin_UI::callout( sprintf( '<strong>These edits are not saved yet.</strong> They are kept here for %d minutes so a preview or test doesn\'t cost you your typing. Click <em>Save changes</em> to keep them, or <a href="%s">discard them</a>.', (int) ( self::DRAFT_TTL / 60 ), esc_url( self::url() ) ), 'info' );
        }
        $problems = self::wording_problems( $v );
        if ( $problems ) {
            MyNJILGA_Admin_UI::callout( self::problems_html( $problems, 'The wording below has' ) . ' It would be emailed to firms as literal text, so <em>Save changes</em> and <em>Send test email</em> will refuse until it is fixed.', 'warning' );
        }

        printf( '<form method="post" action="%s">', esc_url( admin_url( 'admin-post.php' ) ) );
        printf( '<input type="hidden" name="action" value="%s">', esc_attr( self::ACTION_SUBMIT ) );
        wp_nonce_field( self::ACTION_SUBMIT );

        self::render_recipients( $v );
        self::render_wording( $v );
        self::render_controls( $v, $year, $live );

        echo '</form>';

        self::render_preview( $v, $year, $live, $draft !== null );
    }

    private static function render_notices(): void {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $msg = isset( $_GET['msg'] ) ? sanitize_key( wp_unslash( (string) $_GET['msg'] ) ) : '';
        $to  = isset( $_GET['to'] ) ? sanitize_email( wp_unslash( (string) $_GET['to'] ) ) : '';
        $bad = isset( $_GET['bad'] ) ? array_filter( array_map( 'sanitize_text_field', explode( ',', wp_unslash( (string) $_GET['bad'] ) ) ) ) : [];
        $why = isset( $_GET['detail'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['detail'] ) ) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        switch ( $msg ) {
            case 'saved':
                MyNJILGA_Admin_UI::callout( '<strong>Saved.</strong> Firms get this wording from the next invoice email on.', 'success' );
                break;
            case 'refused':
                MyNJILGA_Admin_UI::callout( '<strong>' . esc_html( $why !== '' ? $why : 'Nothing was done' ) . ':</strong> ' . self::problems_html( $bad, 'the wording has' ) . ' Your edits are still in the boxes below.', 'error' );
                break;
            case 'test_sent':
                MyNJILGA_Admin_UI::callout( sprintf( '<strong>Test email sent%s.</strong> It was built from what is in the boxes below, saved or not. Check that inbox (and spam) — only a person can confirm it arrived.', $to !== '' ? ' to ' . esc_html( $to ) : '' ), 'success' );
                break;
            case 'test_failed':
                MyNJILGA_Admin_UI::callout( '<strong>The test email could not be sent.</strong> The site\'s mail isn\'t working; fix that before sending invoices.', 'error' );
                break;
            case 'error':
                MyNJILGA_Admin_UI::callout( '<strong>That didn\'t work:</strong> ' . esc_html( $why ), 'error' );
                break;
        }
    }

    /** "the wording has {frim}, {tota1}" for a list of "field:tag". @param array<int,string> $problems */
    private static function problems_html( array $problems, string $lead ): string {
        $labels = [ 'subject' => 'subject', 'intro' => 'opening', 'closing' => 'sign-off' ];
        $parts  = [];
        foreach ( $problems as $p ) {
            $bits = explode( ':', (string) $p, 2 );
            $f    = sanitize_key( (string) ( $bits[0] ?? '' ) );
            $t    = sanitize_key( (string) ( $bits[1] ?? '' ) );
            if ( isset( $labels[ $f ] ) && $t !== '' ) {
                $parts[] = sprintf( 'the %s has <code>{%s}</code>', esc_html( $labels[ $f ] ), esc_html( $t ) );
            }
        }
        if ( ! $parts ) {
            return '';
        }
        return sprintf(
            '%s %s, which isn&rsquo;t a merge tag (the ones that work: %s).',
            esc_html( $lead ),
            implode( '; ', $parts ),
            implode( ', ', array_map( static function ( $t ) { return '<code>{' . esc_html( $t ) . '}</code>'; }, array_keys( MyNJILGA_Invoice_Email::TAGS ) ) )
        );
    }

    /** @param array<string,string> $v */
    private static function render_recipients( array $v ): void {
        MyNJILGA_Admin_UI::section( 'Who receives it', 'The invoice email always goes to the firm\'s bill-to contact (its Owner). This adds copies and decides where replies go.' );
        echo '<div class="njilga-card njilga-card-pad"><table class="njilga-formtable"><tbody>';
        echo '<tr><th scope="row">Copies (Cc)</th><td><div class="njilga-radio-list">';
        foreach ( MyNJILGA_Dues_Settings::cc_mode_labels() as $val => $label ) {
            printf( '<label class="njilga-check-label"><input type="radio" name="cc_mode" value="%s"%s> <span>%s</span></label>', esc_attr( $val ), checked( $v['cc_mode'], $val, false ), esc_html( $label ) );
        }
        echo '</div>';
        printf( '<div class="njilga-field"><label for="em-cc">Fixed CC list</label><textarea id="em-cc" name="cc_emails" rows="2" class="large-text" placeholder="treasurer@njilga.org, admin@njilga.org">%s</textarea></div>', esc_textarea( $v['cc_emails'] ) );
        echo '</td></tr>';
        echo '<tr><th scope="row"><label for="em-replyto">Reply-To</label></th><td>';
        printf( '<input type="email" id="em-replyto" name="reply_to" value="%s" class="regular-text" placeholder="dues@njilga.org">', esc_attr( $v['reply_to'] ) );
        echo '<p class="njilga-help">Where a firm\'s reply lands. Blank = the site\'s From address, which may not be monitored.</p></td></tr>';
        echo '</tbody></table></div>';
    }

    /** @param array<string,string> $v */
    private static function render_wording( array $v ): void {
        MyNJILGA_Admin_UI::section( 'Wording', 'Leave a box blank to use the standard wording (shown greyed out). The list of who the invoice covers, the payment link and the mail-a-check address (Settings → Payments) are always added for you, so they can\'t be edited away.' );
        echo '<div class="njilga-card njilga-card-pad"><table class="njilga-formtable"><tbody>';
        echo '<tr><th scope="row"><label for="em-subject">Subject</label></th><td>';
        printf( '<input type="text" id="em-subject" name="subject" value="%s" class="large-text" maxlength="%d" placeholder="%s"></td></tr>', esc_attr( $v['subject'] ), (int) MyNJILGA_Invoice_Email::MAX_SUBJECT, esc_attr( MyNJILGA_Invoice_Email::default_template( 'subject' ) ) );
        echo '<tr><th scope="row"><label for="em-intro">Opening</label></th><td>';
        printf( '<textarea id="em-intro" name="intro" rows="4" class="large-text" maxlength="%d" placeholder="%s">%s</textarea><p class="njilga-help">The greeting and the sentence giving the total.</p></td></tr>', (int) MyNJILGA_Invoice_Email::MAX_TEXT, esc_attr( MyNJILGA_Invoice_Email::default_template( 'intro' ) ), esc_textarea( $v['intro'] ) );
        echo '<tr><th scope="row"><label for="em-closing">Sign-off</label></th><td>';
        printf( '<textarea id="em-closing" name="closing" rows="3" class="large-text" maxlength="%d" placeholder="%s">%s</textarea></td></tr>', (int) MyNJILGA_Invoice_Email::MAX_TEXT, esc_attr( MyNJILGA_Invoice_Email::default_template( 'closing' ) ), esc_textarea( $v['closing'] ) );
        echo '<tr><th scope="row">Merge tags</th><td><p class="njilga-help">You can use these in any of the three:</p><ul class="njilga-timeline">';
        foreach ( MyNJILGA_Invoice_Email::TAGS as $tag => $what ) {
            printf( '<li><code>{%s}</code> — %s</li>', esc_html( $tag ), esc_html( $what ) );
        }
        echo '</ul><p class="njilga-help">The same wording is used for every invoice email, including a separate assessment invoice if you bill assessments separately — use <code>{invoice}</code> to name which one it is. Plain text only.</p></td></tr>';
        echo '</tbody></table></div>';
    }

    /** @param array<string,string> $v */
    private static function render_controls( array $v, int $year, bool $live ): void {
        MyNJILGA_Admin_UI::section( 'Preview and test', 'Both use what is in the boxes above right now — saved or not — and the same code that builds a real invoice email. A test goes only to the address you type, never to a firm.' );
        echo '<div class="njilga-card njilga-card-pad"><div class="njilga-inline-fields">';

        echo '<div class="njilga-field"><label for="em-firm"><strong>Show the email for</strong></label><select id="em-firm" name="firm">';
        printf( '<option value="%s"%s>Example firm (made-up details)</option>', esc_attr( MyNJILGA_Invoice_Email::SAMPLE_FIRM ), selected( $v['firm'], MyNJILGA_Invoice_Email::SAMPLE_FIRM, false ) );
        $rows = MyNJILGA_Page_Launch::preview_rows( $year, $live );
        foreach ( array_slice( $rows, 0, MyNJILGA_Page_Launch::LIST_LIMIT ) as $r ) {
            printf( '<option value="%d"%s>%s — %s</option>', (int) $r->id, selected( $v['firm'], (string) (int) $r->id, false ), esc_html( MyNJILGA_Dues_Snapshot::company_name( $r ) ), esc_html( (string) $r->status ) );
        }
        echo '</select></div>';

        $user = wp_get_current_user();
        echo '<div class="njilga-field"><label for="em-to"><strong>Send the test to</strong></label>';
        printf( '<input type="email" id="em-to" name="to" value="%s" placeholder="%s"></div>', esc_attr( $v['to'] ), esc_attr( (string) $user->user_email ) );
        echo '</div>';

        echo '<div class="njilga-actions">';
        echo '<button type="submit" name="do" value="save" class="njilga-btn njilga-btn-primary">Save changes</button> ';
        echo '<button type="submit" name="do" value="preview" class="njilga-btn njilga-btn-outline">Preview</button> ';
        echo '<button type="submit" name="do" value="test" class="njilga-btn njilga-btn-outline">Send test email</button>';
        echo '</div>';
        echo '<p class="njilga-help">Blank "send the test to" = your own address. The test subject starts with <code>[TEST]</code>, and nothing is marked sent or changed on any invoice.</p>';
        echo '</div>';
    }

    /** @param array<string,string> $v */
    private static function render_preview( array $v, int $year, bool $live, bool $isDraft ): void {
        MyNJILGA_Admin_UI::section( 'What a firm will receive', $isDraft ? 'Built from your unsaved edits above.' : 'Built from the saved wording. Click Preview to see unsaved edits.' );
        $m = self::message_for( $v, $year, $live );
        if ( empty( $m['ok'] ) ) {
            MyNJILGA_Admin_UI::callout( '<strong>This email can\'t be built:</strong> ' . esc_html( (string) ( $m['error'] ?? 'unknown error' ) ), 'warning' );
            return;
        }
        if ( ! empty( $m['sample'] ) ) {
            MyNJILGA_Admin_UI::callout( 'This uses an example firm and a placeholder payment link. Pick a real firm above to see its actual roster and amounts.', 'info' );
        } elseif ( ! empty( $m['placeholder'] ) ) {
            MyNJILGA_Admin_UI::callout( 'This firm\'s invoice hasn\'t been created yet, so the payment link is a placeholder; everything else is what it will say.', 'info' );
        }
        MyNJILGA_Page_Launch::render_message( $m );
    }

    /**
     * The composed email for the chosen firm, using the values in $v (the form's) instead of what is saved.
     *
     * @param array<string,string> $v
     * @return array<string,mixed>
     */
    private static function message_for( array $v, int $year, bool $live ): array {
        $override = [
            'subject' => $v['subject'], 'intro' => $v['intro'], 'closing' => $v['closing'],
            'cc_mode' => $v['cc_mode'], 'cc_emails' => $v['cc_emails'], 'reply_to' => $v['reply_to'],
        ];
        if ( ctype_digit( $v['firm'] ) && (int) $v['firm'] > 0 ) {
            foreach ( MyNJILGA_Page_Launch::preview_rows( $year, $live ) as $r ) {
                if ( (int) $r->id === (int) $v['firm'] ) {
                    return MyNJILGA_Invoice_Sender::compose( $r, true, $override ) + [ 'sample' => false ];
                }
            }
            return [ 'ok' => false, 'error' => 'That invoice is no longer available to preview — pick another firm.' ];
        }
        return MyNJILGA_Invoice_Sender::compose( MyNJILGA_Invoice_Email::sample_row( $year ), true, $override ) + [ 'sample' => true ];
    }

    // =========================================================================
    // admin-post handler — checks the capability and a nonce, takes NO
    // parameters (WordPress passes '' to an argument-less action), and ends in
    // a redirect back to this tab.
    // =========================================================================

    public static function handle_submit(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Access denied.' );
        }
        check_admin_referer( self::ACTION_SUBMIT );

        $in = wp_unslash( $_POST );
        $do = sanitize_key( (string) ( $in['do'] ?? 'save' ) );
        $v  = self::parse_post( $in );

        if ( $do === 'preview' ) {
            self::store_draft( $v );
            self::back( [ 'draft' => '1', 'firm' => $v['firm'] ] );
        }

        $problems = self::wording_problems( $v );
        if ( $problems ) {
            self::store_draft( $v );
            self::back( [ 'draft' => '1', 'firm' => $v['firm'], 'msg' => 'refused', 'detail' => $do === 'test' ? 'No test was sent' : 'Nothing was saved', 'bad' => implode( ',', $problems ) ] );
        }

        if ( $do === 'test' ) {
            self::send_test( $v );
        }

        // Save.
        $s                              = MyNJILGA_Dues_Settings::get();
        $s['general']['send_cc_mode']   = $v['cc_mode'];
        $s['general']['send_cc_emails'] = $v['cc_emails'];
        $s['general']['send_reply_to']  = $v['reply_to'];
        $s['general']['email_subject']  = $v['subject'];
        $s['general']['email_intro']    = $v['intro'];
        $s['general']['email_closing']  = $v['closing'];
        MyNJILGA_Dues_Settings::save( $s );
        delete_transient( self::draft_key() );
        self::back( [ 'msg' => 'saved', 'firm' => $v['firm'] ] );
    }

    /**
     * The form's fields, cleaned.
     *
     * @param array<string,mixed> $in
     * @return array<string,string>
     */
    private static function parse_post( array $in ): array {
        $mode = (string) ( $in['cc_mode'] ?? '' );
        $firm = sanitize_key( (string) ( $in['firm'] ?? '' ) );
        return [
            'cc_mode'   => array_key_exists( $mode, MyNJILGA_Dues_Settings::cc_mode_labels() ) ? $mode : MyNJILGA_Dues_Settings::CC_OWNER_ONLY,
            'cc_emails' => sanitize_text_field( (string) ( $in['cc_emails'] ?? '' ) ),
            'reply_to'  => sanitize_email( (string) ( $in['reply_to'] ?? '' ) ),
            'subject'   => MyNJILGA_Invoice_Email::clean( 'subject', (string) ( $in['subject'] ?? '' ) ),
            'intro'     => MyNJILGA_Invoice_Email::clean( 'intro', (string) ( $in['intro'] ?? '' ) ),
            'closing'   => MyNJILGA_Invoice_Email::clean( 'closing', (string) ( $in['closing'] ?? '' ) ),
            'to'        => sanitize_email( trim( (string) ( $in['to'] ?? '' ) ) ),
            'firm'      => ( $firm !== '' && ( ctype_digit( $firm ) || $firm === MyNJILGA_Invoice_Email::SAMPLE_FIRM ) ) ? $firm : MyNJILGA_Invoice_Email::SAMPLE_FIRM,
        ];
    }

    /**
     * Email the message built from $v to the address in it (blank = the signed-in admin's), then redirect.
     *
     * @param array<string,string> $v
     */
    private static function send_test( array $v ): void {
        $raw = trim( (string) ( $_POST['to'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput
        $to  = $v['to'];
        if ( $to === '' && $raw === '' ) {
            $user = wp_get_current_user();
            $to   = sanitize_email( (string) $user->user_email );
        }
        self::store_draft( $v ); // keep the edits across the redirect, whatever happens next
        if ( $to === '' || ! is_email( $to ) ) {
            self::back( [ 'draft' => '1', 'firm' => $v['firm'], 'msg' => 'error', 'detail' => 'That doesn\'t look like a valid email address to send the test to.' ] );
        }

        $m = self::message_for( $v, MyNJILGA_Invoicing::default_dues_year(), MyNJILGA_Stripe_Connection::active_mode() === MyNJILGA_Stripe_Connection::MODE_LIVE );
        if ( empty( $m['ok'] ) ) {
            self::back( [ 'draft' => '1', 'firm' => $v['firm'], 'msg' => 'error', 'detail' => (string) ( $m['error'] ?? 'The email could not be built.' ) ] );
        }

        // To the address typed ONLY: no Cc, and a banner so it can't be mistaken for a real invoice email.
        $headers = [];
        foreach ( (array) $m['headers'] as $h ) {
            if ( stripos( $h, 'Reply-To:' ) === 0 ) {
                $headers[] = $h;
            }
        }
        $sent = wp_mail( $to, '[TEST] ' . (string) $m['subject'], self::test_banner( $to, $m, ! empty( $m['sample'] ) ) . "\n\n" . (string) $m['body'], $headers );
        self::back( [ 'draft' => '1', 'firm' => $v['firm'], 'msg' => $sent ? 'test_sent' : 'test_failed', 'to' => $to ] );
    }

    /** Redirect to this tab and stop. @param array<string,mixed> $args */
    private static function back( array $args = [] ): void {
        wp_safe_redirect( self::url( $args ) );
        exit;
    }
}
