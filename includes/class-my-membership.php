<?php
/**
 * Member-facing "My Membership" — the person AND their firm in one view.
 *
 *   [njilga_my_membership]
 *
 * A signed-in member sees, for themselves and for every firm they belong to:
 *
 *   1. whether they are an active or an expired member,
 *   2. when the membership next expires (12/31/YYYY),
 *   3. which firm they belong to, and whether that firm manages their
 *      membership (pays one invoice for everyone) or they manage it
 *      themselves,
 *   4. every member of the firm and each one's standing,
 *   5. every fee invoiced to each of those members — membership dues,
 *      trustee dinner assessments and so on — and the invoices they sit on,
 *      so a payment can be traced to the people it covers.
 *
 * Where each answer comes from (there is no stored "expiration date"):
 *
 *   Paid-through year   the highest "Dues Paid {year}" tag the contact
 *                       carries (Settings → year tag pattern). Paying an
 *                       invoice stamps that tag on everyone the invoice
 *                       covers, and memberships run to the end of the
 *                       calendar year, so paid through 2027 = expires
 *                       12/31/2027. An online join that pays for next year
 *                       stamps this year's tag too, so the highest is right.
 *   Active              paid through this year or later. A contact with the
 *                       evergreen "Dues Paid" tag and NO year tag at all
 *                       (paid the pre-invoicing way) is active through the
 *                       end of this year. The date wins over a lingering
 *                       evergreen tag: paid through last year = expired.
 *   Firm manages it     the firm's billing mode (Settings → firm
 *                       overrides) and whether the viewer is the firm's
 *                       Owner. The Invoices table shows who each invoice
 *                       was actually addressed to.
 *   Fees                the frozen roster snapshot on each invoice — the
 *                       same rows [njilga_firm_dues_status] reads. Drafts
 *                       are staff previews and never shown here.
 *
 * Shown to EVERY member of a firm, not just the Owner — the same rule as
 * [njilga_firm_dues_status] — so nobody has to ask the Owner what the firm
 * owes. Always Live-mode invoices, deliberately not the staff Test/Live
 * toggle (see render()).
 *
 * The pure half (standing, management wording, fee lines, the ledger) has
 * no WordPress dependency and is covered by tests/MyMembershipTest.php.
 */
class MyNJILGA_My_Membership {

    const SHORTCODE = 'njilga_my_membership';

    const STATE_ACTIVE   = 'active';
    const STATE_EXPIRED  = 'expired';
    const STATE_EXEMPT   = 'exempt';
    const STATE_INACTIVE = 'inactive';
    const STATE_NONE     = 'none';

    const FEE_DUES       = 'dues';
    const FEE_ASSESSMENT = 'assessment';

    /** A firm this small shows every member's fees open; a bigger one opens only the viewer's. */
    const OPEN_ALL_UP_TO = 6;

    /** @var bool Whether this request has already printed the stylesheet. */
    private static $styled = false;

    public static function register(): void {
        add_shortcode( self::SHORTCODE, [ __CLASS__, 'render' ] );
    }

    // =========================================================================
    // Pure logic — no WordPress, no FluentCRM
    // =========================================================================

    /**
     * The dues years a contact has paid, newest first, read off their tags
     * ("Dues Paid 2026", "Dues Paid 2027" for the default pattern). A tag
     * counts when its title OR its slug fits the pattern, the same
     * either-or MyNJILGA_Tags::has_title() uses.
     *
     * @param array<int,array{title?:string,slug?:string}> $tags
     * @param string                                       $pattern e.g. "Dues Paid {year}"
     * @return array<int,int>
     */
    public static function paid_years( array $tags, string $pattern ): array {
        if ( strpos( $pattern, '{year}' ) === false ) {
            return [];
        }
        $titleRe = self::year_regex( $pattern );
        $slugRe  = self::year_regex( str_replace( 'yyyy', '{year}', self::slugify( str_replace( '{year}', 'yyyy', $pattern ) ) ) );

        $years = [];
        foreach ( $tags as $tag ) {
            $title = (string) ( $tag['title'] ?? '' );
            $slug  = (string) ( $tag['slug'] ?? '' );
            if ( $title !== '' && preg_match( $titleRe, $title, $m ) ) {
                $years[ (int) $m[1] ] = true;
            } elseif ( $slug !== '' && preg_match( $slugRe, $slug, $m ) ) {
                $years[ (int) $m[1] ] = true;
            }
        }
        $out = array_keys( $years );
        rsort( $out );
        return $out;
    }

    /**
     * One contact's standing.
     *
     * $facts: paid_years (int[]), paid_tag / unpaid_tag / exempt / inactive
     * (bool — the evergreen tags, and the dues-exempt and inactive roles).
     *
     * Order matters and is the rule set in the class comment:
     * paid-through-this-year-or-later → active; evergreen tag with no year
     * tag → active through the end of this year ('assumed'); then dues-
     * exempt and inactive (neither owes dues, so neither is "expired");
     * then anything that has lapsed or carries the unpaid tag → expired.
     *
     * @param array<string,mixed> $facts
     * @return array{state:string,through:?int,assumed:bool}
     */
    public static function standing( array $facts, int $thisYear ): array {
        $years   = array_map( 'intval', (array) ( $facts['paid_years'] ?? [] ) );
        $through = $years ? max( $years ) : null;

        if ( $through !== null && $through >= $thisYear ) {
            return [ 'state' => self::STATE_ACTIVE, 'through' => $through, 'assumed' => false ];
        }
        if ( $through === null && ! empty( $facts['paid_tag'] ) ) {
            return [ 'state' => self::STATE_ACTIVE, 'through' => $thisYear, 'assumed' => true ];
        }
        if ( ! empty( $facts['exempt'] ) ) {
            return [ 'state' => self::STATE_EXEMPT, 'through' => $through, 'assumed' => false ];
        }
        if ( ! empty( $facts['inactive'] ) ) {
            return [ 'state' => self::STATE_INACTIVE, 'through' => $through, 'assumed' => false ];
        }
        if ( $through !== null || ! empty( $facts['unpaid_tag'] ) ) {
            return [ 'state' => self::STATE_EXPIRED, 'through' => $through, 'assumed' => false ];
        }
        return [ 'state' => self::STATE_NONE, 'through' => null, 'assumed' => false ];
    }

    /**
     * "12/31/2027" — memberships run to the end of the calendar year.
     */
    public static function expires_label( int $year ): string {
        return '12/31/' . $year;
    }

    /**
     * The pill for a standing: [ label, css modifier ].
     *
     * @param array{state:string} $standing
     * @return array{0:string,1:string}
     */
    public static function standing_pill( array $standing, bool $long = false ): array {
        switch ( $standing['state'] ) {
            case self::STATE_ACTIVE:
                return [ $long ? 'Active member' : 'Active', 'paid' ];
            case self::STATE_EXPIRED:
                return [ $long ? 'Expired member' : 'Expired', 'unpaid' ];
            case self::STATE_EXEMPT:
                return [ 'Dues-exempt', 'paid' ];
            case self::STATE_INACTIVE:
                return [ 'Inactive', 'none' ];
            default:
                return [ $long ? 'No membership on record' : 'None', 'none' ];
        }
    }

    /**
     * The date cell for a standing: what a member sees under "Expires".
     *
     * @param array{state:string,through:?int} $standing
     */
    public static function expiry_text( array $standing ): string {
        if ( $standing['through'] === null ) {
            return '';
        }
        switch ( $standing['state'] ) {
            case self::STATE_ACTIVE:
                return self::expires_label( (int) $standing['through'] );
            case self::STATE_EXPIRED:
                return 'Expired ' . self::expires_label( (int) $standing['through'] );
            default:
                return '';
        }
    }

    /**
     * Whether a firm manages its members' membership, in words.
     *
     * $mode is the firm's billing mode (MyNJILGA_Dues_Settings::billing_mode_for()),
     * $ownerName the firm Owner's name ('' when none is on record).
     *
     * @return array{firm_managed:bool,label:string,detail:string}
     */
    public static function management( bool $isOwner, string $mode, string $ownerName ): array {
        $owner    = $ownerName !== '' ? $ownerName : 'The firm\'s Owner';
        $noOwner  = $ownerName === '' ? ' No Owner is on record for this firm yet, so please contact NJILGA.' : '';

        switch ( $mode ) {
            case MyNJILGA_Dues_Settings::MODE_INDIVIDUAL:
                return $isOwner
                    ? [
                        'firm_managed' => false,
                        'label'        => 'Members are billed individually',
                        'detail'       => 'You are the firm\'s Owner, but every member receives their own invoice — the firm doesn\'t pay for anyone else\'s membership.',
                    ]
                    : [
                        'firm_managed' => false,
                        'label'        => 'You manage your own membership',
                        'detail'       => 'The firm doesn\'t pay for your membership — your invoice comes to you.',
                    ];

            case MyNJILGA_Dues_Settings::MODE_SPLIT_ASSESSMENT:
                return $isOwner
                    ? [
                        'firm_managed' => true,
                        'label'        => 'You manage this firm\'s dues',
                        'detail'       => 'Dues are on one invoice addressed to you. Any assessment is billed to the member it belongs to.',
                    ]
                    : [
                        'firm_managed' => true,
                        'label'        => 'Dues managed by your firm',
                        'detail'       => $owner . ' receives the dues invoice for the firm. Any assessment is billed to you directly.' . $noOwner,
                    ];

            default:
                return $isOwner
                    ? [
                        'firm_managed' => true,
                        'label'        => 'You manage this firm\'s membership',
                        'detail'       => 'NJILGA sends one invoice, addressed to you, that covers everyone at the firm.',
                    ]
                    : [
                        'firm_managed' => true,
                        'label'        => 'Managed by your firm',
                        'detail'       => $owner . ' receives one invoice covering everyone at the firm — you don\'t pay separately.' . $noOwner,
                    ];
        }
    }

    /**
     * How an invoice row's status reads to a member, and whether they see
     * it at all. Drafts are a staff preview (they can be regenerated or
     * deleted) and excluded rows were never invoiced, so neither shows.
     * 'bucket' groups the statuses for the balance: only 'due' is owed.
     *
     * @return array{show:bool,label:string,variant:string,bucket:string}
     */
    public static function invoice_state( string $status ): array {
        $T = 'MyNJILGA_Dues_Invoice_Table';
        switch ( $status ) {
            case $T::STATUS_PAID:
                return [ 'show' => true, 'label' => 'Paid', 'variant' => 'paid', 'bucket' => 'paid' ];
            case $T::STATUS_CREATED:
            case $T::STATUS_SENT:
                return [ 'show' => true, 'label' => 'Awaiting payment', 'variant' => 'unpaid', 'bucket' => 'due' ];
            case $T::STATUS_PROCESSING:
                return [ 'show' => true, 'label' => 'Payment processing', 'variant' => 'processing', 'bucket' => 'processing' ];
            case $T::STATUS_APPROVED:
                return [ 'show' => true, 'label' => 'Being prepared', 'variant' => 'none', 'bucket' => 'pending' ];
            case $T::STATUS_DOWNGRADED:
                return [ 'show' => true, 'label' => 'Not paid — lapsed', 'variant' => 'unpaid', 'bucket' => 'lapsed' ];
            case $T::STATUS_VOIDED:
                return [ 'show' => true, 'label' => 'Voided', 'variant' => 'none', 'bucket' => 'void' ];
            case $T::STATUS_UNCOLLECTIBLE:
                return [ 'show' => true, 'label' => 'Written off', 'variant' => 'none', 'bucket' => 'void' ];
            default:
                return [ 'show' => false, 'label' => '', 'variant' => 'none', 'bucket' => '' ];
        }
    }

    /**
     * The fee lines one snapshot member carries on one invoice: their
     * dues (shown even at $0, with the reason — the payment still covers
     * them) unless the invoice is assessment-only, and their assessment
     * where one is owed.
     *
     * @param array<string,mixed> $m           One snapshot member.
     * @return array<int,array{type:string,label:string,detail:string,cents:int,note:string}>
     */
    public static function fee_lines( array $m, string $invoiceKind ): array {
        $lines = [];
        $dues  = (int) ( $m['dues_cents'] ?? 0 );
        $fee   = (int) ( $m['assessment_cents'] ?? 0 );

        if ( $invoiceKind !== MyNJILGA_Dues_Snapshot::KIND_ASSESSMENT ) {
            $label = trim( (string) ( $m['category_label'] ?? '' ) );
            if ( $label === '' ) {
                $label = 'Membership dues';
            }
            $lines[] = [
                'type'   => self::FEE_DUES,
                'label'  => $label,
                'detail' => $dues > 0 ? (string) ( $m['tier_label'] ?? '' ) : '',
                'cents'  => $dues,
                'note'   => $dues > 0 ? '' : self::no_charge_note( $m, $label ),
            ];
        }
        if ( $fee > 0 ) {
            $label = trim( (string) ( $m['assessment_label'] ?? '' ) );
            $lines[] = [
                'type'   => self::FEE_ASSESSMENT,
                'label'  => $label !== '' ? $label : 'Assessment',
                'detail' => (string) ( $m['assessment_qualifier'] ?? '' ),
                'cents'  => $fee,
                'note'   => '',
            ];
        }
        return $lines;
    }

    /**
     * Turn a flat list of invoice rows into what the page shows: the
     * invoices a member can see, and every fee line grouped by the person
     * it belongs to. Pure — rows are plain objects (id, dues_year, status,
     * total_amount_cents, roster_snapshot, and optionally hosted_invoice_url,
     * invoice_pdf_url, paid_at).
     *
     * Newest year first, then invoice id. A person is keyed by contact id
     * (or, for a snapshot member with none, by name). 'due_cents' on an
     * invoice list is the balance still owed — invoices Awaiting payment
     * only; processing, voided and written-off invoices never count.
     *
     * $onlyFor is for invoices that reach a person WITHOUT their belonging
     * to the firm that owns them (an online join before they were linked to
     * a firm, a former firm). An invoice addressed to that person is theirs
     * in full — they paid for everyone on it. One addressed to anybody else
     * shows only that person's own lines: the rest of the roster and the
     * invoice's total are none of their business, and it is not theirs to
     * pay (total_cents is null, no links, no share of the balance).
     *
     * @param array<int,object> $rows
     * @return array{
     *   invoices:array<int,array<string,mixed>>,  total_cents is null on a limited one
     *   members:array<int|string,array{name:string,fees:array<int,array<string,mixed>>,due_cents:int,paid_cents:int}>,
     *   due_cents:int
     * }
     */
    public static function fee_ledger( array $rows, ?int $onlyFor = null ): array {
        usort( $rows, static function ( $a, $b ) {
            $byYear = (int) $b->dues_year <=> (int) $a->dues_year;
            return $byYear !== 0 ? $byYear : (int) $a->id <=> (int) $b->id;
        } );

        $invoices = [];
        $members  = [];
        $due      = 0;

        foreach ( $rows as $row ) {
            $state = self::invoice_state( (string) $row->status );
            if ( ! $state['show'] ) {
                continue;
            }

            $snap    = MyNJILGA_Dues_Snapshot::decode( $row );
            $kind    = (string) ( $snap['invoice_kind'] ?? MyNJILGA_Dues_Snapshot::KIND_COMBINED );
            $billTo  = MyNJILGA_Dues_Snapshot::person( (array) ( $snap['bill_to'] ?? $snap['owner'] ?? [] ) );
            $limited = $onlyFor !== null && (int) $billTo['contact_id'] !== $onlyFor;
            $year    = (int) $row->dues_year;
            $total   = (int) $row->total_amount_cents;
            $hasFee  = false;
            $status  = (string) $row->status;
            $payable = in_array( $status, [ MyNJILGA_Dues_Invoice_Table::STATUS_CREATED, MyNJILGA_Dues_Invoice_Table::STATUS_SENT ], true );

            foreach ( (array) $snap['members'] as $m ) {
                if ( ! is_array( $m ) ) {
                    continue;
                }
                $name = trim( (string) ( $m['name'] ?? '' ) );
                $cid  = (int) ( $m['contact_id'] ?? 0 );
                if ( $limited && $cid !== $onlyFor ) {
                    continue;
                }
                $key  = $cid > 0 ? $cid : 'n:' . strtolower( $name );

                if ( ! isset( $members[ $key ] ) ) {
                    $members[ $key ] = [ 'name' => $name !== '' ? $name : 'Member', 'fees' => [], 'due_cents' => 0, 'paid_cents' => 0 ];
                }
                foreach ( self::fee_lines( $m, $kind ) as $line ) {
                    $hasFee = $hasFee || $line['type'] === self::FEE_ASSESSMENT;
                    $members[ $key ]['fees'][] = $line + [ 'year' => $year, 'invoice_id' => (int) $row->id, 'state' => $state ];
                    if ( $state['bucket'] === 'due' ) {
                        $members[ $key ]['due_cents'] += $line['cents'];
                    } elseif ( $state['bucket'] === 'paid' ) {
                        $members[ $key ]['paid_cents'] += $line['cents'];
                    }
                }
            }

            if ( $state['bucket'] === 'due' && ! $limited ) {
                $due += $total;
            }
            $invoices[] = [
                'id'           => (int) $row->id,
                'year'         => $year,
                'kind'         => $kind,
                'kind_label'   => self::kind_label( $kind, $hasFee ),
                'firm'         => (string) ( $snap['company']['name'] ?? '' ),
                'billed_to'    => $billTo['name'] !== '' ? $billTo['name'] : $billTo['email'],
                'billed_to_id' => (int) $billTo['contact_id'],
                'total_cents'  => $limited ? null : $total,
                'state'        => $state,
                'pay_url'      => $payable && ! $limited ? (string) ( $row->hosted_invoice_url ?? '' ) : '',
                'pdf_url'      => $limited ? '' : ( in_array( $status, [ MyNJILGA_Dues_Invoice_Table::STATUS_CREATED, MyNJILGA_Dues_Invoice_Table::STATUS_SENT, MyNJILGA_Dues_Invoice_Table::STATUS_PAID ], true ) ? (string) ( $row->invoice_pdf_url ?? '' ) : '' ),
                'paid_at'      => (string) ( $row->paid_at ?? '' ),
            ];
        }

        return [ 'invoices' => $invoices, 'members' => $members, 'due_cents' => $due ];
    }

    /**
     * What an invoice is for, in a member's words.
     */
    public static function kind_label( string $kind, bool $hasAssessment ): string {
        switch ( $kind ) {
            case MyNJILGA_Dues_Snapshot::KIND_ASSESSMENT:
                return 'Assessment';
            case MyNJILGA_Dues_Snapshot::KIND_JOIN:
                return 'Online join';
            case MyNJILGA_Dues_Snapshot::KIND_DUES:
                return 'Membership dues';
            default:
                return $hasAssessment ? 'Dues & assessment' : 'Membership dues';
        }
    }

    /**
     * Why a $0 dues line is $0, in a member's words ('' when the label
     * already says it — "Past President Membership (Exempt)").
     */
    private static function no_charge_note( array $m, string $label ): string {
        $note = (string) ( $m['dues_note'] ?? '' );
        if ( empty( $m['unbilled_reason'] ) ) {
            if ( $note === MyNJILGA_Dues_Preview::NOTE_JOIN_PAID ) {
                return 'Paid through an online join';
            }
            if ( $note === MyNJILGA_Dues_Preview::NOTE_JOIN_CLEARING ) {
                return 'Online join payment still clearing';
            }
        }
        $reason = MyNJILGA_Dues_Roster::no_charge_reason( $m );
        if ( $reason === '' || strcasecmp( $reason, $label ) === 0 ) {
            return 'No charge';
        }
        return 'No charge — ' . $reason;
    }

    /** "Dues Paid {year}" → a regex capturing the four-digit year. */
    private static function year_regex( string $pattern ): string {
        $token  = "\x01";
        $quoted = preg_quote( str_replace( '{year}', $token, $pattern ), '/' );
        return '/^' . str_replace( $token, '(\d{4})', $quoted ) . '$/i';
    }

    /** What WordPress's sanitize_title() does to a tag title, close enough to match a tag slug. */
    private static function slugify( string $text ): string {
        return trim( (string) preg_replace( '/[^a-z0-9]+/', '-', strtolower( $text ) ), '-' );
    }

    // =========================================================================
    // WordPress + FluentCRM
    // =========================================================================

    public static function render( $atts = [] ): string {
        if ( ! is_user_logged_in() ) {
            return sprintf(
                '<div class="njilga-mem"><p>Please <a href="%s">log in</a> to see your membership.</p></div>',
                esc_url( wp_login_url( (string) ( get_permalink() ?: home_url( '/' ) ) ) )
            );
        }
        if ( ! MyNJILGA_Members_Data::fluentcrm_active() ) {
            return '<div class="njilga-mem"><p>Your membership details are temporarily unavailable.</p></div>';
        }

        $user    = wp_get_current_user();
        $contact = self::contact_for_user( $user );
        if ( ! $contact ) {
            return '<div class="njilga-mem"><p>We couldn\'t find a member record for your account (' . esc_html( (string) $user->user_email ) . '). Please contact NJILGA.</p></div>';
        }

        // This page lists other people's names and fees: keep it out of any
        // page cache (the flag WP Super Cache, W3 Total Cache, WP Rocket,
        // LiteSpeed and the like all honour).
        if ( ! defined( 'DONOTCACHEPAGE' ) ) {
            define( 'DONOTCACHEPAGE', true );
        }

        try {
            $model = self::build_model( $contact );
        } catch ( \Throwable $e ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[My NJILGA] My Membership failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            }
            return '<div class="njilga-mem"><p>Your membership details are temporarily unavailable. Please try again shortly, or contact NJILGA.</p></div>';
        }

        return self::html( $model );
    }

    /**
     * The contact this account belongs to, looked up WITHOUT the side
     * effects of FluentCrmApi('contacts')->getContactByUserRef() — that
     * links whatever contact it finds to the account. A page that only
     * displays data must never re-point a contact, and must not show a
     * contact that another account is linked to just because the emails
     * match. Linked by user_id first, else by the account's email when the
     * contact isn't linked to a different account.
     *
     * @return object|null A FluentCRM Subscriber.
     */
    public static function contact_for_user( \WP_User $user ) {
        $contact = \FluentCrm\App\Models\Subscriber::where( 'user_id', (int) $user->ID )->first();
        if ( ! $contact && ! empty( $user->user_email ) ) {
            $byEmail = \FluentCrm\App\Models\Subscriber::where( 'email', (string) $user->user_email )->first();
            if ( $byEmail && ( empty( $byEmail->user_id ) || (int) $byEmail->user_id === (int) $user->ID ) ) {
                $contact = $byEmail;
            }
        }
        return $contact ?: null;
    }

    /**
     * Everything the page shows, gathered.
     *
     * @param object $contact A FluentCRM Subscriber.
     * @return array<string,mixed>
     */
    private static function build_model( $contact ): array {
        $thisYear  = MyNJILGA_Invoicing::current_dues_year();
        $slugMap   = self::tag_map();
        $viewerId  = (int) $contact->id;
        $me        = self::summarize( $contact, $slugMap, $thisYear );

        $companies = [];
        $ids       = self::company_ids_for( $contact );
        if ( $ids && MyNJILGA_Members_Data::companies_module_active() ) {
            $primary   = (int) ( $contact->company_id ?? 0 );
            $companies = \FluentCrm\App\Models\Company::with( [ 'subscribers.tags', 'owner' ] )->whereIn( 'id', $ids )->get()->all();
            usort( $companies, static function ( $a, $b ) use ( $primary ) {
                if ( (int) $a->id === $primary || (int) $b->id === $primary ) {
                    return (int) $b->id === $primary ? 1 : -1;
                }
                return strcasecmp( (string) $a->name, (string) $b->name );
            } );
        }
        $companyIds = array_map( static function ( $c ) { return (int) $c->id; }, $companies );

        // ALWAYS live, and deliberately not the staff mode toggle — the same
        // reasoning as [njilga_firm_dues_status]: a member can never pay a
        // test invoice, and a real unpaid balance must not vanish because
        // staff flipped the admin into Test mode.
        $firmRows = $companyIds ? MyNJILGA_Dues_Invoice_Table::get_for_companies( $companyIds, true ) : [];
        $mine     = MyNJILGA_Dues_Invoice_Table::rows_listing_contact( $viewerId, true );

        $scopes = [];
        foreach ( $companies as $company ) {
            $cid    = (int) $company->id;
            $ledger = self::fee_ledger( array_values( array_filter( $firmRows, static function ( $r ) use ( $cid ) {
                return (int) $r->fluentcrm_company_id === $cid;
            } ) ) );

            $roster = [];
            foreach ( self::subscribers_of( $company ) as $sub ) {
                $roster[] = self::summarize( $sub, $slugMap, $thisYear );
            }
            usort( $roster, static function ( $a, $b ) {
                $cmp = strcasecmp( $a['last_name'], $b['last_name'] );
                return $cmp !== 0 ? $cmp : strcasecmp( $a['first_name'], $b['first_name'] );
            } );

            $owner     = $company->owner ?? null;
            $ownerName = $owner ? MyNJILGA_Members_Data::display_name( $owner ) : '';
            $isOwner   = (int) ( $company->owner_id ?? 0 ) === $viewerId;
            $active    = 0;
            foreach ( $roster as $r ) {
                $active += $r['standing']['state'] === self::STATE_ACTIVE ? 1 : 0;
            }

            [ $groups, $extras ] = self::fee_groups( $ledger, $roster, $viewerId );
            $scopes[] = [
                'kind'       => 'firm',
                'name'       => (string) ( $company->name ?? '' ),
                'owner_id'   => (int) ( $company->owner_id ?? 0 ),
                'owner_name' => $ownerName,
                'is_owner'   => $isOwner,
                'management' => self::management( $isOwner, MyNJILGA_Dues_Settings::billing_mode_for( $cid ), $ownerName ),
                'roster'     => $roster,
                'active'     => $active,
                'ledger'     => $ledger,
                'groups'     => $groups,
                'extras'     => $extras,
            ];
        }

        // Invoices that list the viewer but aren't filed under a firm they
        // belong to now — an online join before they were linked to a firm,
        // a former firm — so their history is complete.
        $otherRows = array_values( array_filter( $mine, static function ( $r ) use ( $companyIds ) {
            return ! in_array( (int) $r->fluentcrm_company_id, $companyIds, true );
        } ) );
        if ( $otherRows ) {
            $ledger = self::fee_ledger( $otherRows, $viewerId );
            if ( $ledger['invoices'] ) {
                [ $groups ] = self::fee_groups( $ledger, [], $viewerId );
                $scopes[]   = [ 'kind' => 'other', 'ledger' => $ledger, 'groups' => $groups, 'extras' => [] ];
            }
        }

        return [ 'me' => $me, 'viewer_id' => $viewerId, 'scopes' => $scopes, 'has_firm' => (bool) $companies ];
    }

    /**
     * One person as the page needs them: name, category and standing.
     *
     * @param object              $sub A FluentCRM Subscriber with tags loaded (or loadable).
     * @param array<string,int>   $slugMap
     * @return array<string,mixed>
     */
    private static function summarize( $sub, array $slugMap, int $thisYear ): array {
        $entry = MyNJILGA_Dues_Preview::roster_entry( $sub, $slugMap );
        $tags  = $entry['tags'];

        $tagRows = [];
        foreach ( $sub->tags ?? [] as $tag ) {
            $tagRows[] = [ 'title' => (string) ( $tag->title ?? '' ), 'slug' => (string) ( $tag->slug ?? '' ) ];
        }

        $pattern = (string) MyNJILGA_Dues_Settings::general( 'year_paid_tag_pattern', '' );
        if ( $pattern === '' ) {
            $pattern = (string) MyNJILGA_Dues_Settings::defaults()['general']['year_paid_tag_pattern'];
        }
        $paidTag   = (string) MyNJILGA_Dues_Settings::general( 'paid_tag', 'dues-paid' );
        $unpaidTag = (string) MyNJILGA_Dues_Settings::general( 'unpaid_tag', 'unpaid-dues' );
        $inactive  = (string) MyNJILGA_Dues_Settings::general( 'inactive_tag', 'inactive' );

        $standing = self::standing( [
            'paid_years' => self::paid_years( $tagRows, $pattern ),
            'paid_tag'   => $paidTag !== '' && in_array( $paidTag, $tags, true ),
            'unpaid_tag' => $unpaidTag !== '' && in_array( $unpaidTag, $tags, true ),
            'exempt'     => (bool) array_intersect( MyNJILGA_Tags::EXEMPT_SLUGS, $tags ),
            'inactive'   => $inactive !== '' && in_array( $inactive, $tags, true ),
        ], $thisYear );

        $config   = MyNJILGA_Dues_Settings::engine_config();
        $catKey   = MyNJILGA_Pricing_Engine::category_for( $tags, (array) $config['categories'], (string) $config['default_category'] );
        $category = $catKey !== null ? MyNJILGA_Dues_Settings::category( $catKey ) : null;

        return [
            'contact_id' => (int) $sub->id,
            'name'       => MyNJILGA_Members_Data::display_name( $sub ),
            'first_name' => (string) ( $sub->first_name ?? '' ),
            'last_name'  => (string) ( $sub->last_name ?? '' ),
            'category'   => $category ? (string) $category['label'] : '',
            'standing'   => $standing,
        ];
    }

    /**
     * Configured tag slug → id, plus the dues-exempt slugs (Past President,
     * Senior Trustee) so a legacy tag whose slug differs from its title
     * still reads as exempt.
     *
     * @return array<string,int>
     */
    private static function tag_map(): array {
        $map = MyNJILGA_Dues_Preview::resolve_configured_tags();
        foreach ( MyNJILGA_Tags::EXEMPT_SLUGS as $slug ) {
            $id = MyNJILGA_Tags::id_for( $slug );
            if ( $id ) {
                $map[ $slug ] = $id;
            }
        }
        return $map;
    }

    /**
     * Every FluentCRM Company the contact belongs to — their primary
     * company plus any others (the Companies module can attach several).
     *
     * @param object $contact
     * @return array<int,int>
     */
    private static function company_ids_for( $contact ): array {
        $ids = [];
        if ( ! empty( $contact->company_id ) ) {
            $ids[] = (int) $contact->company_id;
        }
        try {
            foreach ( $contact->companies ?? [] as $c ) {
                $ids[] = (int) $c->id;
            }
        } catch ( \Throwable $e ) {
            // Companies module off — the primary id (if any) is all we have.
        }
        return array_values( array_unique( array_filter( $ids ) ) );
    }

    /**
     * @return array<int,object>
     */
    private static function subscribers_of( $company ): array {
        $subs = $company->subscribers ?? [];
        if ( is_array( $subs ) ) {
            return array_values( $subs );
        }
        if ( is_object( $subs ) && method_exists( $subs, 'all' ) ) {
            return $subs->all();
        }
        return iterator_to_array( $subs );
    }

    /**
     * The ledger's fee lines, grouped per person for display: the current
     * roster first (in roster order, everyone — even with no fees yet),
     * then, separately, anyone an invoice lists who is no longer on the
     * roster, so the lines always add up to the invoices. With no roster
     * (the "other invoices" scope) everyone is one list, the viewer first.
     *
     * @param array<string,mixed>            $ledger
     * @param array<int,array<string,mixed>> $roster
     * @return array{0:array<int,array<string,mixed>>,1:array<int,array<string,mixed>>}
     */
    private static function fee_groups( array $ledger, array $roster, int $viewerId ): array {
        $left   = $ledger['members'];
        $groups = [];
        foreach ( $roster as $r ) {
            $id     = (int) $r['contact_id'];
            $l      = $left[ $id ] ?? null;
            unset( $left[ $id ] );
            $groups[] = [
                'contact_id' => $id,
                'name'       => $r['name'],
                'is_you'     => $id === $viewerId,
                'fees'       => $l ? $l['fees'] : [],
                'due_cents'  => $l ? $l['due_cents'] : 0,
            ];
        }

        $rest = [];
        foreach ( $left as $key => $l ) {
            $rest[] = [
                'contact_id' => is_int( $key ) ? $key : 0,
                'name'       => $l['name'],
                'is_you'     => is_int( $key ) && $key === $viewerId,
                'fees'       => $l['fees'],
                'due_cents'  => $l['due_cents'],
            ];
        }
        usort( $rest, static function ( $a, $b ) {
            if ( $a['is_you'] !== $b['is_you'] ) {
                return $a['is_you'] ? -1 : 1;
            }
            return strcasecmp( $a['name'], $b['name'] );
        } );

        return $roster ? [ $groups, $rest ] : [ $rest, [] ];
    }

    // =========================================================================
    // Rendering
    // =========================================================================

    /**
     * @param array<string,mixed> $model
     */
    private static function html( array $model ): string {
        ob_start();
        self::styles();
        echo '<div class="njilga-mem">';

        self::render_me( $model );

        $n = 0;
        foreach ( $model['scopes'] as $i => $scope ) {
            $n++;
            if ( $scope['kind'] === 'firm' ) {
                self::render_firm( $scope, (int) $model['viewer_id'], (int) $i );
            } else {
                self::render_other( $scope, (bool) $model['has_firm'], (int) $model['viewer_id'], (int) $i );
            }
        }
        if ( ! $model['has_firm'] && $n === 0 ) {
            echo '<p class="njilga-mem__muted">Nothing has been invoiced to you yet.</p>';
        }

        echo '</div>';
        return (string) ob_get_clean();
    }

    /**
     * The viewer's own card: standing, expiration, category, firm(s).
     *
     * @param array<string,mixed> $model
     */
    private static function render_me( array $model ): void {
        $me       = $model['me'];
        $standing = $me['standing'];
        [ $pill, $variant ] = self::standing_pill( $standing, true );

        echo '<section class="njilga-mem__card njilga-mem__hero">';
        printf(
            '<div class="njilga-mem__hero-head"><div><p class="njilga-mem__eyebrow">Your membership</p><h2 class="njilga-mem__name">%s</h2></div>%s</div>',
            esc_html( $me['name'] ),
            self::pill( $pill, $variant )
        );

        echo '<dl class="njilga-mem__kv">';
        if ( $me['category'] !== '' ) {
            printf( '<dt>Membership</dt><dd>%s</dd>', esc_html( $me['category'] ) );
        }
        if ( $standing['state'] === self::STATE_ACTIVE ) {
            printf( '<dt>Next expiration</dt><dd><strong>%s</strong></dd>', esc_html( self::expires_label( (int) $standing['through'] ) ) );
        } elseif ( $standing['state'] === self::STATE_EXPIRED && $standing['through'] !== null ) {
            printf( '<dt>Expired</dt><dd><strong>%s</strong></dd>', esc_html( self::expires_label( (int) $standing['through'] ) ) );
        } elseif ( $standing['state'] === self::STATE_EXEMPT ) {
            echo '<dt>Dues</dt><dd>None owed — your category is dues-exempt.</dd>';
        } elseif ( $standing['state'] === self::STATE_INACTIVE ) {
            echo '<dt>Dues</dt><dd>Not billed while your membership is marked inactive.</dd>';
        }

        $firms = [];
        foreach ( $model['scopes'] as $scope ) {
            if ( $scope['kind'] === 'firm' ) {
                $firms[] = sprintf(
                    '<strong>%s</strong> %s',
                    esc_html( $scope['name'] ),
                    self::badge( $scope['management']['label'], $scope['management']['firm_managed'] )
                );
            }
        }
        printf(
            '<dt>%s</dt><dd>%s</dd>',
            count( $firms ) > 1 ? 'Firms' : 'Firm',
            $firms ? implode( '<br>', $firms ) : 'No firm on record — you manage your own membership.'
        );
        echo '</dl>';

        foreach ( $model['scopes'] as $i => $scope ) {
            if ( (int) $scope['ledger']['due_cents'] > 0 ) {
                printf(
                    '<p class="njilga-mem__note">An invoice is awaiting payment — <a href="#njilga-mem-invoices-%d">see the details below</a>.</p>',
                    (int) $i
                );
                break;
            }
        }
        echo '</section>';
    }

    /**
     * @param array<string,mixed> $scope
     */
    private static function render_firm( array $scope, int $viewerId, int $index ): void {
        $mgmt = $scope['management'];

        echo '<section class="njilga-mem__firm">';
        printf( '<h2 class="njilga-mem__h">%s</h2>', esc_html( $scope['name'] ) );

        echo '<div class="njilga-mem__card">';
        printf( '<p class="njilga-mem__lead">%s</p>', self::badge( $mgmt['label'], $mgmt['firm_managed'] ) );
        printf( '<p>%s</p>', esc_html( $mgmt['detail'] ) );
        echo '<dl class="njilga-mem__kv">';
        printf(
            '<dt>Firm Owner</dt><dd>%s</dd>',
            $scope['owner_name'] !== ''
                ? esc_html( $scope['owner_name'] ) . ( $scope['is_owner'] ? ' <span class="njilga-mem__muted">(you)</span>' : '' )
                : '<span class="njilga-mem__muted">None on record</span>'
        );
        $count = count( $scope['roster'] );
        printf( '<dt>Members</dt><dd>%d <span class="njilga-mem__muted">· %d active</span></dd>', $count, (int) $scope['active'] );
        if ( (int) $scope['ledger']['due_cents'] > 0 ) {
            printf( '<dt>Balance due</dt><dd><strong>%s</strong></dd>', esc_html( MyNJILGA_Invoicing::money( (int) $scope['ledger']['due_cents'] ) ) );
        }
        echo '</dl></div>';

        // Members.
        echo '<h3 class="njilga-mem__sub">Members</h3>';
        echo '<div class="njilga-mem__card njilga-mem__card--flush"><div class="njilga-mem__scroll"><table class="njilga-mem__table"><thead><tr><th scope="col">Member</th><th scope="col">Membership</th><th scope="col">Status</th><th scope="col">Expires</th></tr></thead><tbody>';
        if ( ! $scope['roster'] ) {
            echo '<tr><td colspan="4" class="njilga-mem__muted">No members are attached to this firm yet.</td></tr>';
        }
        foreach ( $scope['roster'] as $r ) {
            $isYou = (int) $r['contact_id'] === $viewerId;
            [ $pill, $variant ] = self::standing_pill( $r['standing'] );
            $expires = self::expiry_text( $r['standing'] );
            printf(
                '<tr%s><td>%s%s%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                $isYou ? ' class="is-you"' : '',
                esc_html( $r['name'] ),
                $isYou ? ' <span class="njilga-mem__muted">(you)</span>' : '',
                (int) $r['contact_id'] === (int) $scope['owner_id'] ? ' <span class="njilga-mem__tag">Owner</span>' : '',
                $r['category'] !== '' ? esc_html( $r['category'] ) : '<span class="njilga-mem__muted">—</span>',
                self::pill( $pill, $variant ),
                $expires !== '' ? esc_html( $expires ) : '<span class="njilga-mem__muted">—</span>'
            );
        }
        echo '</tbody></table></div></div>';

        if ( ! $scope['ledger']['invoices'] ) {
            printf( '<p class="njilga-mem__muted">No invoices have been issued for %s yet.</p>', esc_html( $scope['name'] ) );
            echo '</section>';
            return;
        }

        self::render_fees( $scope['groups'], $scope['extras'], 'No longer listed at this firm' );
        self::render_invoices( $scope['ledger']['invoices'], $viewerId, $index, false );
        echo '</section>';
    }

    /**
     * Invoices that list the viewer but aren't under a firm they belong to.
     *
     * @param array<string,mixed> $scope
     */
    private static function render_other( array $scope, bool $hasFirm, int $viewerId, int $index ): void {
        echo '<section class="njilga-mem__firm">';
        printf( '<h2 class="njilga-mem__h">%s</h2>', $hasFirm ? 'Other invoices' : 'Your invoices' );
        if ( $hasFirm ) {
            echo '<p class="njilga-mem__muted">These list you but aren\'t filed under your current firm — for example, an online join from before you were linked to a firm.</p>';
        }
        self::render_fees( $scope['groups'], [], '' );
        self::render_invoices( $scope['ledger']['invoices'], $viewerId, $index, true );
        echo '</section>';
    }

    /**
     * Fees, one collapsible block per person.
     *
     * @param array<int,array<string,mixed>> $groups
     * @param array<int,array<string,mixed>> $extras
     */
    private static function render_fees( array $groups, array $extras, string $extrasHeading ): void {
        echo '<h3 class="njilga-mem__sub">Fees by member</h3>';
        $openAll = count( $groups ) <= self::OPEN_ALL_UP_TO;
        foreach ( $groups as $g ) {
            self::render_fee_group( $g, $g['is_you'] || $openAll );
        }
        if ( $extras ) {
            printf( '<h4 class="njilga-mem__minor">%s</h4>', esc_html( $extrasHeading ) );
            foreach ( $extras as $g ) {
                self::render_fee_group( $g, true );
            }
        }
    }

    /**
     * @param array<string,mixed> $g
     */
    private static function render_fee_group( array $g, bool $open ): void {
        printf( '<details class="njilga-mem__fees"%s><summary>', $open ? ' open' : '' );
        printf(
            '<span class="njilga-mem__who">%s%s</span>',
            esc_html( $g['name'] ),
            $g['is_you'] ? ' <span class="njilga-mem__muted">(you)</span>' : ''
        );
        if ( (int) $g['due_cents'] > 0 ) {
            printf( '<span class="njilga-mem__owed">%s due</span>', esc_html( MyNJILGA_Invoicing::money( (int) $g['due_cents'] ) ) );
        }
        echo '</summary>';

        if ( ! $g['fees'] ) {
            echo '<p class="njilga-mem__muted njilga-mem__none">Nothing has been invoiced for this member yet.</p></details>';
            return;
        }

        echo '<div class="njilga-mem__scroll"><table class="njilga-mem__table"><thead><tr><th scope="col">Year</th><th scope="col">Fee</th><th scope="col" class="njilga-mem__num">Amount</th><th scope="col">Status</th></tr></thead><tbody>';
        foreach ( $g['fees'] as $f ) {
            printf(
                '<tr><td>%d</td><td><strong>%s</strong>%s%s</td><td class="njilga-mem__num">%s</td><td>%s</td></tr>',
                (int) $f['year'],
                esc_html( $f['label'] ),
                $f['detail'] !== '' ? ' <span class="njilga-mem__muted">(' . esc_html( $f['detail'] ) . ')</span>' : '',
                $f['note'] !== '' ? '<span class="njilga-mem__sub-line">' . esc_html( $f['note'] ) . '</span>' : '',
                esc_html( MyNJILGA_Invoicing::money( (int) $f['cents'] ) ),
                self::pill( $f['state']['label'], $f['state']['variant'] )
            );
        }
        echo '</tbody></table></div></details>';
    }

    /**
     * The invoices the fee lines sit on — who each was addressed to, the
     * total, and the way to pay it.
     *
     * @param array<int,array<string,mixed>> $invoices
     */
    private static function render_invoices( array $invoices, int $viewerId, int $index, bool $showFirm ): void {
        printf( '<h3 class="njilga-mem__sub" id="njilga-mem-invoices-%d">Invoices</h3>', $index );
        echo '<div class="njilga-mem__card njilga-mem__card--flush"><div class="njilga-mem__scroll"><table class="njilga-mem__table"><thead><tr><th scope="col">Year</th><th scope="col">Invoice</th><th scope="col">Billed to</th><th scope="col" class="njilga-mem__num">Total</th><th scope="col">Status</th><th scope="col"><span class="njilga-mem__sr">Actions</span></th></tr></thead><tbody>';
        foreach ( $invoices as $inv ) {
            $links = [];
            if ( $inv['pay_url'] !== '' ) {
                $links[] = sprintf( '<a class="njilga-mem__pay" href="%s">Pay now</a>', esc_url( $inv['pay_url'] ) );
            }
            if ( $inv['pdf_url'] !== '' ) {
                $links[] = sprintf( '<a href="%s">PDF</a>', esc_url( $inv['pdf_url'] ) );
            }
            $status = self::pill( $inv['state']['label'], $inv['state']['variant'] );
            if ( $inv['state']['bucket'] === 'paid' && $inv['paid_at'] !== '' ) {
                $ts      = strtotime( $inv['paid_at'] );
                $status .= $ts ? '<span class="njilga-mem__sub-line">' . esc_html( gmdate( 'm/d/Y', $ts ) ) . '</span>' : '';
            }
            printf(
                '<tr><td>%d</td><td>%s%s</td><td>%s%s</td><td class="njilga-mem__num">%s</td><td>%s</td><td class="njilga-mem__links">%s</td></tr>',
                (int) $inv['year'],
                esc_html( $inv['kind_label'] ),
                $showFirm && $inv['firm'] !== '' ? '<span class="njilga-mem__sub-line">' . esc_html( $inv['firm'] ) . '</span>' : '',
                $inv['billed_to'] !== '' ? esc_html( $inv['billed_to'] ) : '<span class="njilga-mem__muted">—</span>',
                (int) $inv['billed_to_id'] === $viewerId ? ' <span class="njilga-mem__muted">(you)</span>' : '',
                $inv['total_cents'] === null ? '<span class="njilga-mem__muted">—</span>' : esc_html( MyNJILGA_Invoicing::money( (int) $inv['total_cents'] ) ),
                $status,
                implode( ' ', $links )
            );
        }
        echo '</tbody></table></div></div>';
        if ( ! $showFirm ) {
            echo '<p class="njilga-mem__muted njilga-mem__foot">Anyone at the firm can pay a firm invoice — paying it covers everyone listed on it.</p>';
        }
    }

    private static function pill( string $label, string $variant ): string {
        return sprintf( '<span class="njilga-mem__pill njilga-mem__pill--%s">%s</span>', esc_attr( $variant ), esc_html( $label ) );
    }

    private static function badge( string $label, bool $firmManaged ): string {
        return sprintf( '<span class="njilga-mem__badge%s">%s</span>', $firmManaged ? ' njilga-mem__badge--firm' : '', esc_html( $label ) );
    }

    /**
     * Once per request, however many times the shortcode appears. Same type
     * and colours as the other public shortcodes (MyNJILGA_Front_Style).
     */
    private static function styles(): void {
        if ( self::$styled ) {
            return;
        }
        self::$styled = true;
        MyNJILGA_Front_Style::enqueue_fonts();
        echo '<style>' . MyNJILGA_Front_Style::tokens( '.njilga-mem' ) . '
            .njilga-mem{max-width:960px;-webkit-font-smoothing:antialiased}
            .njilga-mem *{box-sizing:border-box}
            .njilga-mem p{margin:0 0 16px}
            .njilga-mem a{color:var(--nj-blue)}
            .njilga-mem__muted{color:var(--nj-muted);font-size:14px}
            .njilga-mem__card{border:1px solid var(--nj-line);border-radius:var(--nj-radius);padding:28px 32px;margin:0 0 20px;background:#fff;box-shadow:0 1px 2px rgba(16,24,40,.04),0 4px 16px rgba(16,24,40,.04)}
            .njilga-mem__card>:last-child{margin-bottom:0}
            .njilga-mem__card--flush{padding:0;overflow:hidden}
            .njilga-mem__hero{background:var(--nj-soft);margin-bottom:12px}
            .njilga-mem__hero-head{display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px 24px;margin:0 0 20px}
            .njilga-mem__eyebrow{font-family:var(--nj-font-ui);font-size:12px;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--nj-muted);margin:0 0 4px!important}
            .njilga-mem__name{font-family:var(--nj-font-head);font-size:28px;font-weight:700;line-height:1.2;color:var(--nj-ink);margin:0;letter-spacing:0;text-transform:none}
            .njilga-mem__h{font-family:var(--nj-font-head);font-size:26px;font-weight:700;line-height:1.25;color:var(--nj-ink);margin:40px 0 16px;letter-spacing:0;text-transform:none}
            .njilga-mem__sub{font-family:var(--nj-font-head);font-size:20px;font-weight:700;line-height:1.3;color:var(--nj-ink);margin:28px 0 12px;letter-spacing:0;text-transform:none}
            .njilga-mem__minor{font-family:var(--nj-font-ui);font-size:12px;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--nj-muted);margin:24px 0 10px}
            .njilga-mem__lead{margin:0 0 8px!important}
            .njilga-mem__kv{display:grid;grid-template-columns:max-content 1fr;gap:10px 28px;margin:0}
            .njilga-mem__kv dt{font-family:var(--nj-font-ui);font-size:12px;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--nj-muted);padding-top:3px}
            .njilga-mem__kv dd{margin:0;color:var(--nj-ink);font-size:16px}
            .njilga-mem__hero .njilga-mem__kv+.njilga-mem__note{margin-top:20px}
            .njilga-mem__note{padding:12px 16px;border-radius:var(--nj-radius-sm);background:var(--nj-warn-bg);color:var(--nj-warn);font-size:15px;margin-bottom:0!important}
            .njilga-mem__note a{color:inherit;font-weight:600}
            .njilga-mem__pill{display:inline-block;padding:4px 12px;border-radius:999px;font-family:var(--nj-font-ui);font-size:12px;font-weight:600;letter-spacing:.02em;white-space:nowrap}
            .njilga-mem__pill--paid{background:var(--nj-success-bg);color:var(--nj-success)}
            .njilga-mem__pill--unpaid{background:var(--nj-danger-bg);color:var(--nj-danger)}
            .njilga-mem__pill--processing{background:var(--nj-warn-bg);color:var(--nj-warn)}
            .njilga-mem__pill--none{background:var(--nj-soft);color:var(--nj-muted)}
            .njilga-mem__badge{display:inline-block;padding:3px 10px;border-radius:999px;font-family:var(--nj-font-ui);font-size:12px;font-weight:600;background:var(--nj-soft);color:var(--nj-text);border:1px solid var(--nj-line);vertical-align:middle}
            .njilga-mem__badge--firm{background:var(--nj-blue-soft);color:var(--nj-blue-dark);border-color:transparent}
            .njilga-mem__tag{display:inline-block;padding:2px 8px;border-radius:999px;font-family:var(--nj-font-ui);font-size:11px;font-weight:600;letter-spacing:.04em;color:#fff;background:var(--nj-blue);vertical-align:middle}
            .njilga-mem__scroll{overflow-x:auto;position:relative}
            .njilga-mem__table{width:100%;min-width:640px;border-collapse:collapse;margin:0;font-size:15px}
            .njilga-mem__table th{font-family:var(--nj-font-ui);font-size:12px;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--nj-muted);background:var(--nj-soft)}
            .njilga-mem__table th,.njilga-mem__table td{text-align:left;padding:12px 16px;border-bottom:1px solid var(--nj-line);vertical-align:top}
            .njilga-mem__table tbody tr:last-child td{border-bottom:0}
            .njilga-mem__table tr.is-you td{background:var(--nj-blue-soft)}
            .njilga-mem__table .njilga-mem__num{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
            .njilga-mem__sub-line{display:block;color:var(--nj-muted);font-size:13px;margin-top:2px}
            .njilga-mem__links{white-space:nowrap}
            .njilga-mem__links a{margin-right:12px}
            .njilga-mem__pay{display:inline-flex;align-items:center;min-height:36px;padding:6px 16px;border-radius:var(--nj-radius-sm);background:var(--nj-navy);color:#fff!important;font-family:var(--nj-font-ui);font-size:14px;font-weight:600;text-decoration:none!important}
            .njilga-mem__pay:hover{background:var(--nj-blue)}
            .njilga-mem__sr{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}
            .njilga-mem__fees{border:1px solid var(--nj-line);border-radius:var(--nj-radius);background:#fff;margin:0 0 12px;overflow:hidden}
            .njilga-mem__fees>summary{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:14px 16px;cursor:pointer;font-family:var(--nj-font-ui);font-weight:600;font-size:15px;color:var(--nj-ink);background:var(--nj-soft);list-style-position:inside}
            .njilga-mem__fees[open]>summary{border-bottom:1px solid var(--nj-line)}
            .njilga-mem__who{flex:1 1 auto}
            .njilga-mem__owed{font-size:13px;color:var(--nj-danger)}
            .njilga-mem__fees .njilga-mem__table{table-layout:fixed;min-width:540px}
            .njilga-mem__fees .njilga-mem__table th:nth-child(1){width:76px}
            .njilga-mem__fees .njilga-mem__table th:nth-child(3){width:120px}
            .njilga-mem__fees .njilga-mem__table th:nth-child(4){width:190px}
            .njilga-mem__none{padding:14px 16px;margin:0!important}
            .njilga-mem__foot{margin-top:-4px}
            @media (max-width:640px){.njilga-mem__card{padding:20px}.njilga-mem__kv{grid-template-columns:1fr;gap:2px}.njilga-mem__kv dd{margin-bottom:10px}.njilga-mem__table{font-size:14px}.njilga-mem__table th,.njilga-mem__table td{padding:10px 12px}.njilga-mem__name{font-size:24px}}
        </style>';
    }
}
