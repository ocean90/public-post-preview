<?php
/**
 * Token class.
 */

namespace DS\PublicPostPreview;

/**
 * The `<id>.<secret>` value in the `_ppp` query parameter of a preview link.
 */
final class Token {

	/**
	 * Regular expression for a link ID, without delimiters.
	 */
	public const ID_PATTERN = '[0-9a-f]{8}';

	/**
	 * Public link ID, 8 hex characters.
	 *
	 * @var string
	 */
	public $id;

	/**
	 * Secret, 32 base64url characters (192 bits).
	 *
	 * @var string
	 */
	public $secret;

	/**
	 * Constructor.
	 *
	 * @param string $id     Link ID.
	 * @param string $secret Secret.
	 */
	private function __construct( string $id, string $secret ) {
		$this->id     = $id;
		$this->secret = $secret;
	}

	/**
	 * Creates a random token.
	 *
	 * @return self The token.
	 */
	public static function generate(): self {
		return new self(
			bin2hex( random_bytes( 4 ) ),
			sodium_bin2base64( random_bytes( 24 ), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING )
		);
	}

	/**
	 * Parses a token from a query parameter.
	 *
	 * @param string $value Raw value.
	 * @return self|null The token, or null if the value is not a token.
	 */
	public static function parse( string $value ): ?self {
		if ( ! preg_match( '/^(' . self::ID_PATTERN . ')\.([A-Za-z0-9_-]{32})$/D', $value, $matches ) ) {
			return null;
		}

		return new self( $matches[1], $matches[2] );
	}

	/**
	 * Whether a value has the format of a preview link from before 4.0.
	 *
	 * @param string $value Raw value.
	 * @return bool True for a legacy nonce.
	 */
	public static function is_legacy( string $value ): bool {
		return 1 === preg_match( '/^[0-9a-f]{10}$/D', $value );
	}

	/**
	 * Returns the token as it appears in a preview link.
	 *
	 * @return string The token.
	 */
	public function to_string(): string {
		return $this->id . '.' . $this->secret;
	}

	/**
	 * Hashes the token for storage.
	 *
	 * The post ID is part of the hash, so a link copied along with post meta
	 * (duplicated or translated posts) doesn't unlock the copy.
	 *
	 * @param int $post_id Post ID.
	 * @return string The hash.
	 */
	public function hash( int $post_id ): string {
		return wp_fast_hash( $this->hash_input( $post_id ) );
	}

	/**
	 * Checks the token against a stored hash.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $hash    Stored hash.
	 * @return bool True if the token matches.
	 */
	public function verify( int $post_id, string $hash ): bool {
		return wp_verify_fast_hash( $this->hash_input( $post_id ), $hash );
	}

	/**
	 * Returns the value that gets hashed.
	 *
	 * @param int $post_id Post ID.
	 * @return string Hash input.
	 */
	private function hash_input( int $post_id ): string {
		return $post_id . ':' . $this->id . ':' . $this->secret;
	}
}
