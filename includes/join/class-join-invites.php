<?php
/**
 * Invitations for the colleagues a paying member covered in an online
 * join. By the time one is sent, the colleague is already a paid, tagged
 * member of the firm (MyNJILGA_Join_Fulfillment) — the invite is only the
 * way in to a website account of their own.
 *
 *   - Already has a WordPress account (by email): nothing to create. They
 *     are told to log in; settle() has already granted their role.
 *   - Otherwise: a single-use link (a 192-bit token; only its hash is
 *     stored) back to the join page, where [njilga_join] shows the
 *     "create your account" form instead of the join form.
 */
class MyNJILGA_Join_Invites {

    const QUERY_ARG = 'njilga_invite';

    /**
     * @param array{contact_id:int,email:string,first_name:string,last_name:string} $person
     */
    public static function send( object $join, array $person, string $companyName ): void {
        $email = strtolower( (string) $person['email'] );
        $payer = trim( $join->first_name . ' ' . $join->last_name );
        $year  = (int) $join->dues_year;
        $user  = get_user_by( 'email', $email );

        if ( $user ) {
            self::link_contact_to_user( (int) $person['contact_id'], (int) $user->ID );
            MyNJILGA_Join_Invites_Table::issue( (int) $join->id, $person, null, MyNJILGA_Join_Invites_Table::STATUS_EXISTING_ACCOUNT, '' );
            MyNJILGA_Join_Fulfillment::join_mail(
                $join,
                $email,
                sprintf( 'Your %d NJILGA membership is active', $year ),
                sprintf(
                    "Hi %s,\n\n%s has covered your %d NJILGA membership%s — you're all set.\n\nYou already have an account on the NJILGA website, so just log in as usual:\n%s\n\nWelcome,\nNJILGA",
                    $person['first_name'] !== '' ? $person['first_name'] : 'there',
                    $payer,
                    $year,
                    $companyName !== '' ? ' as a member of ' . $companyName : '',
                    wp_login_url()
                )
            );
            return;
        }

        $token   = MyNJILGA_Join_Invites_Table::new_token();
        $days    = max( 1, (int) MyNJILGA_Dues_Settings::general( 'join_invite_expiry_days', 30 ) );
        $expires = gmdate( 'Y-m-d H:i:s', (int) current_time( 'timestamp' ) + $days * 86400 );
        $issued  = MyNJILGA_Join_Invites_Table::issue( (int) $join->id, $person, MyNJILGA_Join_Invites_Table::hash_token( $token ), MyNJILGA_Join_Invites_Table::STATUS_SENT, $expires );
        if ( $issued['status'] !== MyNJILGA_Join_Invites_Table::STATUS_SENT ) {
            return; // They already used an earlier link, or are using it right now.
        }

        MyNJILGA_Join_Fulfillment::join_mail(
            $join,
            $email,
            sprintf( '%s has made you an NJILGA member — create your account', $payer ),
            sprintf(
                "Hi %s,\n\n%s has covered your %d NJILGA membership%s. Your membership is already active.\n\nCreate your NJILGA website account here (the link is just for you and works once, for %d days):\n%s\n\nWelcome,\nNJILGA",
                $person['first_name'] !== '' ? $person['first_name'] : 'there',
                $payer,
                $year,
                $companyName !== '' ? ' as a member of ' . $companyName : '',
                $days,
                self::accept_url( $join, $token )
            )
        );
    }

    /**
     * Re-send every invite on a join that hasn't been used yet, each with
     * a new link (the old ones stop working).
     *
     * @return int How many went out.
     */
    public static function resend( int $joinId ): int {
        $join = MyNJILGA_Join_Orders_Table::get( $joinId );
        if ( ! $join || $join->status !== MyNJILGA_Join_Orders_Table::STATUS_FULFILLED ) {
            return 0;
        }
        $companyName = '';
        if ( (int) $join->company_id > 0 && MyNJILGA_Members_Data::companies_module_active() ) {
            $company     = \FluentCrm\App\Models\Company::find( (int) $join->company_id );
            $companyName = $company ? (string) $company->name : '';
        }
        $sent = 0;
        foreach ( MyNJILGA_Join_Invites_Table::get_for_join( $joinId ) as $invite ) {
            if ( $invite->status === MyNJILGA_Join_Invites_Table::STATUS_ACCEPTED || MyNJILGA_Join_Invites_Table::is_being_claimed( $invite ) ) {
                continue;
            }
            self::send( $join, [
                'contact_id' => (int) $invite->contact_id,
                'email'      => (string) $invite->email,
                'first_name' => (string) $invite->first_name,
                'last_name'  => (string) $invite->last_name,
            ], $companyName );
            $sent++;
        }
        return $sent;
    }

    public static function accept_url( object $join, string $token ): string {
        $base = (string) $join->source_url;
        if ( $base === '' || ! wp_http_validate_url( $base ) ) {
            $base = home_url( '/' );
        }
        return add_query_arg( self::QUERY_ARG, $token, $base );
    }

    /**
     * The usable invite behind a token, or why there isn't one. 'busy' is
     * true only while an account is being created through it this moment
     * — the one failure that passes by itself within seconds (every other
     * one is for good, and the join form treats the link as spent).
     *
     * @return array{invite:?object,join:?object,error:string,busy:bool}
     */
    public static function lookup( string $token ): array {
        $invite = MyNJILGA_Join_Invites_Table::get_by_token( $token );
        if ( $invite && MyNJILGA_Join_Invites_Table::is_being_claimed( $invite ) ) {
            return [ 'invite' => null, 'join' => null, 'error' => self::claimed_message(), 'busy' => true ];
        }
        // A stale claim (its request died) is still a usable invite:
        // accept() can take it over.
        if ( ! $invite || ! in_array( $invite->status, [ MyNJILGA_Join_Invites_Table::STATUS_SENT, MyNJILGA_Join_Invites_Table::STATUS_CLAIMING ], true ) ) {
            return [ 'invite' => null, 'join' => null, 'error' => 'This invitation link is not valid any more — it may already have been used, or a newer one sent. If you already created your account, just log in.', 'busy' => false ];
        }
        if ( MyNJILGA_Join_Invites_Table::is_expired( $invite ) ) {
            return [ 'invite' => null, 'join' => null, 'error' => 'This invitation link has expired. Please ask NJILGA to send you a new one.', 'busy' => false ];
        }
        $join = MyNJILGA_Join_Orders_Table::get( (int) $invite->join_id );
        if ( ! $join ) {
            return [ 'invite' => null, 'join' => null, 'error' => 'This invitation is no longer valid. Please contact NJILGA.', 'busy' => false ];
        }
        return [ 'invite' => $invite, 'join' => $join, 'error' => '', 'busy' => false ];
    }

    /**
     * The role a joiner's new account gets: Subscriber when the site has
     * it, and otherwise none at all — never the "New User Default Role",
     * which someone may have raised to a member role for another purpose,
     * and this account has paid for nothing yet. The membership role is
     * added on top by the dues role sync once payment is confirmed.
     */
    public static function new_account_role(): string {
        return get_role( 'subscriber' ) ? 'subscriber' : '';
    }

    /**
     * Create the colleague's account from their accepted invite.
     *
     * @param array<string,string> $in Validated form answers: username, password, first_name, last_name,
     *                                 plus optional profile fields (phone, address…, attorney_id, …).
     * @return array{ok:bool,error:string,user_id:int}
     */
    public static function accept( object $invite, string $token, array $in ): array {
        $email = (string) $invite->email;
        if ( email_exists( $email ) ) {
            return [ 'ok' => false, 'error' => 'An account with this email already exists — please log in instead.', 'user_id' => 0 ];
        }

        // Single use — claimed before the account exists, so of two
        // submits at once only one ever creates a user. (Creating first
        // and deleting the loser's user afterwards could make FluentCRM
        // delete the colleague's paid contact along with it.)
        $hash = MyNJILGA_Join_Invites_Table::hash_token( $token );
        if ( ! MyNJILGA_Join_Invites_Table::claim( (int) $invite->id, $hash ) ) {
            return [ 'ok' => false, 'error' => self::claimed_message(), 'user_id' => 0 ];
        }

        $userId = wp_insert_user( [
            'user_login'   => $in['username'],
            'user_pass'    => $in['password'],
            'user_email'   => $email,
            'first_name'   => $in['first_name'],
            'last_name'    => $in['last_name'],
            'display_name' => trim( $in['first_name'] . ' ' . $in['last_name'] ),
            'role'         => self::new_account_role(),
        ] );
        if ( is_wp_error( $userId ) ) {
            MyNJILGA_Join_Invites_Table::release_claim( (int) $invite->id, $hash );
            return [ 'ok' => false, 'error' => $userId->get_error_message(), 'user_id' => 0 ];
        }
        $userId = (int) $userId;

        // Ours since the claim. This only misses if the claim went stale
        // and was taken over mid-request; the account stands either way —
        // it is the invitee's own, made through their link.
        MyNJILGA_Join_Invites_Table::mark_accepted( (int) $invite->id, $hash, $userId );

        $contactId = (int) $invite->contact_id;
        if ( $contactId > 0 && MyNJILGA_Members_Data::fluentcrm_active() && function_exists( 'FluentCrmApi' ) ) {
            try {
                $fields = [
                    'email'      => $email,
                    'first_name' => $in['first_name'],
                    'last_name'  => $in['last_name'],
                ];
                foreach ( [ 'phone', 'address_line_1', 'address_line_2', 'city', 'state', 'postal_code', 'country' ] as $k ) {
                    if ( (string) ( $in[ $k ] ?? '' ) !== '' ) {
                        $fields[ $k ] = (string) $in[ $k ];
                    }
                }
                if ( isset( $fields['phone'] ) ) {
                    $fields['phone'] = MyNJILGA_Phone::for_crm( $fields['phone'] ); // +1 ###-###-####
                }
                $custom = [];
                $map    = MyNJILGA_Dues_Settings::join_custom_fields();
                $known  = MyNJILGA_Join_Fulfillment::fluentcrm_custom_field_slugs();
                foreach ( [ 'attorney_id', 'bar_admission_date', 'municipality', 'nj_county', 'mailing_phone' ] as $k ) {
                    $slug = (string) ( $map[ $k ] ?? '' );
                    if ( $slug !== '' && in_array( $slug, $known, true ) && (string) ( $in[ $k ] ?? '' ) !== '' ) {
                        $custom[ $slug ] = $k === 'mailing_phone' ? MyNJILGA_Phone::for_crm( (string) $in[ $k ] ) : (string) $in[ $k ];
                    }
                }
                if ( $custom ) {
                    $fields['custom_values'] = $custom;
                }
                FluentCrmApi( 'contacts' )->createOrUpdate( $fields );
                self::link_contact_to_user( $contactId, $userId );

                // Their membership was paid before the account existed, so
                // the role settle() couldn't grant then is granted now —
                // but only while they are still current.
                $contact  = \FluentCrm\App\Models\Subscriber::find( $contactId );
                $category = MyNJILGA_Dues_Settings::category( (string) MyNJILGA_Join_Orders_Table::get( (int) $invite->join_id )->category_key );
                if ( $contact && $category && MyNJILGA_Tags::has_slug( $contact, (string) MyNJILGA_Dues_Settings::general( 'paid_tag', 'dues-paid' ) ) && MyNJILGA_Payment_Listener::paid_for_current_year( $contact ) ) {
                    MyNJILGA_Payment_Listener::grant_role( $contact, (string) $category['role'] );
                }
            } catch ( \Throwable $e ) {
                // The account exists and the invite is spent; a CRM hiccup
                // here is staff's to tidy, not a reason to fail the person.
            }
        }

        return [ 'ok' => true, 'error' => '', 'user_id' => $userId ];
    }

    private static function claimed_message(): string {
        return 'This invitation is being used right now. If you just sent the form, your account is being set up — log in with the username and password you chose in a moment.';
    }

    private static function link_contact_to_user( int $contactId, int $userId ): void {
        if ( $contactId <= 0 || $userId <= 0 || ! class_exists( '\\FluentCrm\\App\\Models\\Subscriber' ) ) {
            return;
        }
        $contact = \FluentCrm\App\Models\Subscriber::find( $contactId );
        if ( $contact && empty( $contact->user_id ) ) {
            $contact->user_id = $userId;
            $contact->save();
        }
    }
}
