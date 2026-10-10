<?php
/** Interactive Preview → Apply → Verify → Rollback service for Research Outputs, Projects, Software and Datasets. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Object_Editor {
    private Eduardo_Research_Manager_Executor $executor;

    public function __construct(?Eduardo_Research_Manager_Executor $executor = null) {
        $this->executor = $executor ?: Eduardo_Research_Manager::executor();
    }

    public function supported_kinds(): array {
        return array(
            'output'=>array(
                'post_type'=>'research_output',
                'label'=>'Research Output',
                'fields'=>array('status','slug','title','excerpt','content','language','output_type','review_status','publication_date','venue','doi','authors','line_ids'),
            ),
            'project'=>array(
                'post_type'=>'research_project',
                'label'=>'Research Project',
                'fields'=>array('status','slug','title','excerpt','content','language','project_status','question','role','start_date','end_date','partner','funding','project_url','methods','line_ids'),
            ),
            'software'=>array(
                'post_type'=>'research_software',
                'label'=>'Research Software',
                'fields'=>array('status','slug','title','excerpt','content','language','software_status','version','release_date','repository_url','archive_url','license','documentation_url','doi','programming_languages','line_ids'),
            ),
            'dataset'=>array(
                'post_type'=>'research_dataset',
                'label'=>'Research Dataset',
                'fields'=>array('status','slug','title','excerpt','content','language','version','publication_date','repository','doi','license','access_level','methodology','provenance','size','documentation_url','ethics_notes','formats','line_ids'),
            ),
        );
    }

    public function list(string $kind, string $language = ''): array|WP_Error {
        $spec = $this->spec($kind);
        if (is_wp_error($spec)) { return $spec; }
        $language = sanitize_key($language);
        $supported = Eduardo_Research_Manager::contract()->languages();
        if ('' !== $language && ! in_array($language, $supported, true)) {
            return new WP_Error('research_manager_unknown_language', 'Research object language is outside the active Research preset.');
        }

        $args = array(
            'post_type'=>$spec['post_type'],
            'post_status'=>'any',
            'posts_per_page'=>100,
            'orderby'=>'modified',
            'order'=>'DESC',
            'suppress_filters'=>true,
        );
        if ('' !== $language) {
            $args['meta_query'] = array(array('key'=>'_research_language','value'=>$language,'compare'=>'='));
        }

        $resource = $this->resource($kind);
        if (is_wp_error($resource)) { return $resource; }
        $rows = array();
        foreach (get_posts($args) as $post) {
            if (! $post instanceof WP_Post) { continue; }
            $record = $resource->inspect((int) $post->ID);
            if (is_wp_error($record)) { return $record; }
            $rows[] = $record;
        }
        return $rows;
    }

    public function inspect(string $kind, int $post_id): array|WP_Error {
        $resource = $this->resource($kind);
        return is_wp_error($resource) ? $resource : $resource->inspect($post_id);
    }

    public function preview_create(
        string $kind,
        array $data,
        bool $evidence_confirmed = false,
        string $evidence_reference = ''
    ): array|WP_Error {
        $spec = $this->spec($kind);
        if (is_wp_error($spec)) { return $spec; }
        $resource = $this->resource($kind);
        if (is_wp_error($resource)) { return $resource; }
        $context = $this->evidence_context($evidence_confirmed, $evidence_reference);
        $plan = $resource->build_creation_plan(
            $data,
            sprintf('Interactive Research object editor: create %s', (string) $spec['label']),
            $context
        );
        if (is_wp_error($plan)) { return $plan; }
        $preview = $this->executor->preview($plan);
        if (is_wp_error($preview)) { return $preview; }
        $action = is_array($plan['actions'][0] ?? null) ? $plan['actions'][0] : array();
        if (! $action) {
            return new WP_Error('research_manager_object_editor_plan_invalid', 'Research object creation plan contains no normalized action.');
        }

        return array(
            'kind'=>sanitize_key($kind),
            'mode'=>'create',
            'status'=>'create',
            'apply_allowed'=>! empty($preview['apply_allowed']),
            'apply_blocker'=>(string) ($preview['apply_blocker'] ?? ''),
            'risk'=>(string) ($preview['risk'] ?? $plan['risk'] ?? ''),
            'plan'=>$plan,
            'plan_id'=>(string) ($plan['id'] ?? ''),
            'preview'=>$preview,
            'post_id'=>0,
            'expected'=>$this->expected_from_creation_action($kind, $action),
            'creation_token'=>(string) ($action['creation_token'] ?? ''),
            'baseline_checksum'=>'',
        );
    }

    public function preview_update(
        string $kind,
        int $post_id,
        array $changes,
        bool $evidence_confirmed = false,
        string $evidence_reference = ''
    ): array|WP_Error {
        $spec = $this->spec($kind);
        if (is_wp_error($spec)) { return $spec; }
        $resource = $this->resource($kind);
        if (is_wp_error($resource)) { return $resource; }
        $current = $resource->inspect($post_id);
        if (is_wp_error($current)) { return $current; }
        $baseline_checksum = $this->state_checksum($kind, $current);
        $context = $this->evidence_context($evidence_confirmed, $evidence_reference);

        $plan = $resource->build_update_plan(
            $post_id,
            $changes,
            sprintf('Interactive Research object editor: update %s #%d', (string) $spec['label'], $post_id),
            $context
        );
        if (is_wp_error($plan)) {
            if ('research_manager_no_change' !== $plan->get_error_code()) { return $plan; }
            $verification = $resource->verify($post_id, $changes);
            if (is_wp_error($verification)) { return $verification; }
            if (empty($verification['verified'])) {
                return new WP_Error('research_manager_object_editor_verification_failed', 'The current Research object state did not verify as already matching.');
            }
            return array(
                'kind'=>sanitize_key($kind),
                'mode'=>'update',
                'status'=>'already-matching',
                'apply_allowed'=>true,
                'apply_blocker'=>'',
                'risk'=>'standard',
                'plan'=>array(),
                'plan_id'=>'',
                'preview'=>array(),
                'post_id'=>$post_id,
                'expected'=>$changes,
                'creation_token'=>'',
                'baseline_checksum'=>$baseline_checksum,
                'verification'=>$verification,
            );
        }

        $preview = $this->executor->preview($plan);
        if (is_wp_error($preview)) { return $preview; }
        return array(
            'kind'=>sanitize_key($kind),
            'mode'=>'update',
            'status'=>'change',
            'apply_allowed'=>! empty($preview['apply_allowed']),
            'apply_blocker'=>(string) ($preview['apply_blocker'] ?? ''),
            'risk'=>(string) ($preview['risk'] ?? $plan['risk'] ?? ''),
            'plan'=>$plan,
            'plan_id'=>(string) ($plan['id'] ?? ''),
            'preview'=>$preview,
            'post_id'=>$post_id,
            'expected'=>$changes,
            'creation_token'=>'',
            'baseline_checksum'=>$baseline_checksum,
        );
    }

    public function apply_preview(array $prepared): array|WP_Error {
        if (! current_user_can('manage_options')) {
            return new WP_Error('research_manager_forbidden', 'You are not allowed to apply interactive Research object changes.');
        }
        $kind = sanitize_key((string) ($prepared['kind'] ?? ''));
        $spec = $this->spec($kind);
        if (is_wp_error($spec)) { return $spec; }
        $resource = $this->resource($kind);
        if (is_wp_error($resource)) { return $resource; }

        $mode = sanitize_key((string) ($prepared['mode'] ?? ''));
        $status = sanitize_key((string) ($prepared['status'] ?? ''));
        $expected = is_array($prepared['expected'] ?? null) ? $prepared['expected'] : array();
        if (! in_array($mode, array('create','update'), true) || ! $expected) {
            return new WP_Error('research_manager_object_editor_preview_invalid', 'The prepared Research object preview is incomplete.');
        }
        if ('already-matching' !== $status
            && (empty($prepared['apply_allowed']) || ! is_array($prepared['plan'] ?? null) || ! $prepared['plan'])) {
            return new WP_Error(
                'research_manager_object_editor_apply_blocked',
                '' !== trim((string) ($prepared['apply_blocker'] ?? ''))
                    ? (string) $prepared['apply_blocker']
                    : 'The prepared Research object preview is not allowed to apply.'
            );
        }

        $post_id = absint($prepared['post_id'] ?? 0);
        if ('update' === $mode) {
            $baseline_checksum = sanitize_text_field((string) ($prepared['baseline_checksum'] ?? ''));
            if ($post_id <= 0 || '' === $baseline_checksum) {
                return new WP_Error('research_manager_object_editor_preview_invalid', 'Research object update Preview is missing its resource baseline.');
            }
            $current = $resource->inspect($post_id);
            if (is_wp_error($current)) { return $current; }
            if (! hash_equals($baseline_checksum, $this->state_checksum($kind, $current))) {
                return new WP_Error(
                    'research_manager_object_editor_stale_preview',
                    'The Research object changed after Preview. Refresh the editor and prepare a new Preview before Apply.'
                );
            }
        }

        if ('already-matching' === $status) {
            $verification = $resource->verify($post_id, $expected);
            if (is_wp_error($verification)) { return $verification; }
            if (empty($verification['verified'])) {
                return new WP_Error('research_manager_object_editor_verification_failed', 'The Research object no longer matches the prepared Preview.');
            }
            return array('status'=>'already-matching','kind'=>$kind,'mode'=>$mode,'post_id'=>$post_id,'snapshot_id'=>'','verification'=>$verification,'verified'=>true);
        }

        $plan = $prepared['plan'];
        $result = $this->executor->apply($plan);
        if (is_wp_error($result)) { return $result; }

        if ('create' === $mode) {
            $token = sanitize_text_field((string) ($prepared['creation_token'] ?? ''));
            $post_id = $resource->find_created_by_token($token);
            $semantic = $post_id > 0
                ? $resource->verify($post_id, $expected, $token)
                : new WP_Error('research_manager_object_editor_created_missing', 'Created Research object could not be resolved by provenance token.');
        } else {
            $semantic = $resource->verify($post_id, $expected);
        }

        $generic = $this->executor->verify($plan);
        $verified = ! is_wp_error($generic)
            && ! empty($generic['verified'])
            && ! is_wp_error($semantic)
            && ! empty($semantic['verified']);

        if (! $verified) {
            $snapshot_id = (string) ($result['snapshot_id'] ?? '');
            if ('' !== $snapshot_id) { $this->executor->rollback($snapshot_id); }
            return is_wp_error($generic)
                ? $generic
                : (is_wp_error($semantic)
                    ? $semantic
                    : new WP_Error('research_manager_object_editor_verification_failed', 'Interactive Research object changes failed post-Apply verification.'));
        }

        return array(
            'status'=>(string) ($result['status'] ?? 'applied'),
            'kind'=>$kind,
            'mode'=>$mode,
            'post_id'=>$post_id,
            'plan_id'=>(string) ($plan['id'] ?? ''),
            'snapshot_id'=>(string) ($result['snapshot_id'] ?? ''),
            'verification'=>$semantic,
            'verified'=>true,
        );
    }

    public function rollback(string $snapshot_id): array|WP_Error {
        if (! current_user_can('manage_options')) {
            return new WP_Error('research_manager_forbidden', 'You are not allowed to rollback interactive Research object changes.');
        }
        $snapshot_id = sanitize_text_field($snapshot_id);
        if ('' === $snapshot_id) {
            return new WP_Error('research_manager_object_editor_snapshot_missing', 'A Research object editor snapshot ID is required for rollback.');
        }
        return $this->executor->rollback($snapshot_id);
    }

    private function spec(string $kind): array|WP_Error {
        $kind = sanitize_key($kind);
        $supported = $this->supported_kinds();
        if (! isset($supported[$kind])) {
            return new WP_Error('research_manager_unknown_object_kind', 'Unsupported Research object kind.');
        }
        return $supported[$kind];
    }

    private function resource(string $kind): object {
        return match (sanitize_key($kind)) {
            'output' => Eduardo_Research_Manager::outputs(),
            'project' => Eduardo_Research_Manager::projects(),
            'software' => Eduardo_Research_Manager::software(),
            'dataset' => Eduardo_Research_Manager::datasets(),
            default => new WP_Error('research_manager_unknown_object_kind', 'Unsupported Research object kind.'),
        };
    }

    private function evidence_context(bool $confirmed, string $reference): array {
        return array(
            'evidence_confirmed'=>$confirmed,
            'evidence_reference'=>sanitize_text_field($reference),
        );
    }

    private function expected_from_creation_action(string $kind, array $action): array {
        $spec = $this->supported_kinds()[sanitize_key($kind)] ?? array('fields'=>array());
        $expected = array();
        foreach ((array) $spec['fields'] as $field) {
            $expected[$field] = $action[$field] ?? $this->default_value_for_field($field);
        }
        return $expected;
    }

    private function state_checksum(string $kind, array $record): string {
        $spec = $this->supported_kinds()[sanitize_key($kind)] ?? array('fields'=>array());
        $state = array('post_id'=>$record['post_id'] ?? 0);
        foreach ((array) $spec['fields'] as $field) {
            $state[$field] = $record[$field] ?? null;
        }
        foreach ($this->auxiliary_state_fields($kind) as $field) {
            $state[$field] = $record[$field] ?? null;
        }
        return hash('sha256', (string) wp_json_encode($state));
    }

    private function auxiliary_state_fields(string $kind): array {
        return match (sanitize_key($kind)) {
            'output' => array('output_type_verified','review_status_verified','doi_verified'),
            'software', 'dataset' => array('doi_verified'),
            default => array(),
        };
    }

    private function default_value_for_field(string $field): mixed {
        return in_array($field, array('authors','line_ids','methods','programming_languages','formats'), true) ? array() : '';
    }
}
