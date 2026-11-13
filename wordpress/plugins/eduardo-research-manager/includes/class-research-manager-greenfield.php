<?php
/** Greenfield orchestration boundary for a clean Research WordPress installation. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

require_once EDUARDO_RESEARCH_MANAGER_DIR . 'includes/class-research-manager-research-blueprint-resources.php';

final class Eduardo_Research_Manager_Greenfield {
    private Eduardo_Research_Manager_Contract $contract;
    private Site_Architect_Blueprint_Resource_Provider $resource_provider;

    public function __construct(
        ?Eduardo_Research_Manager_Contract $contract = null,
        ?Site_Architect_Blueprint_Resource_Provider $resource_provider = null
    ) {
        $this->contract = $contract ?: new Eduardo_Research_Manager_Contract();
        $this->resource_provider = $resource_provider ?? new Eduardo_Research_Manager_Research_Blueprint_Resources();
    }

    public function contract(): Eduardo_Research_Manager_Contract { return $this->contract; }

    public function resource_provider(): Site_Architect_Blueprint_Resource_Provider { return $this->resource_provider; }

    public function ready(): bool|WP_Error {
        if (! Eduardo_Research_Manager_Mode::is_greenfield()) {
            return new WP_Error('research_manager_greenfield_mode_required', 'Greenfield orchestration requires Greenfield mode.');
        }
        if (! $this->contract->compatible()) {
            return new WP_Error('research_manager_theme_contract_unavailable', 'A compatible Research Theme contract is required for Greenfield creation.');
        }
        if ($this->resource_provider->theme_id() !== (string) ($this->contract->preset()['id'] ?? '')) {
            return new WP_Error('research_manager_blueprint_catalog_mismatch', 'Blueprint resource catalog does not belong to the active Theme contract.');
        }
        return true;
    }

    public function resources(): array|WP_Error {
        $ready = $this->ready();
        if (is_wp_error($ready)) { return $ready; }
        $services = $this->resource_provider->services();
        $expected = array_merge(array('pages'), $this->resource_provider->resource_keys());
        if (array_keys($services) !== $expected) {
            return new WP_Error('research_manager_blueprint_services_mismatch', 'Blueprint services do not match the declared Theme resources.');
        }
        foreach ($expected as $key) {
            if (! is_object($services[$key]) || ! is_callable(array($services[$key], 'build_creation_plan'))) {
                return new WP_Error('research_manager_blueprint_services_mismatch', 'Blueprint resource service is missing its declared creation planner.');
            }
        }
        return $services;
    }

    public function status(): array {
        $ready = $this->ready();
        return array(
            'mode' => Eduardo_Research_Manager_Mode::current(),
            'ready' => ! is_wp_error($ready),
            'error' => is_wp_error($ready) ? $ready->get_error_code() : null,
            'contract_compatible' => $this->contract->compatible(),
            'requires_legacy_discovery' => false,
            'requires_legacy_mapping' => false,
        );
    }
}
