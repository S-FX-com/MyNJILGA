<?php
/**
 * PMPro Migrator — recreates a past year's PAID dues invoices from Paid
 * Memberships Pro as Dues History records, so the contact and company
 * tabs keep a record of what each member and firm paid before this
 * plugin's own invoicing took over.
 *
 * It reads PMPro's own tables (pmpro_membership_orders and
 * pmpro_membership_levels) directly. PMPro does not have to be active, but
 * its data does have to be in this site's database. It never writes to
 * PMPro and never touches Stripe, the live invoice tables, FluentCRM tags
 * or any WordPress role: what it creates is reference history, nothing
 * more (see MyNJILGA_Dues_History_Table).
 *
 * One PMPro order = one history invoice:
 *   • only orders with status "success" (paid) are migrated; sandbox
 *     (test-gateway) orders never are;
 *   • the order's user is matched to a FluentCRM contact (by linked user,
 *     then by email), and the contact's firm is the invoice's firm;
 *   • an order that can't be matched to a contact is NOT migrated — it
 *     would appear on no tab — and is listed so it can be fixed and the
 *     migration run again;
 *   • each order is keyed by its PMPro id, so running the migration again
 *     (or for an overlapping date range) skips what is already there.
 *
 * map_order() and method_for() are PURE and unit tested in
 * tests/PmproMigratorTest.php; the rest reads the database.
 */
class MyNJILGA_PMPro_Migrator {

    /** Most orders one run migrates; run again for the rest (it skips what's done). */
    const MAX_PER_RUN = 2000;

    /** Most orders one preview loads. */
    const MAX_PREVIEW = 20000;

    // -------------------------------------------------------------------------
    // PURE — one PMPro order → one history row
    // -------------------------------------------------------------------------

    /**
     * Build the history row for a PMPro order.
     *
     * @param array<string,mixed>  $order  A pmpro_membership_orders row, as an array.
     * @param array<int,string>    $levels membership id → level name.
     * @param array{contact_id:int,contact_name:string,contact_email:string,company_id:int,company_name:string,dues_year:int,batch_id:string,user_id:int} $ctx
     *        dues_year 0 = the year the order was placed.
     * @return array<string,mixed> A MyNJILGA_Dues_History_Table::insert() row.
     */
    public static function map_order( array $order, array $levels, array $ctx ): array {
        $stamp = (string) ( $order['timestamp'] ?? '' );
        $day   = MyNJILGA_Dues_History::date_part( $stamp );
        $year  = (int) $ctx['dues_year'] > 0 ? (int) $ctx['dues_year'] : (int) substr( $day, 0, 4 );

        $total  = self::cents( $order['total'] ?? '' );
        $sub    = self::cents( $order['subtotal'] ?? '' );
        $tax    = self::cents( $order['tax'] ?? '' );
        $coupon = self::cents( $order['couponamount'] ?? '' );

        $levelId = (int) ( $order['membership_id'] ?? 0 );
        $level   = (string) ( $levels[ $levelId ] ?? '' );
        if ( $level === '' ) {
            $level = $levelId > 0 ? 'Membership level #' . $levelId : 'Membership';
        }
        $title = $year . ' ' . $level;

        $contactId = (int) $ctx['contact_id'];
        $lines     = self::lines( $title, $sub, $tax, $coupon, $total, $contactId );

        [ $method, $detail ] = self::method_for( $order );
        $code      = trim( (string) ( $order['code'] ?? '' ) );
        $txn       = trim( (string) ( $order['payment_transaction_id'] ?? '' ) );
        $orderId   = (int) ( $order['id'] ?? 0 );

        $notes = 'Recreated from Paid Memberships Pro order #' . $orderId . ( $code !== '' ? ' (' . $code . ')' : '' ) . '.';
        $own   = trim( (string) ( $order['notes'] ?? '' ) );
        if ( $own !== '' ) {
            $notes .= "\nPMPro note: " . $own;
        }

        return [
            'source'         => MyNJILGA_Dues_History_Table::SOURCE_PMPRO,
            'source_ref'     => (string) $orderId,
            'batch_id'       => (string) $ctx['batch_id'],
            'dues_year'      => $year,
            'company_id'     => (int) $ctx['company_id'],
            'company_name'   => (string) $ctx['company_name'],
            'contact_id'     => $contactId,
            'contact_name'   => (string) $ctx['contact_name'],
            'contact_email'  => (string) $ctx['contact_email'],
            'member_ids'     => MyNJILGA_Dues_History::member_ids_column( [ $contactId ] ),
            'invoice_number' => $code !== '' ? $code : (string) $orderId,
            'description'    => $title,
            'line_items'     => (string) json_encode( $lines ),
            'status'         => MyNJILGA_Dues_History_Table::STATUS_PAID,
            'total_cents'    => $total,
            'paid_cents'     => $total,
            'invoice_date'   => $day,
            'due_date'       => '',
            'paid_at'        => $stamp,
            'method'         => $method,
            'method_detail'  => $detail,
            'reference'      => $txn !== '' ? $txn : $code,
            'notes'          => $notes,
            'created_by'     => (int) $ctx['user_id'],
        ];
    }

    /**
     * The invoice's lines, always adding up to the order total: the
     * subtotal, a discount line when a coupon was used, tax when charged,
     * and an "Adjustment" for any difference PMPro's own figures leave
     * (so a line total can never disagree with the amount that was paid).
     *
     * @return array<int,array{title:string,amount:int,contact_id:int}>
     */
    public static function lines( string $title, int $sub, int $tax, int $coupon, int $total, int $contactId ): array {
        $lines = [];
        if ( $sub > 0 ) {
            $lines[] = [ 'title' => $title, 'amount' => $sub, 'contact_id' => $contactId ];
        }
        if ( $coupon > 0 ) {
            $lines[] = [ 'title' => 'Discount', 'amount' => -$coupon, 'contact_id' => $contactId ];
        }
        if ( $tax > 0 ) {
            $lines[] = [ 'title' => 'Tax', 'amount' => $tax, 'contact_id' => $contactId ];
        }
        $sum = array_sum( array_column( $lines, 'amount' ) );
        if ( ! $lines ) {
            return [ [ 'title' => $title, 'amount' => $total, 'contact_id' => $contactId ] ];
        }
        if ( $sum !== $total ) {
            $lines[] = [ 'title' => 'Adjustment', 'amount' => $total - $sum, 'contact_id' => $contactId ];
        }
        return $lines;
    }

    /**
     * How the order was paid — [ method, detail ] — from PMPro's gateway,
     * payment type and card fields. Detail is "Visa ••4242" for a card.
     *
     * @param array<string,mixed> $order
     * @return array{0:string,1:string}
     */
    public static function method_for( array $order ): array {
        $gateway = strtolower( trim( (string) ( $order['gateway'] ?? '' ) ) );
        $type    = strtolower( trim( (string) ( $order['payment_type'] ?? '' ) ) );
        $brand   = trim( (string) ( $order['cardtype'] ?? '' ) );
        $acct    = trim( (string) ( $order['accountnumber'] ?? '' ) );

        if ( $gateway === 'check' || strpos( $type, 'check' ) !== false ) {
            return [ 'check', '' ];
        }
        if ( strpos( $gateway, 'paypal' ) !== false || strpos( $type, 'paypal' ) !== false ) {
            return [ 'paypal', '' ];
        }
        $cardGateways = [ 'stripe', 'authorizenet', 'braintree', 'payflowpro', 'cybersource', 'twocheckout', 'paypalpro' ];
        if ( $brand !== '' || $acct !== '' || in_array( $gateway, $cardGateways, true ) || strpos( $type, 'card' ) !== false || strpos( $type, 'credit' ) !== false ) {
            $last4  = preg_match( '/(\d{4})\s*$/', $acct, $m ) ? $m[1] : '';
            $detail = trim( ( $brand !== '' ? ucfirst( strtolower( $brand ) ) : '' ) . ( $last4 !== '' ? ' ••' . $last4 : '' ) );
            return [ 'card', $detail ];
        }
        return [ 'other', '' ];
    }

    /**
     * PMPro stores money as text ("125.00", "", "0"). Whole cents; '' is 0.
     *
     * @param mixed $value
     */
    public static function cents( $value ): int {
        $cents = MyNJILGA_Historical_Import::parse_money( (string) $value );
        return $cents === null ? 0 : $cents;
    }

    /**
     * Clean the migration form's fields. Dates that aren't real dates
     * fall back to last calendar year; a window whose end is before its
     * start is swapped; the dues year is 0 (each order's own year) or a
     * plausible year.
     *
     * @param array<string,mixed> $in         Raw request values.
     * @param array{0:string,1:string} $default [ from, to ] to use for a bad or missing date.
     * @return array{from:string,to:string,year:int,skip_zero:bool}
     */
    public static function parse_opts( array $in, array $default ): array {
        $from = MyNJILGA_Historical_Import::parse_date( (string) ( $in['from'] ?? '' ) );
        $to   = MyNJILGA_Historical_Import::parse_date( (string) ( $in['to'] ?? '' ) );
        $from = $from !== '' ? $from : $default[0];
        $to   = $to !== '' ? $to : $default[1];
        if ( $to < $from ) {
            [ $from, $to ] = [ $to, $from ];
        }
        $year = (int) ( $in['year'] ?? 0 );
        if ( $year < 1990 || $year > 2100 ) {
            $year = 0;
        }
        return [ 'from' => $from, 'to' => $to, 'year' => $year, 'skip_zero' => ! empty( $in['skip_zero'] ) ];
    }

    // -------------------------------------------------------------------------
    // Database
    // -------------------------------------------------------------------------

    public static function orders_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'pmpro_membership_orders';
    }

    public static function levels_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'pmpro_membership_levels';
    }

    /**
     * Whether PMPro's order table is in this database.
     */
    public static function available(): bool {
        global $wpdb;
        static $found = null;
        if ( $found === null ) {
            $found = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', self::orders_table() ) ) === self::orders_table();
        }
        return $found;
    }

    /**
     * Last calendar year, January 1 – December 31, as [ from, to ] —
     * where a "migrate last year's invoices" run starts.
     *
     * @return array{0:string,1:string}
     */
    public static function default_window(): array {
        $y = (int) current_time( 'Y' ) - 1;
        return [ $y . '-01-01', $y . '-12-31' ];
    }

    /**
     * What PMPro holds: paid orders and their total per calendar year, so
     * the right window is easy to see.
     *
     * @return array<int,array{year:int,orders:int,total_cents:int}>
     */
    public static function years(): array {
        global $wpdb;
        $t    = self::orders_table();
        $rows = (array) $wpdb->get_results( // phpcs:ignore
            "SELECT YEAR(`timestamp`) AS y, COUNT(*) AS c, COALESCE(SUM(CAST(total AS DECIMAL(12,2))),0) AS t FROM $t WHERE status = 'success' AND (gateway_environment IS NULL OR gateway_environment <> 'sandbox') GROUP BY YEAR(`timestamp`) ORDER BY y DESC"
        );
        $out = [];
        foreach ( $rows as $r ) {
            $out[] = [ 'year' => (int) $r->y, 'orders' => (int) $r->c, 'total_cents' => (int) round( (float) $r->t * 100 ) ];
        }
        return $out;
    }

    /**
     * Everything in the window by order status, plus the sandbox orders
     * that are never migrated.
     *
     * @return array{by_status:array<string,int>,sandbox:int}
     */
    public static function window_counts( string $from, string $to ): array {
        global $wpdb;
        $t    = self::orders_table();
        $rows = (array) $wpdb->get_results( $wpdb->prepare( // phpcs:ignore
            "SELECT status, COUNT(*) AS c FROM $t WHERE `timestamp` >= %s AND `timestamp` <= %s GROUP BY status",
            $from . ' 00:00:00',
            $to . ' 23:59:59'
        ) );
        $by = [];
        foreach ( $rows as $r ) {
            $by[ (string) $r->status ] = (int) $r->c;
        }
        $sandbox = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore
            "SELECT COUNT(*) FROM $t WHERE status = 'success' AND gateway_environment = 'sandbox' AND `timestamp` >= %s AND `timestamp` <= %s",
            $from . ' 00:00:00',
            $to . ' 23:59:59'
        ) );
        return [ 'by_status' => $by, 'sandbox' => $sandbox ];
    }

    /**
     * @return array<int,string> membership id → level name.
     */
    public static function levels(): array {
        global $wpdb;
        $t   = self::levels_table();
        $out = [];
        if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) !== $t ) {
            return $out;
        }
        foreach ( (array) $wpdb->get_results( "SELECT id, name FROM $t" ) as $r ) { // phpcs:ignore
            $out[ (int) $r->id ] = (string) $r->name;
        }
        return $out;
    }

    /**
     * The paid, non-sandbox orders placed in the window, oldest first.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function paid_orders( string $from, string $to ): array {
        global $wpdb;
        $t    = self::orders_table();
        $rows = (array) $wpdb->get_results( $wpdb->prepare( // phpcs:ignore
            "SELECT * FROM $t WHERE status = 'success' AND (gateway_environment IS NULL OR gateway_environment <> 'sandbox') AND `timestamp` >= %s AND `timestamp` <= %s ORDER BY `timestamp` ASC, id ASC LIMIT %d",
            $from . ' 00:00:00',
            $to . ' 23:59:59',
            self::MAX_PREVIEW
        ), ARRAY_A );
        return $rows;
    }

    /**
     * Plan a migration: every paid order in the window, matched to a
     * contact and firm, with what a run would do with it.
     *
     * $opts: from, to (Y-m-d), year (0 = each order's own year),
     * skip_zero (leave $0 orders out).
     *
     * state: new | duplicate (already migrated) | unmatched (no FluentCRM
     * contact) | zero (a $0 order, skipped by choice).
     *
     * @param array{from:string,to:string,year:int,skip_zero:bool} $opts
     * @return array{rows:array<int,array<string,mixed>>,counts:array<string,int>,truncated:bool}
     */
    public static function plan( array $opts ): array {
        $orders    = self::paid_orders( $opts['from'], $opts['to'] );
        $levels    = self::levels();
        $existing  = MyNJILGA_Dues_History_Table::existing_refs( MyNJILGA_Dues_History_Table::SOURCE_PMPRO, array_map( static function ( $o ) {
            return (string) $o['id'];
        }, $orders ) );
        $contacts  = [];
        $rows      = [];
        $counts    = [ 'new' => 0, 'duplicate' => 0, 'unmatched' => 0, 'zero' => 0, 'no_firm' => 0 ];

        foreach ( $orders as $o ) {
            $userId = (int) $o['user_id'];
            if ( ! array_key_exists( $userId, $contacts ) ) {
                $contacts[ $userId ] = self::contact_for_user( $userId );
            }
            $c = $contacts[ $userId ];

            if ( ! empty( $opts['skip_zero'] ) && self::cents( $o['total'] ?? '' ) <= 0 ) {
                $state  = 'zero';
                $reason = 'A $0 order.';
            } elseif ( isset( $existing[ (string) $o['id'] ] ) ) {
                $state  = 'duplicate';
                $reason = 'Already migrated.';
            } elseif ( $c === null ) {
                $state  = 'unmatched';
                $reason = self::unmatched_reason( $userId );
            } else {
                $state  = 'new';
                $reason = $c['company_id'] > 0 ? '' : 'No firm on this contact — it will show on the contact tab only.';
            }
            $counts[ $state ]++;
            if ( $state === 'new' && $c['company_id'] <= 0 ) {
                $counts['no_firm']++;
            }

            $rows[] = [
                'order'  => $o,
                'state'  => $state,
                'reason' => $reason,
                'contact'=> $c,
                'row'    => $c === null ? null : self::map_order( $o, $levels, [
                    'contact_id'    => $c['id'],
                    'contact_name'  => $c['name'],
                    'contact_email' => $c['email'],
                    'company_id'    => $c['company_id'],
                    'company_name'  => $c['company_name'],
                    'dues_year'     => (int) $opts['year'],
                    'batch_id'      => '',
                    'user_id'       => 0,
                ] ),
            ];
        }
        return [ 'rows' => $rows, 'counts' => $counts, 'truncated' => count( $orders ) >= self::MAX_PREVIEW ];
    }

    /**
     * Migrate the plan's new orders.
     *
     * @param array{from:string,to:string,year:int,skip_zero:bool} $opts
     * @return array{created:int,failed:int,remaining:int,batch:string}
     */
    public static function run( array $opts, int $userId ): array {
        $plan    = self::plan( $opts );
        $batch   = MyNJILGA_Historical_Import::batch_id( MyNJILGA_Dues_History_Table::SOURCE_PMPRO );
        $created = 0;
        $failed  = 0;
        $left    = 0;

        foreach ( $plan['rows'] as $p ) {
            if ( $p['state'] !== 'new' || ! $p['row'] ) {
                continue;
            }
            if ( $created + $failed >= self::MAX_PER_RUN ) {
                $left++;
                continue;
            }
            $row               = $p['row'];
            $row['batch_id']   = $batch;
            $row['created_by'] = $userId;
            if ( MyNJILGA_Dues_History_Table::insert( $row ) > 0 ) {
                $created++;
            } else {
                $failed++;
            }
        }
        return [ 'created' => $created, 'failed' => $failed, 'remaining' => $left, 'batch' => $created > 0 ? $batch : '' ];
    }

    /**
     * The FluentCRM contact (and firm) for a PMPro order's user: the
     * contact linked to that WordPress user, else the one with the user's
     * email.
     *
     * @return array{id:int,name:string,email:string,company_id:int,company_name:string}|null
     */
    private static function contact_for_user( int $userId ): ?array {
        if ( $userId <= 0 ) {
            return null;
        }
        $user = get_userdata( $userId );
        if ( ! $user ) {
            return null;
        }
        $contact = \FluentCrm\App\Models\Subscriber::where( 'user_id', $userId )->first();
        if ( ! $contact && (string) $user->user_email !== '' ) {
            $contact = \FluentCrm\App\Models\Subscriber::where( 'email', (string) $user->user_email )->first();
        }
        if ( ! $contact ) {
            return null;
        }
        $companyId   = (int) $contact->company_id;
        $companyName = '';
        if ( $companyId > 0 && MyNJILGA_Members_Data::companies_module_active() ) {
            $company = \FluentCrm\App\Models\Company::find( $companyId );
            if ( $company ) {
                $companyName = (string) $company->name;
            } else {
                $companyId = 0;
            }
        }
        return [
            'id'           => (int) $contact->id,
            'name'         => MyNJILGA_Members_Data::display_name( $contact ),
            'email'        => (string) $contact->email,
            'company_id'   => $companyId,
            'company_name' => $companyName,
        ];
    }

    private static function unmatched_reason( int $userId ): string {
        if ( $userId <= 0 ) {
            return 'The order has no website user.';
        }
        $user = get_userdata( $userId );
        if ( ! $user ) {
            return 'Website user #' . $userId . ' no longer exists.';
        }
        return 'No FluentCRM contact for ' . $user->user_email . '.';
    }
}
