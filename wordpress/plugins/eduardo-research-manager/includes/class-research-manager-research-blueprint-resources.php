<?php
/** Exact legacy Blueprint resources and service bindings of the Research vertical. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

require_once EDUARDO_RESEARCH_MANAGER_DIR . 'includes/class-site-architect-blueprint-resource-provider.php';
require_once EDUARDO_RESEARCH_MANAGER_DIR . 'includes/class-research-manager-resource-registry.php';

final class Eduardo_Research_Manager_Research_Blueprint_Resources implements Site_Architect_Blueprint_Resource_Provider {
    /** Exact reviewed keys; Registry descriptions cannot add a new Blueprint resource. */
    private const KEYS = array('insights','lines','outputs','projects','software','datasets');

    public function theme_id(): string { return 'research'; }

    public function resource_keys(): array {
        return self::KEYS;
    }

    /** Keep Research intent vocabulary in its descriptive Registry. */
    public function resource_label(string $resource_key): string {
        return in_array($resource_key, self::KEYS, true)
            ? Eduardo_Research_Manager_Resource_Registry::blueprint_resource_label($resource_key)
            : '';
    }

    /** Bind exactly the existing Site Architect 0.11.1 Research services. */
    public function services(): array {
        return Eduardo_Research_Manager::research_services();
    }

    /** Preserve the accepted Research Line slug/language idempotency path. */
    public function existing_record(string $resource_key, array $record, array $services, string $default_language): array|WP_Error {
        if ('lines' !== $resource_key) { return array(); }
        $slug = sanitize_title((string) ($record['slug'] ?? $record['title'] ?? ''));
        $language = sanitize_key((string) ($record['language'] ?? $default_language));
        if ('' !== $slug) {
            $existing = $services['lines']->find_by_slug($slug, $language);
            if ($existing > 0) {
                return array('slug'=>$slug,'language'=>$language,'reason'=>'already_exists','post_id'=>$existing);
            }
        }
        return array();
    }
}
