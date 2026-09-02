<?php
/**
 * Member-facing firm dues status (spec §11).
 *
 *   [njilga_firm_dues_status]
 *
 * Logged-in WP user → FluentCRM contact → their Company (primary, plus
 * any others they're attached to) → every invoice row for those firms,
 * newest year first, with the full roster, amounts, status and the
 * payment link. Shown to EVERY member of the firm, not just the Owner,
 * so nobody has to ask the Owner whether the firm has paid.
 */
class MyNJILGA_Firm_Status_Page {

    const SHORTCODE = 'njilga_firm_dues_status';

    public static function register(): void {
        add_shortcode( self::SHORTCODE, [ __CLASS__, 'render' ] );
    }

    public static function render( $atts = [] ): string {
        if ( ! is_user_logged_in() ) {
            return sprintf(
                '<div class="njilga-status"><p>Please <a href="%s">log in</a> to see your firm\'s dues status.</p></div>',
                esc_url( wp_login_url( self::current_url() ) )
            );
        }
        if ( ! MyNJILGA_Members_Data::fluentcrm_active() || ! function_exists( 'FluentCrmApi' ) ) {
            return '<div class="njilga-status"><p>Dues status is temporarily unavailable.</p></div>';
        }

        $user    = wp_get_current_user();
        $contact = FluentCrmApi( 'contacts' )->getContactByUserRef( (int) $user->ID );
        if ( ! $contact && ! empty( $user->user_email ) ) {
            $contact = FluentCrmApi( 'contacts' )->getContactByUserRef( (string) $user->user_email );
        }
        if ( ! $contact ) {
            return '<div class="njilga-status"><p>We couldn\'t find a member record for your account (' . esc_html( (string) $user->user_email ) . '). Please contact NJILGA.</p></div>';
        }

        $companyIds = [];
        if ( ! empty( $contact->company_id ) ) {
            $companyIds[] = (int) $contact->company_id;
        }
        try {
            foreach ( $contact->companies ?? [] as $c ) {
                $companyIds[] = (int) $c->id;
            }
        } catch ( \Throwable $e ) {
            // Companies module off — primary id (if any) is all we have.
        }
        $companyIds = array_values( array_unique( array_filter( $companyIds ) ) );

        ob_start();
        self::styles();
        echo '<div class="njilga-status">';

        self::render_member_summary( $contact );

        if ( empty( $companyIds ) ) {
            echo '<p>Your member record isn\'t linked to a firm yet, so there\'s no firm invoice to show. Please contact NJILGA if that looks wrong.</p></div>';
            return (string) ob_get_clean();
        }

        $rows = MyNJILGA_Dues_Invoice_Table::get_for_companies( $companyIds );
        // Only real invoices — exceptions are staff-facing.
        $rows = array_values( array_filter( $rows, static function ( $r ) { return $r->status !== MyNJILGA_Dues_Invoice_Table::STATUS_EXCLUDED; } ) );

        if ( empty( $rows ) ) {
            echo '<p>No dues invoices have been issued for your firm yet.</p></div>';
            return (string) ob_get_clean();
        }

        $byYear = [];
        foreach ( $rows as $r ) {
            $byYear[ (int) $r->dues_year ][] = $r;
        }
        krsort( $byYear );

        foreach ( $byYear as $year => $yearRows ) {
            printf( '<h3 class="njilga-status__year">%d dues</h3>', $year );
            foreach ( $yearRows as $row ) {
                self::render_invoice( $row, (int) $contact->id );
            }
        }

        echo '</div>';
        return (string) ob_get_clean();
    }

    private static function render_member_summary( $contact ): void {
        $paidTag   = (string) MyNJILGA_Dues_Settings::general( 'paid_tag', 'dues-paid' );
        $unpaidTag = (string) MyNJILGA_Dues_Settings::general( 'unpaid_tag', 'unpaid-dues' );
        $paidId    = MyNJILGA_Tags::resolve_slug( $paidTag );
        $unpaidId  = MyNJILGA_Tags::resolve_slug( $unpaidTag );
        $isPaid    = $paidId && $contact->hasAnyTagId( [ $paidId ] );
        $isUnpaid  = ! $isPaid && $unpaidId && $contact->hasAnyTagId( [ $unpaidId ] );

        $firm = '';
        if ( ! empty( $contact->company_id ) && MyNJILGA_Members_Data::companies_module_active() ) {
            $c    = \FluentCrm\App\Models\Company::find( (int) $contact->company_id );
            $firm = $c ? (string) $c->name : '';
        }

        printf(
            '<div class="njilga-status__me"><div><strong>%s</strong>%s</div><div class="njilga-status__pill njilga-status__pill--%s">%s</div></div>',
            esc_html( MyNJILGA_Members_Data::display_name( $contact ) ),
            $firm !== '' ? ' · ' . esc_html( $firm ) : '',
            $isPaid ? 'paid' : ( $isUnpaid ? 'unpaid' : 'none' ),
            $isPaid ? 'Dues current' : ( $isUnpaid ? 'Dues outstanding' : 'No dues status on record' )
        );
    }

    private static function render_invoice( object $row, int $viewerContactId ): void {
        $snapshot = MyNJILGA_Dues_Snapshot::decode( $row );
        $members  = $snapshot['members'];
        $billTo   = MyNJILGA_Dues_Snapshot::bill_to( $row );
        $kind     = MyNJILGA_Dues_Snapshot::invoice_kind( $row );
        $payable  = in_array( $row->status, [ MyNJILGA_Dues_Invoice_Table::STATUS_CREATED, MyNJILGA_Dues_Invoice_Table::STATUS_SENT ], true ) && ! empty( $row->hosted_invoice_url );
        $link     = $payable ? (string) ( $row->hosted_invoice_url ?? '' ) : '';

        [ $statusLabel, $statusClass ] = self::status_label( $row );

        echo '<div class="njilga-status__card">';
        printf(
            '<div class="njilga-status__head"><div><strong>%s</strong>%s<div class="njilga-status__meta">Billed to %s%s</div></div><div class="njilga-status__right"><div class="njilga-status__total">%s</div><div class="njilga-status__pill njilga-status__pill--%s">%s</div></div></div>',
            esc_html( MyNJILGA_Dues_Snapshot::company_name( $row ) ),
            $kind === MyNJILGA_Dues_Snapshot::KIND_ASSESSMENT ? ' <span class="njilga-status__tag">assessment</span>' : ( $kind === MyNJILGA_Dues_Snapshot::KIND_DUES ? ' <span class="njilga-status__tag">dues only</span>' : '' ),
            esc_html( $billTo['name'] !== '' ? $billTo['name'] : $billTo['email'] ),
            (int) $billTo['contact_id'] === $viewerContactId ? ' (you)' : '',
            esc_html( MyNJILGA_Invoicing::money( (int) $row->total_amount_cents ) ),
            esc_attr( $statusClass ),
            esc_html( $statusLabel )
        );

        echo '<table class="njilga-status__table"><thead><tr><th>Member</th><th>Category</th><th>Dues</th><th>Assessment</th></tr></thead><tbody>';
        foreach ( $members as $m ) {
            $isYou = (int) ( $m['contact_id'] ?? 0 ) === $viewerContactId;
            $dues  = (int) ( $m['dues_cents'] ?? 0 );
            $fee   = (int) ( $m['assessment_cents'] ?? 0 );
            $duesCell = ! empty( $m['unbilled_reason'] )
                ? '<span class="njilga-status__muted">' . esc_html( ucfirst( (string) $m['unbilled_reason'] ) ) . '</span>'
                : ( $dues > 0
                    ? esc_html( MyNJILGA_Invoicing::money( $dues ) ) . ( ! empty( $m['tier_label'] ) ? ' <span class="njilga-status__muted">(' . esc_html( (string) $m['tier_label'] ) . ')</span>' : '' )
                    : '<span class="njilga-status__muted">' . esc_html( $kind === MyNJILGA_Dues_Snapshot::KIND_ASSESSMENT ? '—' : ( 'No charge' . ( ! empty( $m['dues_note'] ) ? ' (' . $m['dues_note'] . ')' : '' ) ) ) . '</span>' );
            $feeCell = $fee > 0
                ? esc_html( MyNJILGA_Invoicing::money( $fee ) ) . ( ! empty( $m['assessment_qualifier'] ) ? ' <span class="njilga-status__muted">(' . esc_html( (string) $m['assessment_qualifier'] ) . ')</span>' : '' )
                : '<span class="njilga-status__muted">' . esc_html( (string) ( $m['assessment_note'] ?? '—' ) ) . '</span>';
            printf(
                '<tr%s><td>%s%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                $isYou ? ' class="is-you"' : '',
                esc_html( (string) ( $m['name'] ?? '' ) ),
                $isYou ? ' <span class="njilga-status__muted">(you)</span>' : '',
                esc_html( (string) ( $m['category_label'] ?: '—' ) ),
                $duesCell,
                $feeCell
            );
        }
        echo '</tbody></table>';

        if ( $link !== '' ) {
            printf( '<p class="njilga-status__actions"><a class="njilga-status__pay" href="%s">Pay this invoice — %s</a> <span class="njilga-status__muted">Anyone at the firm can pay; payment marks everyone listed above as current.</span></p>', esc_url( $link ), esc_html( MyNJILGA_Invoicing::money( (int) $row->total_amount_cents ) ) );
        } elseif ( $row->status === MyNJILGA_Dues_Invoice_Table::STATUS_PAID ) {
            printf( '<p class="njilga-status__meta">Paid on %s. Thank you!</p>', esc_html( (string) $row->paid_at ) );
        } elseif ( $row->status === MyNJILGA_Dues_Invoice_Table::STATUS_DOWNGRADED ) {
            echo '<p class="njilga-status__meta">This invoice was never paid and the cycle has closed. Please contact NJILGA to reinstate membership.</p>';
        } else {
            echo '<p class="njilga-status__meta">This invoice is being prepared by NJILGA — the payment link will appear here once it\'s issued.</p>';
        }
        echo '</div>';
    }

    /**
     * @return array{0:string,1:string}
     */
    private static function status_label( object $row ): array {
        switch ( $row->status ) {
            case MyNJILGA_Dues_Invoice_Table::STATUS_PAID:
                return [ 'Paid', 'paid' ];
            case MyNJILGA_Dues_Invoice_Table::STATUS_SENT:
            case MyNJILGA_Dues_Invoice_Table::STATUS_CREATED:
                return [ 'Awaiting payment', 'unpaid' ];
            case MyNJILGA_Dues_Invoice_Table::STATUS_DOWNGRADED:
                return [ 'Not paid — lapsed', 'unpaid' ];
            default:
                return [ 'In preparation', 'none' ];
        }
    }

    private static function styles(): void {
        echo '<style>
            .njilga-status{max-width:860px}
            .njilga-status__me{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:12px 14px;background:#f6f7f7;border-radius:6px;margin-bottom:18px}
            .njilga-status__year{margin:22px 0 8px;font-size:1.1em}
            .njilga-status__card{border:1px solid #dcdcde;border-radius:6px;padding:14px 16px;margin-bottom:14px;background:#fff}
            .njilga-status__head{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:flex-start}
            .njilga-status__right{text-align:right}
            .njilga-status__total{font-size:1.4em;font-weight:700}
            .njilga-status__meta{color:#646970;font-size:.9em;margin:4px 0 0}
            .njilga-status__muted{color:#8c8f94}
            .njilga-status__tag{display:inline-block;padding:0 7px;border-radius:10px;font-size:.75em;font-weight:600;color:#fff;background:#2271b1;vertical-align:middle}
            .njilga-status__pill{display:inline-block;padding:3px 10px;border-radius:12px;font-size:.8em;font-weight:600;margin-top:4px}
            .njilga-status__pill--paid{background:#edfaef;color:#1d6f42}
            .njilga-status__pill--unpaid{background:#fcf0f1;color:#d63638}
            .njilga-status__pill--none{background:#f0f0f1;color:#646970}
            .njilga-status__table{width:100%;border-collapse:collapse;margin-top:12px;font-size:.95em}
            .njilga-status__table th,.njilga-status__table td{text-align:left;padding:6px 8px;border-bottom:1px solid #f0f0f1}
            .njilga-status__table tr.is-you td{background:#f0f6fc}
            .njilga-status__actions{margin:14px 0 0}
            .njilga-status__pay{display:inline-block;padding:10px 18px;border-radius:4px;background:#1d6f42;color:#fff!important;text-decoration:none;font-weight:600;margin-right:10px}
        </style>';
    }

    private static function current_url(): string {
        $scheme = is_ssl() ? 'https' : 'http';
        $host   = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : wp_parse_url( home_url(), PHP_URL_HOST );
        $uri    = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
        return $scheme . '://' . $host . $uri;
    }
}
