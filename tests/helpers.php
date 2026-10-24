<?php
/**
 * Shared test helpers: redirect interception and plugin-table reset.
 *
 * The admin and frontend handlers finish with wp_safe_redirect() + exit, and
 * the repository uses explicit SQL transactions that commit past
 * WP_UnitTestCase's rollback. These helpers let tests drive those paths.
 */

use BmltEnabled\Poller\Repository;

if ( ! class_exists( 'Poller_Redirect_Exception' ) ) {
	/**
	 * Thrown from a wp_redirect filter so a handler's redirect can be caught
	 * before it reaches exit. The message carries the redirect target.
	 */
	class Poller_Redirect_Exception extends \Exception {}
}

if ( ! function_exists( 'poller_catch_redirect' ) ) {
	/**
	 * Run $fn and return the URL it tries to redirect to (before its exit).
	 * Returns '' if it does not redirect.
	 */
	function poller_catch_redirect( callable $fn ): string {
		$thrower = static function ( $location ) {
			throw new Poller_Redirect_Exception( (string) $location );
		};
		add_filter( 'wp_redirect', $thrower, 1 );
		$location = '';
		try {
			$fn();
		} catch ( Poller_Redirect_Exception $e ) {
			$location = $e->getMessage();
		} finally {
			remove_filter( 'wp_redirect', $thrower, 1 );
		}
		return $location;
	}
}

if ( ! function_exists( 'poller_install_tables' ) ) {
	/**
	 * Create the plugin tables once per test process.
	 */
	function poller_install_tables(): void {
		static $done = false;
		if ( $done ) {
			return;
		}
		( new Repository() )->install();
		$done = true;
	}
}

if ( ! function_exists( 'poller_reset_tables' ) ) {
	/**
	 * Empty every plugin table so a test starts from a clean slate. The
	 * repository's own COMMITs survive WP_UnitTestCase's rollback, so tests
	 * that cast votes must reset here rather than rely on it.
	 */
	function poller_reset_tables(): void {
		global $wpdb;
		poller_install_tables();
		foreach ( [ 'words', 'hidden_words', 'votes', 'ballots', 'choices', 'polls' ] as $name ) {
			$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}poller_{$name}" ); // phpcs:ignore WordPress.DB
		}
	}
}
