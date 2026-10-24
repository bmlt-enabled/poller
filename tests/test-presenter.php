<?php
/**
 * Tests for the public poll payload. Counts only, never a ballot token.
 */

use BmltEnabled\Poller\Presenter;
use BmltEnabled\Poller\Repository;
use BmltEnabled\Poller\Voter;

class Test_Presenter extends WP_UnitTestCase {

	private Repository $repo;
	private Presenter $presenter;

	public function setUp(): void {
		parent::setUp();
		poller_reset_tables();
		$this->repo = new Repository();
		$this->presenter = new Presenter( $this->repo );
	}

	/**
	 * @param array<int, array{label: string, image_id: int}> $choices
	 * @param array<string, mixed> $overrides
	 */
	private function poll_with_choices( array $choices, array $overrides = [] ): array {
		$id = $this->repo->insert_poll(
			array_merge(
				[
					'code' => $this->repo->next_code(),
					'title' => 'T',
					'question' => 'Q?',
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
		if ( $choices ) {
			$this->repo->replace_choices( $id, $choices );
		}
		return $this->repo->get_poll( $id );
	}

	public function test_text_payload_has_counts_mine_and_urls() {
		$poll = $this->poll_with_choices( [ [ 'label' => 'A', 'image_id' => 0 ], [ 'label' => 'B', 'image_id' => 0 ] ] );
		$choices = $this->repo->get_choices( (int) $poll['id'] );
		$hash = Voter::hash( str_repeat( 'a', 64 ) );
		$this->repo->cast( (int) $poll['id'], $hash, [ (int) $choices[0]['id'] => 1 ] );

		$payload = $this->presenter->payload( $this->repo->get_poll( (int) $poll['id'] ), $hash );

		$this->assertSame( 'single', $payload['selection'] );
		$this->assertSame( 1, $payload['ballots'] );
		$this->assertSame( 0, $payload['dots'] );
		$this->assertSame( [ 'A', 'B' ], array_column( $payload['choices'], 'label' ) );
		$this->assertSame( 1, $payload['choices'][0]['votes'] );
		$this->assertSame( [ (int) $choices[0]['id'] ], $payload['mine'], 'a text ballot reports chosen ids' );
		$this->assertArrayHasKey( 'poll', $payload['urls'] );
		$this->assertArrayHasKey( 'board', $payload['urls'] );
		$this->assertArrayHasKey( 'join', $payload['urls'] );
		$this->assertNull( $payload['image'] );
	}

	public function test_no_voter_hash_means_no_mine() {
		$poll = $this->poll_with_choices( [ [ 'label' => 'A', 'image_id' => 0 ], [ 'label' => 'B', 'image_id' => 0 ] ] );
		$payload = $this->presenter->payload( $poll, null );
		$this->assertSame( [], $payload['mine'] );
	}

	public function test_dots_payload_reports_rules_and_dot_totals() {
		$poll = $this->poll_with_choices(
			[ [ 'label' => 'A', 'image_id' => 0 ], [ 'label' => 'B', 'image_id' => 0 ] ],
			[ 'selection' => 'dots', 'budget' => 5, 'per_choice' => 3, 'spend_all' => true ]
		);
		$choices = $this->repo->get_choices( (int) $poll['id'] );
		$a = (int) $choices[0]['id'];
		$b = (int) $choices[1]['id'];
		$hash = Voter::hash( str_repeat( 'b', 64 ) );
		$this->repo->cast( (int) $poll['id'], $hash, [ $a => 3, $b => 2 ] );

		$payload = $this->presenter->payload( $this->repo->get_poll( (int) $poll['id'] ), $hash );
		$this->assertSame( 5, $payload['budget'] );
		$this->assertSame( 3, $payload['per_choice'] );
		$this->assertTrue( $payload['spend_all'] );
		$this->assertSame( 5, $payload['dots'], 'dots is the sum of all weights' );
		$this->assertSame( [ $a => 3, $b => 2 ], $payload['mine'], 'a dot ballot reports id => dots' );
	}

	public function test_words_payload_carries_cloud_and_voter_entries() {
		$poll = $this->poll_with_choices( [], [ 'selection' => 'words', 'kind' => 'text', 'budget' => 3 ] );
		$hash = Voter::hash( str_repeat( 'c', 64 ) );
		$this->repo->cast_words( (int) $poll['id'], $hash, [ [ 'text' => 'Hope', 'key' => 'hope' ], [ 'text' => 'Calm', 'key' => 'calm' ] ] );

		$payload = $this->presenter->payload( $this->repo->get_poll( (int) $poll['id'] ), $hash );
		$this->assertSame( 3, $payload['budget'] );
		// The cloud is sorted by ballots then alphabetically (calm before hope).
		$this->assertSame( [ 'calm', 'hope' ], array_column( $payload['words'], 'key' ) );
		$this->assertSame( [ 'Hope', 'Calm' ], $payload['mine'], 'a word ballot reports this voter entries in order' );
		$this->assertSame( [ 'hope', 'calm' ], $payload['mine_keys'] );
	}

	public function test_image_poll_resolves_attachment_urls_and_labels() {
		$prompt = $this->image_attachment( 'Question art' );
		$pic = $this->image_attachment( '' );

		$poll = $this->poll_with_choices(
			[ [ 'label' => '', 'image_id' => $pic ] ],
			[ 'kind' => 'image', 'image_id' => $prompt ]
		);
		$payload = $this->presenter->payload( $poll, null );

		$this->assertIsArray( $payload['image'] );
		$this->assertArrayHasKey( 'url', $payload['image'] );
		$this->assertSame( 'Question art', $payload['image']['alt'] );
		$this->assertIsArray( $payload['choices'][0]['image'] );
		$this->assertSame( 'Picture 1', $payload['choices'][0]['label'], 'an unlabeled picture choice gets a numbered label' );
	}

	public function test_image_ignores_a_non_image_attachment() {
		$pdf = self::factory()->post->create( [ 'post_type' => 'attachment', 'post_mime_type' => 'application/pdf' ] );
		$poll = $this->poll_with_choices( [ [ 'label' => 'A', 'image_id' => 0 ] ], [ 'kind' => 'image', 'image_id' => $pdf ] );
		$payload = $this->presenter->payload( $poll, null );
		$this->assertNull( $payload['image'], 'a non-image attachment yields no image' );
	}

	public function test_word_keys_normalizes_entries() {
		$this->assertSame( [ 'hope', 'calm' ], Presenter::word_keys( [ 'Hope!', ' Calm ' ] ) );
		$this->assertSame( [ 'hope' ], Presenter::word_keys( [ 'Hope', '...' ] ), 'empty entries drop out' );
	}

	private function image_attachment( string $alt ): int {
		$id = wp_insert_attachment(
			[
				'post_mime_type' => 'image/png',
				'post_title' => 'poller-test',
				'post_status' => 'inherit',
			],
			'poller-test.png'
		);
		if ( '' !== $alt ) {
			update_post_meta( $id, '_wp_attachment_image_alt', $alt );
		}
		return (int) $id;
	}
}
