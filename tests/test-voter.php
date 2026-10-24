<?php
/**
 * Tests for the anonymous browser ballot token.
 */

use BmltEnabled\Poller\Voter;

class Test_Voter extends WP_UnitTestCase {

	public function tearDown(): void {
		unset( $_COOKIE[ Voter::COOKIE ] );
		parent::tearDown();
	}

	public function test_no_cookie_means_no_token() {
		unset( $_COOKIE[ Voter::COOKIE ] );
		$this->assertFalse( Voter::has_token() );
		$this->assertSame( '', Voter::token() );
	}

	public function test_a_valid_cookie_is_the_token() {
		$raw = str_repeat( 'a', 64 );
		$_COOKIE[ Voter::COOKIE ] = $raw;
		$this->assertTrue( Voter::has_token() );
		$this->assertSame( $raw, Voter::token() );
	}

	public function test_a_malformed_cookie_is_rejected() {
		$_COOKIE[ Voter::COOKIE ] = 'not-a-token';
		$this->assertFalse( Voter::has_token() );
		$this->assertSame( '', Voter::token() );
	}

	public function test_ensure_mints_a_token_then_reuses_it() {
		unset( $_COOKIE[ Voter::COOKIE ] );
		// setcookie() warns because the test bootstrap has already produced output; the mint path still runs.
		$raw = @Voter::ensure();
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $raw );
		$this->assertSame( $raw, $_COOKIE[ Voter::COOKIE ] );
		$this->assertSame( $raw, Voter::ensure(), 'a second call reuses the minted token' );
	}

	public function test_hash_is_stable_and_input_dependent() {
		$raw = str_repeat( 'b', 64 );
		$this->assertSame( Voter::hash( $raw ), Voter::hash( $raw ) );
		$this->assertNotSame( Voter::hash( $raw ), Voter::hash( str_repeat( 'c', 64 ) ) );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', Voter::hash( $raw ) );
	}
}
