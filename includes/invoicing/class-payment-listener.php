<?php
/**
 * Step 5 (spec §7) — payment settles the whole invoice at once. Registered
 * through the gateway's on_invoice_paid() hook (gateway-agnostic —
 * whatever internal event the active MyNJILGA_Invoice_Gateway wires that
 * to is its own business); receives an invoice id and payment details,
 * looks up the invoice row, and cascades onto EVERY member of the frozen
 * snapshot — never a fresh Company query.
 *
 * Dues / combined invoices — each member gets:
 *   - the year-specific paid tag ("Dues Paid 2027", pattern in Settings),
 *     a permanent record of which year they were covered for;
 *   - the evergreen paid tag (`dues-paid`) and loses the evergreen unpaid
 *     tag — this is what every existing report in the plugin reads;
 *   - their category's WordPress role (Settings → category mapping),
 *     decided by MyNJILGA_Role_Sync from the contact's CURRENT CRM tags at
 *     the moment of payment (the frozen snapshot role is only the
 *     fallback), and only ever added. Best-effort per member: a contact
 *     with no WP account is skipped cleanly, and a role that is undefined
 *     or administrator-level is refused — but every outcome is counted,
 *     written into the Company Note and, for the two misconfigurations,
 *     left as a callout (MyNJILGA_Role_Sync::problem()).
 * Assessment-only invoices — each member gets "Assessment Paid {year}";
 * dues tags and roles are untouched (the dinner isn't the membership).
 *
 * Idempotent: a row already marked paid is ignored on a duplicate fire.
 */
class MyNJILGA_Payment_Listener {

    /**
     * The role the downgrade sweep falls back to for a snapshot with none, and the ONE role
     * MyNJILGA_Role_Sync creates on demand when a site lacks it. What a member is granted
     * comes from Settings → category mapping, not from this.
     */
    const WP_ROLE = 'professional';

    public static function register(): void {
        MyNJILGA_Invoicing::gateway()->on_invoice_paid( [ __CLASS__, 'handle_invoice_paid' ] );
    }

    /**
     * @param array<string,mixed> $payment Raw payment details from the
     *   gateway — the Stripe webhook receiver's invoice.paid handler
     *   builds this in the shape MyNJILGA_Dues_Payments_Table::record()
     *   expects (stripe_object_id/kind/method/amount_cents/status/
     *   occurred_at/card_brand/last4/bank_name/receipt_url/raw), minus
     *   invoice_row_id/livemode — this method supplies those two from
     *   the invoice row it looks up right here, and is the ONE place
     *   that both writes the ledger row and decides settlement for this
     *   event, so a duplicate fire can never do one without the other.
     */
    public static function handle_invoice_paid( string $invoiceId, array $payment ): void {
        if ( $invoiceId === '' ) {
            return;
        }
        $invoiceRow = MyNJILGA_Dues_Invoice_Table::get_by_order_id( $invoiceId );
        if ( ! $invoiceRow ) {
            return; // Not a dues invoice — some other invoice.
        }
        // An online join's row is written, ledgered and settled by
        // MyNJILGA_Join_Fulfillment alone. Letting a webhook or reconcile
        // of the same payment in here would book it a second time under a
        // different object id (and a Checkout invoice's out-of-band flag
        // as off-Stripe money).
        if ( (string) ( $invoiceRow->invoice_kind ?? '' ) === MyNJILGA_Dues_Snapshot::KIND_JOIN ) {
            return;
        }

        if ( ! empty( $payment ) ) {
            $ledgerData = $payment;
            $ledgerData['invoice_row_id'] = (int) $invoiceRow->id;
            if ( ! array_key_exists( 'livemode', $ledgerData ) ) {
                $ledgerData['livemode'] = (bool) $invoiceRow->livemode;
            }
            // Duplicate-safe on (stripe_object_id, kind) — a re-delivery
            // of the same event (or a re-fire after the row is already
            // paid) is a harmless no-op here, not a second ledger row.
            MyNJILGA_Dues_Payments_Table::record( $ledgerData );
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
     * Apply the paid outcome for one invoice row: tag every member of the
     * frozen snapshot, give them their role, mark the row paid, leave a
     * Company Note. Public because MyNJILGA_Join_Fulfillment calls it too:
     * an online join's row is written already paid, so it never passes
     * through handle_invoice_paid(). There is no in-plugin "mark paid
     * manually" — an offline cheque is recorded with Stripe's "Mark as
     * paid", which arrives here as an ordinary paid event.
     *
     * A role problem can never stop the settlement. Each member's role step
     * runs on its own, so one Throwable (a third-party add_user_role hook,
     * say) costs that member their role — flagged on the invoice row — and
     * not the rest of the roster their tags, nor the row its paid status.
     *
     * role_outcomes is MyNJILGA_Role_Sync's status => members tally (empty
     * for an assessment-only invoice, which never touches roles).
     * roles_granted counts members who now hold their role (added now or
     * already held), roles_skipped everyone else, contacts missing from the
     * CRM included.
     *
     * @return array{members:int,roles_granted:int,roles_skipped:int,role_outcomes:array<string,int>}
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
        $roleResults = [];
        foreach ( $members as $member ) {
            $contact = $crmActive ? \FluentCrm\App\Models\Subscriber::find( (int) ( $member['contact_id'] ?? 0 ) ) : null;
            if ( ! $contact ) {
                $skipped++;
                if ( $settles ) {
                    $roleResults[] = [ 'status' => MyNJILGA_Role_Sync::STATUS_NO_CONTACT ];
                }
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

            $result        = self::role_step( $contact, (string) ( $member['role'] ?? '' ) );
            $roleResults[] = $result;
            if ( $result['status'] === MyNJILGA_Role_Sync::STATUS_CHANGED || $result['status'] === MyNJILGA_Role_Sync::STATUS_UNCHANGED ) {
                $granted++;
            } else {
                $skipped++;
            }
        }

        MyNJILGA_Dues_Invoice_Table::mark_paid( (int) $invoiceRow->id );
        MyNJILGA_Dues_Invoice_Table::clear_error( (int) $invoiceRow->id );

        // Reporting comes after the row is paid and can only ever add to
        // what staff see: a failure here must not undo the settlement.
        $report = MyNJILGA_Role_Sync::aggregate( $roleResults );
        if ( $settles ) {
            try {
                MyNJILGA_Role_Sync::record_report( $report );
            } catch ( \Throwable $e ) {
                // The Company Note below still carries the outcome.
            }
            if ( $report['errors'] ) {
                MyNJILGA_Dues_Invoice_Table::set_error(
                    (int) $invoiceRow->id,
                    sprintf(
                        'Paid, but the WordPress role step failed for %d member(s): %s',
                        (int) ( $report['counts'][ MyNJILGA_Role_Sync::STATUS_ERROR ] ?? 0 ),
                        implode( ' | ', $report['errors'] )
                    )
                );
            }
        }

        // Tags just changed for the whole roster: the dashboard's cached
        // membership figures are now wrong. Guarded, and before the note so
        // a note that fails to save cannot leave them stale.
        if ( class_exists( 'MyNJILGA_Membership_Stats' ) && method_exists( 'MyNJILGA_Membership_Stats', 'flush' ) ) {
            try {
                MyNJILGA_Membership_Stats::flush();
            } catch ( \Throwable $e ) {
                // A cache that will expire on its own.
            }
        }

        $roleText = $settles
            ? trim( MyNJILGA_Role_Sync::describe_outcomes( $report['counts'] ) . '. ' . MyNJILGA_Role_Sync::describe_problems( $report['problems'], $report['created'] ) )
            : 'WordPress roles untouched (assessment only).';
        MyNJILGA_Invoicing_Notes::log(
            (int) $invoiceRow->fluentcrm_company_id,
            $settles ? 'Dues invoice paid' : 'Assessment invoice paid',
            sprintf(
                '%d %s invoice paid in full (%s) — %d member(s) %s; %s',
                $duesYear,
                $settles ? 'dues' : 'assessment',
                $source,
                count( $members ),
                $settles ? 'marked current' : 'marked assessment paid',
                $roleText
            )
        );

        return [ 'members' => $touched, 'roles_granted' => $granted, 'roles_skipped' => $skipped, 'role_outcomes' => $report['counts'] ];
    }

    /**
     * One member's role step, isolated: whatever goes wrong inside comes
     * back as an 'error' result instead of leaving settle() half-done.
     *
     * @param \FluentCrm\App\Models\Subscriber $contact
     * @return array<string,mixed> MyNJILGA_Role_Sync::sync_contact()'s shape, plus `message` on an error.
     */
    private static function role_step( $contact, string $snapshotRole ): array {
        try {
            return MyNJILGA_Role_Sync::sync_contact( $contact, $snapshotRole );
        } catch ( \Throwable $e ) {
            return [
                'status'  => MyNJILGA_Role_Sync::STATUS_ERROR,
                'user_id' => 0,
                'role'    => '',
                'added'   => [],
                'created' => false,
                'message' => $e->getMessage() !== '' ? $e->getMessage() : get_class( $e ),
            ];
        }
    }

    /**
     * A member paid for before they had a website account — a colleague
     * covered by an online join, someone on a firm invoice — gets their
     * category's role the first time the account appears (registration,
     * or their next login), rather than only at the next payment. Only
     * ever grants; never removes. Hooked on user_register and wp_login.
     *
     * "Paid" means paid for THIS dues year or the next one (the batch goes
     * out ahead of the year it covers), not just the evergreen paid tag:
     * the downgrade sweep only strips that from people on an unpaid
     * invoice, so a lapsed member who left their firm can still carry it.
     * The join form creates its applicant's account BEFORE payment, and
     * this fires on that registration — with the evergreen tag alone, a
     * lapsed member could start a join, abandon the checkout, and keep
     * the member role.
     *
     * @param int|\WP_User $user
     */
    public static function sync_role_for_user( $user ): void {
        try {
            $user = $user instanceof \WP_User ? $user : get_user_by( 'id', (int) $user );
            if ( ! $user || ! MyNJILGA_Members_Data::fluentcrm_active() || ! class_exists( '\\FluentCrm\\App\\Models\\Subscriber' ) ) {
                return;
            }
            $contact = self::contact_for_user( $user );
            if ( ! $contact || ! MyNJILGA_Tags::has_slug( $contact, (string) MyNJILGA_Dues_Settings::general( 'paid_tag', 'dues-paid' ) ) || ! self::paid_for_current_year( $contact ) ) {
                return;
            }
            // Never re-point a contact another account already owns.
            if ( ! empty( $contact->user_id ) && (int) $contact->user_id !== (int) $user->ID ) {
                return;
            }
            if ( empty( $contact->user_id ) ) {
                $contact->user_id = (int) $user->ID;
                $contact->save();
            }
            // The same resolver a payment uses, so login and payment can
            // never disagree about which category's role a member gets.
            MyNJILGA_Role_Sync::sync_contact( $contact );
        } catch ( \Throwable $e ) {
            // Never let a CRM hiccup break a login.
        }
    }

    /**
     * The contact this account belongs to — linked by user_id, else
     * matched on the account's email — looked up WITHOUT side effects.
     * FluentCRM's getContactByUserRef() falls back to the email the same
     * way, but then writes this user's id onto whatever contact it found
     * and saves it before returning, so the "another account already owns
     * it" guard in sync_role_for_user() could never fire: on a mere login,
     * a contact linked to user 5 whose email matches user 9 was moved to
     * user 9 (with its member role). Any linking is left to the caller,
     * after that guard.
     *
     * @return object|null A FluentCRM Subscriber.
     */
    private static function contact_for_user( \WP_User $user ) {
        $contact = \FluentCrm\App\Models\Subscriber::where( 'user_id', (int) $user->ID )->first();
        if ( ! $contact && ! empty( $user->user_email ) ) {
            $contact = \FluentCrm\App\Models\Subscriber::where( 'email', (string) $user->user_email )->first();
        }
        return $contact ?: null;
    }

    /**
     * Whether the contact carries "Dues Paid {year}" for the current dues
     * year or the next one — the batch for next year goes out (and is
     * paid) before that year starts, and an online join after the cutover
     * pays for next year.
     *
     * @param object $contact A FluentCRM Subscriber.
     */
    public static function paid_for_current_year( $contact ): bool {
        $year = MyNJILGA_Invoicing::current_dues_year();
        foreach ( [ $year, $year + 1 ] as $y ) {
            if ( MyNJILGA_Tags::has_title( $contact, MyNJILGA_Dues_Settings::year_tag( 'year_paid_tag_pattern', $y ) ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Best-effort grant of a role the caller has already chosen (an approved
     * application's category, a claimed invite's). True when the linked
     * account ends up holding the role; false when there is no account, no
     * role, the role is not defined on the site, or it is administrator-
     * level. A thin wrapper — the rules live in MyNJILGA_Role_Sync::apply_role().
     *
     * @param \FluentCrm\App\Models\Subscriber $contact
     */
    public static function grant_role( $contact, string $role ): bool {
        return MyNJILGA_Role_Sync::grant( $contact, $role );
    }
}
