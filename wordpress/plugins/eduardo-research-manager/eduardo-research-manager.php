<?php
/**
 * Plugin Name: Research Manager
 * Plugin URI: https://kairoseth.com/
 * Description: Controlled creation, diagnostics and mutation control plane for the Eduardo Research Theme.
 * Version: 0.9.0
 * Requires at least: 6.6
 * Requires PHP: 8.1
 * Author: Emmake by Kairoseth
 * Author URI: https://kairoseth.com/
 * Text Domain: eduardo-research-manager
 */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

define('EDUARDO_RESEARCH_MANAGER_VERSION', '0.9.0');
define('EDUARDO_RESEARCH_MANAGER_FILE', __FILE__);
define('EDUARDO_RESEARCH_MANAGER_DIR', plugin_dir_path(__FILE__));

require_once EDUARDO_RESEARCH_MANAGER_DIR . 'includes/class-research-manager-mode.php';
require_once EDUARDO_RESEARCH_MANAGER_DIR . 'includes/class-research-manager-contract.php';
require_once EDUARDO_RESEARCH_MANAGER_DIR . 'includes/class-research-manager-diagnostics.php';
require_once EDUARDO_RESEARCH_MANAGER_DIR . 'includes/class-research-manager-plan.php';
require_once EDUARDO_RESEARCH_MANAGER_DIR . 'includes/class-research-manager-snapshots.php';
require_once EDUARDO_RESEARCH_MANAGER_DIR . 'includes/class-research-manager-executor.php';
require_once EDUARDO_RESEARCH_MANAGER_DIR . 'includes/class-research-manager-page-resource.php';
require_once EDUARDO_RESEARCH_MANAGER_DIR . 'includes/class-research-manager-page-editor.php';
require_once EDUARDO_RESEARCH_MANAGER_DIR . 'includes/class-research-manager-insight-resource.php';
require_once EDUARDO_RESEARCH_MANAGER_DIR . 'includes/class-research-manager-insight-editor.php';
require_once EDUARDO_RESEARCH_MANAGER_DIR . 'includes/class-research-manager-line-resource.php';
require_once EDUARDO_RESEARCH_MANAGER_DIR . 'includes/class-research-manager-line-editor.php';
require_once EDUARDO_RESEARCH_MANAGER_DIR . 'includes/class-research-manager-output-resource.php';
require_once EDUARDO_RESEARCH_MANAGER_DIR . 'includes/class-research-manager-project-resource.php';
require_once EDUARDO_RESEARCH_MANAGER_DIR . 'includes/class-research-manager-software-resource.php';
require_once EDUARDO_RESEARCH_MANAGER_DIR . 'includes/class-research-manager-dataset-resource.php';
require_once EDUARDO_RESEARCH_MANAGER_DIR . 'includes/class-research-manager-rendered-verifier.php';
require_once EDUARDO_RESEARCH_MANAGER_DIR . 'includes/class-research-manager-remediation.php';
require_once EDUARDO_RESEARCH_MANAGER_DIR . 'includes/class-research-manager-translation-pairing.php';
require_once EDUARDO_RESEARCH_MANAGER_DIR . 'includes/class-research-manager-greenfield.php';
require_once EDUARDO_RESEARCH_MANAGER_DIR . 'includes/class-research-manager-blueprint.php';
require_once EDUARDO_RESEARCH_MANAGER_DIR . 'includes/class-research-manager-blueprint-store.php';
require_once EDUARDO_RESEARCH_MANAGER_DIR . 'includes/class-research-manager-blueprint-compiler.php';
require_once EDUARDO_RESEARCH_MANAGER_DIR . 'includes/class-research-manager-bootstrap.php';
require_once EDUARDO_RESEARCH_MANAGER_DIR . 'includes/class-research-manager-blueprint-hydrator.php';
require_once EDUARDO_RESEARCH_MANAGER_DIR . 'includes/class-research-manager-blueprint-pairing.php';
require_once EDUARDO_RESEARCH_MANAGER_DIR . 'includes/class-research-manager-blueprint-relations.php';
require_once EDUARDO_RESEARCH_MANAGER_DIR . 'includes/class-research-manager-greenfield-pipeline.php';
require_once EDUARDO_RESEARCH_MANAGER_DIR . 'includes/class-research-manager-admin.php';
require_once EDUARDO_RESEARCH_MANAGER_DIR . 'includes/class-research-manager-insight-admin.php';
require_once EDUARDO_RESEARCH_MANAGER_DIR . 'includes/class-research-manager-line-admin.php';
require_once EDUARDO_RESEARCH_MANAGER_DIR . 'includes/class-research-manager.php';

register_activation_hook(__FILE__, static function (): void {
    update_option('eduardo_research_manager_version', EDUARDO_RESEARCH_MANAGER_VERSION, false);
    if (false === get_option('eduardo_research_manager_mode', false)) {
        add_option('eduardo_research_manager_mode', Eduardo_Research_Manager_Mode::GREENFIELD, '', false);
    }
});

add_action('plugins_loaded', array('Eduardo_Research_Manager', 'boot'));
