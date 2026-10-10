<?php
/** Interactive Preview → Apply → Verify → Rollback service for Theme-owned Page slots. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Page_Editor {
    private Eduardo_Research_Manager_Page_Resource $pages;
    private Eduardo_Research_Manager_Executor $executor;

    public function __construct(
        ?Eduardo_Research_Manager_Page_Resource $pages = null,
        ?Eduardo_Research_Manager_Executor $executor = null
    ) {
        $this->pages = $pages ?: Eduardo_Research_Manager::pages();
        $this->executor = $executor ?: Eduardo_Research_Manager::executor();
    }

    public function inspect(string $key, string $language = 'en'): array|WP_Error {
        return $this->pages->inspect($key, $language);
    }

    public function preview(string $key, string $language, array $slots): array|WP_Error {
        $resource = $this->pages->inspect($key, $language);
        if (is_wp_error($resource)) { return $resource; }

        $plan = $this->pages->build_hydration_plan(
            $key,
            $language,
            $slots,
            sprintf('Interactive Page editor: update %s (%s)', $key, strtoupper($language))
        );

        if (is_wp_error($plan)) {
            if ('research_manager_no_change' !== $plan->get_error_code()) { return $plan; }
            $verification = $this->pages->verify_hydration($key, $language, $slots);
            if (is_wp_error($verification)) { return $verification; }
            if (empty($verification['verified'])) {
                return new WP_Error('research_manager_page_editor_verification_failed', 'The current Page state did not verify as already matching.');
            }
            return array(
                'status'=>'already-matching',
                'key'=>$key,
                'language'=>$language,
                'slots'=>$slots,
                'apply_allowed'=>true,
                'plan'=>array(),
                'plan_id'=>'',
                'preview'=>array(),
                'verification'=>$verification,
            );
        }

        $preview = $this->executor->preview($plan);
        if (is_wp_error($preview)) { return $preview; }

        return array(
            'status'=>'change',
            'key'=>$key,
            'language'=>$language,
            'slots'=>$slots,
            'apply_allowed'=>! empty($preview['apply_allowed']),
            'plan'=>$plan,
            'plan_id'=>(string) ($plan['id'] ?? ''),
            'preview'=>$preview,
            'verification'=>array(),
        );
    }

    public function apply_preview(array $prepared): array|WP_Error {
        if (! current_user_can('manage_options')) {
            return new WP_Error('research_manager_forbidden', 'You are not allowed to apply interactive Page changes.');
        }

        $key = sanitize_key((string) ($prepared['key'] ?? ''));
        $language = sanitize_key((string) ($prepared['language'] ?? ''));
        $slots = is_array($prepared['slots'] ?? null) ? $prepared['slots'] : array();
        if ('' === $key || '' === $language || ! $slots) {
            return new WP_Error('research_manager_page_editor_preview_invalid', 'The prepared Page preview is incomplete.');
        }

        if ('already-matching' === (string) ($prepared['status'] ?? '')) {
            $verification = $this->pages->verify_hydration($key, $language, $slots);
            if (is_wp_error($verification)) { return $verification; }
            if (empty($verification['verified'])) {
                return new WP_Error('research_manager_page_editor_verification_failed', 'The Page changed after preview and no longer matches the prepared state.');
            }
            return array(
                'status'=>'already-matching',
                'key'=>$key,
                'language'=>$language,
                'snapshot_id'=>'',
                'verification'=>$verification,
                'verified'=>true,
            );
        }

        if (empty($prepared['apply_allowed']) || ! is_array($prepared['plan'] ?? null) || ! $prepared['plan']) {
            return new WP_Error('research_manager_page_editor_apply_blocked', 'The prepared Page preview is not allowed to apply.');
        }

        $plan = $prepared['plan'];
        $result = $this->executor->apply($plan);
        if (is_wp_error($result)) { return $result; }

        $generic = $this->executor->verify($plan);
        $semantic = $this->pages->verify_hydration($key, $language, $slots);
        $verified = ! is_wp_error($generic)
            && ! empty($generic['verified'])
            && ! is_wp_error($semantic)
            && ! empty($semantic['verified']);

        if (! $verified) {
            $snapshot_id = (string) ($result['snapshot_id'] ?? '');
            if ('' !== $snapshot_id) { $this->executor->rollback($snapshot_id); }
            $error = is_wp_error($generic)
                ? $generic
                : (is_wp_error($semantic)
                    ? $semantic
                    : new WP_Error('research_manager_page_editor_verification_failed', 'Interactive Page changes failed post-Apply verification.'));
            return $error;
        }

        return array(
            'status'=>(string) ($result['status'] ?? 'applied'),
            'key'=>$key,
            'language'=>$language,
            'plan_id'=>(string) ($plan['id'] ?? ''),
            'snapshot_id'=>(string) ($result['snapshot_id'] ?? ''),
            'verification'=>$semantic,
            'verified'=>true,
        );
    }

    public function rollback(string $snapshot_id): array|WP_Error {
        if (! current_user_can('manage_options')) {
            return new WP_Error('research_manager_forbidden', 'You are not allowed to rollback interactive Page changes.');
        }
        $snapshot_id = sanitize_text_field($snapshot_id);
        if ('' === $snapshot_id) {
            return new WP_Error('research_manager_page_editor_snapshot_missing', 'A Page editor snapshot ID is required for rollback.');
        }
        return $this->executor->rollback($snapshot_id);
    }
}
