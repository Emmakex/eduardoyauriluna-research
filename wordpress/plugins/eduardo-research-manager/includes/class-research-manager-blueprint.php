<?php
/** Declarative Greenfield site blueprint boundary. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

require_once EDUARDO_RESEARCH_MANAGER_DIR . 'includes/class-research-manager-research-blueprint-resources.php';

final class Eduardo_Research_Manager_Blueprint {
    private const VERSION = 1;
    private Site_Architect_Blueprint_Resource_Provider $resource_provider;
    private Eduardo_Research_Manager_Contract $contract;

    public function __construct(
        ?Site_Architect_Blueprint_Resource_Provider $resource_provider = null,
        ?Eduardo_Research_Manager_Contract $contract = null
    ) {
        $this->resource_provider = $resource_provider ?? new Eduardo_Research_Manager_Research_Blueprint_Resources();
        $this->contract = $contract ?? new Eduardo_Research_Manager_Contract();
    }

    /** Blueprint's explicitly selected Theme resource authority. */
    public function theme_id(): string { return $this->resource_provider->theme_id(); }

    public function resource_keys(): array {
        return array_merge(array('pages'), $this->resource_provider->resource_keys());
    }

    public function validate(array $blueprint): array|WP_Error {
        if (! Eduardo_Research_Manager_Mode::is_greenfield()) {
            return new WP_Error('research_manager_blueprint_greenfield_required', 'Research blueprints require Greenfield mode.');
        }
        $version = isset($blueprint['version']) ? (int) $blueprint['version'] : self::VERSION;
        if (self::VERSION !== $version) {
            return new WP_Error('research_manager_blueprint_version_unsupported', 'Unsupported Research blueprint version.');
        }
        if (! $this->contract->compatible() || $this->resource_provider->theme_id() !== (string) ($this->contract->preset()['id'] ?? '')) {
            return new WP_Error('research_manager_blueprint_catalog_mismatch', 'Blueprint resource catalog does not belong to the active Theme contract.');
        }
        $resource_keys = $this->resource_keys();
        $allowed = array_merge(array('version','site','languages'), $resource_keys);
        $unknown = array_diff(array_keys($blueprint), $allowed);
        if ($unknown) {
            return new WP_Error('research_manager_blueprint_key_unknown', 'Blueprint contains unsupported top-level keys: ' . implode(', ', $unknown));
        }
        $languages = $blueprint['languages'] ?? $this->contract->languages();
        if (! is_array($languages) || ! $languages) {
            return new WP_Error('research_manager_blueprint_languages_invalid', 'Blueprint must declare at least one supported language.');
        }
        $languages = array_values(array_unique(array_map('sanitize_key', $languages)));
        $unsupported = array_diff($languages, $this->contract->languages());
        if ($unsupported) {
            return new WP_Error('research_manager_blueprint_language_unsupported', 'Blueprint requests languages outside the active Research Theme contract.');
        }
        $normalized = array('version'=>self::VERSION,'site'=>is_array($blueprint['site'] ?? null) ? $blueprint['site'] : array(),'languages'=>$languages);
        foreach ($resource_keys as $key) {
            $records = $blueprint[$key] ?? array();
            if (! is_array($records)) {
                return new WP_Error('research_manager_blueprint_resource_invalid', sprintf('Blueprint resource "%s" must be an array.', $key));
            }
            $normalized[$key] = array_values($records);
        }
        return $normalized;
    }

    public function summarize(array $blueprint): array|WP_Error {
        $normalized = $this->validate($blueprint);
        if (is_wp_error($normalized)) { return $normalized; }
        $counts = array();
        foreach ($this->resource_keys() as $key) { $counts[$key] = count($normalized[$key]); }
        return array('version'=>$normalized['version'],'languages'=>$normalized['languages'],'resources'=>$counts,'total_resources'=>array_sum($counts),'requires_legacy_discovery'=>false,'requires_gutenberg_layout'=>false);
    }
}
