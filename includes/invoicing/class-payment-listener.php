<?php
/**
 * Step 5 (spec §7) — payment settles the whole invoice at once. Registered
 * through the gateway's "order paid" hook (FluentCart:
 * `fluent_cart/order_paid_done`); receives an order id, looks up the
 * invoice row, and cascades onto EVERY member of the frozen snapshot —
 * never a fresh Company query.
 *
 * Dues / combined invoices — each member gets:
 *   - the year-specific paid tag ("Dues Paid 2027", pattern in Settings),
 *     a permanent record of which year they were covered for;
 *   - the evergreen paid tag (`dues-paid`) and loses the evergreen unpaid
 *     tag — this is what every existing report in the plugin reads;
 *   - their category's WordPress role (Settings → category mapping),
 *     best-effort: only where a linked WP user exists and the role is
 *     defined on the site. A contact with no WP account is skipped
 *     cleanly, never an error.
 * Assessment-only invoices — each member gets "Assessment Paid {year}";
 * dues tags and roles are untouched (the dinner isn't the membership).
 *
 * Idempotent: a row already marked paid is ignored on a duplicate fire.
 */
class MyNJILGA_Payment_Listener {

    /** Legacy constant kept for anything still referring to it; the role now comes from Settings. */
    const WP_ROLE = 'professional';

    public static function register(): void {
        MyNJILGA_Invoicing::gateway()->on_order_paid( [ __CLASS__, 'handle_order_paid' ] );
    }

    public static function handle_order_paid( int $orderId ): void {
        if ( $orderId <= 0 ) {
            return;
        }
        $invoiceRow = MyNJILGA_Dues_Invoice_Table::get_by_order_id( $orderId );
        if ( ! $invoiceRow ) {
            return; // Not a dues invoice — some other order.
        }
        if ( $invoiceRow->status === MyNJILGA_Dues_Invoice_Table::STATUS_PAID ) {
            return; // Already processed.
        }

        try {
            self::settle( $invoiceRow );
        } catch ( \Throwable $e ) {
            // Never let a tagging hiccup bubble into the commerce plugin's
            // payment pipeline; record it on the row for the dashboard.
            MyNJILGA_Dues_Invoice_Table::set_error( (int) $invoiceRow->id, 'Paid, but post-payment processing failed: ' . $e->getMessage() );
        }
    }

    /**
     * Apply the paid outcome for one invoice row. Public so an admin
     * "mark paid manually" path (offline check) can reuse it.
     *
     * @return array{members:int,roles_granted:int,roles_skipped:int}
     */
    public static function settle( object $invoiceRow, string $source = 'payment' ): array {
        $snapshot   = MyNJILGA_Dues_Snapshot::decode( $invoiceRow );
        $members    = $snapshot['members'];
        $duesYear   = (int) $invoiceRow->dues_year;
        $settles    = MyNJILGA_Dues_Snapshot::settles_dues( $invoiceRow );
        $crmActive  = MyNJILGA_Members_Data::fluentcrm_active();

        $paidTag   = (string) MyNJILGA_Dues_Settings::general( 'paid_tag', 'dues-paid' );
        $unpaidTag = (string) MyNJILGA_Dues_Settings::general( 'unpaid_tag', 'unpaid-dues' );
        $yearTitle = $settles
            ? MyNJILGA_Dues_Settings::year_tag( 'year_paid_tag_pattern', $duesYear )
            : MyNJILGA_Dues_Settings::year_tag( 'assessment_paid_pattern', $duesYear );
        $yearTagId = $crmActive ? MyNJILGA_Tags::get_or_create_by_title( $yearTitle ) : null;

        $granted = 0; $skipped = 0; $touched = 0;
        foreach ( $members as $member ) {
            $contact = $crmActive ? \FluentCrm\App\Models\Subscriber::find( (int) ( $member['contact_id'] ?? 0 ) ) : null;
            if ( ! $contact ) {
                $skipped++;
                continue;
            }
            $touched++;

            if ( $yearTagId ) {
                $contact->attachTags( [ $yearTagId ] );
            }
            if ( ! $settles ) {
                continue;
            }

            MyNJILGA_Tags::attach_slug( $contact, $paidTag );
            MyNJILGA_Tags::detach_slug( $contact, $unpaidTag );

            if ( self::grant_role( $contact, (string) ( $member['role'] ?? '' ) ) ) {
                $granted++;
            } else {
                $skipped++;
            }
        }

        MyNJILGA_Dues_Invoice_Table::mark_paid( (int) $invoiceRow->id );
        MyNJILGA_Dues_Invoice_Table::clear_error( (int) $invoiceRow->id );

        MyNJILGA_Invoicing_Notes::log(
            (int) $invoiceRow->fluentcrm_company_id,
            $settles ? 'Dues invoice paid' : 'Assessment invoice paid',
            sprintf(
                '%d %s invoice paid in full (%s) — %d member(s) %s; WordPress role granted to %d, %d had no linked account/role.',
                $duesYear,
                $settles ? 'dues' : 'assessment',
                $source,
                count( $members ),
                $settles ? 'marked current' : 'marked assessment paid',
                $granted,
                $skipped
            )
        );

        return [ 'members' => $touched, 'roles_granted' => $granted, 'roles_skipped' => $skipped ];
    }

    /**
     * Best-effort role grant. False when there's no linked WP user, no
     * role configured, or the role isn't defined on this site.
     *
     * @param \FluentCrm\App\Models\Subscriber $contact
     */
    public static function grant_role( $contact, string $role ): bool {
        $role = sanitize_key( $role );
        if ( $role === '' || ! get_role( $role ) ) {
            return false;
        }
        $userId = (int) ( $contact->user_id ?? 0 );
        if ( $userId <= 0 && ! empty( $contact->email ) ) {
            $user   = get_user_by( 'email', (string) $contact->email );
            $userId = $user ? (int) $user->ID : 0;
        }
        if ( $userId <= 0 ) {
            return false;
        }
        $user = get_user_by( 'id', $userId );
        if ( ! $user ) {
            return false;
        }
        if ( ! in_array( $role, (array) $user->roles, true ) ) {
            $user->add_role( $role );
        }
        return true;
    }
}
