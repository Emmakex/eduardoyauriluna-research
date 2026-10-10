<?php
/** Interactive Preview → Apply → Verify → Rollback service for EN/ES Research translation relationships. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Translation_Editor {
    private Eduardo_Research_Manager_Translation_Pairing $pairing;
    private Eduardo_Research_Manager_Executor $executor;

    public function __construct(
        ?Eduardo_Research_Manager_Translation_Pairing $pairing = null,
        ?Eduardo_Research_Manager_Executor $executor = null
    ) {
        $this->pairing = $pairing ?: Eduardo_Research_Manager::translations();
        $this->executor = $executor ?: Eduardo_Research_Manager::executor();
    }

    public function supported_types(): array {
        return array(
            'research_line'=>'Research Lines',
            'post'=>'Research Insights',
            'research_output'=>'Research Outputs',
            'research_project'=>'Research Projects',
            'research_software'=>'Research Software',
            'research_dataset'=>'Research Datasets',
        );
    }

    public function list_candidates(string $post_type, string $language): array|WP_Error {
        $post_type = sanitize_key($post_type);
        $language = sanitize_key($language);
        if (! isset($this->supported_types()[$post_type])) {
            return new WP_Error('research_manager_translation_type_unsupported', 'Unsupported Research translation resource type.');
        }
        if (! in_array($language, array('en','es'), true)) {
            return new WP_Error('research_manager_translation_language_invalid', 'Translation candidates must use English or Spanish.');
        }

        $meta_query = array(array('key'=>'_research_language','value'=>$language,'compare'=>'='));
        if ('post' === $post_type) {
            $meta_query[] = array('key'=>'_research_insight_type','compare'=>'EXISTS');
        }
        if ('research_line' === $post_type) {
            $meta_query[] = array('key'=>'_research_evidence_status','value'=>'verified','compare'=>'=');
        }
        $posts = get_posts(array(
            'post_type'=>$post_type,
            'post_status'=>'publish',
            'posts_per_page'=>200,
            'orderby'=>'title',
            'order'=>'ASC',
            'meta_query'=>array_merge(array('relation'=>'AND'), $meta_query),
            'suppress_filters'=>true,
        ));

        $rows = array();
        foreach ($posts as $post) {
            if (! $post instanceof WP_Post) { continue; }
            $state = $this->pairing->inspect((int) $post->ID);
            if (is_wp_error($state)) { return $state; }
            $state['title'] = (string) $post->post_title;
            $state['slug'] = (string) $post->post_name;
            $rows[] = $state;
        }
        return $rows;
    }

    public function inspect(int $post_id): array|WP_Error {
        $state = $this->pairing->inspect($post_id);
        if (is_wp_error($state)) { return $state; }
        $post = get_post($post_id);
        $state['title'] = $post instanceof WP_Post ? (string) $post->post_title : '';
        $state['slug'] = $post instanceof WP_Post ? (string) $post->post_name : '';
        return $state;
    }

    public function preview_pair(
        int $first_id,
        int $second_id,
        bool $evidence_confirmed = false,
        string $evidence_reference = ''
    ): array|WP_Error {
        $first = $this->pairing->inspect($first_id);
        if (is_wp_error($first)) { return $first; }
        $second = $this->pairing->inspect($second_id);
        if (is_wp_error($second)) { return $second; }
        $baseline_ids = array($first_id, $second_id);
        $baseline_checksum = $this->state_checksum($baseline_ids);
        $context = $this->evidence_context($evidence_confirmed, $evidence_reference);
        $plan = $this->pairing->build_pair_plan($first_id, $second_id, 'Interactive Research translation editor: pair EN/ES records', $context);
        if (is_wp_error($plan)) { return $plan; }
        $preview = $this->executor->preview($plan);
        if (is_wp_error($preview)) { return $preview; }
        $changed = $this->preview_has_changes($preview);
        $verification = ! $changed ? $this->pairing->verify_pair($first_id, $second_id) : array();
        if (! $changed && (is_wp_error($verification) || empty($verification['verified']))) {
            return is_wp_error($verification)
                ? $verification
                : new WP_Error('research_manager_translation_editor_verification_failed', 'The translation relationship did not verify as already matching.');
        }

        return array(
            'operation'=>'pair',
            'status'=>$changed ? 'change' : 'already-matching',
            'apply_allowed'=>$changed ? ! empty($preview['apply_allowed']) : true,
            'apply_blocker'=>$changed ? (string) ($preview['apply_blocker'] ?? '') : '',
            'risk'=>(string) ($preview['risk'] ?? $plan['risk'] ?? ''),
            'plan'=>$plan,
            'plan_id'=>(string) ($plan['id'] ?? ''),
            'preview'=>$preview,
            'first_id'=>$first_id,
            'second_id'=>$second_id,
            'baseline_ids'=>$baseline_ids,
            'baseline_checksum'=>$baseline_checksum,
            'evidence_reference'=>sanitize_text_field($evidence_reference),
            'verification'=>is_array($verification) ? $verification : array(),
        );
    }

    public function preview_unpair(
        int $post_id,
        bool $evidence_confirmed = false,
        string $evidence_reference = ''
    ): array|WP_Error {
        $state = $this->pairing->inspect($post_id);
        if (is_wp_error($state)) { return $state; }
        $counterpart_id = absint($state['counterpart_id'] ?? 0);
        if ($counterpart_id <= 0 || empty($state['counterpart_exists'])) {
            return new WP_Error('research_manager_translation_not_paired', 'This record does not currently have a valid translation counterpart.');
        }
        $baseline_ids = array($post_id, $counterpart_id);
        $baseline_checksum = $this->state_checksum($baseline_ids);
        $context = $this->evidence_context($evidence_confirmed, $evidence_reference);
        $plan = $this->pairing->build_unpair_plan($post_id, 'Interactive Research translation editor: remove EN/ES pairing', $context);
        if (is_wp_error($plan)) { return $plan; }
        $preview = $this->executor->preview($plan);
        if (is_wp_error($preview)) { return $preview; }

        return array(
            'operation'=>'unpair',
            'status'=>'change',
            'apply_allowed'=>! empty($preview['apply_allowed']),
            'apply_blocker'=>(string) ($preview['apply_blocker'] ?? ''),
            'risk'=>(string) ($preview['risk'] ?? $plan['risk'] ?? ''),
            'plan'=>$plan,
            'plan_id'=>(string) ($plan['id'] ?? ''),
            'preview'=>$preview,
            'first_id'=>$post_id,
            'second_id'=>$counterpart_id,
            'baseline_ids'=>$baseline_ids,
            'baseline_checksum'=>$baseline_checksum,
            'evidence_reference'=>sanitize_text_field($evidence_reference),
        );
    }

    public function apply_preview(array $prepared): array|WP_Error {
        if (! current_user_can('manage_options')) {
            return new WP_Error('research_manager_forbidden', 'You are not allowed to apply Research translation changes.');
        }
        $operation = sanitize_key((string) ($prepared['operation'] ?? ''));
        if (! in_array($operation, array('pair','unpair'), true)) {
            return new WP_Error('research_manager_translation_editor_preview_invalid', 'Prepared translation Preview has an invalid operation.');
        }
        $first_id = absint($prepared['first_id'] ?? 0);
        $second_id = absint($prepared['second_id'] ?? 0);
        $baseline_ids = is_array($prepared['baseline_ids'] ?? null) ? array_values(array_filter(array_map('absint', $prepared['baseline_ids']))) : array();
        $baseline_checksum = sanitize_text_field((string) ($prepared['baseline_checksum'] ?? ''));
        if ($first_id <= 0 || $second_id <= 0 || ! $baseline_ids || '' === $baseline_checksum) {
            return new WP_Error('research_manager_translation_editor_preview_invalid', 'Prepared translation Preview is missing its resource baseline.');
        }
        if (! hash_equals($baseline_checksum, $this->state_checksum($baseline_ids))) {
            return new WP_Error('research_manager_translation_editor_stale_preview', 'One of the translation records changed after Preview. Prepare a new Preview before Apply.');
        }

        if ('already-matching' === (string) ($prepared['status'] ?? '')) {
            $verification = $this->pairing->verify_pair($first_id, $second_id);
            if (is_wp_error($verification)) { return $verification; }
            if (empty($verification['verified'])) {
                return new WP_Error('research_manager_translation_editor_verification_failed', 'The translation relationship no longer matches the prepared Preview.');
            }
            return array(
                'status'=>'already-matching','operation'=>'pair','first_id'=>$first_id,'second_id'=>$second_id,
                'snapshot_id'=>'','verification'=>$verification,'verified'=>true,
            );
        }

        if (empty($prepared['apply_allowed']) || ! is_array($prepared['plan'] ?? null) || ! $prepared['plan']) {
            return new WP_Error(
                'research_manager_translation_editor_apply_blocked',
                '' !== trim((string) ($prepared['apply_blocker'] ?? ''))
                    ? (string) $prepared['apply_blocker']
                    : 'The prepared translation Preview is not allowed to apply.'
            );
        }

        $plan = $prepared['plan'];
        $result = $this->executor->apply($plan);
        if (is_wp_error($result)) { return $result; }
        if ('pair' === $operation) {
            $verification = $this->pairing->verify_pair($first_id, $second_id);
            if (! is_wp_error($verification) && ! empty($verification['verified'])) {
                $verification = $this->verify_evidence_reference($first_id, $second_id, (string) ($prepared['evidence_reference'] ?? ''), $verification);
            }
        } else {
            $first = $this->pairing->verify_unpaired($first_id);
            $second = $this->pairing->verify_unpaired($second_id);
            if (is_wp_error($first)) { $verification = $first; }
            elseif (is_wp_error($second)) { $verification = $second; }
            else {
                $verification = array(
                    'verified'=>! empty($first['verified']) && ! empty($second['verified']),
                    'first'=>$first,
                    'second'=>$second,
                    'verified_at'=>gmdate(DATE_W3C),
                );
            }
        }

        $generic = $this->executor->verify($plan);
        $verified = ! is_wp_error($generic)
            && ! empty($generic['verified'])
            && ! is_wp_error($verification)
            && ! empty($verification['verified']);
        if (! $verified) {
            $snapshot_id = (string) ($result['snapshot_id'] ?? '');
            if ('' !== $snapshot_id) { $this->executor->rollback($snapshot_id); }
            return is_wp_error($generic)
                ? $generic
                : (is_wp_error($verification)
                    ? $verification
                    : new WP_Error('research_manager_translation_editor_verification_failed', 'Translation change failed post-Apply verification.'));
        }

        return array(
            'status'=>(string) ($result['status'] ?? 'applied'),
            'operation'=>$operation,
            'first_id'=>$first_id,
            'second_id'=>$second_id,
            'plan_id'=>(string) ($plan['id'] ?? ''),
            'snapshot_id'=>(string) ($result['snapshot_id'] ?? ''),
            'verification'=>$verification,
            'verified'=>true,
        );
    }

    public function rollback(string $snapshot_id): array|WP_Error {
        if (! current_user_can('manage_options')) {
            return new WP_Error('research_manager_forbidden', 'You are not allowed to rollback Research translation changes.');
        }
        $snapshot_id = sanitize_text_field($snapshot_id);
        if ('' === $snapshot_id) {
            return new WP_Error('research_manager_translation_editor_snapshot_missing', 'A translation editor snapshot ID is required for rollback.');
        }
        return $this->executor->rollback($snapshot_id);
    }

    private function evidence_context(bool $confirmed, string $reference): array {
        return array(
            'evidence_confirmed'=>$confirmed,
            'evidence_reference'=>sanitize_text_field($reference),
        );
    }

    private function preview_has_changes(array $preview): bool {
        foreach ((array) ($preview['actions'] ?? array()) as $row) {
            if (! empty($row['changed'])) { return true; }
        }
        return false;
    }

    private function state_checksum(array $post_ids): string {
        $state = array();
        foreach (array_values(array_unique(array_filter(array_map('absint', $post_ids)))) as $post_id) {
            $post = get_post($post_id);
            $state[$post_id] = $post instanceof WP_Post ? array(
                'post_id'=>$post_id,
                'post_type'=>(string) $post->post_type,
                'status'=>(string) $post->post_status,
                'slug'=>(string) $post->post_name,
                'title'=>(string) $post->post_title,
                'excerpt'=>(string) $post->post_excerpt,
                'content'=>(string) $post->post_content,
                'modified_gmt'=>(string) $post->post_modified_gmt,
                'language'=>function_exists('eduardo_research_post_language') ? eduardo_research_post_language($post_id) : sanitize_key((string) get_post_meta($post_id, '_research_language', true)),
                'translation_en'=>(int) get_post_meta($post_id, '_research_translation_en', true),
                'translation_es'=>(int) get_post_meta($post_id, '_research_translation_es', true),
                'translation_evidence'=>(string) get_post_meta($post_id, '_eduardo_research_translation_evidence', true),
                'line_evidence'=>(string) get_post_meta($post_id, '_research_evidence_status', true),
            ) : array('post_id'=>$post_id,'missing'=>true);
        }
        return hash('sha256', (string) wp_json_encode($state));
    }

    private function verify_evidence_reference(int $first_id, int $second_id, string $expected_reference, array $verification): array {
        $first = get_post($first_id);
        if (! $first instanceof WP_Post || 'post' === (string) $first->post_type) { return $verification; }
        $expected_reference = sanitize_text_field($expected_reference);
        $first_reference = (string) get_post_meta($first_id, '_eduardo_research_translation_evidence', true);
        $second_reference = (string) get_post_meta($second_id, '_eduardo_research_translation_evidence', true);
        $verification['checks']['evidence_reference'] = $expected_reference === $first_reference && $expected_reference === $second_reference;
        $verification['verified'] = ! in_array(false, $verification['checks'], true);
        return $verification;
    }
}
