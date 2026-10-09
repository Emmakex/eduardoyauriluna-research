<?php
/**
 * Plugin Name: Research Manager
 * Plugin URI: https://kairoseth.com/
 * Description: Controlled creation, diagnostics and mutation control plane for the Eduardo Research Theme.
 * Version: 0.6.0
 * Requires at least: 6.6
 * Requires PHP: 8.1
 * Author: Emmake by Kairoseth
 * Author URI: https://kairoseth.com/
 * Text Domain: eduardo-research-manager
 */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

define('EDUARDO_RESEARCH_MANAGER_VERSION', '0.6.0');
define('EDUARDO_RESEARCH_MANAGER_FILE', __FILE__);
define('EDUARDO_RESEARCH_MANAGER_DIR', plugin_dir_path(__FILE__));

require_once EDUARDO_RESEARCH_MANAGER_DIR . 'includes/class-research-manager-contract.php';
require_once EDUARDO_RESEARCH_MANAGER_DIR . 'includes/class-research-manager-diagnostics.php';
require_once EDUARDO_RESEARCH_MANAGER_DIR . 'includes/class-research-manager-plan.php';
require_once EDUARDO_RESEARCH_MANAGER_DIR . 'includes/class-research-manager-snapshots.php';
require_once EDUARDO_RESEARCH_MANAGER_DIR . 'includes/class-research-manager-executor.php';
require_once EDUARDO_RESEARCH_MANAGER_DIR . 'includes/class-research-manager-page-resource.php';
require_once EDUARDO_RESEARCH_MANAGER_DIR . 'includes/class-research-manager-insight-resource.php';
require_once EDUARDO_RESEARCH_MANAGER_DIR . 'includes/class-research-manager-output-resource.php';
require_once EDUARDO_RESEARCH_MANAGER_DIR . 'includes/class-research-manager-project-resource.php';
require_once EDUARDO_RESEARCH_MANAGER_DIR . 'includes/class-research-manager-admin.php';
require_once EDUARDO_RESEARCH_MANAGER_DIR . 'includes/class-research-manager.php';

register_activation_hook(__FILE__, static function (): void {
    update_option('eduardo_research_manager_version', EDUARDO_RESEARCH_MANAGER_VERSION, false);
});

add_action('plugins_loaded', array('Eduardo_Research_Manager', 'boot'));
