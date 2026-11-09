<?php
/**
 * How a verified, recorded Stripe event reaches its handler
 * (MyNJILGA_Stripe_Webhook::dispatch).
 *
 * Stripe never resends an event the endpoint has acknowledged, and the
 * events row is written before dispatch — so an event that is neither
 * queued nor processed is gone. Action Scheduler throws when an action's
 * JSON args exceed 8000 characters (a full invoice.* event for a firm with
 * several roster lines does), which used to surface as an unhandled 500
 * followed by a quiet 200 on Stripe's retry and a row stuck at "received".
 */
declare( strict_types=1 );

class WebhookDispatchTest extends NJILGA_TestCase {

    private function event(): array {
        return [ 'id' => 'evt_1', 'type' => 'invoice.paid', 'data' => [ 'object' => [ 'id' => 'in_1' ] ] ];
    }

    /** The normal path: Action Scheduler takes it, nothing runs inline. */
    public function testQueuedEventIsNotProcessedInline(): void {
        $ran = 0;
        $how = MyNJILGA_Stripe_Webhook::dispatch(
            $this->event(),
            static function ( array $e ) { return 42; },
            static function ( array $e ) use ( &$ran ) { $ran++; }
        );
        $this->assertSame( 'queued', $how );
        $this->assertSame( 0, $ran );
    }

    /** The bug: the scheduler throws (oversized args) — the event must still be processed. */
    public function testEnqueueThatThrowsFallsBackToInline(): void {
        $seen = null;
        $how  = MyNJILGA_Stripe_Webhook::dispatch(
            $this->event(),
            static function ( array $e ) { throw new RuntimeException( 'Error saving action: ActionScheduler_Action::$args too long.' ); },
            static function ( array $e ) use ( &$seen ) { $seen = $e['id']; }
        );
        $this->assertSame( 'inline', $how );
        $this->assertSame( 'evt_1', $seen );
    }

    /** Action Scheduler declines without throwing (0 / false) — same outcome. */
    public function testEnqueueReturningNoActionIdFallsBackToInline(): void {
        foreach ( [ 0, false, null ] as $refusal ) {
            $ran = 0;
            $how = MyNJILGA_Stripe_Webhook::dispatch(
                $this->event(),
                static function ( array $e ) use ( $refusal ) { return $refusal; },
                static function ( array $e ) use ( &$ran ) { $ran++; }
            );
            $this->assertSame( 'inline', $how );
            $this->assertSame( 1, $ran );
        }
    }

    /** No Action Scheduler at all — processed inline, as before. */
    public function testNoSchedulerProcessesInline(): void {
        $ran = 0;
        $how = MyNJILGA_Stripe_Webhook::dispatch(
            $this->event(),
            null,
            static function ( array $e ) use ( &$ran ) { $ran++; }
        );
        $this->assertSame( 'inline', $how );
        $this->assertSame( 1, $ran );
    }

    /** An Error (not just an Exception) from the scheduler is caught too. */
    public function testEnqueueThatRaisesAnErrorFallsBackToInline(): void {
        $ran = 0;
        $how = MyNJILGA_Stripe_Webhook::dispatch(
            $this->event(),
            static function ( array $e ) { throw new TypeError( 'boom' ); },
            static function ( array $e ) use ( &$ran ) { $ran++; }
        );
        $this->assertSame( 'inline', $how );
        $this->assertSame( 1, $ran );
    }
}
