<?php
/**
 * Poll, choice, and ballot storage.
 *
 * Ballots store a salted hash only. There is no user id and no address
 * column. Counts are the only thing a poll page can show.
 *
 * @package Poller
 */

namespace BmltEnabled\Poller;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// These queries use this plugin's own tables. Ballot totals change on every vote, so they are not cached.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

class Repository {

	public const DB_VERSION = '3';
	public const VERSION_OPTION = 'poller_db_version';

	public function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$polls = $this->table( 'polls' );
		$choices = $this->table( 'choices' );
		$ballots = $this->table( 'ballots' );
		$votes = $this->table( 'votes' );
		$words = $this->table( 'words' );
		$hidden = $this->table( 'hidden_words' );

		dbDelta(
			"CREATE TABLE {$polls} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            code varchar(8) NOT NULL,
            title varchar(160) NOT NULL DEFAULT '',
            question text NOT NULL,
            image_id bigint(20) unsigned NOT NULL DEFAULT 0,
            kind varchar(16) NOT NULL DEFAULT 'text',
            selection varchar(16) NOT NULL DEFAULT 'single',
            budget smallint unsigned NOT NULL DEFAULT 0,
            per_choice smallint unsigned NOT NULL DEFAULT 0,
            spend_all tinyint(1) unsigned NOT NULL DEFAULT 0,
            status varchar(16) NOT NULL DEFAULT 'open',
            revision bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY code (code)
        ) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$choices} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            poll_id bigint(20) unsigned NOT NULL,
            label varchar(160) NOT NULL DEFAULT '',
            image_id bigint(20) unsigned NOT NULL DEFAULT 0,
            position smallint unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            KEY poll_id (poll_id)
        ) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$ballots} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            poll_id bigint(20) unsigned NOT NULL,
            voter_token char(64) NOT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY poll_voter (poll_id, voter_token)
        ) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$votes} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            ballot_id bigint(20) unsigned NOT NULL,
            choice_id bigint(20) unsigned NOT NULL,
            weight smallint unsigned NOT NULL DEFAULT 1,
            PRIMARY KEY  (id),
            UNIQUE KEY ballot_choice (ballot_id, choice_id),
            KEY choice_id (choice_id)
        ) {$charset};"
		);

		// Word cloud entries. Voter text lives here, apart from the choices the admin owns.
		// Words are matched in PHP, not by the column collation, which may ignore case and accents.
		dbDelta(
			"CREATE TABLE {$words} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            ballot_id bigint(20) unsigned NOT NULL,
            word varchar(64) NOT NULL DEFAULT '',
            normalized varchar(64) NOT NULL DEFAULT '',
            PRIMARY KEY  (id),
            KEY ballot_id (ballot_id)
        ) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$hidden} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            poll_id bigint(20) unsigned NOT NULL,
            normalized varchar(64) NOT NULL DEFAULT '',
            PRIMARY KEY  (id),
            KEY poll_id (poll_id)
        ) {$charset};"
		);

		update_option( self::VERSION_OPTION, self::DB_VERSION );
	}

	public function drop_tables(): void {
		global $wpdb;

		foreach ( [ 'words', 'hidden_words', 'votes', 'ballots', 'choices', 'polls' ] as $name ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Uninstall removes this plugin's tables.
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $this->table( $name ) ) );
		}
		delete_option( self::VERSION_OPTION );
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function insert_poll( array $data ): int {
		global $wpdb;

		$now = $this->now();
		$wpdb->insert(
			$this->table( 'polls' ),
			[
				'code' => $data['code'],
				'title' => $data['title'],
				'question' => $data['question'],
				'image_id' => $data['image_id'],
				'kind' => $data['kind'],
				'selection' => $data['selection'],
				'budget' => $data['budget'],
				'per_choice' => $data['per_choice'],
				'spend_all' => $data['spend_all'] ? 1 : 0,
				'status' => $data['status'],
				'revision' => 0,
				'created_at' => $now,
				'updated_at' => $now,
			],
			[ '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%d', '%d', '%s', '%d', '%s', '%s' ]
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function update_poll( int $id, array $data ): void {
		global $wpdb;

		$wpdb->update(
			$this->table( 'polls' ),
			[
				'title' => $data['title'],
				'question' => $data['question'],
				'image_id' => $data['image_id'],
				'kind' => $data['kind'],
				'selection' => $data['selection'],
				'budget' => $data['budget'],
				'per_choice' => $data['per_choice'],
				'spend_all' => $data['spend_all'] ? 1 : 0,
				'status' => $data['status'],
				'updated_at' => $this->now(),
			],
			[ 'id' => $id ],
			[ '%s', '%s', '%d', '%s', '%s', '%d', '%d', '%d', '%s', '%s' ],
			[ '%d' ]
		);
		$this->touch( $id );
	}

	public function set_status( int $id, string $status ): void {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET status = %s, revision = revision + 1, updated_at = %s WHERE id = %d',
				$this->table( 'polls' ),
				$status,
				$this->now(),
				$id
			)
		);
	}

	public function delete_poll( int $id ): void {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				'DELETE v FROM %i v INNER JOIN %i b ON b.id = v.ballot_id WHERE b.poll_id = %d',
				$this->table( 'votes' ),
				$this->table( 'ballots' ),
				$id
			)
		);
		$wpdb->query(
			$wpdb->prepare(
				'DELETE w FROM %i w INNER JOIN %i b ON b.id = w.ballot_id WHERE b.poll_id = %d',
				$this->table( 'words' ),
				$this->table( 'ballots' ),
				$id
			)
		);
		$wpdb->delete( $this->table( 'hidden_words' ), [ 'poll_id' => $id ], [ '%d' ] );
		$wpdb->delete( $this->table( 'ballots' ), [ 'poll_id' => $id ], [ '%d' ] );
		$wpdb->delete( $this->table( 'choices' ), [ 'poll_id' => $id ], [ '%d' ] );
		$wpdb->delete( $this->table( 'polls' ), [ 'id' => $id ], [ '%d' ] );
	}

	public function get_poll( int $id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE id = %d',
				$this->table( 'polls' ),
				$id
			),
			ARRAY_A
		);

		return is_array( $row ) ? self::hydrate_poll( $row ) : null;
	}

	public function get_poll_by_code( string $code ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE code = %s',
				$this->table( 'polls' ),
				$code
			),
			ARRAY_A
		);

		return is_array( $row ) ? self::hydrate_poll( $row ) : null;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function list_polls(): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT p.*, (SELECT COUNT(*) FROM %i b WHERE b.poll_id = p.id) AS ballots
            FROM %i p
            ORDER BY p.created_at DESC',
				$this->table( 'ballots' ),
				$this->table( 'polls' )
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return [];
		}

		return array_map( [ self::class, 'hydrate_poll' ], $rows );
	}

	public function next_code(): string {
		for ( $i = 0; $i < 12; $i++ ) {
			$code = Codes::generate();
			if ( $this->get_poll_by_code( $code ) === null ) {
				return $code;
			}
		}

		throw new \RuntimeException( 'code' );
	}

	/**
	 * @param array<int, array{label: string, image_id: int}> $choices
	 */
	public function replace_choices( int $poll_id, array $choices ): bool {
		if ( $this->ballot_count( $poll_id ) > 0 ) {
			return false;
		}

		$this->write_choices( $poll_id, $choices );
		$this->touch( $poll_id );
		return true;
	}

	/**
	 * Update a poll, and its choices when nobody has voted yet. The poll row
	 * stays locked for the whole save, the same lock cast() takes, so a vote
	 * sees either the old choices and rules or the new ones, never a mix.
	 *
	 * Returns false when there are ballots. Then the choices and voting rules
	 * already stored are kept, and only the other fields are saved.
	 *
	 * @param array<string, mixed> $fields
	 * @param array<int, array{label: string, image_id: int}>|null $choices null leaves the choices alone.
	 */
	public function save_poll( int $id, array $fields, ?array $choices ): bool {
		global $wpdb;

		$wpdb->query( 'START TRANSACTION' );
		$rules = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT kind, selection, budget, per_choice, spend_all FROM %i WHERE id = %d FOR UPDATE',
				$this->table( 'polls' ),
				$id
			),
			ARRAY_A
		);
		if ( ! is_array( $rules ) ) {
			$wpdb->query( 'ROLLBACK' );
			return false;
		}

		$open = $this->ballot_count( $id ) === 0;
		if ( $open && null !== $choices ) {
			$this->write_choices( $id, $choices );
		} elseif ( ! $open ) {
			$fields = [
				'kind' => (string) $rules['kind'],
				'selection' => (string) $rules['selection'],
				'budget' => (int) $rules['budget'],
				'per_choice' => (int) $rules['per_choice'],
				'spend_all' => ! empty( $rules['spend_all'] ),
			] + $fields;
		}
		$this->update_poll( $id, $fields );
		$wpdb->query( 'COMMIT' );
		return $open;
	}

	/**
	 * @param array<int, array{label: string, image_id: int}> $choices
	 */
	private function write_choices( int $poll_id, array $choices ): void {
		global $wpdb;

		$wpdb->delete( $this->table( 'choices' ), [ 'poll_id' => $poll_id ], [ '%d' ] );
		$position = 0;
		foreach ( $choices as $choice ) {
			$wpdb->insert(
				$this->table( 'choices' ),
				[
					'poll_id' => $poll_id,
					'label' => $choice['label'],
					'image_id' => $choice['image_id'],
					'position' => $position,
				],
				[ '%d', '%s', '%d', '%d' ]
			);
			$position++;
		}
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function get_choices( int $poll_id ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE poll_id = %d ORDER BY position ASC, id ASC',
				$this->table( 'choices' ),
				$poll_id
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return [];
		}

		return array_map(
			static function ( $row ) {
				return [
					'id' => (int) $row['id'],
					'poll_id' => (int) $row['poll_id'],
					'label' => (string) $row['label'],
					'image_id' => (int) $row['image_id'],
					'position' => (int) $row['position'],
				];
			},
			$rows
		);
	}

	public function ballot_count( int $poll_id ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE poll_id = %d',
				$this->table( 'ballots' ),
				$poll_id
			)
		);
	}

	/**
	 * @return array<int, int> choice id => votes, or dots on a dot voting poll
	 */
	public function choice_counts( int $poll_id ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT v.choice_id, SUM(v.weight) AS votes
            FROM %i v
            INNER JOIN %i b ON b.id = v.ballot_id
            WHERE b.poll_id = %d
            GROUP BY v.choice_id',
				$this->table( 'votes' ),
				$this->table( 'ballots' ),
				$poll_id
			),
			ARRAY_A
		);

		$counts = [];
		if ( ! is_array( $rows ) ) {
			return $counts;
		}
		foreach ( $rows as $row ) {
			$counts[ (int) $row['choice_id'] ] = (int) $row['votes'];
		}
		return $counts;
	}

	public function get_ballot( int $poll_id, string $voter_token ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE poll_id = %d AND voter_token = %s',
				$this->table( 'ballots' ),
				$poll_id,
				$voter_token
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return null;
		}

		return [
			'id' => (int) $row['id'],
			'poll_id' => (int) $row['poll_id'],
			'voter_token' => (string) $row['voter_token'],
		];
	}

	/**
	 * @return array<int, int> choice id => weight
	 */
	public function ballot_weights( int $ballot_id ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT choice_id, weight FROM %i WHERE ballot_id = %d',
				$this->table( 'votes' ),
				$ballot_id
			),
			ARRAY_A
		);

		$weights = [];
		if ( ! is_array( $rows ) ) {
			return $weights;
		}
		foreach ( $rows as $row ) {
			$weights[ (int) $row['choice_id'] ] = (int) $row['weight'];
		}
		return $weights;
	}

	/**
	 * This ballot's word cloud entries, as they were shown back.
	 *
	 * @return array<int, string>
	 */
	public function ballot_words( int $ballot_id ): array {
		global $wpdb;

		$rows = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT word FROM %i WHERE ballot_id = %d ORDER BY id ASC',
				$this->table( 'words' ),
				$ballot_id
			)
		);
		return is_array( $rows ) ? array_map( 'strval', $rows ) : [];
	}

	/**
	 * Word cloud entries for Tally::words(), one row per entry. Never which
	 * ballot. Not grouped in SQL: the column collation could merge "Hope" with
	 * "hope", and Tally needs each spelling to pick the one to show.
	 *
	 * @return array<int, array{key: string, text: string, count: int}>
	 */
	public function word_rows( int $poll_id ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT w.normalized, w.word
            FROM %i w
            INNER JOIN %i b ON b.id = w.ballot_id
            WHERE b.poll_id = %d',
				$this->table( 'words' ),
				$this->table( 'ballots' ),
				$poll_id
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return [];
		}
		return array_map(
			static function ( $row ) {
				return [
					'key' => (string) $row['normalized'],
					'text' => (string) $row['word'],
					'count' => 1,
				];
			},
			$rows
		);
	}

	/**
	 * @return array<int, string> keys an admin hid from this poll's cloud
	 */
	public function hidden_words( int $poll_id ): array {
		global $wpdb;

		$rows = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT normalized FROM %i WHERE poll_id = %d ORDER BY normalized ASC',
				$this->table( 'hidden_words' ),
				$poll_id
			)
		);
		return is_array( $rows ) ? array_map( 'strval', $rows ) : [];
	}

	/**
	 * Hide a word from the cloud, or show it again. Bumps the revision so
	 * open pages pick it up on their next refresh.
	 */
	public function set_word_hidden( int $poll_id, string $key, bool $hidden ): void {
		global $wpdb;

		$table = $this->table( 'hidden_words' );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, normalized FROM %i WHERE poll_id = %d',
				$table,
				$poll_id
			),
			ARRAY_A
		);
		$found = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			if ( (string) $row['normalized'] === $key ) {
				$found[] = (int) $row['id'];
			}
		}

		if ( $hidden && ! $found ) {
			$wpdb->insert(
				$table,
				[
					'poll_id' => $poll_id,
					'normalized' => $key,
				],
				[ '%d', '%s' ]
			);
		} elseif ( ! $hidden ) {
			foreach ( $found as $id ) {
				$wpdb->delete( $table, [ 'id' => $id ], [ '%d' ] );
			}
		}
		$this->touch( $poll_id );
	}

	/**
	 * Replace this browser's word cloud entries. Throws RuntimeException('closed')
	 * or RuntimeException('invalid') after rolling back.
	 *
	 * The entries are checked again against the locked poll row, like cast().
	 *
	 * @param array<int, array{text: string, key: string}> $words
	 */
	public function cast_words( int $poll_id, string $voter_token, array $words ): void {
		global $wpdb;

		$polls = $this->table( 'polls' );
		$table = $this->table( 'words' );
		$now = $this->now();

		$wpdb->query( 'START TRANSACTION' );

		$poll = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT id, status, selection, budget FROM %i WHERE id = %d FOR UPDATE',
				$polls,
				$poll_id
			)
		);
		if ( ! $poll || 'open' !== $poll->status ) {
			$wpdb->query( 'ROLLBACK' );
			throw new \RuntimeException( 'closed' );
		}

		$checked = BallotRules::words( array_column( $words, 'text' ), (int) $poll->budget );
		if ( 'words' !== $poll->selection || null !== $checked['error'] || $checked['words'] !== $words ) {
			$wpdb->query( 'ROLLBACK' );
			throw new \RuntimeException( 'invalid' );
		}

		$ballot_id = $this->upsert_ballot( $poll_id, $voter_token, $now );
		$wpdb->delete( $table, [ 'ballot_id' => $ballot_id ], [ '%d' ] );
		foreach ( $words as $word ) {
			$ok = $wpdb->insert(
				$table,
				[
					'ballot_id' => $ballot_id,
					'word' => $word['text'],
					'normalized' => $word['key'],
				],
				[ '%d', '%s', '%s' ]
			);
			if ( ! $ok ) {
				$wpdb->query( 'ROLLBACK' );
				throw new \RuntimeException( 'invalid' );
			}
		}

		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET revision = revision + 1, updated_at = %s WHERE id = %d',
				$polls,
				$now,
				$poll_id
			)
		);
		$wpdb->query( 'COMMIT' );
	}

	/**
	 * Find or add this browser's ballot inside a cast transaction. Rolls back
	 * and throws RuntimeException('invalid') when there is no row to use.
	 */
	private function upsert_ballot( int $poll_id, string $voter_token, string $now ): int {
		global $wpdb;

		$ballots = $this->table( 'ballots' );
		$ballot_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE poll_id = %d AND voter_token = %s LIMIT 1 FOR UPDATE',
				$ballots,
				$poll_id,
				$voter_token
			)
		);

		if ( 0 === $ballot_id ) {
			$inserted = $wpdb->insert(
				$ballots,
				[
					'poll_id' => $poll_id,
					'voter_token' => $voter_token,
					'created_at' => $now,
					'updated_at' => $now,
				],
				[ '%d', '%s', '%s', '%s' ]
			);
			if ( ! $inserted ) {
				$ballot_id = (int) $wpdb->get_var(
					$wpdb->prepare(
						'SELECT id FROM %i WHERE poll_id = %d AND voter_token = %s LIMIT 1',
						$ballots,
						$poll_id,
						$voter_token
					)
				);
			} else {
				$ballot_id = (int) $wpdb->insert_id;
			}
			if ( 0 === $ballot_id ) {
				$wpdb->query( 'ROLLBACK' );
				throw new \RuntimeException( 'invalid' );
			}
		} else {
			$wpdb->update(
				$ballots,
				[ 'updated_at' => $now ],
				[ 'id' => $ballot_id ],
				[ '%s' ],
				[ '%d' ]
			);
		}
		return $ballot_id;
	}

	/**
	 * Replace this browser's ballot. Throws RuntimeException('closed')
	 * or RuntimeException('invalid') after rolling back.
	 *
	 * The weights are checked again against the locked poll row, so a rule
	 * change saved between the first check and this write cannot slip through.
	 *
	 * @param array<int, int> $weights choice id => weight
	 */
	public function cast( int $poll_id, string $voter_token, array $weights ): void {
		global $wpdb;

		$polls = $this->table( 'polls' );
		$choices = $this->table( 'choices' );
		$votes = $this->table( 'votes' );
		$now = $this->now();

		$wpdb->query( 'START TRANSACTION' );

		$poll = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT id, status, selection, budget, per_choice, spend_all FROM %i WHERE id = %d FOR UPDATE',
				$polls,
				$poll_id
			)
		);
		if ( ! $poll || 'open' !== $poll->status ) {
			$wpdb->query( 'ROLLBACK' );
			throw new \RuntimeException( 'closed' );
		}

		$choice_ids = array_values( array_map( 'intval', array_keys( $weights ) ) );
		$submitted = 'dots' === $poll->selection ? $weights : $choice_ids;
		$checked = BallotRules::weigh( (array) $poll, $submitted, $choice_ids );
		if ( count( $choice_ids ) === 0 || null !== $checked['error'] || $checked['weights'] != $weights ) {
			$wpdb->query( 'ROLLBACK' );
			throw new \RuntimeException( 'invalid' );
		}

		$placeholders = implode( ',', array_fill( 0, count( $choice_ids ), '%d' ) );
		// The IN list is only repeated %d placeholders, one per choice id.
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$found = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM %i WHERE poll_id = %d AND id IN ({$placeholders})",
				...array_merge( [ $choices, $poll_id ], $choice_ids )
			)
		);
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$found = is_array( $found ) ? array_map( 'intval', $found ) : [];
		sort( $found );
		$expected = array_map( 'intval', $choice_ids );
		sort( $expected );
		if ( $found !== $expected ) {
			$wpdb->query( 'ROLLBACK' );
			throw new \RuntimeException( 'invalid' );
		}

		$ballot_id = $this->upsert_ballot( $poll_id, $voter_token, $now );

		$wpdb->delete( $votes, [ 'ballot_id' => $ballot_id ], [ '%d' ] );
		foreach ( $expected as $choice_id ) {
			$ok = $wpdb->insert(
				$votes,
				[
					'ballot_id' => $ballot_id,
					'choice_id' => $choice_id,
					'weight' => (int) $weights[ $choice_id ],
				],
				[ '%d', '%d', '%d' ]
			);
			if ( ! $ok ) {
				$wpdb->query( 'ROLLBACK' );
				throw new \RuntimeException( 'invalid' );
			}
		}

		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET revision = revision + 1, updated_at = %s WHERE id = %d',
				$polls,
				$now,
				$poll_id
			)
		);
		$wpdb->query( 'COMMIT' );
	}

	private function touch( int $id ): void {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET revision = revision + 1, updated_at = %s WHERE id = %d',
				$this->table( 'polls' ),
				$this->now(),
				$id
			)
		);
	}

	private function table( string $name ): string {
		global $wpdb;

		return $wpdb->prefix . 'poller_' . $name;
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	public static function hydrate_poll( array $row ): array {
		return [
			'id' => (int) $row['id'],
			'code' => (string) $row['code'],
			'title' => (string) $row['title'],
			'question' => (string) $row['question'],
			'image_id' => (int) $row['image_id'],
			'kind' => (string) $row['kind'],
			'selection' => (string) $row['selection'],
			'budget' => (int) ( $row['budget'] ?? 0 ),
			'per_choice' => (int) ( $row['per_choice'] ?? 0 ),
			'spend_all' => ! empty( $row['spend_all'] ),
			'status' => (string) $row['status'],
			'revision' => (int) $row['revision'],
			'created_at' => (string) $row['created_at'],
			'updated_at' => (string) $row['updated_at'],
			'ballots' => isset( $row['ballots'] ) ? (int) $row['ballots'] : null,
		];
	}

	private function now(): string {
		return current_time( 'mysql', true );
	}
}
