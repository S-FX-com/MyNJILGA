<?php
/**
 * Pulls per-member payment data from Paid Memberships Pro tables.
 *
 * Source of truth, when available, for:
 *   - amount_paid    (sum of successful order totals for the current dues year)
 *   - open_balance   (current level's initial_payment minus amount_paid, floored at 0)
 *   - status         ("Paid" | "Partial" | "Unpaid" | "" if no PMPro membership)
 *
 * Keyed by WordPress user_id. FluentCRM exposes user_id on each contact when
 * the contact is linked to a WP user; contacts without a user_id fall back to
 * the FluentCRM custom field values in class-report-data.php.
 *
 * Tables (PMPro creates these with the standard wpdb prefix):
 *   {prefix}pmpro_memberships_users     active membership rows
 *   {prefix}pmpro_membership_orders     order rows (status = "success" when paid)
 *   {prefix}pmpro_membership_levels     level definitions (initial_payment, name)
 *
 * Status enums per PMPro docs:
 *   memberships_users.status: active | admin_cancelled | admin_changed | cancelled | expired
 *   membership_orders.status:  success | pending | cancelled | refunded | error | token | review
 */
class NJILGA_PMPro_Data {

    /**
     * @return bool  True when PMPro's core tables exist in this DB.
     */
    public static function is_available(): bool {
        global $wpdb;
        static $cached = null;
        if ( $cached !== null ) {
            return $cached;
        }
        $table   = $wpdb->prefix . 'pmpro_memberships_users';
        $found   = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        $cached  = ( $found === $table );
        return $cached;
    }

    /**
     * Returns payment data for a single user, or null when no active PMPro
     * membership exists for that user. The caller decides what to do with a
     * null (typically: fall back to FluentCRM custom field values).
     *
     * @return array{level_id:int, level_name:string, expected:float, amount_paid:float, open_balance:float, status:string}|null
     */
    public static function for_user( int $user_id, int $year ): ?array {
        if ( $user_id <= 0 || ! self::is_available() ) {
            return null;
        }

        global $wpdb;

        $level = $wpdb->get_row( $wpdb->prepare(
            "SELECT mu.membership_id AS level_id, ml.name AS level_name, ml.initial_payment
             FROM {$wpdb->prefix}pmpro_memberships_users AS mu
             LEFT JOIN {$wpdb->prefix}pmpro_membership_levels AS ml
                    ON ml.id = mu.membership_id
             WHERE mu.user_id = %d AND mu.status = 'active'
             ORDER BY mu.id DESC
             LIMIT 1",
            $user_id
        ), ARRAY_A );

        if ( ! $level ) {
            return null;
        }

        // Sum successful orders for this user inside the dues year.
        // PMPro stores `timestamp` as a MySQL DATETIME in site timezone.
        $paid = (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(total), 0)
             FROM {$wpdb->prefix}pmpro_membership_orders
             WHERE user_id = %d
               AND status = 'success'
               AND YEAR(timestamp) = %d",
            $user_id,
            $year
        ) );

        $expected = (float) $level['initial_payment'];
        $open     = max( 0.0, $expected - $paid );

        $status = self::derive_status( $expected, $paid );

        return [
            'level_id'     => (int) $level['level_id'],
            'level_name'   => (string) ( $level['level_name'] ?? '' ),
            'expected'     => $expected,
            'amount_paid'  => $paid,
            'open_balance' => $open,
            'status'       => $status,
        ];
    }

    private static function derive_status( float $expected, float $paid ): string {
        if ( $expected <= 0 && $paid <= 0 ) {
            return '';
        }
        if ( $paid <= 0 ) {
            return 'Unpaid';
        }
        if ( $paid + 0.001 < $expected ) {
            return 'Partial';
        }
        return 'Paid';
    }
}
