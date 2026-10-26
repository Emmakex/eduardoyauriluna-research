<?php
/** Canonical bundled Greenfield blueprint loader and integrity boundary. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Blueprint_Store {
    private const DEFAULT_RELATIVE_PATH = 'blueprints/eduardo-research.json';
    private const DEFAULT_EXPORT_FILENAME = 'eduardo-research-blueprint-v1.json';

    private Eduardo_Research_Manager_Blueprint $blueprints;
    private string $path;

    public function __construct(?Eduardo_Research_Manager_Blueprint $blueprints = null, ?string $path = null) {
        $this->blueprints = $blueprints ?: Eduardo_Research_Manager::blueprint();
        $this->path = $path ?: EDUARDO_RESEARCH_MANAGER_DIR . self::DEFAULT_RELATIVE_PATH;
    }

    public function canonical(): array|WP_Error {
        $raw = $this->raw();
        if (is_wp_error($raw)) { return $raw; }
        $decoded = json_decode($raw, true);
        if (! is_array($decoded) || JSON_ERROR_NONE !== json_last_error()) {
            return new WP_Error('research_manager_blueprint_json_invalid', 'The bundled canonical Research blueprint is not valid JSON.');
        }
        $validated = $this->blueprints->validate($decoded);
        if (is_wp_error($validated)) { return $validated; }
        return $decoded;
    }

    public function raw(): string|WP_Error {
        if (! is_readable($this->path) || ! is_file($this->path)) {
            return new WP_Error('research_manager_blueprint_missing', 'The bundled canonical Research blueprint is missing or unreadable.');
        }
        $raw = file_get_contents($this->path);
        if (false === $raw || '' === trim($raw)) {
            return new WP_Error('research_manager_blueprint_unreadable', 'The bundled canonical Research blueprint could not be read.');
        }
        return (string) $raw;
    }

    /**
     * Describe the active instance blueprint without turning its instance data
     * into Site Architect engine constants.
     */
    public function descriptor(): array {
        return array(
            'role'=>'active-instance-blueprint',
            'path'=>$this->path,
            'relative_path'=>str_starts_with($this->path, EDUARDO_RESEARCH_MANAGER_DIR)
                ? ltrim(substr($this->path, strlen(EDUARDO_RESEARCH_MANAGER_DIR)), '/')
                : '',
            'export_filename'=>self::DEFAULT_EXPORT_FILENAME,
        );
    }

    /**
     * Read instance-owned identity defaults from the active blueprint.
     *
     * Site Architect may consume these defaults, but the exact researcher name,
     * domain and translated copy remain instance data rather than engine data.
     */
    public function instance_defaults(): array|WP_Error {
        $blueprint = $this->canonical();
        if (is_wp_error($blueprint)) { return $blueprint; }

        $site = is_array($blueprint['site'] ?? null) ? $blueprint['site'] : array();
        $identity = is_array($site['identity'] ?? null) ? $site['identity'] : array();
        $identity_headlines = is_array($identity['headlines'] ?? null) ? $identity['headlines'] : array();
        $languages = array_values(array_filter(array_map(
            static fn(mixed $value): string => is_scalar($value) ? sanitize_key((string) $value) : '',
            is_array($blueprint['languages'] ?? null) ? $blueprint['languages'] : array()
        )));

        // Backward-compatible fallback for older v1 blueprints that stored identity aliases on Home.
        $home_slots = array();
        foreach ((array) ($blueprint['pages'] ?? array()) as $page) {
            if (! is_array($page) || 'home' !== sanitize_key((string) ($page['key'] ?? ''))) { continue; }
            $home_slots = is_array($page['slots'] ?? null) ? $page['slots'] : array();
            break;
        }

        $headlines = array();
        foreach ($languages as $language) {
            $headline = is_scalar($identity_headlines[$language] ?? null)
                ? trim((string) $identity_headlines[$language])
                : '';
            if ('' === $headline) {
                $slots = is_array($home_slots[$language] ?? null) ? $home_slots[$language] : array();
                $headline = is_scalar($slots['researcher-headline'] ?? null)
                    ? trim((string) $slots['researcher-headline'])
                    : '';
            }
            if ('' !== $headline) { $headlines[$language] = $headline; }
        }

        $identity_name = is_scalar($identity['name'] ?? null) ? trim((string) $identity['name']) : '';
        if ('' === $identity_name) {
            $identity_name = is_scalar($site['name'] ?? null) ? trim((string) $site['name']) : '';
        }

        return array(
            'site'=>array(
                'name'=>is_scalar($site['name'] ?? null) ? trim((string) $site['name']) : '',
                'canonical_domain'=>is_scalar($site['canonical_domain'] ?? null) ? trim((string) $site['canonical_domain']) : '',
                'purpose'=>is_scalar($site['purpose'] ?? null) ? trim((string) $site['purpose']) : '',
            ),
            'identity'=>array(
                'name'=>$identity_name,
                'headlines'=>$headlines,
            ),
            'languages'=>$languages,
        );
    }

    public function metadata(): array|WP_Error {
        $blueprint = $this->canonical();
        if (is_wp_error($blueprint)) { return $blueprint; }
        $raw = $this->raw();
        if (is_wp_error($raw)) { return $raw; }
        $summary = $this->blueprints->summarize($blueprint);
        if (is_wp_error($summary)) { return $summary; }

        return array(
            'name'=>(string) ($blueprint['site']['name'] ?? ''),
            'canonical_domain'=>(string) ($blueprint['site']['canonical_domain'] ?? ''),
            'version'=>(int) ($blueprint['version'] ?? 1),
            'languages'=>$blueprint['languages'] ?? array(),
            'summary'=>$summary,
            'sha256'=>hash('sha256', $raw),
            'bytes'=>strlen($raw),
            'path'=>$this->path,
            'descriptor'=>$this->descriptor(),
        );
    }

    public function export_filename(): string {
        return self::DEFAULT_EXPORT_FILENAME;
    }

    public function export_json(): string|WP_Error {
        $blueprint = $this->canonical();
        if (is_wp_error($blueprint)) { return $blueprint; }
        $json = wp_json_encode($blueprint, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (! is_string($json) || '' === $json) {
            return new WP_Error('research_manager_blueprint_export_failed', 'The canonical Research blueprint could not be encoded for export.');
        }
        return $json . "\n";
    }
}
