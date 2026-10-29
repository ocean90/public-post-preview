<?php
/**
 * Clock class.
 */

namespace DS\PublicPostPreview;

/**
 * Source of the current time, so expiry can be tested without waiting.
 */
final class Clock {

	/**
	 * Frozen timestamp, or null for the real time.
	 *
	 * @var int|null
	 */
	private static $frozen = null;

	/**
	 * Returns the current Unix timestamp.
	 *
	 * @return int Unix timestamp.
	 */
	public static function now(): int {
		return self::$frozen ?? time();
	}

	/**
	 * Freezes the clock at a timestamp.
	 *
	 * @internal For tests only.
	 *
	 * @param int $timestamp Unix timestamp.
	 */
	public static function freeze( int $timestamp ): void {
		self::$frozen = $timestamp;
	}

	/**
	 * Lets the clock follow the real time again.
	 *
	 * @internal For tests only.
	 */
	public static function unfreeze(): void {
		self::$frozen = null;
	}
}
