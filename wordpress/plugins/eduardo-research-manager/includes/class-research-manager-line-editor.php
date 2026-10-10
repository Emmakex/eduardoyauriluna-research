<?php
/** Interactive Preview → Apply → Verify → Rollback service for first-class Research Lines. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Line_Editor {
    private Eduardo_Research_Manager_Line_Resource $lines;
    private Eduardo_Research_Manager_Executor $executor;

    public function __construct(
        ?Eduardo_Research_Manager_Line_Resource $lines = null,
        ?Eduardo_Research_Manager_Executor $executor = null
    ) {
        $this->lines = $lines ?: Eduardo_Research_Manager::lines();
        $this->executor = $executor ?: Eduardo_Research_Manager::executor();
    }

    public function list(string $language = ''): array|WP_Error {
        $language = sanitize_key($language);
        $supported = Eduardo_Research_Manager::contract()->languages();
        if ('' !== $language && ! in_array($language, $supported, true)) {
            return new WP_Error('research_manager_unknown_language', 'Research Line language is outside the active Research preset.');
        }

        $args = array(
            'post_type'=>'research_line',
            'post_status'=>'any',
            'posts_per_page'=>100,
            'orderby'=>array('menu_order'=>'ASC','modified'=>'DESC'),
            'order'=>'ASC',
            'suppress_filters'=>true,
        );
        if ('' !== $language) {
            $args['meta_query'] = array(array('key'=>'_research_language','value'=>$language,'compare'=>'='));
        }

        $rows = array();
        foreach (get_posts($args) as $post) {
            if (! $post instanceof WP_Post) { continue; }
            $record = $this->lines->inspect((int) $post->ID);
            if (is_wp_error($record)) { return $record; }
            $rows[] = $record;
        }
        return $rows;
    }

    public function inspect(int $post_id): array|WP_Error {
        return $this->lines->inspect($post_id);
    }

    public function preview_create(array $data, bool $evidence_confirmed = false, string $evidence_reference = ''): array|WP_Error {
        $context = $this->evidence_context($evidence_confirmed, $evidence_reference);
        $plan = $this->lines->build_creation_plan($data, 'Interactive Research Line editor: create Research Line', $context);
        if (is_wp_error($plan)) { return $plan; }
        $preview = $this->executor->preview($plan);
        if (is_wp_error($preview)) { return $preview; }
        $action = is_array($plan['actions'][0] ?? null) ? $plan['actions'][0] : array();
        if (! $action) {
            return new WP_Error('research_manager_line_editor_plan_invalid', 'Research Line creation plan contains no normalized action.');
        }

        return array(
            'mode'=>'create',
            'status'=>'create',
            'apply_allowed'=>! empty($preview['apply_allowed']),
            'apply_blocker'=>(string) ($preview['apply_blocker'] ?? ''),
            'risk'=>(string) ($preview['risk'] ?? $plan['risk'] ?? ''),
            'plan'=>$plan,
            'plan_id'=>(string) ($plan['id'] ?? ''),
            'preview'=>$preview,
            'post_id'=>0,
            'expected'=>$this->expected_from_creation_action($action),
            'creation_token'=>(string) ($action['creation_token'] ?? ''),
            'baseline_checksum'=>'',
        );
    }

    public function preview_update(
        int $post_id,
        array $changes,
        bool $evidence_confirmed = false,
        string $evidence_reference = ''
    ): array|WP_Error {
        $current = $this->lines->inspect($post_id);
        if (is_wp_error($current)) { return $current; }
        $baseline_checksum = $this->state_checksum($current);
        $context = $this->evidence_context($evidence_confirmed, $evidence_reference);

        $plan = $this->lines->build_update_plan(
            $post_id,
            $changes,
            sprintf('Interactive Research Line editor: update Line #%d', $post_id),
            $context
        );
        if (is_wp_error($plan)) {
            if ('research_manager_no_change' !== $plan->get_error_code()) { return $plan; }
            $verification = $this->lines->verify($post_id, $changes);
            if (is_wp_error($verification)) { return $verification; }
            if (empty($verification['verified'])) {
                return new WP_Error('research_manager_line_editor_verification_failed', 'The current Research Line state did not verify as already matching.');
            }
            return array(
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
            return new WP_Error('research_manager_forbidden', 'You are not allowed to apply interactive Research Line changes.');
        }

        $mode = sanitize_key((string) ($prepared['mode'] ?? ''));
        $status = sanitize_key((string) ($prepared['status'] ?? ''));
        $expected = is_array($prepared['expected'] ?? null) ? $prepared['expected'] : array();
        if (! in_array($mode, array('create','update'), true) || ! $expected) {
            return new WP_Error('research_manager_line_editor_preview_invalid', 'The prepared Research Line preview is incomplete.');
        }

        if ('already-matching' !== $status
            && (empty($prepared['apply_allowed']) || ! is_array($prepared['plan'] ?? null) || ! $prepared['plan'])) {
            return new WP_Error(
                'research_manager_line_editor_apply_blocked',
                '' !== trim((string) ($prepared['apply_blocker'] ?? ''))
                    ? (string) $prepared['apply_blocker']
                    : 'The prepared Research Line preview is not allowed to apply.'
            );
        }

        $post_id = absint($prepared['post_id'] ?? 0);
        if ('update' === $mode) {
            $baseline_checksum = sanitize_text_field((string) ($prepared['baseline_checksum'] ?? ''));
            if ($post_id <= 0 || '' === $baseline_checksum) {
                return new WP_Error('research_manager_line_editor_preview_invalid', 'Research Line update Preview is missing its resource baseline.');
            }
            $current = $this->lines->inspect($post_id);
            if (is_wp_error($current)) { return $current; }
            if (! hash_equals($baseline_checksum, $this->state_checksum($current))) {
                return new WP_Error(
                    'research_manager_line_editor_stale_preview',
                    'The Research Line changed after Preview. Refresh the editor and prepare a new Preview before Apply.'
                );
            }
        }

        if ('already-matching' === $status) {
            $verification = $this->lines->verify($post_id, $expected);
            if (is_wp_error($verification)) { return $verification; }
            if (empty($verification['verified'])) {
                return new WP_Error('research_manager_line_editor_verification_failed', 'The Research Line no longer matches the prepared Preview.');
            }
            return array('status'=>'already-matching','mode'=>$mode,'post_id'=>$post_id,'snapshot_id'=>'','verification'=>$verification,'verified'=>true);
        }

        $plan = $prepared['plan'];
        $result = $this->executor->apply($plan);
        if (is_wp_error($result)) { return $result; }

        if ('create' === $mode) {
            $token = sanitize_text_field((string) ($prepared['creation_token'] ?? ''));
            $post_id = $this->lines->find_created_by_token($token);
            $semantic = $post_id > 0
                ? $this->lines->verify($post_id, $expected, $token)
                : new WP_Error('research_manager_line_editor_created_missing', 'Created Research Line could not be resolved by provenance token.');
        } else {
            $semantic = $this->lines->verify($post_id, $expected);
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
                    : new WP_Error('research_manager_line_editor_verification_failed', 'Interactive Research Line changes failed post-Apply verification.'));
        }

        return array(
            'status'=>(string) ($result['status'] ?? 'applied'),
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
            return new WP_Error('research_manager_forbidden', 'You are not allowed to rollback interactive Research Line changes.');
        }
        $snapshot_id = sanitize_text_field($snapshot_id);
        if ('' === $snapshot_id) {
            return new WP_Error('research_manager_line_editor_snapshot_missing', 'A Research Line editor snapshot ID is required for rollback.');
        }
        return $this->executor->rollback($snapshot_id);
    }

    private function evidence_context(bool $confirmed, string $reference): array {
        return array(
            'evidence_confirmed'=>$confirmed,
            'evidence_reference'=>sanitize_text_field($reference),
        );
    }

    private function expected_from_creation_action(array $action): array {
        $expected = array();
        foreach (array(
            'status','slug','title','excerpt','content','language','evidence_status','research_status',
            'central_question','order','topics','methods'
        ) as $field) {
            $expected[$field] = $action[$field] ?? (in_array($field, array('topics','methods'), true) ? array() : '');
        }
        return $expected;
    }

    private function state_checksum(array $record): string {
        $state = array();
        foreach (array(
            'post_id','status','slug','title','excerpt','content','language','evidence_status','research_status',
            'central_question','order','topics','methods'
        ) as $field) {
            $state[$field] = $record[$field] ?? null;
        }
        return hash('sha256', (string) wp_json_encode($state));
    }
}
