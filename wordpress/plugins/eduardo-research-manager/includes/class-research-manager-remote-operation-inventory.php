<?php
/** Read-only aggregate inventory over all typed Manager operation lifecycles. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Remote_Operation_Inventory {
    private const MAX_PAGE_SIZE = 100;

    public function query(array $args = array()): array {
        $items = $this->all();
        $operation_id = sanitize_text_field((string) ($args['operation_id'] ?? ''));
        $status = sanitize_key((string) ($args['status'] ?? ''));
        $operation = sanitize_key((string) ($args['operation'] ?? ''));
        $connection = sanitize_text_field((string) ($args['connection_id'] ?? ''));
        $allowed_statuses = array('applied','pending-verification','verified','verification-failed','rolled-back');
        if ('' !== $status && ! in_array($status, $allowed_statuses, true)) { $status = ''; }

        $filtered = array_values(array_filter($items, static function (array $item) use ($operation_id, $status, $operation, $connection): bool {
            if ('' !== $operation_id && (string) ($item['operation_id'] ?? '') !== $operation_id) { return false; }
            if ('' !== $status && (string) ($item['display_status'] ?? '') !== $status) { return false; }
            if ('' !== $operation && (string) ($item['operation'] ?? '') !== $operation) { return false; }
            if ('' !== $connection && (string) ($item['connection_id'] ?? '') !== $connection) { return false; }
            return true;
        }));

        $page = max(1, (int) ($args['page'] ?? 1));
        $per_page = max(1, min(self::MAX_PAGE_SIZE, (int) ($args['per_page'] ?? 50)));
        $total = count($filtered);
        $offset = ($page - 1) * $per_page;
        return array(
            'page'=>$page,
            'per_page'=>$per_page,
            'total'=>$total,
            'total_pages'=>0 === $total ? 0 : (int) ceil($total / $per_page),
            'filters'=>array_filter(array('operation_id'=>$operation_id,'status'=>$status,'operation'=>$operation,'connection_id'=>$connection), static fn(string $value): bool => '' !== $value),
            'items'=>$offset < $total ? array_slice($filtered, $offset, $per_page) : array(),
            'read_only'=>true,
            'statuses'=>$allowed_statuses,
        );
    }

    public function get(string $operation_id): array|WP_Error {
        $operation_id = sanitize_text_field($operation_id);
        if ('' === $operation_id) { return $this->error('operation_not_found', 'Operation identifier is required.', 404); }
        foreach (Eduardo_Research_Manager_Remote_Audit::operation_option_stores() as $store) {
            $all = get_option($store, array());
            if (! is_array($all) || ! is_array($all[$operation_id] ?? null)) { continue; }
            return $this->public_operation($all[$operation_id], $store);
        }
        return $this->error('operation_not_found', 'Manager operation was not found in any typed lifecycle.', 404);
    }

    private function all(): array {
        $items = array();
        foreach (Eduardo_Research_Manager_Remote_Audit::operation_option_stores() as $store) {
            $all = get_option($store, array());
            if (! is_array($all)) { continue; }
            foreach ($all as $operation) {
                if (! is_array($operation) || '' === (string) ($operation['operation_id'] ?? '')) { continue; }
                $items[] = $this->public_operation($operation, $store);
            }
        }
        usort($items, static function (array $a, array $b): int {
            $aTime = strtotime((string) ($a['updated_at'] ?? $a['created_at'] ?? '')) ?: 0;
            $bTime = strtotime((string) ($b['updated_at'] ?? $b['created_at'] ?? '')) ?: 0;
            if ($aTime === $bTime) { return strcmp((string) ($b['operation_id'] ?? ''), (string) ($a['operation_id'] ?? '')); }
            return $bTime <=> $aTime;
        });
        return $items;
    }

    private function public_operation(array $operation, string $store): array {
        $status = sanitize_key((string) ($operation['status'] ?? ''));
        $stored = $operation['stored_verification'] ?? $operation['verification'] ?? array();
        $rendered = $operation['rendered_verification'] ?? array();
        $updated = (string) ($operation['rolled_back_at'] ?? $operation['verified_at'] ?? $operation['applied_at'] ?? $operation['created_at'] ?? '');
        return array(
            'operation_id'=>sanitize_text_field((string) ($operation['operation_id'] ?? '')),
            'plan_id'=>sanitize_text_field((string) ($operation['plan_id'] ?? '')),
            'operation'=>sanitize_key((string) ($operation['operation'] ?? '')),
            'target'=>$this->target_summary($operation['target'] ?? array()),
            'connection_id'=>sanitize_text_field((string) ($operation['connection_id'] ?? '')),
            'source_revision'=>sanitize_text_field((string) ($operation['source_revision'] ?? '')),
            'resulting_revision'=>sanitize_text_field((string) ($operation['post_apply_revision'] ?? $operation['resulting_revision'] ?? '')),
            'lifecycle_status'=>$status,
            'display_status'=>$this->display_status($status),
            'stored_verification_status'=>$this->verification_status($stored, false),
            'rendered_verification_status'=>$this->verification_status($rendered, true),
            'rollback_available'=>in_array($status, array('applied','verified','verification-failed'), true),
            'rollback_status'=>'rolled-back' === $status ? 'rolled-back' : (in_array($status, array('applied','verified','verification-failed'), true) ? 'available' : 'unavailable'),
            'created_at'=>sanitize_text_field((string) ($operation['created_at'] ?? '')),
            'applied_at'=>sanitize_text_field((string) ($operation['applied_at'] ?? '')),
            'verified_at'=>sanitize_text_field((string) ($operation['verified_at'] ?? '')),
            'rolled_back_at'=>sanitize_text_field((string) ($operation['rolled_back_at'] ?? '')),
            'updated_at'=>sanitize_text_field($updated),
            'store_family'=>$this->store_family($store),
        );
    }

    private function display_status(string $status): string {
        if ('applied' === $status) { return 'pending-verification'; }
        if ('verified' === $status) { return 'verified'; }
        if ('verification-failed' === $status) { return 'verification-failed'; }
        if ('rolled-back' === $status) { return 'rolled-back'; }
        return $status;
    }

    private function verification_status(mixed $value, bool $rendered): string {
        if (! is_array($value) || ! $value) { return $rendered ? 'not-requested' : 'pending'; }
        if (array_key_exists('requested', $value) && empty($value['requested'])) { return 'not-requested'; }
        if (! array_key_exists('verified', $value) || null === $value['verified']) { return 'pending'; }
        return ! empty($value['verified']) ? 'verified' : 'failed';
    }

    private function target_summary(mixed $target): array {
        if (! is_array($target)) { return array(); }
        $allowed = array('resource','type','key','page_id','post_id','attachment_id','group','record_id','language','first_id','second_id','location');
        $result = array();
        foreach ($allowed as $key) {
            if (! array_key_exists($key, $target) || ! is_scalar($target[$key])) { continue; }
            $result[$key] = is_int($target[$key]) ? (int) $target[$key] : substr(sanitize_text_field((string) $target[$key]), 0, 120);
        }
        return $result;
    }

    private function store_family(string $store): string {
        $families = array(
            'eduardo_research_manager_remote_operations'=>'core',
            'eduardo_research_manager_navigation_operations'=>'navigation',
            'eduardo_research_manager_remote_media_operations'=>'media-metadata',
            'eduardo_research_manager_remote_media_assignment_operations'=>'media-assignment',
            'eduardo_research_manager_remote_media_ingest_operations'=>'media-ingest',
            'eduardo_research_manager_remote_insight_pair_operations'=>'insight-pairing',
            'eduardo_research_manager_remote_object_operations'=>'research-object',
            'eduardo_research_manager_remote_line_operations'=>'research-line',
            'eduardo_research_manager_remote_translation_operations'=>'translation',
            'eduardo_research_manager_remote_evidence_operations'=>'evidence',
            'eduardo_research_manager_remote_identity_profile_operations'=>'identity-profile',
            'eduardo_research_manager_remote_seo_geo_operations'=>'seo-geo',
            'eduardo_research_manager_remote_design_operations'=>'design',
            'eduardo_research_manager_remote_design_variant_operations'=>'design-variant',
            'eduardo_research_manager_remote_page_state_operations'=>'page-state',
        );
        return (string) ($families[$store] ?? 'typed');
    }

    private function error(string $code, string $message, int $status): WP_Error { return new WP_Error($code, $message, array('status'=>$status)); }
}
