<?php
/**
 * Unit tests for poll codes.
 */

use BmltEnabled\Poller\Codes;

class Test_Codes extends WP_UnitTestCase {

	public function test_alphabet_leaves_out_ambiguous_characters() {
		$this->assertCount( 31, str_split( Codes::ALPHABET ), 'alphabet leaves out ambiguous characters' );
		$this->assertSame( 0, preg_match( '/[01ILO]/', Codes::ALPHABET ), 'alphabet has no ambiguous characters' );
	}

	public function test_generated_codes_are_valid_and_unambiguous() {
		for ( $i = 0; $i < 40; $i++ ) {
			$code = Codes::generate();
			$this->assertTrue( Codes::is_valid( $code ), "generated code {$code} is valid" );
			$this->assertFalse( Codes::has_ambiguous( $code ), "generated code {$code} is unambiguous" );
		}
	}

	public function test_normalize_strips_separators_and_uppercases() {
		$this->assertSame( 'K7MQ2P', Codes::normalize( ' k7-mq 2p ' ), 'normalize strips separators and uppercases' );
		$this->assertTrue( Codes::is_valid( Codes::normalize( 'k7mq2p' ) ), 'a normalized code can be valid' );
	}

	public function test_ambiguous_characters_are_rejected() {
		$this->assertTrue( Codes::has_ambiguous( Codes::normalize( 'K7MQ2O' ) ), 'O is rejected' );
		$this->assertTrue( Codes::has_ambiguous( Codes::normalize( 'k7mq20' ) ), '0 is rejected' );
		$this->assertTrue( Codes::has_ambiguous( Codes::normalize( 'k7mq21' ) ), '1 is rejected' );
		$this->assertTrue( Codes::has_ambiguous( Codes::normalize( 'k7mqi2' ) ), 'I is rejected' );
	}

	public function test_wrong_length_codes_are_invalid() {
		$this->assertFalse( Codes::is_valid( 'K7MQ2' ), 'short codes are invalid' );
		$this->assertFalse( Codes::is_valid( 'K7MQ2P2' ), 'long codes are invalid' );
	}
}
