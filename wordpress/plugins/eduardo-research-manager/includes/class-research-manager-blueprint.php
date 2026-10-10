<?php
/** Declarative Greenfield site blueprint boundary. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Blueprint {
    private const VERSION = 1;
    private const RESOURCE_KEYS = array('pages','insights','outputs','projects','software','datasets');

    public function validate(array $blueprint): array|WP_Error {
        if (! Eduardo_Research_Manager_Mode::is_greenfield()) {
            return new WP_Error('research_manager_blueprint_greenfield_required', 'Research blueprints require Greenfield mode.');
        }

        $version = isset($blueprint['version']) ? (int) $blueprint['version'] : self::VERSION;
        if (self::VERSION !== $version) {
            return new WP_Error('research_manager_blueprint_version_unsupported', 'Unsupported Research blueprint version.');
        }

        $allowed = array_merge(array('version','site','languages'), self::RESOURCE_KEYS);
        $unknown = array_diff(array_keys($blueprint), $allowed);
        if ($unknown) {
            return new WP_Error('research_manager_blueprint_key_unknown', 'Blueprint contains unsupported top-level keys: ' . implode(', ', $unknown));
        }

        $languages = $blueprint['languages'] ?? Eduardo_Research_Manager::contract()->languages();
        if (! is_array($languages) || ! $languages) {
            return new WP_Error('research_manager_blueprint_languages_invalid', 'Blueprint must declare at least one supported language.');
        }
        $languages = array_values(array_unique(array_map('sanitize_key', $languages)));
        $unsupported = array_diff($languages, Eduardo_Research_Manager::contract()->languages());
        if ($unsupported) {
            return new WP_Error('research_manager_blueprint_language_unsupported', 'Blueprint requests languages outside the active Research Theme contract.');
        }

        $normalized = array(
            'version' => self::VERSION,
            'site' => is_array($blueprint['site'] ?? null) ? $blueprint['site'] : array(),
            'languages' => $languages,
        );
        foreach (self::RESOURCE_KEYS as $key) {
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
        foreach (self::RESOURCE_KEYS as $key) {
            $counts[$key] = count($normalized[$key]);
        }
        return array(
            'version' => $normalized['version'],
            'languages' => $normalized['languages'],
            'resources' => $counts,
            'total_resources' => array_sum($counts),
            'requires_legacy_discovery' => false,
            'requires_gutenberg_layout' => false,
        );
    }
}
