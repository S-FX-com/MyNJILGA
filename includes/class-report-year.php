<?php
/**
 * The dues year a report is about.
 *
 * "Active" means paid through a dues year or later, so every report answers
 * for ONE year: this calendar year (2026) or a coming one (2027, …). This
 * class decides which years can be chosen, reads the choice from the
 * request, and draws the year switcher the report pages share.
 *
 * Which years are offered: this year, next year, and any later year that
 * already has a "Dues Paid {year}" tag — so 2027 is there from the day it
 * is invoiced or paid, and 2028 appears on its own once its first tag is
 * made. Past years are NOT offered: a contact's tags say the latest year
 * they paid through, not every year they were a member, so a past year
 * can't be rebuilt honestly.
 *
 * The year travels as ?report_year= on the report pages and as a hidden
 * field on every export form, so a file is always for the year on screen.
 */
class MyNJILGA_Report_Year {

    const PARAM = 'report_year';

    // -------------------------------------------------------------------------
    // Pure
    // -------------------------------------------------------------------------

    /**
     * The years a report can be for, oldest first.
     *
     * @param int             $current      this calendar year
     * @param array<int,int>  $taggedYears  years that have a "Dues Paid {year}" tag
     * @return array<int,int>
     */
    public static function options( int $current, array $taggedYears ): array {
        $years = [ $current, $current + 1 ];
        foreach ( $taggedYears as $y ) {
            $y = (int) $y;
            if ( $y >= $current && $y <= $current + MyNJILGA_Membership_Stats::YEARS_AHEAD ) {
                $years[] = $y;
            }
        }
        $years = array_values( array_unique( $years ) );
        sort( $years );
        return $years;
    }

    /**
     * The year to use for a requested value: it, when it is one of the
     * options, otherwise this year. Anything that isn't a plain whole
     * number ("2027abc", "-1", an array) is this year too.
     *
     * @param mixed           $requested
     * @param array<int,int>  $options
     */
    public static function resolve( $requested, array $options, int $current ): int {
        if ( is_string( $requested ) && preg_match( '/^\d{4}$/', $requested ) && in_array( (int) $requested, $options, true ) ) {
            return (int) $requested;
        }
        return $current;
    }

    /** "2027" for the year; "2026 (this year)" for the current one. */
    public static function label( int $year, int $current ): string {
        return $year === $current ? $year . ' (this year)' : (string) $year;
    }

    // -------------------------------------------------------------------------
    // WordPress + FluentCRM
    // -------------------------------------------------------------------------

    /** @return array<int,int> */
    public static function available(): array {
        $pattern = (string) MyNJILGA_Dues_Settings::general( 'year_paid_tag_pattern', '' );
        if ( $pattern === '' ) {
            $pattern = (string) MyNJILGA_Dues_Settings::defaults()['general']['year_paid_tag_pattern'];
        }
        return self::options(
            MyNJILGA_Invoicing::current_dues_year(),
            MyNJILGA_My_Membership::paid_years( MyNJILGA_Tags::all_tags(), $pattern )
        );
    }

    /** The year in the request (GET or POST), this year when none or an unknown one. */
    public static function from_request(): int {
        $raw = isset( $_REQUEST[ self::PARAM ] ) ? wp_unslash( $_REQUEST[ self::PARAM ] ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a display choice; exports are nonce-checked by their handlers
        return self::resolve( $raw, self::available(), MyNJILGA_Invoicing::current_dues_year() );
    }

    /**
     * The year switcher: one tab per available year, the chosen one marked.
     * $args are the rest of the page's query (a scope, say), kept on every tab.
     *
     * @param array<string,string> $args
     */
    public static function render_picker( string $pageSlug, int $selected, array $args = [] ): void {
        $current = MyNJILGA_Invoicing::current_dues_year();
        echo '<p class="njilga-help">Dues year — every figure, list and export on this page is for the year you pick.</p>';
        echo '<div class="njilga-tabs njilga-tabs-bare" role="navigation" aria-label="Dues year">';
        foreach ( self::available() as $year ) {
            printf(
                '<a class="njilga-tab%s" href="%s"%s>%s</a>',
                $year === $selected ? ' active' : '',
                esc_url( add_query_arg( [ 'page' => $pageSlug, self::PARAM => $year ] + $args, admin_url( 'admin.php' ) ) ),
                $year === $selected ? ' aria-current="page"' : '',
                esc_html( self::label( $year, $current ) )
            );
        }
        echo '</div>';
    }

    /** The year-in-words line the pages and exports share: what "active" means for $year. */
    public static function definition( int $year ): string {
        $current = MyNJILGA_Invoicing::current_dues_year();
        $text    = sprintf( 'Paid = paid through %d or later (a "Dues Paid %d" or later tag)', $year, $year );
        if ( $year > $current ) {
            $text .= sprintf( '. %d is a coming year: members with only the older, undated Dues Paid tag are not counted as paid for it until they pay', $year );
        }
        return $text;
    }
}
