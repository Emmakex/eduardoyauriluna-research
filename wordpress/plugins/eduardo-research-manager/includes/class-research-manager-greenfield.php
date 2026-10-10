<?php
/** Greenfield orchestration boundary for a clean Research WordPress installation. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Greenfield {
    private Eduardo_Research_Manager_Contract $contract;

    public function __construct(?Eduardo_Research_Manager_Contract $contract = null) {
        $this->contract = $contract ?: new Eduardo_Research_Manager_Contract();
    }

    public function ready(): bool|WP_Error {
        if (! Eduardo_Research_Manager_Mode::is_greenfield()) {
            return new WP_Error('research_manager_greenfield_mode_required', 'Greenfield orchestration requires Greenfield mode.');
        }
        if (! $this->contract->compatible()) {
            return new WP_Error('research_manager_theme_contract_unavailable', 'A compatible Research Theme contract is required for Greenfield creation.');
        }
        return true;
    }

    public function resources(): array|WP_Error {
        $ready = $this->ready();
        if (is_wp_error($ready)) { return $ready; }
        return array(
            'pages' => Eduardo_Research_Manager::pages(),
            'insights' => Eduardo_Research_Manager::insights(),
            'outputs' => Eduardo_Research_Manager::outputs(),
            'projects' => Eduardo_Research_Manager::projects(),
            'software' => Eduardo_Research_Manager::software(),
            'datasets' => Eduardo_Research_Manager::datasets(),
        );
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
