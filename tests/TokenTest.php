<?php
/**
 * Tests for preview tokens.
 */

namespace DS\PublicPostPreview\Tests;

use DS\PublicPostPreview\Token;

/**
 * A token must only ever unlock the one link of the one post it was made for.
 */
class TokenTest extends TestCase {

	public function test_generated_token_can_be_parsed_back() {
		$token = Token::generate();

		$parsed = Token::parse( $token->to_string() );

		$this->assertNotNull( $parsed );
		$this->assertSame( $token->id, $parsed->id );
		$this->assertSame( $token->secret, $parsed->secret );
	}

	public function test_generated_tokens_differ() {
		$this->assertNotSame( Token::generate()->to_string(), Token::generate()->to_string() );
	}

	/**
	 * @dataProvider data_malformed_tokens
	 *
	 * @param string $value Malformed token.
	 */
	public function test_malformed_token_is_rejected( $value ) {
		$this->assertNull( Token::parse( $value ) );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public function data_malformed_tokens() {
		$secret = str_repeat( 'a', 32 );

		return array(
			'empty'                  => array( '' ),
			'legacy nonce'           => array( '3f9a1c07be' ),
			'no separator'           => array( '3f9a1c07' . $secret ),
			'short link id'          => array( '3f9a1c0.' . $secret ),
			'uppercase link id'      => array( '3F9A1C07.' . $secret ),
			'short secret'           => array( '3f9a1c07.' . substr( $secret, 1 ) ),
			'invalid secret char'    => array( '3f9a1c07.' . substr( $secret, 1 ) . '+' ),
			'trailing data'          => array( '3f9a1c07.' . $secret . '.x' ),
			'surrounding whitespace' => array( ' 3f9a1c07.' . $secret ),
		);
	}

	public function test_legacy_nonce_is_recognized_by_format_only() {
		$this->assertTrue( Token::is_legacy( '3f9a1c07be' ) );
		$this->assertFalse( Token::is_legacy( Token::generate()->to_string() ) );
		$this->assertFalse( Token::is_legacy( '3f9a1c07b' ) );
	}

	public function test_hash_does_not_verify_for_another_post() {
		$token = Token::generate();
		$hash  = $token->hash( 10 );

		$this->assertTrue( $token->verify( 10, $hash ) );
		$this->assertFalse( $token->verify( 11, $hash ) );
	}

	public function test_hash_does_not_verify_with_another_secret() {
		$token = Token::generate();
		$other = Token::parse( $token->id . '.' . str_repeat( 'b', 32 ) );

		$this->assertFalse( $other->verify( 10, $token->hash( 10 ) ) );
	}
}
