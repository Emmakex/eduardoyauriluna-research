<?php
/** Shared managed Media binary ingest domain service. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Media_Ingest {
    public const MAX_BYTES = 10485760;
    public const META_OPERATION = '_eduardo_research_manager_ingest_operation';
    public const META_CHECKSUM = '_eduardo_research_manager_ingest_sha256';
    public const META_CONNECTION = '_eduardo_research_manager_ingest_connection';
    public const META_REQUEST = '_eduardo_research_manager_ingest_request';

    public function prepare(array $file, array $input): array|WP_Error {
        $validated = $this->validate_upload($file, $input);
        if (is_wp_error($validated)) { return $validated; }
        $checksum = hash_file('sha256', (string) $validated['tmp_name']);
        if (! is_string($checksum) || '' === $checksum) { return $this->error('validation_failed', 'Unable to checksum uploaded Media binary.', 400); }

        $staged = wp_tempnam((string) $validated['filename']);
        if (! is_string($staged) || '' === $staged || ! copy((string) $validated['tmp_name'], $staged)) {
            if (is_string($staged) && '' !== $staged && file_exists($staged)) { @unlink($staged); }
            return $this->error('apply_failed', 'Unable to stage uploaded Media binary.', 500);
        }
        @chmod($staged, 0600);
        if (! hash_equals($checksum, (string) hash_file('sha256', $staged))) {
            @unlink($staged);
            return $this->error('validation_failed', 'Staged Media checksum mismatch.', 409);
        }

        return array(
            'file'=>array(
                'filename'=>(string) $validated['filename'],
                'mime_type'=>(string) $validated['mime_type'],
                'size'=>(int) $validated['size'],
                'sha256'=>$checksum,
            ),
            'metadata'=>array(
                'title'=>(string) $validated['title'],
                'alt_text'=>(string) $validated['alt_text'],
                'caption'=>(string) $validated['caption'],
                'description'=>(string) $validated['description'],
            ),
            'duplicate_candidates'=>$this->duplicate_candidates($checksum),
            'staged_path'=>$staged,
        );
    }

    public function stage_is_current(string $path, string $checksum): bool {
        return '' !== $path && is_file($path) && '' !== $checksum && hash_equals($checksum, (string) hash_file('sha256', $path));
    }

    public function create_attachment(string $staged_path, array $file, array $metadata, array $provenance): array|WP_Error {
        $checksum = (string) ($file['sha256'] ?? '');
        if (! $this->stage_is_current($staged_path, $checksum)) {
            return $this->error('stale_revision', 'Staged Media binary changed or disappeared after Preview.', 409);
        }
        if (! current_user_can('upload_files') || ! current_user_can('manage_options')) {
            return $this->error('capability_unavailable', 'Local execution identity cannot ingest Media.', 403);
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        $file_array = array(
            'name'=>(string) ($file['filename'] ?? ''),
            'type'=>(string) ($file['mime_type'] ?? ''),
            'tmp_name'=>$staged_path,
            'error'=>0,
            'size'=>(int) ($file['size'] ?? 0),
        );
        $post_data = array(
            'post_title'=>(string) ($metadata['title'] ?? ''),
            'post_excerpt'=>(string) ($metadata['caption'] ?? ''),
            'post_content'=>(string) ($metadata['description'] ?? ''),
        );
        $attachment_id = media_handle_sideload($file_array, 0, (string) ($metadata['title'] ?? ''), $post_data);
        if (is_wp_error($attachment_id)) { return $attachment_id; }
        $attachment_id = absint($attachment_id);
        if ($attachment_id <= 0) { return $this->error('apply_failed', 'WordPress did not return a valid Media attachment ID.', 409); }

        update_post_meta($attachment_id, '_wp_attachment_image_alt', (string) ($metadata['alt_text'] ?? ''));
        update_post_meta($attachment_id, self::META_OPERATION, sanitize_text_field((string) ($provenance['operation_id'] ?? '')));
        update_post_meta($attachment_id, self::META_CHECKSUM, $checksum);
        update_post_meta($attachment_id, self::META_CONNECTION, sanitize_text_field((string) ($provenance['connection_id'] ?? '')));
        update_post_meta($attachment_id, self::META_REQUEST, sanitize_text_field((string) ($provenance['request_id'] ?? '')));

        return array('attachment_id'=>$attachment_id,'sha256'=>$checksum,'provenance_written'=>true);
    }

    public function verify_attachment(int $attachment_id, string $operation_id, array $file, array $metadata): array {
        $post = $attachment_id > 0 ? get_post($attachment_id) : null;
        $expected_checksum = (string) ($file['sha256'] ?? '');
        $path = $post instanceof WP_Post ? get_attached_file($attachment_id) : '';
        $actual_checksum = is_string($path) && is_file($path) ? (string) hash_file('sha256', $path) : '';
        $provenance = $post instanceof WP_Post
            && '' !== $operation_id
            && hash_equals($operation_id, (string) get_post_meta($attachment_id, self::META_OPERATION, true))
            && '' !== $expected_checksum
            && hash_equals($expected_checksum, (string) get_post_meta($attachment_id, self::META_CHECKSUM, true));
        $mime_matches = $post instanceof WP_Post && (string) get_post_mime_type($attachment_id) === (string) ($file['mime_type'] ?? '');
        $alt_matches = ! str_starts_with((string) ($file['mime_type'] ?? ''), 'image/')
            || (string) get_post_meta($attachment_id, '_wp_attachment_image_alt', true) === (string) ($metadata['alt_text'] ?? '');
        $checksum_matches = '' !== $actual_checksum && '' !== $expected_checksum && hash_equals($expected_checksum, $actual_checksum);
        $verified = $post instanceof WP_Post && $checksum_matches && $provenance && $mime_matches && $alt_matches;
        return array(
            'verified'=>$verified,
            'attachment_exists'=>$post instanceof WP_Post,
            'checksum_matches'=>$checksum_matches,
            'provenance_matches'=>$provenance,
            'mime_matches'=>$mime_matches,
            'alt_matches'=>$alt_matches,
            'verified_at'=>gmdate(DATE_W3C),
        );
    }

    public function rollback_attachment(int $attachment_id, string $operation_id): array|WP_Error {
        if ($attachment_id <= 0 || ! get_post($attachment_id)) { return $this->error('rollback_unavailable', 'Managed Media attachment no longer exists.', 409); }
        if ('' === $operation_id || ! hash_equals($operation_id, (string) get_post_meta($attachment_id, self::META_OPERATION, true))) {
            return $this->error('stale_revision', 'Media provenance changed after ingest. Rollback is blocked.', 409);
        }
        $diagnostics = new Eduardo_Research_Manager_Media_Diagnostics(new Eduardo_Research_Manager_Media_Resource());
        $usage = $diagnostics->inspect($attachment_id);
        if (is_wp_error($usage)) { return $usage; }
        if (! empty($usage['usage']['used'])) { return $this->error('rollback_unavailable', 'Managed Media is now in use. Detach it through bounded Manager operations before rollback.', 409); }
        $deleted = wp_delete_attachment($attachment_id, true);
        if (! $deleted || get_post($attachment_id)) { return $this->error('rollback_failed', 'Managed Media rollback did not remove the owned attachment.', 409); }
        return array('verified'=>true,'attachment_id'=>$attachment_id,'deleted'=>true);
    }

    public function cleanup_stage(string $path): void {
        if ('' !== $path && is_file($path)) { @unlink($path); }
    }

    private function duplicate_candidates(string $checksum): array {
        $ids = get_posts(array(
            'post_type'=>'attachment','post_status'=>'inherit','posts_per_page'=>10,'fields'=>'ids',
            'meta_key'=>self::META_CHECKSUM,'meta_value'=>$checksum,'suppress_filters'=>true,
        ));
        return array_values(array_filter(array_map('absint', (array) $ids)));
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
        return array(
            'tmp_name'=>$tmp,'size'=>$size,'filename'=>$name,'mime_type'=>$mime,'title'=>$title,'alt_text'=>$alt,
            'caption'=>sanitize_text_field((string) ($input['caption'] ?? '')),'description'=>sanitize_textarea_field((string) ($input['description'] ?? '')),
        );
    }

    private function error(string $code, string $message, int $status): WP_Error { return new WP_Error($code, $message, array('status'=>$status)); }
}
