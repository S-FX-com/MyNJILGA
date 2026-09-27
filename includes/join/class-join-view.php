<?php
/**
 * Markup, styles and script for [njilga_join] — the view half of
 * MyNJILGA_Join_Form, which decides WHAT to show.
 *
 * Front-end, inside the site's own theme, so (design.md §6) it keeps its
 * own small scoped stylesheet rather than loading the admin design
 * system: everything is under .njilga-join, colours are CSS custom
 * properties a theme can override (--nj-primary, --nj-accent, …), and
 * type inherits the theme's fonts.
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
            <form class="njilga-join__form" id="<?php echo esc_attr( $uid ); ?>" method="post" action="<?php echo esc_url( MyNJILGA_Join_Form::page_url() ); ?>">
                <?php self::hidden( 'accept_invite' ); ?>
                <div class="njilga-join__card">
                    <h3 class="njilga-join__title">Create your NJILGA account</h3>
                    <p><?php echo esc_html( sprintf( '%s %s has covered your %d NJILGA membership%s — it\'s already active. Choose a username and password to sign in to the website.', $join->first_name, $join->last_name, (int) $join->dues_year, $firm !== '' ? ' with ' . $firm : '' ) ); ?></p>
                    <?php self::general_error( $state ); ?>
                    <div class="njilga-join__grid">
                        <?php self::text_field( $uid, 'first_name', 'First name', $v( 'first_name', (string) $invite->first_name ), $errors, [ 'required' => true, 'autocomplete' => 'given-name' ] ); ?>
                        <?php self::text_field( $uid, 'last_name', 'Last name', $v( 'last_name', (string) $invite->last_name ), $errors, [ 'required' => true, 'autocomplete' => 'family-name' ] ); ?>
                    </div>
                    <div class="njilga-join__field"><span class="njilga-join__label">Email</span><div class="njilga-join__static"><?php echo esc_html( (string) $invite->email ); ?></div></div>
                    <?php self::text_field( $uid, 'username', 'Username', $v( 'username' ), $errors, [ 'required' => true, 'autocomplete' => 'username', 'maxlength' => 60 ] ); ?>
                    <div class="njilga-join__grid">
                        <?php self::password_field( $uid, 'password', 'Password', $errors, true ); ?>
                        <?php self::password_field( $uid, 'password_confirm', 'Confirm password', $errors, false ); ?>
                    </div>
                </div>
                <div class="njilga-join__card">
                    <h3 class="njilga-join__title">More information</h3>
                    <?php self::municipality_field( $uid, $v( 'municipality' ), $errors ); ?>
                    <?php self::text_field( $uid, 'phone', 'Primary contact phone', $v( 'phone' ), $errors, [ 'required' => true, 'type' => 'tel', 'autocomplete' => 'tel' ] ); ?>
                    <?php self::text_field( $uid, 'attorney_id', 'NJ Attorney ID number', $v( 'attorney_id' ), $errors, [ 'required' => true, 'maxlength' => 20 ] ); ?>
                    <?php self::text_field( $uid, 'bar_admission_date', 'Date of admission to the New Jersey Bar', $v( 'bar_admission_date' ), $errors, [ 'required' => true, 'type' => 'date', 'max' => gmdate( 'Y-m-d' ) ] ); ?>
                </div>
                <div class="njilga-join__card">
                    <h3 class="njilga-join__title">Mailing address</h3>
                    <?php self::address_fields( $uid, $v, $errors, $old ); ?>
                </div>
                <div class="njilga-join__actions"><button type="submit" class="njilga-join__btn njilga-join__btn--primary">Create my account</button></div>
            </form>
        </div>
        <?php
        self::password_script( $uid );
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

        $steps = $isStudent
            ? [ 'enrollment' => 'Enrollment', 'details' => 'Your details', 'review' => 'Review & pay' ]
            : array_filter( [ 'firm' => 'Your firm', 'details' => 'Your details', 'colleagues' => $a['colleagues'] ? 'Add colleagues' : '', 'review' => 'Review & pay' ] );

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
                        <legend class="njilga-join__title">Are you currently enrolled in law school?</legend>
                        <div class="njilga-join__choices">
                            <?php foreach ( [ 'enrolled' => 'I am actively enrolled as a law student', 'undergrad' => 'I am an undergraduate student with aspirations to get into law school' ] as $val => $label ) : ?>
                                <label class="njilga-join__choice"><input type="radio" name="student_status" value="<?php echo esc_attr( $val ); ?>" required<?php checked( (string) ( $old['student_status'] ?? '' ), $val ); ?>> <span><?php echo esc_html( $label ); ?></span></label>
                            <?php endforeach; ?>
                        </div>
                        <?php self::error( $errors, 'student_status', $uid ); ?>
                    </fieldset>
                <?php else : ?>
                    <fieldset class="njilga-join__step njilga-join__card" data-step="firm">
                        <legend class="njilga-join__title">Which firm do you represent?</legend>
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
                    <?php if ( $user ) : ?>
                        <div class="njilga-join__card">
                            <h3 class="njilga-join__title">Account</h3>
                            <p><?php echo esc_html( sprintf( 'Signed in as %s (%s).', $user->user_login, $user->user_email ) ); ?> <a href="<?php echo esc_url( wp_logout_url( (string) $a['action_url'] ) ); ?>">Not you?</a></p>
                        </div>
                    <?php else : ?>
                        <div class="njilga-join__card">
                            <h3 class="njilga-join__title">Account information</h3>
                            <?php self::text_field( $uid, 'username', 'Username', $v( 'username' ), $errors, [ 'required' => true, 'autocomplete' => 'username', 'maxlength' => 60 ] ); ?>
                            <div class="njilga-join__grid">
                                <?php self::password_field( $uid, 'password', 'Password', $errors, true ); ?>
                                <?php self::password_field( $uid, 'password_confirm', 'Confirm password', $errors, false ); ?>
                            </div>
                            <div class="njilga-join__grid">
                                <?php self::text_field( $uid, 'email', 'Email address', $v( 'email' ), $errors, [ 'required' => true, 'type' => 'email', 'autocomplete' => 'email' ] ); ?>
                                <?php self::text_field( $uid, 'email_confirm', 'Confirm email address', $v( 'email_confirm' ), $errors, [ 'required' => true, 'type' => 'email', 'autocomplete' => 'email' ] ); ?>
                            </div>
                            <div class="njilga-join__field njilga-join__code" data-code-field<?php echo empty( $a['needs_code'] ) ? ' data-code-later' : ''; ?>>
                                <label class="njilga-join__label" for="<?php echo esc_attr( $uid ); ?>-email_code">Email verification code</label>
                                <input type="text" id="<?php echo esc_attr( $uid ); ?>-email_code" name="email_code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="\d{6}" value="<?php echo ! empty( $a['needs_code'] ) ? $v( 'email_code' ) : ''; // phpcs:ignore ?>"<?php self::invalid( $errors, 'email_code', $uid ); ?>>
                                <p class="njilga-join__hint" data-code-hint><?php echo empty( $a['needs_code'] ) ? 'We\'ll email you a 6-digit code to confirm your address. If you\'re filling this in without JavaScript, leave this empty the first time you submit.' : 'Enter the 6-digit code we just emailed you.'; ?></p>
                                <?php self::error( $errors, 'email_code', $uid ); ?>
                                <button type="button" class="njilga-join__toggle" data-resend-code hidden>Send a new code</button>
                            </div>
                            <div class="njilga-join__foot">Already have an account? <a href="<?php echo esc_url( wp_login_url( (string) $a['action_url'] ) ); ?>">Log in here</a></div>
                        </div>
                    <?php endif; ?>

                    <?php if ( $isStudent ) : ?>
                        <div class="njilga-join__card">
                            <h3 class="njilga-join__title">About you</h3>
                            <div class="njilga-join__grid">
                                <?php self::text_field( $uid, 'first_name', 'First name', $v( 'first_name', $user ? (string) $user->first_name : '' ), $errors, [ 'required' => true, 'autocomplete' => 'given-name' ] ); ?>
                                <?php self::text_field( $uid, 'last_name', 'Last name', $v( 'last_name', $user ? (string) $user->last_name : '' ), $errors, [ 'required' => true, 'autocomplete' => 'family-name' ] ); ?>
                            </div>
                            <?php self::text_field( $uid, 'phone', 'Phone', $v( 'phone' ), $errors, [ 'required' => true, 'type' => 'tel', 'autocomplete' => 'tel' ] ); ?>
                            <div class="njilga-join__field">
                                <label class="njilga-join__label" for="<?php echo esc_attr( $uid ); ?>-school"><span data-school-label>School</span> <span class="njilga-join__req">*</span></label>
                                <input type="text" id="<?php echo esc_attr( $uid ); ?>-school" name="school" required maxlength="190" value="<?php echo $v( 'school' ); // phpcs:ignore ?>"<?php self::invalid( $errors, 'school', $uid ); ?>>
                                <?php self::error( $errors, 'school', $uid ); ?>
                            </div>
                            <div class="njilga-join__field" data-upload>
                                <label class="njilga-join__label" for="<?php echo esc_attr( $uid ); ?>-doc">Student ID or transcript <span class="njilga-join__req">*</span></label>
                                <input type="file" id="<?php echo esc_attr( $uid ); ?>-doc" name="student_document" accept=".pdf,.jpg,.jpeg,.png,.webp,.heic,application/pdf,image/*"<?php self::invalid( $errors, 'student_document', $uid ); ?>>
                                <p class="njilga-join__hint">Enrolled law students only. A photo of your student ID or a PDF transcript, up to 8 MB. Only NJILGA staff can see it.</p>
                                <?php self::error( $errors, 'student_document', $uid ); ?>
                            </div>
                        </div>
                        <div class="njilga-join__card">
                            <h3 class="njilga-join__title">Mailing address</h3>
                            <?php self::address_fields( $uid, $v, $errors, $old ); ?>
                        </div>
                    <?php else : ?>
                        <div class="njilga-join__card">
                            <h3 class="njilga-join__title">More information</h3>
                            <?php self::municipality_field( $uid, $v( 'municipality' ), $errors ); ?>
                            <?php self::text_field( $uid, 'phone', 'Primary contact phone', $v( 'phone' ), $errors, [ 'required' => true, 'type' => 'tel', 'autocomplete' => 'tel' ] ); ?>
                            <?php self::text_field( $uid, 'attorney_id', 'NJ Attorney ID number', $v( 'attorney_id' ), $errors, [ 'required' => true, 'maxlength' => 20 ] ); ?>
                            <?php self::text_field( $uid, 'bar_admission_date', 'Date of admission to the New Jersey Bar', $v( 'bar_admission_date' ), $errors, [ 'required' => true, 'type' => 'date', 'max' => gmdate( 'Y-m-d' ) ] ); ?>
                        </div>
                        <div class="njilga-join__card">
                            <h3 class="njilga-join__title">Mailing address</h3>
                            <div class="njilga-join__grid">
                                <?php self::text_field( $uid, 'first_name', 'First name', $v( 'first_name', $user ? (string) $user->first_name : '' ), $errors, [ 'required' => true, 'autocomplete' => 'given-name' ] ); ?>
                                <?php self::text_field( $uid, 'last_name', 'Last name', $v( 'last_name', $user ? (string) $user->last_name : '' ), $errors, [ 'required' => true, 'autocomplete' => 'family-name' ] ); ?>
                            </div>
                            <?php self::address_fields( $uid, $v, $errors, $old ); ?>
                            <?php self::text_field( $uid, 'mailing_phone', 'Phone', $v( 'mailing_phone' ), $errors, [ 'required' => true, 'type' => 'tel', 'autocomplete' => 'tel' ] ); ?>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if ( ! $isStudent && $a['colleagues'] ) : ?>
                    <fieldset class="njilga-join__step njilga-join__card" data-step="colleagues">
                        <legend class="njilga-join__title">Bring your firm along</legend>
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
                    <legend class="njilga-join__title">Review &amp; pay</legend>
                    <table class="njilga-join__summary" data-summary>
                        <tbody>
                            <tr><td><?php echo esc_html( (string) $category['label'] ); ?> — you</td><td class="njilga-join__num"><?php echo esc_html( self::dollars( $price ) ); ?></td></tr>
                        </tbody>
                        <tfoot><tr><th>Total</th><th class="njilga-join__num" data-total><?php echo esc_html( self::dollars( $price ) ); ?></th></tr></tfoot>
                    </table>
                    <noscript><p class="njilga-join__hint"><?php echo esc_html( $a['colleagues'] ? self::ladder_sentence( $ladder ) . ' Your total, including any colleagues, is shown on the payment page before you pay.' : 'Your total is shown on the payment page before you pay.' ); ?></p></noscript>
                    <p class="njilga-join__muted">You'll pay on Stripe's secure page<?php echo MyNJILGA_Dues_Settings::general( 'join_allow_ach', true ) ? ' by card or US bank account' : ' by card'; ?>. Your membership — and any colleagues' — starts as soon as the payment clears.</p>
                    <div class="njilga-join__actions"><button type="submit" class="njilga-join__btn njilga-join__btn--primary" data-submit>Continue to secure payment</button></div>
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
        wp_nonce_field( MyNJILGA_Join_Form::NONCE_ACTION . '_' . $action, MyNJILGA_Join_Form::NONCE_FIELD, false );
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
        foreach ( [ 'autocomplete', 'maxlength', 'max', 'pattern', 'inputmode' ] as $k ) {
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
     * @param array<string,string> $errors
     */
    private static function municipality_field( string $uid, string $escapedValue, array $errors ): void {
        $options = MyNJILGA_Dues_Settings::municipality_options();
        if ( ! $options ) {
            self::text_field( $uid, 'municipality', 'Municipality', $escapedValue, $errors, [ 'maxlength' => 190 ] );
            return;
        }
        $id = $uid . '-municipality';
        printf( '<div class="njilga-join__field"><label class="njilga-join__label" for="%1$s">Municipality</label><select id="%1$s" name="municipality"', esc_attr( $id ) );
        self::invalid( $errors, 'municipality', $uid );
        echo '><option value="">- Select -</option>';
        foreach ( $options as $opt ) {
            printf( '<option value="%s"%s>%s</option>', esc_attr( $opt ), selected( $escapedValue, esc_attr( $opt ), false ), esc_html( $opt ) );
        }
        echo '</select>';
        self::error( $errors, 'municipality', $uid );
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

        echo '<div class="njilga-join__grid" data-us-only' . ( $outside ? ' hidden' : '' ) . '>';
        $id = $uid . '-state';
        printf( '<div class="njilga-join__field"><label class="njilga-join__label" for="%1$s">State <span class="njilga-join__req">*</span></label><select id="%1$s" name="state" autocomplete="address-level1"%2$s', esc_attr( $id ), $outside ? '' : ' required' );
        self::invalid( $errors, 'state', $uid );
        echo '><option value="">Select state</option>';
        foreach ( self::us_states() as $code => $name ) {
            printf( '<option value="%s"%s>%s</option>', esc_attr( $code ), selected( (string) ( $old['state'] ?? '' ), $code, false ), esc_html( $name ) );
        }
        echo '</select>';
        self::error( $errors, 'state', $uid );
        echo '</div>';
        self::text_field( $uid, 'postal_code', 'ZIP code', $v( 'postal_code' ), $errors, $outside ? [ 'autocomplete' => 'postal-code' ] : [ 'required' => true, 'autocomplete' => 'postal-code', 'inputmode' => 'numeric', 'pattern' => '\d{5}(-\d{4})?' ] );
        echo '</div>';

        echo '<div class="njilga-join__grid" data-intl-only' . ( $outside ? '' : ' hidden' ) . '>';
        self::text_field( $uid, 'region', 'State / province / region', $v( 'region' ), $errors, [ 'autocomplete' => 'address-level1' ] );
        self::text_field( $uid, 'country', 'Country', $v( 'country' ), $errors, $outside ? [ 'required' => true, 'autocomplete' => 'country-name' ] : [ 'autocomplete' => 'country-name' ] );
        echo '</div>';
    }

    /**
     * @param array<string,string> $r
     * @param array<string,string> $errors
     */
    private static function colleague_row( string $uid, int $i, array $r, array $errors ): void {
        $err = $i >= 0 ? (string) ( $errors[ 'colleague_' . $i ] ?? '' ) : '';
        printf( '<div class="njilga-join__colleague%s" data-colleague-row>', $err !== '' ? ' is-invalid' : '' );
        printf( '<div class="njilga-join__field"><label class="njilga-join__label">First name</label><input type="text" name="colleague_first[]" autocomplete="off" maxlength="100" value="%s"></div>', esc_attr( (string) ( $r['first_name'] ?? '' ) ) );
        printf( '<div class="njilga-join__field"><label class="njilga-join__label">Last name</label><input type="text" name="colleague_last[]" autocomplete="off" maxlength="100" value="%s"></div>', esc_attr( (string) ( $r['last_name'] ?? '' ) ) );
        printf( '<div class="njilga-join__field"><label class="njilga-join__label">Email</label><input type="email" name="colleague_email[]" autocomplete="off" maxlength="190" value="%s"></div>', esc_attr( (string) ( $r['raw_email'] ?? ( $r['email'] ?? '' ) ) ) );
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
        return '<style>
.njilga-join{--nj-primary:#1c2b45;--nj-primary-fg:#fff;--nj-accent:#c5a253;--nj-fg:#1f2937;--nj-muted:#6b7280;--nj-border:#d1d5db;--nj-card:#fff;--nj-soft:#f4f5f7;--nj-danger:#b42318;--nj-danger-bg:#fef3f2;--nj-success:#067647;--nj-success-bg:#ecfdf3;--nj-warn:#b54708;--nj-warn-bg:#fffaeb;--nj-radius:8px;max-width:960px;margin:0 auto;color:var(--nj-fg);box-sizing:border-box}
.njilga-join *,.njilga-join *::before,.njilga-join *::after{box-sizing:border-box}
.njilga-join [hidden]{display:none!important}
.njilga-join__card{background:var(--nj-card);border:1px solid #e5e7eb;border-radius:var(--nj-radius);box-shadow:0 1px 3px rgba(16,24,40,.06);padding:28px 36px;margin:0 0 24px;min-width:0}
.njilga-join__card--success{border-color:#abefc6}
.njilga-join__card--error{border-color:#fecdca}
.njilga-join fieldset.njilga-join__card{display:block}
.njilga-join__title{font-size:1.35em;margin:0 0 14px;padding:0;line-height:1.25}
.njilga-join legend.njilga-join__title{float:left;width:100%}
.njilga-join legend.njilga-join__title+*{clear:both}
.njilga-join__optional{font-size:.7em;font-weight:400;color:var(--nj-muted)}
.njilga-join__plan{display:flex;justify-content:space-between;align-items:center;gap:16px;background:var(--nj-primary);color:var(--nj-primary-fg);border-radius:var(--nj-radius);padding:18px 24px;margin:0 0 20px}
.njilga-join__plan .njilga-join__muted{color:rgba(255,255,255,.75)}
.njilga-join__plan-name{font-weight:700;font-size:1.1em}
.njilga-join__plan-price{font-size:1.8em;font-weight:700;white-space:nowrap}
.njilga-join__plan-price span{font-size:.45em;font-weight:400;margin-left:4px;opacity:.8}
.njilga-join__steps{display:flex;flex-wrap:wrap;gap:8px 20px;list-style:none;margin:0 0 20px;padding:0;font-size:.9em;color:var(--nj-muted)}
.njilga-join__steps li{display:flex;align-items:center;gap:8px}
.njilga-join__steps li span{display:inline-flex;width:24px;height:24px;border-radius:50%;align-items:center;justify-content:center;border:1px solid var(--nj-border);font-size:.85em}
.njilga-join__steps li.is-current{color:var(--nj-fg);font-weight:600}
.njilga-join__steps li.is-current span,.njilga-join__steps li.is-done span{background:var(--nj-primary);border-color:var(--nj-primary);color:var(--nj-primary-fg)}
.njilga-join__field{margin:0 0 16px;min-width:0}
.njilga-join__label{display:block;color:var(--nj-muted);font-size:.95em;margin:0 0 6px}
.njilga-join__labelrow{display:flex;justify-content:space-between;align-items:baseline;gap:8px}
.njilga-join__req{color:var(--nj-danger)}
.njilga-join input[type=text],.njilga-join input[type=email],.njilga-join input[type=tel],.njilga-join input[type=password],.njilga-join input[type=date],.njilga-join input[type=file],.njilga-join select{width:100%;min-height:48px;padding:10px 14px;border:1px solid #9ca3af;border-radius:6px;background:#fff;color:var(--nj-fg);font:inherit;margin:0}
.njilga-join input[type=file]{padding:10px}
.njilga-join input:focus,.njilga-join select:focus{outline:2px solid var(--nj-accent);outline-offset:1px;border-color:var(--nj-primary)}
.njilga-join [aria-invalid=true],.njilga-join .is-invalid input{border-color:var(--nj-danger)}
.njilga-join__grid{display:grid;grid-template-columns:1fr 1fr;gap:0 18px}
.njilga-join__static{min-height:48px;padding:12px 14px;border-radius:6px;background:var(--nj-soft)}
.njilga-join__foot{margin:8px -36px -28px;padding:16px 36px;background:var(--nj-soft);border-top:1px solid #e5e7eb;border-radius:0 0 var(--nj-radius) var(--nj-radius)}
.njilga-join__hint,.njilga-join__muted{color:var(--nj-muted);font-size:.9em;margin:6px 0 0}
.njilga-join__err{color:var(--nj-danger);font-size:.9em;margin:6px 0 0}
.njilga-join__notice{border:1px solid #bfdbfe;background:#eff6ff;border-radius:var(--nj-radius);padding:14px 18px;margin:0 0 20px}
.njilga-join__notice ul{margin:8px 0 0 20px;padding:0}
.njilga-join__notice--error{border-color:#fecdca;background:var(--nj-danger-bg);color:var(--nj-danger)}
.njilga-join__notice--success{border-color:#abefc6;background:var(--nj-success-bg);color:var(--nj-success)}
.njilga-join__notice--warning{border-color:#fedf89;background:var(--nj-warn-bg);color:var(--nj-warn)}
.njilga-join__choices{display:grid;gap:10px;margin:0 0 12px}
.njilga-join__choice{display:flex;gap:12px;align-items:flex-start;border:1px solid var(--nj-border);border-radius:6px;padding:14px 16px;cursor:pointer}
.njilga-join__choice:has(input:checked){border-color:var(--nj-primary);box-shadow:0 0 0 1px var(--nj-primary)}
.njilga-join__choice input{margin-top:4px}
.njilga-join__check{display:flex;gap:10px;align-items:center;margin:0 0 16px;color:var(--nj-muted)}
.njilga-join__firm{position:relative}
.njilga-join__suggest{position:absolute;left:0;right:0;top:78px;z-index:50;background:#fff;border:1px solid var(--nj-border);border-radius:6px;list-style:none;margin:0;padding:4px 0;max-height:260px;overflow:auto;box-shadow:0 8px 24px rgba(16,24,40,.12);display:none}
.njilga-join__suggest li{padding:10px 14px;cursor:pointer;margin:0}
.njilga-join__suggest li:hover,.njilga-join__suggest li[aria-selected=true]{background:var(--nj-soft)}
.njilga-join__suggest li.is-new{color:var(--nj-primary);font-weight:600;border-top:1px solid #eee}
.njilga-join__colleague{display:grid;grid-template-columns:1fr 1fr 1.4fr 36px;gap:0 12px;align-items:end;padding:12px 0;border-top:1px solid #eee}
.njilga-join__colleague .njilga-join__err{grid-column:1/-1;margin-top:0}
.njilga-join__remove{width:36px;height:48px;margin:0 0 16px;border:0;background:none;color:var(--nj-muted);font-size:24px;cursor:pointer}
.njilga-join__summary{width:100%;border-collapse:collapse;margin:0 0 12px}
.njilga-join__summary td,.njilga-join__summary th{padding:10px 0;border-bottom:1px solid #eee;text-align:left;font-weight:400}
.njilga-join__summary tfoot th{font-weight:700;border-bottom:0;font-size:1.1em}
.njilga-join__num{text-align:right!important;white-space:nowrap;padding-left:16px!important}
.njilga-join__actions{display:flex;flex-wrap:wrap;gap:12px;align-items:center;margin-top:16px}
.njilga-join__inline{display:inline;margin:0}
.njilga-join__btn{display:inline-flex;align-items:center;justify-content:center;min-height:48px;padding:12px 28px;border-radius:6px;border:1px solid var(--nj-primary);font:inherit;font-weight:700;cursor:pointer;text-decoration:none;line-height:1.2}
.njilga-join__btn--primary{background:var(--nj-primary);color:var(--nj-primary-fg)}
.njilga-join__btn--primary:hover{background:var(--nj-accent);border-color:var(--nj-accent);color:#fff}
.njilga-join__btn--ghost{background:transparent;color:var(--nj-primary)}
.njilga-join__btn[disabled]{opacity:.6;cursor:wait}
.njilga-join__toggle{border:0;background:none;padding:0;color:var(--nj-primary);font:inherit;font-size:.9em;font-weight:600;cursor:pointer}
.njilga-join__nav{display:flex;justify-content:space-between;gap:12px;margin:-4px 0 24px}
.njilga-join__hp{position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden}
@media (max-width:640px){.njilga-join__card{padding:20px}.njilga-join__foot{margin:8px -20px -20px;padding:14px 20px}.njilga-join__grid{grid-template-columns:1fr}.njilga-join__colleague{grid-template-columns:1fr 36px}.njilga-join__colleague .njilga-join__field{grid-column:1}.njilga-join__remove{grid-row:1;grid-column:2}.njilga-join__plan{flex-direction:column;align-items:flex-start}}
</style>';
    }

    // -------------------------------------------------------------------------
    // Scripts
    // -------------------------------------------------------------------------

    private static function password_script( string $uid ): void {
        ?>
        <script>
        (function(){var f=document.getElementById(<?php echo wp_json_encode( $uid ); ?>);if(!f)return;
            f.querySelectorAll('[data-show-password]').forEach(function(b){b.hidden=false;b.addEventListener('click',function(){var show=b.textContent.indexOf('Show')===0;f.querySelectorAll('input[name=password],input[name=password_confirm]').forEach(function(i){i.type=show?'text':'password';});b.textContent=show?'Hide password':'Show password';});});
            var p=f.querySelector('input[name=password]'),c=f.querySelector('input[name=password_confirm]');
            function match(){if(c)c.setCustomValidity(c.value&&p&&c.value!==p.value?'The passwords don’t match.':'');}
            if(p&&c){p.addEventListener('input',match);c.addEventListener('input',match);}
        })();
        </script>
        <?php
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
        ?>
        <script>
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

            // ---- Colleagues ------------------------------------------------
            var box=$('[data-colleagues]'),addBtn=$('[data-add-colleague]');
            function rows(){return box?$$('[data-colleague-row]',box):[];}
            function wantsColleagues(){var r=$('input[name=add_colleagues]:checked');return !!(r&&r.value==='yes');}
            function syncColleagues(){
                if(!box)return;var on=wantsColleagues();
                box.hidden=!on;if(addBtn)addBtn.hidden=!on||rows().length>=cfg.max;
                rows().forEach(function(r){$$('input',r).forEach(function(i){i.disabled=!on;i.required=on;});var x=$('[data-remove-colleague]',r);if(x)x.hidden=false;});
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
            function colleagueError(){
                if(!wantsColleagues())return '';var seen={},payer=($('input[name=email]')||{}).value||'';seen[payer.trim().toLowerCase()]=1;
                var rs=rows();if(!rs.length)return 'Add at least one colleague, or choose “No, just me”.';
                for(var i=0;i<rs.length;i++){var v=$$('input',rs[i]).map(function(x){return x.value.trim();});
                    if(!v[0]||!v[1])return 'Colleague '+(i+1)+': please give a first and last name.';
                    if(!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v[2]))return 'Colleague '+(i+1)+': please enter a valid email address.';
                    var k=v[2].toLowerCase();if(seen[k])return 'Colleague '+(i+1)+': that email is already on this form.';seen[k]=1;}
                return '';
            }

            // ---- Summary ---------------------------------------------------
            function summary(){
                var t=$('[data-summary] tbody'),tot=$('[data-total]');if(!t)return;
                var me=(($('input[name=first_name]')||{}).value||'').trim()+' '+(($('input[name=last_name]')||{}).value||'').trim();
                var people=[{name:me.trim()||'You',you:true}];
                if(cfg.colleagues&&wantsColleagues())rows().forEach(function(r){var v=$$('input',r).map(function(x){return x.value.trim();});people.push({name:(v[0]+' '+v[1]).trim()||'Colleague'});});
                var html='',total=0;
                people.forEach(function(p,i){var tier=priceFor(i+1);total+=tier.price_cents;var what=cfg.colleagues?(tier.label+(tier.price_cents?'':' — no charge')):cfg.label;
                    html+='<tr><td>'+esc(p.name)+(p.you?' (you)':'')+'<br><span class="njilga-join__muted">'+esc(what)+'</span></td><td class="njilga-join__num">'+money(tier.price_cents)+'</td></tr>';});
                t.innerHTML=html;if(tot)tot.textContent=money(total);
            }
            $$('input[name=first_name],input[name=last_name]').forEach(function(i){i.addEventListener('input',summary);});

            // ---- Address / student toggles -------------------------------
            var outside=$('[data-outside-us]');
            function syncAddress(){if(!outside)return;var o=outside.checked;$$('[data-us-only]').forEach(function(e){e.hidden=o;$$('input,select',e).forEach(function(i){i.required=!o&&(i.name==='state'||i.name==='postal_code');});});$$('[data-intl-only]').forEach(function(e){e.hidden=!o;var c=$('input[name=country]',e);if(c)c.required=o;});}
            if(outside){outside.addEventListener('change',syncAddress);syncAddress();}
            var up=$('[data-upload]'),schoolLabel=$('[data-school-label]');
            function syncStudent(){var s=$('input[name=student_status]:checked'),enrolled=!!(s&&s.value==='enrolled');if(up){up.hidden=!enrolled;var f=$('input[type=file]',up);if(f){f.required=enrolled;f.disabled=!enrolled;}}if(schoolLabel)schoolLabel.textContent=s?(enrolled?'Law school':'College or university'):'School';}
            $$('input[name=student_status]').forEach(function(i){i.addEventListener('change',syncStudent);});syncStudent();
            var phone=$('input[name=phone]'),mphone=$('input[name=mailing_phone]');
            if(phone&&mphone)phone.addEventListener('change',function(){if(!mphone.value)mphone.value=phone.value;});
            var em=$('input[name=email]'),emc=$('input[name=email_confirm]');
            function emailMatch(){if(emc)emc.setCustomValidity(emc.value&&em&&emc.value.trim().toLowerCase()!==em.value.trim().toLowerCase()?'The email addresses don’t match.':'');}
            if(em&&emc){em.addEventListener('input',emailMatch);emc.addEventListener('input',emailMatch);}

            // ---- Firm type-ahead ----------------------------------------
            var firm=$('input[name=firm_name]');
            if(firm){
                var hid=$('input[name=company_id]'),list=$('.njilga-join__suggest'),hint=$('[data-firm-hint]'),timer=null,items=[],active=-1,picked=firm.value;
                function close(){list.style.display='none';list.innerHTML='';firm.setAttribute('aria-expanded','false');active=-1;}
                function pick(it){hid.value=it.id||0;firm.value=it.name;hint.textContent=it.id?('Existing firm selected: '+it.name):('“'+it.name+'” will be added as a new firm.');picked=firm.value;close();summary();}
                function render(q,data){items=data.map(function(c){return {id:c.id,name:c.name};});
                    var exact=data.some(function(c){return c.name.toLowerCase()===q.toLowerCase();});if(!exact&&q.length>=2)items.push({id:0,name:q,isNew:true});
                    list.innerHTML='';if(!items.length){close();return;}
                    items.forEach(function(it,i){var li=document.createElement('li');li.setAttribute('role','option');li.id=form.id+'-opt-'+i;li.textContent=it.isNew?('Add your firm: “'+it.name+'”'):it.name;if(it.isNew)li.className='is-new';li.addEventListener('mousedown',function(e){e.preventDefault();pick(it);});list.appendChild(li);});
                    list.style.display='block';firm.setAttribute('aria-expanded','true');active=-1;}
                function search(){var q=firm.value.trim();if(q.length<2){close();return;}
                    var body=new URLSearchParams({action:cfg.searchAction,q:q,_nonce:cfg.searchNonce});
                    fetch(cfg.ajaxUrl,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:body.toString()}).then(function(r){return r.json();}).then(function(res){if(firm.value.trim()===q)render(q,(res&&res.success&&res.data)?res.data:[]);}).catch(function(){render(q,[]);});}
                firm.addEventListener('input',function(){if(firm.value!==picked){hid.value='0';hint.textContent='';}clearTimeout(timer);timer=setTimeout(search,250);});
                firm.addEventListener('keydown',function(e){if(list.style.display!=='block')return;var lis=$$('li',list);
                    if(e.key==='ArrowDown'){e.preventDefault();active=Math.min(active+1,lis.length-1);}else if(e.key==='ArrowUp'){e.preventDefault();active=Math.max(active-1,0);}else if(e.key==='Enter'){if(active>=0){e.preventDefault();pick(items[active]);}return;}else if(e.key==='Escape'){close();return;}else{return;}
                    lis.forEach(function(li,i){li.setAttribute('aria-selected',i===active?'true':'false');});if(lis[active])firm.setAttribute('aria-activedescendant',lis[active].id);});
                firm.addEventListener('blur',function(){setTimeout(close,150);if(hid.value==='0'&&firm.value.trim()!==''&&!hint.textContent)hint.textContent='“'+firm.value.trim()+'” will be added as a new firm.';});
            }

            // ---- Email verification -------------------------------------
            // A 6-digit code proves the address before any account exists.
            var codeBox=$('[data-code-field]'),codeIn=$('input[name=email_code]'),codeHint=$('[data-code-hint]'),resend=$('[data-resend-code]'),verified='';
            if(codeBox&&!cfg.needsCode)codeBox.hidden=true;
            function post(action,data){data.action=action;data._nonce=cfg.codeNonce;return fetch(cfg.ajaxUrl,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams(data).toString()}).then(function(r){return r.json();});}
            function curEmail(){return em?em.value.trim().toLowerCase():'';}
            function sendCode(){codeHint.textContent='Sending a code to '+curEmail()+'…';return post(cfg.sendCode,{email:curEmail()}).then(function(res){
                if(res&&res.success){codeBox.hidden=false;if(resend)resend.hidden=false;codeHint.textContent='We’ve emailed a 6-digit code to '+curEmail()+'. Enter it to continue.';codeIn.focus();}
                else{codeBox.hidden=false;codeHint.textContent=(res&&res.data)||'We couldn’t send a code just now — please try again.';}}).catch(function(){codeBox.hidden=false;codeHint.textContent='We couldn’t send a code just now — please try again.';});}
            function gate(done){
                if(cfg.loggedIn||!codeBox||!em||verified===curEmail()){done();return;}
                var c=(codeIn.value||'').replace(/\D+/g,'');
                if(codeBox.hidden||c.length!==6){if(codeBox.hidden)sendCode();else{codeHint.textContent='Enter the 6-digit code we emailed to '+curEmail()+'.';codeIn.focus();}return;}
                post(cfg.checkCode,{email:curEmail(),code:c}).then(function(res){if(res&&res.success){verified=curEmail();codeHint.textContent='Email confirmed.';done();}else{codeHint.textContent=(res&&res.data)||'That code isn’t right.';codeIn.focus();}}).catch(function(){codeHint.textContent='We couldn’t check the code just now — please try again.';});
            }
            if(em)em.addEventListener('input',function(){if(verified&&verified!==curEmail()){verified='';}if(codeIn&&!cfg.needsCode){codeIn.value='';}});
            if(resend)resend.addEventListener('click',sendCode);
            if(cfg.needsCode&&resend)resend.hidden=false;

            // ---- Wizard ----------------------------------------------------
            var steps=$$('[data-step]'),dots=wrap?$$('[data-step-dot]',wrap):[],nav=wrap?$('.njilga-join__steps',wrap):null,cur=0;
            if(nav)nav.hidden=false;
            steps.forEach(function(s,i){
                var bar=document.createElement('div');bar.className='njilga-join__nav';
                if(i>0){var b=document.createElement('button');b.type='button';b.className='njilga-join__btn njilga-join__btn--ghost';b.textContent='← Back';b.addEventListener('click',function(){go(i-1);});bar.appendChild(b);}else{bar.appendChild(document.createElement('span'));}
                if(i<steps.length-1){var n=document.createElement('button');n.type='button';n.className='njilga-join__btn njilga-join__btn--primary';n.textContent='Continue';n.addEventListener('click',function(){if(!check(s))return;if(s.getAttribute('data-step')==='details')gate(function(){go(i+1);});else go(i+1);});bar.appendChild(n);s.appendChild(bar);}
                else if(i>0){var act=$('.njilga-join__actions',s);if(act)act.insertBefore(bar.firstChild,act.firstChild);}
            });
            function check(s){
                var fields=$$('input,select,textarea',s).filter(function(i){return !i.disabled&&i.type!=='hidden'&&!closestHidden(i);});
                if(s.getAttribute('data-step')==='colleagues'){var ce=colleagueError();var first=rows()[0]?$('input',rows()[0]):$('input[name=add_colleagues]');if(ce&&first){first.setCustomValidity(ce);first.reportValidity();setTimeout(function(){first.setCustomValidity('');},0);return false;}}
                for(var i=0;i<fields.length;i++){if(!fields[i].checkValidity()){fields[i].reportValidity();return false;}}
                return true;
            }
            function closestHidden(el){while(el&&el!==form){if(el.hidden)return true;el=el.parentNode;}return false;}
            function go(i){cur=i;steps.forEach(function(s,j){s.hidden=j!==i;});dots.forEach(function(d,j){d.className=j===i?'is-current':(j<i?'is-done':'');});if(i===steps.length-1)summary();var top=wrap||form;if(top.scrollIntoView&&i>=0)top.scrollIntoView({behavior:'smooth',block:'start'});}
            var gated=false;
            form.addEventListener('submit',function(e){
                for(var i=0;i<steps.length;i++){if(!check(steps[i])){e.preventDefault();go(i);setTimeout(function(){check(steps[cur]);},50);return;}}
                if(!cfg.loggedIn&&codeBox&&!gated&&verified!==curEmail()){
                    e.preventDefault();var d=0;steps.forEach(function(s,j){if(s.getAttribute('data-step')==='details')d=j;});
                    if(cur!==d)go(d);gate(function(){gated=true;var btn=$('[data-submit]');if(btn){btn.disabled=true;btn.textContent='Opening secure payment…';}form.submit();});return;
                }
                var btn=$('[data-submit]');if(btn){btn.disabled=true;btn.textContent='Opening secure payment…';}
            });
            // Start where the server found a problem, else at the beginning.
            var start=0;
            if(cfg.hasErrors){for(var k=0;k<steps.length;k++){if($('[aria-invalid=true],.is-invalid',steps[k])){start=k;break;}}}
            steps.forEach(function(s,j){s.hidden=j!==start;});dots.forEach(function(d,j){d.className=j===start?'is-current':(j<start?'is-done':'');});
            summary();
            if(cfg.hasErrors){var n=wrap&&$('[data-errors]',wrap);if(n)n.focus();}
        })();
        </script>
        <?php
    }
}
