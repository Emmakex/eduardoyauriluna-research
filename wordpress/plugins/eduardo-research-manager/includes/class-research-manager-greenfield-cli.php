<?php
/** WP-CLI operator surface for deterministic Greenfield Research deployment. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Greenfield_CLI {
    private const LAST_RUN_OPTION = 'eduardo_research_manager_last_pipeline_run';

    /**
     * Show Research Manager Greenfield readiness and last reversible run.
     *
     * ## EXAMPLES
     *
     *     wp research-manager greenfield status
     */
    public function status(array $args, array $assoc_args): void {
        unset($args, $assoc_args);
        $metadata = Eduardo_Research_Manager::blueprint_store()->metadata();
        if (is_wp_error($metadata)) { $this->error($metadata); }
        $diagnostics = Eduardo_Research_Manager::diagnostics()->run();
        $last_run = get_option(self::LAST_RUN_OPTION, array());
        $theme = wp_get_theme();

        $this->json(array(
            'mode'=>Eduardo_Research_Manager_Mode::current(),
            'manager_version'=>EDUARDO_RESEARCH_MANAGER_VERSION,
            'active_theme'=>array(
                'stylesheet'=>(string) $theme->get_stylesheet(),
                'name'=>(string) $theme->get('Name'),
                'version'=>(string) $theme->get('Version'),
                'research_theme_active'=>'eduardo-research' === (string) $theme->get_stylesheet(),
            ),
            'blueprint'=>array(
                'name'=>(string) ($metadata['name'] ?? ''),
                'canonical_domain'=>(string) ($metadata['canonical_domain'] ?? ''),
                'languages'=>$metadata['languages'] ?? array(),
                'sha256'=>(string) ($metadata['sha256'] ?? ''),
                'total_resources'=>(int) ($metadata['summary']['total_resources'] ?? 0),
            ),
            'readiness'=>array(
                'ready'=>! empty($diagnostics['ready']),
                'summary'=>$diagnostics['summary'] ?? array(),
            ),
            'last_run'=>is_array($last_run) ? $last_run : array(),
        ));
    }

    /**
     * Preview the canonical Greenfield blueprint without mutating WordPress.
     *
     * ## EXAMPLES
     *
     *     wp research-manager greenfield preview
     */
    public function preview(array $args, array $assoc_args): void {
        unset($args, $assoc_args);
        $blueprint = Eduardo_Research_Manager::blueprint_store()->canonical();
        if (is_wp_error($blueprint)) { $this->error($blueprint); }
        $metadata = Eduardo_Research_Manager::blueprint_store()->metadata();
        if (is_wp_error($metadata)) { $this->error($metadata); }
        $preview = Eduardo_Research_Manager::pipeline()->preview($blueprint);
        if (is_wp_error($preview)) { $this->error($preview); }

        $this->json(array(
            'blueprint_sha256'=>(string) ($metadata['sha256'] ?? ''),
            'preview'=>$preview,
        ));
    }

    /**
     * Apply and verify the bundled canonical Greenfield blueprint.
     *
     * Requires an administrator context. Use WP-CLI's global --user parameter.
     * The command asks for confirmation unless WP-CLI's global --yes flag is used.
     *
     * ## EXAMPLES
     *
     *     wp research-manager greenfield apply --user=admin --yes
     */
    public function apply(array $args, array $assoc_args): void {
        unset($args);
        $this->require_admin();
        $blueprint = Eduardo_Research_Manager::blueprint_store()->canonical();
        if (is_wp_error($blueprint)) { $this->error($blueprint); }
        $metadata = Eduardo_Research_Manager::blueprint_store()->metadata();
        if (is_wp_error($metadata)) { $this->error($metadata); }
        $preview = Eduardo_Research_Manager::pipeline()->preview($blueprint);
        if (is_wp_error($preview)) { $this->error($preview); }
        if (empty($preview['apply_allowed'])) {
            WP_CLI::error('Canonical Greenfield Apply is blocked by the current Preview gate.');
        }

        WP_CLI::confirm('Apply the canonical Greenfield Research blueprint?', $assoc_args);
        $result = Eduardo_Research_Manager::pipeline()->apply($blueprint);
        if (is_wp_error($result)) { $this->error($result); }

        $snapshot_count = (int) ($result['snapshot_count'] ?? 0);
        if ($snapshot_count > 0) {
            update_option(
                self::LAST_RUN_OPTION,
                array(
                    'status'=>'applied',
                    'applied_at'=>(string) ($result['applied_at'] ?? gmdate(DATE_W3C)),
                    'snapshot_count'=>$snapshot_count,
                    'snapshots'=>$result['snapshots'] ?? array(),
                    'blueprint_sha256'=>(string) ($metadata['sha256'] ?? ''),
                    'operator'=>'wp-cli',
                ),
                false
            );
        }

        $this->json(array(
            'status'=>(string) ($result['status'] ?? 'unknown'),
            'verified'=>! empty($result['verified']),
            'snapshot_count'=>$snapshot_count,
            'blueprint_sha256'=>(string) ($metadata['sha256'] ?? ''),
            'mutated'=>$snapshot_count > 0,
        ));
        WP_CLI::success($snapshot_count > 0
            ? sprintf('Canonical Greenfield site applied and verified with %d reversible snapshots.', $snapshot_count)
            : 'Canonical Greenfield site already matched the bundled blueprint; no mutation was required.');
    }

    /**
     * Roll back the latest reversible canonical Greenfield Apply.
     *
     * Requires an administrator context. The command asks for confirmation unless
     * WP-CLI's global --yes flag is used.
     *
     * ## EXAMPLES
     *
     *     wp research-manager greenfield rollback --user=admin --yes
     */
    public function rollback(array $args, array $assoc_args): void {
        unset($args);
        $this->require_admin();
        $state = get_option(self::LAST_RUN_OPTION, array());
        if (! is_array($state) || 'applied' !== (string) ($state['status'] ?? '') || ! is_array($state['snapshots'] ?? null)) {
            WP_CLI::error('No applied canonical Greenfield run is available for rollback.');
        }

        WP_CLI::confirm('Roll back the latest canonical Greenfield Research run?', $assoc_args);
        $result = Eduardo_Research_Manager::pipeline()->rollback($state['snapshots']);
        if (is_wp_error($result)) { $this->error($result); }

        $state['status'] = 'rolled-back';
        $state['rolled_back_at'] = (string) ($result['rolled_back_at'] ?? gmdate(DATE_W3C));
        $state['rollback_operator'] = 'wp-cli';
        update_option(self::LAST_RUN_OPTION, $state, false);

        $this->json(array(
            'status'=>(string) ($result['status'] ?? 'unknown'),
            'snapshot_count'=>(int) ($result['snapshot_count'] ?? 0),
            'rolled_back_at'=>(string) ($result['rolled_back_at'] ?? ''),
        ));
        WP_CLI::success(sprintf('Canonical Greenfield run rolled back; %d snapshots restored.', (int) ($result['snapshot_count'] ?? 0)));
    }

    private function require_admin(): void {
        if (! current_user_can('manage_options')) {
            WP_CLI::error('Administrator capability is required. Re-run with WP-CLI global --user=<administrator>.');
        }
    }

    private function json(array $payload): void {
        WP_CLI::line((string) wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function error(WP_Error $error): never {
        WP_CLI::error(sprintf('%s: %s', $error->get_error_code(), $error->get_error_message()));
    }
}
