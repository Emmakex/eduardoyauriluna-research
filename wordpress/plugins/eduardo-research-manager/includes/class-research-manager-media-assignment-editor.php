<?php
/** Bounded assignment of an existing image attachment to Theme-owned Research resources. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Media_Assignment_Editor {
    public const SLOT = 'primary';
    public const POST_META = '_eduardo_research_primary_media_id';
    public const PAGE_OPTION_PREFIX = 'eduardo_research_media_primary_';

    private Eduardo_Research_Manager_Media_Resource $media;
    private Eduardo_Research_Manager_Snapshots $snapshots;
    private Eduardo_Research_Manager_Rendered_Verifier $rendered;

    public function __construct(
        ?Eduardo_Research_Manager_Media_Resource $media = null,
        ?Eduardo_Research_Manager_Snapshots $snapshots = null,
        ?Eduardo_Research_Manager_Rendered_Verifier $rendered = null
    ) {
        $this->media = $media ?: new Eduardo_Research_Manager_Media_Resource();
        $this->snapshots = $snapshots ?: new Eduardo_Research_Manager_Snapshots();
        $this->rendered = $rendered ?: Eduardo_Research_Manager::rendered();
    }

    public function preview_assign(array $target, int $attachment_id, string $slot = self::SLOT): array|WP_Error {
        $slot = sanitize_key($slot);
        if (self::SLOT !== $slot) {
            return $this->error('validation_failed', 'Only the Theme-owned primary Media slot is supported.', 400);
        }

        $attachment = $this->media->inspect($attachment_id);
        if (is_wp_error($attachment)) { return $attachment; }
        if ('image' !== (string) ($attachment['media_type'] ?? '')) {
            return $this->error('validation_failed', 'The primary Media slot accepts image attachments only.', 400);
        }

        $state = $this->inspect_assignment($target, $slot);
        if (is_wp_error($state)) { return $state; }
        $status = (int) $state['attachment_id'] === $attachment_id ? 'already-matching' : 'change';
        $material = array(
            'target'=>$state['target'],
            'slot'=>$slot,
            'attachment_id'=>$attachment_id,
            'target_revision'=>$state['target_revision'],
            'attachment_revision'=>$this->attachment_revision_from_record($attachment),
        );
        $checksum = hash('sha256', (string) wp_json_encode($material));
        $plan_id = 'erm-media-assignment-' . substr($checksum, 0, 20);

        return array(
            'status'=>$status,
            'slot'=>$slot,
            'attachment_id'=>$attachment_id,
            'target'=>$state['target'],
            'resource_id'=>$state['resource_id'],
            'url'=>$state['url'],
            'language'=>$state['language'],
            'semantic_scope'=>$state['semantic_scope'],
            'read_scope'=>$state['read_scope'],
            'previous_attachment_id'=>(int) $state['attachment_id'],
            'target_revision'=>(string) $state['target_revision'],
            'attachment_revision'=>$this->attachment_revision_from_record($attachment),
            'apply_allowed'=>true,
            'risk'=>'editorial-review',
            'plan_id'=>$plan_id,
            'preview'=>array(
                'plan_id'=>$plan_id,
                'intent'=>sprintf('Assign image attachment #%d to %s primary Media slot', $attachment_id, $state['resource_id']),
                'risk'=>'editorial-review',
                'apply_allowed'=>true,
                'apply_blocker'=>'',
                'actions'=>array(array(
                    'type'=>'media-assignment',
                    'resource'=>$state['resource_id'],
                    'slot'=>$slot,
                    'before'=>(int) $state['attachment_id'],
                    'after'=>$attachment_id,
                    'changed'=>'change' === $status,
                )),
            ),
        );
    }

    public function inspect_assignment(array $target, string $slot = self::SLOT): array|WP_Error {
        $slot = sanitize_key($slot);
        if (self::SLOT !== $slot) {
            return $this->error('validation_failed', 'Unsupported Media assignment slot.', 400);
        }
        $resolved = $this->resolve_target($target);
        if (is_wp_error($resolved)) { return $resolved; }
        $attachment_id = $this->read_assignment($resolved);
        $record = (array) $resolved['record'];
        $record['_media_assignment'] = array('slot'=>$slot, 'attachment_id'=>$attachment_id);

        return array_merge($resolved, array(
            'slot'=>$slot,
            'attachment_id'=>$attachment_id,
            'target_revision'=>hash('sha256', (string) wp_json_encode($record)),
        ));
    }

    public function attachment_revision(int $attachment_id): string|WP_Error {
        $attachment = $this->media->inspect($attachment_id);
        return is_wp_error($attachment) ? $attachment : $this->attachment_revision_from_record($attachment);
    }

    public function apply_preview(array $prepared): array|WP_Error {
        if (! current_user_can('manage_options')) {
            return $this->error('capability_unavailable', 'The local execution identity cannot assign Media.', 403);
        }
        $target = is_array($prepared['target'] ?? null) ? $prepared['target'] : array();
        $attachment_id = absint($prepared['attachment_id'] ?? 0);
        $slot = sanitize_key((string) ($prepared['slot'] ?? ''));
        $expected_target_revision = sanitize_text_field((string) ($prepared['target_revision'] ?? ''));
        $expected_attachment_revision = sanitize_text_field((string) ($prepared['attachment_revision'] ?? ''));
        if (! $target || $attachment_id <= 0 || self::SLOT !== $slot || '' === $expected_target_revision || '' === $expected_attachment_revision) {
            return $this->error('validation_failed', 'Prepared Media assignment Preview is incomplete.', 409);
        }

        $current = $this->inspect_assignment($target, $slot);
        if (is_wp_error($current)) { return $current; }
        if (! hash_equals($expected_target_revision, (string) $current['target_revision'])) {
            return $this->error('stale_revision', 'The target resource changed after Preview. Create a fresh Media assignment plan.', 409);
        }
        $current_attachment_revision = $this->attachment_revision($attachment_id);
        if (is_wp_error($current_attachment_revision)) { return $current_attachment_revision; }
        if (! hash_equals($expected_attachment_revision, $current_attachment_revision)) {
            return $this->error('stale_revision', 'The Media attachment changed after Preview. Create a fresh Media assignment plan.', 409);
        }

        if ('already-matching' === (string) ($prepared['status'] ?? '')) {
            $verification = $this->verify($target, $attachment_id, $slot, false);
            if (is_wp_error($verification)) { return $verification; }
            return array(
                'status'=>'already-matching',
                'snapshot_id'=>'',
                'target'=>$current['target'],
                'attachment_id'=>$attachment_id,
                'slot'=>$slot,
                'post_apply_target_revision'=>(string) $current['target_revision'],
                'post_apply_attachment_revision'=>$current_attachment_revision,
                'verification'=>$verification,
                'verified'=>! empty($verification['verified']),
            );
        }

        $snapshot_plan = array(
            'id'=>(string) ($prepared['plan_id'] ?? ''),
            'checksum'=>hash('sha256', (string) wp_json_encode(array($target, $slot, $attachment_id, $expected_target_revision))),
            'intent'=>sprintf('Rollback boundary for %s Media assignment', (string) $current['resource_id']),
        );
        $snapshot_id = $this->snapshots->create($snapshot_plan, array(
            'type'=>'media-assignment',
            'target'=>$current['target'],
            'slot'=>$slot,
            'attachment_id'=>(int) $current['attachment_id'],
            'target_revision'=>(string) $current['target_revision'],
        ));

        $written = $this->write_assignment($current, $attachment_id);
        if (is_wp_error($written)) { return $written; }
        $after = $this->inspect_assignment($target, $slot);
        if (is_wp_error($after)) { return $after; }
        if ((int) $after['attachment_id'] !== $attachment_id) {
            $this->restore_snapshot($snapshot_id);
            return $this->error('validation_failed', 'Media assignment failed stored verification and was rolled back.', 409);
        }

        return array(
            'status'=>'applied',
            'snapshot_id'=>$snapshot_id,
            'target'=>$after['target'],
            'resource_id'=>$after['resource_id'],
            'attachment_id'=>$attachment_id,
            'slot'=>$slot,
            'post_apply_target_revision'=>(string) $after['target_revision'],
            'post_apply_attachment_revision'=>$current_attachment_revision,
            'verified'=>true,
        );
    }

    public function verify(array $target, int $attachment_id, string $slot = self::SLOT, bool $rendered = true): array|WP_Error {
        $state = $this->inspect_assignment($target, $slot);
        if (is_wp_error($state)) { return $state; }
        $stored = (int) $state['attachment_id'] === $attachment_id;
        $attachment = $this->media->inspect($attachment_id);
        if (is_wp_error($attachment)) { return $attachment; }

        $rendered_state = array('requested'=>$rendered, 'verified'=>null);
        if ($rendered) {
            $public = 'publish' === (string) ($state['record']['status'] ?? '');
            if (! $public) {
                return $this->error('validation_failed', 'Rendered Media assignment verification requires a published target resource.', 409);
            }
            $rendered_state = $this->rendered->verify_media_assignment(
                (string) $state['url'],
                (string) $state['language'],
                $attachment_id,
                $slot
            );
            if (is_wp_error($rendered_state)) { return $rendered_state; }
        }

        return array(
            'verified'=>$stored && (! $rendered || ! empty($rendered_state['verified'])),
            'stored_verified'=>$stored,
            'rendered_verification'=>$rendered_state,
            'target'=>$state['target'],
            'resource_id'=>$state['resource_id'],
            'attachment_id'=>$attachment_id,
            'slot'=>$slot,
            'target_revision'=>$state['target_revision'],
            'attachment_revision'=>$this->attachment_revision_from_record($attachment),
            'verified_at'=>gmdate(DATE_W3C),
        );
    }

    public function rollback_snapshot(
        string $snapshot_id,
        string $expected_target_revision,
        string $expected_attachment_revision
    ): array|WP_Error {
        if (! current_user_can('manage_options')) {
            return $this->error('capability_unavailable', 'The local execution identity cannot rollback Media assignment.', 403);
        }
        $snapshot = $this->snapshots->get(sanitize_text_field($snapshot_id));
        $before = is_array($snapshot['before'] ?? null) ? $snapshot['before'] : array();
        if ('media-assignment' !== (string) ($before['type'] ?? '')) {
            return $this->error('rollback_unavailable', 'Snapshot is not a Media assignment rollback boundary.', 409);
        }
        $target = is_array($before['target'] ?? null) ? $before['target'] : array();
        $slot = sanitize_key((string) ($before['slot'] ?? ''));
        $current = $this->inspect_assignment($target, $slot);
        if (is_wp_error($current)) { return $current; }
        if ('' === $expected_target_revision || ! hash_equals($expected_target_revision, (string) $current['target_revision'])) {
            return $this->error('stale_revision', 'The target resource changed after Media Apply. Rollback is blocked to protect newer changes.', 409);
        }
        $current_attachment_id = (int) $current['attachment_id'];
        if ($current_attachment_id <= 0) {
            return $this->error('stale_revision', 'The Media relationship changed after Apply. Rollback is blocked.', 409);
        }
        $current_attachment_revision = $this->attachment_revision($current_attachment_id);
        if (is_wp_error($current_attachment_revision)) { return $current_attachment_revision; }
        if ('' === $expected_attachment_revision || ! hash_equals($expected_attachment_revision, $current_attachment_revision)) {
            return $this->error('stale_revision', 'The assigned Media attachment changed after Apply. Rollback is blocked to protect newer Media state.', 409);
        }

        $result = $this->restore_snapshot($snapshot_id);
        if (is_wp_error($result)) { return $result; }
        return $result;
    }

    public function required_scopes(array $target): array|WP_Error {
        $resolved = $this->resolve_target($target);
        if (is_wp_error($resolved)) { return $resolved; }
        return array('write'=>(string) $resolved['semantic_scope'], 'read'=>(string) $resolved['read_scope']);
    }

    private function restore_snapshot(string $snapshot_id): array|WP_Error {
        $snapshot = $this->snapshots->get(sanitize_text_field($snapshot_id));
        $before = is_array($snapshot['before'] ?? null) ? $snapshot['before'] : array();
        if ('media-assignment' !== (string) ($before['type'] ?? '')) {
            return $this->error('rollback_unavailable', 'Media assignment snapshot is unavailable.', 409);
        }
        $target = is_array($before['target'] ?? null) ? $before['target'] : array();
        $slot = sanitize_key((string) ($before['slot'] ?? ''));
        $state = $this->inspect_assignment($target, $slot);
        if (is_wp_error($state)) { return $state; }
        $previous = absint($before['attachment_id'] ?? 0);
        $written = $this->write_assignment($state, $previous);
        if (is_wp_error($written)) { return $written; }
        $restored = $this->inspect_assignment($target, $slot);
        if (is_wp_error($restored)) { return $restored; }
        if ((int) $restored['attachment_id'] !== $previous) {
            return $this->error('rollback_unavailable', 'Media assignment rollback verification failed.', 409);
        }
        $this->snapshots->mark_rolled_back($snapshot_id);
        return array(
            'status'=>'rolled-back',
            'snapshot_id'=>$snapshot_id,
            'target'=>$restored['target'],
            'resource_id'=>$restored['resource_id'],
            'slot'=>$slot,
            'attachment_id'=>$previous,
            'restored_target_revision'=>(string) $restored['target_revision'],
            'verified'=>true,
        );
    }

    private function resolve_target(array $target): array|WP_Error {
        $type = sanitize_key((string) ($target['type'] ?? ''));
        if ('page' === $type) {
            $key = sanitize_key((string) ($target['key'] ?? ''));
            $language = sanitize_key((string) ($target['language'] ?? 'en'));
            $record = Eduardo_Research_Manager::pages()->inspect($key, $language);
            if (is_wp_error($record)) { return $record; }
            return array(
                'target'=>array('type'=>'page','key'=>$key,'language'=>$language),
                'resource_id'=>'page:' . $key . ':' . $language,
                'record'=>$record,
                'post_id'=>(int) $record['page_id'],
                'url'=>(string) $record['url'],
                'language'=>$language,
                'storage'=>'option',
                'storage_key'=>self::PAGE_OPTION_PREFIX . $key . '_' . $language,
                'semantic_scope'=>'pages.write',
                'read_scope'=>'pages.read',
            );
        }

        $post_id = absint($target['post_id'] ?? 0);
        if ('insight' === $type) {
            $record = Eduardo_Research_Manager::insights()->inspect($post_id);
            if (is_wp_error($record)) { return $record; }
            return array(
                'target'=>array('type'=>'insight','post_id'=>$post_id),
                'resource_id'=>'insight:' . $post_id,
                'record'=>$record,
                'post_id'=>$post_id,
                'url'=>(string) $record['url'],
                'language'=>(string) $record['language'],
                'storage'=>'post_meta',
                'storage_key'=>self::POST_META,
                'semantic_scope'=>'insights.write',
                'read_scope'=>'insights.read',
            );
        }

        if ('research' === $type) {
            $kind = sanitize_key((string) ($target['kind'] ?? ''));
            if ('line' === $kind) {
                $record = Eduardo_Research_Manager::lines()->inspect($post_id);
            } else {
                $record = Eduardo_Research_Manager::object_editor()->inspect($kind, $post_id);
            }
            if (is_wp_error($record)) { return $record; }
            if (! in_array($kind, array('line','output','project','software','dataset'), true)) {
                return $this->error('validation_failed', 'Unsupported Research Media assignment target kind.', 400);
            }
            return array(
                'target'=>array('type'=>'research','kind'=>$kind,'post_id'=>$post_id),
                'resource_id'=>'research:' . $kind . ':' . $post_id,
                'record'=>$record,
                'post_id'=>$post_id,
                'url'=>(string) $record['url'],
                'language'=>(string) $record['language'],
                'storage'=>'post_meta',
                'storage_key'=>self::POST_META,
                'semantic_scope'=>'research.write',
                'read_scope'=>'research.read',
            );
        }

        return $this->error('validation_failed', 'Media assignment target type must be page, insight or research.', 400);
    }

    private function read_assignment(array $resolved): int {
        if ('option' === (string) $resolved['storage']) {
            return absint(get_option((string) $resolved['storage_key'], 0));
        }
        return absint(get_post_meta((int) $resolved['post_id'], (string) $resolved['storage_key'], true));
    }

    private function write_assignment(array $resolved, int $attachment_id): bool|WP_Error {
        if ($attachment_id > 0) {
            $attachment = $this->media->inspect($attachment_id);
            if (is_wp_error($attachment)) { return $attachment; }
            if ('image' !== (string) ($attachment['media_type'] ?? '')) {
                return $this->error('validation_failed', 'Primary Media assignment accepts image attachments only.', 400);
            }
        }
        if ('option' === (string) $resolved['storage']) {
            if ($attachment_id > 0) { update_option((string) $resolved['storage_key'], $attachment_id, false); }
            else { delete_option((string) $resolved['storage_key']); }
            return true;
        }
        if ($attachment_id > 0) { update_post_meta((int) $resolved['post_id'], (string) $resolved['storage_key'], $attachment_id); }
        else { delete_post_meta((int) $resolved['post_id'], (string) $resolved['storage_key']); }
        clean_post_cache((int) $resolved['post_id']);
        return true;
    }

    private function attachment_revision_from_record(array $attachment): string {
        return hash('sha256', (string) wp_json_encode($attachment));
    }

    private function error(string $code, string $message, int $status): WP_Error {
        return new WP_Error($code, $message, array('status'=>$status));
    }
}
