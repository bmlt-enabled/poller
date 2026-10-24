<?php
/**
 * Tests for the poll-management screens and admin-post handlers.
 */

use BmltEnabled\Poller\Admin;
use BmltEnabled\Poller\Repository;
use BmltEnabled\Poller\Voter;

class Test_Admin extends WP_UnitTestCase {

	private Repository $repo;
	private Admin $admin;

	public function setUp(): void {
		parent::setUp();
		require_once ABSPATH . 'wp-admin/includes/admin.php';
		poller_reset_tables();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->repo = new Repository();
		$this->admin = new Admin( $this->repo );
		$_SERVER['REQUEST_METHOD'] = 'GET';
	}

	public function tearDown(): void {
		$_POST = [];
		$_GET = [];
		$_REQUEST = [];
		unset( $_SERVER['REQUEST_METHOD'] );
		parent::tearDown();
	}

	/**
	 * @param array<string, mixed> $overrides
	 */
	private function make_poll( array $overrides = [], array $choices = [ [ 'label' => 'A', 'image_id' => 0 ], [ 'label' => 'B', 'image_id' => 0 ] ] ): int {
		$id = $this->repo->insert_poll(
			array_merge(
				[
					'code' => $this->repo->next_code(),
					'title' => 'Snacks',
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
		return $id;
	}

	private function render( string $method ): string {
		ob_start();
		try {
			$this->admin->$method();
		} finally {
			$html = (string) ob_get_clean();
		}
		return $html;
	}

	private function nonce( string $action ): void {
		$nonce = wp_create_nonce( $action );
		$_POST['_wpnonce'] = $nonce;
		$_REQUEST['_wpnonce'] = $nonce;
	}

	// -------------------------------------------------------------------------
	// Menu / enqueue
	// -------------------------------------------------------------------------

	public function test_menu_and_enqueue() {
		$this->admin->menu();
		$ref = new ReflectionObject( $this->admin );
		$list = $ref->getProperty( 'list_hook' );
		$list->setAccessible( true );
		$edit = $ref->getProperty( 'edit_hook' );
		$edit->setAccessible( true );
		$this->assertNotEmpty( $list->getValue( $this->admin ) );

		$this->admin->enqueue( 'some-other-hook' );
		$this->assertFalse( wp_style_is( 'poller-admin', 'enqueued' ), 'nothing enqueues on an unrelated screen' );

		$this->admin->enqueue( (string) $list->getValue( $this->admin ) );
		$this->assertTrue( wp_style_is( 'poller-admin', 'enqueued' ) );

		$this->admin->enqueue( (string) $edit->getValue( $this->admin ) );
		$this->assertTrue( wp_script_is( 'poller-admin', 'enqueued' ) );
	}

	// -------------------------------------------------------------------------
	// render_list
	// -------------------------------------------------------------------------

	public function test_render_list_empty() {
		$html = $this->render( 'render_list' );
		$this->assertStringContainsString( 'No polls yet', $html );
	}

	public function test_render_list_shows_polls_and_notice() {
		$this->make_poll( [ 'title' => 'Lunch', 'selection' => 'multi' ] );
		$this->make_poll( [ 'selection' => 'dots', 'budget' => 5, 'per_choice' => 3, 'spend_all' => true ] );
		$this->make_poll( [ 'selection' => 'words', 'kind' => 'text', 'budget' => 3 ], [] );
		$_GET['poller_notice'] = 'saved';

		$html = $this->render( 'render_list' );
		$this->assertStringContainsString( 'Lunch', $html );
		$this->assertStringContainsString( 'Poll saved.', $html );
		$this->assertStringContainsString( 'more than one', $html );
		$this->assertStringContainsString( 'dot voting', $html );
		$this->assertStringContainsString( 'word cloud', $html );
	}

	// -------------------------------------------------------------------------
	// render_edit
	// -------------------------------------------------------------------------

	public function test_render_edit_blank_new_poll() {
		$html = $this->render( 'render_edit' );
		$this->assertStringContainsString( 'Add poll', $html );
		$this->assertStringContainsString( 'name="choice_label[]"', $html );
		$this->assertStringContainsString( 'name="selection"', $html );
	}

	public function test_render_edit_existing_poll_shows_code() {
		$id = $this->make_poll();
		$_GET['poll'] = $id;
		$html = $this->render( 'render_edit' );
		$this->assertStringContainsString( 'Edit poll', $html );
		$this->assertStringContainsString( $this->repo->get_poll( $id )['code'], $html );
	}

	public function test_render_edit_missing_poll_dies() {
		$_GET['poll'] = 999999;
		$this->expectException( WPDieException::class );
		$this->render( 'render_edit' );
	}

	public function test_render_edit_locked_text_poll_shows_locked_choices() {
		$id = $this->make_poll( [ 'selection' => 'multi' ] );
		$choices = $this->repo->get_choices( $id );
		$this->repo->cast( $id, str_repeat( 'a', 64 ), [ (int) $choices[0]['id'] => 1 ] );
		$_GET['poll'] = $id;
		$html = $this->render( 'render_edit' );
		$this->assertStringContainsString( 'poller-admin-locked', $html );
	}

	public function test_render_edit_words_poll_lists_words() {
		$id = $this->make_poll( [ 'selection' => 'words', 'kind' => 'text', 'budget' => 3 ], [] );
		$this->repo->cast_words( $id, str_repeat( 'b', 64 ), [ [ 'text' => 'Hope', 'key' => 'hope' ] ] );
		$this->repo->set_word_hidden( $id, 'hope', true );
		$_GET['poll'] = $id;
		$html = $this->render( 'render_edit' );
		$this->assertStringContainsString( 'poller-admin-words', $html );
		$this->assertStringContainsString( 'Hope', $html );
		$this->assertStringContainsString( 'Show again', $html );
	}

	public function test_render_edit_empty_words_poll() {
		$id = $this->make_poll( [ 'selection' => 'words', 'kind' => 'text', 'budget' => 3 ], [] );
		$_GET['poll'] = $id;
		$html = $this->render( 'render_edit' );
		$this->assertStringContainsString( 'No words yet', $html );
	}

	// -------------------------------------------------------------------------
	// guard
	// -------------------------------------------------------------------------

	public function test_non_admin_is_blocked() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$this->expectException( WPDieException::class );
		$this->render( 'render_list' );
	}

	// -------------------------------------------------------------------------
	// handle_save
	// -------------------------------------------------------------------------

	/**
	 * @param array<string, mixed> $fields
	 */
	private function post_poll( array $fields ): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$this->nonce( 'poller_save_poll' );
		$_POST = array_merge(
			$_POST,
			array_merge(
				[
					'poll_id' => 0,
					'title' => '',
					'question' => 'Which snack?',
					'kind' => 'text',
					'selection' => 'single',
					'status' => 'open',
					'choice_label' => [ 'A', 'B' ],
					'choice_image' => [ 0, 0 ],
				],
				$fields
			)
		);
	}

	public function test_handle_save_creates_a_poll() {
		$this->post_poll( [ 'question' => 'New poll?' ] );
		$location = poller_catch_redirect( [ $this->admin, 'handle_save' ] );
		$this->assertStringContainsString( 'poller_notice=saved', $location );

		$polls = $this->repo->list_polls();
		$this->assertCount( 1, $polls );
		$this->assertSame( 'New poll?', $polls[0]['question'] );
	}

	public function test_handle_save_rejects_a_missing_question() {
		$this->post_poll( [ 'question' => '' ] );
		$location = poller_catch_redirect( [ $this->admin, 'handle_save' ] );
		$this->assertSame( '', $location, 'a validation error does not redirect' );

		// The error is shown back on the edit screen.
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$html = $this->render( 'render_edit' );
		$this->assertStringContainsString( 'Write the question', $html );
	}

	public function test_handle_save_updates_an_existing_poll() {
		$id = $this->make_poll();
		$this->post_poll(
			[
				'poll_id' => $id,
				'question' => 'Edited?',
			]
		);
		$location = poller_catch_redirect( [ $this->admin, 'handle_save' ] );
		$this->assertStringContainsString( 'poller_notice=saved', $location );
		$this->assertSame( 'Edited?', $this->repo->get_poll( $id )['question'] );
	}

	public function test_handle_save_ignores_a_get_request() {
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$location = poller_catch_redirect( [ $this->admin, 'handle_save' ] );
		$this->assertSame( '', $location );
		$this->assertCount( 0, $this->repo->list_polls() );
	}

	// -------------------------------------------------------------------------
	// handle_delete / handle_status / handle_hide_word
	// -------------------------------------------------------------------------

	public function test_handle_delete_removes_the_poll() {
		$id = $this->make_poll();
		$_POST['poll_id'] = $id;
		$this->nonce( 'poller_delete_poll_' . $id );
		$location = poller_catch_redirect( [ $this->admin, 'handle_delete' ] );
		$this->assertStringContainsString( 'poller_notice=deleted', $location );
		$this->assertNull( $this->repo->get_poll( $id ) );
	}

	public function test_handle_status_closes_the_poll() {
		$id = $this->make_poll();
		$_POST['poll_id'] = $id;
		$_POST['status'] = 'closed';
		$this->nonce( 'poller_status_' . $id );
		$location = poller_catch_redirect( [ $this->admin, 'handle_status' ] );
		$this->assertStringContainsString( 'poller_notice=closed', $location );
		$this->assertSame( 'closed', $this->repo->get_poll( $id )['status'] );
	}

	public function test_handle_hide_word_hides_an_entry() {
		$id = $this->make_poll( [ 'selection' => 'words', 'kind' => 'text', 'budget' => 3 ], [] );
		$this->repo->cast_words( $id, str_repeat( 'c', 64 ), [ [ 'text' => 'Hope', 'key' => 'hope' ] ] );
		$_POST['poll_id'] = $id;
		$_POST['word'] = 'Hope';
		$_POST['hide'] = '1';
		$this->nonce( 'poller_hide_word_' . $id );
		$location = poller_catch_redirect( [ $this->admin, 'handle_hide_word' ] );
		$this->assertStringContainsString( 'poller_notice=hidden', $location );
		$this->assertSame( [ 'hope' ], $this->repo->hidden_words( $id ) );
	}
}
