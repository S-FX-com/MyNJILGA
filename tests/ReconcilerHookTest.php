<?php
/**
 * The reconciler's `action_scheduler_init` hook, driven the way WordPress
 * drives it.
 *
 * 3.10.1 hooked ensure_scheduled( ?callable $a = null, ?callable $b = null )
 * straight onto that action and took every page of the site down:
 * WordPress passes an EMPTY STRING to the callbacks of an action that was
 * fired without arguments, so PHP threw "Argument #1 must be of type
 * ?callable, string given" on every request. The unit tests had called
 * ensure_scheduled() directly with injected callables and never went
 * through hook dispatch.
 *
 * The helper below reproduces WordPress's dispatch faithfully — two rules,
 * copied from wp-includes/plugin.php (do_action) and class-wp-hook.php
 * (apply_filters):
 *   1. an action fired with no arguments has its argument list turned into
 *      [ '' ]                       (`if ( empty( $arg ) ) { $arg[] = ''; }`)
 *   2. a callback registered with accepted_args 0 is called with nothing;
 *      one that accepts >= the number of arguments gets them all.
 */
declare( strict_types=1 );

require_once dirname( __DIR__ ) . '/includes/invoicing/class-invoice-creator.php';
require_once dirname( __DIR__ ) . '/includes/invoicing/class-stripe-reconciler.php';

// A recording stand-in for the two WordPress functions register() uses.
$GLOBALS['njilga_test_hooks'] = [];
$GLOBALS['njilga_test_did']   = [];
if ( ! function_exists( 'add_action' ) ) {
    function add_action( $tag, $callback, $priority = 10, $acceptedArgs = 1 ) {
        $GLOBALS['njilga_test_hooks'][ $tag ][] = [ 'callback' => $callback, 'accepted_args' => (int) $acceptedArgs ];
        return true;
    }
}
if ( ! function_exists( 'did_action' ) ) {
    function did_action( $tag ) {
        return $GLOBALS['njilga_test_did'][ $tag ] ?? 0;
    }
}

class ReconcilerHookTest extends NJILGA_TestCase {

    private function reset_hooks(): void {
        $GLOBALS['njilga_test_hooks'] = [];
        $GLOBALS['njilga_test_did']   = [];
    }

    /** do_action( $tag, ...$args ) as WordPress runs it. */
    private function wp_do_action( string $tag, array $args = [] ): void {
        $GLOBALS['njilga_test_did'][ $tag ] = ( $GLOBALS['njilga_test_did'][ $tag ] ?? 0 ) + 1;
        if ( empty( $args ) ) {
            $args[] = ''; // plugin.php: the quirk that bit 3.10.1.
        }
        $numArgs = count( $args );
        foreach ( $GLOBALS['njilga_test_hooks'][ $tag ] ?? [] as $hook ) {
            if ( 0 === $hook['accepted_args'] ) {
                call_user_func( $hook['callback'] );
            } elseif ( $hook['accepted_args'] >= $numArgs ) {
                call_user_func_array( $hook['callback'], $args );
            } else {
                call_user_func_array( $hook['callback'], array_slice( $args, 0, $hook['accepted_args'] ) );
            }
        }
    }

    /** The bug itself, so the helper's fidelity is itself tested: the injectable method cannot be a hook callback. */
    public function testTheInjectableMethodCannotTakeWordPressesEmptyStringArgument(): void {
        $threw = false;
        try {
            call_user_func_array( [ 'MyNJILGA_Stripe_Reconciler', 'ensure_scheduled' ], [ '' ] );
        } catch ( TypeError $e ) {
            $threw = true;
        }
        $this->assertTrue( $threw, 'ensure_scheduled() hooked directly is a TypeError on every request' );
    }

    /** register() must hook the parameterless entry point, not the injectable one. */
    public function testRegisterHooksTheParameterlessCallback(): void {
        $this->reset_hooks();
        MyNJILGA_Stripe_Reconciler::register();

        $hooks = $GLOBALS['njilga_test_hooks']['action_scheduler_init'] ?? [];
        $this->assertCount( 1, $hooks, 'exactly one callback on action_scheduler_init' );
        $this->assertSame( [ 'MyNJILGA_Stripe_Reconciler', 'schedule_daily' ], $hooks[0]['callback'] );
        $this->assertSame( 0, $hooks[0]['accepted_args'], 'registered to receive no arguments' );
    }

    /** The request that fataled: Action Scheduler fires the action, WordPress dispatches it. */
    public function testFiringTheActionTheWayActionSchedulerDoesDoesNotThrow(): void {
        $this->reset_hooks();
        MyNJILGA_Stripe_Reconciler::register();

        $this->wp_do_action( 'action_scheduler_init' );
        $this->assertSame( 1, $GLOBALS['njilga_test_did']['action_scheduler_init'] );
    }

    /** Whatever WordPress passes — nothing, '' or real arguments — the callback must cope. */
    public function testTheCallbackToleratesEveryCallingConvention(): void {
        foreach ( [ [], [ '' ], [ 'x', 1 ], [ [ 'a' => 1 ] ] ] as $args ) {
            MyNJILGA_Stripe_Reconciler::schedule_daily( ...$args );
        }
        $this->assertTrue( true );
    }

    /** register() running after Action Scheduler is already up must schedule straight away, without error. */
    public function testRegisterAfterActionSchedulerIsReadyDoesNotThrow(): void {
        $this->reset_hooks();
        $GLOBALS['njilga_test_did']['action_scheduler_init'] = 1;
        MyNJILGA_Stripe_Reconciler::register();
        $this->assertTrue( true );
    }

    /** The parameterless entry point declares no parameters at all, so no typed one can ever reject WordPress's argument. */
    public function testTheCallbackDeclaresNoParameters(): void {
        $method = new ReflectionMethod( 'MyNJILGA_Stripe_Reconciler', 'schedule_daily' );
        $this->assertSame( 0, $method->getNumberOfParameters() );
    }
}
