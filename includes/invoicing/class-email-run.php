<?php
/**
 * Email a batch of created invoices to their firms, in the background.
 *
 * Invoicing's "Generate emails" review screen hands the invoices staff
 * confirmed to queue(). They go out through MyNJILGA_Invoice_Sender —
 * the same compose() the review screen previewed and the test email used,
 * so what was read is what is sent — in Action Scheduler chunks, so a
 * batch of 160 neither times out nor ties up the page. Staff can leave;
 * the Invoicing page shows a progress card (MyNJILGA_Create_Progress::card())
 * that checks back every few seconds.
 *
 * Never emailed twice: each job re-reads every row just before sending.
 * An invoice not yet emailed (status created) is sent. One already emailed
 * (sent) is re-sent only when staff ticked "email again" for it on the
 * review screen AND it hasn't been sent since this run was queued — so a
 * job that runs twice, or overlaps another, sends it once. Anything that
 * has moved on since (paid, voided…) is left alone.
 *
 * Progress is read from the rows, as for creation: queue() stamps
 * queued_at (and clears last_error) on each; a successful send stamps
 * sent_at and clears queued_at; a failure records last_error (which clears
 * queued_at). One run per dues year and Stripe mode; queuing more while
 * one is going joins it.
 */
class MyNJILGA_Email_Run {

    const HOOK   = 'njilga_dues_email_chunk';
    const OPTION = 'njilga_email_runs';
    const AJAX   = 'njilga_email_progress';

    /** Emails per background job — small, so one slow mail server can't run a job past PHP's time limit. */
    const CHUNK = 10;

    public static function register(): void {
        add_action( self::HOOK, [ __CLASS__, 'run_chunk' ], 10, 4 );
        add_action( 'wp_ajax_' . self::AJAX, [ __CLASS__, 'ajax' ] );
    }

    // -------------------------------------------------------------------------
    // Which invoices can be emailed
    // -------------------------------------------------------------------------

    /**
     * PURE: why this row can't be emailed from a batch for $year in this
     * mode ('' when it can). Only a created or sent invoice of that year
     * and mode with a payment link — never an online join.
     */
    public static function refusal( object $row, int $year, bool $live ): string {
        if ( (int) ( $row->dues_year ?? 0 ) !== $year || (bool) ( $row->livemode ?? false ) !== $live ) {
            return 'Not a ' . $year . ' invoice in this Stripe mode.';
        }
        if ( (string) ( $row->invoice_kind ?? '' ) === MyNJILGA_Dues_Snapshot::KIND_JOIN ) {
            return 'An online join — already paid, nothing to email.';
        }
        $status = (string) ( $row->status ?? '' );
        if ( ! in_array( $status, [ MyNJILGA_Dues_Invoice_Table::STATUS_CREATED, MyNJILGA_Dues_Invoice_Table::STATUS_SENT ], true ) ) {
            return 'Its status is now "' . $status . '" — only an invoice awaiting payment is emailed.';
        }
        if ( (string) ( $row->hosted_invoice_url ?? '' ) === '' ) {
            return 'No payment link on file — run Sync with Stripe for this invoice.';
        }
        return '';
    }

    /**
     * PURE: sort a selection for the review screen.
     *
     * @param array<int,object> $rows
     * @return array{unsent:array<int,object>,sent:array<int,object>,refused:array<int,array{row:object,reason:string}>}
     */
    public static function sort_selection( array $rows, int $year, bool $live ): array {
        $out = [ 'unsent' => [], 'sent' => [], 'refused' => [] ];
        foreach ( $rows as $row ) {
            $why = self::refusal( $row, $year, $live );
            if ( $why !== '' ) {
                $out['refused'][] = [ 'row' => $row, 'reason' => $why ];
            } elseif ( (string) $row->status === MyNJILGA_Dues_Invoice_Table::STATUS_SENT ) {
                $out['sent'][] = $row;
            } else {
                $out['unsent'][] = $row;
            }
        }
        return $out;
    }

    /**
     * PURE: whether a job should send this row now.
     *
     * @param array<int,int> $resendIds Rows staff chose to email again.
     * @param string         $since     When the run was queued (site time).
     */
    public static function should_send( object $row, array $resendIds, string $since ): bool {
        $status = (string) ( $row->status ?? '' );
        if ( (string) ( $row->hosted_invoice_url ?? '' ) === '' || (string) ( $row->invoice_kind ?? '' ) === MyNJILGA_Dues_Snapshot::KIND_JOIN ) {
            return false;
        }
        if ( $status === MyNJILGA_Dues_Invoice_Table::STATUS_CREATED ) {
            return true;
        }
        if ( $status === MyNJILGA_Dues_Invoice_Table::STATUS_SENT ) {
            $sentAt = (string) ( $row->sent_at ?? '' );
            return in_array( (int) $row->id, array_map( 'intval', $resendIds ), true ) && ( $sentAt === '' || $sentAt < $since );
        }
        return false;
    }

    // -------------------------------------------------------------------------
    // Queue and run
    // -------------------------------------------------------------------------

    /**
     * Queue these invoices for emailing. Each must pass refusal(); one
     * already sent is only included when it is in $resendIds.
     *
     * @param array<int,int> $ids
     * @param array<int,int> $resendIds
     * @return array{ok:bool,error:string,queued:int}
     */
    public static function queue( array $ids, array $resendIds, int $year, bool $live ): array {
        $out = [ 'ok' => false, 'error' => '', 'queued' => 0 ];
        $resendIds = array_values( array_map( 'intval', $resendIds ) );

        $eligible = [];
        foreach ( MyNJILGA_Dues_Invoice_Table::get_many( array_map( 'intval', $ids ) ) as $row ) {
            if ( self::refusal( $row, $year, $live ) !== '' ) {
                continue;
            }
            if ( (string) $row->status === MyNJILGA_Dues_Invoice_Table::STATUS_SENT && ! in_array( (int) $row->id, $resendIds, true ) ) {
                continue;
            }
            $eligible[] = (int) $row->id;
        }
        if ( ! $eligible ) {
            $out['error'] = 'None of those invoices can be emailed now.';
            return $out;
        }

        $since = current_time( 'mysql' );
        MyNJILGA_Dues_Invoice_Table::mark_queued( $eligible );

        if ( function_exists( 'as_enqueue_async_action' ) ) {
            foreach ( array_chunk( $eligible, self::CHUNK ) as $chunk ) {
                $resend = array_values( array_intersect( $chunk, $resendIds ) );
                if ( ! as_enqueue_async_action( self::HOOK, [ 'row_ids' => $chunk, 'dues_year' => $year, 'resend_ids' => $resend, 'since' => $since ], MyNJILGA_Invoice_Creator::AS_GROUP ) ) {
                    // The scheduler refused — send this chunk right here rather than lose it.
                    self::run_chunk( $chunk, $year, $resend, $since );
                }
            }
        } else {
            self::run_chunk( $eligible, $year, $resendIds, $since );
        }

        self::record( $year, $live, $eligible, $since );
        $out['ok']     = true;
        $out['queued'] = count( $eligible );
        return $out;
    }

    /**
     * Action Scheduler callback (and the fallback). One row at a time,
     * each re-read and re-checked just before it is sent.
     *
     * @param array<int,int>|mixed $rowIds
     * @param array<int,int>|mixed $resendIds
     * @return array{sent:int,failed:int,skipped:int}
     */
    public static function run_chunk( $rowIds, $duesYear = 0, $resendIds = [], $since = '' ): array {
        $out   = [ 'sent' => 0, 'failed' => 0, 'skipped' => 0 ];
        $since = (string) $since !== '' ? (string) $since : current_time( 'mysql' );
        foreach ( (array) $rowIds as $id ) {
            $row = MyNJILGA_Dues_Invoice_Table::get( (int) $id );
            if ( ! $row ) {
                continue;
            }
            if ( ! self::should_send( $row, (array) $resendIds, $since ) ) {
                MyNJILGA_Dues_Invoice_Table::clear_queued( (int) $row->id );
                $out['skipped']++;
                continue;
            }
            try {
                $r = MyNJILGA_Invoice_Sender::send_for_row( $row );
            } catch ( \Throwable $e ) {
                $r = [ 'ok' => false, 'error' => $e->getMessage() ];
            }
            if ( ! empty( $r['ok'] ) ) {
                MyNJILGA_Dues_Invoice_Table::clear_queued( (int) $row->id );
                $out['sent']++;
            } else {
                MyNJILGA_Dues_Invoice_Table::set_error( (int) $row->id, 'Email not sent: ' . (string) ( $r['error'] ?? 'unknown error' ) );
                $out['failed']++;
            }
        }
        return $out;
    }

    // -------------------------------------------------------------------------
    // The run record and its progress
    // -------------------------------------------------------------------------

    private static function key( int $year, bool $live ): string {
        return $year . ':' . ( $live ? 'live' : 'test' );
    }

    private static function record( int $year, bool $live, array $ids, string $since ): void {
        $all = get_option( self::OPTION, [] );
        $all = is_array( $all ) ? $all : [];
        $key = self::key( $year, $live );
        $run = isset( $all[ $key ] ) && is_array( $all[ $key ] ) ? $all[ $key ] : [ 'ids' => [], 'started_at' => $since, 'by' => get_current_user_id() ];
        $run['ids'] = array_values( array_unique( array_merge( array_map( 'intval', (array) $run['ids'] ), array_map( 'intval', $ids ) ) ) );
        $all[ $key ] = $run;
        update_option( self::OPTION, $all, false );
    }

    /** @return array{ids:array<int,int>,started_at:string,by:int}|null */
    public static function get( int $year, bool $live ): ?array {
        $all = get_option( self::OPTION, [] );
        $run = is_array( $all ) ? ( $all[ self::key( $year, $live ) ] ?? null ) : null;
        return is_array( $run ) && ! empty( $run['ids'] ) ? $run : null;
    }

    public static function clear( int $year, bool $live ): void {
        $all = get_option( self::OPTION, [] );
        if ( is_array( $all ) && isset( $all[ self::key( $year, $live ) ] ) ) {
            unset( $all[ self::key( $year, $live ) ] );
            update_option( self::OPTION, $all, false );
        }
    }

    /**
     * PURE: where a run stands, from its rows as they are now.
     *
     *   done     emailed since the run started
     *   failed   the send failed (the error is on the firm's row)
     *   pending  still queued for a background job
     *   skipped  moved on before its turn (paid, voided…) — nothing to send
     *   stalled  queued so long ago its job must have died
     *
     * @param array<int,object> $rows Rows of the run's year and mode.
     * @param array<int,int>    $ids
     * @return array{total:int,done:int,failed:int,pending:int,skipped:int,stalled:int,finished:bool}
     */
    public static function progress( array $rows, array $ids, string $startedAt, string $nowMysql ): array {
        $byId = [];
        foreach ( $rows as $r ) {
            $byId[ (int) $r->id ] = $r;
        }
        $out = [ 'total' => 0, 'done' => 0, 'failed' => 0, 'pending' => 0, 'skipped' => 0, 'stalled' => 0, 'finished' => false ];
        foreach ( $ids as $id ) {
            $row = $byId[ (int) $id ] ?? null;
            if ( ! $row ) {
                continue;
            }
            $out['total']++;
            $sentAt = (string) ( $row->sent_at ?? '' );
            $queued = (string) ( $row->queued_at ?? '' );
            if ( $sentAt !== '' && $sentAt >= $startedAt && $queued === '' ) {
                $out['done']++;
            } elseif ( (string) ( $row->last_error ?? '' ) !== '' ) {
                $out['failed']++;
            } elseif ( MyNJILGA_Invoice_Creator::is_actively_queued( $queued, $nowMysql ) ) {
                $out['pending']++;
            } elseif ( $queued === '' ) {
                $out['skipped']++;
            } else {
                $out['stalled']++;
            }
        }
        $out['finished'] = $out['pending'] === 0;
        return $out;
    }

    public static function current( int $year, bool $live ): ?array {
        $run = self::get( $year, $live );
        if ( ! $run ) {
            return null;
        }
        $rows = MyNJILGA_Dues_Invoice_Table::get_by_year( $year, [], $live );
        return self::progress( $rows, $run['ids'], (string) $run['started_at'], current_time( 'mysql' ) );
    }

    public static function ajax(): void {
        check_ajax_referer( self::AJAX, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( null, 403 );
        }
        $year = isset( $_POST['dues_year'] ) ? (int) $_POST['dues_year'] : 0;
        $live = MyNJILGA_Stripe_Connection::active_mode() === MyNJILGA_Stripe_Connection::MODE_LIVE;
        wp_send_json_success( self::current( $year, $live ) );
    }

    /** The finished run in one sentence. */
    public static function summary( array $p ): string {
        $bad = $p['failed'] + $p['stalled'];
        return sprintf(
            'Finished emailing invoices: %d of %d sent.%s%s',
            $p['done'],
            $p['total'],
            $p['skipped'] > 0 ? sprintf( ' %d skipped — paid or changed before their turn.', $p['skipped'] ) : '',
            $bad > 0 ? sprintf( ' %d could not be sent — each shows why on the firm\'s row, with a Resend button.', $bad ) : ''
        );
    }

    /** The progress card while a run goes; a one-time summary once it has finished. */
    public static function render( int $year, bool $live, string $reloadUrl ): void {
        $p = self::current( $year, $live );
        if ( $p === null ) {
            return;
        }
        if ( $p['finished'] ) {
            self::clear( $year, $live );
            if ( $p['total'] > 0 ) {
                MyNJILGA_Admin_UI::callout( esc_html( self::summary( $p ) ), $p['failed'] + $p['stalled'] > 0 ? 'warning' : 'success' );
            }
            return;
        }
        MyNJILGA_Create_Progress::card( $p, [
            'ajax'      => self::AJAX,
            'year'      => $year,
            'reload'    => $reloadUrl,
            'title'     => sprintf( 'Emailing %d invoice%s in the background', $p['total'], $p['total'] === 1 ? '' : 's' ),
            'verb'      => 'sent',
            'done'      => 'Finished emailing invoices',
            'fail'      => 'could not be sent — each shows why on the firm’s row, with a Resend button.',
            'note'      => 'You can leave this page — the emails carry on going out, and this shows where they have got to when you come back.',
            'lost'      => 'Lost touch with the server — reload this page to see how many have gone out.',
            'reload_as' => 'Show the updated invoices',
        ] );
    }
}
