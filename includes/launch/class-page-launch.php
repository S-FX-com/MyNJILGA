<?php
/**
 * My NJILGA → Launch Wizard: open a dues year, check everything, and schedule
 * the invoice emails.
 *
 * Five steps, server-rendered (no JavaScript), each reachable by URL so the
 * wizard can be left and resumed:
 *
 *   1 Check   readiness checklist (MyNJILGA_Launch_Checks::evaluate)
 *   2 Open    choose the year, set when joiners start paying it, generate the preview
 *   3 Review  what the preview produced, and what needs a person
 *   4 Create  pilot firms first, then the rest (the same creation the Invoicing page does)
 *   5 Send    test email, sample of the real invoice email, send a few now,
 *             then schedule the rest (MyNJILGA_Launch_Schedule)
 *
 * It adds no new way to bill anyone: creation goes through the same
 * MyNJILGA_Invoice_Creator and emails through the same MyNJILGA_Invoice_Sender
 * the Invoicing page uses. What it adds is the checks, the order, and the
 * schedule.
 *
 * Every state change is an admin-post handler that checks the capability and
 * a nonce; none takes a parameter (WordPress passes an argument-less action's
 * callbacks an empty string). Nothing here emails a firm unless a person
 * either clicked "Send selected now" or confirmed a schedule.
 */
class MyNJILGA_Page_Launch {

    const ACTION_CUTOVER  = 'my_njilga_launch_cutover';
    const ACTION_PREVIEW  = 'my_njilga_launch_preview';
    const ACTION_CREATE   = 'my_njilga_launch_create';
    const ACTION_MAILTEST = 'my_njilga_launch_mailtest';
    const ACTION_SAMPLE   = 'my_njilga_launch_sample';
    const ACTION_SEND_NOW = 'my_njilga_launch_send_now';
    const ACTION_SCHEDULE = 'my_njilga_launch_schedule';
    const ACTION_CONTROL  = 'my_njilga_launch_control';

    /** @var array<int,string> */
    const STEPS = [ 1 => 'Check', 2 => 'Open', 3 => 'Review', 4 => 'Create', 5 => 'Send' ];

    /** Invoice statuses an email can be previewed for (a draft previews with a placeholder payment link). */
    const PREVIEW_STATUSES = [ 'draft', 'approved', 'created', 'sent' ];

    /** Most firms listed in a selection table (the rest are still handled by "all"). */
    const LIST_LIMIT = 300;

    // =========================================================================
    // Pure helpers
    // =========================================================================

    /**
     * The form's default start: 9 am (site time) on the next weekday that is
     * at least an hour away, as a datetime-local value.
     */
    public static function default_start( int $now, \DateTimeZone $tz ): string {
        $d = ( new \DateTimeImmutable( '@' . ( $now + 3600 ) ) )->setTimezone( $tz );
        if ( (int) $d->format( 'G' ) >= 8 ) {
            $d = $d->modify( '+1 day' );
        }
        $d = $d->setTime( 9, 0 );
        while ( (int) $d->format( 'N' ) >= 6 ) {
            $d = $d->modify( '+1 day' )->setTime( 9, 0 );
        }
        return $d->format( 'Y-m-d\TH:i' );
    }

    /** "Mon, Nov 16, 2026 at 9:00 am EST" in the given zone. */
    public static function fmt_time( int $ts, \DateTimeZone $tz ): string {
        return ( new \DateTimeImmutable( '@' . $ts ) )->setTimezone( $tz )->format( 'D, M j, Y \a\t g:i a T' );
    }

    /**
     * A step's small status pill in the stepper: [ text, variant ] ('' text = none).
     *
     * @param array<string,mixed>      $review   MyNJILGA_Launch_Checks::review()
     * @param array<string,mixed>|null $schedule The year's schedule record, if any.
     * @return array{0:string,1:string}
     */
    public static function step_pill( int $step, array $review, ?array $schedule ): array {
        $total = (int) ( $review['total'] ?? 0 );
        switch ( $step ) {
            case 2:
                return $total > 0 ? [ 'Generated', 'success' ] : [ 'Not generated', 'muted' ];
            case 3:
                $attn = (int) ( $review['excluded_by']['no_owner'] ?? 0 ) + (int) ( $review['excluded_by']['other'] ?? 0 ) + (int) ( $review['errors'] ?? 0 );
                if ( $total === 0 ) {
                    return [ '', 'muted' ];
                }
                return $attn > 0 ? [ $attn . ' need attention', 'warning' ] : [ (int) ( $review['ready'] ?? 0 ) . ' ready', 'info' ];
            case 4:
                $made = (int) ( $review['created_unsent'] ?? 0 ) + (int) ( $review['sent'] ?? 0 ) + (int) ( $review['paid'] ?? 0 );
                if ( (int) ( $review['creating'] ?? 0 ) > 0 ) {
                    return [ 'Creating…', 'info' ];
                }
                return $made > 0 ? [ $made . ' created', 'success' ] : [ 'None yet', 'muted' ];
            case 5:
                if ( $schedule && ! empty( $schedule['status'] ) && (string) $schedule['status'] !== MyNJILGA_Launch_Schedule::ST_CANCELLED ) {
                    return self::status_pill( (string) $schedule['status'] );
                }
                $unsent = (int) ( $review['created_unsent'] ?? 0 );
                return $unsent > 0 ? [ $unsent . ' to send', 'info' ] : [ '—', 'muted' ];
        }
        return [ '', 'muted' ];
    }

    /**
     * @return array{0:string,1:string} [ label, pill variant ]
     */
    public static function status_pill( string $status ): array {
        $map = [
            MyNJILGA_Launch_Schedule::ST_SCHEDULED => [ 'Scheduled', 'info' ],
            MyNJILGA_Launch_Schedule::ST_RUNNING   => [ 'Sending', 'info' ],
            MyNJILGA_Launch_Schedule::ST_PAUSED    => [ 'Paused', 'warning' ],
            MyNJILGA_Launch_Schedule::ST_HALTED    => [ 'Halted — needs you', 'destructive' ],
            MyNJILGA_Launch_Schedule::ST_COMPLETE  => [ 'Complete', 'success' ],
            MyNJILGA_Launch_Schedule::ST_CANCELLED => [ 'Cancelled', 'muted' ],
        ];
        return $map[ $status ] ?? [ ucfirst( $status ), 'muted' ];
    }

    /**
     * Posted row ids → a clean, de-duplicated list of positive ints.
     *
     * @param mixed $raw
     * @return array<int,int>
     */
    public static function parse_ids( $raw ): array {
        if ( ! is_array( $raw ) ) {
            return [];
        }
        return array_values( array_unique( array_filter( array_map( 'intval', $raw ), static function ( $n ) { return $n > 0; } ) ) );
    }

    /** "MM-DD" or blank; anything else is null (rejected). */
    public static function clean_cutover( string $value ): ?string {
        $value = trim( $value );
        if ( $value === '' ) {
            return '';
        }
        return preg_match( '/^(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])\z/', $value ) === 1 ? $value : null;
    }

    // =========================================================================
    // Page
    // =========================================================================

    public static function render(): void {
        MyNJILGA_Dues_Invoice_Table::maybe_upgrade();

        $year = self::get_year();
        $step = self::get_step();
        $live = self::is_live();

        MyNJILGA_Admin_UI::open( 'Launch Wizard', 'Open a dues year, check everything, and schedule the invoice emails.', self::year_form( $year, $step ) );

        if ( MyNJILGA_Admin_Menu::require_fluentcrm() ) {
            MyNJILGA_Admin_UI::close();
            return;
        }
        if ( ! MyNJILGA_Members_Data::companies_module_active() ) {
            MyNJILGA_Admin_UI::callout( 'The FluentCRM <strong>Companies</strong> module is not active on this site. Enable it under FluentCRM → Settings → Modules.', 'warning' );
            MyNJILGA_Admin_UI::close();
            return;
        }

        self::render_notice();
        if ( ! $live ) {
            MyNJILGA_Admin_UI::callout( '<strong>Stripe is in Test mode.</strong> Anything created here is a test invoice, hidden from Live. Switching to Live is under Settings → Payments. The wizard will not schedule emails to firms while in Test mode.', 'warning' );
        }

        $rows     = MyNJILGA_Dues_Invoice_Table::get_by_year( $year, [], $live );
        $review   = MyNJILGA_Launch_Checks::review( $rows );
        $schedule = MyNJILGA_Launch_Schedule::get( $year );

        self::render_stepper( $year, $step, $review, $schedule );

        switch ( $step ) {
            case 2:
                self::render_step_open( $year, $live, $review );
                break;
            case 3:
                self::render_step_review( $year, $review );
                break;
            case 4:
                self::render_step_create( $year, $live, $rows, $review );
                break;
            case 5:
                self::render_step_send( $year, $live, $review, $schedule );
                break;
            default:
                self::render_step_check( $year );
        }

        MyNJILGA_Admin_UI::close();
    }

    // ---- Header / stepper ---------------------------------------------------

    private static function year_form( int $year, int $step ): string {
        $years = array_values( array_unique( array_merge(
            MyNJILGA_Dues_Invoice_Table::years(),
            [ $year, MyNJILGA_Invoicing::default_dues_year(), MyNJILGA_Invoicing::current_dues_year() ]
        ) ) );
        sort( $years );
        $opts = '';
        foreach ( $years as $y ) {
            $opts .= sprintf( '<option value="%d"%s>%d</option>', (int) $y, (int) $y === $year ? ' selected' : '', (int) $y );
        }
        return sprintf(
            '<form method="get" action="%s" class="njilga-year"><input type="hidden" name="page" value="%s"><input type="hidden" name="step" value="%d"><span class="njilga-year-label">%s Dues Year</span><select name="dues_year" class="njilga-select" onchange="this.form.submit()">%s</select><noscript><button type="submit" class="njilga-btn njilga-btn-outline njilga-btn-sm">Go</button></noscript></form>',
            esc_url( admin_url( 'admin.php' ) ),
            esc_attr( MyNJILGA_Admin_Menu::SLUG_LAUNCH ),
            $step,
            MyNJILGA_Admin_UI::icon( 'calendar' ),
            $opts
        );
    }

    /**
     * @param array<string,mixed>      $review
     * @param array<string,mixed>|null $schedule
     */
    private static function render_stepper( int $year, int $current, array $review, ?array $schedule ): void {
        echo '<nav class="njilga-steps" aria-label="Launch steps">';
        foreach ( self::STEPS as $n => $title ) {
            [ $pillText, $pillVariant ] = self::step_pill( $n, $review, $schedule );
            $done = $pillVariant === 'success';
            printf(
                '<a class="njilga-step%s%s" href="%s"><span class="njilga-step-num">%s</span><span class="njilga-step-body"><span class="njilga-step-title">%s</span>%s</span></a>',
                $n === $current ? ' is-current' : '',
                $done ? ' is-done' : '',
                esc_url( self::url( $year, $n ) ),
                $done ? '&#10003;' : (int) $n,
                esc_html( $title ),
                $pillText !== '' ? MyNJILGA_Admin_UI::pill( $pillText, $pillVariant ) : ''
            );
        }
        echo '</nav>';
    }

    // ---- Step 1: Check ------------------------------------------------------

    private static function render_step_check( int $year ): void {
        $checks = MyNJILGA_Launch_Checks::evaluate( MyNJILGA_Launch_Checks::gather_facts( $year ) );
        $n      = MyNJILGA_Launch_Checks::summarize( $checks );

        MyNJILGA_Admin_UI::section( 'Is the site ready to open ' . $year . '?', 'Everything below is checked live, right now. Red items will cause real problems; amber ones are worth a person\'s eyes; "confirm" items can\'t be read from here, so they are for you to check once.' );
        MyNJILGA_Admin_UI::stat_cards( [
            [ 'label' => 'To fix', 'value' => $n['fail'], 'variant' => $n['fail'] > 0 ? 'destructive' : 'success', 'icon' => 'alert' ],
            [ 'label' => 'Worth a look', 'value' => $n['warn'], 'variant' => $n['warn'] > 0 ? 'warning' : 'default', 'icon' => 'alert' ],
            [ 'label' => 'Passed', 'value' => $n['ok'], 'variant' => 'success', 'icon' => 'check-circle' ],
            [ 'label' => 'To confirm by eye', 'value' => $n['info'], 'variant' => 'info', 'icon' => 'file' ],
        ], 4 );

        if ( $n['fail'] > 0 ) {
            MyNJILGA_Admin_UI::callout( sprintf( '<strong>%d thing%s to fix before you create invoices.</strong> You can still look around the other steps.', $n['fail'], $n['fail'] === 1 ? '' : 's' ), 'error' );
        } elseif ( $n['warn'] === 0 ) {
            MyNJILGA_Admin_UI::callout( '<strong>No problems found.</strong> Have a look at the "to confirm" items, then carry on.', 'success' );
        }

        self::render_checks_table( $checks );

        printf(
            '<div class="njilga-actions"><a class="njilga-btn njilga-btn-outline" href="%s">Re-run the checks</a> <a class="njilga-btn njilga-btn-primary" href="%s">Next: open %d &rarr;</a></div>',
            esc_url( self::url( $year, 1 ) ),
            esc_url( self::url( $year, 2 ) ),
            $year
        );
    }

    /**
     * @param array<int,array<string,mixed>> $checks
     */
    private static function render_checks_table( array $checks ): void {
        $groups = [];
        foreach ( $checks as $c ) {
            $groups[ (string) $c['group'] ][] = $c;
        }
        foreach ( $groups as $group => $items ) {
            MyNJILGA_Admin_UI::section( (string) $group );
            echo '<div class="njilga-card njilga-table-boxed"><div class="njilga-tablewrap"><table class="njilga-table"><tbody>';
            foreach ( $items as $c ) {
                $fix = '';
                if ( ! empty( $c['fix']['url'] ) ) {
                    $fix = sprintf( '<a href="%s">%s</a>', esc_url( (string) $c['fix']['url'] ), esc_html( (string) $c['fix']['label'] ) );
                }
                printf(
                    '<tr><td class="njilga-nowrap">%s</td><td><div class="njilga-check-title">%s</div>%s</td><td class="njilga-nowrap">%s</td></tr>',
                    self::check_pill( (string) $c['status'] ),
                    esc_html( (string) $c['title'] ),
                    $c['detail'] !== '' ? '<div class="njilga-check-detail">' . nl2br( esc_html( (string) $c['detail'] ) ) . '</div>' : '',
                    $fix
                );
            }
            echo '</tbody></table></div></div>';
        }
    }

    private static function check_pill( string $status ): string {
        switch ( $status ) {
            case MyNJILGA_Launch_Checks::OK:
                return MyNJILGA_Admin_UI::pill( 'OK', 'success' );
            case MyNJILGA_Launch_Checks::WARN:
                return MyNJILGA_Admin_UI::pill( 'Check', 'warning' );
            case MyNJILGA_Launch_Checks::FAIL:
                return MyNJILGA_Admin_UI::pill( 'Fix', 'destructive' );
        }
        return MyNJILGA_Admin_UI::pill( 'Confirm', 'info' );
    }

    // ---- Step 2: Open the year ----------------------------------------------

    /**
     * @param array<string,mixed> $review
     */
    private static function render_step_open( int $year, bool $live, array $review ): void {
        $cut     = (string) MyNJILGA_Dues_Settings::general( 'join_next_year_from', '' );
        $joinYr  = MyNJILGA_Join_Form::dues_year( $live );
        $days    = (int) MyNJILGA_Stripe_Connection::setting( 'days_until_due', 30 );
        $due     = MyNJILGA_Invoicing::year_end_due_timestamp( $days );

        MyNJILGA_Admin_UI::section( 'Open ' . $year, sprintf( 'Opening a dues year means two things: telling online joiners which year they pay, and generating the preview — every firm\'s roster and price, worked out from FluentCRM. Nothing is billed or emailed by either. Invoices created now are due %s.', gmdate( 'F j, Y', $due ) ) );

        echo '<div class="njilga-card njilga-card-pad">';
        echo '<h3 class="njilga-section-title">Online joiners</h3>';
        printf(
            '<p>Someone who joins online today pays <strong>%d</strong> dues%s.</p>',
            $joinYr,
            $joinYr < $year ? sprintf( ' — and would then be billed %d again in the batch', $year ) : ''
        );
        echo '<p class="njilga-help">Set the date from which a join pays <em>next</em> year\'s dues (and covers the rest of this one). It repeats every year, so a late-year joiner is never missing from the next year\'s invoices. Leave it blank to switch only once next year\'s invoices exist.</p>';
        self::open_form( self::ACTION_CUTOVER, $year, 2 );
        echo '<div class="njilga-inline-fields"><div class="njilga-field"><label for="njilga-cutover"><strong>Joins cover next year from</strong></label>';
        printf( '<input type="text" id="njilga-cutover" name="join_next_year_from" value="%s" placeholder="MM-DD, e.g. 11-01" maxlength="5" pattern="(0[1-9]|1[0-2])-(0[1-9]|[12][0-9]|3[01])" autocomplete="off"></div>', esc_attr( $cut ) );
        echo '<button type="submit" class="njilga-btn njilga-btn-outline">Save</button></div></form></div>';

        MyNJILGA_Admin_UI::section( 'Generate the preview', 'Safe to run again at any time: it refreshes drafts from FluentCRM, and never touches an invoice that has already been created.' );
        if ( (int) $review['total'] > 0 ) {
            MyNJILGA_Admin_UI::callout( sprintf( 'A preview already exists for %d: <strong>%d</strong> firm invoice%s (%d ready, %d excluded). Generating again refreshes the drafts.', $year, (int) $review['total'], (int) $review['total'] === 1 ? '' : 's', (int) $review['ready'], (int) $review['excluded'] ), 'info' );
        }
        echo '<div class="njilga-actions">';
        echo MyNJILGA_Admin_UI::action_form( self::ACTION_PREVIEW, 'Generate preview for ' . $year, [ 'dues_year' => $year ], 'primary', 'refresh' );
        printf( ' <a class="njilga-btn njilga-btn-outline" href="%s">Next: review &rarr;</a>', esc_url( self::url( $year, 3 ) ) );
        echo '</div>';
    }

    // ---- Step 3: Review -----------------------------------------------------

    /**
     * @param array<string,mixed> $review
     */
    private static function render_step_review( int $year, array $review ): void {
        MyNJILGA_Admin_UI::section( 'Review the ' . $year . ' preview', 'This is the preview as it stands. Open Invoicing for the firm-by-firm view, where each firm\'s roster, tiers and assessment can be inspected before anything is created.' );

        if ( (int) $review['total'] === 0 ) {
            MyNJILGA_Admin_UI::callout( sprintf( 'No preview has been generated for %d yet. <a href="%s">Generate it in step 2</a>.', $year, esc_url( self::url( $year, 2 ) ) ), 'info' );
            return;
        }

        $attn = (int) $review['excluded_by']['no_owner'] + (int) $review['excluded_by']['other'];
        MyNJILGA_Admin_UI::stat_cards( [
            [ 'label' => 'Ready to create', 'value' => (int) $review['ready'], 'variant' => 'success', 'icon' => 'check-circle', 'sub' => MyNJILGA_Invoicing::money( (int) $review['ready_cents'] ) . ' in total' ],
            [ 'label' => 'Need attention', 'value' => $attn + (int) $review['errors'], 'variant' => ( $attn + (int) $review['errors'] ) > 0 ? 'warning' : 'default', 'icon' => 'alert', 'sub' => 'No Owner, errors' ],
            [ 'label' => 'Nothing to bill', 'value' => (int) $review['excluded_by']['no_members'] + (int) $review['excluded_by']['zero_total'], 'variant' => 'default', 'icon' => 'users', 'sub' => 'Empty or fully exempt' ],
            [ 'label' => 'Already created', 'value' => (int) $review['created_unsent'] + (int) $review['sent'] + (int) $review['paid'], 'variant' => 'info', 'icon' => 'file', 'sub' => (int) $review['paid'] . ' paid' ],
        ], 4 );

        self::render_checks_table( MyNJILGA_Launch_Checks::review_checks( $review, $year, MyNJILGA_Page_Invoicing::page_url( $year ) ) );

        // Empty and fully-exempt firms need nobody (they're counted above); only the ones a person can fix are listed.
        $needsPerson = array_values( array_filter( (array) $review['attention'], static function ( $a ) {
            return in_array( (string) $a['reason'], [ 'no_owner', 'other' ], true );
        } ) );
        if ( $needsPerson ) {
            MyNJILGA_Admin_UI::section( 'Firms that need a person', 'These will not be invoiced as they stand: fix the cause (usually a missing Owner), then generate the preview again.', count( $needsPerson ) );
            echo '<div class="njilga-card njilga-table-boxed"><div class="njilga-tablewrap"><table class="njilga-table"><thead><tr><th>Firm</th><th>Why</th></tr></thead><tbody>';
            $why = [ 'no_owner' => [ 'No Owner', 'warning' ], 'other' => [ 'Excluded', 'warning' ] ];
            foreach ( array_slice( $needsPerson, 0, self::LIST_LIMIT ) as $a ) {
                $w = $why[ $a['reason'] ] ?? $why['other'];
                printf( '<tr><td>%s</td><td>%s</td></tr>', esc_html( (string) $a['name'] ), MyNJILGA_Admin_UI::pill( $w[0], $w[1] ) );
            }
            echo '</tbody></table></div></div>';
        }

        printf(
            '<div class="njilga-actions"><a class="njilga-btn njilga-btn-outline" href="%s">Open Invoicing for %d</a> <a class="njilga-btn njilga-btn-primary" href="%s">Next: create invoices &rarr;</a></div>',
            esc_url( MyNJILGA_Page_Invoicing::page_url( $year ) ),
            $year,
            esc_url( self::url( $year, 4 ) )
        );
    }

    // ---- Step 4: Create -----------------------------------------------------

    /**
     * @param array<int,object>   $rows
     * @param array<string,mixed> $review
     */
    private static function render_step_create( int $year, bool $live, array $rows, array $review ): void {
        MyNJILGA_Admin_UI::section( 'Create the ' . $year . ' invoices', 'Creating an invoice freezes the firm\'s roster and prices and issues the invoice in Stripe. It does not email anyone — that is step 5. Start with a few firms you know, check them in Stripe, then create the rest.' );

        $gateway = MyNJILGA_Invoicing::gateway();
        $errors  = $gateway->is_available() ? (array) $gateway->readiness_errors() : [ $gateway->name() . ' is not active.' ];
        $can     = ! $errors;
        if ( ! $can ) {
            MyNJILGA_Admin_UI::callout( '<strong>Stripe isn\'t ready</strong>, so nothing can be created yet: ' . esc_html( (string) reset( $errors ) ), 'error' );
        }
        if ( MyNJILGA_Invoice_Creator::has_pending_jobs() ) {
            MyNJILGA_Admin_UI::callout( sprintf( '<strong>Invoices are being created in the background.</strong> <a href="%s">Reload</a> to see them appear. Any failures are recorded on the firm\'s row.', esc_url( self::url( $year, 4 ) ) ), 'info' );
        }

        MyNJILGA_Admin_UI::stat_cards( [
            [ 'label' => 'Ready to create', 'value' => (int) $review['ready'], 'variant' => 'default', 'icon' => 'file' ],
            [ 'label' => 'Being created', 'value' => (int) $review['creating'], 'variant' => (int) $review['creating'] > 0 ? 'info' : 'default', 'icon' => 'refresh' ],
            [ 'label' => 'Created, not yet sent', 'value' => (int) $review['created_unsent'], 'variant' => 'success', 'icon' => 'check-circle' ],
            [ 'label' => 'Errors', 'value' => (int) $review['errors'], 'variant' => (int) $review['errors'] > 0 ? 'destructive' : 'default', 'icon' => 'alert' ],
        ], 4 );

        $ready = array_values( array_filter( $rows, static function ( $r ) {
            return (string) ( $r->invoice_kind ?? '' ) !== MyNJILGA_Dues_Snapshot::KIND_JOIN
                && in_array( (string) $r->status, [ 'draft', 'approved' ], true )
                && ! MyNJILGA_Invoice_Creator::is_actively_queued( (string) ( $r->queued_at ?? '' ), current_time( 'mysql' ) );
        } ) );
        usort( $ready, static function ( $a, $b ) { return strcasecmp( MyNJILGA_Dues_Snapshot::company_name( $a ), MyNJILGA_Dues_Snapshot::company_name( $b ) ); } );

        if ( ! $ready ) {
            MyNJILGA_Admin_UI::callout( (int) $review['total'] === 0 ? sprintf( 'Nothing to create yet — <a href="%s">generate the preview first</a>.', esc_url( self::url( $year, 2 ) ) ) : 'Nothing is waiting to be created.', 'info' );
        } else {
            MyNJILGA_Admin_UI::section( 'Pick your pilot firms', 'Tick a handful you know — firms who will tell you if anything looks off — and create just those first. Then check each in the Stripe Dashboard: the lines, the amounts and the due date.', count( $ready ) );
            self::open_form( self::ACTION_CREATE, $year, 4 );
            echo '<div class="njilga-card njilga-table-boxed"><div class="njilga-tablewrap"><table class="njilga-table"><thead><tr><th class="njilga-col-center">Pilot</th><th>Firm</th><th class="njilga-col-num">Members</th><th class="njilga-col-num">Total</th><th>Bill to</th></tr></thead><tbody>';
            foreach ( array_slice( $ready, 0, self::LIST_LIMIT ) as $r ) {
                $billTo = MyNJILGA_Dues_Snapshot::bill_to( $r );
                printf(
                    '<tr><td class="njilga-col-center"><input type="checkbox" name="row_ids[]" value="%d"></td><td>%s</td><td class="njilga-col-num">%d</td><td class="njilga-col-num">%s</td><td>%s</td></tr>',
                    (int) $r->id,
                    esc_html( MyNJILGA_Dues_Snapshot::company_name( $r ) ),
                    count( MyNJILGA_Dues_Snapshot::members( $r ) ),
                    esc_html( MyNJILGA_Invoicing::money( (int) $r->total_amount_cents ) ),
                    esc_html( (string) ( $billTo['name'] !== '' ? $billTo['name'] : $billTo['email'] ) )
                );
            }
            echo '</tbody></table></div></div>';
            if ( count( $ready ) > self::LIST_LIMIT ) {
                printf( '<p class="njilga-help">Showing the first %d of %d firms. "Create all" covers every one.</p>', self::LIST_LIMIT, count( $ready ) );
            }
            echo '<div class="njilga-actions">';
            printf( '<button type="submit" name="scope" value="selected" class="njilga-btn njilga-btn-primary"%s>Create the ticked firms (pilot)</button> ', $can ? '' : ' disabled' );
            printf(
                '<button type="submit" name="scope" value="all" class="njilga-btn njilga-btn-outline"%s onclick="return confirm(%s)">Create all %d ready</button>',
                $can ? '' : ' disabled',
                esc_attr( "'" . esc_js( sprintf( 'Create all %d ready invoices in Stripe? This does not email anyone.', count( $ready ) ) ) . "'" ),
                count( $ready )
            );
            echo '</div></form>';
        }

        printf( '<div class="njilga-actions"><a class="njilga-btn njilga-btn-outline" href="%s">&larr; Review</a> <a class="njilga-btn njilga-btn-primary" href="%s">Next: send &rarr;</a></div>', esc_url( self::url( $year, 3 ) ), esc_url( self::url( $year, 5 ) ) );
    }

    // ---- Step 5: Send -------------------------------------------------------

    /**
     * @param array<string,mixed>      $review
     * @param array<string,mixed>|null $schedule
     */
    private static function render_step_send( int $year, bool $live, array $review, ?array $schedule ): void {
        $tz     = self::tz();
        $unsent = $live ? MyNJILGA_Launch_Schedule::unsent_rows( $year, true ) : [];

        MyNJILGA_Admin_UI::section( 'Send the ' . $year . ' invoice emails', 'Test the email first, send a few firms now if you want a pilot, then schedule the rest. Nothing is emailed to a firm unless you click "Send now" or confirm a schedule below.' );

        if ( $schedule && MyNJILGA_Launch_Schedule::is_open( (string) $schedule['status'] ) || ( $schedule && (string) $schedule['status'] === MyNJILGA_Launch_Schedule::ST_COMPLETE ) ) {
            self::render_schedule_status( $year, (array) $schedule, $tz );
            // Invoices created after the confirmation are not part of it — say so rather than let it look like a miss.
            if ( $live && MyNJILGA_Launch_Schedule::is_open( (string) $schedule['status'] ) && isset( $schedule['row_ids'] ) ) {
                $covered = array_flip( array_map( 'intval', (array) $schedule['row_ids'] ) );
                $outside = count( array_filter( $unsent, static function ( $r ) use ( $covered ) { return ! isset( $covered[ (int) $r->id ] ); } ) );
                if ( $outside > 0 ) {
                    MyNJILGA_Admin_UI::callout( sprintf( '<strong>%d invoice%s created since this was scheduled %s not part of it.</strong> The schedule sends only what you confirmed. Schedule %s once it finishes, or cancel it and schedule everything together.', $outside, $outside === 1 ? '' : 's', $outside === 1 ? 'is' : 'are', $outside === 1 ? 'it' : 'them' ), 'info' );
                }
            }
        }

        if ( ! $live ) {
            MyNJILGA_Admin_UI::callout( '<strong>Sending is switched off in Test mode</strong> — real contacts would be emailed a test payment link. You can still preview the email and send yourself a sample below. Switch Stripe to Live (Settings → Payments) to send for real.', 'warning' );
        }

        self::render_mail_test( $year );
        self::render_sample( $year, $live );

        if ( ! $live ) {
            echo '<div class="njilga-actions"><a class="njilga-btn njilga-btn-outline" href="' . esc_url( self::url( $year, 4 ) ) . '">&larr; Create</a></div>';
            return;
        }

        if ( ! $unsent ) {
            MyNJILGA_Admin_UI::callout( (int) $review['created_unsent'] === 0 && (int) $review['ready'] > 0
                ? sprintf( 'No invoices have been created yet. <a href="%s">Create some in step 4</a>, then come back to send them.', esc_url( self::url( $year, 4 ) ) )
                : 'There are no created, unsent invoices to email.', 'info' );
            echo '<div class="njilga-actions"><a class="njilga-btn njilga-btn-outline" href="' . esc_url( self::url( $year, 4 ) ) . '">&larr; Create</a></div>';
            return;
        }

        self::render_send_now( $year, $unsent );
        self::render_schedule_form( $year, $unsent, $schedule, $tz );

        echo '<div class="njilga-actions"><a class="njilga-btn njilga-btn-outline" href="' . esc_url( self::url( $year, 4 ) ) . '">&larr; Create</a></div>';
    }

    /** A. Is mail working at all — to an address you choose. */
    private static function render_mail_test( int $year ): void {
        MyNJILGA_Admin_UI::section( 'A. Test that email works' );
        $user = wp_get_current_user();
        self::open_form( self::ACTION_MAILTEST, $year, 5 );
        echo '<div class="njilga-inline-fields"><div class="njilga-field"><label for="njilga-mt-to"><strong>Send to</strong></label>';
        printf( '<input type="email" id="njilga-mt-to" name="to" value="%s" placeholder="you@example.org" required></div>', esc_attr( (string) $user->user_email ) );
        echo '<button type="submit" class="njilga-btn njilga-btn-outline">Send a test email</button></div></form>';
        echo '<p class="njilga-help">A plain message, to the address above only — never to a firm. Check that it arrives (and isn\'t in spam): that is the only proof the site\'s mail is working.</p>';
    }

    /**
     * Invoices of this year in the active mode that an email can be previewed for: not yet sent,
     * or sent already — never a join, never an excluded firm. Drafts count: their payment link
     * is a placeholder until the invoice is created.
     *
     * @return array<int,object>
     */
    private static function preview_rows( int $year, bool $live ): array {
        $rows = MyNJILGA_Dues_Invoice_Table::get_by_year( $year, self::PREVIEW_STATUSES, $live );
        $rows = array_values( array_filter( (array) $rows, static function ( $r ) {
            return (string) ( $r->invoice_kind ?? '' ) !== MyNJILGA_Dues_Snapshot::KIND_JOIN;
        } ) );
        usort( $rows, static function ( $a, $b ) { return strcasecmp( MyNJILGA_Dues_Snapshot::company_name( $a ), MyNJILGA_Dues_Snapshot::company_name( $b ) ); } );
        return $rows;
    }

    /** B. Read the exact email a firm will get, and send yourself (or anyone) a marked sample. */
    private static function render_sample( int $year, bool $live ): void {
        MyNJILGA_Admin_UI::section(
            'B. See exactly what a firm will receive',
            sprintf( 'Built by the same code that sends the real email. Pick any firm — even one whose invoice isn\'t created yet — to read its email, and send a copy marked SAMPLE to any address you like (it never goes to the firm). To change the wording: <a href="%s">Settings → Dues &amp; Billing → Invoice email wording</a>.', esc_url( MyNJILGA_Admin_Menu::url( MyNJILGA_Admin_Menu::SLUG_SETTINGS ) ) )
        );

        $rows = self::preview_rows( $year, $live );
        if ( ! $rows ) {
            MyNJILGA_Admin_UI::callout( sprintf( 'No invoices to preview yet for %d%s. <a href="%s">Generate the preview in step 2</a> first.', $year, $live ? '' : ' in Test mode', esc_url( self::url( $year, 2 ) ) ), 'info' );
            return;
        }

        $pick = isset( $_GET['sample'] ) ? (int) $_GET['sample'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        // Default to an invoice that is already created (it has the real payment link), else the first firm.
        $row = $rows[0];
        foreach ( $rows as $r ) {
            if ( (string) ( $r->hosted_invoice_url ?? '' ) !== '' ) {
                $row = $r;
                break;
            }
        }
        foreach ( $rows as $r ) {
            if ( (int) $r->id === $pick ) {
                $row = $r;
            }
        }

        printf( '<form method="get" action="%s" class="njilga-inline-fields"><input type="hidden" name="page" value="%s"><input type="hidden" name="step" value="5"><input type="hidden" name="dues_year" value="%d"><div class="njilga-field"><label for="njilga-sample"><strong>Firm</strong></label><select id="njilga-sample" name="sample">', esc_url( admin_url( 'admin.php' ) ), esc_attr( MyNJILGA_Admin_Menu::SLUG_LAUNCH ), $year );
        $shown = array_slice( $rows, 0, self::LIST_LIMIT );
        if ( ! in_array( $row, $shown, true ) ) {
            $shown[] = $row;
        }
        foreach ( $shown as $r ) {
            printf( '<option value="%d"%s>%s — %s</option>', (int) $r->id, (int) $r->id === (int) $row->id ? ' selected' : '', esc_html( MyNJILGA_Dues_Snapshot::company_name( $r ) ), esc_html( (string) $r->status ) );
        }
        echo '</select></div><button type="submit" class="njilga-btn njilga-btn-outline">Show this email</button></form>';

        $m = MyNJILGA_Invoice_Sender::compose( $row, true );
        if ( empty( $m['ok'] ) ) {
            MyNJILGA_Admin_UI::callout( '<strong>This firm\'s email can\'t be built:</strong> ' . esc_html( (string) $m['error'] ), 'warning' );
            return;
        }
        if ( ! empty( $m['placeholder'] ) ) {
            MyNJILGA_Admin_UI::callout( 'This firm\'s invoice hasn\'t been created yet, so the payment link below is a placeholder; everything else is what it will say.', 'info' );
        }
        $cc = '';
        $reply = '';
        foreach ( (array) $m['headers'] as $h ) {
            if ( stripos( $h, 'Cc:' ) === 0 ) {
                $cc = trim( substr( $h, 3 ) );
            }
            if ( stripos( $h, 'Reply-To:' ) === 0 ) {
                $reply = trim( substr( $h, 9 ) );
            }
        }
        printf(
            '<p class="njilga-emailmeta"><strong>To:</strong> %s<br>%s%s<strong>Subject:</strong> %s</p><div class="njilga-emailsample">%s</div>',
            esc_html( (string) $m['to'] ),
            $cc !== '' ? '<strong>Cc:</strong> ' . esc_html( $cc ) . '<br>' : '',
            $reply !== '' ? '<strong>Reply-To:</strong> ' . esc_html( $reply ) . '<br>' : '',
            esc_html( (string) $m['subject'] ),
            esc_html( (string) $m['body'] )
        );

        $user = wp_get_current_user();
        self::open_form( self::ACTION_SAMPLE, $year, 5 );
        printf( '<input type="hidden" name="row_id" value="%d">', (int) $row->id );
        echo '<div class="njilga-inline-fields"><div class="njilga-field"><label for="njilga-sample-to"><strong>Send this sample to</strong></label>';
        printf( '<input type="email" id="njilga-sample-to" name="to" value="%s" placeholder="you@example.org" required></div>', esc_attr( (string) $user->user_email ) );
        echo '<button type="submit" class="njilga-btn njilga-btn-outline">Email me this sample</button></div></form>';
    }

    /**
     * @param array<int,object> $unsent
     */
    private static function render_send_now( int $year, array $unsent ): void {
        MyNJILGA_Admin_UI::section( 'C. Send a few now (optional pilot)', 'Tick the firms you created as a pilot and send their invoices right away. Anyone you don\'t tick stays unsent and is covered by the schedule in D.', count( $unsent ) );
        self::open_form( self::ACTION_SEND_NOW, $year, 5 );
        echo '<div class="njilga-card njilga-table-boxed"><div class="njilga-tablewrap"><table class="njilga-table"><thead><tr><th class="njilga-col-center">Send</th><th>Firm</th><th>To</th><th class="njilga-col-num">Total</th></tr></thead><tbody>';
        foreach ( array_slice( $unsent, 0, self::LIST_LIMIT ) as $r ) {
            $billTo = MyNJILGA_Dues_Snapshot::bill_to( $r );
            printf(
                '<tr><td class="njilga-col-center"><input type="checkbox" name="row_ids[]" value="%d"></td><td>%s</td><td>%s</td><td class="njilga-col-num">%s</td></tr>',
                (int) $r->id,
                esc_html( MyNJILGA_Dues_Snapshot::company_name( $r ) ),
                esc_html( (string) ( $billTo['email'] !== '' ? $billTo['email'] : '— (falls back to the contact\'s current email)' ) ),
                esc_html( MyNJILGA_Invoicing::money( (int) $r->total_amount_cents ) )
            );
        }
        echo '</tbody></table></div></div>';
        echo '<div class="njilga-actions"><button type="submit" class="njilga-btn njilga-btn-outline" onclick="return confirm(\'Email the ticked firms their invoices now?\')">Send the ticked firms now</button></div></form>';
    }

    /**
     * @param array<int,object>        $unsent
     * @param array<string,mixed>|null $schedule
     */
    private static function render_schedule_form( int $year, array $unsent, ?array $schedule, \DateTimeZone $tz ): void {
        MyNJILGA_Admin_UI::section( 'D. Schedule the rest', sprintf( '%d invoice%s will go out in paced batches, so firms (and your inbox) aren\'t hit all at once. You can pause, resume or cancel at any time, and nothing is ever emailed twice.', count( $unsent ), count( $unsent ) === 1 ? '' : 's' ) );

        $open = $schedule && MyNJILGA_Launch_Schedule::is_open( (string) $schedule['status'] );
        if ( $open ) {
            MyNJILGA_Admin_UI::callout( 'A send is already ' . esc_html( (string) $schedule['status'] ) . ' for ' . $year . ' — see the status above. Cancel it to schedule a different one.', 'info' );
            return;
        }
        $other = MyNJILGA_Launch_Schedule::open_record();
        if ( $other ) {
            MyNJILGA_Admin_UI::callout( sprintf( 'A send for %d is still %s. Finish or cancel it before scheduling another.', (int) $other['year'], esc_html( (string) $other['status'] ) ), 'warning' );
            return;
        }
        if ( ! function_exists( 'as_schedule_single_action' ) ) {
            MyNJILGA_Admin_UI::callout( '<strong>Scheduling needs Action Scheduler</strong> (it comes with FluentCRM) and it isn\'t available. You can still send firms by hand above, or from the Invoicing page.', 'error' );
            return;
        }
        $gateway = MyNJILGA_Invoicing::gateway();
        $errors  = $gateway->is_available() ? (array) $gateway->readiness_errors() : [ $gateway->name() . ' is not active.' ];
        if ( $errors ) {
            MyNJILGA_Admin_UI::callout( '<strong>Stripe isn\'t ready</strong>, so nothing will be scheduled: ' . esc_html( (string) reset( $errors ) ), 'error' );
            return;
        }

        if ( MyNJILGA_Invoice_Creator::has_pending_jobs() ) {
            MyNJILGA_Admin_UI::callout( sprintf( '<strong>Invoices are still being created in the background.</strong> Scheduling opens once they finish, so the list you confirm is the list that is sent. <a href="%s">Reload</a> in a moment.', esc_url( self::url( $year, 5 ) ) ), 'info' );
            return;
        }

        // Chosen timing (a GET "preview timing"), else the defaults.
        $g = static function ( string $k, $d ) { return isset( $_GET[ $k ] ) ? wp_unslash( $_GET[ $k ] ) : $d; }; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $timing = isset( $_GET['timing'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $in = [
            'start'         => (string) $g( 'start', self::default_start( time(), $tz ) ),
            'batch_size'    => (string) $g( 'batch_size', '25' ),
            'interval_min'  => (string) $g( 'interval_min', '15' ),
            'weekdays_only' => $timing ? ( isset( $_GET['weekdays_only'] ) ? '1' : '' ) : '1', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            'window'        => $timing ? ( isset( $_GET['window'] ) ? '1' : '' ) : '1', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            'window_start'  => (string) $g( 'window_start', '9' ),
            'window_end'    => (string) $g( 'window_end', '17' ),
        ];

        echo '<div class="njilga-card njilga-card-pad">';
        printf( '<form method="get" action="%s"><input type="hidden" name="page" value="%s"><input type="hidden" name="step" value="5"><input type="hidden" name="dues_year" value="%d"><input type="hidden" name="timing" value="1">', esc_url( admin_url( 'admin.php' ) ), esc_attr( MyNJILGA_Admin_Menu::SLUG_LAUNCH ), $year );
        echo '<div class="njilga-inline-fields">';
        printf( '<div class="njilga-field"><label for="nj-start"><strong>Start</strong> (site time, %s)</label><input type="datetime-local" id="nj-start" name="start" value="%s" required></div>', esc_html( $tz->getName() ), esc_attr( $in['start'] ) );
        printf( '<div class="njilga-field"><label for="nj-batch"><strong>Emails per batch</strong></label><input type="number" id="nj-batch" name="batch_size" min="%d" max="%d" value="%s" class="njilga-input-sm"></div>', MyNJILGA_Launch_Schedule::MIN_BATCH, MyNJILGA_Launch_Schedule::MAX_BATCH, esc_attr( $in['batch_size'] ) );
        printf( '<div class="njilga-field"><label for="nj-int"><strong>Minutes between batches</strong></label><input type="number" id="nj-int" name="interval_min" min="%d" max="%d" value="%s" class="njilga-input-sm"></div>', MyNJILGA_Launch_Schedule::MIN_INTERVAL, MyNJILGA_Launch_Schedule::MAX_INTERVAL, esc_attr( $in['interval_min'] ) );
        echo '</div>';
        printf( '<p><label class="njilga-check-label"><input type="checkbox" name="weekdays_only" value="1"%s> Weekdays only (Monday–Friday)</label></p>', $in['weekdays_only'] !== '' ? ' checked' : '' );
        printf(
            '<p><label class="njilga-check-label"><input type="checkbox" name="window" value="1"%s> Only send between <input type="number" name="window_start" min="0" max="23" value="%s" class="njilga-input-sm"> and <input type="number" name="window_end" min="1" max="24" value="%s" class="njilga-input-sm"> o\'clock (24-hour, site time)</label></p>',
            $in['window'] !== '' ? ' checked' : '',
            esc_attr( $in['window_start'] ),
            esc_attr( $in['window_end'] )
        );
        echo '<div class="njilga-actions"><button type="submit" class="njilga-btn njilga-btn-outline">Preview the timing</button></div></form></div>';

        if ( ! $timing ) {
            MyNJILGA_Admin_UI::callout( 'Choose the timing above and click <strong>Preview the timing</strong>. You\'ll see when the first and last batch will go before anything is scheduled.', 'info' );
            return;
        }

        $norm = MyNJILGA_Launch_Schedule::normalize_options( $in, time(), $tz );
        if ( ! $norm['ok'] ) {
            MyNJILGA_Admin_UI::callout( '<strong>That timing can\'t be used:</strong> ' . esc_html( $norm['error'] ), 'error' );
            return;
        }
        $est = MyNJILGA_Launch_Schedule::estimate( count( $unsent ), $norm['options'], $tz );
        MyNJILGA_Admin_UI::callout(
            sprintf(
                '<strong>%d email%s in %d batch%s.</strong> First batch: <strong>%s</strong>. Last batch: about <strong>%s</strong>.',
                count( $unsent ),
                count( $unsent ) === 1 ? '' : 's',
                $est['batches'],
                $est['batches'] === 1 ? '' : 'es',
                esc_html( self::fmt_time( $est['first_ts'], $tz ) ),
                esc_html( self::fmt_time( $est['last_ts'], $tz ) )
            ),
            'success'
        );

        echo '<div class="njilga-card njilga-card-pad">';
        self::open_form( self::ACTION_SCHEDULE, $year, 5 );
        foreach ( $in as $k => $v ) {
            printf( '<input type="hidden" name="%s" value="%s">', esc_attr( $k ), esc_attr( $v ) );
        }
        // Ties the confirmation to the exact invoices listed above, not just how many there are.
        printf( '<input type="hidden" name="list" value="%s">', esc_attr( MyNJILGA_Launch_Schedule::list_fingerprint( array_map( static function ( $r ) { return (int) $r->id; }, $unsent ) ) ) );
        printf( '<p><label class="njilga-check-label"><input type="checkbox" name="confirm" value="1" required> I have read the sample email, and I understand that %d invoice%s will be emailed to firms starting at the time above.</label></p>', count( $unsent ), count( $unsent ) === 1 ? '' : 's' );
        echo '<div class="njilga-actions"><button type="submit" class="njilga-btn njilga-btn-primary">Schedule these emails</button></div></form></div>';
    }

    /**
     * @param array<string,mixed> $rec
     */
    private static function render_schedule_status( int $year, array $rec, \DateTimeZone $tz ): void {
        [ $label, $variant ] = self::status_pill( (string) $rec['status'] );
        $sent   = (int) ( $rec['sent'] ?? 0 );
        $failed = (int) ( $rec['failed'] ?? 0 );
        $total  = max( 1, (int) ( $rec['unsent_at_schedule'] ?? 0 ) );
        $pct    = (int) min( 100, floor( ( $sent / $total ) * 100 ) );
        $active = MyNJILGA_Launch_Schedule::is_active( (string) $rec['status'] );

        MyNJILGA_Admin_UI::section( 'Status of the scheduled send' );
        echo '<div class="njilga-card njilga-card-pad">';
        printf( '<p>%s</p>', MyNJILGA_Admin_UI::pill( $label, $variant ) );
        echo '<div class="njilga-progress-wrap">';
        printf( '<div class="njilga-progress-top"><span class="njilga-progress-label">%d of %d sent%s</span></div>', $sent, (int) ( $rec['unsent_at_schedule'] ?? 0 ), $failed > 0 ? sprintf( ' · %d could not be sent', $failed ) : '' );
        printf( '<div class="njilga-progress"><div class="njilga-progress-bar" style="width:%d%%"></div></div></div>', $pct );
        if ( $active && ! empty( $rec['next_run_ts'] ) ) {
            printf( '<p>Next batch: <strong>%s</strong></p>', esc_html( self::fmt_time( (int) $rec['next_run_ts'], $tz ) ) );
            if ( time() - (int) $rec['next_run_ts'] > 600 ) {
                MyNJILGA_Admin_UI::callout( 'The next batch was due more than 10 minutes ago. Scheduled jobs run when WordPress\'s scheduler runs — on a quiet site that needs a visit or a real server cron. You can send the next batch now.', 'warning' );
            }
        }
        echo '<div class="njilga-actions">';
        if ( $active ) {
            echo MyNJILGA_Admin_UI::action_form( self::ACTION_CONTROL, 'Send the next batch now', [ 'dues_year' => $year, 'op' => 'run_now' ], 'outline', '', 'Send the next batch of emails right now?' );
            echo MyNJILGA_Admin_UI::action_form( self::ACTION_CONTROL, 'Pause', [ 'dues_year' => $year, 'op' => 'pause' ], 'outline' );
        }
        if ( in_array( (string) $rec['status'], [ MyNJILGA_Launch_Schedule::ST_PAUSED, MyNJILGA_Launch_Schedule::ST_HALTED ], true ) ) {
            echo MyNJILGA_Admin_UI::action_form( self::ACTION_CONTROL, 'Resume', [ 'dues_year' => $year, 'op' => 'resume' ], 'primary' );
        }
        if ( MyNJILGA_Launch_Schedule::is_open( (string) $rec['status'] ) ) {
            echo MyNJILGA_Admin_UI::action_form( self::ACTION_CONTROL, 'Cancel the schedule', [ 'dues_year' => $year, 'op' => 'cancel' ], 'outline', '', 'Cancel the scheduled send? Emails already sent stay sent; the rest will not go out.' );
        }
        echo '</div>';

        $failures = (array) ( $rec['failures'] ?? [] );
        if ( $failures ) {
            $rows = MyNJILGA_Dues_Invoice_Table::get_many( array_map( 'intval', array_keys( $failures ) ) );
            echo '<h3 class="njilga-section-title">Invoices that couldn\'t be sent</h3><ul class="njilga-timeline">';
            foreach ( array_slice( $rows, 0, 30 ) as $r ) {
                printf( '<li><strong>%s</strong> — %s</li>', esc_html( MyNJILGA_Dues_Snapshot::company_name( $r ) ), esc_html( (string) ( $failures[ (int) $r->id ] ?? '' ) ) );
            }
            echo '</ul><p class="njilga-help">Fix the cause (usually a missing email on the Owner), then send these from the Invoicing page.</p>';
        }
        $notes = array_reverse( array_slice( (array) ( $rec['notes'] ?? [] ), -10 ) );
        if ( $notes ) {
            echo '<h3 class="njilga-section-title">Log</h3><ul class="njilga-timeline">';
            foreach ( $notes as $n ) {
                printf( '<li><span class="njilga-dim">%s</span> — %s</li>', esc_html( self::fmt_time( (int) ( $n['ts'] ?? 0 ), $tz ) ), esc_html( (string) ( $n['msg'] ?? '' ) ) );
            }
            echo '</ul>';
        }
        echo '</div>';
    }

    // ---- Notices ------------------------------------------------------------

    private static function render_notice(): void {
        if ( empty( $_GET['msg'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return;
        }
        $msg    = sanitize_key( wp_unslash( (string) $_GET['msg'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $n      = isset( $_GET['n'] ) ? (int) $_GET['n'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $f      = isset( $_GET['f'] ) ? (int) $_GET['f'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $to     = isset( $_GET['to'] ) ? sanitize_email( wp_unslash( (string) $_GET['to'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $detail = isset( $_GET['detail'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['detail'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $plural = static function ( int $c, string $one, string $many ): string { return $c === 1 ? $one : $many; };

        switch ( $msg ) {
            case 'previewed':
                MyNJILGA_Admin_UI::callout( sprintf( '<strong>Preview generated:</strong> %d invoice%s ready, %d excluded.', $n, $plural( $n, '', 's' ), $f ), 'success' );
                break;
            case 'cutover_saved':
                MyNJILGA_Admin_UI::callout( '<strong>Saved.</strong> Joiners\' dues year is updated.', 'success' );
                break;
            case 'cutover_invalid':
                MyNJILGA_Admin_UI::callout( 'That isn\'t a date in <code>MM-DD</code> form (for example <code>11-01</code>), so nothing was changed.', 'error' );
                break;
            case 'queued':
                MyNJILGA_Admin_UI::callout( sprintf( '<strong>%d invoice%s queued</strong> for creation in the background. Reload this page in a moment to see them appear.', $n, $plural( $n, '', 's' ) ), 'success' );
                break;
            case 'created':
                MyNJILGA_Admin_UI::callout( sprintf( '<strong>%d invoice%s created</strong>%s.', $n, $plural( $n, '', 's' ), $f > 0 ? sprintf( ', %d failed — see the error on the firm\'s row in Invoicing', $f ) : '' ), $f > 0 ? 'warning' : 'success' );
                break;
            case 'nothing':
                MyNJILGA_Admin_UI::callout( 'Nothing was selected, or nothing was eligible.', 'info' );
                break;
            case 'mailtest_sent':
                MyNJILGA_Admin_UI::callout( sprintf( '<strong>Test email sent%s.</strong> Check that inbox (and spam) — only a person can confirm it arrived.', $to !== '' ? ' to ' . esc_html( $to ) : '' ), 'success' );
                break;
            case 'mailtest_failed':
                MyNJILGA_Admin_UI::callout( '<strong>The test email could not be sent.</strong> The site\'s mail isn\'t working; fix that before sending invoices.', 'error' );
                break;
            case 'sample_sent':
                MyNJILGA_Admin_UI::callout( sprintf( '<strong>Sample sent%s</strong>, marked SAMPLE. It was not sent to the firm.', $to !== '' ? ' to ' . esc_html( $to ) : '' ), 'success' );
                break;
            case 'sent_now':
                MyNJILGA_Admin_UI::callout( sprintf( '<strong>%d email%s sent</strong>%s.', $n, $plural( $n, '', 's' ), $f > 0 ? sprintf( ', %d could not be sent', $f ) : '' ), $f > 0 ? 'warning' : 'success' );
                break;
            case 'scheduled':
                MyNJILGA_Admin_UI::callout( '<strong>Scheduled.</strong> The first batch will go out at the time shown in the status below.', 'success' );
                break;
            case 'control_ok':
                MyNJILGA_Admin_UI::callout( $detail !== '' ? esc_html( $detail ) : 'Done.', 'success' );
                break;
            case 'error':
                MyNJILGA_Admin_UI::callout( '<strong>That didn\'t work:</strong> ' . esc_html( $detail ), 'error' );
                break;
        }
    }

    // =========================================================================
    // admin-post handlers — each checks the capability and a nonce, takes NO
    // parameters, and ends in a redirect back to the wizard.
    // =========================================================================

    public static function handle_cutover(): void {
        self::guard( self::ACTION_CUTOVER );
        $year  = self::post_year();
        $clean = self::clean_cutover( (string) wp_unslash( $_POST['join_next_year_from'] ?? '' ) );
        if ( $clean === null ) {
            self::back( $year, 2, [ 'msg' => 'cutover_invalid' ] );
        }
        $settings = MyNJILGA_Dues_Settings::get();
        $settings['general']['join_next_year_from'] = $clean;
        MyNJILGA_Dues_Settings::save( $settings );
        self::back( $year, 2, [ 'msg' => 'cutover_saved' ] );
    }

    public static function handle_preview(): void {
        self::guard( self::ACTION_PREVIEW );
        $year  = self::post_year();
        $stats = MyNJILGA_Dues_Preview::generate_and_persist( $year );
        self::back( $year, 3, [ 'msg' => 'previewed', 'n' => (int) $stats['drafts'], 'f' => (int) $stats['excluded'] ] );
    }

    public static function handle_create(): void {
        self::guard( self::ACTION_CREATE );
        $year = self::post_year();
        $live = self::is_live();

        // Same gate the Invoicing page uses: never approve rows the gateway can't create.
        $gateway = MyNJILGA_Invoicing::gateway();
        if ( ! $gateway->is_available() || $gateway->readiness_errors() ) {
            $errs = $gateway->is_available() ? (array) $gateway->readiness_errors() : [ $gateway->name() . ' is not active.' ];
            self::back( $year, 4, [ 'msg' => 'error', 'detail' => (string) reset( $errs ) ] );
        }

        $scope = isset( $_POST['scope'] ) ? sanitize_key( wp_unslash( (string) $_POST['scope'] ) ) : '';
        $ids   = [];
        if ( $scope === 'all' ) {
            foreach ( MyNJILGA_Dues_Invoice_Table::get_by_year( $year, [ MyNJILGA_Dues_Invoice_Table::STATUS_DRAFT, MyNJILGA_Dues_Invoice_Table::STATUS_APPROVED ], $live ) as $r ) {
                $ids[] = (int) $r->id;
            }
        } else {
            $ids = self::parse_ids( $_POST['row_ids'] ?? [] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        }

        // Only draft/approved rows of THIS year, in the CURRENT mode, qualify.
        $eligible = [];
        foreach ( $ids as $id ) {
            $row = MyNJILGA_Dues_Invoice_Table::get( $id );
            if ( $row
                && (int) $row->dues_year === $year
                && (bool) $row->livemode === $live
                && (string) ( $row->invoice_kind ?? '' ) !== MyNJILGA_Dues_Snapshot::KIND_JOIN
                && in_array( (string) $row->status, [ MyNJILGA_Dues_Invoice_Table::STATUS_DRAFT, MyNJILGA_Dues_Invoice_Table::STATUS_APPROVED ], true )
            ) {
                $eligible[] = (int) $row->id;
            }
        }
        if ( ! $eligible ) {
            self::back( $year, 4, [ 'msg' => 'nothing' ] );
        }

        MyNJILGA_Dues_Invoice_Table::mark_approved( $eligible );
        $r = MyNJILGA_Invoice_Creator::schedule( $eligible, $year );
        if ( $r['mode'] === 'scheduled' && $r['queued'] > 0 ) {
            self::back( $year, 4, [ 'msg' => 'queued', 'n' => (int) $r['queued'] ] );
        }
        self::back( $year, 4, [ 'msg' => 'created', 'n' => (int) $r['ok'], 'f' => (int) $r['fail'] ] );
    }

    public static function handle_mailtest(): void {
        self::guard( self::ACTION_MAILTEST );
        $year = self::post_year();
        $to   = self::recipient();
        if ( $to === '' ) {
            self::back( $year, 5, [ 'msg' => 'error', 'detail' => 'That doesn\'t look like a valid email address.' ] );
        }
        $sent = wp_mail(
            $to,
            'My NJILGA Launch Wizard: test email',
            "This is a test email from the My NJILGA Launch Wizard.\n\nIf you can read this, the site can send email. Check that it did not land in spam: the invoice emails are sent the same way.\n\nNothing was sent to any firm."
        );
        if ( $sent ) {
            update_option( MyNJILGA_Launch_Checks::MAIL_TEST_OPTION, time(), false );
        }
        self::back( $year, 5, [ 'msg' => $sent ? 'mailtest_sent' : 'mailtest_failed', 'to' => $to ] );
    }

    public static function handle_sample(): void {
        self::guard( self::ACTION_SAMPLE );
        $year = self::post_year();
        $to   = self::recipient();
        if ( $to === '' ) {
            self::back( $year, 5, [ 'msg' => 'error', 'detail' => 'That doesn\'t look like a valid email address.' ] );
        }
        $row = MyNJILGA_Dues_Invoice_Table::get( isset( $_POST['row_id'] ) ? (int) $_POST['row_id'] : 0 );
        if ( ! $row
            || (int) $row->dues_year !== $year
            || (bool) $row->livemode !== self::is_live()
            || (string) ( $row->invoice_kind ?? '' ) === MyNJILGA_Dues_Snapshot::KIND_JOIN
            || ! in_array( (string) $row->status, self::PREVIEW_STATUSES, true )
        ) {
            self::back( $year, 5, [ 'msg' => 'error', 'detail' => 'That invoice wasn\'t found.' ] );
        }
        $m = MyNJILGA_Invoice_Sender::compose( $row, true );
        if ( empty( $m['ok'] ) ) {
            self::back( $year, 5, [ 'msg' => 'error', 'detail' => (string) $m['error'] ] );
        }
        // To the address you chose ONLY: no firm, no CC, and a clear banner so it can never be mistaken for the real thing.
        $headers = [];
        foreach ( (array) $m['headers'] as $h ) {
            if ( stripos( $h, 'Reply-To:' ) === 0 ) {
                $headers[] = $h;
            }
        }
        $banner = '*** SAMPLE — this went only to ' . $to . ', not to the firm. It would be sent to ' . (string) $m['to'] . ' ***';
        if ( ! empty( $m['placeholder'] ) ) {
            $banner .= "\n*** The payment link below is a placeholder: this invoice has not been created yet. ***";
        }
        $sent = wp_mail( $to, '[SAMPLE] ' . (string) $m['subject'], $banner . "\n\n" . (string) $m['body'], $headers );
        self::back( $year, 5, [ 'msg' => $sent ? 'sample_sent' : 'mailtest_failed', 'sample' => (int) $row->id, 'to' => $to ] );
    }

    public static function handle_send_now(): void {
        self::guard( self::ACTION_SEND_NOW );
        $year = self::post_year();
        if ( ! self::is_live() ) {
            self::back( $year, 5, [ 'msg' => 'error', 'detail' => 'Stripe is in Test mode, so nothing is sent to firms. Switch to Live first.' ] );
        }
        // The same lock a scheduled batch holds: a hand-send can neither overlap a batch nor a second click of itself.
        $lock = MyNJILGA_Launch_Schedule::acquire_lock();
        if ( $lock === '' ) {
            self::back( $year, 5, [ 'msg' => 'error', 'detail' => 'A batch is sending right now (or another send is in progress). Wait a minute, then try again.' ] );
        }
        $ok = 0;
        $fail = 0;
        try {
            self::send_selected( $year, $ok, $fail );
        } finally {
            MyNJILGA_Launch_Schedule::release_lock( $lock );
        }
        if ( $ok === 0 && $fail === 0 ) {
            self::back( $year, 5, [ 'msg' => 'nothing' ] );
        }
        self::back( $year, 5, [ 'msg' => 'sent_now', 'n' => $ok, 'f' => $fail ] );
    }

    /** Email each ticked invoice that is still created and unsent. Counts into $ok / $fail. */
    private static function send_selected( int $year, int &$ok, int &$fail ): void {
        foreach ( self::parse_ids( $_POST['row_ids'] ?? [] ) as $id ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
            $row = MyNJILGA_Dues_Invoice_Table::get( $id );
            // Created and not yet sent, this year, live, and not an online join: exactly what a schedule would send.
            if ( ! $row
                || (int) $row->dues_year !== $year
                || ! (bool) $row->livemode
                || (string) $row->status !== MyNJILGA_Dues_Invoice_Table::STATUS_CREATED
                || (string) ( $row->invoice_kind ?? '' ) === MyNJILGA_Dues_Snapshot::KIND_JOIN
            ) {
                continue;
            }
            try {
                $res = MyNJILGA_Invoice_Sender::send_for_row( $row );
            } catch ( \Throwable $e ) {
                $res = [ 'ok' => false, 'error' => $e->getMessage() ];
            }
            if ( ! empty( $res['ok'] ) ) {
                $ok++;
            } else {
                $fail++;
                MyNJILGA_Dues_Invoice_Table::set_error( (int) $row->id, (string) ( $res['error'] ?? 'Unknown error' ) );
            }
        }
    }

    public static function handle_schedule(): void {
        self::guard( self::ACTION_SCHEDULE );
        $year = self::post_year();
        $live = self::is_live();

        if ( empty( $_POST['confirm'] ) ) {
            self::back( $year, 5, [ 'msg' => 'error', 'detail' => 'Please tick the box to confirm you have read the sample email.' ] );
        }
        if ( ! $live ) {
            self::back( $year, 5, [ 'msg' => 'error', 'detail' => 'Stripe is in Test mode. Scheduling is only available in Live mode.' ] );
        }
        $gateway = MyNJILGA_Invoicing::gateway();
        if ( ! $gateway->is_available() || $gateway->readiness_errors() ) {
            $errs = $gateway->is_available() ? (array) $gateway->readiness_errors() : [ $gateway->name() . ' is not active.' ];
            self::back( $year, 5, [ 'msg' => 'error', 'detail' => 'Stripe isn\'t ready: ' . (string) reset( $errs ) ] );
        }

        $tz   = self::tz();
        $in   = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $norm = MyNJILGA_Launch_Schedule::normalize_options( (array) $in, time(), $tz );
        if ( ! $norm['ok'] ) {
            self::back( $year, 5, [ 'msg' => 'error', 'detail' => $norm['error'] ] );
        }

        if ( MyNJILGA_Invoice_Creator::has_pending_jobs() ) {
            self::back( $year, 5, [ 'msg' => 'error', 'detail' => 'Invoices are still being created in the background. Wait until they finish, reload this page and confirm again, so the list you confirm is the list that is sent.' ] );
        }

        // The schedule sends exactly the invoices the admin was shown and confirmed: not "whatever is unsent by then".
        $ids = array_map( static function ( $r ) { return (int) $r->id; }, MyNJILGA_Launch_Schedule::unsent_rows( $year, true ) );
        $seen = isset( $_POST['list'] ) ? sanitize_key( wp_unslash( (string) $_POST['list'] ) ) : '';
        if ( $seen === '' || ! hash_equals( MyNJILGA_Launch_Schedule::list_fingerprint( $ids ), $seen ) ) {
            self::back( $year, 5, [ 'msg' => 'error', 'detail' => sprintf( 'The invoices waiting to be sent changed while you were on this page (there are now %d). Reload step 5, read the list and sample again, and confirm.', count( $ids ) ) ] );
        }
        $user = wp_get_current_user();
        $r    = MyNJILGA_Launch_Schedule::create( $year, $norm['options'], true, (int) $user->ID, (string) $user->user_email, $ids );
        if ( ! $r['ok'] ) {
            self::back( $year, 5, [ 'msg' => 'error', 'detail' => $r['error'] ] );
        }
        self::back( $year, 5, [ 'msg' => 'scheduled' ] );
    }

    public static function handle_control(): void {
        self::guard( self::ACTION_CONTROL );
        $year = self::post_year();
        $op   = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( (string) $_POST['op'] ) ) : '';

        switch ( $op ) {
            case 'pause':
                $r = MyNJILGA_Launch_Schedule::pause( $year );
                $done = 'Paused. Nothing more will be sent until you resume.';
                break;
            case 'resume':
                $r = MyNJILGA_Launch_Schedule::resume( $year );
                $done = 'Resumed.';
                break;
            case 'cancel':
                $r = MyNJILGA_Launch_Schedule::cancel( $year );
                $done = 'Cancelled. Emails already sent stay sent; the rest will not go out.';
                break;
            case 'run_now':
                $b = MyNJILGA_Launch_Schedule::run_batch( $year, null, true );
                if ( $b['outcome'] === 'skipped' ) {
                    self::back( $year, 5, [ 'msg' => 'error', 'detail' => 'There is no active send to run (it may be paused, halted or finished).' ] );
                }
                self::back( $year, 5, [ 'msg' => 'control_ok', 'detail' => sprintf( 'Batch run: %d sent, %d failed, %d remaining (%s).', (int) $b['sent'], (int) $b['failed'], (int) $b['remaining'], (string) $b['outcome'] ) ] );
                return;
            default:
                self::back( $year, 5, [ 'msg' => 'error', 'detail' => 'Unknown action.' ] );
                return;
        }
        if ( empty( $r['ok'] ) ) {
            self::back( $year, 5, [ 'msg' => 'error', 'detail' => (string) $r['error'] ] );
        }
        self::back( $year, 5, [ 'msg' => 'control_ok', 'detail' => $done ] );
    }

    // =========================================================================
    // Plumbing
    // =========================================================================

    /** The address a test/sample goes to: what was typed, else the signed-in admin's; '' when it isn't a valid address. */
    private static function recipient(): string {
        $raw = isset( $_POST['to'] ) ? trim( (string) wp_unslash( $_POST['to'] ) ) : '';
        if ( $raw === '' ) {
            $user = wp_get_current_user();
            $raw  = (string) $user->user_email;
        }
        $to = sanitize_email( $raw );
        return ( $to !== '' && is_email( $to ) ) ? $to : '';
    }

    private static function guard( string $action ): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Access denied.' );
        }
        check_admin_referer( $action );
    }

    private static function get_year(): int {
        $y = isset( $_GET['dues_year'] ) ? (int) $_GET['dues_year'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        return ( $y >= 2000 && $y <= 2100 ) ? $y : MyNJILGA_Invoicing::default_dues_year();
    }

    private static function post_year(): int {
        $y = isset( $_POST['dues_year'] ) ? (int) $_POST['dues_year'] : 0;
        return ( $y >= 2000 && $y <= 2100 ) ? $y : MyNJILGA_Invoicing::default_dues_year();
    }

    private static function get_step(): int {
        $s = isset( $_GET['step'] ) ? (int) $_GET['step'] : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        return isset( self::STEPS[ $s ] ) ? $s : 1;
    }

    private static function is_live(): bool {
        return MyNJILGA_Stripe_Connection::active_mode() === MyNJILGA_Stripe_Connection::MODE_LIVE;
    }

    private static function tz(): \DateTimeZone {
        return function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );
    }

    public static function url( int $year, int $step, array $args = [] ): string {
        return add_query_arg( array_merge( [ 'page' => MyNJILGA_Admin_Menu::SLUG_LAUNCH, 'step' => $step, 'dues_year' => $year ], $args ), admin_url( 'admin.php' ) );
    }

    /** @param array<string,mixed> $args */
    private static function back( int $year, int $step, array $args = [] ): void {
        wp_safe_redirect( self::url( $year, $step, $args ) );
        exit;
    }

    private static function open_form( string $action, int $year, int $step ): void {
        printf(
            '<form method="post" action="%s"><input type="hidden" name="action" value="%s"><input type="hidden" name="dues_year" value="%d">%s',
            esc_url( admin_url( 'admin-post.php' ) ),
            esc_attr( $action ),
            $year,
            wp_nonce_field( $action, '_wpnonce', true, false )
        );
    }
}
