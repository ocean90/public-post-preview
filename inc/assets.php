<?php
/**
 * Enqueues the built scripts and styles from `js/dist`.
 */

namespace DS\PublicPostPreview\Assets;

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
	wp_set_script_translations( $handle, 'public-post-preview' );

	return $asset['version'];
}

/**
 * Enqueues the style of a webpack entry.
 *
 * @param string   $handle  Style handle.
 * @param string   $entry   Name of the webpack entry.
 * @param string[] $deps    Style dependencies.
 * @param string   $version Version of the build.
 */
function enqueue_style( string $handle, string $entry, array $deps, string $version ): void {
	wp_enqueue_style( $handle, plugins_url( "js/dist/{$entry}.css", __DIR__ ), $deps, $version );
}

/**
 * Passes settings to a script as `window.DSPublicPostPreview`.
 *
 * @param string               $handle   Script handle.
 * @param array<string, mixed> $settings Settings.
 */
function add_settings( string $handle, array $settings ): void {
	wp_add_inline_script( $handle, 'window.DSPublicPostPreview = ' . wp_json_encode( $settings ) . ';', 'before' );
}
