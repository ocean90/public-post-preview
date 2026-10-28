<?php
/**
 * Link class.
 */

namespace DS\PublicPostPreview;

/**
 * A stored preview link. Holds the hash of its token, never the secret.
 */
final class Link {

	/**
	 * Public link ID.
	 *
	 * @var string
	 */
	public $id;

	/**
	 * Hash of the token.
	 *
	 * @var string
	 */
	public $hash;

	/**
	 * Last four characters of the secret, to tell links apart.
	 *
	 * @var string
	 */
	public $hint;

	/**
	 * Optional label.
	 *
	 * @var string
	 */
	public $label;

	/**
	 * Creation time, Unix timestamp.
	 *
	 * @var int
	 */
	public $created_at;

	/**
	 * ID of the user who created the link.
	 *
	 * @var int
	 */
	public $created_by;

	/**
	 * Expiry time, Unix timestamp.
	 *
	 * @var int
	 */
	public $expires_at;

	/**
	 * First visit, Unix timestamp.
	 *
	 * @var int|null
	 */
	public $first_accessed_at;

	/**
	 * Last visit, Unix timestamp.
	 *
	 * @var int|null
	 */
	public $last_accessed_at;

	/**
	 * Whether the link is internal and hidden from the UI.
	 *
	 * @var bool
	 */
	public $internal;

	/**
	 * Restores a link from its stored array.
	 *
	 * @param mixed $data Stored meta value.
	 * @return self|null The link, or null for a malformed value.
	 */
	public static function from_array( $data ): ?self {
		if ( ! is_array( $data ) || empty( $data['id'] ) || empty( $data['hash'] ) || empty( $data['expires_at'] ) ) {
			return null;
		}

		$link                    = new self();
		$link->id                = (string) $data['id'];
		$link->hash              = (string) $data['hash'];
		$link->hint              = (string) ( $data['hint'] ?? '' );
		$link->label             = (string) ( $data['label'] ?? '' );
		$link->created_at        = (int) ( $data['created_at'] ?? 0 );
		$link->created_by        = (int) ( $data['created_by'] ?? 0 );
		$link->expires_at        = (int) $data['expires_at'];
		$link->first_accessed_at = isset( $data['first_accessed_at'] ) ? (int) $data['first_accessed_at'] : null;
		$link->last_accessed_at  = isset( $data['last_accessed_at'] ) ? (int) $data['last_accessed_at'] : null;
		$link->internal          = ! empty( $data['internal'] );

		return $link;
	}

	/**
	 * Returns the array that is stored in post meta.
	 *
	 * @return array<string, mixed> Stored value.
	 */
	public function to_array(): array {
		return array(
			'id'                => $this->id,
			'hash'              => $this->hash,
			'hint'              => $this->hint,
			'label'             => $this->label,
			'created_at'        => $this->created_at,
			'created_by'        => $this->created_by,
			'expires_at'        => $this->expires_at,
			'first_accessed_at' => $this->first_accessed_at,
			'last_accessed_at'  => $this->last_accessed_at,
			'internal'          => $this->internal,
		);
	}

	/**
	 * Whether the link has expired.
	 *
	 * @param int $now Unix timestamp.
	 * @return bool True if expired.
	 */
	public function is_expired( int $now ): bool {
		return $now >= $this->expires_at;
	}
}
