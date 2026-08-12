<?php
/**
 * Thin wrapper around FluentCRM's CompanyNote model — every invoicing
 * step that changes a firm's status (sent / paid / downgraded) leaves one
 * of these on the Company's "Notes & Activities" tab, so NJILGA staff get
 * a plain-English audit trail without needing to read the invoices table.
 */
class MyNJILGA_Invoicing_Notes {

    public static function log( int $companyId, string $title, string $description ): void {
        if ( ! class_exists( '\\FluentCrm\\App\\Models\\CompanyNote' ) ) {
            return;
        }
        \FluentCrm\App\Models\CompanyNote::create( [
            'subscriber_id' => $companyId, // This model stores the Company ID here, not a contact ID.
            'title'         => $title,
            'description'   => $description,
            'created_by'    => get_current_user_id(),
        ] );
    }
}
