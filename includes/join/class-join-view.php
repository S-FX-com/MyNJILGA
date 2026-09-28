<?php
/**
 * Markup, styles and script for [njilga_join] — the view half of
 * MyNJILGA_Join_Form, which decides WHAT to show.
 *
 * Front-end, inside the site's own theme, so (design.md §6) it keeps its
 * own small scoped stylesheet rather than loading the admin design
 * system: everything is under .njilga-join, and its fonts and colours
 * are the site's own, via MyNJILGA_Front_Style (--nj-navy, --nj-blue,
 * --nj-gold, … read from the theme's Automatic.css tokens).
 *
 * Works without JavaScript: every step renders as one long form with a
 * single submit, validated by the browser and again by the server. With
 * JavaScript it becomes a step-by-step wizard with a firm type-ahead, a
 * live price summary and add/remove colleague rows.
 */
class MyNJILGA_Join_View {

    /** @var bool */
    private static $styled = false;

    // -------------------------------------------------------------------------
    // Small views
    // -------------------------------------------------------------------------

    public static function message( string $text, string $variant = 'info' ): string {
        return self::styles() . sprintf(
            '<div class="njilga-join"><div class="njilga-join__notice njilga-join__notice--%s" role="status">%s</div></div>',
            esc_attr( $variant ),
            esc_html( $text )
        );
    }

    /**
     * @param array<string,mixed> $result
     * @param array<string,mixed> $category
     */
    public static function result( array $result, array $category ): string {
        $kind = (string) ( $result['kind'] ?? '' );
        ob_start();
        echo self::styles(); // phpcs:ignore
        echo '<div class="njilga-join">';

        if ( $kind === 'review' ) {
            self::card( 'Thank you', '<p>Your membership comes to no charge, so there\'s nothing to pay — NJILGA staff will confirm it and email you shortly.</p>' );
        } elseif ( $kind === 'cancelled' ) {
            $user = wp_get_current_user();
            $open = ( $user && $user->ID ) ? MyNJILGA_Join_Orders_Table::get_open_for_user( (int) $user->ID ) : null;
            $body = '<p>No payment was taken. Your details are saved — you can continue whenever you\'re ready.</p>';
            if ( $open && $open->status === MyNJILGA_Join_Orders_Table::STATUS_PENDING ) {
                $body .= '<div class="njilga-join__actions">' . self::action_button( 'resume', 'Continue to payment', 'primary' ) . self::action_button( 'restart', 'Start over', 'ghost' ) . '</div>';
            }
            self::card( 'Payment cancelled', $body );
        } elseif ( $kind === 'join' && ! empty( $result['join'] ) ) {
            echo self::join_status_body( $result['join'], (string) ( $result['message'] ?? '' ) ); // phpcs:ignore
        } else {
            self::card( 'Thank you', '<p>If you completed your payment, you\'ll receive a confirmation email shortly.</p>' );
        }

        echo '</div>';
        return (string) ob_get_clean();
    }

    /**
     * A signed-in visitor who already has a join in progress.
     *
     * @param array<string,mixed> $state
     */
    public static function open_join( object $join, string $pageUrl, array $state ): string {
        unset( $pageUrl, $state );
        return self::styles() . '<div class="njilga-join">' . self::join_status_body( $join, '' ) . '</div>';
    }

    private static function join_status_body( object $join, string $message ): string {
        $T        = 'MyNJILGA_Join_Orders_Table';
        $category = MyNJILGA_Dues_Settings::category( (string) $join->category_key );
        $label    = $category ? $category['label'] : 'membership';
        $total    = MyNJILGA_Invoicing::money( (int) $join->total_cents );
        $extra    = $message !== '' ? '<p class="njilga-join__muted">' . esc_html( $message ) . '</p>' : '';

        ob_start();
        switch ( (string) $join->status ) {
            case $T::STATUS_FULFILLED:
                $colleagues = MyNJILGA_Join_Orders_Table::json( $join, 'colleagues' );
                $body       = '<p>' . esc_html( (string) MyNJILGA_Dues_Settings::general( 'join_success_text', 'Welcome to NJILGA — your membership is active.' ) ) . '</p>';
                $body      .= sprintf( '<p>Your %d %s is active. A confirmation is on its way to <strong>%s</strong>.</p>', (int) $join->dues_year, esc_html( $label ), esc_html( (string) $join->email ) );
                if ( $colleagues ) {
                    $names = array_map( static function ( $c ) {
                        return esc_html( trim( (string) ( $c['first_name'] ?? '' ) . ' ' . (string) ( $c['last_name'] ?? '' ) ) );
                    }, $colleagues );
                    $body .= '<p>We\'ve emailed an invitation to create their own account to: ' . implode( ', ', $names ) . '.</p>';
                }
                $row = (int) $join->invoice_row_id > 0 ? MyNJILGA_Dues_Invoice_Table::get( (int) $join->invoice_row_id ) : null;
                if ( $row && ! empty( $row->hosted_invoice_url ) ) {
                    $body .= sprintf( '<p><a href="%s" target="_blank" rel="noopener">View your receipt</a></p>', esc_url( (string) $row->hosted_invoice_url ) );
                }
                self::card( 'Welcome to NJILGA!', $body, 'success' );
                break;
            case $T::STATUS_PROCESSING:
                self::card( 'Your bank payment is on its way', sprintf( '<p>Thanks — your %s bank payment for %s is processing. Bank transfers usually take about four business days to clear; we\'ll email you the moment your membership is active.</p>', esc_html( $total ), esc_html( $label ) ) . $extra );
                break;
            case $T::STATUS_PAID:
            case $T::STATUS_FULFILLING:
                self::card( 'Payment received', '<p>Thank you — your payment is in, and we\'re finishing setting up your membership. You\'ll receive a confirmation email shortly.</p>' . $extra, 'success' );
                break;
            case $T::STATUS_REVIEW:
                self::card( 'Waiting for NJILGA', '<p>Your membership comes to no charge, so NJILGA staff will confirm it and email you shortly.</p><div class="njilga-join__actions">' . self::action_button( 'restart', 'Withdraw and start over', 'ghost' ) . '</div>' );
                break;
            case $T::STATUS_PENDING:
                self::card(
                    'Finish joining',
                    sprintf( '<p>Your %s application (%s) is waiting for payment.</p>', esc_html( $label ), esc_html( $total ) ) . $extra
                        . '<div class="njilga-join__actions">' . self::action_button( 'resume', 'Continue to payment', 'primary' ) . self::action_button( 'restart', 'Start over', 'ghost' ) . '</div>'
                );
                break;
            case $T::STATUS_FAILED:
                self::card( 'Payment didn\'t go through', '<p>Your bank reported that the payment could not be completed, so your membership hasn\'t started. You can try again below.</p>', 'error' );
                break;
            default:
                self::card( 'Thank you', '<p>If you completed your payment, you\'ll receive a confirmation email shortly.</p>' . $extra );
        }
        return (string) ob_get_clean();
    }

    public static function invite_done(): string {
        return self::styles() . '<div class="njilga-join">' . self::card_html( 'Your account is ready', sprintf( '<p>You\'re signed in, and your NJILGA membership is active.</p><p><a class="njilga-join__btn njilga-join__btn--primary" href="%s">Continue to the NJILGA website</a></p>', esc_url( home_url( '/' ) ) ), 'success' ) . '</div>';
    }

    // -------------------------------------------------------------------------
    // Invite form
    // -------------------------------------------------------------------------

    /**
     * @param array<string,mixed> $state
     */
    public static function invite_form( object $invite, object $join, array $state ): string {
        $errors = (array) ( $state['errors'] ?? [] );
        $old    = (array) ( $state['old'] ?? [] );
        $uid    = 'njj-' . wp_generate_password( 6, false, false );
        $v      = static function ( string $k, string $default = '' ) use ( $old ): string {
            return esc_attr( (string) ( $old[ $k ] ?? $default ) );
        };
        $firm = '';
        if ( (int) $join->company_id > 0 && MyNJILGA_Members_Data::companies_module_active() ) {
            $c    = \FluentCrm\App\Models\Company::find( (int) $join->company_id );
            $firm = $c ? (string) $c->name : '';
        }

        ob_start();
        echo self::styles(); // phpcs:ignore
        ?>
        <div class="njilga-join">
            <?php
            // Anyone can be sent someone else's invitation link, and it
            // takes over every join page for half an hour. Say up front
            // whose account this is, with one click back to the normal
            // join form (its own form: forms can't nest).
            ?>
            <div class="njilga-join__notice" role="status">
                You're creating the account for <strong><?php echo esc_html( (string) $invite->email ); ?></strong>.
                <form class="njilga-join__inline" method="post" action="<?php echo esc_url( MyNJILGA_Join_Form::page_url() ); ?>"><?php self::hidden( 'clear_invite' ); ?><button type="submit" class="njilga-join__toggle">This isn't me — clear this invitation</button></form>
            </div>
            <form class="njilga-join__form" id="<?php echo esc_attr( $uid ); ?>" method="post" action="<?php echo esc_url( MyNJILGA_Join_Form::page_url() ); ?>">
                <?php self::hidden( 'accept_invite' ); ?>
                <div class="njilga-join__card">
                    <h3 class="njilga-join__title"><span class="njilga-join__eyebrow">Your invitation</span>Create your NJILGA account</h3>
                    <p><?php echo esc_html( sprintf( '%s %s has covered your %d NJILGA membership%s — it\'s already active. Choose a username and password to sign in to the website.', $join->first_name, $join->last_name, (int) $join->dues_year, $firm !== '' ? ' with ' . $firm : '' ) ); ?></p>
                    <?php self::general_error( $state ); ?>
                </div>
                <?php self::personal_card( $uid, $v, $errors, [ 'action_url' => MyNJILGA_Join_Form::page_url() ], null, $invite ); ?>
                <div class="njilga-join__card">
                    <h3 class="njilga-join__title">Contact Details</h3>
                    <?php self::nj_address_fields( $uid, $v, $errors ); ?>
                </div>
                <?php self::professional_card( $uid, $v, $errors ); ?>
                <div class="njilga-join__actions"><button type="submit" class="njilga-join__btn njilga-join__btn--primary">Create my account</button></div>
            </form>
        </div>
        <?php
        self::password_script( $uid );
        self::phone_script( $uid );
        self::account_script( $uid );
        self::address_script( $uid );
        return (string) ob_get_clean();
    }

    // -------------------------------------------------------------------------
    // The join form
    // -------------------------------------------------------------------------

    /**
     * @param array<string,mixed> $a See MyNJILGA_Join_Form::render().
     */
    public static function join_form( array $a ): string {
        $category   = $a['category'];
        $isStudent  = $a['form'] === MyNJILGA_Join_Orders_Table::FORM_STUDENT;
        $errors     = (array) $a['errors'];
        $old        = (array) $a['old'];
        $user       = $a['user'];
        $uid        = 'njj-' . wp_generate_password( 6, false, false );
        $v          = static function ( string $k, string $default = '' ) use ( $old ): string {
            return esc_attr( (string) ( $old[ $k ] ?? $default ) );
        };
        $ladder     = (array) $a['ladder'];
        $price      = (int) ( $ladder[0]['price_cents'] ?? 0 );
        // A $0 join never reaches Stripe: the server sends it to NJILGA
        // staff to approve (STATUS_REVIEW). Free for certain only when
        // the first seat costs nothing and no paid colleague seat can be
        // added; the script flips the same copy by the live total.
        $paidSeats = $a['colleagues'] && array_filter( $ladder, static function ( $t ): bool {
            return (int) $t['price_cents'] > 0;
        } );
        $free      = $price === 0 && ! $paidSeats;
        $reviewLbl = $free ? 'Review & submit' : 'Review & pay';

        $steps = $isStudent
            ? [ 'enrollment' => 'Enrollment', 'details' => 'Your details', 'review' => $reviewLbl ]
            : array_filter( [ 'firm' => 'Your firm', 'details' => 'Your details', 'colleagues' => $a['colleagues'] ? 'Add colleagues' : '', 'review' => $reviewLbl ] );

        // "Step 2 of 4" over each step's first heading (Inter eyebrows, as
        // the site sets them over its own headings).
        $stepKeys = array_keys( $steps );
        $eyebrow  = static function ( string $key ) use ( $stepKeys ): string {
            $i = array_search( $key, $stepKeys, true );
            return $i === false ? '' : sprintf( '<span class="njilga-join__eyebrow">Step %d of %d</span>', $i + 1, count( $stepKeys ) );
        };

        // Existing firm name for a re-rendered form.
        $firmName = (string) ( $old['firm_name'] ?? '' );
        if ( ! $isStudent && (int) ( $old['company_id'] ?? 0 ) > 0 && MyNJILGA_Members_Data::companies_module_active() ) {
            $c        = \FluentCrm\App\Models\Company::find( (int) $old['company_id'] );
            $firmName = $c ? (string) $c->name : $firmName;
        }

        ob_start();
        echo self::styles(); // phpcs:ignore
        ?>
        <div class="njilga-join" id="<?php echo esc_attr( $uid ); ?>-wrap">
            <?php if ( $a['test_mode'] ) : ?>
                <div class="njilga-join__notice njilga-join__notice--warning"><strong>Test mode</strong> (?njilga_test=1, staff only) — pay with a Stripe test card such as 4242 4242 4242 4242. No real money moves, and every email this join sends comes to you rather than to the colleagues you list — but the FluentCRM contacts, tags and firm changes are real.</div>
            <?php endif; ?>

            <div class="njilga-join__plan">
                <div>
                    <span class="njilga-join__eyebrow">NJILGA Membership</span>
                    <div class="njilga-join__plan-name"><?php echo esc_html( (string) $category['label'] ); ?></div>
                    <div class="njilga-join__muted"><?php echo esc_html( self::covers( (int) $a['year'], (int) $a['current_year'] ) ); ?></div>
                </div>
                <div class="njilga-join__plan-price"><?php echo esc_html( self::dollars( $price ) ); ?><span>/year</span></div>
            </div>

            <ol class="njilga-join__steps" aria-label="Steps" hidden>
                <?php $n = 0; foreach ( $steps as $key => $label ) : $n++; ?>
                    <li data-step-dot="<?php echo esc_attr( $key ); ?>"><span><?php echo (int) $n; ?></span> <?php echo esc_html( $label ); ?></li>
                <?php endforeach; ?>
            </ol>

            <form class="njilga-join__form" id="<?php echo esc_attr( $uid ); ?>" method="post" action="<?php echo esc_url( (string) $a['action_url'] ); ?>" enctype="multipart/form-data">
                <?php self::hidden( 'submit' ); ?>
                <input type="hidden" name="category" value="<?php echo esc_attr( (string) $category['key'] ); ?>">
                <input type="hidden" name="form" value="<?php echo esc_attr( (string) $a['form_att'] ); ?>">
                <input type="hidden" name="category_sig" value="<?php echo esc_attr( MyNJILGA_Join_Form::category_sig( (string) $category['key'], (string) $a['form_att'] ) ); ?>">
                <div class="njilga-join__hp" aria-hidden="true"><label>Leave this empty <input type="text" name="njilga_website" tabindex="-1" autocomplete="off"></label></div>
                <?php self::general_error( $a ); ?>

                <?php if ( $isStudent ) : ?>
                    <fieldset class="njilga-join__step njilga-join__card" data-step="enrollment">
                        <legend class="njilga-join__title"><?php echo $eyebrow( 'enrollment' ); // phpcs:ignore ?>Are you currently enrolled in law school?</legend>
                        <div class="njilga-join__choices">
                            <?php foreach ( [ 'enrolled' => 'I am actively enrolled as a law student', 'undergrad' => 'I am an undergraduate student with aspirations to get into law school' ] as $val => $label ) : ?>
                                <label class="njilga-join__choice"><input type="radio" name="student_status" value="<?php echo esc_attr( $val ); ?>" required<?php checked( (string) ( $old['student_status'] ?? '' ), $val ); ?>> <span><?php echo esc_html( $label ); ?></span></label>
                            <?php endforeach; ?>
                        </div>
                        <?php self::error( $errors, 'student_status', $uid ); ?>
                    </fieldset>
                <?php else : ?>
                    <fieldset class="njilga-join__step njilga-join__card" data-step="firm">
                        <legend class="njilga-join__title"><?php echo $eyebrow( 'firm' ); // phpcs:ignore ?>Which firm do you represent?</legend>
                        <p class="njilga-join__muted">Start typing your firm's name and pick it from the list. Not there? Keep typing and choose <em>Add your firm</em>.</p>
                        <div class="njilga-join__field njilga-join__firm">
                            <label class="njilga-join__label" for="<?php echo esc_attr( $uid ); ?>-firm_name">Firm / organization <span class="njilga-join__req">*</span></label>
                            <input type="text" id="<?php echo esc_attr( $uid ); ?>-firm_name" name="firm_name" required maxlength="190" autocomplete="organization" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="<?php echo esc_attr( $uid ); ?>-suggest" placeholder="Start typing your firm's name…" value="<?php echo esc_attr( $firmName ); ?>"<?php self::invalid( $errors, 'firm_name', $uid ); ?>>
                            <input type="hidden" name="company_id" value="<?php echo (int) ( $old['company_id'] ?? 0 ); ?>">
                            <ul class="njilga-join__suggest" id="<?php echo esc_attr( $uid ); ?>-suggest" role="listbox"></ul>
                            <p class="njilga-join__hint" data-firm-hint aria-live="polite"><?php echo (int) ( $old['company_id'] ?? 0 ) > 0 ? esc_html( 'Existing firm selected: ' . $firmName ) : ''; ?></p>
                            <?php self::error( $errors, 'firm_name', $uid ); ?>
                        </div>
                    </fieldset>
                <?php endif; ?>

                <div class="njilga-join__step" data-step="details">
                    <?php self::personal_card( $uid, $v, $errors, $a, $user, null, $eyebrow( 'details' ) ); ?>
                    <div class="njilga-join__card">
                        <h3 class="njilga-join__title">Contact Details</h3>
                        <?php
                        if ( $isStudent ) {
                            self::address_fields( $uid, $v, $errors, $old );
                        } else {
                            self::nj_address_fields( $uid, $v, $errors );
                        }
                        ?>
                    </div>
                    <?php if ( $isStudent ) : ?>
                        <div class="njilga-join__card">
                            <h3 class="njilga-join__title">Student Details</h3>
                            <div class="njilga-join__field">
                                <label class="njilga-join__label" for="<?php echo esc_attr( $uid ); ?>-school"><span data-school-label>School</span> <span class="njilga-join__req">*</span></label>
                                <input type="text" id="<?php echo esc_attr( $uid ); ?>-school" name="school" required maxlength="190" value="<?php echo $v( 'school' ); // phpcs:ignore ?>"<?php self::invalid( $errors, 'school', $uid ); ?>>
                                <?php self::error( $errors, 'school', $uid ); ?>
                            </div>
                            <?php [ $maxBytes, $maxLabel ] = self::upload_limit(); ?>
                            <div class="njilga-join__field" data-upload>
                                <label class="njilga-join__label" for="<?php echo esc_attr( $uid ); ?>-doc">Student ID or transcript <span class="njilga-join__req">*</span></label>
                                <input type="file" id="<?php echo esc_attr( $uid ); ?>-doc" name="student_document" accept=".pdf,.jpg,.jpeg,.png,.webp,.heic,application/pdf,image/*" data-max-bytes="<?php echo (int) $maxBytes; ?>" data-max-label="<?php echo esc_attr( $maxLabel ); ?>"<?php self::invalid( $errors, 'student_document', $uid ); ?>>
                                <p class="njilga-join__hint">Enrolled law students only. A photo of your student ID or a PDF transcript, up to <?php echo esc_html( $maxLabel ); ?>. Only NJILGA staff can see it.</p>
                                <?php self::error( $errors, 'student_document', $uid ); ?>
                            </div>
                        </div>
                    <?php else : ?>
                        <?php self::professional_card( $uid, $v, $errors ); ?>
                    <?php endif; ?>
                </div>

                <?php if ( ! $isStudent && $a['colleagues'] ) : ?>
                    <fieldset class="njilga-join__step njilga-join__card" data-step="colleagues">
                        <legend class="njilga-join__title"><?php echo $eyebrow( 'colleagues' ); // phpcs:ignore ?>Bring your firm along</legend>
                        <p><?php echo esc_html( self::ladder_sentence( $ladder ) ); ?> Pay for your colleagues now and we'll email each of them an invitation to set up their own account — their membership starts the moment yours does.</p>
                        <div class="njilga-join__choices">
                            <label class="njilga-join__choice"><input type="radio" name="add_colleagues" value="yes"<?php checked( (string) ( $old['add_colleagues'] ?? '' ), 'yes' ); ?>> <span>Yes — add colleagues from my firm and pay for them</span></label>
                            <label class="njilga-join__choice"><input type="radio" name="add_colleagues" value="no"<?php checked( (string) ( $old['add_colleagues'] ?? 'no' ), 'no' ); ?>> <span>No thanks, just me</span></label>
                        </div>
                        <?php self::error( $errors, 'colleagues', $uid ); ?>
                        <div class="njilga-join__colleagues" data-colleagues>
                            <?php
                            $rows = (array) ( $old['colleagues'] ?? [] );
                            // Without JavaScript there's no "add" button, so offer a few blank rows.
                            $blank = max( 0, 3 - count( $rows ) );
                            foreach ( $rows as $i => $r ) {
                                self::colleague_row( $uid, (int) $i, $r, $errors );
                            }
                            for ( $j = 0; $j < $blank; $j++ ) {
                                self::colleague_row( $uid, count( $rows ) + $j, [], $errors );
                            }
                            ?>
                        </div>
                        <button type="button" class="njilga-join__btn njilga-join__btn--ghost" data-add-colleague hidden>+ Add another colleague</button>
                        <p class="njilga-join__hint">Up to <?php echo (int) $a['max_colleagues']; ?> colleagues online. Each needs a first name, last name and their own email address.</p>
                    </fieldset>
                <?php endif; ?>

                <fieldset class="njilga-join__step njilga-join__card" data-step="review">
                    <legend class="njilga-join__title"><?php echo $eyebrow( 'review' ); // phpcs:ignore ?><?php echo esc_html( $reviewLbl ); ?></legend>
                    <table class="njilga-join__summary" data-summary>
                        <tbody>
                            <tr><td><?php echo esc_html( (string) $category['label'] ); ?> — you</td><td class="njilga-join__num"><?php echo esc_html( self::dollars( $price ) ); ?></td></tr>
                        </tbody>
                        <tfoot><tr><th>Total</th><th class="njilga-join__num" data-total><?php echo esc_html( self::dollars( $price ) ); ?></th></tr></tfoot>
                    </table>
                    <?php if ( ! $free ) : ?>
                        <noscript><p class="njilga-join__hint"><?php echo esc_html( $a['colleagues'] ? self::ladder_sentence( $ladder ) . ' Your total, including any colleagues, is shown on the payment page before you pay.' : 'Your total is shown on the payment page before you pay.' ); ?></p></noscript>
                    <?php endif; ?>
                    <p class="njilga-join__muted" data-pay-note<?php echo $free ? ' hidden' : ''; ?>>You'll pay on Stripe's secure page<?php echo MyNJILGA_Dues_Settings::general( 'join_allow_ach', true ) ? ' by card or US bank account' : ' by card'; ?>. Your membership — and any colleagues' — starts as soon as the payment clears.</p>
                    <p class="njilga-join__muted" data-review-note<?php echo $free ? '' : ' hidden'; ?>>Your membership comes to no charge, so there's nothing to pay. NJILGA staff will review your application and email you once your membership is confirmed.</p>
                    <div class="njilga-join__actions"><button type="submit" class="njilga-join__btn njilga-join__btn--primary" data-submit><?php echo esc_html( $free ? 'Submit for approval' : 'Continue to secure payment' ); ?></button></div>
                </fieldset>
            </form>

            <template id="<?php echo esc_attr( $uid ); ?>-colleague"><?php self::colleague_row( $uid, -1, [], [] ); ?></template>
        </div>
        <?php
        self::form_script( $uid, $a, $isStudent );
        return (string) ob_get_clean();
    }

    // -------------------------------------------------------------------------
    // Field helpers
    // -------------------------------------------------------------------------

    private static function hidden( string $action ): void {
        printf( '<input type="hidden" name="%s" value="%s">', esc_attr( MyNJILGA_Join_Form::ACTION_FIELD ), esc_attr( $action ) );
        // Not wp_nonce_field(): it gives every nonce input the same id, and
        // one page can carry several of these forms (invite + clear-invite,
        // resume + start over).
        printf( '<input type="hidden" name="%s" value="%s">', esc_attr( MyNJILGA_Join_Form::NONCE_FIELD ), esc_attr( wp_create_nonce( MyNJILGA_Join_Form::NONCE_ACTION . '_' . $action ) ) );
    }

    /**
     * The student upload limit the server really enforces — PHP's own is
     * often below MAX_BYTES (2 MB out of the box), and promising 8 MB
     * there means a refused file and a re-typed form.
     *
     * @return array{0:int,1:string} [bytes, "8 MB"]
     */
    private static function upload_limit(): array {
        return [ MyNJILGA_Join_Documents::max_bytes(), MyNJILGA_Join_Documents::max_label() ];
    }

    private static function action_button( string $action, string $label, string $variant ): string {
        ob_start();
        printf( '<form method="post" action="%s" class="njilga-join__inline">', esc_url( MyNJILGA_Join_Form::page_url() ) );
        self::hidden( $action );
        printf( '<button type="submit" class="njilga-join__btn njilga-join__btn--%s">%s</button></form>', esc_attr( $variant ), esc_html( $label ) );
        return (string) ob_get_clean();
    }

    /**
     * @param array<string,mixed> $state
     */
    private static function general_error( array $state ): void {
        $errors = (array) ( $state['errors'] ?? [] );
        $error  = (string) ( $state['error'] ?? '' );
        if ( $error === '' && ! $errors ) {
            return;
        }
        echo '<div class="njilga-join__notice njilga-join__notice--error" role="alert" tabindex="-1" data-errors>';
        echo esc_html( $error !== '' ? $error : 'Please check the highlighted fields.' );
        if ( $errors ) {
            echo '<ul>';
            foreach ( $errors as $msg ) {
                echo '<li>' . esc_html( (string) $msg ) . '</li>';
            }
            echo '</ul>';
        }
        echo '</div>';
    }

    /**
     * @param array<string,string> $errors
     * @param array<string,mixed>  $o
     */
    private static function text_field( string $uid, string $name, string $label, string $escapedValue, array $errors, array $o = [] ): void {
        $id    = $uid . '-' . $name;
        $attrs = '';
        foreach ( [ 'autocomplete', 'maxlength', 'max', 'pattern', 'inputmode', 'placeholder' ] as $k ) {
            if ( isset( $o[ $k ] ) ) {
                $attrs .= sprintf( ' %s="%s"', $k, esc_attr( (string) $o[ $k ] ) );
            }
        }
        if ( ! empty( $o['required'] ) ) {
            $attrs .= ' required';
        }
        printf(
            '<div class="njilga-join__field"><label class="njilga-join__label" for="%1$s">%2$s%3$s</label><input type="%4$s" id="%1$s" name="%5$s" value="%6$s"%7$s',
            esc_attr( $id ),
            esc_html( $label ),
            ! empty( $o['required'] ) ? ' <span class="njilga-join__req">*</span>' : '',
            esc_attr( (string) ( $o['type'] ?? 'text' ) ),
            esc_attr( $name ),
            $escapedValue, // Already esc_attr()'d by the caller.
            $attrs // phpcs:ignore
        );
        self::invalid( $errors, $name, $uid );
        echo '>';
        if ( isset( $o['hint'] ) || isset( $o['hint_attr'] ) ) {
            printf( '<p class="njilga-join__hint"%s aria-live="polite">%s</p>', isset( $o['hint_attr'] ) ? ' ' . esc_attr( (string) $o['hint_attr'] ) : '', esc_html( (string) ( $o['hint'] ?? '' ) ) );
        }
        self::error( $errors, $name, $uid );
        echo '</div>';
    }

    /**
     * @param array<string,string> $errors
     */
    private static function password_field( string $uid, string $name, string $label, array $errors, bool $withToggle ): void {
        $id = $uid . '-' . $name;
        echo '<div class="njilga-join__field">';
        printf( '<div class="njilga-join__labelrow"><label class="njilga-join__label" for="%s">%s <span class="njilga-join__req">*</span></label>', esc_attr( $id ), esc_html( $label ) );
        if ( $withToggle ) {
            echo '<button type="button" class="njilga-join__toggle" data-show-password hidden>Show password</button>';
        }
        echo '</div>';
        printf( '<input type="password" id="%s" name="%s" required minlength="8" autocomplete="new-password"', esc_attr( $id ), esc_attr( $name ) );
        self::invalid( $errors, $name, $uid );
        echo '>';
        self::error( $errors, $name, $uid );
        echo '</div>';
    }

    /**
     * Personal Details: Prefix, first and last name, phone, then — for a
     * new account — email (with its verification code), username and
     * password. A signed-in joiner already has the account, so only
     * their name and phone are asked; an invited colleague's email is
     * fixed by the invitation.
     *
     * @param array<string,mixed>  $a       join_form()'s args (needs action_url, needs_code).
     * @param WP_User|null         $user
     * @param object|null          $invite
     * @param array<string,string> $errors
     * @param string               $eyebrowHtml Trusted markup for the "Step N of M" eyebrow.
     */
    private static function personal_card( string $uid, callable $v, array $errors, array $a, $user, $invite, string $eyebrowHtml = '' ): void {
        $firstDefault = $invite ? (string) $invite->first_name : ( $user ? (string) $user->first_name : '' );
        $lastDefault  = $invite ? (string) $invite->last_name : ( $user ? (string) $user->last_name : '' );
        $newAccount   = ! $user;
        ?>
        <div class="njilga-join__card">
            <h3 class="njilga-join__title"><?php echo $eyebrowHtml; // phpcs:ignore -- built from fixed words ?>Personal Details</h3>
            <?php if ( $user ) : ?>
                <p class="njilga-join__muted"><?php echo esc_html( sprintf( 'Signed in as %s (%s).', $user->user_login, $user->user_email ) ); ?> <a href="<?php echo esc_url( wp_logout_url( (string) $a['action_url'] ) ); ?>">Not you?</a></p>
            <?php endif; ?>
            <div class="njilga-join__grid njilga-join__grid--name">
                <?php self::prefix_field( $uid, $v( 'prefix' ), $errors ); ?>
                <?php self::text_field( $uid, 'first_name', 'First name', $v( 'first_name', $firstDefault ), $errors, [ 'required' => true, 'autocomplete' => 'given-name' ] ); ?>
                <?php self::text_field( $uid, 'last_name', 'Last name', $v( 'last_name', $lastDefault ), $errors, [ 'required' => true, 'autocomplete' => 'family-name' ] ); ?>
            </div>
            <?php self::text_field( $uid, 'phone', 'Phone', $v( 'phone' ), $errors, [ 'required' => true, 'type' => 'tel', 'autocomplete' => 'tel', 'inputmode' => 'tel', 'placeholder' => MyNJILGA_Phone::US_PLACEHOLDER, 'maxlength' => 30 ] ); ?>
            <?php if ( $invite ) : ?>
                <div class="njilga-join__field"><span class="njilga-join__label">Email</span><div class="njilga-join__static"><?php echo esc_html( (string) $invite->email ); ?></div></div>
            <?php elseif ( $newAccount ) : ?>
                <?php self::text_field( $uid, 'email', 'Email address', $v( 'email' ), $errors, [ 'required' => true, 'type' => 'email', 'autocomplete' => 'email', 'hint_attr' => 'data-email-hint' ] ); ?>
                <div class="njilga-join__field njilga-join__code" data-code-field<?php echo empty( $a['needs_code'] ) ? ' data-code-later' : ''; ?>>
                    <label class="njilga-join__label" for="<?php echo esc_attr( $uid ); ?>-email_code">Email verification code</label>
                    <input type="text" id="<?php echo esc_attr( $uid ); ?>-email_code" name="email_code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="\d{6}" value="<?php echo ! empty( $a['needs_code'] ) ? $v( 'email_code' ) : ''; // phpcs:ignore ?>"<?php self::invalid( $errors, 'email_code', $uid ); ?>>
                    <p class="njilga-join__hint" data-code-hint><?php echo empty( $a['needs_code'] ) ? 'We\'ll email you a 6-digit code to confirm your address. If you\'re filling this in without JavaScript, leave this empty the first time you submit.' : 'Enter the 6-digit code from the email we just sent you.'; ?></p>
                    <?php self::error( $errors, 'email_code', $uid ); ?>
                    <button type="button" class="njilga-join__toggle" data-resend-code hidden>Send a new code</button>
                </div>
            <?php endif; ?>
            <?php if ( $newAccount ) : ?>
                <?php
                // Not natively required: left blank (no JavaScript to fill
                // it), the server gives it the same default.
                self::text_field( $uid, 'username', 'Username', $v( 'username' ), $errors, [ 'autocomplete' => 'username', 'maxlength' => 60, 'hint' => 'Your first initial and last name, unless you choose another. Letters and numbers only.', 'hint_attr' => 'data-username-hint' ] );
                ?>
                <div class="njilga-join__grid">
                    <?php self::password_field( $uid, 'password', 'Set password', $errors, true ); ?>
                    <?php self::password_field( $uid, 'password_confirm', 'Confirm password', $errors, false ); ?>
                </div>
                <?php if ( ! $invite ) : ?>
                    <div class="njilga-join__foot">Already have an account? <a href="<?php echo esc_url( wp_login_url( (string) $a['action_url'] ) ); ?>">Log in here</a></div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * @param array<string,string> $errors
     */
    private static function professional_card( string $uid, callable $v, array $errors ): void {
        ?>
        <div class="njilga-join__card">
            <h3 class="njilga-join__title">Professional Details</h3>
            <?php self::text_field( $uid, 'attorney_id', 'NJ Attorney ID number', $v( 'attorney_id' ), $errors, [ 'required' => true, 'maxlength' => 20 ] ); ?>
            <?php self::text_field( $uid, 'bar_admission_date', 'Date of admission to the New Jersey Bar', $v( 'bar_admission_date' ), $errors, [ 'required' => true, 'type' => 'date', 'max' => gmdate( 'Y-m-d' ) ] ); ?>
            <?php self::county_field( $uid, $v( 'nj_county' ), $errors ); ?>
            <?php self::municipality_field( $uid, $v( 'municipality' ), $errors ); ?>
        </div>
        <?php
    }

    /**
     * Prefix: FluentCRM's own list (MyNJILGA_Join_Form::prefix_options()).
     *
     * @param array<string,string> $errors
     */
    private static function prefix_field( string $uid, string $escapedValue, array $errors ): void {
        self::choice_field( $uid, 'prefix', 'Prefix', MyNJILGA_Join_Form::prefix_options(), $escapedValue, $errors, '—' );
    }

    /**
     * Contact Details for the Professional forms and invited colleagues:
     * a New Jersey address only, so State is New Jersey and the ZIP must
     * be one (07xxx/08xxx). The student form keeps address_fields(), which
     * takes any US state or an address abroad.
     *
     * @param array<string,string> $errors
     */
    private static function nj_address_fields( string $uid, callable $v, array $errors ): void {
        self::text_field( $uid, 'address_1', 'Address 1', $v( 'address_1' ), $errors, [ 'required' => true, 'autocomplete' => 'address-line1' ] );
        self::text_field( $uid, 'address_2', 'Address 2', $v( 'address_2' ), $errors, [ 'autocomplete' => 'address-line2' ] );
        self::text_field( $uid, 'city', 'City', $v( 'city' ), $errors, [ 'required' => true, 'autocomplete' => 'address-level2' ] );
        echo '<div class="njilga-join__grid">';
        $id = $uid . '-state';
        printf( '<div class="njilga-join__field"><label class="njilga-join__label" for="%1$s">State <span class="njilga-join__req">*</span></label><select id="%1$s" name="state" required autocomplete="address-level1"', esc_attr( $id ) );
        self::invalid( $errors, 'state', $uid );
        echo '><option value="NJ" selected>New Jersey</option></select>';
        echo '<p class="njilga-join__hint">Online membership is for New Jersey addresses.</p>';
        self::error( $errors, 'state', $uid );
        echo '</div>';
        self::text_field( $uid, 'postal_code', 'ZIP code', $v( 'postal_code' ), $errors, [ 'required' => true, 'autocomplete' => 'postal-code', 'inputmode' => 'numeric', 'maxlength' => 10, 'pattern' => '0[78]\d{3}(-\d{4})?' ] );
        echo '</div>';
    }

    /**
     * Municipality: FluentCRM's own choices for the field it's written to
     * (MyNJILGA_Dues_Settings::municipality_options()), or free text when
     * there are none.
     *
     * @param array<string,string> $errors
     */
    private static function municipality_field( string $uid, string $escapedValue, array $errors ): void {
        $options = MyNJILGA_Dues_Settings::municipality_options();
        if ( ! $options ) {
            self::text_field( $uid, 'municipality', 'Municipality', $escapedValue, $errors, [ 'maxlength' => 190 ] );
            return;
        }
        self::choice_field( $uid, 'municipality', 'Municipality', $options, $escapedValue, $errors );
    }

    /**
     * NJ County: FluentCRM's own choices for the field it's written to
     * ("nj_county"). Left out when that field has none — a typed county
     * wouldn't match what FluentCRM filters on.
     *
     * @param array<string,string> $errors
     */
    private static function county_field( string $uid, string $escapedValue, array $errors ): void {
        $options = MyNJILGA_Dues_Settings::county_options();
        if ( $options ) {
            self::choice_field( $uid, 'nj_county', 'NJ County', $options, $escapedValue, $errors );
        }
    }

    /**
     * @param array<int,string>    $options
     * @param array<string,string> $errors
     */
    private static function choice_field( string $uid, string $name, string $label, array $options, string $escapedValue, array $errors, string $emptyLabel = '- Select -' ): void {
        $id = $uid . '-' . $name;
        printf( '<div class="njilga-join__field"><label class="njilga-join__label" for="%1$s">%2$s</label><select id="%1$s" name="%3$s"', esc_attr( $id ), esc_html( $label ), esc_attr( $name ) );
        self::invalid( $errors, $name, $uid );
        printf( '><option value="">%s</option>', esc_html( $emptyLabel ) );
        foreach ( $options as $opt ) {
            printf( '<option value="%s"%s>%s</option>', esc_attr( $opt ), selected( $escapedValue, esc_attr( $opt ), false ), esc_html( $opt ) );
        }
        echo '</select>';
        self::error( $errors, $name, $uid );
        echo '</div>';
    }

    /**
     * @param callable             $v
     * @param array<string,string> $errors
     * @param array<string,mixed>  $old
     */
    private static function address_fields( string $uid, callable $v, array $errors, array $old ): void {
        $outside = ! empty( $old['outside_us'] );
        self::text_field( $uid, 'address_1', 'Address 1', $v( 'address_1' ), $errors, [ 'required' => true, 'autocomplete' => 'address-line1' ] );
        self::text_field( $uid, 'address_2', 'Address 2', $v( 'address_2' ), $errors, [ 'autocomplete' => 'address-line2' ] );
        printf( '<label class="njilga-join__check"><input type="checkbox" name="outside_us" value="1" data-outside-us%s> <span>My mailing address is outside the United States</span></label>', checked( $outside, true, false ) );
        self::text_field( $uid, 'city', 'City', $v( 'city' ), $errors, [ 'required' => true, 'autocomplete' => 'address-level2' ] );

        // State for a US address, region and Country for any other, and
        // one postcode field for both. As served, no field here is hidden
        // and the browser is told none of them is required: which set
        // applies turns on the checkbox, and without JavaScript only the
        // server reads that (validate_address()). A natively required
        // State or ZIP pattern made an overseas applicant invent a US
        // address, and a hidden Country left them no way to give theirs —
        // so each label says which addresses it's for instead. With
        // JavaScript, address_script() shows one set, requires what that
        // set needs, and DISABLES the other set's controls: a disabled
        // control is neither validated nor posted, so nothing hidden can
        // block the submit or be saved.
        echo '<div class="njilga-join__grid">';
        $id = $uid . '-state';
        printf( '<div class="njilga-join__field" data-us-only><label class="njilga-join__label" for="%1$s">State%2$s</label><select id="%1$s" name="state" autocomplete="address-level1"', esc_attr( $id ), self::address_label_tail( 'US addresses', 'us' ) ); // phpcs:ignore
        self::invalid( $errors, 'state', $uid );
        echo '><option value="">Select state</option>';
        foreach ( self::us_states() as $code => $name ) {
            printf( '<option value="%s"%s>%s</option>', esc_attr( $code ), selected( (string) ( $old['state'] ?? '' ), $code, false ), esc_html( $name ) );
        }
        echo '</select>';
        self::error( $errors, 'state', $uid );
        echo '</div>';
        self::address_input( $uid, 'region', 'State / province / region' . self::address_label_tail( 'outside the US', '' ), $v( 'region' ), $errors, 'data-intl-only', [ 'maxlength' => '80', 'autocomplete' => 'address-level1' ] );
        // The ZIP pattern waits in data-us-pattern: the script applies it
        // only while the address is in the US.
        self::address_input( $uid, 'postal_code', '<span data-postal-label>ZIP / postal code</span>' . self::address_label_tail( '', 'us' ), $v( 'postal_code' ), $errors, '', [ 'maxlength' => '20', 'autocomplete' => 'postal-code', 'data-us-pattern' => '\d{5}(-\d{4})?' ] );
        self::address_input( $uid, 'country', 'Country' . self::address_label_tail( 'outside the US', 'intl' ), $v( 'country' ), $errors, 'data-intl-only', [ 'maxlength' => '80', 'autocomplete' => 'country-name' ] );
        echo '</div>';
    }

    /**
     * The end of an address label: which addresses the field is for —
     * shown only without JavaScript, when every field is on screen — and
     * the asterisk the script shows while the field is required.
     *
     * @param string $for  e.g. "US addresses"; '' for none.
     * @param string $when 'us' or 'intl': when the field is required; '' never.
     */
    private static function address_label_tail( string $for, string $when ): string {
        $html = $for !== '' ? sprintf( '<span data-address-note> (%s)</span>', esc_html( $for ) ) : '';
        if ( $when !== '' ) {
            $html .= sprintf( ' <span class="njilga-join__req" data-required-when="%s" hidden>*</span>', esc_attr( $when ) );
        }
        return $html;
    }

    /**
     * One text input of address_fields(). $labelHtml is trusted (built
     * there from fixed words); attribute values are escaped here.
     *
     * @param array<string,string> $errors
     * @param array<string,string> $attrs
     */
    private static function address_input( string $uid, string $name, string $labelHtml, string $escapedValue, array $errors, string $group, array $attrs ): void {
        $id    = $uid . '-' . $name;
        $extra = '';
        foreach ( $attrs as $k => $val ) {
            $extra .= sprintf( ' %s="%s"', esc_attr( $k ), esc_attr( $val ) );
        }
        printf(
            '<div class="njilga-join__field"%1$s><label class="njilga-join__label" for="%2$s">%3$s</label><input type="text" id="%2$s" name="%4$s" value="%5$s"%6$s',
            $group !== '' ? ' ' . esc_attr( $group ) : '',
            esc_attr( $id ),
            $labelHtml, // phpcs:ignore -- trusted, see above.
            esc_attr( $name ),
            $escapedValue, // Already esc_attr()'d by the caller.
            $extra // phpcs:ignore
        );
        self::invalid( $errors, $name, $uid );
        echo '>';
        self::error( $errors, $name, $uid );
        echo '</div>';
    }

    /**
     * @param array<string,string> $r
     * @param array<string,string> $errors
     */
    private static function colleague_row( string $uid, int $i, array $r, array $errors ): void {
        $err = $i >= 0 ? (string) ( $errors[ 'colleague_' . $i ] ?? '' ) : '';
        // Rows are cloned from a <template>, so no ids: each input sits
        // inside its own label, and the row is a group the script numbers
        // ("Colleague 2") as rows come and go.
        printf( '<div class="njilga-join__colleague%s" data-colleague-row role="group" aria-label="%s">', $err !== '' ? ' is-invalid' : '', esc_attr( $i >= 0 ? 'Colleague ' . ( $i + 1 ) : 'Colleague' ) );
        printf( '<label class="njilga-join__field"><span class="njilga-join__label">First name</span><input type="text" name="colleague_first[]" autocomplete="off" maxlength="100" value="%s"></label>', esc_attr( (string) ( $r['first_name'] ?? '' ) ) );
        printf( '<label class="njilga-join__field"><span class="njilga-join__label">Last name</span><input type="text" name="colleague_last[]" autocomplete="off" maxlength="100" value="%s"></label>', esc_attr( (string) ( $r['last_name'] ?? '' ) ) );
        printf( '<label class="njilga-join__field"><span class="njilga-join__label">Email</span><input type="email" name="colleague_email[]" autocomplete="off" maxlength="190" value="%s"></label>', esc_attr( (string) ( $r['raw_email'] ?? ( $r['email'] ?? '' ) ) ) );
        echo '<button type="button" class="njilga-join__remove" data-remove-colleague aria-label="Remove this colleague" hidden>&times;</button>';
        if ( $err !== '' ) {
            echo '<p class="njilga-join__err">' . esc_html( $err ) . '</p>';
        }
        echo '</div>';
        unset( $uid );
    }

    /**
     * @param array<string,string> $errors
     */
    private static function invalid( array $errors, string $name, string $uid ): void {
        if ( isset( $errors[ $name ] ) ) {
            printf( ' aria-invalid="true" aria-describedby="%s"', esc_attr( $uid . '-' . $name . '-err' ) );
        }
    }

    /**
     * @param array<string,string> $errors
     */
    private static function error( array $errors, string $name, string $uid ): void {
        if ( isset( $errors[ $name ] ) ) {
            printf( '<p class="njilga-join__err" id="%s">%s</p>', esc_attr( $uid . '-' . $name . '-err' ), esc_html( (string) $errors[ $name ] ) );
        }
    }

    private static function card( string $title, string $bodyHtml, string $variant = '' ): void {
        echo self::card_html( $title, $bodyHtml, $variant ); // phpcs:ignore
    }

    /**
     * $bodyHtml is trusted — callers escape what they interpolate.
     */
    private static function card_html( string $title, string $bodyHtml, string $variant = '' ): string {
        return sprintf(
            '<div class="njilga-join__card%s"><h3 class="njilga-join__title">%s</h3>%s</div>',
            $variant !== '' ? ' njilga-join__card--' . esc_attr( $variant ) : '',
            esc_html( $title ),
            $bodyHtml
        );
    }

    /**
     * "2026 membership", or — for a join made after next year's invoices
     * went out — "Rest of 2026 and all of 2027".
     */
    public static function covers( int $year, int $currentYear ): string {
        return $year > $currentYear ? sprintf( 'Rest of %d and all of %d', $currentYear, $year ) : sprintf( '%d membership', $year );
    }

    public static function dollars( int $cents ): string {
        return $cents % 100 === 0 ? '$' . number_format( $cents / 100 ) : '$' . number_format( $cents / 100, 2 );
    }

    /**
     * "Members 2–5 from your firm join for $75 each; beyond 5, additional
     * colleagues join free." — from the category's own tiers, so it stays
     * true when Settings change.
     *
     * @param array<int,array<string,mixed>> $ladder
     */
    public static function ladder_sentence( array $ladder ): string {
        $parts = [];
        foreach ( $ladder as $t ) {
            if ( (int) $t['from'] <= 1 ) {
                continue;
            }
            $range = (int) $t['to'] === 0 ? sprintf( 'beyond %d', (int) $t['from'] - 1 ) : ( (int) $t['from'] === (int) $t['to'] ? sprintf( 'member %d', (int) $t['from'] ) : sprintf( 'members %d–%d', (int) $t['from'], (int) $t['to'] ) );
            $parts[] = (int) $t['price_cents'] > 0
                ? sprintf( '%s from your firm join for %s each', $range, self::dollars( (int) $t['price_cents'] ) )
                : sprintf( '%s, additional colleagues join free', $range );
        }
        if ( ! $parts ) {
            return '';
        }
        return ucfirst( implode( '; ', $parts ) ) . '.';
    }

    /**
     * @return array<string,string> USPS code => name
     */
    public static function us_states(): array {
        return [
            'AL' => 'Alabama', 'AK' => 'Alaska', 'AZ' => 'Arizona', 'AR' => 'Arkansas', 'CA' => 'California',
            'CO' => 'Colorado', 'CT' => 'Connecticut', 'DE' => 'Delaware', 'DC' => 'District of Columbia', 'FL' => 'Florida',
            'GA' => 'Georgia', 'HI' => 'Hawaii', 'ID' => 'Idaho', 'IL' => 'Illinois', 'IN' => 'Indiana',
            'IA' => 'Iowa', 'KS' => 'Kansas', 'KY' => 'Kentucky', 'LA' => 'Louisiana', 'ME' => 'Maine',
            'MD' => 'Maryland', 'MA' => 'Massachusetts', 'MI' => 'Michigan', 'MN' => 'Minnesota', 'MS' => 'Mississippi',
            'MO' => 'Missouri', 'MT' => 'Montana', 'NE' => 'Nebraska', 'NV' => 'Nevada', 'NH' => 'New Hampshire',
            'NJ' => 'New Jersey', 'NM' => 'New Mexico', 'NY' => 'New York', 'NC' => 'North Carolina', 'ND' => 'North Dakota',
            'OH' => 'Ohio', 'OK' => 'Oklahoma', 'OR' => 'Oregon', 'PA' => 'Pennsylvania', 'RI' => 'Rhode Island',
            'SC' => 'South Carolina', 'SD' => 'South Dakota', 'TN' => 'Tennessee', 'TX' => 'Texas', 'UT' => 'Utah',
            'VT' => 'Vermont', 'VA' => 'Virginia', 'WA' => 'Washington', 'WV' => 'West Virginia', 'WI' => 'Wisconsin',
            'WY' => 'Wyoming', 'PR' => 'Puerto Rico', 'GU' => 'Guam', 'VI' => 'U.S. Virgin Islands', 'AS' => 'American Samoa',
            'MP' => 'Northern Mariana Islands', 'AA' => 'Armed Forces Americas', 'AE' => 'Armed Forces Europe', 'AP' => 'Armed Forces Pacific',
        ];
    }

    // -------------------------------------------------------------------------
    // Styles
    // -------------------------------------------------------------------------

    private static function styles(): string {
        if ( self::$styled ) {
            return '';
        }
        self::$styled = true;
        MyNJILGA_Front_Style::enqueue_fonts();
        return '<style>' . MyNJILGA_Front_Style::tokens( '.njilga-join' ) . '
.njilga-join{max-width:920px;margin:0 auto;box-sizing:border-box;-webkit-font-smoothing:antialiased}
.njilga-join *,.njilga-join *::before,.njilga-join *::after{box-sizing:border-box}
.njilga-join [hidden]{display:none!important}
.njilga-join [tabindex="-1"]:focus{outline:none}
.njilga-join strong{font-weight:700;color:var(--nj-ink)}
.njilga-join p{margin:0 0 16px}
.njilga-join p:last-child{margin-bottom:0}
.njilga-join a{color:var(--nj-blue);text-decoration:underline;text-underline-offset:2px}
.njilga-join a:hover{color:var(--nj-blue-dark)}
.njilga-join__eyebrow{display:block;font-family:var(--nj-font-ui);font-size:12px;font-weight:600;letter-spacing:.14em;line-height:1.4;text-transform:uppercase;color:var(--nj-blue);margin:0 0 10px}
.njilga-join__plan{display:flex;justify-content:space-between;align-items:flex-end;gap:24px;background:var(--nj-navy);color:#fff;border-radius:var(--nj-radius);padding:30px 40px;margin:0 0 28px}
.njilga-join__plan .njilga-join__eyebrow{color:var(--nj-gold);margin-bottom:8px}
.njilga-join__plan-name{font-family:var(--nj-font-head);font-size:30px;font-weight:700;line-height:1.2;color:#fff}
.njilga-join__plan .njilga-join__muted{color:rgba(255,255,255,.78);font-size:15px;margin:8px 0 0}
.njilga-join__plan-price{font-family:var(--nj-font-head);font-size:44px;font-weight:700;line-height:1;white-space:nowrap;color:#fff}
.njilga-join__plan-price span{font-family:var(--nj-font-ui);font-size:14px;font-weight:500;margin-left:6px;opacity:.78}
.njilga-join__steps{display:flex;flex-wrap:wrap;gap:12px 32px;list-style:none;margin:0 0 28px;padding:0;font-family:var(--nj-font-ui);font-size:14px;font-weight:500;color:var(--nj-muted)}
.njilga-join__steps li{display:flex;align-items:center;gap:10px;margin:0}
.njilga-join__steps li span{display:inline-flex;width:28px;height:28px;border-radius:50%;align-items:center;justify-content:center;border:1px solid var(--nj-field);background:#fff;font-size:12px;font-weight:600;color:var(--nj-muted)}
.njilga-join__steps li.is-current{color:var(--nj-ink);font-weight:600}
.njilga-join__steps li.is-current span,.njilga-join__steps li.is-done span{background:var(--nj-navy);border-color:var(--nj-navy);color:#fff}
.njilga-join__steps li.is-done{color:var(--nj-text)}
.njilga-join__card{background:#fff;border:1px solid var(--nj-line);border-radius:var(--nj-radius);box-shadow:0 1px 2px rgba(16,24,40,.04),0 4px 16px rgba(16,24,40,.04);padding:36px 40px;margin:0 0 24px;min-width:0}
.njilga-join__card--success{border-top:4px solid var(--nj-success)}
.njilga-join__card--error{border-top:4px solid var(--nj-danger)}
.njilga-join fieldset.njilga-join__card{display:block}
.njilga-join__title{font-family:var(--nj-font-head);font-size:26px;font-weight:700;line-height:1.25;color:var(--nj-ink);margin:0 0 20px;padding:0;letter-spacing:0;text-transform:none}
.njilga-join legend.njilga-join__title{float:left;width:100%}
.njilga-join legend.njilga-join__title+*{clear:both}
.njilga-join__title+p,.njilga-join legend.njilga-join__title+p{margin-top:-8px;margin-bottom:24px;color:var(--nj-text)}
.njilga-join__optional{font-family:var(--nj-font-ui);font-size:13px;font-weight:500;color:var(--nj-muted)}
.njilga-join__field{margin:0 0 20px;min-width:0}
.njilga-join__label{display:block;font-family:var(--nj-font-ui);font-size:14px;font-weight:500;line-height:1.4;color:var(--nj-ink);margin:0 0 8px}
.njilga-join__labelrow{display:flex;justify-content:space-between;align-items:baseline;gap:8px}
.njilga-join__labelrow .njilga-join__label{margin-bottom:8px}
.njilga-join__req{color:var(--nj-danger);margin-left:2px}
.njilga-join input[type=text],.njilga-join input[type=email],.njilga-join input[type=tel],.njilga-join input[type=password],.njilga-join input[type=date],.njilga-join input[type=file],.njilga-join select{display:block;width:100%;min-height:48px;padding:11px 14px;border:1px solid var(--nj-field);border-radius:var(--nj-radius-sm);background:#fff;color:var(--nj-ink);font-family:var(--nj-font-body);font-size:16px;line-height:1.4;margin:0;box-shadow:none;transition:border-color .15s,box-shadow .15s}
.njilga-join input::placeholder{color:#9aa0a6;opacity:1}
.njilga-join input[type=file]{padding:10px;font-size:15px}
.njilga-join input:hover,.njilga-join select:hover{border-color:#9aa0a6}
.njilga-join input:focus,.njilga-join select:focus{outline:none;border-color:var(--nj-blue);box-shadow:0 0 0 3px rgba(31,84,147,.18)}
.njilga-join [aria-invalid=true],.njilga-join .is-invalid input{border-color:var(--nj-danger)}
.njilga-join__grid{display:grid;grid-template-columns:1fr 1fr;gap:0 20px}
.njilga-join__grid--name{grid-template-columns:minmax(96px,124px) 1fr 1fr}
.njilga-join__static{min-height:48px;padding:12px 14px;border-radius:var(--nj-radius-sm);background:var(--nj-soft);color:var(--nj-ink)}
.njilga-join__hint,.njilga-join__muted{color:var(--nj-muted);font-size:14px;line-height:1.5;margin:8px 0 0}
.njilga-join__hint.is-bad{color:var(--nj-danger)}
[data-add-colleague]+.njilga-join__hint{margin-top:16px}
.njilga-join__err{color:var(--nj-danger);font-size:14px;line-height:1.5;margin:8px 0 0}
.njilga-join__foot{margin:12px -40px -36px;padding:18px 40px;background:var(--nj-soft);border-top:1px solid var(--nj-line);border-radius:0 0 var(--nj-radius) var(--nj-radius);font-size:15px}
.njilga-join__notice{border:1px solid #c0d6f2;border-left:4px solid var(--nj-blue);background:var(--nj-blue-soft);color:var(--nj-ink);border-radius:var(--nj-radius-sm);padding:16px 20px;margin:0 0 24px;font-size:15px;line-height:1.55}
.njilga-join__notice ul{margin:8px 0 0 20px;padding:0}
.njilga-join__notice--error{border-color:#fecdca;border-left-color:var(--nj-danger);background:var(--nj-danger-bg);color:var(--nj-danger)}
.njilga-join__notice--success{border-color:#abefc6;border-left-color:var(--nj-success);background:var(--nj-success-bg);color:var(--nj-success)}
.njilga-join__notice--warning{border-color:#fedf89;border-left-color:var(--nj-gold);background:var(--nj-warn-bg);color:var(--nj-warn)}
.njilga-join__choices{display:grid;gap:12px;margin:0 0 12px}
.njilga-join__choice{display:flex;align-items:center;gap:14px;margin:0;padding:16px 20px;border:1px solid var(--nj-field);border-radius:var(--nj-radius-sm);background:#fff;color:var(--nj-ink);font-family:var(--nj-font-body);font-size:16px;line-height:1.45;cursor:pointer;transition:border-color .15s,background-color .15s,box-shadow .15s}
.njilga-join__choice:hover{border-color:#9aa0a6}
.njilga-join__choice:has(input:checked){border-color:var(--nj-navy);background:var(--nj-blue-soft);box-shadow:inset 0 0 0 1px var(--nj-navy)}
.njilga-join__choice input,.njilga-join__check input{flex:0 0 auto;width:18px;height:18px;margin:0;accent-color:var(--nj-navy)}
.njilga-join__choice span{color:inherit}
.njilga-join__check{display:flex;gap:12px;align-items:center;margin:0 0 20px;color:var(--nj-text);font-size:15px;cursor:pointer}
.njilga-join__firm{position:relative}
.njilga-join__suggest{position:absolute;left:0;right:0;top:82px;z-index:50;background:#fff;border:1px solid var(--nj-field);border-radius:var(--nj-radius-sm);list-style:none;margin:0;padding:6px 0;max-height:280px;overflow:auto;box-shadow:0 12px 32px rgba(16,24,40,.14);display:none}
.njilga-join__suggest li{padding:11px 16px;cursor:pointer;margin:0;color:var(--nj-ink)}
.njilga-join__suggest li:hover,.njilga-join__suggest li[aria-selected=true]{background:var(--nj-soft)}
.njilga-join__suggest li.is-new{color:var(--nj-blue);font-weight:600;border-top:1px solid var(--nj-line)}
.njilga-join__colleagues{margin:8px 0 0}
.njilga-join__colleague{display:grid;grid-template-columns:1fr 1fr 1.4fr 40px;gap:0 16px;align-items:end;padding:20px 0 4px;border-top:1px solid var(--nj-line)}
.njilga-join__colleague .njilga-join__err{grid-column:1/-1;margin:0 0 12px}
.njilga-join__remove{width:40px;height:48px;margin:0 0 20px;border:1px solid transparent;border-radius:var(--nj-radius-sm);background:none;color:var(--nj-muted);font-size:24px;line-height:1;cursor:pointer}
.njilga-join__remove:hover{border-color:var(--nj-line);color:var(--nj-danger)}
.njilga-join__summary{width:100%;border-collapse:collapse;margin:0 0 20px;font-size:16px}
.njilga-join__summary td,.njilga-join__summary th{padding:14px 0;border-bottom:1px solid var(--nj-line);text-align:left;font-weight:400;vertical-align:top}
.njilga-join__summary tfoot th{font-family:var(--nj-font-ui);font-weight:700;font-size:17px;color:var(--nj-ink);border-bottom:0;padding-top:18px}
.njilga-join__summary .njilga-join__muted{display:block;margin:2px 0 0}
.njilga-join__num{text-align:right!important;white-space:nowrap;padding-left:16px!important;color:var(--nj-ink)}
.njilga-join__actions{display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between;margin:28px 0 0;padding:24px 0 0;border-top:1px solid var(--nj-line)}
.njilga-join__actions:only-child,.njilga-join__card>.njilga-join__actions:first-child{border-top:0;padding-top:0;margin-top:0}
.njilga-join__inline{display:inline;margin:0}
.njilga-join__btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:48px;padding:12px 28px;border-radius:var(--nj-radius-sm);border:1px solid var(--nj-navy);font-family:var(--nj-font-ui);font-size:15px;font-weight:600;letter-spacing:.01em;line-height:1.2;cursor:pointer;text-decoration:none!important;transition:background-color .15s,border-color .15s,color .15s}
.njilga-join__btn--primary{background:var(--nj-navy);color:#fff!important}
.njilga-join__btn--primary:hover{background:var(--nj-blue);border-color:var(--nj-blue);color:#fff}
.njilga-join__btn--ghost{background:#fff;color:var(--nj-navy)!important;border-color:var(--nj-field)}
.njilga-join__btn--ghost:hover{border-color:var(--nj-navy);background:var(--nj-soft)}
.njilga-join__btn:focus-visible,.njilga-join__toggle:focus-visible{outline:2px solid var(--nj-blue);outline-offset:2px}
.njilga-join__btn[disabled]{opacity:.6;cursor:wait}
.njilga-join__toggle{border:0;background:none;padding:0;margin:8px 0 0;color:var(--nj-blue);font-family:var(--nj-font-ui);font-size:13px;font-weight:600;cursor:pointer}
.njilga-join__labelrow .njilga-join__toggle{margin:0}
.njilga-join__toggle:hover{color:var(--nj-blue-dark);text-decoration:underline}
.njilga-join__nav{display:flex;justify-content:space-between;align-items:center;gap:16px;margin:28px 0 0;padding:24px 0 0;border-top:1px solid var(--nj-line)}
.njilga-join__step:not(.njilga-join__card)>.njilga-join__nav{margin:4px 0 24px;padding:0;border-top:0}
.njilga-join__actions .njilga-join__nav{margin:0;padding:0;border:0}
.njilga-join__hp{position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden}
@media (max-width:640px){.njilga-join__card{padding:24px 20px}.njilga-join__foot{margin:12px -20px -24px;padding:16px 20px}.njilga-join__title{font-size:22px}.njilga-join__grid,.njilga-join__grid--name{grid-template-columns:1fr}.njilga-join__plan{flex-direction:column;align-items:flex-start;padding:24px 20px}.njilga-join__plan-name{font-size:24px}.njilga-join__plan-price{font-size:36px}.njilga-join__steps{gap:8px 12px;flex-wrap:nowrap}.njilga-join__steps li:not(.is-current){font-size:0;gap:0}.njilga-join__steps li span{font-size:12px}.njilga-join__colleague{grid-template-columns:1fr 40px}.njilga-join__colleague .njilga-join__field{grid-column:1}.njilga-join__remove{grid-row:1;grid-column:2}.njilga-join__nav .njilga-join__btn,.njilga-join__actions .njilga-join__btn{padding:12px 20px}}
</style>';
    }

    // -------------------------------------------------------------------------
    // Scripts
    // -------------------------------------------------------------------------

    /**
     * Queue inline JavaScript for the footer rather than printing it in
     * the shortcode's HTML. Block themes run wptexturize() over the whole
     * rendered template, and it reads `i<steps.length` in an inline
     * script as the start of a tag and rewrites the `&&` after it — a
     * SyntaxError. Scripts queued through WordPress are printed after
     * that filtering, untouched.
     */
    private static function add_script( string $js ): void {
        if ( did_action( 'wp_print_footer_scripts' ) ) {
            wp_print_inline_script_tag( $js ); // Rendered too late for the queue (rare).
            return;
        }
        if ( ! wp_script_is( 'njilga-join', 'registered' ) ) {
            wp_register_script( 'njilga-join', false, [], false, true );
        }
        wp_enqueue_script( 'njilga-join' );
        wp_add_inline_script( 'njilga-join', $js );
    }

    private static function password_script( string $uid ): void {
        ob_start();
        ?>
        (function(){var f=document.getElementById(<?php echo wp_json_encode( $uid ); ?>);if(!f)return;
            f.querySelectorAll('[data-show-password]').forEach(function(b){b.hidden=false;b.addEventListener('click',function(){var show=b.textContent.indexOf('Show')===0;f.querySelectorAll('input[name=password],input[name=password_confirm]').forEach(function(i){i.type=show?'text':'password';});b.textContent=show?'Hide password':'Show password';});});
            var p=f.querySelector('input[name=password]'),c=f.querySelector('input[name=password_confirm]');
            function match(){if(c)c.setCustomValidity(c.value&&p&&c.value!==p.value?'The passwords don’t match.':'');}
            if(p&&c){p.addEventListener('input',match);c.addEventListener('input',match);}
        })();
        <?php
        self::add_script( (string) ob_get_clean() );
    }

    /**
     * Phone numbers as FluentCRM keeps them: a US number, however it was
     * typed, is rewritten to ###-###-#### when the field is left (the
     * server does the same, and adds +1 on the way to FluentCRM). A
     * number that isn't a complete US one, or an international number
     * starting with +, gets a message instead of a silent failure.
     * Registered before the wizard's own listeners, so the mailing phone
     * copies the tidied number.
     */
    private static function phone_script( string $uid ): void {
        ob_start();
        ?>
        (function(){var f=document.getElementById(<?php echo wp_json_encode( $uid ); ?>);if(!f)return;
            var msg=<?php echo wp_json_encode( 'Please enter a 10-digit US phone number, such as ' . MyNJILGA_Phone::US_PLACEHOLDER . ' — or, outside the US, the number with its country code (starting with +).' ); ?>;
            function us(v){var t=v.trim();if(/^(\+|00)\s*\(?\s*[02-9]/.test(t))return '';var d=t.replace(/\D+/g,'');if(d.length===13&&d.indexOf('001')===0)d=d.slice(3);if(d.length===11&&d.charAt(0)==='1')d=d.slice(1);return /^[2-9]\d{2}[2-9]\d{6}$/.test(d)?d:'';}
            function intl(v){var t=v.trim();if(!/^(\+|00)\s*\(?\s*[02-9]/.test(t))return false;var n=t.replace(/^\s*00/,'').replace(/\D+/g,'').length;return n>=8&&n<=15;}
            function tidy(i){var v=i.value;if(v.trim()===''){i.setCustomValidity('');return;}var d=us(v);
                if(d){i.value=d.slice(0,3)+'-'+d.slice(3,6)+'-'+d.slice(6);i.setCustomValidity('');}
                else i.setCustomValidity(intl(v)?'':msg);}
            f.querySelectorAll('input[type=tel]').forEach(function(i){
                i.addEventListener('change',function(){tidy(i);});i.addEventListener('blur',function(){tidy(i);});
                i.addEventListener('input',function(){if(i.validationMessage===msg&&(us(i.value)||intl(i.value)))i.setCustomValidity('');});
                if(i.value)tidy(i);});
        })();
        <?php
        self::add_script( (string) ob_get_clean() );
    }

    /**
     * Username and email, checked against existing accounts before the
     * form goes on (ajax_check_account(); the server checks again on
     * submit). Until the person types a username of their own, it follows
     * the names — first initial + last name, letters and digits only —
     * and a taken one is swapped for the next free variant (azulu2). An
     * email that already has an account blocks Continue with a log-in
     * link.
     */
    private static function account_script( string $uid ): void {
        $config = [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'action'  => MyNJILGA_Join_Form::AJAX_CHECK_ACCOUNT,
            'nonce'   => wp_create_nonce( MyNJILGA_Join_Form::NONCE_ACTION . '_code' ),
        ];
        ob_start();
        ?>
        (function(){var f=document.getElementById(<?php echo wp_json_encode( $uid ); ?>);if(!f)return;var cfg=<?php echo wp_json_encode( $config ); ?>;
            var u=f.querySelector('input[name=username]');if(!u)return;
            var fn=f.querySelector('input[name=first_name]'),ln=f.querySelector('input[name=last_name]'),em=f.querySelector('input[name=email]');
            var uh=f.querySelector('[data-username-hint]'),eh=f.querySelector('[data-email-hint]'),uDefault=uh?uh.textContent:'';
            var auto=u.value==='',timer=null,seq=0,eseq=0;
            function clean(x){x=(x||'');if(x.normalize)x=x.normalize('NFD').replace(/[\u0300-\u036f]/g,'');return x.replace(/[^A-Za-z0-9]+/g,'').toLowerCase();}
            function base(){var a=clean(fn&&fn.value),b=clean(ln&&ln.value),r=a.charAt(0)+b;if(r.length<3)r=a+b;if(r.length<3)r=r?r+'member':'';return r.slice(0,50);}
            function post(data){data.action=cfg.action;data._nonce=cfg.nonce;return fetch(cfg.ajaxUrl,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams(data).toString()}).then(function(r){return r.json();});}
            function say(t,bad){if(uh){uh.textContent=t;uh.classList.toggle('is-bad',!!bad);}}
            function check(){var v=u.value.trim(),mine=++seq;if(!v){u.setCustomValidity('');say(uDefault);return;}
                post({username:v,first_name:fn?fn.value:'',last_name:ln?ln.value:''}).then(function(res){if(mine!==seq||!res||!res.success||!res.data.username)return;var r=res.data.username;
                    if(r.status==='ok'){u.setCustomValidity('');say('✓ '+v+' is available.');}
                    else if(r.status==='taken'&&auto&&r.suggestion){u.value=r.suggestion;u.setCustomValidity('');say('✓ '+r.suggestion+' is available ('+v+' is taken).');}
                    else{u.setCustomValidity(r.message||'Please choose another username.');say(r.message,true);}
                }).catch(function(){});}
            function schedule(){clearTimeout(timer);timer=setTimeout(check,400);}
            function follow(){if(!auto)return;u.value=base();schedule();}
            if(fn)fn.addEventListener('input',follow);if(ln)ln.addEventListener('input',follow);
            u.addEventListener('input',function(){auto=u.value==='';u.setCustomValidity('');schedule();});
            u.addEventListener('blur',check);
            if(auto&&((fn&&fn.value)||(ln&&ln.value)))follow();else if(u.value)check();
            if(em&&eh){var eDefault=eh.textContent;
                em.addEventListener('input',function(){if(em.validationMessage&&em.getAttribute('data-exists')){em.setCustomValidity('');em.removeAttribute('data-exists');eh.textContent=eDefault;eh.classList.remove('is-bad');}});
                em.addEventListener('blur',function(){var v=em.value.trim(),mine=++eseq;if(!v)return;
                    post({email:v}).then(function(res){if(mine!==eseq||!res||!res.success||!res.data.email)return;var r=res.data.email;
                        if(r.status==='exists'){em.setCustomValidity(r.message);em.setAttribute('data-exists','1');eh.classList.add('is-bad');eh.textContent='';eh.appendChild(document.createTextNode(r.message+' '));var a=document.createElement('a');a.href=r.login_url;a.textContent='Log in';eh.appendChild(a);}
                        else if(em.getAttribute('data-exists')){em.setCustomValidity('');em.removeAttribute('data-exists');eh.textContent=eDefault;eh.classList.remove('is-bad');}
                    }).catch(function(){});});}
        })();
        <?php
        self::add_script( (string) ob_get_clean() );
    }

    /**
     * "My mailing address is outside the United States": show State, or
     * region and Country, to match (address_fields() serves them all),
     * and move `required` and the ZIP pattern with them so the browser
     * doesn't insist on a US state or ZIP for an overseas address. The
     * set not showing is DISABLED as well as hidden — a hidden control
     * that is still enabled is still validated, and on the invite form
     * (native validation) a hidden ZIP holding "SW1A 2AA" silently
     * blocked the submit. Disabled, it is skipped and not posted either.
     * Its own script because the invite form has no wizard to carry it.
     */
    private static function address_script( string $uid ): void {
        ob_start();
        ?>
        (function(){var f=document.getElementById(<?php echo wp_json_encode( $uid ); ?>);if(!f)return;var o=f.querySelector('[data-outside-us]');if(!o)return;
            var st=f.querySelector('select[name=state]'),country=f.querySelector('input[name=country]'),zip=f.querySelector('input[name=postal_code]'),zipLabel=f.querySelector('[data-postal-label]');
            // Only one set shows from here on, so the "(US addresses)" notes go.
            f.querySelectorAll('[data-address-note]').forEach(function(e){e.hidden=true;});
            function sync(){var out=o.checked;
                f.querySelectorAll('[data-us-only],[data-intl-only]').forEach(function(g){var off=g.hasAttribute('data-us-only')?out:!out;g.hidden=off;g.querySelectorAll('input,select').forEach(function(i){i.disabled=off;});});
                f.querySelectorAll('[data-required-when]').forEach(function(s){s.hidden=(s.getAttribute('data-required-when')==='intl')!==out;});
                if(st)st.required=!out;if(country)country.required=out;
                if(zip){var zp=zip.getAttribute('data-us-pattern');zip.required=!out;
                    if(out||!zp){zip.removeAttribute('pattern');zip.removeAttribute('inputmode');}else{zip.setAttribute('pattern',zp);zip.setAttribute('inputmode','numeric');}}
                if(zipLabel)zipLabel.textContent=out?'Postal code':'ZIP code';}
            o.addEventListener('change',sync);sync();
        })();
        <?php
        self::add_script( (string) ob_get_clean() );
    }

    /**
     * @param array<string,mixed> $a
     */
    private static function form_script( string $uid, array $a, bool $isStudent ): void {
        $config = [
            'ladder'        => array_values( (array) $a['ladder'] ),
            'label'         => (string) $a['category']['label'],
            'max'           => (int) $a['max_colleagues'],
            'colleagues'    => (bool) $a['colleagues'],
            'student'       => $isStudent,
            'hasErrors'     => ! empty( $a['errors'] ) || (string) $a['error'] !== '',
            'errorFields'   => array_keys( (array) $a['errors'] ),
            'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
            'searchAction'  => MyNJILGA_Application_Form::AJAX_SEARCH,
            'searchNonce'   => wp_create_nonce( MyNJILGA_Application_Form::NONCE_SEARCH ),
            'loggedIn'      => ! empty( $a['user'] ),
            'needsCode'     => ! empty( $a['needs_code'] ),
            'codeNonce'     => wp_create_nonce( MyNJILGA_Join_Form::NONCE_ACTION . '_code' ),
            'sendCode'      => MyNJILGA_Join_Form::AJAX_SEND_CODE,
            'checkCode'     => MyNJILGA_Join_Form::AJAX_CHECK_CODE,
        ];
        self::password_script( $uid );
        self::phone_script( $uid );
        self::account_script( $uid );
        self::address_script( $uid );
        ob_start();
        ?>
        (function(){
            var cfg=<?php echo wp_json_encode( $config ); ?>;
            var form=document.getElementById(<?php echo wp_json_encode( $uid ); ?>);if(!form)return;
            var wrap=document.getElementById(<?php echo wp_json_encode( $uid . '-wrap' ); ?>);
            var tpl=document.getElementById(<?php echo wp_json_encode( $uid . '-colleague' ); ?>);
            function $(s,r){return (r||form).querySelector(s);} function $$(s,r){return Array.prototype.slice.call((r||form).querySelectorAll(s));}
            function money(c){return '$'+(c%100===0?(c/100).toLocaleString('en-US'):(c/100).toLocaleString('en-US',{minimumFractionDigits:2}));}
            function priceFor(rank){var l=cfg.ladder;for(var i=0;i<l.length;i++){if(rank>=l[i].from&&(l[i].to===0||rank<=l[i].to))return l[i];}return l[l.length-1];}
            function esc(s){var d=document.createElement('div');d.textContent=s;return d.innerHTML;}
            form.noValidate=true;
            var free=false; // The live total is $0 (see summary()).

            // ---- Colleagues ------------------------------------------------
            var box=$('[data-colleagues]'),addBtn=$('[data-add-colleague]');
            function rows(){return box?$$('[data-colleague-row]',box):[];}
            function wantsColleagues(){var r=$('input[name=add_colleagues]:checked');return !!(r&&r.value==='yes');}
            function syncColleagues(){
                if(!box)return;var on=wantsColleagues();
                box.hidden=!on;if(addBtn)addBtn.hidden=!on||rows().length>=cfg.max;
                rows().forEach(function(r,n){r.setAttribute('aria-label','Colleague '+(n+1));$$('input',r).forEach(function(i){i.disabled=!on;i.required=on;});var x=$('[data-remove-colleague]',r);if(x)x.hidden=false;});
                if(on&&!rows().length)addRow();
                summary();
            }
            function addRow(){if(!tpl||rows().length>=cfg.max)return;var node=tpl.content.firstElementChild.cloneNode(true);box.appendChild(node);wireRow(node);syncColleagues();var f=$('input',node);if(f)f.focus();}
            function wireRow(r){var x=$('[data-remove-colleague]',r);if(x)x.addEventListener('click',function(){r.parentNode.removeChild(r);if(!rows().length){var no=$('input[name=add_colleagues][value=no]');if(no)no.checked=true;}syncColleagues();});$$('input',r).forEach(function(i){i.addEventListener('input',summary);});}
            if(box){
                // Drop the no-JS spare blank rows; JS adds rows on demand.
                rows().forEach(function(r){var empty=$$('input',r).every(function(i){return !i.value;});if(empty&&rows().length>1)r.parentNode.removeChild(r);else wireRow(r);});
                $$('input[name=add_colleagues]').forEach(function(i){i.addEventListener('change',syncColleagues);});
                if(addBtn)addBtn.addEventListener('click',addRow);
                syncColleagues();
            }
            // The first problem with the colleagues, and the field it's about —
            // so colleague 3's duplicate email is reported on colleague 3's email.
            function colleagueError(){
                if(!wantsColleagues())return null;var seen={},payer=($('input[name=email]')||{}).value||'';seen[payer.trim().toLowerCase()]=1;
                var rs=rows();if(!rs.length)return {msg:'Add at least one colleague, or choose “No, just me”.',el:$('input[name=add_colleagues]')};
                for(var i=0;i<rs.length;i++){var ins=$$('input',rs[i]),v=ins.map(function(x){return x.value.trim();}),who='Colleague '+(i+1)+': ';
                    if(!v[0]||!v[1])return {msg:who+'please give a first and last name.',el:ins[v[0]?1:0]};
                    if(!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v[2]))return {msg:who+'please enter a valid email address.',el:ins[2]};
                    var k=v[2].toLowerCase();if(seen[k])return {msg:who+'that email is already on this form.',el:ins[2]};seen[k]=1;}
                return null;
            }

            // ---- Summary ---------------------------------------------------
            // A $0 total goes to NJILGA staff for approval, not to Stripe, so the
            // note and the button follow the live total.
            function payLabel(){return free?'Submit for approval':'Continue to secure payment';}
            function summary(){
                var t=$('[data-summary] tbody'),tot=$('[data-total]');if(!t)return;
                var me=(($('input[name=first_name]')||{}).value||'').trim()+' '+(($('input[name=last_name]')||{}).value||'').trim();
                var people=[{name:me.trim()||'You',you:true}];
                if(cfg.colleagues&&wantsColleagues())rows().forEach(function(r){var v=$$('input',r).map(function(x){return x.value.trim();});people.push({name:(v[0]+' '+v[1]).trim()||'Colleague'});});
                var html='',total=0;
                people.forEach(function(p,i){var tier=priceFor(i+1);total+=tier.price_cents;var what=cfg.colleagues?(tier.label+(tier.price_cents?'':' — no charge')):cfg.label;
                    html+='<tr><td>'+esc(p.name)+(p.you?' (you)':'')+'<br><span class="njilga-join__muted">'+esc(what)+'</span></td><td class="njilga-join__num">'+money(tier.price_cents)+'</td></tr>';});
                t.innerHTML=html;if(tot)tot.textContent=money(total);
                free=total===0;var pn=$('[data-pay-note]'),rn=$('[data-review-note]'),sb=$('[data-submit]');
                if(pn)pn.hidden=free;if(rn)rn.hidden=!free;if(sb&&!sb.disabled)sb.textContent=payLabel();
            }
            $$('input[name=first_name],input[name=last_name]').forEach(function(i){i.addEventListener('input',summary);});

            // ---- Student toggles -----------------------------------------
            var up=$('[data-upload]'),schoolLabel=$('[data-school-label]'),doc=up?$('input[type=file]',up):null;
            function syncStudent(){var s=$('input[name=student_status]:checked'),enrolled=!!(s&&s.value==='enrolled');if(up){up.hidden=!enrolled;var f=$('input[type=file]',up);if(f){f.required=enrolled;f.disabled=!enrolled;}}if(schoolLabel)schoolLabel.textContent=s?(enrolled?'Law school':'College or university'):'School';}
            $$('input[name=student_status]').forEach(function(i){i.addEventListener('change',syncStudent);});syncStudent();
            // Over the server's limit, a file is refused only after the whole
            // form has gone up — and past post_max_size PHP drops the POST and
            // everything typed with it — so stop it here.
            if(doc)doc.addEventListener('change',function(){var f=doc.files&&doc.files[0],max=parseInt(doc.getAttribute('data-max-bytes')||'0',10);
                doc.setCustomValidity(f&&max>0&&f.size>max?'That file is too large — the limit is '+doc.getAttribute('data-max-label')+'. Please choose a smaller photo or PDF.':'');if(!doc.checkValidity())doc.reportValidity();});
            var phone=$('input[name=phone]'),mphone=$('input[name=mailing_phone]');
            if(phone&&mphone)phone.addEventListener('change',function(){if(!mphone.value)mphone.value=phone.value;});
            var em=$('input[name=email]'),emc=$('input[name=email_confirm]');
            function emailMatch(){if(emc)emc.setCustomValidity(emc.value&&em&&emc.value.trim().toLowerCase()!==em.value.trim().toLowerCase()?'The email addresses don’t match.':'');}
            if(em&&emc){em.addEventListener('input',emailMatch);emc.addEventListener('input',emailMatch);}

            // ---- Firm type-ahead ----------------------------------------
            var firm=$('input[name=firm_name]');
            if(firm){
                var hid=$('input[name=company_id]'),list=$('.njilga-join__suggest'),hint=$('[data-firm-hint]'),timer=null,items=[],active=-1,picked=firm.value,lastQ='',lastData=[],guessed=false;
                function close(){list.style.display='none';list.innerHTML='';firm.setAttribute('aria-expanded','false');active=-1;}
                // A pending search would reopen the list under the choice.
                function pick(it){clearTimeout(timer);hid.value=it.id||0;firm.value=it.name;hint.textContent=it.id?('Existing firm selected: '+it.name):('“'+it.name+'” will be added as a new firm.');guessed=false;picked=firm.value;close();summary();}
                // A name typed but not picked. The server files the join under an
                // existing firm of exactly that name (resolve_firm_choice), so an
                // exact match is picked rather than called new; until a search
                // has answered for this name, the hint says either can happen.
                function settle(){var q=firm.value.trim();if(hid.value!=='0'||q===''||(hint.textContent&&!guessed))return;
                    if(q.toLowerCase()!==lastQ.toLowerCase()){hint.textContent='“'+q+'” will be matched to an existing firm of that name, or added as a new firm.';guessed=true;return;}
                    for(var i=0;i<lastData.length;i++){if(String(lastData[i].name).trim().toLowerCase()===q.toLowerCase()){pick(lastData[i]);return;}}
                    hint.textContent='“'+q+'” will be added as a new firm.';guessed=false;}
                function render(q,data,known){if(known!==false){lastQ=q;lastData=data;}
                    // An answer that lands after the applicant has left the field
                    // only settles the hint; it never reopens the list.
                    if(document.activeElement!==firm){close();settle();return;}
                    items=data.map(function(c){return {id:c.id,name:c.name};});
                    var exact=data.some(function(c){return c.name.toLowerCase()===q.toLowerCase();});if(!exact&&q.length>=2)items.push({id:0,name:q,isNew:true});
                    list.innerHTML='';if(!items.length){close();return;}
                    items.forEach(function(it,i){var li=document.createElement('li');li.setAttribute('role','option');li.id=form.id+'-opt-'+i;li.textContent=it.isNew?('Add your firm: “'+it.name+'”'):it.name;if(it.isNew)li.className='is-new';li.addEventListener('mousedown',function(e){e.preventDefault();pick(it);});list.appendChild(li);});
                    list.style.display='block';firm.setAttribute('aria-expanded','true');active=-1;}
                function search(){var q=firm.value.trim();if(q.length<2){close();return;}
                    var body=new URLSearchParams({action:cfg.searchAction,q:q,_nonce:cfg.searchNonce});
                    fetch(cfg.ajaxUrl,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:body.toString()}).then(function(r){return r.json();}).then(function(res){if(firm.value.trim()===q&&firm.value!==picked)render(q,(res&&res.success&&res.data)?res.data:[]);}).catch(function(){if(firm.value.trim()===q&&firm.value!==picked)render(q,[],false);});}
                firm.addEventListener('input',function(){if(firm.value!==picked){hid.value='0';hint.textContent='';picked=null;}clearTimeout(timer);timer=setTimeout(search,250);});
                firm.addEventListener('keydown',function(e){if(list.style.display!=='block')return;var lis=$$('li',list);
                    if(e.key==='ArrowDown'){e.preventDefault();active=Math.min(active+1,lis.length-1);}else if(e.key==='ArrowUp'){e.preventDefault();active=Math.max(active-1,0);}
                    // Enter picks the highlighted firm, else just closes the list
                    // (settling the hint) — never the form's Enter, which moves on.
                    else if(e.key==='Enter'){e.preventDefault();clearTimeout(timer);if(active>=0)pick(items[active]);else{close();settle();}return;}
                    else if(e.key==='Escape'){close();return;}else{return;}
                    lis.forEach(function(li,i){li.setAttribute('aria-selected',i===active?'true':'false');});if(lis[active])firm.setAttribute('aria-activedescendant',lis[active].id);});
                firm.addEventListener('blur',function(){setTimeout(close,150);settle();});
            }

            // ---- Email verification -------------------------------------
            // A 6-digit code proves the address before any account exists.
            // sentTo is the address a code really went to: only that one is
            // asked for its code, and any other address gets one sent first.
            // sentMsg is what the server said about that send. An address
            // that already has an account is sent a "log in instead" note,
            // not a code, and the server's answer is worded to cover both
            // (it can't say which, or the reply would tell anyone who has an
            // account here) — so that answer is what the applicant is shown,
            // never a promise of a code that may not be coming.
            var codeBox=$('[data-code-field]'),codeIn=$('input[name=email_code]'),codeHint=$('[data-code-hint]'),resend=$('[data-resend-code]'),verified='',sentTo='',sentMsg='',sending=false,checking=false;
            if(codeBox&&!cfg.needsCode)codeBox.hidden=true;
            function post(action,data){data.action=action;data._nonce=cfg.codeNonce;return fetch(cfg.ajaxUrl,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams(data).toString()}).then(function(r){return r.json();});}
            function curEmail(){return em?em.value.trim().toLowerCase():'';}
            if(cfg.needsCode)sentTo=curEmail(); // The server just sent (or kept) one for the posted address.
            function enterCode(to){return sentMsg||'Enter the 6-digit code from the email we sent to '+to+'.';}
            // "Send a new code" shows after every attempt, failed ones too.
            // A send that goes through starts over: whatever was confirmed or
            // typed belonged to the code before, which a new one replaces on
            // the server (and within a minute of the last send, when nothing
            // new goes out, the code in that email is still the one to use).
            function sendCode(){
                if(sending||!codeBox)return;var to=curEmail();sending=true;codeBox.hidden=false;codeHint.textContent='Sending an email to '+to+'…';
                post(cfg.sendCode,{email:to}).then(function(res){
                    if(res&&res.success){verified='';codeIn.value='';sentTo=to;sentMsg=(res.data&&res.data.message)||'';codeHint.textContent=enterCode(to);codeIn.focus();}
                    else codeHint.textContent=(res&&res.data)||'We couldn’t send a code just now — please try again.';
                },function(){codeHint.textContent='We couldn’t send a code just now — please try again.';}).then(function(){sending=false;if(resend)resend.hidden=false;});
            }
            // done() once the address is proven; fail() whenever the applicant
            // has something to do here first (the submit path re-enables its
            // button and comes back to this step).
            function gate(done,fail){
                if(cfg.loggedIn||!codeBox||!em||verified===curEmail()){done();return;}
                function no(msg){if(fail)fail();codeBox.hidden=false;if(resend)resend.hidden=false;if(msg)codeHint.textContent=msg;codeIn.focus();}
                if(sending||checking){if(fail)fail();return;}
                if(sentTo!==curEmail()){if(fail)fail();sendCode();return;}
                var c=(codeIn.value||'').replace(/\D+/g,''),to=sentTo;
                if(c.length!==6){no(enterCode(to));return;}
                checking=true;
                post(cfg.checkCode,{email:to,code:c}).then(function(res){checking=false;if(res&&res.success){verified=to;codeHint.textContent='Email confirmed.';done();}else no((res&&res.data)||'That code isn’t right.');},function(){checking=false;no('We couldn’t check the code just now — please try again.');});
            }
            // A code belongs to the address it went to: after an edit the next
            // Continue sends one to the new address, and the hint says so.
            if(em)em.addEventListener('input',function(){if(verified&&verified!==curEmail())verified='';
                if(codeBox&&!codeBox.hidden&&!sending)codeHint.textContent=sentTo&&sentTo===curEmail()?enterCode(sentTo):'We’ll email a code to this address when you continue.';});
            if(resend)resend.addEventListener('click',function(){sendCode();});
            if(cfg.needsCode&&resend)resend.hidden=false;

            // ---- Wizard ----------------------------------------------------
            var steps=$$('[data-step]'),dots=wrap?$$('[data-step-dot]',wrap):[],nav=wrap?$('.njilga-join__steps',wrap):null,cur=0;
            if(nav)nav.hidden=false;
            function next(i){var s=steps[i];if(!check(s))return;if(s.getAttribute('data-step')==='details')gate(function(){go(i+1);});else go(i+1);}
            steps.forEach(function(s,i){
                var bar=document.createElement('div');bar.className='njilga-join__nav';
                if(i>0){var b=document.createElement('button');b.type='button';b.className='njilga-join__btn njilga-join__btn--ghost';b.textContent='← Back';b.addEventListener('click',function(){go(i-1);});bar.appendChild(b);}else{bar.appendChild(document.createElement('span'));}
                if(i<steps.length-1){var n=document.createElement('button');n.type='button';n.className='njilga-join__btn njilga-join__btn--primary';n.textContent='Continue';n.addEventListener('click',function(){next(i);});bar.appendChild(n);s.appendChild(bar);}
                else if(i>0){var act=$('.njilga-join__actions',s);if(act)act.insertBefore(bar.firstChild,act.firstChild);}
            });
            function check(s){
                var fields=$$('input,select,textarea',s).filter(function(i){return !i.disabled&&i.type!=='hidden'&&!closestHidden(i);});
                if(s.getAttribute('data-step')==='colleagues'){var ce=colleagueError();if(ce&&ce.el){var el=ce.el;el.setCustomValidity(ce.msg);el.reportValidity();setTimeout(function(){el.setCustomValidity('');},0);return false;}}
                for(var i=0;i<fields.length;i++){if(!fields[i].checkValidity()){fields[i].reportValidity();return false;}}
                return true;
            }
            // Hidden within its step — the code box before a code is sent, State
            // for an overseas address. The step's own hidden flag doesn't count,
            // so the check at submit covers the steps not showing too (after a
            // re-render, the always-blank password on "Your details").
            function closestHidden(el){while(el&&el!==form&&!el.hasAttribute('data-step')){if(el.hidden)return true;el=el.parentNode;}return false;}
            function show(i){cur=i;steps.forEach(function(s,j){s.hidden=j!==i;});dots.forEach(function(d,j){d.className=j===i?'is-current':(j<i?'is-done':'');if(j===i)d.setAttribute('aria-current','step');else d.removeAttribute('aria-current');});}
            function go(i){show(i);if(i===steps.length-1)summary();var top=wrap||form;if(top.scrollIntoView)top.scrollIntoView({behavior:'smooth',block:'start'});
                // Hiding the old step took keyboard focus with it: start the new
                // one at its heading, so Tab goes on into its fields and a screen
                // reader announces where the applicant now is.
                var h=$('.njilga-join__title',steps[i]);if(h){h.setAttribute('tabindex','-1');try{h.focus({preventScroll:true});}catch(x){h.focus();}}}
            // Enter in a field is the browser's cue to submit the form — from
            // whichever step is showing, as the pay button is the default button
            // even while hidden. In the wizard it means Continue. (The firm
            // type-ahead handles its own Enter first.)
            form.addEventListener('keydown',function(e){var t=e.target;
                if(e.key!=='Enter'||e.defaultPrevented||e.isComposing||!t||t.tagName!=='INPUT'||/^(button|submit|reset|file|image)$/.test(t.type))return;
                e.preventDefault();if(cur<steps.length-1)next(cur);else{var b=$('[data-submit]');if(b&&!b.disabled)b.click();}});
            var inFlight=false;
            function busy(on){inFlight=on;var b=$('[data-submit]');if(b){b.disabled=on;b.textContent=on?(free?'Submitting…':'Opening secure payment…'):payLabel();}}
            form.addEventListener('submit',function(e){
                // One post per click, however impatient: a second would race the
                // first for the same username and code.
                if(inFlight){e.preventDefault();return;}
                // Submitted from an earlier step (a phone keyboard's Go): move on.
                if(cur<steps.length-1){e.preventDefault();next(cur);return;}
                for(var i=0;i<steps.length;i++){if(!check(steps[i])){e.preventDefault();go(i);setTimeout(function(){check(steps[cur]);},50);return;}}
                busy(true);
                if(!cfg.loggedIn&&codeBox&&verified!==curEmail()){
                    e.preventDefault();var d=0;steps.forEach(function(s,j){if(s.getAttribute('data-step')==='details')d=j;});
                    gate(function(){form.submit();},function(){busy(false);go(d);});
                }
            });
            // Back from Stripe can restore this page exactly as it was left: busy.
            window.addEventListener('pageshow',function(e){if(e.persisted)busy(false);});
            // Start where the server found a problem, else at the beginning.
            var start=0;
            if(cfg.hasErrors){for(var k=0;k<steps.length;k++){if($('[aria-invalid=true],.is-invalid',steps[k])){start=k;break;}}}
            show(start);
            summary();
            if(cfg.hasErrors){var n=wrap&&$('[data-errors]',wrap);if(n)n.focus();}
        })();
        <?php
        self::add_script( (string) ob_get_clean() );
    }
}
