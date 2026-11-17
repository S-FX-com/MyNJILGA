<?php
/**
 * Paces invoice emails to what the site's mail service allows.
 *
 * Mail services limit how fast they accept messages — Emailit, for one, allows 2 per
 * second and answers anything faster with "Too many requests", which WordPress sees as
 * wp_mail() failing. Nothing here used to pause between emails: the Launch Wizard's
 * scheduled batches (25 by default) and the Invoicing page's background email runs
 * (10 per job) sent back to back, so after the first couple in a second every email
 * was refused, counted as a mail failure, and a run of five halted the schedule.
 *
 * Every real invoice email now takes a "slot" first (MyNJILGA_Invoice_Sender::send_for_row()).
 * A slot is the earliest moment this email may go out: at least one interval after the
 * previous email's slot, whoever sent it. The slots are handed out through ONE shared
 * record, claimed with a compare-and-set, so a scheduled batch, several background
 * jobs running at once and a hand-sent invoice all draw on the same budget — pacing
 * each loop separately would not hold when they overlap. Whoever holds a later slot
 * simply waits for it.
 *
 * The speed is a setting (Settings → Invoice Email → Sending speed, emails per second),
 * default 1: half Emailit's limit, leaving room for the site's other mail, which counts
 * against the same allowance. The `my_njilga_mail_interval` filter overrides the gap (in
 * seconds) and `my_njilga_mail_retry_pause` the pause before a retry.
 *
 * Pure rules (per_second(), next_slot()) are unit-tested; wait() is exercised against a
 * rate-limiting mail stub in the simulated-Stripe harness.
 */
class MyNJILGA_Mail_Pacer {

    /** The shared record: the slot most recently handed out (a Unix time in seconds, as a decimal string). */
    const OPTION = 'njilga_mail_last_slot';

    const DEFAULT_PER_SECOND = 1.0;
    const MIN_PER_SECOND     = 0.1;
    const MAX_PER_SECOND     = 10.0;

    /** Never sleep longer than this for one slot (and a recorded slot further ahead than this is treated as stale). */
    const MAX_WAIT = 30.0;

    /** How long to wait before the one retry of an email the mail system refused. */
    const RETRY_PAUSE = 2.0;

    // -------------------------------------------------------------------------
    // PURE
    // -------------------------------------------------------------------------

    /**
     * The emails-per-second a stored or typed value means: a number within bounds, else the default.
     *
     * @param mixed $raw
     */
    public static function per_second( $raw ): float {
        if ( ! is_numeric( $raw ) ) {
            return self::DEFAULT_PER_SECOND;
        }
        $n = (float) $raw;
        if ( $n < self::MIN_PER_SECOND ) {
            return $n <= 0 ? self::DEFAULT_PER_SECOND : self::MIN_PER_SECOND;
        }
        return $n > self::MAX_PER_SECOND ? self::MAX_PER_SECOND : $n;
    }

    /** A speed as it is stored and shown: "1", "0.5", "2.5". */
    public static function format( float $perSecond ): string {
        return rtrim( rtrim( sprintf( '%.2F', $perSecond ), '0' ), '.' );
    }

    /**
     * The slot the next email may go out at: now if the gap since the last slot has passed,
     * else one interval after it. A recorded slot far in the future (a clock that jumped
     * back) is stale and ignored, so it can never hold up sending for long.
     */
    public static function next_slot( float $last, float $now, float $interval ): float {
        if ( $last > $now + self::MAX_WAIT ) {
            $last = 0.0;
        }
        return max( $now, $last + max( 0.0, $interval ) );
    }

    // -------------------------------------------------------------------------
    // Settings
    // -------------------------------------------------------------------------

    /** The gap between two emails, in seconds. 0 = no pacing (the filter can say so). */
    public static function interval(): float {
        $perSecond = self::per_second( MyNJILGA_Dues_Settings::general( 'email_per_second', self::DEFAULT_PER_SECOND ) );
        $gap       = 1.0 / $perSecond;
        return max( 0.0, (float) apply_filters( 'my_njilga_mail_interval', $gap ) );
    }

    // -------------------------------------------------------------------------
    // Waiting for a slot
    // -------------------------------------------------------------------------

    /**
     * Take the next slot and wait for it. Returns how long it waited, in seconds.
     *
     * The slot is claimed by compare-and-set on one options row, so two senders can never be
     * given the same one; when the claim is lost the record is read again and the next slot
     * tried. If the bookkeeping keeps losing (heavy contention) the email is not held up for it.
     */
    public static function wait(): float {
        $interval = self::interval();
        if ( $interval <= 0 ) {
            return 0.0;
        }
        global $wpdb;
        for ( $try = 0; $try < 8; $try++ ) {
            $now  = microtime( true );
            $raw  = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::OPTION ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $last = ( $raw === null || $raw === false ) ? 0.0 : (float) $raw;
            $slot = self::next_slot( $last, $now, $interval );
            $val  = sprintf( '%.4F', $slot );
            if ( $raw === null || $raw === false ) {
                $won = (int) $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'no' )", self::OPTION, $val ) ) === 1; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            } else {
                $won = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $val, self::OPTION, (string) $raw ) ) === 1; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            }
            if ( ! $won ) {
                continue;
            }
            $sleep = min( $slot - $now, self::MAX_WAIT );
            if ( $sleep > 0 ) {
                usleep( (int) round( $sleep * 1000000 ) );
                return $sleep;
            }
            return 0.0;
        }
        return 0.0;
    }

    /** Pause before the one retry of an email the mail system refused. */
    public static function pause_before_retry(): void {
        $pause = max( 0.0, (float) apply_filters( 'my_njilga_mail_retry_pause', self::RETRY_PAUSE ) );
        if ( $pause > 0 ) {
            usleep( (int) round( min( $pause, self::MAX_WAIT ) * 1000000 ) );
        }
    }
}
