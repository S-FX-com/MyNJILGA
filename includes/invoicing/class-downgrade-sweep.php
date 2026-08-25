<?php
/**
 * Step 6 (spec §7) — end-of-year downgrade. Manually triggered, never
 * cron: the dashboard shows a confirmation screen with the affected
 * firm/member counts (preview()) before anything runs (run()).
 *
 * For every invoice row still unpaid for the year that would have
 * settled membership (assessment-only invoices don't count — an unpaid
 * dinner never lapses a membership): every roster member gets the
 * year-specific unpaid tag ("Unpaid Dues 2027") and the evergreen
 * `unpaid-dues` tag, loses `dues-paid`, and — if Settings say so — loses
 * their category's WordPress role (best-effort, same rules as the grant).
 * The row is marked downgraded and a Company Note is left.
 *
 * Only firms that never paid are touched: paid rows are excluded by the
 * query, and a contact who appears on BOTH an unpaid row and a paid row
 * for the same year (possible under individual billing) is protected —
 * they're skipped, since someone's payment did cover them.
 */
class MyNJILGA_Downgrade_Sweep {

    /**
     * What run() WOULD do — for the confirmation screen.
     *
     * @return array{rows:array<int,object>,invoices:int,firms:int,members:int,protected:int,remove_roles:bool}
     */
    public static function preview( int $duesYear ): array {
        $rows      = MyNJILGA_Dues_Invoice_Table::get_unpaid_for_sweep( $duesYear );
        $protected = self::protected_contact_ids( $duesYear );

        $firms = []; $members = 0; $skipped = 0;
        foreach ( $rows as $row ) {
            $firms[ (int) $row->fluentcrm_company_id ] = true;
            foreach ( MyNJILGA_Dues_Snapshot::members( $row ) as $m ) {
                if ( isset( $protected[ (int) $m['contact_id'] ] ) ) {
                    $skipped++;
                } else {
                    $members++;
                }
            }
        }

        return [
            'rows'         => $rows,
            'invoices'     => count( $rows ),
            'firms'        => count( $firms ),
            'members'      => $members,
            'protected'    => $skipped,
            'remove_roles' => (bool) MyNJILGA_Dues_Settings::general( 'downgrade_remove_roles', true ),
        ];
    }

    /**
     * @return array{firms_swept:int,invoices_swept:int,members_downgraded:int,roles_removed:int,protected:int}
     */
    public static function run( int $duesYear ): array {
        $rows        = MyNJILGA_Dues_Invoice_Table::get_unpaid_for_sweep( $duesYear );
        $protected   = self::protected_contact_ids( $duesYear );
        $removeRoles = (bool) MyNJILGA_Dues_Settings::general( 'downgrade_remove_roles', true );
        $crmActive   = MyNJILGA_Members_Data::fluentcrm_active();

        $paidTag   = (string) MyNJILGA_Dues_Settings::general( 'paid_tag', 'dues-paid' );
        $unpaidTag = (string) MyNJILGA_Dues_Settings::general( 'unpaid_tag', 'unpaid-dues' );
        $yearTagId = $crmActive ? MyNJILGA_Tags::get_or_create_by_title( MyNJILGA_Dues_Settings::year_tag( 'year_unpaid_tag_pattern', $duesYear ) ) : null;

        $firms = []; $membersDowngraded = 0; $rolesRemoved = 0; $protectedCount = 0;

        foreach ( $rows as $row ) {
            $firms[ (int) $row->fluentcrm_company_id ] = true;
            $members   = MyNJILGA_Dues_Snapshot::members( $row );
            $rowCount  = 0;

            foreach ( $members as $member ) {
                $contactId = (int) ( $member['contact_id'] ?? 0 );
                if ( isset( $protected[ $contactId ] ) ) {
                    $protectedCount++;
                    continue;
                }
                $contact = $crmActive ? \FluentCrm\App\Models\Subscriber::find( $contactId ) : null;
                if ( ! $contact ) {
                    continue;
                }

                if ( $yearTagId ) {
                    $contact->attachTags( [ $yearTagId ] );
                }
                MyNJILGA_Tags::attach_slug( $contact, $unpaidTag );
                MyNJILGA_Tags::detach_slug( $contact, $paidTag );

                if ( $removeRoles && self::remove_role( $contact, (string) ( $member['role'] ?? '' ) ) ) {
                    $rolesRemoved++;
                }
                $membersDowngraded++;
                $rowCount++;
            }

            MyNJILGA_Dues_Invoice_Table::mark_downgraded( [ (int) $row->id ] );

            MyNJILGA_Invoicing_Notes::log(
                (int) $row->fluentcrm_company_id,
                'Dues invoice downgraded',
                sprintf(
                    '%d invoice never paid as of the downgrade sweep — %d member(s) tagged unpaid%s.',
                    $duesYear,
                    $rowCount,
                    $removeRoles ? ' and WordPress role removed where present' : ''
                )
            );
        }

        return [
            'firms_swept'        => count( $firms ),
            'invoices_swept'     => count( $rows ),
            'members_downgraded' => $membersDowngraded,
            'roles_removed'      => $rolesRemoved,
            'protected'          => $protectedCount,
        ];
    }

    /**
     * Contacts covered by a PAID dues/combined invoice for the year —
     * never downgraded even if they also appear on an unpaid row.
     *
     * @return array<int,true>
     */
    private static function protected_contact_ids( int $duesYear ): array {
        $ids = [];
        foreach ( MyNJILGA_Dues_Invoice_Table::get_by_year( $duesYear, [ MyNJILGA_Dues_Invoice_Table::STATUS_PAID ] ) as $row ) {
            if ( ! MyNJILGA_Dues_Snapshot::settles_dues( $row ) ) {
                continue;
            }
            foreach ( MyNJILGA_Dues_Snapshot::members( $row ) as $m ) {
                $ids[ (int) $m['contact_id'] ] = true;
            }
        }
        return $ids;
    }

    /**
     * @param \FluentCrm\App\Models\Subscriber $contact
     */
    private static function remove_role( $contact, string $role ): bool {
        $role = sanitize_key( $role );
        if ( $role === '' ) {
            $role = MyNJILGA_Payment_Listener::WP_ROLE;
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
        if ( ! $user || ! in_array( $role, (array) $user->roles, true ) ) {
            return false;
        }
        $user->remove_role( $role );
        return true;
    }
}
