<?php
/**
 * Enqueues the built scripts and styles from `js/dist`.
 */

namespace DS\PublicPostPreview\Assets;

use DS\PublicPostPreview\Link_Store;
use DS\PublicPostPreview\Preview_Policy;

/**
 * Enqueues a script entry with the dependencies and version from its asset file.
 *
 * @param string $handle Script handle.
 * @param string $entry  Name of the webpack entry.
 * @return string Version of the build, for the entry's style.
 */
function enqueue_script( string $handle, string $entry ): string {
	$asset_file = dirname( __DIR__ ) . "/js/dist/{$entry}.asset.php";
	$asset      = file_exists( $asset_file ) ?
		require $asset_file : // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable -- The entry names are fixed in the plugin.
		array(
			'dependencies' => array(),
			'version'      => '',
		);

	wp_enqueue_script( $handle, plugins_url( "js/dist/{$entry}.js", __DIR__ ), $asset['dependencies'], $asset['version'], true );

	return $asset['version'];
}

/**
 * Enqueues the preview link UI of an editor with its translations, settings and style.
 *
 * @param string               $handle     Script and style handle.
 * @param string               $entry      Name of the webpack entry.
 * @param array<string, mixed> $settings   Settings of this editor, added to the shared ones.
 * @param string[]             $style_deps Style dependencies.
 */
function enqueue_editor_ui( string $handle, string $entry, array $settings, array $style_deps = array() ): void {
	$version = enqueue_script( $handle, $entry );
	wp_set_script_translations( $handle, 'public-post-preview' );

	$settings = array_merge(
		array(
			'defaultLifetimeLabel' => format_lifetime( Preview_Policy\get_default_lifetime() ),
			'maxLifetime'          => Link_Store\get_max_lifetime(),
		),
		$settings
	);
	wp_add_inline_script( $handle, 'window.DSPublicPostPreview = ' . wp_json_encode( $settings ) . ';', 'before' );

	wp_enqueue_style( $handle, plugins_url( "js/dist/{$entry}.css", __DIR__ ), $style_deps, $version );
}

/**
 * Formats a lifetime as days, or as hours if it isn't a whole number of days.
 *
 * `human_time_diff()` doesn't fit: its words are meant for "… ago" and read wrong in some languages.
 *
 * @internal
 *
 * @param int $seconds Lifetime in seconds.
 * @return string Duration, e.g. "2 days".
 */
function format_lifetime( int $seconds ): string {
	if ( 0 === $seconds % DAY_IN_SECONDS ) {
		$days = intdiv( $seconds, DAY_IN_SECONDS );

		/* translators: %s: Number of days. */
		return sprintf( _n( '%s day', '%s days', $days, 'public-post-preview' ), number_format_i18n( $days ) );
	}

	$hours = (int) ceil( $seconds / HOUR_IN_SECONDS );

	/* translators: %s: Number of hours. */
	return sprintf( _n( '%s hour', '%s hours', $hours, 'public-post-preview' ), number_format_i18n( $hours ) );
}
