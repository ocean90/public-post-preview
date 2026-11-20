<?php
/**
 * Plugin Name: Public Post Preview
 * Version: 4.0.0.beta.1
 * Description: Allow anonymous users to preview a post before it is published.
 * Author: Dominik Schilling
 * Author URI: https://dominikschilling.de/
 * Plugin URI: https://github.com/ocean90/public-post-preview
 * Text Domain: public-post-preview
 * Requires at least: 6.9
 * Tested up to: 7.1
 * Requires PHP: 8.0
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * Previously (2009-2011) maintained by Jonathan Dingman and Matt Martz.
 *
 *  Copyright (C) 2012-2026 Dominik Schilling
 *
 *  This program is free software; you can redistribute it and/or
 *  modify it under the terms of the GNU General Public License
 *  as published by the Free Software Foundation; either version 2
 *  of the License, or (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program; if not, write to the Free Software
 *  Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
 */

/**
 * Don't call this file directly.
 */
if ( ! class_exists( 'WP' ) ) {
	die();
}

require_once __DIR__ . '/inc/class-clock.php';
require_once __DIR__ . '/inc/class-token.php';
require_once __DIR__ . '/inc/class-link.php';
require_once __DIR__ . '/inc/link-store.php';
require_once __DIR__ . '/inc/preview-policy.php';
require_once __DIR__ . '/inc/preview-access.php';
require_once __DIR__ . '/inc/link-service.php';
require_once __DIR__ . '/inc/class-rest-links-controller.php';
require_once __DIR__ . '/inc/class-rest-preview-status-controller.php';
require_once __DIR__ . '/inc/frontend.php';
require_once __DIR__ . '/inc/assets.php';
require_once __DIR__ . '/inc/admin.php';
require_once __DIR__ . '/inc/classic-editor.php';
require_once __DIR__ . '/inc/abilities.php';

use DS\PublicPostPreview\Clock;
use DS\PublicPostPreview\Link_Service;
use DS\PublicPostPreview\Link_Store;
use DS\PublicPostPreview\Preview_Policy;

/**
 * Wires the plugin together and handles what doesn't belong to the frontend or the admin.
 *
 * Inits at 'plugins_loaded' hook.
 */
class DS_Public_Post_Preview {

	/**
	 * Version of the stored data, raised when an upgrade routine is needed.
	 */
	private const DB_VERSION = 4;

	/**
	 * Lifetime of the link User Switching redirects to, in seconds.
	 */
	private const USER_SWITCHING_LINK_LIFETIME = 15 * MINUTE_IN_SECONDS;

	/**
	 * Registers actions and filters.
	 *
	 * @since 1.0.0
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_settings' ) );
		add_action( 'init', array( __CLASS__, 'maybe_upgrade' ) );
		add_action( 'init', 'DS\\PublicPostPreview\\Link_Store\\register_meta' );
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
		add_filter( 'rest_post_dispatch', array( DS\PublicPostPreview\REST_Preview_Status_Controller::class, 'add_nocache_headers' ), 10, 3 );
		add_action( 'transition_post_status', array( __CLASS__, 'delete_links_on_status_change' ), 20, 3 );
		add_filter( 'user_switching_redirect_to', array( __CLASS__, 'user_switching_redirect_to' ), 10, 4 );
		DS\PublicPostPreview\Abilities\register();

		if ( is_admin() ) {
			DS\PublicPostPreview\Admin\register();
			DS\PublicPostPreview\Classic_Editor\register();
		} else {
			DS\PublicPostPreview\Frontend\register();
		}
	}

	/**
	 * Registers the REST API routes.
	 *
	 * @since 4.0.0
	 */
	public static function register_rest_routes() {
		( new DS\PublicPostPreview\REST_Links_Controller() )->register_routes();
		( new DS\PublicPostPreview\REST_Preview_Status_Controller() )->register_routes();
	}

	/**
	 * Drops the data of previous versions.
	 *
	 * Links from before 4.0 are not migrated: they were derived from a nonce and can't be
	 * turned into hashed links.
	 *
	 * @since 4.0.0
	 */
	public static function maybe_upgrade() {
		if ( (int) get_option( 'public_post_preview_db_version' ) >= self::DB_VERSION ) {
			return;
		}

		delete_option( 'public_post_preview' );
		update_option( 'public_post_preview_db_version', self::DB_VERSION );
	}

	/**
	 * Registers the settings used by the plugin.
	 *
	 * @since 3.0.0
	 */
	public static function register_settings() {
		register_setting(
			'reading',
			'public_post_preview_expiration_time',
			array(
				'show_in_rest' => true,
				'type'         => 'integer',
				'description'  => __( 'Default expiration time in seconds.', 'public-post-preview' ),
				'default'      => 48,
			)
		);
	}

	/**
	 * Redirects to the public preview of a post when switching off from its editing screen with User Switching.
	 *
	 * The redirect goes through an internal link that lives 15 minutes and never shows up in the UI.
	 *
	 * @since 2.10.0
	 *
	 * @param string       $redirect_to   The target redirect location, or an empty string if none is specified.
	 * @param string|null  $redirect_type The redirect type, see the `user_switching::REDIRECT_*` constants.
	 * @param WP_User|null $new_user      The user being switched to, or null if there is none.
	 * @param WP_User|null $old_user      The user being switched from, or null if there is none.
	 * @return string The target redirect location.
	 */
	public static function user_switching_redirect_to( $redirect_to, $redirect_type, $new_user, $old_user ) {
		if ( null !== $new_user || 'post' !== $redirect_type || ! $old_user ) {
			return $redirect_to;
		}

		$post_id = isset( $_GET['redirect_to_post'] ) ? (int) $_GET['redirect_to_post'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- User Switching verifies its own nonce; edit_post is checked below.
		$post    = $post_id ? get_post( $post_id ) : null;

		if ( ! $post || ! user_can( $old_user, 'edit_post', $post->ID ) || ! Preview_Policy\is_previewable( $post ) ) {
			return $redirect_to;
		}

		Link_Store\prune_expired( $post->ID );

		$created = Link_Store\create(
			$post->ID,
			array(
				'expires_at' => Clock::now() + self::USER_SWITCHING_LINK_LIFETIME,
				'created_by' => $old_user->ID,
				'internal'   => true,
			)
		);

		if ( is_wp_error( $created ) ) {
			return $redirect_to;
		}

		return Preview_Policy\get_preview_url( $post, $created['token']->to_string() );
	}

	/**
	 * Deletes all links of a post that gets published or trashed.
	 *
	 * @since 4.0.0
	 *
	 * @param string  $new_status New post status.
	 * @param string  $old_status Old post status.
	 * @param WP_Post $post       Post object.
	 */
	public static function delete_links_on_status_change( $new_status, $old_status, $post ) {
		if ( $new_status === $old_status ) {
			return;
		}

		$statuses   = Preview_Policy\get_published_statuses();
		$statuses[] = 'trash';

		if ( in_array( $new_status, $statuses, true ) ) {
			Link_Service\remove_all_links( $post->ID );
		}
	}

	/**
	 * Deletes the plugin's data when the plugin is uninstalled.
	 *
	 * @since 2.0.0
	 */
	public static function uninstall() {
		delete_option( 'public_post_preview' );
		delete_option( 'public_post_preview_db_version' );
		delete_post_meta_by_key( Link_Store\META_KEY );
		delete_post_meta_by_key( Link_Store\EXPIRY_META_KEY );
	}

	/**
	 * Performs actions on plugin activation.
	 *
	 * @since 2.9.4
	 */
	public static function activate() {
		register_uninstall_hook( __FILE__, array( 'DS_Public_Post_Preview', 'uninstall' ) );
	}
}

add_action( 'plugins_loaded', array( 'DS_Public_Post_Preview', 'init' ) );

register_activation_hook( __FILE__, array( 'DS_Public_Post_Preview', 'activate' ) );
