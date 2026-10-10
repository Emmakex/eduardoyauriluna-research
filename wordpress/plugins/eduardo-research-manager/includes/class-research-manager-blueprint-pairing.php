<?php
/** Resolve and apply bilingual Research Line pairs declared by Greenfield blueprint translation keys. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Blueprint_Pairing {
    private Eduardo_Research_Manager_Blueprint $blueprints;
    private Eduardo_Research_Manager_Line_Resource $lines;
    private Eduardo_Research_Manager_Translation_Pairing $translations;
    private Eduardo_Research_Manager_Executor $executor;

    public function __construct(
        ?Eduardo_Research_Manager_Blueprint $blueprints = null,
        ?Eduardo_Research_Manager_Line_Resource $lines = null,
        ?Eduardo_Research_Manager_Translation_Pairing $translations = null,
        ?Eduardo_Research_Manager_Executor $executor = null
    ) {
        $this->blueprints = $blueprints ?: Eduardo_Research_Manager::blueprint();
        $this->lines = $lines ?: Eduardo_Research_Manager::lines();
        $this->translations = $translations ?: Eduardo_Research_Manager::translations();
        $this->executor = $executor ?: Eduardo_Research_Manager::executor();
    }

    public function preview(array $blueprint): array|WP_Error {
        $pairs = $this->resolve_pairs($blueprint);
        if (is_wp_error($pairs)) { return $pairs; }

        $operations = array();
        $apply_allowed = true;
        foreach ($pairs as $pair) {
            $matching = $this->already_matching($pair);
            if (is_wp_error($matching)) { return $matching; }
            if ($matching) {
                $operations[] = array(
                    'translation_key'=>$pair['translation_key'],'status'=>'already-matching','apply_allowed'=>true,
                    'en_id'=>$pair['en_id'],'es_id'=>$pair['es_id'],'plan_id'=>'','preview'=>array(),
                );
                continue;
            }

            $plan = $this->translations->build_pair_plan(
                $pair['en_id'],
                $pair['es_id'],
                sprintf('Greenfield blueprint: pair Research Line translations %s', $pair['translation_key']),
                $pair['context']
            );
            if (is_wp_error($plan)) { return $plan; }
            $preview = $this->executor->preview($plan);
            if (is_wp_error($preview)) { return $preview; }
            $allowed = ! empty($preview['apply_allowed']);
            $apply_allowed = $apply_allowed && $allowed;
            $operations[] = array(
                'translation_key'=>$pair['translation_key'],'status'=>'change','apply_allowed'=>$allowed,
                'en_id'=>$pair['en_id'],'es_id'=>$pair['es_id'],'plan_id'=>$plan['id'],'preview'=>$preview,
            );
        }

        return array(
            'mode'=>Eduardo_Research_Manager_Mode::current(),
            'apply_allowed'=>$apply_allowed,
            'operations'=>$operations,
            'operation_count'=>count($operations),
            'requires_legacy_discovery'=>false,
            'requires_legacy_mapping'=>false,
        );
    }

    public function apply(array $blueprint): array|WP_Error {
        if (! current_user_can('manage_options')) {
            return new WP_Error('research_manager_forbidden', 'You are not allowed to apply blueprint translation pairs.');
        }

        $pairs = $this->resolve_pairs($blueprint);
        if (is_wp_error($pairs)) { return $pairs; }

        $applied = array();
        foreach ($pairs as $pair) {
            $matching = $this->already_matching($pair);
            if (is_wp_error($matching)) { return $this->abort($matching, $applied); }
            if ($matching) {
                $applied[] = array(
                    'translation_key'=>$pair['translation_key'],'status'=>'already-matching','en_id'=>$pair['en_id'],'es_id'=>$pair['es_id'],
                    'snapshot_id'=>'','verification'=>$this->translations->verify_pair($pair['en_id'], $pair['es_id']),
                );
                continue;
            }

            $plan = $this->translations->build_pair_plan(
                $pair['en_id'],
                $pair['es_id'],
                sprintf('Greenfield blueprint: pair Research Line translations %s', $pair['translation_key']),
                $pair['context']
            );
            if (is_wp_error($plan)) { return $this->abort($plan, $applied); }
            $result = $this->executor->apply($plan);
            if (is_wp_error($result)) { return $this->abort($result, $applied); }
            $verification = $this->translations->verify_pair($pair['en_id'], $pair['es_id']);
            if (is_wp_error($verification) || empty($verification['verified'])) {
                if (! empty($result['snapshot_id'])) { $this->executor->rollback((string) $result['snapshot_id']); }
                $error = is_wp_error($verification)
                    ? $verification
                    : new WP_Error('research_manager_blueprint_pairing_verification_failed', 'Research Line translation pairing failed verification.');
                return $this->abort($error, $applied);
            }
            $applied[] = array(
                'translation_key'=>$pair['translation_key'],'status'=>(string) ($result['status'] ?? 'applied'),
                'en_id'=>$pair['en_id'],'es_id'=>$pair['es_id'],'snapshot_id'=>(string) ($result['snapshot_id'] ?? ''),
                'verification'=>$verification,
            );
        }

        return array(
            'status'=>'applied','mode'=>Eduardo_Research_Manager_Mode::current(),'operations'=>$applied,'operation_count'=>count($applied),
            'snapshot_ids'=>array_values(array_filter(array_map(static fn(array $row): string => (string) ($row['snapshot_id'] ?? ''), $applied))),
            'verified'=>true,
        );
    }

    public function rollback(array $snapshot_ids): array|WP_Error {
        if (! current_user_can('manage_options')) {
            return new WP_Error('research_manager_forbidden', 'You are not allowed to rollback blueprint translation pairs.');
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

    private function resolve_pairs(array $blueprint): array|WP_Error {
        $normalized = $this->blueprints->validate($blueprint);
        if (is_wp_error($normalized)) { return $normalized; }
        $groups = array();
        foreach ($normalized['lines'] as $index => $record) {
            if (! is_array($record)) {
                return new WP_Error('research_manager_blueprint_pairing_record_invalid', 'Research Line translation records must be object-like arrays.', array('index'=>$index));
            }
            $key = sanitize_key((string) ($record['translation_key'] ?? ''));
            if ('' === $key) { continue; }
            $language = sanitize_key((string) ($record['language'] ?? ''));
            if (! in_array($language, array('en','es'), true)) {
                return new WP_Error('research_manager_blueprint_pairing_language_invalid', 'Blueprint translation keys require explicit EN or ES Research Line language.', array('index'=>$index));
            }
            if (isset($groups[$key][$language])) {
                return new WP_Error('research_manager_blueprint_pairing_duplicate_language', 'Each Research Line translation key may contain only one record per language.', array('translation_key'=>$key,'language'=>$language));
            }
            $slug = sanitize_title((string) ($record['slug'] ?? $record['title'] ?? ''));
            if ('' === $slug) {
                return new WP_Error('research_manager_blueprint_pairing_slug_missing', 'Paired Research Lines require a resolvable slug.', array('translation_key'=>$key,'language'=>$language));
            }
            $groups[$key][$language] = array('record'=>$record,'slug'=>$slug,'index'=>$index);
        }

        $pairs = array();
        foreach ($groups as $key => $group) {
            if (! isset($group['en'], $group['es'])) {
                return new WP_Error('research_manager_blueprint_pairing_incomplete', 'Each Research Line translation key requires exactly one English and one Spanish record.', array('translation_key'=>$key));
            }
            $en_id = $this->lines->find_by_slug($group['en']['slug'], 'en');
            $es_id = $this->lines->find_by_slug($group['es']['slug'], 'es');
            if ($en_id <= 0 || $es_id <= 0) {
                return new WP_Error('research_manager_blueprint_pairing_resource_missing', 'Research Line translation pairing requires bootstrap creation to complete first.', array('translation_key'=>$key));
            }

            $en_evidence = is_array($group['en']['record']['evidence'] ?? null) ? $group['en']['record']['evidence'] : array();
            $es_evidence = is_array($group['es']['record']['evidence'] ?? null) ? $group['es']['record']['evidence'] : array();
            $confirmed = ! empty($en_evidence['confirmed']) && ! empty($es_evidence['confirmed']);
            $en_reference = sanitize_text_field((string) ($en_evidence['reference'] ?? ''));
            $es_reference = sanitize_text_field((string) ($es_evidence['reference'] ?? ''));
            $reference = $en_reference === $es_reference ? $en_reference : trim($en_reference . ' | ' . $es_reference, ' |');
            $pairs[] = array(
                'translation_key'=>$key,'en_id'=>$en_id,'es_id'=>$es_id,
                'context'=>array('evidence_confirmed'=>$confirmed,'evidence_reference'=>$reference),
            );
        }
        return $pairs;
    }

    private function already_matching(array $pair): bool|WP_Error {
        $verification = $this->translations->verify_pair($pair['en_id'], $pair['es_id']);
        if (is_wp_error($verification)) {
            if ('research_manager_translation_pair_invalid' === $verification->get_error_code()) { return false; }
            return $verification;
        }
        if (empty($verification['verified'])) { return false; }
        $reference = sanitize_text_field((string) ($pair['context']['evidence_reference'] ?? ''));
        $en = $this->translations->inspect($pair['en_id']);
        $es = $this->translations->inspect($pair['es_id']);
        if (is_wp_error($en) || is_wp_error($es)) { return is_wp_error($en) ? $en : $es; }
        return $reference === (string) $en['evidence_reference'] && $reference === (string) $es['evidence_reference'];
    }

    private function abort(WP_Error $error, array $applied): WP_Error {
        $rollback = array();
        foreach (array_reverse($applied) as $entry) {
            $snapshot_id = (string) ($entry['snapshot_id'] ?? '');
            if ('' === $snapshot_id) { continue; }
            $result = $this->executor->rollback($snapshot_id);
            $rollback[] = is_wp_error($result)
                ? array('snapshot_id'=>$snapshot_id,'rolled_back'=>false,'error'=>$result->get_error_message())
                : array('snapshot_id'=>$snapshot_id,'rolled_back'=>true);
        }
        return new WP_Error('research_manager_blueprint_pairing_failed', $error->get_error_message(), array('source_code'=>$error->get_error_code(),'rollback'=>$rollback));
    }
}
