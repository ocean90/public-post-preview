<?php
/**
 * Tests for the abilities.
 */

namespace DS\PublicPostPreview\Tests;

use DS\PublicPostPreview\Link_Store;
use WP_REST_Request;

/**
 * Abilities must apply exactly the rules of the REST API, since agents reach them without the editor.
 */
class AbilitiesTest extends TestCase {

	/**
	 * Draft by the editor.
	 *
	 * @var int
	 */
	private $post_id;

	public function set_up() {
		parent::set_up();
		$this->post_id = self::factory()->post->create(
			array(
				'post_status' => 'draft',
				'post_author' => self::$editor_id,
			)
		);
	}

	/**
	 * Runs an ability.
	 *
	 * @param string               $name    Ability name without namespace.
	 * @param array<string, mixed> $input   Input.
	 * @param int|null             $user_id User to run as, defaults to the editor.
	 * @return mixed Result.
	 */
	private function run_ability( $name, array $input, $user_id = null ) {
		wp_set_current_user( $user_id ?? self::$editor_id );

		$result = wp_get_ability( 'public-post-preview/' . $name )->execute( $input );

		wp_set_current_user( 0 );

		return $result;
	}

	public function test_link_created_by_an_agent_opens_the_preview() {
		$link = $this->run_ability( 'create-link', array( 'post_id' => $this->post_id ) );

		$this->visit_preview( $link['url'] );

		$this->assertSame( $this->post_id, get_queried_object_id() );
	}

	public function test_link_can_be_listed_changed_and_revoked() {
		$link = $this->run_ability( 'create-link', array( 'post_id' => $this->post_id ) );

		$updated = $this->run_ability(
			'update-link',
			array(
				'post_id' => $this->post_id,
				'link_id' => $link['id'],
				'label'   => 'Client review',
			)
		);
		$this->assertSame( 'Client review', $updated['label'] );
		$this->assertSame( array( $link['id'] ), wp_list_pluck( $this->run_ability( 'list-links', array( 'post_id' => $this->post_id ) ), 'id' ) );

		$this->run_ability(
			'revoke-link',
			array(
				'post_id' => $this->post_id,
				'link_id' => $link['id'],
			)
		);
		$this->assertSame( array(), Link_Store\get_links( $this->post_id ) );
	}

	public function test_list_never_contains_secrets_or_internal_links() {
		$link = $this->run_ability( 'create-link', array( 'post_id' => $this->post_id ) );
		Link_Store\create( $this->post_id, array( 'expires_at' => self::NOW + 900, 'internal' => true ) );

		$list = $this->run_ability( 'list-links', array( 'post_id' => $this->post_id ) );
		$json = wp_json_encode( $list );

		$this->assertCount( 1, $list );
		$this->assertStringNotContainsString( explode( '.', $this->get_token( $link['url'] ) )[1], $json );
		$this->assertStringNotContainsString( Link_Store\find( $this->post_id, $link['id'] )->hash, $json );
	}

	public function test_revoke_all_reports_the_visible_links() {
		$this->run_ability( 'create-link', array( 'post_id' => $this->post_id ) );
		$this->run_ability( 'create-link', array( 'post_id' => $this->post_id ) );

		$this->assertSame( array( 'deleted' => 2 ), $this->run_ability( 'revoke-all-links', array( 'post_id' => $this->post_id ) ) );
	}

	/**
	 * @dataProvider data_users_without_access
	 *
	 * @param string $role Role of the user.
	 */
	public function test_user_without_access_gets_the_same_error_as_from_the_rest_api( $role ) {
		$user = self::factory()->user->create( array( 'role' => $role ) );

		$ability = $this->run_ability( 'create-link', array( 'post_id' => $this->post_id ), $user );

		wp_set_current_user( $user );
		$rest = rest_do_request( new WP_REST_Request( 'POST', "/public-post-preview/v1/posts/{$this->post_id}/links" ) );

		$this->assertWPError( $ability );
		$this->assertSame( $rest->get_data()['code'], $ability->get_error_code() );
		$this->assertSame( array(), Link_Store\get_links( $this->post_id, true ) );
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

	public function test_anonymous_agent_cannot_run_any_ability() {
		foreach ( array( 'list-links', 'create-link', 'revoke-all-links' ) as $name ) {
			$this->assertWPError( $this->run_ability( $name, array( 'post_id' => $this->post_id ), 0 ), $name );
		}
		$this->assertSame( array(), Link_Store\get_links( $this->post_id, true ) );
	}

	public function test_invalid_expiry_is_rejected() {
		$result = $this->run_ability(
			'create-link',
			array(
				'post_id'    => $this->post_id,
				'expires_at' => gmdate( 'Y-m-d\TH:i:s\Z', self::NOW - 1 ),
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( array(), Link_Store\get_links( $this->post_id ) );
	}
}
