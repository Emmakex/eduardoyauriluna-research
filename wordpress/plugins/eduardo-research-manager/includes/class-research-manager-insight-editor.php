<?php
/** Interactive Preview → Apply → Verify → Rollback service for Research Insights. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Insight_Editor {
    private Eduardo_Research_Manager_Insight_Resource $insights;
    private Eduardo_Research_Manager_Executor $executor;
    private Eduardo_Research_Manager_Snapshots $snapshots;

    public function __construct(
        ?Eduardo_Research_Manager_Insight_Resource $insights = null,
        ?Eduardo_Research_Manager_Executor $executor = null,
        ?Eduardo_Research_Manager_Snapshots $snapshots = null
    ) {
        $this->insights = $insights ?: Eduardo_Research_Manager::insights();
        $this->executor = $executor ?: Eduardo_Research_Manager::executor();
        $this->snapshots = $snapshots ?: new Eduardo_Research_Manager_Snapshots();
    }

    public function list(string $language = ''): array|WP_Error {
        $language = sanitize_key($language);
        if ('' !== $language && ! in_array($language, $this->insights->languages(), true)) {
            return new WP_Error('research_manager_unknown_language', 'Insight language is outside the active Research preset.');
        }

        $args = array(
            'post_type'=>'post',
            'post_status'=>'any',
            'posts_per_page'=>100,
            'orderby'=>'modified',
            'order'=>'DESC',
            'meta_key'=>'_research_insight_type',
            'meta_compare'=>'EXISTS',
            'suppress_filters'=>true,
        );
        if ('' !== $language) {
            $args['meta_query'] = array(array('key'=>'_research_language','value'=>$language,'compare'=>'='));
        }

        $rows = array();
        foreach (get_posts($args) as $post) {
            if (! $post instanceof WP_Post) { continue; }
            $record = $this->insights->inspect((int) $post->ID);
            if (is_wp_error($record)) { return $record; }
            $rows[] = $record;
        }
        return $rows;
    }

    public function inspect(int $post_id): array|WP_Error {
        return $this->insights->inspect($post_id);
    }

    public function preview_create(array $data): array|WP_Error {
        $plan = $this->insights->build_creation_plan($data, 'Interactive Insight editor: create Research Insight');
        if (is_wp_error($plan)) { return $plan; }
        $preview = $this->executor->preview($plan);
        if (is_wp_error($preview)) { return $preview; }
        $action = is_array($plan['actions'][0] ?? null) ? $plan['actions'][0] : array();
        if (! $action) { return new WP_Error('research_manager_insight_editor_plan_invalid', 'Insight creation plan contains no normalized action.'); }

        return array(
            'mode'=>'create',
            'status'=>'create',
            'apply_allowed'=>! empty($preview['apply_allowed']),
            'plan'=>$plan,
            'plan_id'=>(string) ($plan['id'] ?? ''),
            'preview'=>$preview,
            'post_id'=>0,
            'expected'=>$this->expected_from_creation_action($action),
            'creation_token'=>(string) ($action['creation_token'] ?? ''),
            'baseline_checksum'=>'',
        );
    }

    public function preview_update(int $post_id, array $changes): array|WP_Error {
        $current = $this->insights->inspect($post_id);
        if (is_wp_error($current)) { return $current; }
        $baseline_checksum = $this->state_checksum($current);

        if (array_key_exists('status', $changes)) {
            if (1 !== count($changes)) {
                return new WP_Error(
                    'research_manager_insight_status_requires_separate_operation',
                    'Insight publication state must be changed in its own Preview so publish/unpublish remains independently reviewable and reversible.'
                );
            }
            return $this->preview_status_change($post_id, $current, $changes['status'], $baseline_checksum);
        }

        $plan = $this->insights->build_update_plan($post_id, $changes, sprintf('Interactive Insight editor: update Insight #%d', $post_id));
        if (is_wp_error($plan)) {
            if ('research_manager_no_change' !== $plan->get_error_code()) { return $plan; }
            $verification = $this->insights->verify($post_id, $changes);
            if (is_wp_error($verification)) { return $verification; }
            if (empty($verification['verified'])) {
                return new WP_Error('research_manager_insight_editor_verification_failed', 'The current Insight state did not verify as already matching.');
            }
            return array(
                'mode'=>'update',
                'status'=>'already-matching',
                'apply_allowed'=>true,
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
            return new WP_Error('research_manager_forbidden', 'You are not allowed to apply interactive Insight changes.');
        }

        $mode = sanitize_key((string) ($prepared['mode'] ?? ''));
        $status = sanitize_key((string) ($prepared['status'] ?? ''));
        $expected = is_array($prepared['expected'] ?? null) ? $prepared['expected'] : array();
        if (! in_array($mode, array('create','update','status'), true) || ! $expected) {
            return new WP_Error('research_manager_insight_editor_preview_invalid', 'The prepared Insight preview is incomplete.');
        }

        if ('already-matching' !== $status && empty($prepared['apply_allowed'])) {
            return new WP_Error('research_manager_insight_editor_apply_blocked', 'The prepared Insight preview is not allowed to apply.');
        }
        if ('status' !== $mode && 'already-matching' !== $status
            && (! is_array($prepared['plan'] ?? null) || ! $prepared['plan'])) {
            return new WP_Error('research_manager_insight_editor_apply_blocked', 'The prepared Insight preview is missing its mutation plan.');
        }

        $post_id = absint($prepared['post_id'] ?? 0);
        if (in_array($mode, array('update','status'), true)) {
            $baseline_checksum = sanitize_text_field((string) ($prepared['baseline_checksum'] ?? ''));
            if ($post_id <= 0 || '' === $baseline_checksum) {
                return new WP_Error('research_manager_insight_editor_preview_invalid', 'Insight update Preview is missing its resource baseline.');
            }
            $current = $this->insights->inspect($post_id);
            if (is_wp_error($current)) { return $current; }
            if (! hash_equals($baseline_checksum, $this->state_checksum($current))) {
                return new WP_Error(
                    'research_manager_insight_editor_stale_preview',
                    'The Insight changed after Preview. Refresh the editor and prepare a new Preview before Apply.'
                );
            }
        }

        if ('already-matching' === $status) {
            $verification = $this->insights->verify($post_id, $expected);
            if (is_wp_error($verification)) { return $verification; }
            if (empty($verification['verified'])) {
                return new WP_Error('research_manager_insight_editor_verification_failed', 'The Insight no longer matches the prepared Preview.');
            }
            return array('status'=>'already-matching','mode'=>$mode,'post_id'=>$post_id,'snapshot_id'=>'','verification'=>$verification,'verified'=>true);
        }

        if ('status' === $mode) {
            return $this->apply_status_preview($prepared);
        }

        $plan = $prepared['plan'];
        $result = $this->executor->apply($plan);
        if (is_wp_error($result)) { return $result; }

        if ('create' === $mode) {
            $token = sanitize_text_field((string) ($prepared['creation_token'] ?? ''));
            $post_id = $this->insights->find_created_by_token($token);
            $semantic = $post_id > 0 ? $this->insights->verify($post_id, $expected, $token) : new WP_Error('research_manager_insight_editor_created_missing', 'Created Insight could not be resolved by provenance token.');
        } else {
            $semantic = $this->insights->verify($post_id, $expected);
        }
        $generic = $this->executor->verify($plan);
        $verified = ! is_wp_error($generic) && ! empty($generic['verified']) && ! is_wp_error($semantic) && ! empty($semantic['verified']);

        if (! $verified) {
            $snapshot_id = (string) ($result['snapshot_id'] ?? '');
            if ('' !== $snapshot_id) { $this->executor->rollback($snapshot_id); }
            return is_wp_error($generic)
                ? $generic
                : (is_wp_error($semantic)
                    ? $semantic
                    : new WP_Error('research_manager_insight_editor_verification_failed', 'Interactive Insight changes failed post-Apply verification.'));
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
            return new WP_Error('research_manager_forbidden', 'You are not allowed to rollback interactive Insight changes.');
        }
        $snapshot_id = sanitize_text_field($snapshot_id);
        if ('' === $snapshot_id) {
            return new WP_Error('research_manager_insight_editor_snapshot_missing', 'An Insight editor snapshot ID is required for rollback.');
        }
        return $this->executor->rollback($snapshot_id);
    }

    private function preview_status_change(int $post_id, array $current, mixed $requested_status, string $baseline_checksum): array|WP_Error {
        if (! is_scalar($requested_status)) {
            return new WP_Error('research_manager_invalid_insight_status', 'Insight publication state must be draft or publish.');
        }
        $target = sanitize_key((string) $requested_status);
        if (! in_array($target, array('draft','publish'), true)) {
            return new WP_Error('research_manager_invalid_insight_status', 'Insight publication state may only transition between draft and publish.');
        }
        $from = sanitize_key((string) ($current['status'] ?? ''));
        $expected = array('status'=>$target);
        if ($target === $from) {
            $verification = $this->insights->verify($post_id, $expected);
            if (is_wp_error($verification)) { return $verification; }
            return array(
                'mode'=>'status','status'=>'already-matching','apply_allowed'=>true,'plan'=>array(),'plan_id'=>'',
                'preview'=>array('risk'=>'editorial-review','actions'=>array()),'post_id'=>$post_id,'expected'=>$expected,
                'creation_token'=>'','baseline_checksum'=>$baseline_checksum,'from_status'=>$from,'to_status'=>$target,
                'verification'=>$verification,
            );
        }

        $material = array('post_id'=>$post_id,'from_status'=>$from,'to_status'=>$target,'baseline_checksum'=>$baseline_checksum);
        $checksum = hash('sha256', (string) wp_json_encode($material));
        $plan_id = 'erm-insight-status-' . substr($checksum, 0, 16);
        return array(
            'mode'=>'status',
            'status'=>'change',
            'apply_allowed'=>true,
            'plan'=>array(),
            'plan_id'=>$plan_id,
            'preview'=>array(
                'plan_id'=>$plan_id,
                'intent'=>sprintf('Change Research Insight #%d publication state from %s to %s', $post_id, $from, $target),
                'risk'=>'editorial-review',
                'apply_allowed'=>true,
                'apply_blocker'=>'',
                'actions'=>array(array(
                    'action'=>array('type'=>'insight_status','post_id'=>$post_id,'status'=>$target),
                    'before'=>array('exists'=>true,'value'=>$from),
                    'after'=>$target,
                    'changed'=>true,
                    'risk'=>'editorial-review',
                )),
            ),
            'post_id'=>$post_id,
            'expected'=>$expected,
            'creation_token'=>'',
            'baseline_checksum'=>$baseline_checksum,
            'from_status'=>$from,
            'to_status'=>$target,
            'status_checksum'=>$checksum,
        );
    }

    private function apply_status_preview(array $prepared): array|WP_Error {
        $post_id = absint($prepared['post_id'] ?? 0);
        $from = sanitize_key((string) ($prepared['from_status'] ?? ''));
        $to = sanitize_key((string) ($prepared['to_status'] ?? ''));
        $checksum = sanitize_text_field((string) ($prepared['status_checksum'] ?? ''));
        if ($post_id <= 0 || ! in_array($from, array('draft','publish'), true) || ! in_array($to, array('draft','publish'), true) || $from === $to || '' === $checksum) {
            return new WP_Error('research_manager_insight_editor_preview_invalid', 'Prepared Insight publication-state Preview is invalid.');
        }

        $snapshot_plan = array(
            'id'=>(string) ($prepared['plan_id'] ?? ('erm-insight-status-' . substr($checksum, 0, 16))),
            'checksum'=>$checksum,
            'intent'=>sprintf('Change Research Insight #%d publication state from %s to %s', $post_id, $from, $to),
        );
        $before = array(array(
            'action'=>array('type'=>'post_field','post_id'=>$post_id,'field'=>'post_status','value'=>$to),
            'state'=>array('exists'=>true,'value'=>$from),
        ));
        $snapshot_id = $this->snapshots->create($snapshot_plan, $before);

        $written = wp_update_post(array('ID'=>$post_id,'post_status'=>$to), true);
        if (is_wp_error($written)) {
            $this->executor->rollback($snapshot_id);
            return $written;
        }
        clean_post_cache($post_id);

        $verification = $this->insights->verify($post_id, array('status'=>$to));
        if (is_wp_error($verification) || empty($verification['verified'])) {
            $this->executor->rollback($snapshot_id);
            return is_wp_error($verification)
                ? $verification
                : new WP_Error('research_manager_insight_editor_verification_failed', 'Insight publication-state change failed verification and was rolled back.');
        }

        return array(
            'status'=>'applied',
            'mode'=>'status',
            'post_id'=>$post_id,
            'plan_id'=>(string) $snapshot_plan['id'],
            'snapshot_id'=>$snapshot_id,
            'verification'=>$verification,
            'verified'=>true,
        );
    }

    private function expected_from_creation_action(array $action): array {
        $expected = array();
        foreach (array('status','slug','title','excerpt','content','language','insight_type') as $field) {
            $expected[$field] = $action[$field] ?? '';
        }
        return $expected;
    }

    private function state_checksum(array $record): string {
        $state = array();
        foreach (array('post_id','status','slug','title','excerpt','content','language','insight_type','line_ids') as $field) {
            $state[$field] = $record[$field] ?? null;
        }
        return hash('sha256', (string) wp_json_encode($state));
    }
}
