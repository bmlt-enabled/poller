<?php
/**
 * Static preview of the public pages, without WordPress.
 *
 * From the plugin directory:
 *   php -S 127.0.0.1:8899 -t . bin/preview.php
 */

if (PHP_SAPI === 'cli' || PHP_SAPI === 'cli-server') {
    if (!defined('ABSPATH')) {
        define('ABSPATH', __DIR__); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
    }
}

if (!defined('ABSPATH')) {
    exit;
}

// Stubs so the templates can render outside WordPress. Skipped when the real functions exist.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
if (!function_exists('esc_html')) {
    function esc_html($text)
    {
        return htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
if (!function_exists('esc_attr')) {
    function esc_attr($text)
    {
        return esc_html($text);
    }
}
if (!function_exists('esc_url')) {
    function esc_url($url)
    {
        return esc_html($url);
    }
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data)
    {
        return json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
    }
}
if (!function_exists('__')) {
    function __($text, $domain = null)
    {
        unset($domain);
        return $text;
    }
}
if (!function_exists('wp_unslash')) {
    function wp_unslash($value)
    {
        return is_string($value) ? stripslashes($value) : $value;
    }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($text)
    {
        return trim((string) $text);
    }
}
// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound

function poller_preview_svg(string $color): string
{
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="800" height="800"><rect width="800" height="800" fill="' . $color . '"/></svg>';
    return 'data:image/svg+xml,' . rawurlencode($svg);
}

function poller_preview_client(array $state): array
{
    $state['nonce'] = 'preview';
    $state['rest'] = '/api/poll';
    $state['restVotes'] = '/api/votes';
    $state['i18n'] = [
        'oneVote' => '1 vote',
        'manyVotes' => '%s votes',
        'noVotes' => 'No votes yet',
        'yourVote' => 'Your vote',
        'vote' => 'Vote',
        'updateVote' => 'Update vote',
        'saving' => 'Saving vote',
        'paused' => 'Live results paused. Still showing the last update.',
        'chooseOne' => 'Choose one option.',
        'chooseSome' => 'Choose at least one option.',
        'voteFailed' => 'The vote did not save. Try again.',
        'qr' => 'QR code that opens this poll',
    ];
    return $state;
}

function poller_preview_text_state(): array
{
    return [
        'code' => 'K7MQ2P',
        'question' => 'Where should we meet next month?',
        'kind' => 'text',
        'selection' => 'single',
        'status' => 'open',
        'revision' => 7,
        'ballots' => 18,
        'image' => null,
        'mine' => [],
        'choices' => [
            ['id' => 1, 'label' => 'The library', 'votes' => 11, 'image' => null],
            ['id' => 2, 'label' => 'The park shelter', 'votes' => 4, 'image' => null],
            ['id' => 3, 'label' => 'Keep it on a call', 'votes' => 3, 'image' => null],
        ],
        'urls' => [
            'poll' => 'http://127.0.0.1:8899/poll',
            'board' => 'http://127.0.0.1:8899/board',
            'join' => 'http://127.0.0.1:8899/',
        ],
    ];
}

function poller_preview_picture_state(): array
{
    $state = poller_preview_text_state();
    $state['question'] = 'Which cover should we print?';
    $state['kind'] = 'image';
    $state['selection'] = 'single';
    $state['ballots'] = 9;
    $state['revision'] = 4;
    $state['choices'] = [
        ['id' => 1, 'label' => 'River', 'votes' => 6, 'image' => ['url' => poller_preview_svg('#1f6f8b'), 'alt' => '']],
        ['id' => 2, 'label' => 'Orchard', 'votes' => 3, 'image' => ['url' => poller_preview_svg('#d23b2f'), 'alt' => '']],
    ];
    return $state;
}

function poller_preview_render(string $view): string
{
    $root = dirname(__DIR__);
    $join_url = '/';
    $error = '';
    $code_value = '';
    $state = poller_preview_text_state();
    $template = 'join';
    $class = 'poller-body poller-body--join';
    $title = 'Enter the code';
    $qr = false;

    if ($view === 'missing') {
        $template = 'missing';
        $class = 'poller-body';
        $title = 'Poll not found';
    } elseif ($view === 'error') {
        $error = 'That code has a character that is not used. Codes leave out 0, 1, I, L, and O.';
        $code_value = 'K7MQ20';
    } elseif ($view === 'poll' || $view === 'pictures' || $view === 'multi' || $view === 'closed' || $view === 'board') {
        if ($view === 'pictures' || $view === 'board') {
            $state = $view === 'board' ? poller_preview_text_state() : poller_preview_picture_state();
        }
        if ($view === 'multi') {
            $state['selection'] = 'multi';
            $state['question'] = 'What should we bring?';
            $state['choices'][0]['votes'] = 14;
            $state['choices'][1]['votes'] = 9;
            $state['choices'][2]['votes'] = 6;
            $state['ballots'] = 18;
        }
        if ($view === 'closed') {
            $state['status'] = 'closed';
        }
        $template = $view === 'board' ? 'board' : 'poll';
        $class = $view === 'board' ? 'poller-body poller-body--board' : 'poller-body';
        $title = $state['question'];
        $qr = $view === 'board';
    }

    $client = poller_preview_client($state);
    $ballots = (int) $state['ballots'];
    if ($ballots === 0) {
        $total_label = 'No votes yet';
    } elseif ($ballots === 1) {
        $total_label = '1 vote';
    } else {
        $total_label = $ballots . ' votes';
    }
    $parts = [$total_label];
    foreach ($state['choices'] as $choice) {
        $parts[] = $choice['label'] . ' ' . $choice['votes'];
    }
    $summary = implode('. ', $parts) . '.';

    $data = [
        'error' => $error,
        'code_value' => $code_value,
        'join_url' => $join_url,
        'state' => $state,
        'client' => $client,
        'post_url' => '/api/votes',
        'nonce' => 'preview',
        'can_manage' => $view === 'poll',
        'total_label' => $total_label,
        'summary' => $summary,
        'your_vote' => 'Your vote',
        'join_human' => 'example.org/poller',
    ];

    ob_start();
    (static function (string $path, array $data) {
        extract($data, EXTR_SKIP);
        require $path;
    })($root . '/templates/' . $template . '.php', $data);
    $body = (string) ob_get_clean();

    ob_start();
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html($title); ?></title>
    <?php // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet ?>
<link rel="stylesheet" href="/assets/css/fonts.css">
    <?php // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet ?>
<link rel="stylesheet" href="/assets/css/poller.css">
</head>
<body class="<?php echo esc_attr($class); ?>">
    <?php echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
    <?php if ($qr) : ?>
        <?php // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript ?>
        <script src="/assets/js/qrcode.js"></script>
    <?php endif; ?>
    <?php if ($qr || str_contains($body, 'id="poller-state"')) : ?>
        <?php // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript ?>
        <script src="/assets/js/poller.js"></script>
    <?php endif; ?>
</body>
</html>
    <?php
    return (string) ob_get_clean();
}

/**
 * Built-in server router. Returns false so PHP serves a real file.
 */
function poller_preview_serve(): ?bool
{
    $path = '/';
    if (isset($_SERVER['REQUEST_URI'])) {
        $uri = sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI']));
        $question = strpos($uri, '?');
        $path = ($question === false) ? $uri : substr($uri, 0, $question);
        if ($path === '') {
            $path = '/';
        }
    }
    $file = dirname(__DIR__) . $path;
    if ($path !== '/' && is_file($file)) {
        return false;
    }
    if ($path === '/api/poll') {
        header('Content-Type: application/json');
        echo wp_json_encode(poller_preview_client(poller_preview_text_state()));
        return null;
    }
    if ($path === '/api/votes') {
        $payload = json_decode((string) file_get_contents('php://input'), true);
        $ids = [];
        if (is_array($payload) && isset($payload['choices']) && is_array($payload['choices'])) {
            $ids = array_map('intval', $payload['choices']);
        }
        $state = poller_preview_text_state();
        $state['mine'] = $ids;
        $state['ballots'] = 19;
        $state['revision'] = 8;
        foreach ($state['choices'] as $index => $choice) {
            if (in_array((int) $choice['id'], $ids, true)) {
                $state['choices'][$index]['votes']++;
            }
        }
        header('Content-Type: application/json');
        echo wp_json_encode($state);
        return null;
    }
    $routes = [
        '/' => 'join',
        '/poll' => 'poll',
        '/pictures' => 'pictures',
        '/multi' => 'multi',
        '/closed' => 'closed',
        '/board' => 'board',
        '/missing' => 'missing',
        '/error' => 'error',
    ];
    if (!isset($routes[$path])) {
        http_response_code(404);
        echo 'Not found';
        return null;
    }
    echo poller_preview_render($routes[$path]); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    return null;
}

function poller_preview_self_check(): void
{
    $failed = 0;
    foreach (['join', 'poll', 'pictures', 'multi', 'closed', 'board', 'missing', 'error'] as $view) {
        $html = poller_preview_render($view);
        if (!str_contains($html, 'Poller') && $view !== 'board') {
            fwrite(STDERR, "FAIL: {$view} did not render\n"); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
            $failed++;
            continue;
        }
        if ($view === 'board' && !str_contains($html, 'K7MQ2P')) {
            fwrite(STDERR, "FAIL: board is missing the code\n"); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
            $failed++;
        }
    }
    if ($failed > 0) {
        exit(1);
    }
    fwrite(STDOUT, "preview ok\n"); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
}

if (PHP_SAPI === 'cli-server') {
    return poller_preview_serve();
}

if (PHP_SAPI === 'cli') {
    poller_preview_self_check();
}
