<?php
/**
 * Step 5 — listens for FluentCart's own "order paid" hook and cascades
 * the result onto every member of the frozen roster snapshot (never a
 * fresh Company query — see the invoicing spec's "Why a Snapshot Table,
 * Not a Live Re-Query"):
 *
 *   - Everyone gets the year-specific "Dues Paid {year}" tag (a
 *     historical record of which year they were covered for), whether or
 *     not they have a WordPress login.
 *   - Everyone ALSO gets the plugin's evergreen `dues-paid` tag (and
 *     loses `unpaid-dues` if they carried it) — this is a deliberate
 *     addition beyond the invoicing spec's literal wording, done because
 *     every existing report in this plugin (Active Paid Members,
 *     Membership by Firm, the Executive Summary, the dashboards) reads
 *     that evergreen tag, not a year-suffixed one. Without this, those
 *     reports would go stale the moment real invoicing replaced however
 *     dues-paid was being tagged before.
 *   - The `professional` WordPress role is granted only where a linked
 *     WP user exists (see MyNJILGA_Payment_Listener::WP_ROLE) — not every
 *     FluentCRM contact necessarily has one; this is skipped cleanly
 *     rather than erroring, per the spec (creating an account here is
 *     assumed to be handled elsewhere, e.g. first login/registration).
 */
class MyNJILGA_Payment_Listener {

    const WP_ROLE = 'professional';

    public static function register(): void {
        add_action( 'fluent_cart/order_paid_done', [ __CLASS__, 'handle' ], 10, 1 );
    }

    /**
     * @param array{order?:object} $data
     */
    public static function handle( $data ): void {
        $order = is_array( $data ) ? ( $data['order'] ?? null ) : null;
        if ( ! $order || empty( $order->id ) ) {
            return;
        }

        $invoiceRow = MyNJILGA_Dues_Invoice_Table::get_by_order_id( (int) $order->id );
        if ( ! $invoiceRow ) {
            return; // Not a dues invoice — some other FluentCart order type.
        }
        if ( $invoiceRow->status === MyNJILGA_Dues_Invoice_Table::STATUS_PAID ) {
            return; // Already processed — don't double-tag on a retried/duplicate hook fire.
        }

        $roster   = json_decode( (string) $invoiceRow->roster_snapshot, true );
        $members  = $roster['members'] ?? [];
        $duesYear = (int) $invoiceRow->dues_year;
        $yearTagId = MyNJILGA_Tags::get_or_create_by_title( 'Dues Paid ' . $duesYear );

        foreach ( $members as $member ) {
            $contact = \FluentCrm\App\Models\Subscriber::find( (int) ( $member['contact_id'] ?? 0 ) );
            if ( ! $contact ) {
                continue;
            }

            if ( $yearTagId ) {
                $contact->attachTags( [ $yearTagId ] );
            }
            MyNJILGA_Tags::attach( $contact, MyNJILGA_Tags::SLUG_DUES_PAID );
            MyNJILGA_Tags::detach( $contact, MyNJILGA_Tags::SLUG_UNPAID_DUES );

            if ( ! empty( $contact->user_id ) ) {
                $user = get_user_by( 'id', (int) $contact->user_id );
                if ( $user ) {
                    $user->add_role( self::WP_ROLE );
                }
            }
        }

        MyNJILGA_Dues_Invoice_Table::mark_paid( (int) $invoiceRow->id );

        MyNJILGA_Invoicing_Notes::log(
            (int) $invoiceRow->fluentcrm_company_id,
            'Dues invoice paid',
            sprintf( '%d invoice paid in full — %d member(s) marked current.', $duesYear, count( $members ) )
        );
    }
}
