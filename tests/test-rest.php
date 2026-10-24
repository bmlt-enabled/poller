<?php
/**
 * Tests for the public REST API: GET a poll and POST a vote.
 */

use BmltEnabled\Poller\Repository;
use BmltEnabled\Poller\Voter;

class Test_Rest extends WP_UnitTestCase {

	private Repository $repo;

	/** @var \WP_REST_Server */
	private $server;

	public function setUp(): void {
		parent::setUp();
		poller_reset_tables();
		$_COOKIE[ Voter::COOKIE ] = str_repeat( 'f', 64 );

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		$this->server = $wp_rest_server;
		do_action( 'rest_api_init' );

		$this->repo = new Repository();
	}

	public function tearDown(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		unset( $_COOKIE[ Voter::COOKIE ] );
		parent::tearDown();
	}

	/**
	 * @return array{0: int, 1: array<int, int>}
	 */
	private function make_poll(): array {
		$id = $this->repo->insert_poll(
			[
				'code' => 'K7MQ2P',
				'title' => 'T',
				'question' => 'Q?',
				'image_id' => 0,
				'kind' => 'text',
				'selection' => 'single',
				'budget' => 0,
				'per_choice' => 0,
				'spend_all' => false,
				'status' => 'open',
			]
		);
		$this->repo->replace_choices( $id, [ [ 'label' => 'A', 'image_id' => 0 ], [ 'label' => 'B', 'image_id' => 0 ] ] );
		$ids = array_map( 'intval', array_column( $this->repo->get_choices( $id ), 'id' ) );
		return [ $id, $ids ];
	}

	public function test_routes_are_registered() {
		$routes = $this->server->get_routes();
		$this->assertArrayHasKey( '/poller/v1/polls/(?P<code>[A-Za-z0-9]{6})', $routes );
		$this->assertArrayHasKey( '/poller/v1/polls/(?P<code>[A-Za-z0-9]{6})/votes', $routes );
	}

	public function test_get_poll_returns_payload() {
		$this->make_poll();
		$request = new WP_REST_Request( 'GET', '/poller/v1/polls/K7MQ2P' );
		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'K7MQ2P', $data['code'] );
		$this->assertSame( [ 'A', 'B' ], array_column( $data['choices'], 'label' ) );
	}

	public function test_get_unknown_poll_is_404() {
		$request = new WP_REST_Request( 'GET', '/poller/v1/polls/ZZZZZZ' );
		$response = rest_do_request( $request );
		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'poller_missing', $response->get_data()['code'] );
	}

	public function test_vote_without_a_nonce_is_rejected() {
		[ , $ids ] = $this->make_poll();
		$request = new WP_REST_Request( 'POST', '/poller/v1/polls/K7MQ2P/votes' );
		$request->set_param( 'choices', [ (string) $ids[0] ] );
		$response = rest_do_request( $request );
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'poller_nonce', $response->get_data()['code'] );
	}

	public function test_vote_with_a_nonce_records_the_ballot() {
		[ $id, $ids ] = $this->make_poll();
		$request = new WP_REST_Request( 'POST', '/poller/v1/polls/K7MQ2P/votes' );
		$request->set_param( 'nonce', wp_create_nonce( 'poller_vote_K7MQ2P' ) );
		$request->set_param( 'choices', [ (string) $ids[0] ] );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 1, $response->get_data()['ballots'] );
		$this->assertSame( 1, $this->repo->ballot_count( $id ) );
	}
}
