<?php
/**
 * When a dues invoice falls due (MyNJILGA_Invoicing::year_end_due_timestamp).
 *
 * The rule is December 31 of the calendar year the invoice is RAISED IN
 * — not the dues year it covers — with the "days until due" setting
 * acting only as a floor for invoices raised close to year-end.
 */
declare( strict_types=1 );

class InvoiceDueDateTest extends NJILGA_TestCase {

    private function ts( string $utc ): int {
        return (int) strtotime( $utc . ' UTC' );
    }

    private function dueDate( string $raisedOn, int $minDays = 30 ): string {
        return gmdate( 'Y-m-d', MyNJILGA_Invoicing::year_end_due_timestamp( $minDays, $this->ts( $raisedOn ) ) );
    }

    /** The ordinary case: 2027 dues raised in September 2026 are due at the end of 2026. */
    public function testAutumnBatchIsDueAtTheEndOfTheYearItWasRaisedIn(): void {
        $this->assertSame( '2026-12-31', $this->dueDate( '2026-09-02 14:00:00' ) );
    }

    /** A mid-year join, raised inside the year it covers, falls due at the end of that same year. */
    public function testMidYearJoinIsDueAtTheEndOfThatYear(): void {
        $this->assertSame( '2027-12-31', $this->dueDate( '2027-03-18 09:00:00' ) );
    }

    /** Never a due date in the past, and never a year out: the floor takes over near year-end. */
    public function testLateDecemberFallsBackToTheMinimumWindow(): void {
        $this->assertSame( '2027-01-27', $this->dueDate( '2026-12-28 10:00:00', 30 ) );
    }

    /** The floor only applies when year-end is genuinely too close. */
    public function testTheFloorDoesNotDisplaceAComfortableYearEnd(): void {
        $this->assertSame( '2026-12-31', $this->dueDate( '2026-11-15 10:00:00', 30 ) );
        // Exactly on the boundary — 30 days out lands on year-end itself.
        $this->assertSame( '2026-12-31', $this->dueDate( '2026-12-01 12:00:00', 30 ) );
    }

    /** Noon UTC, so the date prints as December 31 rather than tipping into January 1. */
    public function testTimestampIsMiddayUtcSoTheDateReadsTheSameEverywhere(): void {
        $ts = MyNJILGA_Invoicing::year_end_due_timestamp( 30, $this->ts( '2026-09-02 14:00:00' ) );
        $this->assertSame( '12:00:00', gmdate( 'H:i:s', $ts ) );
        // UTC-11 through UTC+11 all still read December 31.
        $this->assertSame( '2026-12-31', gmdate( 'Y-m-d', $ts - ( 11 * 3600 ) ) );
        $this->assertSame( '2026-12-31', gmdate( 'Y-m-d', $ts + ( 11 * 3600 ) ) );
    }

    /** A nonsensical minimum can't produce a due date before the invoice exists. */
    public function testMinimumIsClampedToAtLeastOneDay(): void {
        $raised = $this->ts( '2026-12-31 18:00:00' );
        $this->assertTrue( MyNJILGA_Invoicing::year_end_due_timestamp( 0, $raised ) > $raised );
    }
}
