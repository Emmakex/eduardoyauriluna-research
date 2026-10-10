<?php
/** Canonical bundled Greenfield blueprint loader and integrity boundary. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Blueprint_Store {
    private Eduardo_Research_Manager_Blueprint $blueprints;
    private string $path;

    public function __construct(?Eduardo_Research_Manager_Blueprint $blueprints = null, ?string $path = null) {
        $this->blueprints = $blueprints ?: Eduardo_Research_Manager::blueprint();
        $this->path = $path ?: EDUARDO_RESEARCH_MANAGER_DIR . 'blueprints/eduardo-research.json';
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

    public function metadata(): array|WP_Error {
        $blueprint = $this->canonical();
        if (is_wp_error($blueprint)) { return $blueprint; }
        $raw = $this->raw();
        if (is_wp_error($raw)) { return $raw; }
        $summary = $this->blueprints->summarize($blueprint);
        if (is_wp_error($summary)) { return $summary; }

        return array(
            'name'=>(string) ($blueprint['site']['name'] ?? 'Eduardo Jose Yauri Luna'),
            'canonical_domain'=>(string) ($blueprint['site']['canonical_domain'] ?? ''),
            'version'=>(int) ($blueprint['version'] ?? 1),
            'languages'=>$blueprint['languages'] ?? array(),
            'summary'=>$summary,
            'sha256'=>hash('sha256', $raw),
            'bytes'=>strlen($raw),
            'path'=>$this->path,
        );
    }

    public function export_filename(): string {
        return 'eduardo-research-blueprint-v1.json';
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
