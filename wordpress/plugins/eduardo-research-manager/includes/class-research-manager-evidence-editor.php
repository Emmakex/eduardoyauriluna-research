<?php
/** Controlled Preview → Apply → Verify → Rollback service for academic identity and verified evidence. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Evidence_Editor {
    private Eduardo_Research_Manager_Executor $executor;

    public function __construct(?Eduardo_Research_Manager_Executor $executor = null) {
        $this->executor = $executor ?: Eduardo_Research_Manager::executor();
    }

    public function groups(): array {
        return array(
            'profile'=>array('label'=>'Research profile','surfaces'=>array('about')),
            'affiliations'=>array('label'=>'Affiliations','surfaces'=>array('about','cv')),
            'identifiers'=>array('label'=>'Academic identifiers','surfaces'=>array('about','contact')),
            'research_questions'=>array('label'=>'Research questions','surfaces'=>array('research')),
            'methods'=>array('label'=>'Research methods','surfaces'=>array('research')),
            'experience'=>array('label'=>'Experience','surfaces'=>array('cv')),
            'education'=>array('label'=>'Education','surfaces'=>array('cv')),
            'awards'=>array('label'=>'Awards','surfaces'=>array('cv')),
            'contact'=>array('label'=>'Research contact','surfaces'=>array('contact')),
        );
    }

    public function identity(): array|WP_Error {
        $stored = get_option('eduardo_research_identity', array());
        if (! is_array($stored)) {
            return new WP_Error('research_manager_identity_invalid', 'The academic identity option has an invalid shape.');
        }
        $name = isset($stored['name']) && is_scalar($stored['name']) && '' !== trim((string) $stored['name'])
            ? trim((string) $stored['name'])
            : trim((string) get_bloginfo('name'));
        return array(
            'name'=>$name,
            'url'=>home_url('/'),
            'evidence_reference'=>$this->scalar($stored['evidence_reference'] ?? ''),
            'verified_at'=>$this->scalar($stored['verified_at'] ?? ''),
            'stored'=>$stored,
        );
    }

    public function store(): array|WP_Error {
        $store = get_option('eduardo_research_evidence', array());
        if (! is_array($store)) {
            return new WP_Error('research_manager_evidence_store_invalid', 'The Research evidence store has an invalid shape.');
        }
        return $store;
    }

    public function records(string $group): array|WP_Error {
        $group = sanitize_key($group);
        if (! isset($this->groups()[$group])) {
            return new WP_Error('research_manager_evidence_group_unknown', 'Unsupported Research evidence group.');
        }
        $store = $this->store();
        if (is_wp_error($store)) { return $store; }
        $records = is_array($store[$group] ?? null) ? $store[$group] : array();
        $result = array();
        foreach ($records as $index => $record) {
            if (! is_array($record)) { continue; }
            $record['record_id'] = $this->record_id($record, (int) $index);
            $record['index'] = (int) $index;
            $result[] = $record;
        }
        return $result;
    }

    public function inspect_record(string $group, string $record_id): array|WP_Error {
        $records = $this->records($group);
        if (is_wp_error($records)) { return $records; }
        $record_id = sanitize_key($record_id);
        foreach ($records as $record) {
            if ($record_id === (string) ($record['record_id'] ?? '')) { return $record; }
        }
        return new WP_Error('research_manager_evidence_record_missing', 'The requested Research evidence record does not exist.');
    }

    public function preview_identity(
        string $name,
        bool $evidence_confirmed = false,
        string $evidence_reference = ''
    ): array|WP_Error {
        $identity = $this->identity();
        if (is_wp_error($identity)) { return $identity; }
        $store = $this->store();
        if (is_wp_error($store)) { return $store; }
        $name = sanitize_text_field($name);
        if ('' === trim($name)) {
            return new WP_Error('research_manager_identity_name_required', 'Academic identity requires a non-empty researcher name.');
        }
        $reference = sanitize_text_field($evidence_reference);
        $current = is_array($identity['stored'] ?? null) ? $identity['stored'] : array();
        $next = $current;
        $next['name'] = $name;
        if ('' !== $reference) { $next['evidence_reference'] = $reference; }
        if ($evidence_confirmed && '' !== $reference) { $next['verified_at'] = gmdate(DATE_W3C); }

        $baseline = $this->identity_baseline_checksum($current, $store);
        if ($this->same($current, $next)) {
            return array(
                'resource'=>'identity','operation'=>'update','status'=>'already-matching','apply_allowed'=>true,'apply_blocker'=>'',
                'risk'=>'evidence-required','plan'=>array(),'plan_id'=>'','baseline_checksum'=>$baseline,
                'expected'=>$next,'verification'=>$this->verify_identity($next),
            );
        }

        // The evidence-store no-op keeps academic identity mutations inside the same evidence-required gate.
        $plan = Eduardo_Research_Manager_Plan::create(
            'Update verified academic identity',
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
            'resource'=>'identity','operation'=>'update','status'=>'change',
            'apply_allowed'=>! empty($preview['apply_allowed']),'apply_blocker'=>(string) ($preview['apply_blocker'] ?? ''),
            'risk'=>(string) ($preview['risk'] ?? $plan['risk'] ?? ''),'plan'=>$plan,'plan_id'=>(string) ($plan['id'] ?? ''),
            'preview'=>$preview,'baseline_checksum'=>$baseline,'expected'=>$next,
        );
    }

    public function preview_record(
        string $group,
        string $record_id,
        array $data,
        bool $evidence_confirmed = false,
        string $evidence_reference = ''
    ): array|WP_Error {
        $group = sanitize_key($group);
        if (! isset($this->groups()[$group])) {
            return new WP_Error('research_manager_evidence_group_unknown', 'Unsupported Research evidence group.');
        }
        $store = $this->store();
        if (is_wp_error($store)) { return $store; }
        $records = is_array($store[$group] ?? null) ? $store[$group] : array();
        $baseline = $this->store_checksum($store);
        $record_id = sanitize_key($record_id);
        $index = '' !== $record_id ? $this->find_record_index($records, $record_id) : -1;
        if ('' !== $record_id && $index < 0) {
            return new WP_Error('research_manager_evidence_record_missing', 'The requested Research evidence record does not exist.');
        }
        $existing = $index >= 0 && is_array($records[$index] ?? null) ? $records[$index] : array();
        if ('' === $record_id) { $record_id = 'evidence-' . str_replace('-', '', wp_generate_uuid4()); }
        $reference = sanitize_text_field($evidence_reference);
        $normalized = $this->normalize_record($record_id, $data, $existing, $reference, $evidence_confirmed);
        if (is_wp_error($normalized)) { return $normalized; }

        if ($index >= 0 && $this->same($existing, $normalized)) {
            return array(
                'resource'=>'evidence','operation'=>'update','status'=>'already-matching','group'=>$group,'record_id'=>$record_id,
                'apply_allowed'=>true,'apply_blocker'=>'','risk'=>'evidence-required','plan'=>array(),'plan_id'=>'',
                'baseline_checksum'=>$baseline,'expected'=>$normalized,'verification'=>$this->verify_record($group, $record_id, $normalized),
            );
        }

        if ($index >= 0) { $records[$index] = $normalized; }
        else { $records[] = $normalized; }
        $next_store = $store;
        $next_store[$group] = array_values($records);
        $operation = $index >= 0 ? 'update' : 'create';
        $plan = Eduardo_Research_Manager_Plan::create(
            sprintf('%s verified Research evidence: %s', ucfirst($operation), $group),
            array(array('type'=>'option','key'=>'eduardo_research_evidence','value'=>$next_store)),
            array('evidence_confirmed'=>$evidence_confirmed,'evidence_reference'=>$reference)
        );
        if (is_wp_error($plan)) { return $plan; }
        $preview = $this->executor->preview($plan);
        if (is_wp_error($preview)) { return $preview; }
        return array(
            'resource'=>'evidence','operation'=>$operation,'status'=>'change','group'=>$group,'record_id'=>$record_id,
            'apply_allowed'=>! empty($preview['apply_allowed']),'apply_blocker'=>(string) ($preview['apply_blocker'] ?? ''),
            'risk'=>(string) ($preview['risk'] ?? $plan['risk'] ?? ''),'plan'=>$plan,'plan_id'=>(string) ($plan['id'] ?? ''),
            'preview'=>$preview,'baseline_checksum'=>$baseline,'expected'=>$normalized,
        );
    }

    public function preview_delete_record(
        string $group,
        string $record_id,
        bool $evidence_confirmed = false,
        string $evidence_reference = ''
    ): array|WP_Error {
        $group = sanitize_key($group);
        if (! isset($this->groups()[$group])) {
            return new WP_Error('research_manager_evidence_group_unknown', 'Unsupported Research evidence group.');
        }
        $store = $this->store();
        if (is_wp_error($store)) { return $store; }
        $records = is_array($store[$group] ?? null) ? $store[$group] : array();
        $record_id = sanitize_key($record_id);
        $index = $this->find_record_index($records, $record_id);
        if ($index < 0) {
            return new WP_Error('research_manager_evidence_record_missing', 'The requested Research evidence record does not exist.');
        }
        $baseline = $this->store_checksum($store);
        $deleted = is_array($records[$index] ?? null) ? $records[$index] : array();
        array_splice($records, $index, 1);
        $next_store = $store;
        $next_store[$group] = array_values($records);
        $reference = sanitize_text_field($evidence_reference);
        $plan = Eduardo_Research_Manager_Plan::create(
            sprintf('Delete verified Research evidence: %s', $group),
            array(array('type'=>'option','key'=>'eduardo_research_evidence','value'=>$next_store)),
            array('evidence_confirmed'=>$evidence_confirmed,'evidence_reference'=>$reference)
        );
        if (is_wp_error($plan)) { return $plan; }
        $preview = $this->executor->preview($plan);
        if (is_wp_error($preview)) { return $preview; }
        return array(
            'resource'=>'evidence','operation'=>'delete','status'=>'change','group'=>$group,'record_id'=>$record_id,
            'apply_allowed'=>! empty($preview['apply_allowed']),'apply_blocker'=>(string) ($preview['apply_blocker'] ?? ''),
            'risk'=>(string) ($preview['risk'] ?? $plan['risk'] ?? ''),'plan'=>$plan,'plan_id'=>(string) ($plan['id'] ?? ''),
            'preview'=>$preview,'baseline_checksum'=>$baseline,'expected'=>array(),'deleted'=>$deleted,
        );
    }

    public function apply_preview(array $prepared): array|WP_Error {
        if (! current_user_can('manage_options')) {
            return new WP_Error('research_manager_forbidden', 'You are not allowed to apply academic identity or Research evidence changes.');
        }
        $resource = sanitize_key((string) ($prepared['resource'] ?? ''));
        if (! in_array($resource, array('identity','evidence'), true)) {
            return new WP_Error('research_manager_evidence_preview_invalid', 'Prepared academic evidence Preview has an invalid resource type.');
        }
        $baseline = sanitize_text_field((string) ($prepared['baseline_checksum'] ?? ''));
        if ('' === $baseline || ! hash_equals($baseline, $this->current_baseline_checksum($resource))) {
            return new WP_Error('research_manager_evidence_stale_preview', 'Academic identity or evidence changed after Preview. Prepare a new Preview before Apply.');
        }

        if ('already-matching' === (string) ($prepared['status'] ?? '')) {
            $verification = $this->semantic_verification($prepared);
            if (is_wp_error($verification)) { return $verification; }
            if (empty($verification['verified'])) {
                return new WP_Error('research_manager_evidence_verification_failed', 'Current academic identity or evidence no longer matches the prepared Preview.');
            }
            return array('status'=>'already-matching','resource'=>$resource,'operation'=>(string) ($prepared['operation'] ?? ''),'snapshot_id'=>'','verification'=>$verification,'verified'=>true);
        }

        if (empty($prepared['apply_allowed']) || ! is_array($prepared['plan'] ?? null) || ! $prepared['plan']) {
            return new WP_Error(
                'research_manager_evidence_apply_blocked',
                '' !== trim((string) ($prepared['apply_blocker'] ?? ''))
                    ? (string) $prepared['apply_blocker']
                    : 'The prepared academic evidence Preview is not allowed to apply.'
            );
        }

        $result = $this->executor->apply($prepared['plan']);
        if (is_wp_error($result)) { return $result; }
        $generic = $this->executor->verify($prepared['plan']);
        $semantic = $this->semantic_verification($prepared);
        $verified = ! is_wp_error($generic) && ! empty($generic['verified']) && ! is_wp_error($semantic) && ! empty($semantic['verified']);
        if (! $verified) {
            $snapshot_id = (string) ($result['snapshot_id'] ?? '');
            if ('' !== $snapshot_id) { $this->executor->rollback($snapshot_id); }
            return is_wp_error($generic)
                ? $generic
                : (is_wp_error($semantic)
                    ? $semantic
                    : new WP_Error('research_manager_evidence_verification_failed', 'Academic identity or evidence failed post-Apply verification.'));
        }

        return array(
            'status'=>(string) ($result['status'] ?? 'applied'),'resource'=>$resource,'operation'=>(string) ($prepared['operation'] ?? ''),
            'group'=>(string) ($prepared['group'] ?? ''),'record_id'=>(string) ($prepared['record_id'] ?? ''),
            'plan_id'=>(string) ($prepared['plan_id'] ?? ''),'snapshot_id'=>(string) ($result['snapshot_id'] ?? ''),
            'verification'=>$semantic,'verified'=>true,
        );
    }

    public function rollback(string $snapshot_id): array|WP_Error {
        if (! current_user_can('manage_options')) {
            return new WP_Error('research_manager_forbidden', 'You are not allowed to rollback academic identity or Research evidence changes.');
        }
        $snapshot_id = sanitize_text_field($snapshot_id);
        if ('' === $snapshot_id) {
            return new WP_Error('research_manager_evidence_snapshot_missing', 'An academic evidence snapshot ID is required for rollback.');
        }
        return $this->executor->rollback($snapshot_id);
    }

    public function verify_identity(array $expected): array|WP_Error {
        $identity = $this->identity();
        if (is_wp_error($identity)) { return $identity; }
        $stored = is_array($identity['stored'] ?? null) ? $identity['stored'] : array();
        $checks = array();
        foreach ($expected as $key => $value) {
            $checks[$key] = $this->same($stored[$key] ?? null, $value);
        }
        return array('verified'=>! in_array(false, $checks, true),'checks'=>$checks,'verified_at'=>gmdate(DATE_W3C));
    }

    public function verify_record(string $group, string $record_id, array $expected): array|WP_Error {
        $record = $this->inspect_record($group, $record_id);
        if (is_wp_error($record)) { return $record; }
        unset($record['record_id'], $record['index']);
        $checks = array('record'=>$this->same($record, $expected));
        return array('verified'=>! in_array(false, $checks, true),'checks'=>$checks,'verified_at'=>gmdate(DATE_W3C));
    }

    private function semantic_verification(array $prepared): array|WP_Error {
        if ('identity' === (string) ($prepared['resource'] ?? '')) {
            return $this->verify_identity(is_array($prepared['expected'] ?? null) ? $prepared['expected'] : array());
        }
        $group = sanitize_key((string) ($prepared['group'] ?? ''));
        $record_id = sanitize_key((string) ($prepared['record_id'] ?? ''));
        if ('delete' === (string) ($prepared['operation'] ?? '')) {
            $record = $this->inspect_record($group, $record_id);
            if (is_wp_error($record) && 'research_manager_evidence_record_missing' === $record->get_error_code()) {
                return array('verified'=>true,'checks'=>array('record_absent'=>true),'verified_at'=>gmdate(DATE_W3C));
            }
            return is_wp_error($record)
                ? $record
                : array('verified'=>false,'checks'=>array('record_absent'=>false),'verified_at'=>gmdate(DATE_W3C));
        }
        return $this->verify_record($group, $record_id, is_array($prepared['expected'] ?? null) ? $prepared['expected'] : array());
    }

    private function normalize_record(
        string $record_id,
        array $data,
        array $existing,
        string $evidence_reference,
        bool $evidence_confirmed
    ): array|WP_Error {
        $record = $existing;
        $record['id'] = sanitize_key($record_id);
        if ('' === $record['id']) {
            return new WP_Error('research_manager_evidence_record_id_invalid', 'Research evidence requires a stable record ID.');
        }
        $status = sanitize_key($this->scalar($data['status'] ?? ($existing['status'] ?? 'unverified')));
        if (! in_array($status, array('verified','unverified'), true)) {
            return new WP_Error('research_manager_evidence_status_invalid', 'Research evidence status must be verified or unverified.');
        }
        $record['status'] = $status;

        foreach ($this->text_fields() as $field) {
            if (array_key_exists($field, $data)) { $record[$field] = sanitize_text_field($this->scalar($data[$field])); }
        }
        if (array_key_exists('summary', $data)) { $record['summary'] = sanitize_textarea_field($this->scalar($data['summary'])); }
        if (array_key_exists('url', $data)) { $record['url'] = esc_url_raw($this->scalar($data['url'])); }

        if (array_key_exists('translations', $data)) {
            if (! is_array($data['translations'])) {
                return new WP_Error('research_manager_evidence_translations_invalid', 'Evidence translations must be an object keyed by language.');
            }
            $translations = array();
            foreach (array('es') as $language) {
                if (! isset($data['translations'][$language])) { continue; }
                if (! is_array($data['translations'][$language])) {
                    return new WP_Error('research_manager_evidence_translation_invalid', 'Each evidence translation must be a structured object.');
                }
                $translations[$language] = $this->normalize_translation($data['translations'][$language]);
            }
            $record['translations'] = $translations;
        }

        if ('' !== $evidence_reference) { $record['evidence_reference'] = $evidence_reference; }
        if ($evidence_confirmed && '' !== $evidence_reference) { $record['verified_at'] = gmdate(DATE_W3C); }
        if ('verified' === $status && '' === trim($this->scalar($record['evidence_reference'] ?? ''))) {
            // Keep the record previewable, but it remains impossible to Apply without a referenced verification source.
            $record['evidence_reference'] = '';
        }

        if (! $this->record_has_public_content($record)) {
            return new WP_Error('research_manager_evidence_record_empty', 'Research evidence requires at least a title, label, value, summary or URL.');
        }
        return $record;
    }

    private function normalize_translation(array $translation): array {
        $result = array();
        foreach ($this->text_fields() as $field) {
            if (array_key_exists($field, $translation)) { $result[$field] = sanitize_text_field($this->scalar($translation[$field])); }
        }
        if (array_key_exists('summary', $translation)) { $result['summary'] = sanitize_textarea_field($this->scalar($translation['summary'])); }
        if (array_key_exists('url', $translation)) { $result['url'] = esc_url_raw($this->scalar($translation['url'])); }
        return $result;
    }

    private function text_fields(): array {
        return array('title','label','value','period','date','dates','start_date','end_date','organization','institution','company','affiliation');
    }

    private function record_has_public_content(array $record): bool {
        foreach (array('title','label','value','summary','url') as $field) {
            if ('' !== trim($this->scalar($record[$field] ?? ''))) { return true; }
        }
        return false;
    }

    private function record_id(array $record, int $index): string {
        $stored = sanitize_key($this->scalar($record['id'] ?? ''));
        if ('' !== $stored) { return $stored; }
        return 'legacy-' . substr(hash('sha256', (string) wp_json_encode($record) . '|' . $index), 0, 16);
    }

    private function find_record_index(array $records, string $record_id): int {
        foreach ($records as $index => $record) {
            if (! is_array($record)) { continue; }
            if ($record_id === $this->record_id($record, (int) $index)) { return (int) $index; }
        }
        return -1;
    }

    private function current_baseline_checksum(string $resource): string {
        $store = get_option('eduardo_research_evidence', array());
        $store = is_array($store) ? $store : array();
        if ('identity' === $resource) {
            $identity = get_option('eduardo_research_identity', array());
            return $this->identity_baseline_checksum(is_array($identity) ? $identity : array(), $store);
        }
        return $this->store_checksum($store);
    }

    private function identity_baseline_checksum(array $identity, array $store): string {
        return hash('sha256', (string) wp_json_encode(array('identity'=>$identity,'evidence'=>$store)));
    }

    private function store_checksum(array $store): string {
        return hash('sha256', (string) wp_json_encode($store));
    }

    private function same(mixed $left, mixed $right): bool {
        return maybe_serialize($left) === maybe_serialize($right);
    }

    private function scalar(mixed $value): string {
        return is_scalar($value) ? (string) $value : '';
    }
}
