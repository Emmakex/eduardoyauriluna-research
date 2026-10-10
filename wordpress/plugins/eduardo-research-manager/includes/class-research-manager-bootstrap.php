<?php
/** Coordinate Greenfield blueprint preview, apply, verify and rollback using native Manager plans. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Bootstrap {
    private Eduardo_Research_Manager_Blueprint_Compiler $compiler;
    private Eduardo_Research_Manager_Executor $executor;

    public function __construct(
        ?Eduardo_Research_Manager_Blueprint_Compiler $compiler = null,
        ?Eduardo_Research_Manager_Executor $executor = null
    ) {
        $this->compiler = $compiler ?: Eduardo_Research_Manager::compiler();
        $this->executor = $executor ?: Eduardo_Research_Manager::executor();
    }

    public function preview(array $blueprint): array|WP_Error {
        $compiled = $this->compiler->compile($blueprint);
        if (is_wp_error($compiled)) { return $compiled; }

        $operations = array();
        $apply_allowed = true;
        foreach ($compiled['plans'] as $entry) {
            $preview = $this->executor->preview($entry['plan']);
            if (is_wp_error($preview)) { return $preview; }
            $apply_allowed = $apply_allowed && ! empty($preview['apply_allowed']);
            $operations[] = array(
                'resource'=>$entry['resource'],
                'index'=>$entry['index'],
                'key'=>$entry['key'] ?? '',
                'plan_id'=>$entry['plan']['id'],
                'preview'=>$preview,
            );
        }

        return array(
            'version'=>$compiled['version'],
            'mode'=>$compiled['mode'],
            'languages'=>$compiled['languages'],
            'apply_allowed'=>$apply_allowed,
            'operations'=>$operations,
            'operation_count'=>count($operations),
            'skipped'=>$compiled['skipped'],
            'skipped_count'=>$compiled['skipped_count'],
            'requires_legacy_discovery'=>false,
            'requires_legacy_mapping'=>false,
        );
    }

    public function apply(array $blueprint): array|WP_Error {
        if (! current_user_can('manage_options')) {
            return new WP_Error('research_manager_forbidden', 'You are not allowed to apply the Greenfield bootstrap.');
        }

        $compiled = $this->compiler->compile($blueprint);
        if (is_wp_error($compiled)) { return $compiled; }

        $applied = array();
        foreach ($compiled['plans'] as $entry) {
            $result = $this->executor->apply($entry['plan']);
            if (is_wp_error($result)) {
                $rollback = $this->rollback_applied($applied);
                return new WP_Error(
                    'research_manager_bootstrap_failed',
                    $result->get_error_message(),
                    array('resource'=>$entry['resource'],'index'=>$entry['index'],'rollback'=>$rollback)
                );
            }
            $verification = $this->executor->verify($entry['plan']);
            if (is_wp_error($verification) || empty($verification['verified'])) {
                if (! empty($result['snapshot_id'])) { $this->executor->rollback((string) $result['snapshot_id']); }
                $rollback = $this->rollback_applied($applied);
                return new WP_Error(
                    'research_manager_bootstrap_verification_failed',
                    is_wp_error($verification) ? $verification->get_error_message() : 'A bootstrap operation failed verification.',
                    array('resource'=>$entry['resource'],'index'=>$entry['index'],'rollback'=>$rollback)
                );
            }
            $applied[] = array(
                'resource'=>$entry['resource'],
                'index'=>$entry['index'],
                'key'=>$entry['key'] ?? '',
                'plan'=>$entry['plan'],
                'result'=>$result,
                'verification'=>$verification,
            );
        }

        return array(
            'status'=>'applied',
            'mode'=>$compiled['mode'],
            'languages'=>$compiled['languages'],
            'operations'=>$applied,
            'operation_count'=>count($applied),
            'skipped'=>$compiled['skipped'],
            'skipped_count'=>$compiled['skipped_count'],
            'snapshot_ids'=>array_values(array_filter(array_map(static fn(array $row): string => (string) ($row['result']['snapshot_id'] ?? ''), $applied))),
            'verified'=>true,
        );
    }

    public function rollback(array $snapshot_ids): array|WP_Error {
        if (! current_user_can('manage_options')) {
            return new WP_Error('research_manager_forbidden', 'You are not allowed to rollback the Greenfield bootstrap.');
        }
        $results = array();
        foreach (array_reverse($snapshot_ids) as $snapshot_id) {
            $snapshot_id = sanitize_text_field((string) $snapshot_id);
            if ('' === $snapshot_id) { continue; }
            $result = $this->executor->rollback($snapshot_id);
            if (is_wp_error($result)) { return $result; }
            $results[] = $result;
        }
        return array('status'=>'rolled-back','snapshots'=>$results,'snapshot_count'=>count($results));
    }

    private function rollback_applied(array $applied): array {
        $results = array();
        foreach (array_reverse($applied) as $entry) {
            $snapshot_id = (string) ($entry['result']['snapshot_id'] ?? '');
            if ('' === $snapshot_id) { continue; }
            $result = $this->executor->rollback($snapshot_id);
            $results[] = is_wp_error($result)
                ? array('snapshot_id'=>$snapshot_id,'rolled_back'=>false,'error'=>$result->get_error_message())
                : array('snapshot_id'=>$snapshot_id,'rolled_back'=>true);
        }
        return $results;
    }
}
