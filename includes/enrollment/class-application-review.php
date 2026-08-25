<?php
/**
 * Enrollment gate (spec §10) — approve / reject.
 *
 * Approval is the ONLY door into the billing pool: it attaches the
 * contact to its Company (creating the firm if the applicant typed a new
 * one, and making the applicant the Owner when the firm has none), swaps
 * pending-approval for the category tag, and then branches on the
 * mid-year join policy from Settings rather than hardcoding "no invoice":
 *
 *   free_until_next_cycle  mark current for the CURRENT year (evergreen
 *                          paid tag + "Dues Paid {year}" + role); their
 *                          first invoice is next year's batch.
 *   invoice_now            create a draft individual invoice for the
 *                          current year in Invoicing for staff to approve
 *                          and send.
 *   manual                 category tag only; staff handle billing.
 */
class MyNJILGA_Application_Review {

    /**
     * @return array{ok:bool,message:string}
     */
    public static function approve( int $appId, int $byUserId, string $note = '' ): array {
        $app = MyNJILGA_Applications_Table::get( $appId );
        if ( ! $app || $app->status !== MyNJILGA_Applications_Table::STATUS_PENDING ) {
            return [ 'ok' => false, 'message' => 'Application not found or already decided.' ];
        }
        if ( ! MyNJILGA_Members_Data::fluentcrm_active() || ! function_exists( 'FluentCrmApi' ) ) {
            return [ 'ok' => false, 'message' => 'FluentCRM is not active.' ];
        }

        try {
            // 1. Contact.
            $contact = $app->fluentcrm_contact_id ? \FluentCrm\App\Models\Subscriber::find( (int) $app->fluentcrm_contact_id ) : null;
            if ( ! $contact ) {
                $contact = FluentCrmApi( 'contacts' )->createOrUpdate( [
                    'email'      => (string) $app->email,
                    'first_name' => (string) $app->first_name,
                    'last_name'  => (string) $app->last_name,
                    'phone'      => (string) $app->phone,
                    'status'     => 'subscribed',
                ] );
            }
            if ( ! $contact || empty( $contact->id ) ) {
                return [ 'ok' => false, 'message' => 'Could not create the FluentCRM contact.' ];
            }
            $contactId = (int) $contact->id;

            // 2. Company (existing, or created from the typed name).
            $companyId = (int) $app->fluentcrm_company_id;
            $company   = $companyId > 0 && MyNJILGA_Members_Data::companies_module_active() ? \FluentCrm\App\Models\Company::find( $companyId ) : null;
            if ( ! $company && MyNJILGA_Members_Data::companies_module_active() ) {
                $name = trim( (string) $app->new_company_name );
                if ( $name === '' ) {
                    return [ 'ok' => false, 'message' => 'No firm on the application — set one before approving.' ];
                }
                $company = FluentCrmApi( 'companies' )->createOrUpdate( [ 'name' => $name, 'owner_id' => $contactId ] );
                if ( ! $company || empty( $company->id ) ) {
                    return [ 'ok' => false, 'message' => 'Could not create the FluentCRM Company "' . $name . '".' ];
                }
            }
            if ( ! $company ) {
                return [ 'ok' => false, 'message' => 'FluentCRM Companies module is not active.' ];
            }
            $companyId = (int) $company->id;

            FluentCrmApi( 'companies' )->attachContactsByIds( [ $contactId ], [ $companyId ] );
            if ( empty( $company->owner_id ) ) {
                $company->owner_id = $contactId;
                $company->save();
            }

            // 3. Tags: pending → category.
            $contact = \FluentCrm\App\Models\Subscriber::find( $contactId ); // fresh, post-attach
            MyNJILGA_Tags::detach_slug( $contact, (string) MyNJILGA_Dues_Settings::general( 'pending_tag', 'pending-approval' ) );
            $category = MyNJILGA_Dues_Settings::category( (string) $app->category_key );
            if ( $category && $category['tag'] !== '' ) {
                MyNJILGA_Tags::attach_slug( $contact, $category['tag'], $category['label'] );
            }

            // 4. Mid-year join policy.
            $policy   = (string) MyNJILGA_Dues_Settings::general( 'mid_year_join_policy', MyNJILGA_Dues_Settings::JOIN_FREE_UNTIL_NEXT_CYCLE );
            $year     = MyNJILGA_Invoicing::current_dues_year();
            $outcome  = '';
            switch ( $policy ) {
                case MyNJILGA_Dues_Settings::JOIN_INVOICE_NOW:
                    $rowId = MyNJILGA_Dues_Preview::draft_individual_for_contact( $contactId, $companyId, $year );
                    $outcome = $rowId
                        ? sprintf( 'a draft %d invoice (#%d) is waiting in Invoicing', $year, $rowId )
                        : sprintf( 'no %d invoice could be drafted (nothing billable, or an invoice for this member already exists)', $year );
                    break;
                case MyNJILGA_Dues_Settings::JOIN_MANUAL:
                    $outcome = 'billing left to staff (manual policy)';
                    break;
                default: // free until next cycle
                    MyNJILGA_Tags::attach_slug( $contact, (string) MyNJILGA_Dues_Settings::general( 'paid_tag', 'dues-paid' ) );
                    MyNJILGA_Tags::detach_slug( $contact, (string) MyNJILGA_Dues_Settings::general( 'unpaid_tag', 'unpaid-dues' ) );
                    $yearTagId = MyNJILGA_Tags::get_or_create_by_title( MyNJILGA_Dues_Settings::year_tag( 'year_paid_tag_pattern', $year ) );
                    if ( $yearTagId ) {
                        $contact->attachTags( [ $yearTagId ] );
                    }
                    $granted = $category ? MyNJILGA_Payment_Listener::grant_role( $contact, (string) $category['role'] ) : false;
                    $outcome = sprintf( 'marked current for %d at no charge%s; first invoice will be the %d batch', $year, $granted ? ', WordPress role granted' : ' (no linked WordPress account yet — role will apply when they have one and pay)', $year + 1 );
                    break;
            }

            // 5. Record + note + email.
            MyNJILGA_Applications_Table::set_decision( $appId, MyNJILGA_Applications_Table::STATUS_APPROVED, $byUserId, $note, $companyId, $contactId );

            MyNJILGA_Invoicing_Notes::log(
                $companyId,
                'Membership application approved',
                sprintf( '%s %s (%s) approved as %s — %s.', $app->first_name, $app->last_name, $app->email, $category ? $category['label'] : $app->category_key, $outcome )
            );

            wp_mail(
                (string) $app->email,
                'Your NJILGA membership application has been approved',
                sprintf(
                    "Hi %s,\n\nYour NJILGA membership application has been approved and you're now listed with %s as %s.%s\n\nWelcome,\nNJILGA",
                    $app->first_name,
                    (string) $company->name,
                    $category ? $category['label'] : 'a member',
                    $note !== '' ? "\n\nNote from NJILGA: " . $note : ''
                )
            );

            return [ 'ok' => true, 'message' => sprintf( 'Approved %s %s → %s; %s.', $app->first_name, $app->last_name, (string) $company->name, $outcome ) ];
        } catch ( \Throwable $e ) {
            return [ 'ok' => false, 'message' => $e->getMessage() ];
        }
    }

    /**
     * @return array{ok:bool,message:string}
     */
    public static function reject( int $appId, int $byUserId, string $note = '' ): array {
        $app = MyNJILGA_Applications_Table::get( $appId );
        if ( ! $app || $app->status !== MyNJILGA_Applications_Table::STATUS_PENDING ) {
            return [ 'ok' => false, 'message' => 'Application not found or already decided.' ];
        }

        try {
            if ( MyNJILGA_Members_Data::fluentcrm_active() && $app->fluentcrm_contact_id ) {
                $contact = \FluentCrm\App\Models\Subscriber::find( (int) $app->fluentcrm_contact_id );
                if ( $contact ) {
                    MyNJILGA_Tags::detach_slug( $contact, (string) MyNJILGA_Dues_Settings::general( 'pending_tag', 'pending-approval' ) );
                    MyNJILGA_Tags::attach_slug( $contact, (string) MyNJILGA_Dues_Settings::general( 'rejected_tag', 'application-rejected' ), 'Application Rejected' );
                }
            }
            MyNJILGA_Applications_Table::set_decision( $appId, MyNJILGA_Applications_Table::STATUS_REJECTED, $byUserId, $note );

            if ( $app->fluentcrm_company_id ) {
                MyNJILGA_Invoicing_Notes::log(
                    (int) $app->fluentcrm_company_id,
                    'Membership application rejected',
                    sprintf( '%s %s (%s) — application rejected%s.', $app->first_name, $app->last_name, $app->email, $note !== '' ? ': ' . $note : '' )
                );
            }

            wp_mail(
                (string) $app->email,
                'Your NJILGA membership application',
                sprintf(
                    "Hi %s,\n\nThank you for applying to NJILGA. We're unable to approve your application at this time.%s\n\nIf you have questions, please reply to this email.\n\nNJILGA",
                    $app->first_name,
                    $note !== '' ? "\n\n" . $note : ''
                )
            );

            return [ 'ok' => true, 'message' => sprintf( 'Rejected %s %s.', $app->first_name, $app->last_name ) ];
        } catch ( \Throwable $e ) {
            return [ 'ok' => false, 'message' => $e->getMessage() ];
        }
    }
}
