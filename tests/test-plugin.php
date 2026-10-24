<?php
/**
 * Tests for plugin bootstrap: URL helpers, query vars, rewrites, activation.
 */

use BmltEnabled\Poller\Plugin;
use BmltEnabled\Poller\Repository;

class Test_Plugin extends WP_UnitTestCase {

	public function tearDown(): void {
		update_option( 'permalink_structure', '' );
		parent::tearDown();
	}

	public function test_permalinks_reflects_the_option() {
		update_option( 'permalink_structure', '' );
		$this->assertFalse( Plugin::permalinks() );
		update_option( 'permalink_structure', '/%postname%/' );
		$this->assertTrue( Plugin::permalinks() );
	}

	public function test_urls_use_pretty_permalinks_when_enabled() {
		update_option( 'permalink_structure', '/%postname%/' );
		$this->assertStringContainsString( '/poller/', Plugin::join_url() );
		$this->assertStringContainsString( '/poller/K7MQ2P/', Plugin::poll_url( 'k7mq2p' ) );
		$this->assertStringContainsString( '/poller/K7MQ2P/board/', Plugin::board_url( 'k7mq2p' ) );
	}

	public function test_urls_fall_back_to_query_args_without_permalinks() {
		update_option( 'permalink_structure', '' );
		$this->assertStringContainsString( 'poller_view=join', Plugin::join_url() );

		$poll = Plugin::poll_url( 'k7mq2p' );
		$this->assertStringContainsString( 'poller_view=poll', $poll );
		$this->assertStringContainsString( 'poller_code=K7MQ2P', $poll );

		$board = Plugin::board_url( 'k7mq2p' );
		$this->assertStringContainsString( 'poller_view=board', $board );
		$this->assertStringContainsString( 'poller_code=K7MQ2P', $board );
	}

	public function test_query_vars_are_registered() {
		$plugin = new Plugin();
		$vars = $plugin->query_vars( [ 'existing' ] );
		$this->assertContains( 'poller_view', $vars );
		$this->assertContains( 'poller_code', $vars );
		$this->assertContains( 'existing', $vars );
	}

	public function test_action_links_prepends_a_polls_link() {
		$plugin = new Plugin();
		$links = $plugin->action_links( [ '<a href="#">Deactivate</a>' ] );
		$this->assertCount( 2, $links );
		$this->assertStringContainsString( 'page=poller', $links[0] );
	}

	public function test_register_rewrites_runs() {
		$plugin = new Plugin();
		$plugin->register_rewrites();
		global $wp_rewrite;
		$found = false;
		foreach ( (array) $wp_rewrite->extra_rules_top as $pattern => $query ) {
			if ( str_contains( $pattern, 'poller' ) ) {
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found, 'a poller rewrite rule is added' );
	}

	public function test_activate_installs_tables_and_sets_the_version() {
		delete_option( Repository::VERSION_OPTION );
		( new Plugin() )->activate();
		$this->assertSame( Repository::DB_VERSION, get_option( Repository::VERSION_OPTION ) );
	}

	public function test_boot_and_init_run_without_error() {
		$plugin = new Plugin();
		$plugin->boot();
		$this->assertNotFalse( has_action( 'rest_api_init' ), 'boot wires the REST route' );
		$plugin->init();
		$this->assertSame( Repository::DB_VERSION, get_option( Repository::VERSION_OPTION ) );
	}
}
