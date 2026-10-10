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

    public function inventory(): array {
        $items = array();
        foreach (Eduardo_Research_Manager::contract()->pages() as $key => $definition) {
            $state = Eduardo_Research_Manager::contract()->page_state((string) $key);
            $row = array(
                'key'=>(string) $key,
                'exists'=>! empty($state['exists']),
                'page_id'=>(int) ($state['id'] ?? 0),
                'role'=>(string) ($definition['role'] ?? ''),
                'model'=>(string) ($definition['model'] ?? ''),
                'languages'=>array(),
            );
            foreach (Eduardo_Research_Manager::contract()->languages() as $language) {
                if (empty($state['exists'])) {
                    $row['languages'][(string) $language] = array(
                        'language'=>(string) $language,
                        'available'=>false,
                        'url'=>'',
                        'allowed_slots'=>array_keys($this->pages->slot_schema((string) $key, (string) $language)),
                    );
                    continue;
                }
                $resource = $this->pages->inspect((string) $key, (string) $language);
                $row['languages'][(string) $language] = is_wp_error($resource)
                    ? array('language'=>(string) $language,'available'=>false,'error_code'=>$resource->get_error_code(),'error'=>$resource->get_error_message())
                    : array(
                        'language'=>(string) $language,
                        'available'=>true,
                        'url'=>(string) ($resource['url'] ?? ''),
                        'contract_aligned'=>! empty($resource['contract_aligned']),
                        'allowed_slots'=>(array) ($resource['allowed_slots'] ?? array()),
                        'stored_slots'=>(array) ($resource['stored_slots'] ?? array()),
                        'effective_slots'=>(array) ($resource['effective_slots'] ?? array()),
                    );
            }
            $items[] = $row;
        }
        return array(
            'items'=>$items,
            'count'=>count($items),
            'languages'=>Eduardo_Research_Manager::contract()->languages(),
            'generated_at'=>gmdate(DATE_W3C),
        );
    }

    public function preview(string $key, string $language, array $slots): array|WP_Error {
        $resource = $this->pages->inspect($key, $language);
        if (is_wp_error($resource)) { return $resource; }
        $baseline_checksum = $this->state_checksum($resource);

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
                'baseline_checksum'=>$baseline_checksum,
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
            'baseline_checksum'=>$baseline_checksum,
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
        $baseline_checksum = sanitize_text_field((string) ($prepared['baseline_checksum'] ?? ''));
        $status = (string) ($prepared['status'] ?? '');
        if ('' === $key || '' === $language || ! $slots || '' === $baseline_checksum) {
            return new WP_Error('research_manager_page_editor_preview_invalid', 'The prepared Page preview is incomplete.');
        }

        if ('already-matching' !== $status
            && (empty($prepared['apply_allowed']) || ! is_array($prepared['plan'] ?? null) || ! $prepared['plan'])) {
            return new WP_Error('research_manager_page_editor_apply_blocked', 'The prepared Page preview is not allowed to apply.');
        }

        $current = $this->pages->inspect($key, $language);
        if (is_wp_error($current)) { return $current; }
        if (! hash_equals($baseline_checksum, $this->state_checksum($current))) {
            return new WP_Error(
                'research_manager_page_editor_stale_preview',
                'The Page changed after Preview. Refresh the editor and prepare a new Preview before Apply.'
            );
        }

        if ('already-matching' === $status) {
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

    public function preview_creation(string $key): array|WP_Error {
        $key = sanitize_key($key);
        $plan = $this->pages->build_creation_plan($key, sprintf('Interactive Page editor: create missing %s Page', $key));
        if (is_wp_error($plan)) { return $plan; }
        $preview = $this->executor->preview($plan);
        if (is_wp_error($preview)) { return $preview; }
        $action = is_array($plan['actions'][0] ?? null) ? $plan['actions'][0] : array();
        $creation_token = sanitize_text_field((string) ($action['creation_token'] ?? ''));
        if ('' === $creation_token) {
            return new WP_Error('research_manager_page_editor_preview_invalid', 'The Page creation plan is missing provenance.');
        }
        return array(
            'status'=>'create',
            'key'=>$key,
            'creation_token'=>$creation_token,
            'apply_allowed'=>! empty($preview['apply_allowed']),
            'plan'=>$plan,
            'plan_id'=>(string) ($plan['id'] ?? ''),
            'preview'=>$preview,
        );
    }

    public function apply_creation_preview(array $prepared): array|WP_Error {
        if (! current_user_can('manage_options')) {
            return new WP_Error('research_manager_forbidden', 'You are not allowed to create Theme-owned Pages.');
        }
        $key = sanitize_key((string) ($prepared['key'] ?? ''));
        $token = sanitize_text_field((string) ($prepared['creation_token'] ?? ''));
        $plan = is_array($prepared['plan'] ?? null) ? $prepared['plan'] : array();
        if ('' === $key || '' === $token || ! $plan || empty($prepared['apply_allowed'])) {
            return new WP_Error('research_manager_page_editor_preview_invalid', 'The prepared Page creation preview is incomplete.');
        }
        $state = Eduardo_Research_Manager::contract()->page_state($key);
        if (! empty($state['exists'])) {
            return new WP_Error('research_manager_page_editor_stale_preview', 'The Theme Page appeared after Preview. Prepare a fresh Page operation.');
        }
        $result = $this->executor->apply($plan);
        if (is_wp_error($result)) { return $result; }
        $verification = $this->pages->verify_creation($key, $token);
        if (is_wp_error($verification) || empty($verification['verified'])) {
            $snapshot_id = (string) ($result['snapshot_id'] ?? '');
            if ('' !== $snapshot_id) { $this->executor->rollback($snapshot_id); }
            return is_wp_error($verification)
                ? $verification
                : new WP_Error('research_manager_page_editor_verification_failed', 'Created Page failed contract verification and was rolled back.');
        }
        return array(
            'status'=>(string) ($result['status'] ?? 'applied'),
            'key'=>$key,
            'page_id'=>(int) ($verification['page_id'] ?? 0),
            'url'=>(string) ($verification['url'] ?? ''),
            'creation_token'=>$token,
            'plan_id'=>(string) ($plan['id'] ?? ''),
            'snapshot_id'=>(string) ($result['snapshot_id'] ?? ''),
            'verification'=>$verification,
            'verified'=>true,
        );
    }

    public function verify_slots(string $key, string $language, array $slots): array|WP_Error {
        return $this->pages->verify_hydration($key, $language, $slots);
    }

    public function verify_creation(string $key, string $creation_token): array|WP_Error {
        return $this->pages->verify_creation($key, $creation_token);
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

    private function state_checksum(array $resource): string {
        $state = array(
            'key'=>(string) ($resource['key'] ?? ''),
            'language'=>(string) ($resource['language'] ?? ''),
            'page_id'=>(int) ($resource['page_id'] ?? 0),
            'role'=>(string) ($resource['role'] ?? ''),
            'model'=>(string) ($resource['model'] ?? ''),
            'stored_slots'=>is_array($resource['stored_slots'] ?? null) ? $resource['stored_slots'] : array(),
        );
        return hash('sha256', (string) wp_json_encode($state));
    }
}
