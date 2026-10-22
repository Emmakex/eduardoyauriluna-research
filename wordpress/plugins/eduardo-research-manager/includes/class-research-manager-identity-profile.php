<?php
/** Shared canonical researcher identity profile service. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Identity_Profile {
    private Eduardo_Research_Manager_Executor $executor;
    private Eduardo_Research_Manager_Evidence_Editor $evidence;

    public function __construct(
        ?Eduardo_Research_Manager_Executor $executor = null,
        ?Eduardo_Research_Manager_Evidence_Editor $evidence = null
    ) {
        $this->executor = $executor ?: Eduardo_Research_Manager::executor();
        $this->evidence = $evidence ?: Eduardo_Research_Manager::evidence_editor();
    }

    public function inspect(): array|WP_Error {
        $stored = get_option('eduardo_research_identity', array());
        if (! is_array($stored)) {
            return new WP_Error('research_manager_identity_invalid', 'The canonical Researcher Identity has an invalid stored shape.');
        }

        $defaults = Eduardo_Research_Manager::blueprint_store()->instance_defaults();
        $defaults = is_wp_error($defaults) ? array() : $defaults;
        $default_identity = is_array($defaults['identity'] ?? null) ? $defaults['identity'] : array();
        $default_headlines = is_array($default_identity['headlines'] ?? null) ? $default_identity['headlines'] : array();
        $languages = array_values(Eduardo_Research_Manager::contract()->languages());
        if (! $languages) {
            return new WP_Error('research_manager_identity_languages_missing', 'The active Greenfield Theme contract does not expose an identity language.');
        }
        $default_language = sanitize_key((string) $languages[0]);
        $secondary_language = isset($languages[1]) ? sanitize_key((string) $languages[1]) : '';

        $name = $this->text($stored['name'] ?? '');
        if ('' === $name) { $name = $this->text($default_identity['name'] ?? ''); }
        if ('' === $name) { $name = trim((string) get_bloginfo('name')); }
        if ('' === $name) {
            return new WP_Error('research_manager_identity_name_missing', 'The Researcher Identity name is missing from stored state, the active instance blueprint and WordPress site identity.');
        }

        $headline = $this->text($stored['headline'] ?? '');
        if ('' === $headline) { $headline = $this->text($default_headlines[$default_language] ?? ''); }
        if ('' === $headline) { $headline = trim((string) get_bloginfo('description')); }
        if ('' === $headline) {
            return new WP_Error('research_manager_identity_headline_missing', 'The primary Researcher Identity headline is missing from stored state, the active instance blueprint and WordPress site identity.');
        }

        $headline_es = $this->text($stored['headline_es'] ?? '');
        if ('' === $headline_es && '' !== $secondary_language) {
            $headline_es = $this->text($default_headlines[$secondary_language] ?? '');
        }
        if ('' === $headline_es) {
            return new WP_Error('research_manager_identity_secondary_headline_missing', 'The secondary Researcher Identity headline is missing from stored state and the active instance blueprint.');
        }

        return array(
            'name'=>$name,
            'headline'=>$headline,
            'headline_es'=>$headline_es,
            'url'=>home_url('/'),
            'evidence_reference'=>$this->text($stored['evidence_reference'] ?? ''),
            'verified_at'=>$this->text($stored['verified_at'] ?? ''),
            'stored'=>$stored,
            'revision'=>$this->revision($stored),
            'fields'=>array('name','headline','headline_es'),
            'languages'=>$languages,
            'identity_language_map'=>array(
                'headline'=>$default_language,
                'headline_es'=>$secondary_language,
            ),
            'arbitrary_wordpress_profile_proxy'=>false,
        );
    }

    public function preview_update(array $changes, bool $evidence_confirmed = false, string $evidence_reference = ''): array|WP_Error {
        if (! $changes) { return new WP_Error('research_manager_identity_empty', 'Identity update requires at least one bounded field.'); }
        $unknown = array_diff(array_keys($changes), array('name','headline','headline_es'));
        if ($unknown) {
            return new WP_Error('research_manager_identity_field_not_allowed', 'Unsupported identity field: ' . implode(', ', array_map('sanitize_key', $unknown)));
        }
        $current = $this->inspect();
        if (is_wp_error($current)) { return $current; }
        $store = $this->evidence->store();
        if (is_wp_error($store)) { return $store; }

        $next = is_array($current['stored'] ?? null) ? $current['stored'] : array();
        $next['name'] = (string) $current['name'];
        $next['headline'] = (string) $current['headline'];
        $next['headline_es'] = (string) $current['headline_es'];
        foreach ($changes as $field => $value) {
            if (! is_scalar($value)) {
                return new WP_Error('research_manager_identity_value_invalid', sprintf('Identity field "%s" must be scalar text.', $field));
            }
            $value = trim(sanitize_text_field((string) $value));
            if ('' === $value) {
                return new WP_Error('research_manager_identity_value_required', sprintf('Identity field "%s" cannot be empty.', $field));
            }
            $next[$field] = $value;
        }

        $reference = sanitize_text_field($evidence_reference);
        if ('' !== $reference) { $next['evidence_reference'] = $reference; }
        if ($evidence_confirmed && '' !== $reference) { $next['verified_at'] = gmdate(DATE_W3C); }
        $baseline = $this->baseline_revision($current['stored'], $store);

        if ($this->same($current['stored'], $next)) {
            return array(
                'resource'=>'identity-profile','operation'=>'update','status'=>'already-matching','apply_allowed'=>true,
                'risk'=>'evidence-required','plan'=>array(),'plan_id'=>'','before'=>$current['stored'],'expected'=>$next,
                'baseline_revision'=>$baseline,'verification'=>$this->verify($next),
            );
        }

        $plan = Eduardo_Research_Manager_Plan::create(
            'Update canonical verified Researcher Identity profile',
            array(
                array('type'=>'option','key'=>'eduardo_research_identity','value'=>$next),
                array('type'=>'option','key'=>'eduardo_research_evidence','value'=>$store),
            ),
            array('evidence_confirmed'=>$evidence_confirmed,'evidence_reference'=>$reference)
        );
        if (is_wp_error($plan)) { return $plan; }
        $preview = $this->executor->preview($plan);
        if (is_wp_error($preview)) { return $preview; }
        return array(
            'resource'=>'identity-profile','operation'=>'update','status'=>'change',
            'apply_allowed'=>! empty($preview['apply_allowed']),'apply_blocker'=>(string) ($preview['apply_blocker'] ?? ''),
            'risk'=>(string) ($preview['risk'] ?? $plan['risk'] ?? 'evidence-required'),
            'plan'=>$plan,'plan_id'=>(string) ($plan['id'] ?? ''),'preview'=>$preview,
            'before'=>$current['stored'],'expected'=>$next,'baseline_revision'=>$baseline,
        );
    }

    public function apply_preview(array $prepared): array|WP_Error {
        if (! current_user_can('manage_options')) {
            return new WP_Error('research_manager_forbidden', 'You are not allowed to apply canonical identity changes.');
        }
        $expected = is_array($prepared['expected'] ?? null) ? $prepared['expected'] : array();
        $baseline = sanitize_text_field((string) ($prepared['baseline_revision'] ?? ''));
        if (! $expected || '' === $baseline) {
            return new WP_Error('research_manager_identity_preview_invalid', 'Prepared identity Preview is incomplete.');
        }
        $current = $this->inspect();
        $store = $this->evidence->store();
        if (is_wp_error($current)) { return $current; }
        if (is_wp_error($store)) { return $store; }
        if (! hash_equals($baseline, $this->baseline_revision($current['stored'], $store))) {
            return new WP_Error('research_manager_identity_stale_preview', 'Identity or evidence changed after Preview. Prepare a fresh Preview.');
        }
        if ('already-matching' === (string) ($prepared['status'] ?? '')) {
            $verification = $this->verify($expected);
            return is_wp_error($verification) ? $verification : array('status'=>'already-matching','snapshot_id'=>'','verified'=>! empty($verification['verified']),'verification'=>$verification);
        }
        if (empty($prepared['apply_allowed']) || ! is_array($prepared['plan'] ?? null) || ! $prepared['plan']) {
            return new WP_Error('research_manager_identity_apply_blocked', (string) ($prepared['apply_blocker'] ?? 'Canonical identity Preview is not allowed to apply.'));
        }
        $result = $this->executor->apply($prepared['plan']);
        if (is_wp_error($result)) { return $result; }
        $generic = $this->executor->verify($prepared['plan']);
        $semantic = $this->verify($expected);
        $verified = ! is_wp_error($generic) && ! empty($generic['verified']) && ! is_wp_error($semantic) && ! empty($semantic['verified']);
        if (! $verified) {
            $snapshot = (string) ($result['snapshot_id'] ?? '');
            if ('' !== $snapshot) { $this->executor->rollback($snapshot); }
            return is_wp_error($semantic) ? $semantic : new WP_Error('research_manager_identity_verification_failed', 'Canonical identity failed post-Apply verification.');
        }
        return array(
            'status'=>(string) ($result['status'] ?? 'applied'),
            'snapshot_id'=>(string) ($result['snapshot_id'] ?? ''),
            'verified'=>true,'verification'=>$semantic,
        );
    }

    public function verify(array $expected): array|WP_Error {
        $current = $this->inspect();
        if (is_wp_error($current)) { return $current; }
        $stored = is_array($current['stored'] ?? null) ? $current['stored'] : array();
        $checks = array();
        foreach ($expected as $key => $value) {
            if (! in_array($key, array('name','headline','headline_es','evidence_reference','verified_at'), true)) { continue; }
            $actual = in_array($key, array('name','headline','headline_es'), true) ? ($current[$key] ?? '') : ($stored[$key] ?? '');
            $checks[$key] = $this->same($actual, $value);
        }
        return array('verified'=>! in_array(false, $checks, true),'checks'=>$checks,'actual'=>$current,'verified_at'=>gmdate(DATE_W3C));
    }

    public function rollback(string $snapshot_id): array|WP_Error {
        if (! current_user_can('manage_options')) {
            return new WP_Error('research_manager_forbidden', 'You are not allowed to rollback canonical identity changes.');
        }
        $snapshot_id = sanitize_text_field($snapshot_id);
        if ('' === $snapshot_id) { return new WP_Error('research_manager_identity_snapshot_required', 'Identity rollback requires a snapshot ID.'); }
        return $this->executor->rollback($snapshot_id);
    }

    private function baseline_revision(array $identity, array $evidence): string {
        return hash('sha256', (string) wp_json_encode(array('identity'=>$identity,'evidence'=>$evidence)));
    }

    private function revision(array $identity): string {
        return hash('sha256', (string) wp_json_encode($identity));
    }

    private function same(mixed $a, mixed $b): bool { return maybe_serialize($a) === maybe_serialize($b); }
    private function text(mixed $value): string { return is_scalar($value) ? trim((string) $value) : ''; }
}
