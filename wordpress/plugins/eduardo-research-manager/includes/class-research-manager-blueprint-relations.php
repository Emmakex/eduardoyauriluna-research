<?php
/** Resolve stable blueprint Research Line references into verified WordPress relations. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Blueprint_Relations {
    private Eduardo_Research_Manager_Blueprint $blueprints;
    private Eduardo_Research_Manager_Line_Resource $lines;
    private Eduardo_Research_Manager_Executor $executor;

    private const RESOURCES = array(
        'outputs'=>array('post_type'=>'research_output','service'=>'outputs'),
        'projects'=>array('post_type'=>'research_project','service'=>'projects'),
        'software'=>array('post_type'=>'research_software','service'=>'software'),
        'datasets'=>array('post_type'=>'research_dataset','service'=>'datasets'),
    );

    public function __construct(
        ?Eduardo_Research_Manager_Blueprint $blueprints = null,
        ?Eduardo_Research_Manager_Line_Resource $lines = null,
        ?Eduardo_Research_Manager_Executor $executor = null
    ) {
        $this->blueprints = $blueprints ?: Eduardo_Research_Manager::blueprint();
        $this->lines = $lines ?: Eduardo_Research_Manager::lines();
        $this->executor = $executor ?: Eduardo_Research_Manager::executor();
    }

    public function preview(array $blueprint): array|WP_Error {
        $operations = $this->resolve_operations($blueprint);
        if (is_wp_error($operations)) { return $operations; }
        $rows = array(); $apply_allowed = true;
        foreach ($operations as $operation) {
            $service = $this->service((string) $operation['service']);
            $current = $service->inspect((int) $operation['post_id']);
            if (is_wp_error($current)) { return $current; }
            if (maybe_serialize($current['line_ids'] ?? array()) === maybe_serialize($operation['line_ids'])) {
                $verified = $service->verify((int) $operation['post_id'], array('line_ids'=>$operation['line_ids']));
                if (is_wp_error($verified) || empty($verified['verified'])) {
                    return is_wp_error($verified) ? $verified : new WP_Error('research_manager_blueprint_relation_verification_failed', 'Existing Research Line relations did not verify as already matching.');
                }
                $rows[] = $this->row($operation, 'already-matching', true, '', array(), $verified);
                continue;
            }
            $plan = $service->build_update_plan(
                (int) $operation['post_id'],
                array('line_ids'=>$operation['line_ids']),
                sprintf('Greenfield blueprint: relate %s %s to Research Lines', $operation['resource'], $operation['slug']),
                $operation['context']
            );
            if (is_wp_error($plan)) { return $plan; }
            $preview = $this->executor->preview($plan);
            if (is_wp_error($preview)) { return $preview; }
            $allowed = ! empty($preview['apply_allowed']);
            $apply_allowed = $apply_allowed && $allowed;
            $rows[] = $this->row($operation, 'change', $allowed, (string) $plan['id'], $preview, array());
        }
        return array(
            'mode'=>Eduardo_Research_Manager_Mode::current(),'apply_allowed'=>$apply_allowed,'operations'=>$rows,'operation_count'=>count($rows),
            'requires_legacy_discovery'=>false,'requires_legacy_mapping'=>false,
        );
    }

    public function apply(array $blueprint): array|WP_Error {
        if (! current_user_can('manage_options')) { return new WP_Error('research_manager_forbidden', 'You are not allowed to apply blueprint Research Line relations.'); }
        $operations = $this->resolve_operations($blueprint);
        if (is_wp_error($operations)) { return $operations; }
        $applied = array();
        foreach ($operations as $operation) {
            $service = $this->service((string) $operation['service']);
            $current = $service->inspect((int) $operation['post_id']);
            if (is_wp_error($current)) { return $this->abort($current, $applied); }
            if (maybe_serialize($current['line_ids'] ?? array()) === maybe_serialize($operation['line_ids'])) {
                $verification = $service->verify((int) $operation['post_id'], array('line_ids'=>$operation['line_ids']));
                if (is_wp_error($verification) || empty($verification['verified'])) {
                    $error = is_wp_error($verification) ? $verification : new WP_Error('research_manager_blueprint_relation_verification_failed', 'Existing Research Line relations did not verify as already matching.');
                    return $this->abort($error, $applied);
                }
                $applied[] = $this->applied_row($operation, 'already-matching', '', $verification);
                continue;
            }
            $plan = $service->build_update_plan(
                (int) $operation['post_id'],
                array('line_ids'=>$operation['line_ids']),
                sprintf('Greenfield blueprint: relate %s %s to Research Lines', $operation['resource'], $operation['slug']),
                $operation['context']
            );
            if (is_wp_error($plan)) { return $this->abort($plan, $applied); }
            $result = $this->executor->apply($plan);
            if (is_wp_error($result)) { return $this->abort($result, $applied); }
            $verification = $service->verify((int) $operation['post_id'], array('line_ids'=>$operation['line_ids']));
            if (is_wp_error($verification) || empty($verification['verified'])) {
                if (! empty($result['snapshot_id'])) { $this->executor->rollback((string) $result['snapshot_id']); }
                $error = is_wp_error($verification) ? $verification : new WP_Error('research_manager_blueprint_relation_verification_failed', 'Applied Research Line relations failed verification.');
                return $this->abort($error, $applied);
            }
            $applied[] = $this->applied_row($operation, (string) ($result['status'] ?? 'applied'), (string) ($result['snapshot_id'] ?? ''), $verification);
        }
        return array(
            'status'=>'applied','mode'=>Eduardo_Research_Manager_Mode::current(),'operations'=>$applied,'operation_count'=>count($applied),
            'snapshot_ids'=>array_values(array_filter(array_map(static fn(array $row): string => (string) ($row['snapshot_id'] ?? ''), $applied))),
            'verified'=>true,
        );
    }

    public function rollback(array $snapshot_ids): array|WP_Error {
        if (! current_user_can('manage_options')) { return new WP_Error('research_manager_forbidden', 'You are not allowed to rollback blueprint Research Line relations.'); }
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

    private function resolve_operations(array $blueprint): array|WP_Error {
        $normalized = $this->blueprints->validate($blueprint);
        if (is_wp_error($normalized)) { return $normalized; }
        $line_index = $this->line_index($normalized['lines']);
        if (is_wp_error($line_index)) { return $line_index; }
        $operations = array();
        foreach (self::RESOURCES as $resource => $spec) {
            foreach ($normalized[$resource] as $index => $record) {
                if (! is_array($record)) { return $this->record_error($resource, $index, 'Blueprint resource records must be object-like arrays.'); }
                if (! array_key_exists('line_refs', $record)) { continue; }
                if (! is_array($record['line_refs'])) { return $this->record_error($resource, $index, 'line_refs must be an array of stable Research Line references.'); }
                $language = sanitize_key((string) ($record['language'] ?? 'en'));
                if (! in_array($language, $normalized['languages'], true)) { return $this->record_error($resource, $index, 'Resource language is outside the blueprint language set.'); }
                $slug = sanitize_title((string) ($record['slug'] ?? $record['title'] ?? ''));
                if ('' === $slug) { return $this->record_error($resource, $index, 'Related resources require a resolvable slug.'); }
                $post_id = $this->find_resource((string) $spec['post_type'], $slug, $language);
                if ($post_id <= 0) { return $this->record_error($resource, $index, 'Research Line relation resolution requires bootstrap creation to complete first.'); }
                $line_ids = array();
                foreach ($record['line_refs'] as $ref) {
                    if (! is_scalar($ref)) { return $this->record_error($resource, $index, 'Each line_refs entry must be a stable text reference.'); }
                    $line_id = $this->resolve_line((string) $ref, $language, $line_index);
                    if ($line_id <= 0) { return $this->record_error($resource, $index, sprintf('Research Line reference "%s" could not be resolved for %s.', sanitize_text_field((string) $ref), strtoupper($language))); }
                    $line_ids[] = $line_id;
                }
                $line_ids = array_values(array_unique($line_ids));
                $context = $this->evidence_context($record);
                if (is_wp_error($context)) { return $this->record_error($resource, $index, $context->get_error_message()); }
                $operations[] = array(
                    'resource'=>$resource,'service'=>$spec['service'],'index'=>$index,'post_id'=>$post_id,'slug'=>$slug,'language'=>$language,
                    'line_refs'=>array_values(array_map('strval', $record['line_refs'])),'line_ids'=>$line_ids,'context'=>$context,
                );
            }
        }
        return $operations;
    }

    private function line_index(array $records): array|WP_Error {
        $index = array();
        foreach ($records as $position => $record) {
            if (! is_array($record)) { return new WP_Error('research_manager_blueprint_relation_line_invalid', 'Research Line blueprint records must be object-like arrays.', array('index'=>$position)); }
            $language = sanitize_key((string) ($record['language'] ?? ''));
            if (! in_array($language, array('en','es'), true)) { continue; }
            $slug = sanitize_title((string) ($record['slug'] ?? $record['title'] ?? ''));
            if ('' === $slug) { continue; }
            $pair_key = sanitize_key((string) ($record['translation_key'] ?? ''));
            if ('' === $pair_key) {
                $order = trim((string) ($record['order'] ?? ''));
                if ('' !== $order) { $pair_key = 'line-order-' . absint($order); }
            }
            $index['slug'][$language][$slug] = $slug;
            if ('' !== $pair_key) { $index['pair'][$pair_key][$language] = $slug; }
        }
        return $index;
    }

    private function resolve_line(string $ref, string $language, array $index): int {
        $slug = sanitize_title($ref);
        if ('' !== $slug && isset($index['slug'][$language][$slug])) {
            $id = $this->lines->find_by_slug($slug, $language);
            return $this->verified_line($id, $language) ? $id : 0;
        }
        $pair_key = sanitize_key($ref);
        $paired_slug = (string) ($index['pair'][$pair_key][$language] ?? '');
        if ('' === $paired_slug) { return 0; }
        $id = $this->lines->find_by_slug($paired_slug, $language);
        return $this->verified_line($id, $language) ? $id : 0;
    }

    private function verified_line(int $post_id, string $language): bool {
        return $post_id > 0 && function_exists('eduardo_research_line_is_verified_public') && eduardo_research_line_is_verified_public($post_id, $language);
    }

    private function find_resource(string $post_type, string $slug, string $language): int {
        $posts = get_posts(array('post_type'=>$post_type,'post_status'=>'any','name'=>$slug,'posts_per_page'=>5,'orderby'=>'ID','order'=>'ASC','suppress_filters'=>true));
        foreach ($posts as $post) {
            if (! $post instanceof WP_Post) { continue; }
            $stored = function_exists('eduardo_research_post_language') ? eduardo_research_post_language((int) $post->ID) : sanitize_key((string) get_post_meta((int) $post->ID, '_research_language', true));
            if ($language === $stored) { return (int) $post->ID; }
        }
        return 0;
    }

    private function service(string $name): object {
        return match ($name) {
            'outputs'=>Eduardo_Research_Manager::outputs(),
            'projects'=>Eduardo_Research_Manager::projects(),
            'software'=>Eduardo_Research_Manager::software(),
            'datasets'=>Eduardo_Research_Manager::datasets(),
            default=>throw new LogicException('Unsupported Research Manager relation service.'),
        };
    }

    private function evidence_context(array $record): array|WP_Error {
        if (! array_key_exists('evidence', $record)) { return array(); }
        if (! is_array($record['evidence'])) { return new WP_Error('research_manager_blueprint_evidence_invalid', 'Blueprint evidence must be an object-like array.'); }
        $confirmed = ! empty($record['evidence']['confirmed']);
        $reference = sanitize_text_field((string) ($record['evidence']['reference'] ?? ''));
        if ($confirmed && '' === $reference) { return new WP_Error('research_manager_blueprint_evidence_reference_required', 'Confirmed blueprint evidence requires a source or verification reference.'); }
        return array('evidence_confirmed'=>$confirmed,'evidence_reference'=>$reference);
    }

    private function row(array $operation, string $status, bool $allowed, string $plan_id, array $preview, array $verification): array {
        return array('resource'=>$operation['resource'],'index'=>$operation['index'],'post_id'=>$operation['post_id'],'slug'=>$operation['slug'],'language'=>$operation['language'],'line_refs'=>$operation['line_refs'],'line_ids'=>$operation['line_ids'],'status'=>$status,'apply_allowed'=>$allowed,'plan_id'=>$plan_id,'preview'=>$preview,'verification'=>$verification);
    }

    private function applied_row(array $operation, string $status, string $snapshot_id, array $verification): array {
        return array('resource'=>$operation['resource'],'index'=>$operation['index'],'post_id'=>$operation['post_id'],'slug'=>$operation['slug'],'language'=>$operation['language'],'line_refs'=>$operation['line_refs'],'line_ids'=>$operation['line_ids'],'status'=>$status,'snapshot_id'=>$snapshot_id,'verification'=>$verification);
    }

    private function abort(WP_Error $error, array $applied): WP_Error {
        $rollback = array();
        foreach (array_reverse($applied) as $entry) {
            $snapshot_id = (string) ($entry['snapshot_id'] ?? '');
            if ('' === $snapshot_id) { continue; }
            $result = $this->executor->rollback($snapshot_id);
            $rollback[] = is_wp_error($result) ? array('snapshot_id'=>$snapshot_id,'rolled_back'=>false,'error'=>$result->get_error_message()) : array('snapshot_id'=>$snapshot_id,'rolled_back'=>true);
        }
        return new WP_Error('research_manager_blueprint_relations_failed', $error->get_error_message(), array('source_code'=>$error->get_error_code(),'rollback'=>$rollback));
    }

    private function record_error(string $resource, int $index, string $message): WP_Error {
        return new WP_Error('research_manager_blueprint_relation_invalid', $message, array('resource'=>$resource,'index'=>$index));
    }
}
