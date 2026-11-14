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

        self::card( $p, [
            'ajax'      => self::AJAX,
            'year'      => $year,
            'reload'    => $reloadUrl,
            'title'     => sprintf( 'Creating %d invoice%s in the background', $p['total'], $p['total'] === 1 ? '' : 's' ),
            'verb'      => 'created',
            'done'      => 'Finished creating invoices',
            'fail'      => 'could not be created — each shows its error on the firm’s row, with a Retry button.',
            'note'      => 'You can leave this page — creation carries on, and this shows where it has got to when you come back.',
            'lost'      => 'Lost touch with the server — reload this page to see where creation has got to.',
            'reload_as' => 'Show the new invoices',
        ] );
    }

    /**
     * A background run's progress card — shared by invoice creation and
     * the invoice emails (MyNJILGA_Email_Run). $p carries total, done,
     * failed, stalled and pending; $cfg the wording and where to poll.
     * The bar's width is its one inline style, as on every njilga-progress
     * bar: it is data, not design.
     *
     * @param array<string,mixed> $p
     * @param array<string,mixed> $cfg ajax, year, reload, title, verb, done, fail, note, lost, reload_as
     */
    public static function card( array $p, array $cfg ): void {
        $handled = (int) $p['total'] - (int) $p['pending'];
        $pct     = (int) $p['total'] > 0 ? (int) floor( $handled * 100 / (int) $p['total'] ) : 0;
        printf(
            '<div class="njilga-card njilga-card-pad njilga-createprogress" data-ajax-url="%s" data-action="%s" data-nonce="%s" data-year="%d" data-poll="%d" data-reload="%s" data-verb="%s" data-done="%s" data-fail="%s" data-lost="%s" data-reload-as="%s">',
            esc_url( admin_url( 'admin-ajax.php' ) ),
            esc_attr( (string) $cfg['ajax'] ),
            esc_attr( wp_create_nonce( (string) $cfg['ajax'] ) ),
            (int) $cfg['year'],
            self::POLL_MS,
            esc_url( (string) $cfg['reload'] ),
            esc_attr( (string) $cfg['verb'] ),
            esc_attr( (string) $cfg['done'] ),
            esc_attr( (string) $cfg['fail'] ),
            esc_attr( (string) $cfg['lost'] ),
            esc_attr( (string) $cfg['reload_as'] )
        );
        printf(
            '<div class="njilga-progress-top"><span class="njilga-progress-label"><span class="njilga-spin">%s</span> <span data-cp="headline">%s</span></span><span class="njilga-money-line" data-cp="counts">%s</span></div>',
            MyNJILGA_Admin_UI::icon( 'refresh' ),
            esc_html( (string) $cfg['title'] ),
            esc_html( self::counts( $p, (string) $cfg['verb'] ) )
        );
        printf( '<div class="njilga-progress"><div class="njilga-progress-bar" data-cp="bar" style="width:%d%%"></div></div>', $pct );
        printf( '<p class="njilga-help" data-cp="note">%s</p>', esc_html( (string) $cfg['note'] ) );
        echo '<div class="njilga-actions" data-cp="done" hidden></div>';
        echo '</div>';

        self::script();
    }

    /** "42 of 166 created · 1 failed". */
    public static function counts( array $p, string $verb = 'created' ): string {
        $out = sprintf( '%d of %d %s', $p['done'], $p['total'], $verb );
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
     * The polling script, printed after EVERY card: it runs while the page
     * is still being parsed, so it can only see cards printed before it.
     * Each run starts polling for the cards not started yet — a page with
     * both a creation and an email run gets both polled.
     */
    private static function script(): void {
        echo <<<'JS'
<script>
(function(){
  document.querySelectorAll('.njilga-createprogress:not([data-cp-bound])').forEach(function(card){
    card.setAttribute('data-cp-bound','1');
    var q=function(n){return card.querySelector('[data-cp="'+n+'"]');};
    var a=function(n){return card.getAttribute('data-'+n)||'';};
    var timer=null, misses=0;
    function done(p){
      var bad=p.failed+p.stalled;
      q('headline').textContent=a('done');
      card.querySelector('.njilga-spin').hidden=true;
      q('note').textContent=p.done+' of '+p.total+' '+a('verb')+'.'+(bad?' '+bad+' '+a('fail'):'');
      var box=q('done'); box.hidden=false;
      var l=document.createElement('a'); l.className='njilga-btn njilga-btn-primary njilga-btn-sm'; l.href=a('reload'); l.textContent=a('reload-as');
      box.appendChild(l);
    }
    function tick(){
      var fd=new FormData();
      fd.append('action',a('action'));
      fd.append('nonce',a('nonce'));
      fd.append('dues_year',a('year'));
      fetch(a('ajax-url'),{method:'POST',body:fd,credentials:'same-origin'})
        .then(function(r){return r.json();})
        .then(function(j){
          misses=0;
          var p=j&&j.success?j.data:null;
          if(!p){ clearInterval(timer); return; }
          var handled=p.total-p.pending;
          q('bar').style.width=(p.total?Math.floor(handled*100/p.total):100)+'%';
          q('counts').textContent=p.done+' of '+p.total+' '+a('verb')+((p.failed+p.stalled)?' \u00b7 '+(p.failed+p.stalled)+' failed':'');
          if(p.finished){ clearInterval(timer); done(p); }
        })
        .catch(function(){ if(++misses>=5){ clearInterval(timer); q('note').textContent=a('lost'); } });
    }
    timer=setInterval(tick,parseInt(a('poll'),10)||4000);
  });
})();
</script>
JS;
    }
}
