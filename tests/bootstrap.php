<?php
/**
 * PHPUnit bootstrap file.
 *
 * Runs inside the wp-env test environment, which provides the WordPress test library via WP_TESTS_DIR.
 */

$tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $tests_dir || ! file_exists( $tests_dir . '/includes/functions.php' ) ) {
	echo 'The WordPress test library was not found. Run the tests with `npm run test:php`.' . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI output.
	exit( 1 );
}

require_once $tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		require dirname( __DIR__ ) . '/public-post-preview.php';
	}
);

require $tests_dir . '/includes/bootstrap.php';

require_once __DIR__ . '/TestCase.php';
