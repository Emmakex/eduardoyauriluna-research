<?php
/** Managed binary Media ingest with staging, provenance, verification and ownership-safe rollback. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Remote_Media_Ingest_Operations {
    private const PLANS_OPTION = 'eduardo_research_manager_remote_media_ingest_plans';
    private const OPERATIONS_OPTION = 'eduardo_research_manager_remote_media_ingest_operations';
    private const PLAN_TTL = 15 * MINUTE_IN_SECONDS;
    private const CLEANUP_HOOK = 'eduardo_research_manager_cleanup_media_ingest_plan';
    private const MAX_BYTES = 10485760;
    private const MAX_PLANS = 40;
    private const MAX_OPERATIONS = 80;
    private const META_OPERATION = '_eduardo_research_manager_ingest_operation';
    private const META_CHECKSUM = '_eduardo_research_manager_ingest_sha256';
    private const META_CONNECTION = '_eduardo_research_manager_ingest_connection';
    private const META_REQUEST = '_eduardo_research_manager_ingest_request';

    private Eduardo_Research_Manager_Remote_Audit $audit;

    public function __construct(?Eduardo_Research_Manager_Remote_Audit $audit = null) {
        $this->audit = $audit ?: Eduardo_Research_Manager::remote_audit();
        add_action(self::CLEANUP_HOOK, array($this, 'cleanup_expired_plan'), 10, 1);
    }

    public function create_plan(array $file, array $input, array $actor): array|WP_Error {
        $validated = $this->validate_upload($file, $input);
        if (is_wp_error($validated)) { return $validated; }
        $checksum = hash_file('sha256', (string) $validated['tmp_name']);
        if (! is_string($checksum) || '' === $checksum) { return $this->error('validation_failed', 'Unable to checksum uploaded Media binary.', 400); }
        $connection_id = sanitize_text_field((string) ($actor['connection_id'] ?? ''));
        $request_id = sanitize_text_field((string) ($actor['request_id'] ?? ''));

        $existing = $this->find_plan_by_request($connection_id, $request_id, $checksum);
        if ($existing) { return $this->public_plan($existing); }

        $duplicate_ids = get_posts(array(
            'post_type'=>'attachment','post_status'=>'inherit','posts_per_page'=>10,'fields'=>'ids',
            'meta_key'=>self::META_CHECKSUM,'meta_value'=>$checksum,'suppress_filters'=>true,
        ));
        $duplicate_ids = array_values(array_filter(array_map('absint', (array) $duplicate_ids)));

        $staged = wp_tempnam((string) $validated['filename']);
        if (! is_string($staged) || '' === $staged || ! copy((string) $validated['tmp_name'], $staged)) {
            if (is_string($staged) && '' !== $staged && file_exists($staged)) { @unlink($staged); }
            return $this->error('apply_failed', 'Unable to stage uploaded Media binary.', 500);
        }
        @chmod($staged, 0600);
        if (! hash_equals($checksum, (string) hash_file('sha256', $staged))) { @unlink($staged); return $this->error('validation_failed', 'Staged Media checksum mismatch.', 409); }

        $now = time();
        $material = array('checksum'=>$checksum,'filename'=>$validated['filename'],'mime_type'=>$validated['mime_type'],'size'=>$validated['size'],'connection_id'=>$connection_id,'request_id'=>$request_id);
        $plan_id = 'erm-media-ingest-plan-' . substr(hash('sha256', (string) wp_json_encode($material)), 0, 20) . '-' . substr(str_replace('-', '', wp_generate_uuid4()), 0, 8);
        $plan = array(
            'plan_id'=>$plan_id,'operation'=>'media-ingest','risk'=>'file-ingest','confirmation_required'=>true,'confirmation_class'=>'media-ingest-explicit',
            'target'=>array('resource'=>'media:new'),'file'=>array('filename'=>$validated['filename'],'mime_type'=>$validated['mime_type'],'size'=>$validated['size'],'sha256'=>$checksum),
            'metadata'=>array('title'=>$validated['title'],'alt_text'=>$validated['alt_text'],'caption'=>$validated['caption'],'description'=>$validated['description']),
            'duplicate_candidates'=>$duplicate_ids,'staged_path'=>$staged,'apply_allowed'=>true,'connection_id'=>$connection_id,
            'created_by'=>(int) ($actor['wordpress_user_id'] ?? 0),'created_request_id'=>$request_id,'created_at'=>gmdate(DATE_W3C, $now),
            'expires_at'=>gmdate(DATE_W3C, $now + self::PLAN_TTL),'expires_unix'=>$now + self::PLAN_TTL,'status'=>'planned',
            'cleanup'=>array('policy'=>'scheduled-or-explicit-cancel','ttl_seconds'=>self::PLAN_TTL),
        );
        $this->put(self::PLANS_OPTION, $plan_id, $plan, self::MAX_PLANS);
        if (! wp_next_scheduled(self::CLEANUP_HOOK, array($plan_id))) {
            wp_schedule_single_event($now + self::PLAN_TTL, self::CLEANUP_HOOK, array($plan_id));
        }
        $this->audit('remote-media-ingest-plan-created', 'success', $actor, $plan_id, '', 'Managed Media binary staged for explicit Apply');
        return $this->public_plan($plan);
    }

    public function get_plan(string $plan_id): array|WP_Error {
        $plan = $this->get(self::PLANS_OPTION, $plan_id);
        if (! $plan) { return $this->error('operation_not_found', 'Media ingest plan was not found.', 404); }
        if ('planned' === (string) ($plan['status'] ?? '') && time() > (int) ($plan['expires_unix'] ?? 0)) {
            $plan = $this->expire_plan($plan_id, $plan);
        }
        return $this->public_plan($plan);
    }

    public function cancel(string $plan_id, array $actor): array|WP_Error {
        $plan = $this->get(self::PLANS_OPTION, $plan_id);
        if (! $plan) { return $this->error('operation_not_found', 'Media ingest plan was not found.', 404); }
        if ((string) ($plan['connection_id'] ?? '') !== (string) ($actor['connection_id'] ?? '')) { return $this->error('scope_denied', 'This Media ingest plan belongs to another connection.', 403); }
        if ('cancelled' === (string) ($plan['status'] ?? '')) { return $this->public_plan($plan); }
        if ('planned' !== (string) ($plan['status'] ?? '')) { return $this->error('validation_failed', 'Only a planned Media ingest Preview can be cancelled.', 409); }
        $this->cleanup_stage((string) ($plan['staged_path'] ?? ''));
        wp_clear_scheduled_hook(self::CLEANUP_HOOK, array($plan_id));
        $plan['staged_path'] = '';
        $plan['status'] = 'cancelled';
        $plan['cancelled_at'] = gmdate(DATE_W3C);
        $this->put(self::PLANS_OPTION, $plan_id, $plan, self::MAX_PLANS);
        $this->audit('remote-media-ingest-plan-cancelled', 'success', $actor, $plan_id, '', 'Private staged Media Preview cancelled and removed');
        return $this->public_plan($plan);
    }

    public function cleanup_expired_plan(string $plan_id): void {
        $plan = $this->get(self::PLANS_OPTION, $plan_id);
        if (! $plan || 'planned' !== (string) ($plan['status'] ?? '')) { return; }
        if (time() < (int) ($plan['expires_unix'] ?? 0)) { return; }
        $this->expire_plan($plan_id, $plan);
    }

    public function apply(string $plan_id, bool $confirm, array $actor): array|WP_Error {
        if (! current_user_can('upload_files') || ! current_user_can('manage_options')) { return $this->error('capability_unavailable', 'Local execution identity cannot ingest Media.', 403); }
        $plan = $this->get(self::PLANS_OPTION, $plan_id);
        if (! $plan) { return $this->error('operation_not_found', 'Media ingest plan was not found.', 404); }
        if ((string) ($plan['connection_id'] ?? '') !== (string) ($actor['connection_id'] ?? '')) { return $this->error('scope_denied', 'This Media ingest plan belongs to another connection.', 403); }
        if ('planned' !== (string) ($plan['status'] ?? '')) {
            if ('applied' === (string) ($plan['status'] ?? '') && '' !== (string) ($plan['operation_id'] ?? '')) { return $this->get_operation((string) $plan['operation_id']); }
            return $this->error('validation_failed', 'Only a planned Media ingest can be applied.', 409);
        }
        if (time() > (int) ($plan['expires_unix'] ?? 0)) { $this->expire_plan($plan_id, $plan); return $this->error('plan_expired', 'Media ingest plan expired.', 409); }
        if (! $confirm) { return $this->error('confirmation_required', 'Media ingest Apply requires explicit confirmation.', 409); }
        $staged = (string) ($plan['staged_path'] ?? '');
        $checksum = (string) ($plan['file']['sha256'] ?? '');
        if ('' === $staged || ! is_file($staged) || '' === $checksum || ! hash_equals($checksum, (string) hash_file('sha256', $staged))) {
            return $this->error('stale_revision', 'Staged Media binary changed or disappeared after Preview.', 409);
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        $file_array = array('name'=>(string) $plan['file']['filename'],'type'=>(string) $plan['file']['mime_type'],'tmp_name'=>$staged,'error'=>0,'size'=>(int) $plan['file']['size']);
        $post_data = array('post_title'=>(string) $plan['metadata']['title'],'post_excerpt'=>(string) $plan['metadata']['caption'],'post_content'=>(string) $plan['metadata']['description']);
        $attachment_id = media_handle_sideload($file_array, 0, (string) $plan['metadata']['title'], $post_data);
        if (is_wp_error($attachment_id)) {
            $this->cleanup_stage($staged);
            wp_clear_scheduled_hook(self::CLEANUP_HOOK, array($plan_id));
            $plan['staged_path'] = '';
            $plan['status'] = 'apply-failed';
            $this->put(self::PLANS_OPTION, $plan_id, $plan, self::MAX_PLANS);
            return $this->normalise($attachment_id, 409);
        }
        $attachment_id = absint($attachment_id);
        $operation_id = 'erm-media-ingest-op-' . str_replace('-', '', wp_generate_uuid4());
        update_post_meta($attachment_id, '_wp_attachment_image_alt', (string) $plan['metadata']['alt_text']);
        update_post_meta($attachment_id, self::META_OPERATION, $operation_id);
        update_post_meta($attachment_id, self::META_CHECKSUM, $checksum);
        update_post_meta($attachment_id, self::META_CONNECTION, (string) ($actor['connection_id'] ?? ''));
        update_post_meta($attachment_id, self::META_REQUEST, (string) ($actor['request_id'] ?? ''));

        $operation = array(
            'operation_id'=>$operation_id,'plan_id'=>$plan_id,'operation'=>'media-ingest','target'=>array('resource'=>'media','attachment_id'=>$attachment_id),
            'file'=>(array) $plan['file'],'metadata'=>(array) $plan['metadata'],'connection_id'=>(string) ($actor['connection_id'] ?? ''),
            'wordpress_user_id'=>(int) ($actor['wordpress_user_id'] ?? 0),'status'=>'applied','created_at'=>gmdate(DATE_W3C),'applied_at'=>gmdate(DATE_W3C),'verified_at'=>'','rolled_back_at'=>'',
        );
        $this->put(self::OPERATIONS_OPTION, $operation_id, $operation, self::MAX_OPERATIONS);
        $plan['status'] = 'applied'; $plan['operation_id'] = $operation_id; $plan['attachment_id'] = $attachment_id; $plan['staged_path'] = ''; $plan['applied_at'] = gmdate(DATE_W3C);
        $this->put(self::PLANS_OPTION, $plan_id, $plan, self::MAX_PLANS);
        wp_clear_scheduled_hook(self::CLEANUP_HOOK, array($plan_id));
        $this->audit('remote-media-ingest-applied', 'success', $actor, $plan_id, $operation_id, 'Managed Media attachment created');
        return $operation;
    }

    public function get_operation(string $operation_id): array|WP_Error {
        $operation = $this->get(self::OPERATIONS_OPTION, $operation_id);
        return $operation ?: $this->error('operation_not_found', 'Media ingest operation was not found.', 404);
    }

    public function verify(string $operation_id, array $actor): array|WP_Error {
        $operation = $this->get(self::OPERATIONS_OPTION, $operation_id);
        if (! $operation) { return $this->error('operation_not_found', 'Media ingest operation was not found.', 404); }
        if ((string) ($operation['connection_id'] ?? '') !== (string) ($actor['connection_id'] ?? '')) { return $this->error('scope_denied', 'This Media ingest operation belongs to another connection.', 403); }
        $attachment_id = absint($operation['target']['attachment_id'] ?? 0);
        $post = get_post($attachment_id);
        $expected_checksum = (string) ($operation['file']['sha256'] ?? '');
        $path = $post instanceof WP_Post ? get_attached_file($attachment_id) : '';
        $actual_checksum = is_string($path) && is_file($path) ? (string) hash_file('sha256', $path) : '';
        $provenance = $post instanceof WP_Post
            && hash_equals($operation_id, (string) get_post_meta($attachment_id, self::META_OPERATION, true))
            && hash_equals($expected_checksum, (string) get_post_meta($attachment_id, self::META_CHECKSUM, true));
        $mime_matches = $post instanceof WP_Post && (string) get_post_mime_type($attachment_id) === (string) ($operation['file']['mime_type'] ?? '');
        $alt_matches = ! str_starts_with((string) ($operation['file']['mime_type'] ?? ''), 'image/')
            || (string) get_post_meta($attachment_id, '_wp_attachment_image_alt', true) === (string) ($operation['metadata']['alt_text'] ?? '');
        $verified = $post instanceof WP_Post && '' !== $actual_checksum && hash_equals($expected_checksum, $actual_checksum) && $provenance && $mime_matches && $alt_matches;
        $operation['verification'] = array('verified'=>$verified,'attachment_exists'=>$post instanceof WP_Post,'checksum_matches'=>'' !== $actual_checksum && hash_equals($expected_checksum, $actual_checksum),'provenance_matches'=>$provenance,'mime_matches'=>$mime_matches,'alt_matches'=>$alt_matches,'verified_at'=>gmdate(DATE_W3C));
        $operation['status'] = $verified ? 'verified' : 'verification-failed'; $operation['verified_at'] = gmdate(DATE_W3C);
        $this->put(self::OPERATIONS_OPTION, $operation_id, $operation, self::MAX_OPERATIONS);
        $this->audit('remote-media-ingest-verified', $verified ? 'success' : 'failed', $actor, (string) $operation['plan_id'], $operation_id, 'Verify managed Media ingest');
        return $operation;
    }

    public function rollback(string $operation_id, bool $confirm, array $actor): array|WP_Error {
        if (! $confirm) { return $this->error('confirmation_required', 'Media ingest rollback requires explicit confirmation.', 409); }
        $operation = $this->get(self::OPERATIONS_OPTION, $operation_id);
        if (! $operation) { return $this->error('operation_not_found', 'Media ingest operation was not found.', 404); }
        if ((string) ($operation['connection_id'] ?? '') !== (string) ($actor['connection_id'] ?? '')) { return $this->error('scope_denied', 'This Media ingest operation belongs to another connection.', 403); }
        if (! in_array((string) ($operation['status'] ?? ''), array('applied','verified','verification-failed'), true)) {
            if ('rolled-back' === (string) ($operation['status'] ?? '')) { return $operation; }
            return $this->error('rollback_unavailable', 'Media ingest operation is not rollback-capable.', 409);
        }
        $attachment_id = absint($operation['target']['attachment_id'] ?? 0);
        if ($attachment_id <= 0 || ! get_post($attachment_id)) { return $this->error('rollback_unavailable', 'Managed Media attachment no longer exists.', 409); }
        if (! hash_equals($operation_id, (string) get_post_meta($attachment_id, self::META_OPERATION, true))) { return $this->error('stale_revision', 'Media provenance changed after ingest. Rollback is blocked.', 409); }
        $diagnostics = new Eduardo_Research_Manager_Media_Diagnostics(new Eduardo_Research_Manager_Media_Resource());
        $usage = $diagnostics->inspect($attachment_id);
        if (is_wp_error($usage)) { return $this->normalise($usage, 409); }
        if (! empty($usage['usage']['used'])) { return $this->error('rollback_unavailable', 'Managed Media is now in use. Detach it through bounded Manager operations before rollback.', 409); }
        $deleted = wp_delete_attachment($attachment_id, true);
        if (! $deleted || get_post($attachment_id)) { return $this->error('rollback_failed', 'Managed Media rollback did not remove the owned attachment.', 409); }
        $operation['status'] = 'rolled-back'; $operation['rolled_back_at'] = gmdate(DATE_W3C); $operation['rollback_result'] = array('verified'=>true,'attachment_id'=>$attachment_id,'deleted'=>true);
        $this->put(self::OPERATIONS_OPTION, $operation_id, $operation, self::MAX_OPERATIONS);
        $this->audit('remote-media-ingest-rolled-back', 'success', $actor, (string) $operation['plan_id'], $operation_id, 'Rollback owned unused Media ingest');
        return $operation;
    }

    private function validate_upload(array $file, array $input): array|WP_Error {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        $tmp = (string) ($file['tmp_name'] ?? '');
        $size = max(0, (int) ($file['size'] ?? 0));
        $name = sanitize_file_name((string) ($file['name'] ?? ''));
        if (UPLOAD_ERR_OK !== $error || '' === $tmp || ! is_file($tmp) || '' === $name) { return $this->error('validation_failed', 'A valid multipart Media file is required.', 400); }
        if ($size <= 0 || $size > self::MAX_BYTES || filesize($tmp) !== $size) { return $this->error('validation_failed', 'Media binary must be non-empty and no larger than 10 MB.', 400); }
        $allowed = array('jpg|jpeg|jpe'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp','pdf'=>'application/pdf');
        $checked = wp_check_filetype_and_ext($tmp, $name, $allowed);
        $mime = (string) ($checked['type'] ?? '');
        $proper = (string) ($checked['proper_filename'] ?? '');
        if ('' === $mime || ! in_array($mime, array_values($allowed), true)) { return $this->error('validation_failed', 'Media type is not allowed. Supported: JPEG, PNG, WebP and PDF.', 400); }
        if ('' !== $proper) { $name = sanitize_file_name($proper); }
        $alt = trim(sanitize_text_field((string) ($input['alt_text'] ?? '')));
        if (str_starts_with($mime, 'image/') && '' === $alt) { return $this->error('validation_failed', 'Image ingest requires operator-reviewed alt_text.', 400); }
        $title = trim(sanitize_text_field((string) ($input['title'] ?? pathinfo($name, PATHINFO_FILENAME))));
        if ('' === $title) { return $this->error('validation_failed', 'Media title is required.', 400); }
        return array('tmp_name'=>$tmp,'size'=>$size,'filename'=>$name,'mime_type'=>$mime,'title'=>$title,'alt_text'=>$alt,'caption'=>sanitize_text_field((string) ($input['caption'] ?? '')),'description'=>sanitize_textarea_field((string) ($input['description'] ?? '')));
    }

    private function expire_plan(string $plan_id, array $plan): array {
        $this->cleanup_stage((string) ($plan['staged_path'] ?? ''));
        wp_clear_scheduled_hook(self::CLEANUP_HOOK, array($plan_id));
        $plan['staged_path'] = '';
        $plan['status'] = 'expired';
        $plan['expired_at'] = gmdate(DATE_W3C);
        $this->put(self::PLANS_OPTION, $plan_id, $plan, self::MAX_PLANS);
        $this->audit('remote-media-ingest-plan-expired', 'success', array(
            'connection_id'=>(string) ($plan['connection_id'] ?? ''),
            'wordpress_user_id'=>(int) ($plan['created_by'] ?? 0),
            'request_id'=>(string) ($plan['created_request_id'] ?? ''),
        ), $plan_id, '', 'Expired private staged Media Preview removed');
        return $plan;
    }

    private function find_plan_by_request(string $connection_id, string $request_id, string $checksum): array {
        if ('' === $request_id) { return array(); }
        $plans = get_option(self::PLANS_OPTION, array());
        foreach (is_array($plans) ? $plans : array() as $plan) {
            if (! is_array($plan)) { continue; }
            if ((string) ($plan['connection_id'] ?? '') === $connection_id && (string) ($plan['created_request_id'] ?? '') === $request_id && (string) ($plan['file']['sha256'] ?? '') === $checksum) { return $plan; }
        }
        return array();
    }

    private function public_plan(array $plan): array { unset($plan['staged_path'], $plan['expires_unix']); return $plan; }
    private function cleanup_stage(string $path): void { if ('' !== $path && is_file($path)) { @unlink($path); } }
    private function get(string $option, string $id): array { $all = get_option($option, array()); $value = is_array($all) ? ($all[sanitize_text_field($id)] ?? array()) : array(); return is_array($value) ? $value : array(); }
    private function put(string $option, string $id, array $value, int $limit): void {
        $all = get_option($option, array());
        $all = is_array($all) ? $all : array();
        $all[$id] = $value;
        while (count($all) > $limit) {
            $old_id = (string) array_key_first($all);
            $old = $all[$old_id] ?? array();
            unset($all[$old_id]);
            if (is_array($old)) { $this->cleanup_stage((string) ($old['staged_path'] ?? '')); }
            if ('' !== $old_id) { wp_clear_scheduled_hook(self::CLEANUP_HOOK, array($old_id)); }
        }
        if (false === get_option($option, false)) { add_option($option, $all, '', false); } else { update_option($option, $all, false); }
    }
    private function audit(string $event, string $outcome, array $actor, string $plan_id, string $operation_id, string $reason): void { $this->audit->record(array('request_id'=>(string) ($actor['request_id'] ?? ''),'connection_id'=>(string) ($actor['connection_id'] ?? ''),'wordpress_user_id'=>(int) ($actor['wordpress_user_id'] ?? 0),'event'=>$event,'outcome'=>$outcome,'scope'=>'media.write','plan_id'=>$plan_id,'operation_id'=>$operation_id,'reason'=>$reason)); }
    private function normalise(WP_Error $error, int $status): WP_Error { $data = $error->get_error_data(); if (! is_array($data) || ! isset($data['status'])) { $error->add_data(array_merge(is_array($data) ? $data : array(), array('status'=>$status))); } return $error; }
    private function error(string $code, string $message, int $status): WP_Error { return new WP_Error($code, $message, array('status'=>$status)); }
}