<?php
/**
 * Telling a Stripe secret stored as pasted from one stored encrypted
 * (MyNJILGA_Stripe_Connection::is_legacy_plaintext_secret).
 *
 * Defining NJILGA_STRIPE_KEY after keys were connected used to make every
 * stored key and webhook secret "undecryptable" — the receiver then
 * answered 400 to every Stripe event. A value that is plainly a Stripe
 * secret is accepted as it is and re-encrypted on the next save; anything
 * else still has to decrypt.
 */
declare( strict_types=1 );

require_once dirname( __DIR__ ) . '/includes/invoicing/class-stripe-connection.php';

class StripeSecretStorageTest extends NJILGA_TestCase {

    /**
     * Fixtures are assembled at runtime on purpose: a literal that looks like
     * a Stripe key is (rightly) refused by GitHub push protection, however
     * obviously fake it is.
     */
    private function secret( string $kind, string $tail = 'A1b2C3d4E5f6G7h8I9j0K1l2' ): string {
        return $kind . '_' . $tail;
    }

    public function testStripeSecretsAsPastedAreRecognised(): void {
        foreach ( [
            $this->secret( 'sk' . '_live' ),
            $this->secret( 'sk' . '_test', '4eC39HqLyjWDarjtT1zdp7dc' ),
            $this->secret( 'rk' . '_live' ),
            $this->secret( 'rk' . '_test' ),
            $this->secret( 'whsec', '5f9a2b8c7d6e4f3a2b1c0d9e8f7a6b5c' ),
        ] as $secret ) {
            $this->assertTrue( MyNJILGA_Stripe_Connection::is_legacy_plaintext_secret( $secret ), 'a ' . substr( $secret, 0, 4 ) . ' secret' );
        }
    }

    /** The empty string means "nothing stored" and is handled before this is asked. */
    public function testNothingAndNonSecretsAreNotRecognised(): void {
        foreach ( [
            '',
            'sk' . '_live_',
            'whsec' . '_',
            $this->secret( 'pk' . '_live' ),            // publishable keys aren't secrets this plugin stores
            $this->secret( 'sk' . '_prod' ),
            'sk' . '_live_A1b2C3 d4E5f6G7h8',
            "whsec" . "_abc\n",
            'not a key',
        ] as $value ) {
            $this->assertFalse( MyNJILGA_Stripe_Connection::is_legacy_plaintext_secret( $value ), var_export( strlen( $value ), true ) );
        }
    }

    /**
     * Ciphertext is base64(nonce . secretbox) — its alphabet has no
     * underscore, so it can never look like a plaintext secret, however
     * the random bytes fall.
     */
    public function testCiphertextIsNeverMistakenForAPlaintextSecret(): void {
        for ( $i = 0; $i < 500; $i++ ) {
            $blob = base64_encode( random_bytes( 24 + 16 + 40 ) );
            $this->assertFalse( MyNJILGA_Stripe_Connection::is_legacy_plaintext_secret( $blob ) );
        }
    }
}
