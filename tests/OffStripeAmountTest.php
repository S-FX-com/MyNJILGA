<?php
/**
 * The money rule for an invoice settled outside Stripe
 * (MyNJILGA_Stripe_Webhook::off_stripe_amount_cents).
 *
 * Stripe's amount_paid is cumulative for the invoice, so it cannot be
 * used as the amount of a single settlement once any of the balance has
 * already been recorded here. The webhook and the reconciler can both
 * notice the same settlement, so both go through this one function.
 */
declare( strict_types=1 );

class OffStripeAmountTest extends NJILGA_TestCase {

    /** The whole invoice closed out in one go: the outstanding balance IS the payment. */
    public function testFullBalanceSettledInOneGo(): void {
        $this->assertSame( 20000, MyNJILGA_Stripe_Webhook::off_stripe_amount_cents( 20000, 20000 ) );
    }

    /**
     * The case that would otherwise overstate collections: $100 already
     * paid against the invoice (a card payment, say), then the remaining
     * balance closed out with "Mark as paid" in the Stripe Dashboard.
     * Stripe reports the cumulative $200; only the remaining $100 was
     * settled off Stripe and belongs to this event.
     */
    public function testPartialAlreadyRecordedIsNotBookedTwice(): void {
        $this->assertSame( 10000, MyNJILGA_Stripe_Webhook::off_stripe_amount_cents( 10000, 20000 ) );
    }

    /** With no balance on file to reason from, Stripe's figure is all there is. */
    public function testFallsBackToStripeWhenNoLocalBalance(): void {
        $this->assertSame( 20000, MyNJILGA_Stripe_Webhook::off_stripe_amount_cents( 0, 20000 ) );
    }

    /** Never negative, whatever the inputs. */
    public function testNeverNegative(): void {
        $this->assertSame( 0, MyNJILGA_Stripe_Webhook::off_stripe_amount_cents( 0, 0 ) );
        $this->assertSame( 0, MyNJILGA_Stripe_Webhook::off_stripe_amount_cents( -5, -20000 ) );
    }
}
