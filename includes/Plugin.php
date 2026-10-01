<?php
/**
 * Plugin bootstrap.
 *
 * @package Poller
 */

namespace BmltEnabled\Poller;

if (!defined('ABSPATH')) {
    exit;
}

class Plugin
{
    public function boot(): void
    {
        $repo = new Repository();
        $presenter = new Presenter($repo);
        $votes = new VoteService($repo, $presenter);

        add_action('init', [$this, 'init']);
        add_filter('query_vars', [$this, 'query_vars']);
        add_filter('plugin_action_links_' . plugin_basename(POLLER_FILE), [$this, 'action_links']);
        add_action('rest_api_init', static function () use ($votes) {
            (new Rest($votes))->register();
        });

        (new Frontend($repo, $presenter, $votes))->hooks();

        if (is_admin()) {
            (new Admin($repo))->hooks();
        }
    }

    public function init(): void
    {
        $this->register_rewrites();

        if (get_option(Repository::VERSION_OPTION) !== Repository::DB_VERSION) {
            (new Repository())->install();
            flush_rewrite_rules();
        }
    }

    public function activate(): void
    {
        (new Repository())->install();
        $this->register_rewrites();
        flush_rewrite_rules();
    }

    public function register_rewrites(): void
    {
        add_rewrite_rule('^poller/?$', 'index.php?poller_view=join', 'top');
        add_rewrite_rule(
            '^poller/([A-Za-z0-9]{6})/board/?$',
            'index.php?poller_view=board&poller_code=$matches[1]',
            'top'
        );
        add_rewrite_rule(
            '^poller/([A-Za-z0-9]{6})/?$',
            'index.php?poller_view=poll&poller_code=$matches[1]',
            'top'
        );
    }

    /**
     * @param array<int, string> $vars
     * @return array<int, string>
     */
    public function query_vars(array $vars): array
    {
        $vars[] = 'poller_view';
        $vars[] = 'poller_code';
        return $vars;
    }

    /**
     * @param array<int, string> $links
     * @return array<int, string>
     */
    public function action_links(array $links): array
    {
        $url = admin_url('admin.php?page=poller');
        array_unshift(
            $links,
            '<a href="' . esc_url($url) . '">' . esc_html__('Polls', 'poller') . '</a>'
        );
        return $links;
    }

    public static function permalinks(): bool
    {
        return (string) get_option('permalink_structure') !== '';
    }

    public static function join_url(): string
    {
        if (self::permalinks()) {
            return home_url('/poller/');
        }
        return add_query_arg('poller_view', 'join', home_url('/'));
    }

    public static function poll_url(string $code): string
    {
        $code = Codes::normalize($code);
        if (self::permalinks()) {
            return home_url('/poller/' . rawurlencode($code) . '/');
        }
        return add_query_arg(
            [
                'poller_view' => 'poll',
                'poller_code' => $code,
            ],
            home_url('/')
        );
    }

    public static function board_url(string $code): string
    {
        $code = Codes::normalize($code);
        if (self::permalinks()) {
            return home_url('/poller/' . rawurlencode($code) . '/board/');
        }
        return add_query_arg(
            [
                'poller_view' => 'board',
                'poller_code' => $code,
            ],
            home_url('/')
        );
    }
}
