<?php
/** Hydrate Theme-owned Page slots from a validated Greenfield blueprint. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Blueprint_Hydrator {
    private Eduardo_Research_Manager_Blueprint $blueprints;
    private Eduardo_Research_Manager_Page_Resource $pages;
    private Eduardo_Research_Manager_Executor $executor;
    private Eduardo_Research_Manager_Identity_Profile $identity;

    public function __construct(
        ?Eduardo_Research_Manager_Blueprint $blueprints = null,
        ?Eduardo_Research_Manager_Page_Resource $pages = null,
        ?Eduardo_Research_Manager_Executor $executor = null,
        ?Eduardo_Research_Manager_Identity_Profile $identity = null
    ) {
        $this->blueprints = $blueprints ?: Eduardo_Research_Manager::blueprint();
        $this->pages = $pages ?: Eduardo_Research_Manager::pages();
        $this->executor = $executor ?: Eduardo_Research_Manager::executor();
        $this->identity = $identity ?: new Eduardo_Research_Manager_Identity_Profile($this->executor, Eduardo_Research_Manager::evidence_editor());
    }

    public function preview(array $blueprint): array|WP_Error {
        $normalized = $this->blueprints->validate($blueprint);
        if (is_wp_error($normalized)) { return $normalized; }

        $operations = array();
        $skipped = array();
        $apply_allowed = true;

        $identity = $this->identity_seed($normalized);
        if (is_wp_error($identity)) { return $identity; }
        if ($identity) {
            $prepared = $this->identity->preview_update($identity, true, 'Greenfield canonical blueprint v1');
            if (is_wp_error($prepared)) { return $prepared; }
            $apply_allowed = $apply_allowed && ! empty($prepared['apply_allowed']);
            $operations[] = array(
                'resource'=>'identity-profile','index'=>-1,'key'=>'researcher-identity','language'=>'multi',
                'status'=>(string) ($prepared['status'] ?? 'change'),'apply_allowed'=>! empty($prepared['apply_allowed']),
                'plan_id'=>(string) ($prepared['plan_id'] ?? ''),'preview'=>$prepared['preview'] ?? array(),
            );
        } elseif ($this->has_legacy_identity_slots($normalized)) {
            $skipped[] = array('resource'=>'identity-profile','index'=>-1,'key'=>'researcher-identity','reason'=>'canonical_identity_already_initialized');
        }

        foreach ($normalized['pages'] as $index => $record) {
            $page = $this->normalize_page_record($record, $index, $normalized['languages']);
            if (is_wp_error($page)) { return $page; }
            if (! $page['slots']) {
                $skipped[] = array('resource'=>'pages','index'=>$index,'key'=>$page['key'],'reason'=>'no_slots');
                continue;
            }

            foreach ($page['slots'] as $language => $slots) {
                $operation = $this->preview_language($page['key'], $language, $slots, $index);
                if (is_wp_error($operation)) { return $operation; }
                $apply_allowed = $apply_allowed && ! empty($operation['apply_allowed']);
                $operations[] = $operation;
            }
        }

        return array(
            'version'=>$normalized['version'],
            'mode'=>Eduardo_Research_Manager_Mode::current(),
            'languages'=>$normalized['languages'],
            'apply_allowed'=>$apply_allowed,
            'operations'=>$operations,
            'operation_count'=>count($operations),
            'skipped'=>$skipped,
            'skipped_count'=>count($skipped),
            'requires_legacy_discovery'=>false,
            'requires_legacy_mapping'=>false,
            'requires_gutenberg_layout'=>false,
        );
    }

    public function apply(array $blueprint): array|WP_Error {
        if (! current_user_can('manage_options')) {
            return new WP_Error('research_manager_forbidden', 'You are not allowed to apply blueprint hydration.');
        }

        $normalized = $this->blueprints->validate($blueprint);
        if (is_wp_error($normalized)) { return $normalized; }

        $applied = array();
        $skipped = array();

        $identity = $this->identity_seed($normalized);
        if (is_wp_error($identity)) { return $identity; }
        if ($identity) {
            $prepared = $this->identity->preview_update($identity, true, 'Greenfield canonical blueprint v1');
            if (is_wp_error($prepared)) { return $this->abort($prepared, $applied); }
            $result = $this->identity->apply_preview($prepared);
            if (is_wp_error($result)) { return $this->abort($result, $applied); }
            $applied[] = array(
                'resource'=>'identity-profile','index'=>-1,'key'=>'researcher-identity','language'=>'multi',
                'status'=>(string) ($result['status'] ?? 'applied'),'plan_id'=>(string) ($prepared['plan_id'] ?? ''),
                'snapshot_id'=>(string) ($result['snapshot_id'] ?? ''),'verification'=>$result['verification'] ?? array(),
            );
        } elseif ($this->has_legacy_identity_slots($normalized)) {
            $skipped[] = array('resource'=>'identity-profile','index'=>-1,'key'=>'researcher-identity','reason'=>'canonical_identity_already_initialized');
        }

        foreach ($normalized['pages'] as $index => $record) {
            $page = $this->normalize_page_record($record, $index, $normalized['languages']);
            if (is_wp_error($page)) { return $this->abort($page, $applied); }
            if (! $page['slots']) {
                $skipped[] = array('resource'=>'pages','index'=>$index,'key'=>$page['key'],'reason'=>'no_slots');
                continue;
            }

            foreach ($page['slots'] as $language => $slots) {
                $plan = $this->pages->build_hydration_plan(
                    $page['key'],
                    $language,
                    $slots,
                    sprintf('Greenfield blueprint: hydrate %s Page (%s)', $page['key'], strtoupper($language))
                );

                if (is_wp_error($plan)) {
                    if ('research_manager_no_change' === $plan->get_error_code()) {
                        $semantic = $this->pages->verify_hydration($page['key'], $language, $slots);
                        if (is_wp_error($semantic) || empty($semantic['verified'])) {
                            $error = is_wp_error($semantic)
                                ? $semantic
                                : new WP_Error('research_manager_hydration_verification_failed', 'Existing Theme state did not verify as already matching.');
                            return $this->abort($error, $applied);
                        }
                        $applied[] = array(
                            'resource'=>'pages','index'=>$index,'key'=>$page['key'],'language'=>$language,
                            'status'=>'already-matching','plan_id'=>'','snapshot_id'=>'','verification'=>$semantic,
                        );
                        continue;
                    }
                    return $this->abort($plan, $applied);
                }

                $result = $this->executor->apply($plan);
                if (is_wp_error($result)) { return $this->abort($result, $applied); }

                $generic = $this->executor->verify($plan);
                $semantic = $this->pages->verify_hydration($page['key'], $language, $slots);
                if (is_wp_error($generic) || empty($generic['verified']) || is_wp_error($semantic) || empty($semantic['verified'])) {
                    if (! empty($result['snapshot_id'])) {
                        $this->executor->rollback((string) $result['snapshot_id']);
                    }
                    $error = is_wp_error($generic)
                        ? $generic
                        : (is_wp_error($semantic)
                            ? $semantic
                            : new WP_Error('research_manager_hydration_verification_failed', 'Hydrated Theme state failed verification.'));
                    return $this->abort($error, $applied);
                }

                $applied[] = array(
                    'resource'=>'pages','index'=>$index,'key'=>$page['key'],'language'=>$language,
                    'status'=>(string) ($result['status'] ?? 'applied'),
                    'plan_id'=>(string) $plan['id'],
                    'snapshot_id'=>(string) ($result['snapshot_id'] ?? ''),
                    'verification'=>$semantic,
                );
            }
        }

        return array(
            'status'=>'applied',
            'mode'=>Eduardo_Research_Manager_Mode::current(),
            'languages'=>$normalized['languages'],
            'operations'=>$applied,
            'operation_count'=>count($applied),
            'skipped'=>$skipped,
            'skipped_count'=>count($skipped),
            'snapshot_ids'=>array_values(array_filter(array_map(
                static fn(array $row): string => (string) ($row['snapshot_id'] ?? ''),
                $applied
            ))),
            'verified'=>true,
        );
    }

    public function rollback(array $snapshot_ids): array|WP_Error {
        if (! current_user_can('manage_options')) {
            return new WP_Error('research_manager_forbidden', 'You are not allowed to rollback blueprint hydration.');
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

    private function preview_language(string $key, string $language, array $slots, int $index): array|WP_Error {
        $plan = $this->pages->build_hydration_plan(
            $key,
            $language,
            $slots,
            sprintf('Greenfield blueprint: hydrate %s Page (%s)', $key, strtoupper($language))
        );

        if (is_wp_error($plan)) {
            if ('research_manager_no_change' !== $plan->get_error_code()) { return $plan; }
            $verification = $this->pages->verify_hydration($key, $language, $slots);
            if (is_wp_error($verification)) { return $verification; }
            return array(
                'resource'=>'pages','index'=>$index,'key'=>$key,'language'=>$language,
                'status'=>'already-matching','apply_allowed'=>true,'plan_id'=>'','preview'=>array(),
                'verification'=>$verification,
            );
        }

        $preview = $this->executor->preview($plan);
        if (is_wp_error($preview)) { return $preview; }
        return array(
            'resource'=>'pages','index'=>$index,'key'=>$key,'language'=>$language,
            'status'=>'change','apply_allowed'=>! empty($preview['apply_allowed']),
            'plan_id'=>(string) $plan['id'],'preview'=>$preview,
        );
    }

    private function normalize_page_record(mixed $record, int $index, array $languages): array|WP_Error {
        if (! is_array($record)) {
            return $this->record_error($index, 'Page blueprint records must be object-like arrays.');
        }
        $key = sanitize_key((string) ($record['key'] ?? ''));
        if ('' === $key) { return $this->record_error($index, 'Page blueprint records require a key.'); }

        $slots = $record['slots'] ?? array();
        if (! is_array($slots)) { return $this->record_error($index, 'Page blueprint slots must be keyed by language.'); }
        $clean = array();
        foreach ($slots as $language => $values) {
            $language = sanitize_key((string) $language);
            if (! in_array($language, $languages, true)) {
                return $this->record_error($index, sprintf('Page hydration language "%s" is outside the blueprint language set.', $language));
            }
            if (! is_array($values)) {
                return $this->record_error($index, sprintf('Page slots for language "%s" must be an object-like array.', $language));
            }
            if ('home' === $key) {
                unset($values['researcher-name'], $values['researcher-headline']);
            }
            if ($values) { $clean[$language] = $values; }
        }
        return array('key'=>$key,'slots'=>$clean);
    }

    /**
     * Backward-compatible bootstrap bridge for the v1 bundled blueprint.
     * Legacy home identity aliases may initialize canonical Identity once,
     * but can never overwrite an already initialized Manager identity.
     */
    private function identity_seed(array $normalized): array|WP_Error {
        $stored = get_option('eduardo_research_identity', array());
        if (is_array($stored) && $stored) { return array(); }

        $seed = array();
        foreach ((array) ($normalized['pages'] ?? array()) as $record) {
            if (! is_array($record) || 'home' !== sanitize_key((string) ($record['key'] ?? ''))) { continue; }
            $slots = is_array($record['slots'] ?? null) ? $record['slots'] : array();
            $en = is_array($slots['en'] ?? null) ? $slots['en'] : array();
            $es = is_array($slots['es'] ?? null) ? $slots['es'] : array();
            $name_en = trim(sanitize_text_field((string) ($en['researcher-name'] ?? '')));
            $name_es = trim(sanitize_text_field((string) ($es['researcher-name'] ?? '')));
            if ('' !== $name_en && '' !== $name_es && $name_en !== $name_es) {
                return new WP_Error('research_manager_blueprint_identity_mismatch', 'Greenfield blueprint researcher name differs between EN and ES.');
            }
            $name = '' !== $name_en ? $name_en : $name_es;
            $headline = trim(sanitize_text_field((string) ($en['researcher-headline'] ?? '')));
            $headline_es = trim(sanitize_text_field((string) ($es['researcher-headline'] ?? '')));
            if ('' !== $name) { $seed['name'] = $name; }
            if ('' !== $headline) { $seed['headline'] = $headline; }
            if ('' !== $headline_es) { $seed['headline_es'] = $headline_es; }
            break;
        }
        return $seed;
    }

    private function has_legacy_identity_slots(array $normalized): bool {
        foreach ((array) ($normalized['pages'] ?? array()) as $record) {
            if (! is_array($record) || 'home' !== sanitize_key((string) ($record['key'] ?? ''))) { continue; }
            foreach ((array) ($record['slots'] ?? array()) as $values) {
                if (is_array($values) && (array_key_exists('researcher-name', $values) || array_key_exists('researcher-headline', $values))) { return true; }
            }
        }
        return false;
    }

    private function abort(WP_Error $error, array $applied): WP_Error {
        $rollback = $this->rollback_applied($applied);
        return new WP_Error(
            'research_manager_blueprint_hydration_failed',
            $error->get_error_message(),
            array('source_code'=>$error->get_error_code(),'rollback'=>$rollback)
        );
    }

    private function rollback_applied(array $applied): array {
        $results = array();
        foreach (array_reverse($applied) as $entry) {
            $snapshot_id = (string) ($entry['snapshot_id'] ?? '');
            if ('' === $snapshot_id) { continue; }
            $result = $this->executor->rollback($snapshot_id);
            $results[] = is_wp_error($result)
                ? array('snapshot_id'=>$snapshot_id,'rolled_back'=>false,'error'=>$result->get_error_message())
                : array('snapshot_id'=>$snapshot_id,'rolled_back'=>true);
        }
        return $results;
    }

    private function record_error(int $index, string $message): WP_Error {
        return new WP_Error('research_manager_blueprint_hydration_record_invalid', $message, array('resource'=>'pages','index'=>$index));
    }
}
