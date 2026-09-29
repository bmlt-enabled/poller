<?php
/**
 * Remove Poller tables when the plugin is deleted.
 *
 * @package Poller
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

require_once __DIR__ . '/includes/Repository.php';

(new BmltEnabled\Poller\Repository())->drop_tables();
