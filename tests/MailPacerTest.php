<?php
/**
 * The pure rules of the invoice-email pacer (MyNJILGA_Mail_Pacer): what a typed speed
 * means, and which slot the next email gets. Mail services cap how fast they accept
 * messages (Emailit: 2 a second); wait() itself is exercised against a rate-limiting
 * mail stub in the simulated-Stripe harness.
 */
declare( strict_types=1 );

require_once dirname( __DIR__ ) . '/includes/invoicing/class-mail-pacer.php';

class MailPacerTest extends NJILGA_TestCase {

    public function testASpeedIsANumberWithinBoundsElseTheDefault(): void {
        $this->assertSame( 1.0, MyNJILGA_Mail_Pacer::per_second( 1 ) );
        $this->assertSame( 1.5, MyNJILGA_Mail_Pacer::per_second( '1.5' ) );
        $this->assertSame( 2.0, MyNJILGA_Mail_Pacer::per_second( 2.0 ) );
        $this->assertSame( 10.0, MyNJILGA_Mail_Pacer::per_second( 50 ), 'a cap, so a typo cannot hammer the mail service' );
        $this->assertSame( 0.1, MyNJILGA_Mail_Pacer::per_second( '0.05' ), 'and a floor above zero' );
        foreach ( [ 0, '0', -3, '', 'abc', null, [], false ] as $junk ) {
            $this->assertSame( 1.0, MyNJILGA_Mail_Pacer::per_second( $junk ), 'unusable → the default: ' . var_export( $junk, true ) );
        }
    }

    public function testASpeedIsShownWithoutTrailingZeros(): void {
        $this->assertSame( '1', MyNJILGA_Mail_Pacer::format( 1.0 ) );
        $this->assertSame( '0.5', MyNJILGA_Mail_Pacer::format( 0.5 ) );
        $this->assertSame( '2.5', MyNJILGA_Mail_Pacer::format( 2.5 ) );
        $this->assertSame( '10', MyNJILGA_Mail_Pacer::format( 10.0 ) );
        $this->assertSame( '0.1', MyNJILGA_Mail_Pacer::format( 0.1 ) );
    }

    public function testAnEmailGoesNowIfTheGapHasPassedElseOneGapAfterTheLast(): void {
        $this->assertSame( 100.0, MyNJILGA_Mail_Pacer::next_slot( 0.0, 100.0, 1.0 ), 'nothing sent before: now' );
        $this->assertSame( 100.0, MyNJILGA_Mail_Pacer::next_slot( 98.0, 100.0, 1.0 ), 'the gap has passed: now' );
        $this->assertSame( 100.5, MyNJILGA_Mail_Pacer::next_slot( 99.5, 100.0, 1.0 ), 'half a second ago: wait for the rest of the gap' );
        $this->assertSame( 101.0, MyNJILGA_Mail_Pacer::next_slot( 100.0, 100.0, 1.0 ), 'just now: a full gap' );
    }

    /** Slots handed out one after another are a gap apart, however fast they are asked for. */
    public function testASuccessionOfSlotsIsEvenlySpaced(): void {
        $last = 0.0; $now = 1000.0; $slots = [];
        for ( $i = 0; $i < 6; $i++ ) {
            $last    = MyNJILGA_Mail_Pacer::next_slot( $last, $now, 0.5 ); // every caller asks at the same instant
            $slots[] = $last;
        }
        $this->assertSame( [ 1000.0, 1000.5, 1001.0, 1001.5, 1002.0, 1002.5 ], $slots );
        // …so no one-second window ever holds more than 2 — the limit Emailit enforces.
        foreach ( $slots as $a ) {
            $inWindow = count( array_filter( $slots, static function ( $b ) use ( $a ) { return $b >= $a && $b < $a + 1.0; } ) );
            $this->assertTrue( $inWindow <= 2, "at most 2 emails in the second from $a" );
        }
    }

    /** A recorded slot far ahead (a clock that jumped back) must never hold sending up for long. */
    public function testAStaleSlotFarInTheFutureIsIgnored(): void {
        $this->assertSame( 100.0, MyNJILGA_Mail_Pacer::next_slot( 100000.0, 100.0, 1.0 ) );
        $this->assertSame( 100.0 + 29.0 + 1.0, MyNJILGA_Mail_Pacer::next_slot( 100.0 + 29.0, 100.0, 1.0 ), 'a slot within the maximum wait is honoured' );
    }

    public function testNoGapMeansNoWaitAndANegativeGapIsNoGap(): void {
        $this->assertSame( 100.0, MyNJILGA_Mail_Pacer::next_slot( 100.0, 100.0, 0.0 ) );
        $this->assertSame( 100.0, MyNJILGA_Mail_Pacer::next_slot( 100.0, 100.0, -5.0 ) );
    }
}
