<?php
/**
 * Tests for the public join, vote, and room-display pages.
 */

use BmltEnabled\Poller\Frontend;
use BmltEnabled\Poller\Presenter;
use BmltEnabled\Poller\Repository;
use BmltEnabled\Poller\Voter;

class Test_Frontend extends WP_UnitTestCase {

	private Repository $repo;
	private Frontend $frontend;

	public function setUp(): void {
		parent::setUp();
		poller_reset_tables();
		$_COOKIE[ Voter::COOKIE ] = str_repeat( 'f', 64 );
		$this->repo = new Repository();
		$presenter = new Presenter( $this->repo );
		$this->frontend = new Frontend( $this->repo, $presenter, new \BmltEnabled\Poller\VoteService( $this->repo, $presenter ) );
	}

	public function tearDown(): void {
		$_GET = [];
		$_POST = [];
		unset( $_COOKIE[ Voter::COOKIE ] );
		set_query_var( 'poller_view', '' );
		set_query_var( 'poller_code', '' );
		parent::tearDown();
	}

	/**
	 * @param array<string, mixed> $overrides
	 */
	private function make_poll( array $overrides = [], array $choices = [ [ 'label' => 'A', 'image_id' => 0 ], [ 'label' => 'B', 'image_id' => 0 ] ] ): array {
		$id = $this->repo->insert_poll(
			array_merge(
				[
					'code' => 'K7MQ2P',
					'title' => 'T',
					'question' => 'Which snack?',
					'image_id' => 0,
					'kind' => 'text',
					'selection' => 'single',
					'budget' => 0,
					'per_choice' => 0,
					'spend_all' => false,
					'status' => 'open',
				],
				$overrides
			)
		);
		if ( $choices && 'words' !== ( $overrides['selection'] ?? '' ) ) {
			$this->repo->replace_choices( $id, $choices );
		}
		return $this->repo->get_poll( $id );
	}

	/**
	 * @param mixed ...$args
	 */
	private function render_private( string $method, ...$args ): string {
		$ref = new ReflectionMethod( Frontend::class, $method );
		$ref->setAccessible( true );
		ob_start();
		try {
			$ref->invoke( $this->frontend, ...$args );
		} finally {
			$html = (string) ob_get_clean();
		}
		return $html;
	}

	// -------------------------------------------------------------------------
	// total_label (public static)
	// -------------------------------------------------------------------------

	public function test_total_label() {
		$this->assertSame( 'No votes yet', Frontend::total_label( 0 ) );
		$this->assertSame( '1 vote', Frontend::total_label( 1 ) );
		$this->assertStringContainsString( 'votes', Frontend::total_label( 5 ) );
		$dots = Frontend::total_label( 4, [ 'selection' => 'dots', 'dots' => 7 ] );
		$this->assertStringContainsString( '7 dots', $dots );
		$this->assertStringContainsString( '1 dot', Frontend::total_label( 2, [ 'selection' => 'dots', 'dots' => 1 ] ) );
	}

	// -------------------------------------------------------------------------
	// canonical / pre_handle_404 / route
	// -------------------------------------------------------------------------

	public function test_canonical_and_404_defer_to_poller_views() {
		set_query_var( 'poller_view', 'poll' );
		$this->assertFalse( $this->frontend->canonical( 'https://example.org/x' ) );
		$this->assertTrue( $this->frontend->pre_handle_404( false, $GLOBALS['wp_query'] ) );

		set_query_var( 'poller_view', '' );
		$this->assertSame( 'https://example.org/x', $this->frontend->canonical( 'https://example.org/x' ) );
		$this->assertFalse( $this->frontend->pre_handle_404( false, $GLOBALS['wp_query'] ) );
	}

	public function test_route_does_nothing_without_a_view() {
		set_query_var( 'poller_view', '' );
		$this->frontend->route();
		$this->assertTrue( true, 'route returns when there is no poller view' );
	}

	// -------------------------------------------------------------------------
	// Join page
	// -------------------------------------------------------------------------

	public function test_render_join_shows_the_form() {
		$html = $this->render_private( 'render_join' );
		$this->assertStringContainsString( 'Enter the code', $html );
	}

	public function test_render_join_reports_an_ambiguous_code() {
		$_GET['code'] = 'K7MQ20';
		$html = $this->render_private( 'render_join' );
		$this->assertStringContainsString( '0, 1, I, L, and O', $html );
	}

	public function test_render_join_redirects_a_valid_code() {
		$this->make_poll();
		$_GET['code'] = 'k7mq2p';
		$location = poller_catch_redirect( fn() => $this->render_private( 'render_join' ) );
		$this->assertStringContainsString( 'K7MQ2P', $location );
	}

	// -------------------------------------------------------------------------
	// Poll / board pages
	// -------------------------------------------------------------------------

	public function test_render_poll_page_shows_question_and_choices() {
		$this->make_poll();
		set_query_var( 'poller_code', 'K7MQ2P' );
		$html = $this->render_private( 'render_poll', 'poll' );
		$this->assertStringContainsString( 'Which snack?', $html );
		$this->assertStringContainsString( 'id="poller-state"', $html );
	}

	public function test_render_board_shows_the_code_and_qr() {
		$this->make_poll();
		set_query_var( 'poller_code', 'K7MQ2P' );
		$html = $this->render_private( 'render_poll', 'board' );
		$this->assertStringContainsString( 'K7MQ2P', $html );
		$this->assertTrue( wp_script_is( 'poller-qrcode', 'enqueued' ) );
	}

	public function test_render_poll_unknown_code_is_missing() {
		set_query_var( 'poller_code', 'ZZZZZZ' );
		$html = $this->render_private( 'render_poll', 'poll' );
		$this->assertStringContainsString( 'Poll not found', $html );
	}

	public function test_render_poll_dots_summary() {
		$poll = $this->make_poll( [ 'selection' => 'dots', 'budget' => 5, 'per_choice' => 3, 'spend_all' => false ] );
		$choices = $this->repo->get_choices( (int) $poll['id'] );
		$this->repo->cast( (int) $poll['id'], str_repeat( 'a', 64 ), [ (int) $choices[0]['id'] => 3, (int) $choices[1]['id'] => 2 ] );
		set_query_var( 'poller_code', 'K7MQ2P' );
		$html = $this->render_private( 'render_poll', 'poll' );
		$this->assertStringContainsString( 'dots', $html );
	}

	public function test_render_poll_words_cloud_and_admin_moderation() {
		$poll = $this->make_poll( [ 'selection' => 'words', 'kind' => 'text', 'budget' => 3 ], [] );
		$this->repo->cast_words( (int) $poll['id'], str_repeat( 'b', 64 ), [ [ 'text' => 'Hope', 'key' => 'hope' ] ] );
		set_query_var( 'poller_code', 'K7MQ2P' );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$html = $this->render_private( 'render_poll', 'poll' );
		$this->assertStringContainsString( 'data-poller-cloud', $html );
		$this->assertTrue( wp_script_is( 'poller-cloud', 'enqueued' ) );
	}

	public function test_render_poll_shows_a_vote_error_notice() {
		$this->make_poll();
		set_query_var( 'poller_code', 'K7MQ2P' );
		$_GET['vote'] = 'poller_choice_one';
		$html = $this->render_private( 'render_poll', 'poll' );
		$this->assertStringContainsString( 'Choose one option.', $html );
	}

	// -------------------------------------------------------------------------
	// post_vote
	// -------------------------------------------------------------------------

	public function test_post_vote_records_a_ballot_and_redirects_to_the_poll() {
		$poll = $this->make_poll();
		$choices = $this->repo->get_choices( (int) $poll['id'] );
		$_POST['code'] = 'K7MQ2P';
		$_POST['nonce'] = wp_create_nonce( 'poller_vote_K7MQ2P' );
		$_POST['choices'] = [ (string) $choices[0]['id'] ];

		$location = poller_catch_redirect( [ $this->frontend, 'post_vote' ] );
		$this->assertStringContainsString( 'K7MQ2P', $location );
		$this->assertStringNotContainsString( 'vote=', $location );
		$this->assertSame( 1, $this->repo->ballot_count( (int) $poll['id'] ) );
	}

	public function test_post_vote_bad_nonce_redirects_with_error() {
		$this->make_poll();
		$_POST['code'] = 'K7MQ2P';
		$_POST['nonce'] = 'wrong';
		$_POST['choices'] = [ '1' ];
		$location = poller_catch_redirect( [ $this->frontend, 'post_vote' ] );
		$this->assertStringContainsString( 'vote=poller_nonce', $location );
	}

	public function test_post_vote_choice_error_redirects_with_error() {
		$poll = $this->make_poll();
		$choices = $this->repo->get_choices( (int) $poll['id'] );
		$_POST['code'] = 'K7MQ2P';
		$_POST['nonce'] = wp_create_nonce( 'poller_vote_K7MQ2P' );
		$_POST['choices'] = [ (string) $choices[0]['id'], (string) $choices[1]['id'] ];
		$location = poller_catch_redirect( [ $this->frontend, 'post_vote' ] );
		$this->assertStringContainsString( 'vote=poller_choice_one', $location );
	}

	public function test_post_vote_words_path() {
		$poll = $this->make_poll( [ 'selection' => 'words', 'kind' => 'text', 'budget' => 3 ], [] );
		$_POST['code'] = 'K7MQ2P';
		$_POST['nonce'] = wp_create_nonce( 'poller_vote_K7MQ2P' );
		$_POST['words'] = [ 'Hope', 'Calm' ];
		$location = poller_catch_redirect( [ $this->frontend, 'post_vote' ] );
		$this->assertStringContainsString( 'K7MQ2P', $location );
		$this->assertStringNotContainsString( 'vote=', $location );
		$this->assertSame( 1, $this->repo->ballot_count( (int) $poll['id'] ) );
	}
}
