<?php
/**
 * Progress of a background invoice-creation run, for staff to watch.
 *
 * "Create Invoices" approves the chosen rows and hands them to Action
 * Scheduler in chunks (MyNJILGA_Invoice_Creator::schedule()), so the work
 * carries on whatever page staff move to. This records which rows a run
 * queued, and reads its progress straight from those rows' statuses —
 * nothing is counted as it happens, so a dropped chunk, a retry or a row
 * created from another screen can never leave a counter wrong.
 *
 * The Invoicing page and the Launch Wizard show it as a card that checks
 * back every few seconds (an admin-ajax poll, which also nudges Action
 * Scheduler's queue runner along) and says when the run has finished.
 * One run per dues year and Stripe mode; a second Create while one is
 * running joins it.
 */
class MyNJILGA_Create_Progress {

    /** Option: [ "{year}:{live|test}" => [ 'ids' => int[], 'started_at' => string, 'by' => int ] ]. */
    const OPTION = 'njilga_create_runs';
    const AJAX   = 'njilga_create_progress';

    /** Seconds between checks while a run is going. */
    const POLL_MS = 4000;

    /** Row statuses that mean "an invoice exists for this row now". */
    const DONE = [
        MyNJILGA_Dues_Invoice_Table::STATUS_CREATED,
        MyNJILGA_Dues_Invoice_Table::STATUS_SENT,
        MyNJILGA_Dues_Invoice_Table::STATUS_PROCESSING,
        MyNJILGA_Dues_Invoice_Table::STATUS_PAID,
        MyNJILGA_Dues_Invoice_Table::STATUS_DOWNGRADED,
        MyNJILGA_Dues_Invoice_Table::STATUS_VOIDED,
        MyNJILGA_Dues_Invoice_Table::STATUS_UNCOLLECTIBLE,
    ];

    public static function register(): void {
        add_action( 'wp_ajax_' . self::AJAX, [ __CLASS__, 'ajax' ] );
    }

    private static function key( int $year, bool $live ): string {
        return $year . ':' . ( $live ? 'live' : 'test' );
    }

    /** Note the rows a Create just queued. Joins a run already under way for the same year and mode. */
    public static function record( int $year, bool $live, array $ids ): void {
        $ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
        if ( ! $ids ) {
            return;
        }
        $all = get_option( self::OPTION, [] );
        $all = is_array( $all ) ? $all : [];
        $key = self::key( $year, $live );
        $run = isset( $all[ $key ] ) && is_array( $all[ $key ] ) ? $all[ $key ] : [ 'ids' => [], 'started_at' => current_time( 'mysql' ), 'by' => get_current_user_id() ];
        $run['ids'] = array_values( array_unique( array_merge( array_map( 'intval', (array) $run['ids'] ), $ids ) ) );
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
     *   done     an invoice exists (created, or already further along)
     *   failed   creation failed (the error is on the firm's row), or the
     *            row was sent back to draft to be re-priced
     *   pending  still queued for a background job
     *   stalled  queued so long ago its job must have died — the row is
     *            offered for Create again (MyNJILGA_Invoice_Creator::is_actively_queued())
     *
     * Rows deleted since (a Refresh, a wipe) are left out of the total.
     *
     * @param array<int,object> $rows Rows of the run's year and mode.
     * @param array<int,int>    $ids  The run's row ids.
     * @return array{total:int,done:int,failed:int,pending:int,stalled:int,finished:bool}
     */
    public static function progress( array $rows, array $ids, string $nowMysql ): array {
        $byId = [];
        foreach ( $rows as $r ) {
            $byId[ (int) $r->id ] = $r;
        }
        $out = [ 'total' => 0, 'done' => 0, 'failed' => 0, 'pending' => 0, 'stalled' => 0, 'finished' => false ];
        foreach ( $ids as $id ) {
            $row = $byId[ (int) $id ] ?? null;
            if ( ! $row ) {
                continue;
            }
            $out['total']++;
            $status = (string) $row->status;
            if ( in_array( $status, self::DONE, true ) ) {
                $out['done']++;
            } elseif ( (string) ( $row->last_error ?? '' ) !== '' || $status !== MyNJILGA_Dues_Invoice_Table::STATUS_APPROVED ) {
                $out['failed']++;
            } elseif ( MyNJILGA_Invoice_Creator::is_actively_queued( (string) ( $row->queued_at ?? '' ), $nowMysql ) ) {
                $out['pending']++;
            } else {
                $out['stalled']++;
            }
        }
        $out['finished'] = $out['pending'] === 0;
        return $out;
    }

    /** The run for this year and mode with its progress, or null when there is none. */
    public static function current( int $year, bool $live ): ?array {
        $run = self::get( $year, $live );
        if ( ! $run ) {
            return null;
        }
        $rows = MyNJILGA_Dues_Invoice_Table::get_by_year( $year, [], $live );
        return self::progress( $rows, $run['ids'], current_time( 'mysql' ) ) + [ 'started_at' => (string) $run['started_at'] ];
    }

    /** admin-ajax: the progress of the run the card on screen is showing. */
    public static function ajax(): void {
        check_ajax_referer( self::AJAX, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( null, 403 );
        }
        $year = isset( $_POST['dues_year'] ) ? (int) $_POST['dues_year'] : 0;
        $live = MyNJILGA_Stripe_Connection::active_mode() === MyNJILGA_Stripe_Connection::MODE_LIVE;
        wp_send_json_success( self::current( $year, $live ) );
    }

    /**
     * The card. While the run goes, a progress bar that checks back every
     * few seconds; once it has finished, a one-time summary — showing it
     * is what closes the run, so the next visit starts clean.
     *
     * @param string $reloadUrl Where "Show the new invoices" goes.
     */
    public static function render( int $year, bool $live, string $reloadUrl ): void {
        $p = self::current( $year, $live );
        if ( $p === null ) {
            if ( MyNJILGA_Invoice_Creator::has_pending_jobs() ) {
                MyNJILGA_Admin_UI::callout( '<strong>Invoices are being created in the background.</strong> Reload this page in a moment to see them move to Created. Any failures are recorded on the firm\'s row.', 'info' );
            }
            return;
        }

        if ( $p['finished'] ) {
            self::clear( $year, $live );
            if ( $p['total'] === 0 ) {
                return;
            }
            MyNJILGA_Admin_UI::callout( esc_html( self::summary( $p ) ), $p['failed'] + $p['stalled'] > 0 ? 'warning' : 'success' );
            return;
        }

        $handled = $p['done'] + $p['failed'] + $p['stalled'];
        $pct     = $p['total'] > 0 ? (int) floor( $handled * 100 / $p['total'] ) : 0;
        printf(
            '<div class="njilga-card njilga-card-pad njilga-createprogress" id="njilga-createprogress" data-ajax="%s" data-nonce="%s" data-year="%d" data-poll="%d" data-reload="%s">',
            esc_url( admin_url( 'admin-ajax.php' ) ),
            esc_attr( wp_create_nonce( self::AJAX ) ),
            $year,
            self::POLL_MS,
            esc_url( $reloadUrl )
        );
        printf(
            '<div class="njilga-progress-top"><span class="njilga-progress-label"><span class="njilga-spin">%s</span> <span data-cp="headline">%s</span></span><span class="njilga-money-line" data-cp="counts">%s</span></div>',
            MyNJILGA_Admin_UI::icon( 'refresh' ),
            esc_html( sprintf( 'Creating %d invoice%s in the background', $p['total'], $p['total'] === 1 ? '' : 's' ) ),
            esc_html( self::counts( $p ) )
        );
        printf( '<div class="njilga-progress"><div class="njilga-progress-bar" data-cp="bar" style="width:%d%%"></div></div>', $pct );
        echo '<p class="njilga-help" data-cp="note">You can leave this page — creation carries on, and this shows where it has got to when you come back.</p>';
        echo '<div class="njilga-actions" data-cp="done" hidden></div>';
        echo '</div>';

        self::script();
    }

    /** "42 of 166 created · 1 failed". */
    public static function counts( array $p ): string {
        $out = sprintf( '%d of %d created', $p['done'], $p['total'] );
        if ( $p['failed'] + $p['stalled'] > 0 ) {
            $out .= sprintf( ' · %d failed', $p['failed'] + $p['stalled'] );
        }
        return $out;
    }

    /** The finished run in one sentence. */
    public static function summary( array $p ): string {
        $bad = $p['failed'] + $p['stalled'];
        return sprintf(
            'Finished creating invoices: %d of %d created.%s',
            $p['done'],
            $p['total'],
            $bad > 0 ? sprintf( ' %d could not be created — each shows its error on the firm\'s row (Needs Attention), with a Retry button.', $bad ) : ''
        );
    }

    /**
     * Polls while the card is on screen. The bar's width is its one inline
     * style, as on every njilga-progress bar: it is data, not design.
     */
    private static function script(): void {
        echo <<<'JS'
<script>
(function(){
  var card=document.getElementById('njilga-createprogress');
  if(!card) return;
  var q=function(n){return card.querySelector('[data-cp="'+n+'"]');};
  var timer=null, misses=0;
  function done(p){
    var bad=p.failed+p.stalled;
    q('headline').textContent='Finished creating invoices';
    card.querySelector('.njilga-spin').hidden=true;
    q('note').textContent=p.done+' of '+p.total+' created.'+(bad?' '+bad+' could not be created — each shows its error on the firm’s row, with a Retry button.':'');
    var box=q('done'); box.hidden=false;
    var a=document.createElement('a'); a.className='njilga-btn njilga-btn-primary njilga-btn-sm'; a.href=card.getAttribute('data-reload'); a.textContent='Show the new invoices';
    box.appendChild(a);
  }
  function tick(){
    var fd=new FormData();
    fd.append('action','njilga_create_progress');
    fd.append('nonce',card.getAttribute('data-nonce'));
    fd.append('dues_year',card.getAttribute('data-year'));
    fetch(card.getAttribute('data-ajax'),{method:'POST',body:fd,credentials:'same-origin'})
      .then(function(r){return r.json();})
      .then(function(j){
        misses=0;
        var p=j&&j.success?j.data:null;
        if(!p){ clearInterval(timer); return; }
        var handled=p.done+p.failed+p.stalled;
        q('bar').style.width=(p.total?Math.floor(handled*100/p.total):100)+'%';
        q('counts').textContent=p.done+' of '+p.total+' created'+((p.failed+p.stalled)?' · '+(p.failed+p.stalled)+' failed':'');
        if(p.finished){ clearInterval(timer); done(p); }
      })
      .catch(function(){ if(++misses>=5){ clearInterval(timer); q('note').textContent='Lost touch with the server — reload this page to see where creation has got to.'; } });
  }
  timer=setInterval(tick,parseInt(card.getAttribute('data-poll'),10)||4000);
})();
</script>
JS;
    }
}
