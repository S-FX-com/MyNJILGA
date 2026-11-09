<?php
/**
 * The daily reconcile job must actually get queued
 * (MyNJILGA_Stripe_Reconciler::ensure_scheduled).
 *
 * It used to be scheduled from register(), which runs while the plugin
 * file is being included — before Action Scheduler has defined its
 * functions (plugins_loaded:1) or opened its data store (init). The guard
 * there read "no Action Scheduler yet", so nothing was ever scheduled:
 * no daily reconcile, no join sweep, no event-log prune. The job is now
 * scheduled from `action_scheduler_init`; this pins the idempotent
 * scheduling decision itself.
 */
declare( strict_types=1 );

require_once dirname( __DIR__ ) . '/includes/invoicing/class-invoice-creator.php';
require_once dirname( __DIR__ ) . '/includes/invoicing/class-stripe-reconciler.php';

class ReconcilerScheduleTest extends NJILGA_TestCase {

    /** Nothing scheduled yet: schedule exactly one recurring daily job, under the plugin's group. */
    public function testSchedulesTheDailyJobWhenNoneExists(): void {
        $calls = [];
        $ok    = MyNJILGA_Stripe_Reconciler::ensure_scheduled(
            static function ( string $hook, string $group ): bool { return false; },
            static function ( int $first, int $interval, string $hook, string $group ) use ( &$calls ) {
                $calls[] = [ $first, $interval, $hook, $group ];
                return 7;
            }
        );

        $this->assertTrue( $ok );
        $this->assertCount( 1, $calls );
        $this->assertSame( 86400, $calls[0][1], 'repeats daily' );
        $this->assertSame( 'njilga_stripe_reconcile_daily', $calls[0][2] );
        $this->assertSame( MyNJILGA_Invoice_Creator::AS_GROUP, $calls[0][3] );
        $this->assertTrue( $calls[0][0] > time(), 'first run is in the future' );
    }

    /** Runs on every request once Action Scheduler is ready — so it must not pile up duplicates. */
    public function testDoesNothingWhenAlreadyScheduled(): void {
        $scheduled = 0;
        $ok        = MyNJILGA_Stripe_Reconciler::ensure_scheduled(
            static function ( string $hook, string $group ): bool { return true; },
            static function ( int $first, int $interval, string $hook, string $group ) use ( &$scheduled ) {
                $scheduled++;
                return 1;
            }
        );

        $this->assertTrue( $ok );
        $this->assertSame( 0, $scheduled );
    }

    /** Action Scheduler declining (it returns 0) is reported, not hidden. */
    public function testReportsWhenTheSchedulerRefuses(): void {
        $ok = MyNJILGA_Stripe_Reconciler::ensure_scheduled(
            static function ( string $hook, string $group ): bool { return false; },
            static function ( int $first, int $interval, string $hook, string $group ) { return 0; }
        );
        $this->assertFalse( $ok );
    }

    /** A scheduler that throws must never break the page load that triggered it. */
    public function testSwallowsSchedulerExceptions(): void {
        $ok = MyNJILGA_Stripe_Reconciler::ensure_scheduled(
            static function ( string $hook, string $group ): bool { throw new RuntimeException( 'store not ready' ); },
            static function ( int $first, int $interval, string $hook, string $group ) { return 1; }
        );
        $this->assertFalse( $ok );
    }

    /** Without Action Scheduler functions (and no injected ones) there is nothing to do. */
    public function testReturnsFalseWhenActionSchedulerIsAbsent(): void {
        if ( function_exists( 'as_has_scheduled_action' ) || function_exists( 'as_schedule_recurring_action' ) ) {
            return; // A real scheduler is loaded in this process; the injected-callable tests above cover the logic.
        }
        $this->assertFalse( MyNJILGA_Stripe_Reconciler::ensure_scheduled() );
    }

    /**
     * A payment the webhook missed, settled by the reconciler, must leave the
     * row carrying the same money columns the webhook would have written — a
     * settled row is terminal and never re-synced, so a row left at paid=0 /
     * due=full overstates Outstanding forever.
     */
    public function testMissedPaymentRowCarriesTheSameMoneyColumnsAsTheWebhook(): void {
        $this->assertSame(
            [ 'amount_paid_cents' => 37500, 'amount_due_cents' => 0, 'stripe_status' => 'paid', 'primary_method' => 'card' ],
            MyNJILGA_Stripe_Reconciler::settled_row_fields( 'paid', 37500, 0, 'card' )
        );
    }

    /** An unknown method is left out rather than blanking the one already on the row. */
    public function testUnknownMethodIsNotBlanked(): void {
        $fields = MyNJILGA_Stripe_Reconciler::settled_row_fields( 'paid', 20000, 0, '' );
        $this->assertFalse( array_key_exists( 'primary_method', $fields ) );
        $this->assertSame( 20000, $fields['amount_paid_cents'] );
    }
}
