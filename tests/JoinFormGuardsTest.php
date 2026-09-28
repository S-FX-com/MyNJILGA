<?php
/**
 * The pure parts of the join form's guards: where a logged-out POST says
 * it came from, the visitor cookie's name and scope, how many colleague
 * lookups a payer and a connection get, and when an email's FluentCRM
 * record belongs to another website account.
 */
require_once dirname( __DIR__ ) . '/includes/join/class-join-form.php';

class JoinFormGuardsTest extends NJILGA_TestCase {

    private $hosts = [ 'njilga.test' ];

    public function test_a_post_from_this_site_is_same(): void {
        $this->assertSame( 'same', MyNJILGA_Join_Form::origin_verdict( 'https://njilga.test', 'https://njilga.test/join/', $this->hosts ) );
        $this->assertSame( 'same', MyNJILGA_Join_Form::origin_verdict( 'http://njilga.test:8080', '', $this->hosts ), 'the port is not part of the host' );
        $this->assertSame( 'same', MyNJILGA_Join_Form::origin_verdict( 'https://NJILGA.test', '', $this->hosts ), 'hosts compare case-insensitively' );
        $this->assertSame( 'same', MyNJILGA_Join_Form::origin_verdict( '', 'https://njilga.test/join/', $this->hosts ), 'no Origin: the Referer decides' );
    }

    public function test_either_header_naming_another_host_is_foreign(): void {
        $this->assertSame( 'foreign', MyNJILGA_Join_Form::origin_verdict( 'https://events.njilga.test', '', $this->hosts ), 'a sibling subdomain is another host' );
        $this->assertSame( 'foreign', MyNJILGA_Join_Form::origin_verdict( 'https://njilga.test', 'https://evil.test/page', $this->hosts ) );
        $this->assertSame( 'foreign', MyNJILGA_Join_Form::origin_verdict( 'null', 'https://evil.test/page', $this->hosts ) );
        $this->assertSame( 'foreign', MyNJILGA_Join_Form::origin_verdict( 'garbage', '', $this->hosts ), 'nothing parseable is not this site' );
        $this->assertSame( 'foreign', MyNJILGA_Join_Form::origin_verdict( 'https://njilga.test.evil.test', '', $this->hosts ) );
    }

    public function test_null_or_silent_is_unknown_never_same(): void {
        $this->assertSame( 'unknown', MyNJILGA_Join_Form::origin_verdict( '', '', $this->hosts ) );
        $this->assertSame( 'unknown', MyNJILGA_Join_Form::origin_verdict( 'null', '', $this->hosts ) );
        $this->assertSame( 'unknown', MyNJILGA_Join_Form::origin_verdict( 'null', 'https://njilga.test/join/', $this->hosts ), 'an opaque Origin is not vouched for by a Referer' );
    }

    public function test_any_of_the_sites_hosts_counts(): void {
        $hosts = [ 'njilga.test', 'www.njilga.test', '' ];
        $this->assertSame( 'same', MyNJILGA_Join_Form::origin_verdict( 'https://www.njilga.test', '', $hosts ) );
        $this->assertSame( 'foreign', MyNJILGA_Join_Form::origin_verdict( 'https://', '', $hosts ), 'an empty host never matches an empty entry' );
    }

    public function test_visitor_cookie_is_a_host_cookie_over_https(): void {
        $spec = MyNJILGA_Join_Form::visitor_cookie_spec( true, '/blog/' );
        $this->assertSame( '__Host-' . MyNJILGA_Join_Form::VISITOR_COOKIE, $spec['name'] );
        $this->assertSame( '/', $spec['path'], '__Host- cookies must be Path=/' );
        $this->assertTrue( $spec['secure'] );
    }

    public function test_visitor_cookie_keeps_its_name_on_plain_http(): void {
        $spec = MyNJILGA_Join_Form::visitor_cookie_spec( false, '/blog/' );
        $this->assertSame( MyNJILGA_Join_Form::VISITOR_COOKIE, $spec['name'] );
        $this->assertSame( '/blog/', $spec['path'] );
        $this->assertFalse( $spec['secure'] );
        $this->assertSame( '/', MyNJILGA_Join_Form::visitor_cookie_spec( false, '' )['path'] );
    }

    public function test_a_genuine_payer_can_send_a_full_list_three_times(): void {
        foreach ( [ 1, 5, 10, 25 ] as $max ) {
            $payer = MyNJILGA_Join_Form::colleague_check_limit( $max, false );
            $ip    = MyNJILGA_Join_Form::colleague_check_limit( $max, true );
            $used  = 0;
            for ( $i = 0; $i < 3; $i++ ) {
                $this->assertTrue( MyNJILGA_Join_Form::rate_allows( $used, $max, $payer ), "list $i of $max fits the payer's allowance" );
                $used += $max;
            }
            $this->assertTrue( $ip >= $payer, 'a connection gets at least what one payer does' );
            $this->assertTrue( MyNJILGA_Join_Form::rate_allows( 0, 2 * $payer, $ip ), 'two payers behind one address fit too' );
        }
    }

    public function test_colleague_allowance_is_bounded_and_all_or_nothing(): void {
        $this->assertSame( MyNJILGA_Join_Form::RATE_COLLEAGUE_ROWS, MyNJILGA_Join_Form::colleague_check_limit( 10, false ) );
        $this->assertSame( MyNJILGA_Join_Form::RATE_COLLEAGUE_ROWS_IP, MyNJILGA_Join_Form::colleague_check_limit( 10, true ) );
        $this->assertSame( 75, MyNJILGA_Join_Form::colleague_check_limit( 25, false ), 'a higher Settings maximum scales it' );
        $this->assertSame( MyNJILGA_Join_Form::RATE_COLLEAGUE_ROWS, MyNJILGA_Join_Form::colleague_check_limit( 0, false ) );
        $this->assertFalse( MyNJILGA_Join_Form::rate_allows( 25, 10, 30 ), 'a batch that would cross the line is refused whole' );
        $this->assertTrue( MyNJILGA_Join_Form::rate_allows( 25, 5, 30 ) );
        $this->assertFalse( MyNJILGA_Join_Form::rate_allows( 0, -5, 30 ) );
    }

    public function test_a_record_linked_to_another_account_is_owned_elsewhere(): void {
        $this->assertTrue( MyNJILGA_Join_Form::linked_to_other_account( 7, 3 ), 'linked to account 7, paying from account 3' );
        $this->assertTrue( MyNJILGA_Join_Form::linked_to_other_account( 7, 0 ), 'logged out: any linked account is another one' );
        $this->assertFalse( MyNJILGA_Join_Form::linked_to_other_account( 3, 3 ), 'their own' );
        $this->assertFalse( MyNJILGA_Join_Form::linked_to_other_account( 0, 3 ), 'not linked to anyone' );
        $this->assertFalse( MyNJILGA_Join_Form::linked_to_other_account( 0, 0 ) );
    }

    public function test_wrong_code_wording_also_points_an_account_holder_to_log_in(): void {
        $this->assertTrue( strpos( MyNJILGA_Join_Form::wrong_code_message(), 'log in' ) !== false );
        $this->assertTrue( strpos( MyNJILGA_Join_Form::wrong_code_message(), 'expired' ) === false, 'never the "no code on file" answer' );
    }
}
