<?php
/**
 * Smoke tests for the public page templates, rendered through the standalone
 * preview harness (bin/preview.php). Mirrors its CLI self-check as assertions.
 */

require_once dirname( __DIR__ ) . '/bin/preview.php';

class Test_Preview extends WP_UnitTestCase {

	/**
	 * @return array<int, array{0: string}>
	 */
	public function view_provider(): array {
		return array_map(
			static function ( $view ) {
				return [ $view ];
			},
			[ 'join', 'poll', 'pictures', 'multi', 'closed', 'board', 'dots', 'dots-toggle', 'dots-board', 'words', 'words-board', 'missing', 'error' ]
		);
	}

	/**
	 * @dataProvider view_provider
	 */
	public function test_view_renders( string $view ) {
		$html = poller_preview_render( $view );
		$this->assertNotEmpty( $html, "{$view} renders some HTML" );

		if ( str_starts_with( $view, 'words' ) ) {
			$this->assertStringContainsString( 'data-poller-cloud', $html, "{$view} has a word cloud" );
			$this->assertStringContainsString( 'data-word="hopeful"', $html, "{$view} shows the most common word" );
		}

		if ( $view === 'words' ) {
			$this->assertSame( 3, substr_count( $html, 'name="words[]"' ), 'words has the word boxes' );
		}

		if ( $view === 'words-board' ) {
			$this->assertStringNotContainsString( 'poller_hide_word', $html, 'the room display shows no hide buttons' );
		}

		if ( str_starts_with( $view, 'dots' ) ) {
			$this->assertStringContainsString( 'poller-results--ranked', $html, "{$view} has the ranked tally" );
		}

		if ( $view === 'dots' || $view === 'dots-toggle' ) {
			$this->assertStringContainsString( 'name="choices[1]"', $html, "{$view} has the dot controls" );
		}

		if ( str_ends_with( $view, 'board' ) ) {
			$this->assertStringContainsString( 'K7MQ2P', $html, 'the board shows the code' );
		} else {
			$this->assertStringContainsString( 'Poller', $html, "{$view} renders the page chrome" );
		}
	}
}
