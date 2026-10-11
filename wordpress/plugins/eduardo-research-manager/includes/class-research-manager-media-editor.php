<?php
/** Bounded Preview → Apply → Verify → Rollback editor for Media metadata. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Media_Editor {
    public const FIELDS = array('title', 'alt_text', 'caption', 'description');

    private Eduardo_Research_Manager_Media_Resource $media;
    private Eduardo_Research_Manager_Snapshots $snapshots;

    public function __construct(
        ?Eduardo_Research_Manager_Media_Resource $media = null,
        ?Eduardo_Research_Manager_Snapshots $snapshots = null
    ) {
        $this->media = $media ?: new Eduardo_Research_Manager_Media_Resource();
        $this->snapshots = $snapshots ?: new Eduardo_Research_Manager_Snapshots();
    }

    public function preview_update(int $attachment_id, array $changes): array|WP_Error {
        $current = $this->media->inspect($attachment_id);
        if (is_wp_error($current)) { return $current; }

        $normalised = $this->normalise_changes($changes, $current);
        if (is_wp_error($normalised)) { return $normalised; }
        if (! $normalised) {
            return new WP_Error('validation_failed', 'media-metadata-update requires at least one supported metadata field.', array('status'=>400));
        }

        $baseline = $this->state_checksum($current);
        $effective = array();
        $actions = array();
        foreach ($normalised as $field => $value) {
            $before = (string) ($current[$field] ?? '');
            if ($before === $value) { continue; }
            $effective[$field] = $value;
            $actions[] = array(
                'field'=>$field,
                'before'=>$before,
                'after'=>$value,
                'changed'=>true,
                'risk'=>'editorial-review',
            );
        }

        $status = $effective ? 'change' : 'already-matching';
        $material = array(
            'attachment_id'=>$attachment_id,
            'changes'=>$normalised,
            'baseline_checksum'=>$baseline,
        );
        $checksum = hash('sha256', (string) wp_json_encode($material));
        $plan_id = 'erm-media-metadata-' . substr($checksum, 0, 20);

        return array(
            'mode'=>'update',
            'status'=>$status,
            'attachment_id'=>$attachment_id,
            'expected'=>$normalised,
            'effective_changes'=>$effective,
            'baseline_checksum'=>$baseline,
            'apply_allowed'=>true,
            'risk'=>'editorial-review',
            'plan_id'=>$plan_id,
            'preview'=>array(
                'plan_id'=>$plan_id,
                'intent'=>sprintf('Update bounded Media metadata for attachment #%d', $attachment_id),
                'risk'=>'editorial-review',
                'apply_allowed'=>true,
                'apply_blocker'=>'',
                'actions'=>$actions,
            ),
        );
    }

    public function apply_preview(array $prepared): array|WP_Error {
        if (! current_user_can('manage_options')) {
            return new WP_Error('capability_unavailable', 'The local WordPress execution identity cannot update Media metadata.', array('status'=>403));
        }

        $attachment_id = absint($prepared['attachment_id'] ?? 0);
        $expected = is_array($prepared['expected'] ?? null) ? $prepared['expected'] : array();
        $baseline = sanitize_text_field((string) ($prepared['baseline_checksum'] ?? ''));
        $status = sanitize_key((string) ($prepared['status'] ?? ''));
        if ($attachment_id <= 0 || ! $expected || '' === $baseline || ! in_array($status, array('change','already-matching'), true)) {
            return new WP_Error('validation_failed', 'Prepared Media metadata Preview is incomplete.', array('status'=>409));
        }

        $current = $this->media->inspect($attachment_id);
        if (is_wp_error($current)) { return $current; }
        if (! hash_equals($baseline, $this->state_checksum($current))) {
            return new WP_Error('stale_revision', 'The Media attachment changed after Preview. Create a fresh plan before Apply.', array('status'=>409));
        }

        if ('already-matching' === $status) {
            $verification = $this->verify($attachment_id, $expected);
            if (is_wp_error($verification)) { return $verification; }
            return array(
                'status'=>'already-matching',
                'attachment_id'=>$attachment_id,
                'snapshot_id'=>'',
                'verification'=>$verification,
                'verified'=>! empty($verification['verified']),
                'post_apply_revision'=>(string) ($verification['revision'] ?? ''),
            );
        }

        $snapshot_plan = array(
            'id'=>(string) ($prepared['plan_id'] ?? ''),
            'checksum'=>hash('sha256', (string) wp_json_encode(array($attachment_id, $expected, $baseline))),
            'intent'=>sprintf('Rollback boundary for Media metadata attachment #%d', $attachment_id),
        );
        $before = array(
            'type'=>'media-metadata',
            'attachment_id'=>$attachment_id,
            'state'=>$this->managed_state($current),
            'baseline_checksum'=>$baseline,
        );
        $snapshot_id = $this->snapshots->create($snapshot_plan, $before);

        $post_update = array('ID'=>$attachment_id);
        if (array_key_exists('title', $expected)) { $post_update['post_title'] = $expected['title']; }
        if (array_key_exists('caption', $expected)) { $post_update['post_excerpt'] = $expected['caption']; }
        if (array_key_exists('description', $expected)) { $post_update['post_content'] = $expected['description']; }
        if (count($post_update) > 1) {
            $updated = wp_update_post(wp_slash($post_update), true);
            if (is_wp_error($updated)) {
                return new WP_Error('validation_failed', $updated->get_error_message(), array('status'=>409));
            }
        }
        if (array_key_exists('alt_text', $expected)) {
            update_post_meta($attachment_id, '_wp_attachment_image_alt', $expected['alt_text']);
        }

        clean_post_cache($attachment_id);
        $verification = $this->verify($attachment_id, $expected);
        if (is_wp_error($verification) || empty($verification['verified'])) {
            $this->rollback($snapshot_id);
            return is_wp_error($verification)
                ? $verification
                : new WP_Error('validation_failed', 'Media metadata failed post-Apply verification and was rolled back.', array('status'=>409));
        }

        $after = $this->media->inspect($attachment_id);
        if (is_wp_error($after)) {
            $this->rollback($snapshot_id);
            return $after;
        }

        return array(
            'status'=>'applied',
            'attachment_id'=>$attachment_id,
            'snapshot_id'=>$snapshot_id,
            'verification'=>$verification,
            'verified'=>true,
            'post_apply_revision'=>$this->state_checksum($after),
        );
    }

    public function verify(int $attachment_id, array $expected): array|WP_Error {
        $current = $this->media->inspect($attachment_id);
        if (is_wp_error($current)) { return $current; }
        $normalised = $this->normalise_changes($expected, $current);
        if (is_wp_error($normalised)) { return $normalised; }

        $matches = array();
        $verified = true;
        foreach ($normalised as $field => $value) {
            $actual = (string) ($current[$field] ?? '');
            $match = $actual === $value;
            $matches[$field] = array('expected'=>$value, 'actual'=>$actual, 'matches'=>$match);
            $verified = $verified && $match;
        }
        return array(
            'verified'=>$verified,
            'attachment_id'=>$attachment_id,
            'matches'=>$matches,
            'revision'=>$this->state_checksum($current),
            'verified_at'=>gmdate(DATE_W3C),
        );
    }

    public function rollback(string $snapshot_id): array|WP_Error {
        if (! current_user_can('manage_options')) {
            return new WP_Error('capability_unavailable', 'The local WordPress execution identity cannot rollback Media metadata.', array('status'=>403));
        }
        $snapshot_id = sanitize_text_field($snapshot_id);
        if ('' === $snapshot_id) {
            return new WP_Error('rollback_unavailable', 'A Media metadata snapshot ID is required.', array('status'=>409));
        }
        $snapshot = $this->snapshots->get($snapshot_id);
        $before = is_array($snapshot['before'] ?? null) ? $snapshot['before'] : array();
        if ('media-metadata' !== (string) ($before['type'] ?? '')) {
            return new WP_Error('rollback_unavailable', 'Snapshot is not a Media metadata rollback boundary.', array('status'=>409));
        }
        $attachment_id = absint($before['attachment_id'] ?? 0);
        $state = is_array($before['state'] ?? null) ? $before['state'] : array();
        if ($attachment_id <= 0 || ! $state) {
            return new WP_Error('rollback_unavailable', 'Media metadata snapshot is incomplete.', array('status'=>409));
        }
        $current = $this->media->inspect($attachment_id);
        if (is_wp_error($current)) { return $current; }

        $updated = wp_update_post(wp_slash(array(
            'ID'=>$attachment_id,
            'post_title'=>(string) ($state['title'] ?? ''),
            'post_excerpt'=>(string) ($state['caption'] ?? ''),
            'post_content'=>(string) ($state['description'] ?? ''),
        )), true);
        if (is_wp_error($updated)) {
            return new WP_Error('rollback_unavailable', $updated->get_error_message(), array('status'=>409));
        }
        update_post_meta($attachment_id, '_wp_attachment_image_alt', (string) ($state['alt_text'] ?? ''));
        clean_post_cache($attachment_id);

        $verification = $this->verify($attachment_id, array_intersect_key($state, array_flip(self::FIELDS)));
        if (is_wp_error($verification) || empty($verification['verified'])) {
            return is_wp_error($verification)
                ? $verification
                : new WP_Error('rollback_unavailable', 'Media metadata rollback verification failed.', array('status'=>409));
        }
        $this->snapshots->mark_rolled_back($snapshot_id);

        return array(
            'status'=>'rolled-back',
            'attachment_id'=>$attachment_id,
            'snapshot_id'=>$snapshot_id,
            'verification'=>$verification,
            'verified'=>true,
            'restored_revision'=>(string) ($verification['revision'] ?? ''),
        );
    }

    private function normalise_changes(array $changes, array $current): array|WP_Error {
        foreach (array_keys($changes) as $field) {
            if (! in_array((string) $field, self::FIELDS, true)) {
                return new WP_Error('validation_failed', sprintf('Unsupported Media metadata field: %s.', (string) $field), array('status'=>400));
            }
        }

        $normalised = array();
        foreach ($changes as $field => $value) {
            if (! is_scalar($value) && null !== $value) {
                return new WP_Error('validation_failed', sprintf('Media metadata field %s must be scalar.', (string) $field), array('status'=>400));
            }
            $value = (string) ($value ?? '');
            if ('title' === $field) {
                $value = trim(sanitize_text_field($value));
                if ('' === $value) {
                    return new WP_Error('validation_failed', 'Media title cannot be empty.', array('status'=>400));
                }
                $value = $this->limit($value, 200);
            } elseif ('alt_text' === $field) {
                if ('image' !== (string) ($current['media_type'] ?? '')) {
                    return new WP_Error('validation_failed', 'alt_text may only be changed for image attachments.', array('status'=>400));
                }
                $value = $this->limit(trim(sanitize_text_field($value)), 500);
            } elseif ('caption' === $field) {
                $value = $this->limit(trim(sanitize_textarea_field($value)), 2000);
            } else {
                $value = $this->limit(trim(wp_kses_post($value)), 12000);
            }
            $normalised[(string) $field] = $value;
        }
        return $normalised;
    }

    private function state_checksum(array $state): string {
        return hash('sha256', (string) wp_json_encode($this->managed_state($state)));
    }

    private function managed_state(array $state): array {
        return array(
            'id'=>(int) ($state['id'] ?? 0),
            'title'=>(string) ($state['title'] ?? ''),
            'alt_text'=>(string) ($state['alt_text'] ?? ''),
            'caption'=>(string) ($state['caption'] ?? ''),
            'description'=>(string) ($state['description'] ?? ''),
            'mime_type'=>(string) ($state['mime_type'] ?? ''),
            'media_type'=>(string) ($state['media_type'] ?? ''),
            'parent_id'=>(int) ($state['parent_id'] ?? 0),
            'status'=>(string) ($state['status'] ?? ''),
            'source_url'=>(string) ($state['source_url'] ?? ''),
        );
    }

    private function limit(string $value, int $length): string {
        return function_exists('mb_substr') ? mb_substr($value, 0, $length) : substr($value, 0, $length);
    }
}
