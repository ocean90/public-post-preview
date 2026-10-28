<?php
/**
 * Tests for User Switching, the upgrade from 3.x and the post list.
 */

namespace DS\PublicPostPreview\Tests;

use DS\PublicPostPreview\Clock;
use DS\PublicPostPreview\Link_Store;
use DS_Public_Post_Preview;

/**
 * Integrations that create or count links outside the editor.
 */
class IntegrationTest extends TestCase {

	private const NOW = 1793000000;

	public function set_up() {
		parent::set_up();
		Clock::freeze( self::NOW );
	}

	public function tear_down() {
		Clock::unfreeze();
		unset( $_GET['redirect_to_post'] );
		parent::tear_down();
	}

	public function test_switching_off_from_a_draft_leads_to_a_working_preview() {
		$post                      = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$_GET['redirect_to_post'] = (string) $post;

		$url = DS_Public_Post_Preview::user_switching_redirect_to( '', null, null, get_userdata( self::$editor_id ) );

		$this->visit_preview( $url );
		$this->assertSame( $post, get_queried_object_id() );
	}

	public function test_switching_off_creates_a_short_internal_link_nobody_sees() {
		$post                      = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$_GET['redirect_to_post'] = (string) $post;

		DS_Public_Post_Preview::user_switching_redirect_to( '', null, null, get_userdata( self::$editor_id ) );

		$this->assertSame( array(), Link_Store\get_links( $post ) );
		$this->assertSame( 0, Link_Store\get_latest_expiry( $post ) );
		$this->assertSame( self::NOW + 15 * MINUTE_IN_SECONDS, Link_Store\get_links( $post, true )[0]->expires_at );
	}

	public function test_switching_off_without_edit_rights_keeps_the_redirect() {
		$post                      = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$_GET['redirect_to_post'] = (string) $post;
		$subscriber                = get_userdata( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->assertSame( 'https://example.org/', DS_Public_Post_Preview::user_switching_redirect_to( 'https://example.org/', null, null, $subscriber ) );
		$this->assertSame( array(), Link_Store\get_links( $post, true ) );
	}

	public function test_upgrade_drops_the_old_option_once() {
		update_option( 'public_post_preview', array( 12, 48 ) );
		delete_option( 'public_post_preview_db_version' );

		DS_Public_Post_Preview::maybe_upgrade();

		$this->assertFalse( get_option( 'public_post_preview' ) );
		$this->assertSame( 4, (int) get_option( 'public_post_preview_db_version' ) );
	}

	public function test_post_list_counts_only_active_visible_links() {
		$post = self::factory()->post->create_and_get( array( 'post_status' => 'draft' ) );
		$this->create_link( $post->ID );
		$this->create_link( $post->ID, array( 'expires_at' => gmdate( 'c', self::NOW + 60 ) ) );
		Link_Store\create( $post->ID, array( 'expires_at' => self::NOW + 900, 'internal' => true ) );

		Clock::freeze( self::NOW + 60 );
		$states = DS_Public_Post_Preview::display_preview_state( array(), $post );

		$this->assertSame( 'Public Preview (1 link)', $states['ppp_enabled'] );
	}

	public function test_post_list_shows_no_state_without_links() {
		$post = self::factory()->post->create_and_get( array( 'post_status' => 'draft' ) );
		Link_Store\create( $post->ID, array( 'expires_at' => self::NOW + 900, 'internal' => true ) );

		$this->assertSame( array(), DS_Public_Post_Preview::display_preview_state( array(), $post ) );
	}
}
