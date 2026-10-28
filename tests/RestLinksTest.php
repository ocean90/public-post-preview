<?php
/**
 * Tests for the links REST API.
 */

namespace DS\PublicPostPreview\Tests;

use DS\PublicPostPreview\Clock;
use DS\PublicPostPreview\Link_Store;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Only people who can edit a post may manage its links, and no response but the creation one
 * may ever carry a usable link.
 */
class RestLinksTest extends TestCase {

	private const NOW = 1793000000;

	/**
	 * Draft by the editor.
	 *
	 * @var int
	 */
	private $post_id;

	public function set_up() {
		parent::set_up();
		Clock::freeze( self::NOW );
		$this->post_id = self::factory()->post->create(
			array(
				'post_status' => 'draft',
				'post_author' => self::$editor_id,
			)
		);
	}

	public function tear_down() {
		Clock::unfreeze();
		parent::tear_down();
	}

	/**
	 * Sends a REST request.
	 *
	 * @param string               $method  HTTP method.
	 * @param string               $path    Route below the plugin namespace.
	 * @param array<string, mixed> $params  Body parameters.
	 * @param int|null             $user_id User to send as, defaults to the editor.
	 * @return WP_REST_Response The response.
	 */
	private function request( $method, $path, array $params = array(), $user_id = null ) {
		wp_set_current_user( $user_id ?? self::$editor_id );

		$request = new WP_REST_Request( $method, '/public-post-preview/v1' . $path );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return rest_do_request( $request );
	}

	/**
	 * Creates a link through the API and returns its response data.
	 *
	 * @param array<string, mixed> $params Body parameters.
	 * @return array<string, mixed> Response data.
	 */
	private function create_link( array $params = array() ) {
		$response = $this->request( 'POST', "/posts/{$this->post_id}/links", $params );
		$this->assertSame( 201, $response->get_status() );

		return $response->get_data();
	}

	/**
	 * Formats a timestamp the way the API does.
	 *
	 * @param int $timestamp Unix timestamp.
	 * @return string ISO 8601 date in UTC.
	 */
	private function iso( $timestamp ) {
		return gmdate( 'Y-m-d\TH:i:s\Z', $timestamp );
	}

	public function test_creating_a_link_returns_its_url_once() {
		$link = $this->create_link();

		$this->assertStringContainsString( '_ppp=' . $link['id'] . '.', $link['url'] );

		$list = $this->request( 'GET', "/posts/{$this->post_id}/links" )->get_data();
		$this->assertCount( 1, $list );
		$this->assertArrayNotHasKey( 'url', $list[0] );
	}

	public function test_no_response_contains_a_hash_or_secret() {
		$link   = $this->create_link( array( 'label' => 'Client review' ) );
		$secret = explode( '.', $this->get_token( $link['url'] ) )[1];
		$hash   = Link_Store\find( $this->post_id, $link['id'] )->hash;

		$responses = array(
			$this->request( 'GET', "/posts/{$this->post_id}/links" ),
			$this->request( 'PATCH', "/posts/{$this->post_id}/links/{$link['id']}", array( 'label' => 'Copy editing' ) ),
			$this->request( 'DELETE', "/posts/{$this->post_id}/links/{$link['id']}" ),
		);
		foreach ( $responses as $response ) {
			$json = wp_json_encode( $response->get_data() );
			$this->assertStringNotContainsString( $secret, $json );
			$this->assertStringNotContainsString( $hash, $json );
		}
	}

	public function test_new_link_uses_the_default_lifetime() {
		$link = $this->create_link();

		$this->assertSame( $this->iso( self::NOW + 48 * HOUR_IN_SECONDS ), $link['expires_at'] );
	}

	public function test_new_link_can_have_its_own_expiry_and_label() {
		$link = $this->create_link(
			array(
				'label'      => 'Client review',
				'expires_at' => $this->iso( self::NOW + WEEK_IN_SECONDS ),
			)
		);

		$this->assertSame( 'Client review', $link['label'] );
		$this->assertSame( $this->iso( self::NOW + WEEK_IN_SECONDS ), $link['expires_at'] );
	}

	/**
	 * @dataProvider data_invalid_expiries
	 *
	 * @param int $offset Seconds from now.
	 */
	public function test_invalid_expiry_is_rejected( $offset ) {
		$response = $this->request( 'POST', "/posts/{$this->post_id}/links", array( 'expires_at' => $this->iso( self::NOW + $offset ) ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( array(), Link_Store\get_links( $this->post_id ) );
	}

	/**
	 * @return array<string, array{int}>
	 */
	public function data_invalid_expiries() {
		return array(
			'past'            => array( -1 ),
			'beyond one year' => array( YEAR_IN_SECONDS + 1 ),
		);
	}

	public function test_link_can_be_extended_and_renamed() {
		$link = $this->create_link();

		$response = $this->request(
			'PATCH',
			"/posts/{$this->post_id}/links/{$link['id']}",
			array(
				'label'      => 'Client review',
				'expires_at' => $this->iso( self::NOW + 30 * DAY_IN_SECONDS ),
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'Client review', $response->get_data()['label'] );
		$this->assertSame( $this->iso( self::NOW + 30 * DAY_IN_SECONDS ), $response->get_data()['expires_at'] );
	}

	public function test_link_of_another_post_cannot_be_changed_or_revoked() {
		$link  = $this->create_link();
		$other = self::factory()->post->create( array( 'post_status' => 'draft' ) );

		$this->assertSame( 404, $this->request( 'PATCH', "/posts/{$other}/links/{$link['id']}", array( 'label' => 'x' ) )->get_status() );
		$this->assertSame( 404, $this->request( 'DELETE', "/posts/{$other}/links/{$link['id']}" )->get_status() );
		$this->assertNotNull( Link_Store\find( $this->post_id, $link['id'] ) );
	}

	public function test_revoking_a_link_keeps_the_others() {
		$first  = $this->create_link();
		$second = $this->create_link();

		$response = $this->request( 'DELETE', "/posts/{$this->post_id}/links/{$first['id']}" );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['deleted'] );
		$this->assertNull( Link_Store\find( $this->post_id, $first['id'] ) );
		$this->assertNotNull( Link_Store\find( $this->post_id, $second['id'] ) );
	}

	public function test_revoking_all_links_counts_only_visible_links() {
		$this->create_link();
		$this->create_link();
		Link_Store\create( $this->post_id, array( 'expires_at' => self::NOW + 900, 'internal' => true ) );

		$response = $this->request( 'DELETE', "/posts/{$this->post_id}/links" );

		$this->assertSame( 2, $response->get_data()['deleted'] );
		$this->assertSame( array(), Link_Store\get_links( $this->post_id, true ) );
	}

	public function test_internal_and_expired_links_are_not_listed() {
		$this->create_link( array( 'expires_at' => $this->iso( self::NOW + 60 ) ) );
		$valid = $this->create_link();
		Link_Store\create( $this->post_id, array( 'expires_at' => self::NOW + 900, 'internal' => true ) );

		Clock::freeze( self::NOW + 60 );
		$list = $this->request( 'GET', "/posts/{$this->post_id}/links" )->get_data();

		$this->assertSame( array( $valid['id'] ), wp_list_pluck( $list, 'id' ) );
	}

	public function test_anonymous_request_is_rejected_without_revealing_the_post() {
		$existing = $this->request( 'GET', "/posts/{$this->post_id}/links", array(), 0 );
		$missing  = $this->request( 'GET', '/posts/999999/links', array(), 0 );

		$this->assertSame( 401, $existing->get_status() );
		$this->assertSame( $missing->get_status(), $existing->get_status() );
		$this->assertSame( $missing->get_data()['code'], $existing->get_data()['code'] );
	}

	/**
	 * @dataProvider data_users_without_access
	 *
	 * @param string $role Role of the requesting user.
	 */
	public function test_user_who_cannot_edit_the_post_gets_the_same_404_as_for_a_missing_post( $role ) {
		$user = self::factory()->user->create( array( 'role' => $role ) );

		foreach ( array( 'GET', 'POST', 'DELETE' ) as $method ) {
			$existing = $this->request( $method, "/posts/{$this->post_id}/links", array(), $user );
			$missing  = $this->request( $method, '/posts/999999/links', array(), $user );

			$this->assertSame( 404, $existing->get_status(), $method );
			$this->assertSame( $missing->get_data()['code'], $existing->get_data()['code'], $method );
		}
		$this->assertSame( array(), Link_Store\get_links( $this->post_id ) );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public function data_users_without_access() {
		return array(
			'subscriber'  => array( 'subscriber' ),
			'contributor' => array( 'contributor' ),
		);
	}

	public function test_contributor_can_share_their_own_draft() {
		$contributor = self::factory()->user->create( array( 'role' => 'contributor' ) );
		$draft       = self::factory()->post->create(
			array(
				'post_status' => 'draft',
				'post_author' => $contributor,
			)
		);

		$this->assertSame( 201, $this->request( 'POST', "/posts/{$draft}/links", array(), $contributor )->get_status() );
	}

	/**
	 * @dataProvider data_posts_without_previews
	 *
	 * @param array<string, mixed> $post Post fields.
	 */
	public function test_post_that_cannot_be_previewed_gets_no_links( $post ) {
		$post_id = self::factory()->post->create( $post );

		$this->assertSame( 400, $this->request( 'POST', "/posts/{$post_id}/links" )->get_status() );
		$this->assertSame( array(), Link_Store\get_links( $post_id, true ) );
	}

	/**
	 * @return array<string, array{array<string, mixed>}>
	 */
	public function data_posts_without_previews() {
		return array(
			'published'    => array( array( 'post_status' => 'publish' ) ),
			'private'      => array( array( 'post_status' => 'private' ) ),
			'trashed'      => array( array( 'post_status' => 'trash' ) ),
			'auto-draft'   => array( array( 'post_status' => 'auto-draft' ) ),
			'not viewable' => array(
				array(
					'post_status' => 'draft',
					'post_type'   => 'wp_block',
				),
			),
		);
	}
}
