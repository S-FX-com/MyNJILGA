<?php
/**
 * One shape for every phone number the plugin writes to FluentCRM.
 *
 * FluentCRM records here hold US numbers as ###-###-#### with the
 * country code in front (+1 201-555-0100), so a join form typed any
 * other way — "(201) 555 0100", "2015550100", "+1.201.555.0100" — ends
 * up the same as the records staff already keep. A number with another
 * country code (an overseas colleague) is kept, tidied, as typed.
 *
 * Pure: no WordPress calls, so it is unit-tested directly.
 */
class MyNJILGA_Phone {

    const US_PLACEHOLDER = '201-555-0100';

    /**
     * The 10 digits of a US (NANP) number, or '' when $raw isn't one —
     * too short or long, an explicit other country code, or an area code
     * or exchange that can't exist (they never start with 0 or 1).
     */
    public static function us_digits( string $raw ): string {
        $raw = trim( $raw );
        if ( $raw === '' || self::foreign_code( $raw ) ) {
            return '';
        }
        $d = (string) preg_replace( '/\D+/', '', $raw );
        if ( strpos( $d, '001' ) === 0 && strlen( $d ) === 13 ) {
            $d = substr( $d, 3 ); // 00 1 …: the international dialling prefix, spelled out.
        }
        if ( strlen( $d ) === 11 && $d[0] === '1' ) {
            $d = substr( $d, 1 );
        }
        return preg_match( '/^[2-9]\d{2}[2-9]\d{6}$/', $d ) ? $d : '';
    }

    /**
     * Whether $raw is a number with a country code other than +1, with a
     * plausible length (E.164 allows at most 15 digits).
     */
    public static function is_international( string $raw ): bool {
        if ( ! self::foreign_code( $raw ) ) {
            return false;
        }
        $n = strlen( self::international_digits( $raw ) );
        return $n >= 8 && $n <= 15;
    }

    public static function valid( string $raw ): bool {
        return self::us_digits( $raw ) !== '' || self::is_international( $raw );
    }

    /**
     * How the form shows it: 201-555-0100 for a US number, +44 20 7946
     * 0000 (spacing tidied, a leading 00 read as +) for another country,
     * and anything else back as typed, trimmed.
     */
    public static function display( string $raw ): string {
        $us = self::us_digits( $raw );
        if ( $us !== '' ) {
            return substr( $us, 0, 3 ) . '-' . substr( $us, 3, 3 ) . '-' . substr( $us, 6 );
        }
        if ( self::is_international( $raw ) ) {
            return self::tidy_international( $raw );
        }
        return trim( $raw );
    }

    /**
     * What FluentCRM gets: +1 201-555-0100 for a US number, the tidied
     * international number otherwise, '' for nothing. Idempotent, so a
     * value that is already in this shape passes through unchanged.
     */
    public static function for_crm( string $raw ): string {
        $us = self::us_digits( $raw );
        if ( $us !== '' ) {
            return '+1 ' . self::display( $us );
        }
        return self::display( $raw );
    }

    /**
     * Whether two numbers are the same line, however each was typed.
     */
    public static function same( string $a, string $b ): bool {
        return self::for_crm( $a ) === self::for_crm( $b );
    }

    private static function foreign_code( string $raw ): bool {
        return (bool) preg_match( '/^\s*(?:\+|00)\s*\(?\s*([0-9])/', $raw, $m ) && $m[1] !== '1';
    }

    private static function international_digits( string $raw ): string {
        return (string) preg_replace( '/\D+/', '', (string) preg_replace( '/^\s*00/', '', $raw ) );
    }

    private static function tidy_international( string $raw ): string {
        $body = (string) preg_replace( '/^\s*(?:\+|00)\s*/', '', trim( $raw ) );
        $body = (string) preg_replace( '/[^\d()\- ]+/', ' ', $body );
        return '+' . trim( (string) preg_replace( '/\s+/', ' ', $body ) );
    }
}
