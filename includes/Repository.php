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

if (!defined('ABSPATH')) {
    exit;
}

class Repository
{
    public const DB_VERSION = '1';
    public const VERSION_OPTION = 'poller_db_version';

    public function install(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $polls = $this->table('polls');
        $choices = $this->table('choices');
        $ballots = $this->table('ballots');
        $votes = $this->table('votes');

        dbDelta("CREATE TABLE {$polls} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            code varchar(8) NOT NULL,
            title varchar(160) NOT NULL DEFAULT '',
            question text NOT NULL,
            image_id bigint(20) unsigned NOT NULL DEFAULT 0,
            kind varchar(16) NOT NULL DEFAULT 'text',
            selection varchar(16) NOT NULL DEFAULT 'single',
            status varchar(16) NOT NULL DEFAULT 'open',
            revision bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY code (code)
        ) {$charset};");

        dbDelta("CREATE TABLE {$choices} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            poll_id bigint(20) unsigned NOT NULL,
            label varchar(160) NOT NULL DEFAULT '',
            image_id bigint(20) unsigned NOT NULL DEFAULT 0,
            position smallint unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            KEY poll_id (poll_id)
        ) {$charset};");

        dbDelta("CREATE TABLE {$ballots} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            poll_id bigint(20) unsigned NOT NULL,
            voter_token char(64) NOT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY poll_voter (poll_id, voter_token)
        ) {$charset};");

        dbDelta("CREATE TABLE {$votes} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            ballot_id bigint(20) unsigned NOT NULL,
            choice_id bigint(20) unsigned NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY ballot_choice (ballot_id, choice_id),
            KEY choice_id (choice_id)
        ) {$charset};");

        update_option(self::VERSION_OPTION, self::DB_VERSION);
    }

    public function drop_tables(): void
    {
        global $wpdb;

        foreach (['votes', 'ballots', 'choices', 'polls'] as $name) {
            $table = $this->table($name);
            $wpdb->query("DROP TABLE IF EXISTS {$table}");
        }
        delete_option(self::VERSION_OPTION);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function insert_poll(array $data): int
    {
        global $wpdb;

        $now = $this->now();
        $wpdb->insert(
            $this->table('polls'),
            [
                'code' => $data['code'],
                'title' => $data['title'],
                'question' => $data['question'],
                'image_id' => $data['image_id'],
                'kind' => $data['kind'],
                'selection' => $data['selection'],
                'status' => $data['status'],
                'revision' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s']
        );

        return (int) $wpdb->insert_id;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update_poll(int $id, array $data): void
    {
        global $wpdb;

        $wpdb->update(
            $this->table('polls'),
            [
                'title' => $data['title'],
                'question' => $data['question'],
                'image_id' => $data['image_id'],
                'kind' => $data['kind'],
                'selection' => $data['selection'],
                'status' => $data['status'],
                'updated_at' => $this->now(),
            ],
            ['id' => $id],
            ['%s', '%s', '%d', '%s', '%s', '%s', '%s'],
            ['%d']
        );
        $this->touch($id);
    }

    public function set_status(int $id, string $status): void
    {
        global $wpdb;

        $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table('polls')} SET status = %s, revision = revision + 1, updated_at = %s WHERE id = %d",
            $status,
            $this->now(),
            $id
        ));
    }

    public function delete_poll(int $id): void
    {
        global $wpdb;

        $votes = $this->table('votes');
        $ballots = $this->table('ballots');
        $wpdb->query($wpdb->prepare(
            "DELETE v FROM {$votes} v INNER JOIN {$ballots} b ON b.id = v.ballot_id WHERE b.poll_id = %d",
            $id
        ));
        $wpdb->delete($ballots, ['poll_id' => $id], ['%d']);
        $wpdb->delete($this->table('choices'), ['poll_id' => $id], ['%d']);
        $wpdb->delete($this->table('polls'), ['id' => $id], ['%d']);
    }

    public function get_poll(int $id): ?array
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table('polls')} WHERE id = %d",
            $id
        ), ARRAY_A);

        return is_array($row) ? $this->hydrate_poll($row) : null;
    }

    public function get_poll_by_code(string $code): ?array
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table('polls')} WHERE code = %s",
            $code
        ), ARRAY_A);

        return is_array($row) ? $this->hydrate_poll($row) : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function list_polls(): array
    {
        global $wpdb;

        $polls = $this->table('polls');
        $ballots = $this->table('ballots');
        $rows = $wpdb->get_results(
            "SELECT p.*, (SELECT COUNT(*) FROM {$ballots} b WHERE b.poll_id = p.id) AS ballots
            FROM {$polls} p
            ORDER BY p.created_at DESC",
            ARRAY_A
        );

        if (!is_array($rows)) {
            return [];
        }

        return array_map([$this, 'hydrate_poll'], $rows);
    }

    public function next_code(): string
    {
        for ($i = 0; $i < 12; $i++) {
            $code = Codes::generate();
            if ($this->get_poll_by_code($code) === null) {
                return $code;
            }
        }

        throw new \RuntimeException('code');
    }

    /**
     * @param array<int, array{label: string, image_id: int}> $choices
     */
    public function replace_choices(int $poll_id, array $choices): bool
    {
        if ($this->ballot_count($poll_id) > 0) {
            return false;
        }

        global $wpdb;

        $wpdb->delete($this->table('choices'), ['poll_id' => $poll_id], ['%d']);
        $position = 0;
        foreach ($choices as $choice) {
            $wpdb->insert(
                $this->table('choices'),
                [
                    'poll_id' => $poll_id,
                    'label' => $choice['label'],
                    'image_id' => $choice['image_id'],
                    'position' => $position,
                ],
                ['%d', '%s', '%d', '%d']
            );
            $position++;
        }
        $this->touch($poll_id);
        return true;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function get_choices(int $poll_id): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table('choices')} WHERE poll_id = %d ORDER BY position ASC, id ASC",
            $poll_id
        ), ARRAY_A);

        if (!is_array($rows)) {
            return [];
        }

        return array_map(static function ($row) {
            return [
                'id' => (int) $row['id'],
                'poll_id' => (int) $row['poll_id'],
                'label' => (string) $row['label'],
                'image_id' => (int) $row['image_id'],
                'position' => (int) $row['position'],
            ];
        }, $rows);
    }

    public function ballot_count(int $poll_id): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table('ballots')} WHERE poll_id = %d",
            $poll_id
        ));
    }

    /**
     * @return array<int, int> choice id => votes
     */
    public function choice_counts(int $poll_id): array
    {
        global $wpdb;

        $votes = $this->table('votes');
        $ballots = $this->table('ballots');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT v.choice_id, COUNT(*) AS votes
            FROM {$votes} v
            INNER JOIN {$ballots} b ON b.id = v.ballot_id
            WHERE b.poll_id = %d
            GROUP BY v.choice_id",
            $poll_id
        ), ARRAY_A);

        $counts = [];
        if (!is_array($rows)) {
            return $counts;
        }
        foreach ($rows as $row) {
            $counts[(int) $row['choice_id']] = (int) $row['votes'];
        }
        return $counts;
    }

    public function get_ballot(int $poll_id, string $voter_token): ?array
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table('ballots')} WHERE poll_id = %d AND voter_token = %s",
            $poll_id,
            $voter_token
        ), ARRAY_A);

        if (!is_array($row)) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'poll_id' => (int) $row['poll_id'],
            'voter_token' => (string) $row['voter_token'],
        ];
    }

    /**
     * @return array<int, int>
     */
    public function ballot_choice_ids(int $ballot_id): array
    {
        global $wpdb;

        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT choice_id FROM {$this->table('votes')} WHERE ballot_id = %d",
            $ballot_id
        ));

        if (!is_array($ids)) {
            return [];
        }

        return array_map('intval', $ids);
    }

    /**
     * Replace this browser's ballot. Throws RuntimeException('closed')
     * or RuntimeException('invalid') after rolling back.
     *
     * @param array<int, int> $choice_ids
     */
    public function cast(int $poll_id, string $voter_token, array $choice_ids): void
    {
        global $wpdb;

        $polls = $this->table('polls');
        $choices = $this->table('choices');
        $ballots = $this->table('ballots');
        $votes = $this->table('votes');
        $now = $this->now();

        $wpdb->query('START TRANSACTION');

        $poll = $wpdb->get_row($wpdb->prepare(
            "SELECT id, status FROM {$polls} WHERE id = %d FOR UPDATE",
            $poll_id
        ));
        if (!$poll || $poll->status !== 'open') {
            $wpdb->query('ROLLBACK');
            throw new \RuntimeException('closed');
        }

        $choice_ids = array_values(array_map('intval', $choice_ids));
        if (count($choice_ids) === 0) {
            $wpdb->query('ROLLBACK');
            throw new \RuntimeException('invalid');
        }

        $placeholders = implode(',', array_fill(0, count($choice_ids), '%d'));
        $found = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$choices} WHERE poll_id = %d AND id IN ({$placeholders})",
            ...array_merge([$poll_id], $choice_ids)
        ));
        $found = is_array($found) ? array_map('intval', $found) : [];
        sort($found);
        $expected = array_map('intval', $choice_ids);
        sort($expected);
        if ($found !== $expected) {
            $wpdb->query('ROLLBACK');
            throw new \RuntimeException('invalid');
        }

        $ballot_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$ballots} WHERE poll_id = %d AND voter_token = %s LIMIT 1 FOR UPDATE",
            $poll_id,
            $voter_token
        ));

        if ($ballot_id === 0) {
            $inserted = $wpdb->insert(
                $ballots,
                [
                    'poll_id' => $poll_id,
                    'voter_token' => $voter_token,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                ['%d', '%s', '%s', '%s']
            );
            if (!$inserted) {
                $ballot_id = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$ballots} WHERE poll_id = %d AND voter_token = %s LIMIT 1",
                    $poll_id,
                    $voter_token
                ));
            } else {
                $ballot_id = (int) $wpdb->insert_id;
            }
            if ($ballot_id === 0) {
                $wpdb->query('ROLLBACK');
                throw new \RuntimeException('invalid');
            }
        } else {
            $wpdb->update(
                $ballots,
                ['updated_at' => $now],
                ['id' => $ballot_id],
                ['%s'],
                ['%d']
            );
        }

        $wpdb->delete($votes, ['ballot_id' => $ballot_id], ['%d']);
        foreach ($expected as $choice_id) {
            $ok = $wpdb->insert(
                $votes,
                [
                    'ballot_id' => $ballot_id,
                    'choice_id' => $choice_id,
                ],
                ['%d', '%d']
            );
            if (!$ok) {
                $wpdb->query('ROLLBACK');
                throw new \RuntimeException('invalid');
            }
        }

        $wpdb->query($wpdb->prepare(
            "UPDATE {$polls} SET revision = revision + 1, updated_at = %s WHERE id = %d",
            $now,
            $poll_id
        ));
        $wpdb->query('COMMIT');
    }

    private function touch(int $id): void
    {
        global $wpdb;

        $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table('polls')} SET revision = revision + 1, updated_at = %s WHERE id = %d",
            $this->now(),
            $id
        ));
    }

    private function table(string $name): string
    {
        global $wpdb;

        return $wpdb->prefix . 'poller_' . $name;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrate_poll(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'code' => (string) $row['code'],
            'title' => (string) $row['title'],
            'question' => (string) $row['question'],
            'image_id' => (int) $row['image_id'],
            'kind' => (string) $row['kind'],
            'selection' => (string) $row['selection'],
            'status' => (string) $row['status'],
            'revision' => (int) $row['revision'],
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
            'ballots' => isset($row['ballots']) ? (int) $row['ballots'] : null,
        ];
    }

    private function now(): string
    {
        return current_time('mysql', true);
    }
}
