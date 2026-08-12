<?php
/**
 * Step 6 — end-of-year downgrade. Run manually (a "Run Downgrade Sweep"
 * button in the dashboard) rather than a silent cron — stripping a role
 * is consequential enough to want a human eye on it, and NJILGA's own
 * reminder cadence lives outside this plugin, so there's no reason this
 * needs to fire automatically on a schedule this plugin controls.
 *
 * For every njilga_dues_invoices row still not paid/excluded/downgraded
 * as of whenever the admin runs the sweep: strip `professional`, add the
 * year-specific "Unpaid Dues {year}" tag, and — matching the payment
 * listener's dual-tag approach — also flip the evergreen `unpaid-dues` /
 * `dues-paid` tags so the plugin's existing reports keep reflecting
 * reality. Mark the row downgraded.
 */
class MyNJILGA_Downgrade_Sweep {

    /**
     * @return array{firms_swept:int, members_downgraded:int}
     */
    public static function run( int $duesYear ): array {
        $rows      = MyNJILGA_Dues_Invoice_Table::get_unpaid_for_sweep( $duesYear );
        $yearTagId = MyNJILGA_Tags::get_or_create_by_title( 'Unpaid Dues ' . $duesYear );

        $membersDowngraded = 0;

        foreach ( $rows as $row ) {
            $roster  = json_decode( (string) $row->roster_snapshot, true );
            $members = $roster['members'] ?? [];

            foreach ( $members as $member ) {
                $contact = \FluentCrm\App\Models\Subscriber::find( (int) ( $member['contact_id'] ?? 0 ) );
                if ( ! $contact ) {
                    continue;
                }

                if ( $yearTagId ) {
                    $contact->attachTags( [ $yearTagId ] );
                }
                MyNJILGA_Tags::attach( $contact, MyNJILGA_Tags::SLUG_UNPAID_DUES );
                MyNJILGA_Tags::detach( $contact, MyNJILGA_Tags::SLUG_DUES_PAID );

                if ( ! empty( $contact->user_id ) ) {
                    $user = get_user_by( 'id', (int) $contact->user_id );
                    if ( $user ) {
                        $user->remove_role( MyNJILGA_Payment_Listener::WP_ROLE );
                    }
                }
                $membersDowngraded++;
            }

            MyNJILGA_Dues_Invoice_Table::mark_downgraded( [ (int) $row->id ] );

            MyNJILGA_Invoicing_Notes::log(
                (int) $row->fluentcrm_company_id,
                'Dues invoice downgraded',
                sprintf( '%d invoice never paid as of the downgrade sweep — %d member(s) downgraded.', $duesYear, count( $members ) )
            );
        }

        return [ 'firms_swept' => count( $rows ), 'members_downgraded' => $membersDowngraded ];
    }
}
