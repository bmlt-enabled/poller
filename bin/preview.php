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
if (!function_exists('_n')) {
    function _n($single, $plural, $number, $domain = null)
    {
        unset($domain);
        return (int) $number === 1 ? $single : $plural;
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

require_once dirname(__DIR__) . '/includes/Tally.php';
require_once dirname(__DIR__) . '/includes/BallotRules.php';

function poller_preview_svg(string $color): string
{
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="800" height="800"><rect width="800" height="800" fill="' . $color . '"/></svg>';
    return 'data:image/svg+xml,' . rawurlencode($svg);
}

function poller_preview_client(array $state, string $view): array
{
    $state['nonce'] = 'preview';
    $state['rest'] = '/api/poll?view=' . rawurlencode($view);
    $state['restVotes'] = '/api/votes?view=' . rawurlencode($view);
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
    if ($state['selection'] === 'dots') {
        $state['i18n'] += [
            'dotsLeft' => (int) $state['budget'] === 1 ? '%1$s of %2$s dot left' : '%1$s of %2$s dots left',
            'toPlaceOne' => '1 dot left to place',
            'toPlaceMany' => '%s dots left to place',
            'placeDot' => 'Place a dot to vote',
            'yourDots' => 'Your dots: %s',
            'oneDot' => '1 dot',
            'manyDots' => '%s dots',
            'showAll' => 'Show all %s',
            'showTop' => 'Show the top 10',
        ];
    }
    if ($state['selection'] === 'words') {
        $state['i18n'] += [
            'enterWord' => 'Enter at least one word.',
            'yourWord' => 'Your word',
            'noWords' => 'Words will show up here as people answer.',
            'showAll' => 'Show all %s',
            'showTop' => 'Show the top 10',
        ];
    }
    return $state;
}

function poller_preview_text_state(): array
{
    return [
        'code' => 'K7MQ2P',
        'question' => 'Where should we meet next month?',
        'kind' => 'text',
        'selection' => 'single',
        'budget' => 0,
        'per_choice' => 0,
        'spend_all' => false,
        'status' => 'open',
        'revision' => 7,
        'ballots' => 18,
        'dots' => 0,
        'image' => null,
        'mine' => [],
        'words' => [],
        'mine_keys' => [],
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

/**
 * A 30-item dot poll. Stacking is on for "dots" and off for "dots-toggle".
 */
function poller_preview_dots_state(bool $stacking): array
{
    $state = poller_preview_text_state();
    $state['question'] = 'Which topics should the next workshop cover?';
    $state['selection'] = 'dots';
    $state['budget'] = 5;
    $state['per_choice'] = $stacking ? 3 : 1;
    $state['spend_all'] = $stacking;
    $state['ballots'] = 12;
    $state['revision'] = 3;
    $topics = ['Sponsorship', 'Service structure', 'Literature', 'Public relations', 'Phone line', 'Website', 'Meeting list', 'Treasury', 'Elections', 'Outreach', 'Hospitals and institutions', 'Newcomers', 'Translation', 'Archives', 'Events', 'Workshops', 'Budgets', 'Group conscience', 'Trusted servants', 'Rotation', 'Anonymity online', 'Social media', 'Accessibility', 'Rural meetings', 'Virtual meetings', 'Youth', 'Literature storage', 'Insurance', 'Venue rent', 'Coffee'];
    $votes = [9, 4, 7, 2, 0, 11, 3, 5, 1, 6, 0, 8, 0, 2, 3, 1, 0, 0, 4, 0, 2, 0, 1, 0, 3, 0, 0, 1, 0, 2];
    $state['choices'] = [];
    foreach ($topics as $index => $label) {
        $state['choices'][] = ['id' => $index + 1, 'label' => $label, 'votes' => $votes[$index], 'image' => null];
    }
    $state['dots'] = array_sum($votes);
    return $state;
}

/**
 * Stored word cloud entries, as Repository::word_rows() returns them.
 *
 * @return array<int, array{key: string, text: string, count: int}>
 */
function poller_preview_word_rows(): array
{
    // Spelling => how many ballots typed it. Rows go out one per entry, like word_rows().
    $entries = [
        'hopeful' => 7, 'Hopeful' => 4, 'Tired' => 7, 'grateful' => 6, 'Calm' => 5, 'Busy' => 4,
        'connected' => 4, 'Long day' => 3, 'peaceful' => 3, 'Rested' => 2, 'curious' => 2,
        'Inspired' => 2, 'Full' => 1, 'Stretched thin' => 1, 'quiet' => 1, 'Okay' => 1,
        'Seen' => 1, 'Encouraged' => 1, 'Restless' => 1, 'Present' => 1, 'Home' => 1,
    ];
    $rows = [];
    foreach ($entries as $text => $count) {
        $word = BmltEnabled\Poller\BallotRules::word($text);
        for ($i = 0; $i < $count; $i++) {
            $rows[] = ['key' => $word['key'], 'text' => $word['text'], 'count' => 1];
        }
    }
    return $rows;
}

/**
 * A word cloud poll, three words per person.
 *
 * @param array<int, array{key: string, text: string, count: int}> $extra More entries, from a preview vote.
 */
function poller_preview_words_state(array $extra = []): array
{
    $state = poller_preview_text_state();
    $state['question'] = 'One word for how today went?';
    $state['selection'] = 'words';
    $state['budget'] = 3;
    $state['ballots'] = 22;
    $state['revision'] = 5;
    $state['choices'] = [];
    $state['words'] = BmltEnabled\Poller\Tally::words(array_merge(poller_preview_word_rows(), $extra));
    return $state;
}

function poller_preview_view_state(string $view): array
{
    if ($view === 'words' || $view === 'words-board') {
        return poller_preview_words_state();
    }
    if ($view === 'pictures') {
        return poller_preview_picture_state();
    }
    if ($view === 'dots' || $view === 'dots-board') {
        return poller_preview_dots_state(true);
    }
    if ($view === 'dots-toggle') {
        return poller_preview_dots_state(false);
    }
    $state = poller_preview_text_state();
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
    } elseif (in_array($view, ['poll', 'pictures', 'multi', 'closed', 'board', 'dots', 'dots-toggle', 'dots-board', 'words', 'words-board'], true)) {
        $state = poller_preview_view_state($view);
        $board = str_ends_with($view, 'board');
        $template = $board ? 'board' : 'poll';
        $class = $board ? 'poller-body poller-body--board' : 'poller-body';
        $title = $state['question'];
        $qr = $board;
    }

    $client = poller_preview_client($state, $view);
    $ballots = (int) $state['ballots'];
    if ($ballots === 0) {
        $total_label = 'No votes yet';
    } elseif ($ballots === 1) {
        $total_label = '1 vote';
    } else {
        $total_label = $ballots . ' votes';
    }
    if ($ballots > 0 && $state['selection'] === 'dots') {
        $total_label .= ' · ' . $state['dots'] . ' dots';
    }
    $parts = [$total_label];
    foreach ($state['choices'] as $choice) {
        $parts[] = $choice['label'] . ' ' . $choice['votes'];
    }
    foreach ($state['words'] as $word) {
        $parts[] = $word['text'] . ' ' . $word['count'];
    }
    $moderate = null;
    if ($view === 'words') {
        // What an admin sees on the poll page. The preview has no admin-post, so the button goes nowhere.
        $moderate = [
            'url' => '/words',
            'poll' => 1,
            'nonce' => 'preview',
            'hide' => 'Hide',
            'hideLabel' => 'Hide "%s" from the results',
        ];
        $client['moderate'] = $moderate;
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
        'moderate' => $moderate,
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
    <?php if (str_contains($body, 'data-poller-cloud')) : ?>
        <?php // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript ?>
        <script src="/assets/js/cloud.js"></script>
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
    // Read-only preview query: which sample poll the API answers for.
    $view = isset($_GET['view']) ? sanitize_text_field(wp_unslash($_GET['view'])) : 'poll'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    if ($path === '/api/poll') {
        header('Content-Type: application/json');
        echo wp_json_encode(poller_preview_client(poller_preview_view_state($view), $view));
        return null;
    }
    if ($path === '/api/votes') {
        $payload = json_decode((string) file_get_contents('php://input'), true);
        $submitted = (is_array($payload) && isset($payload['choices']) && is_array($payload['choices'])) ? $payload['choices'] : [];
        $state = poller_preview_view_state($view);
        if ($state['selection'] === 'words') {
            $picked = BmltEnabled\Poller\BallotRules::words($submitted, (int) $state['budget']);
            if ($picked['error'] !== null) {
                http_response_code(400);
                header('Content-Type: application/json');
                echo wp_json_encode(['code' => 'poller_' . $picked['error'], 'message' => 'Check your words: ' . $picked['error']]);
                return null;
            }
            $extra = array_map(static function ($word) {
                return $word + ['count' => 1];
            }, $picked['words']);
            $state = poller_preview_words_state($extra);
            $state['mine'] = array_column($picked['words'], 'text');
            $state['mine_keys'] = array_column($picked['words'], 'key');
            $state['ballots']++;
            $state['revision']++;
            header('Content-Type: application/json');
            echo wp_json_encode(poller_preview_client($state, $view));
            return null;
        }
        // Dot polls send choice id => dots. The others send a list of choice ids.
        $weights = $state['selection'] === 'dots'
            ? array_map('intval', $submitted)
            : array_fill_keys(array_map('intval', $submitted), 1);
        $state['mine'] = $state['selection'] === 'dots' ? $weights : array_keys($weights);
        $state['ballots']++;
        $state['revision']++;
        foreach ($state['choices'] as $index => $choice) {
            $state['choices'][$index]['votes'] += $weights[(int) $choice['id']] ?? 0;
        }
        if ($state['selection'] === 'dots') {
            $state['dots'] += array_sum($weights);
        }
        header('Content-Type: application/json');
        echo wp_json_encode(poller_preview_client($state, $view));
        return null;
    }
    $routes = [
        '/' => 'join',
        '/poll' => 'poll',
        '/pictures' => 'pictures',
        '/multi' => 'multi',
        '/closed' => 'closed',
        '/board' => 'board',
        '/dots' => 'dots',
        '/dots-toggle' => 'dots-toggle',
        '/dots-board' => 'dots-board',
        '/words' => 'words',
        '/words-board' => 'words-board',
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
    foreach (['join', 'poll', 'pictures', 'multi', 'closed', 'board', 'dots', 'dots-toggle', 'dots-board', 'words', 'words-board', 'missing', 'error'] as $view) {
        $html = poller_preview_render($view);
        if (str_starts_with($view, 'words') && (!str_contains($html, 'data-poller-cloud') || !str_contains($html, 'data-word="hopeful"'))) {
            fwrite(STDERR, "FAIL: {$view} is missing the word cloud\n"); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
            $failed++;
        }
        if ($view === 'words' && substr_count($html, 'name="words[]"') !== 3) {
            fwrite(STDERR, "FAIL: words is missing the word boxes\n"); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
            $failed++;
        }
        if ($view === 'words-board' && str_contains($html, 'poller_hide_word')) {
            fwrite(STDERR, "FAIL: the room display shows hide buttons\n"); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
            $failed++;
        }
        if (str_starts_with($view, 'dots') && !str_contains($html, 'poller-results--ranked')) {
            fwrite(STDERR, "FAIL: {$view} is missing the ranked tally\n"); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
            $failed++;
        }
        if (($view === 'dots' || $view === 'dots-toggle') && !str_contains($html, 'name="choices[1]"')) {
            fwrite(STDERR, "FAIL: {$view} is missing the dot controls\n"); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
            $failed++;
        }
        if (!str_contains($html, 'Poller') && !str_ends_with($view, 'board')) {
            fwrite(STDERR, "FAIL: {$view} did not render\n"); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
            $failed++;
            continue;
        }
        if (str_ends_with($view, 'board') && !str_contains($html, 'K7MQ2P')) {
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

// Only self-check when run directly (php bin/preview.php), not when required
// by the test suite, which exercises the same renders via assertions.
$poller_preview_script = isset($_SERVER['SCRIPT_FILENAME']) ? realpath($_SERVER['SCRIPT_FILENAME']) : '';
if (PHP_SAPI === 'cli' && $poller_preview_script === __FILE__) {
    poller_preview_self_check();
}
