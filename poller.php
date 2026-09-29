<?php
/**
 * Plugin Name: Poller
 * Description: Anonymous polls people join with a code or a QR code, with a live tally everyone can see.
 * Version: 0.1.0
 * Requires at least: 6.4
 * Requires PHP: 8.0
 * Tested up to: 6.8
 * Author: bmlt-enabled
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: poller
 * Author URI: https://bmlt.app
 *
 * @package Poller
 */

if (!defined('ABSPATH')) {
    exit;
}

define('POLLER_VERSION', '0.1.0');
define('POLLER_FILE', __FILE__);
define('POLLER_DIR', plugin_dir_path(__FILE__));
define('POLLER_URL', plugin_dir_url(__FILE__));

spl_autoload_register(static function ($class) {
    $prefix = 'BmltEnabled\\Poller\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = POLLER_DIR . 'includes/' . str_replace('\\', '/', $relative) . '.php';
    if (is_readable($path)) {
        require $path;
    }
});

register_activation_hook(POLLER_FILE, static function () {
    (new BmltEnabled\Poller\Plugin())->activate();
});

register_deactivation_hook(POLLER_FILE, static function () {
    flush_rewrite_rules();
});

add_action('plugins_loaded', static function () {
    (new BmltEnabled\Poller\Plugin())->boot();
});
