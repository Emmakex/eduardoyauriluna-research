<?php
/** Read-only bounded Media diagnostics and safe metadata-remediation eligibility. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Media_Diagnostics {
    private const MAX_SCAN = 500;
    private const LARGE_DIMENSION = 2560;
    private const LARGE_PIXELS = 6000000;
    private Eduardo_Research_Manager_Media_Resource $media;

    public function __construct(?Eduardo_Research_Manager_Media_Resource $media = null) {
        $this->media = $media ?: new Eduardo_Research_Manager_Media_Resource();
    }

    /**
     * Read-only aggregate report. No finding in this report performs a mutation.
     *
     * @return array<string,mixed>|WP_Error
     */
    public function report(array $args = array()): array|WP_Error {
        $limit = max(1, min(self::MAX_SCAN, (int) ($args['limit'] ?? self::MAX_SCAN)));
        $query = new WP_Query(array(
            'post_type'=>'attachment',
            'post_status'=>'inherit',
            'posts_per_page'=>$limit,
            'paged'=>1,
            'orderby'=>'ID',
            'order'=>'ASC',
            'no_found_rows'=>false,
        ));

        $theme_usage = $this->theme_assignment_usage();
        $items = array();
        $fingerprints = array();
        $summary = array(
            'attachments_scanned'=>0,
            'images_scanned'=>0,
            'metadata_ready'=>0,
            'missing_alt'=>0,
            'weak_alt'=>0,
            'unused'=>0,
            'duplicate_candidates'=>0,
            'duplicate_groups'=>0,
            'performance_advisories'=>0,
        );

        foreach ($query->posts as $post) {
            if (! $post instanceof WP_Post) { continue; }
            $record = $this->media->inspect((int) $post->ID);
            if (is_wp_error($record)) { continue; }
            $diagnostic = $this->diagnose_record($record, $theme_usage);
            $items[] = $diagnostic;
            $summary['attachments_scanned']++;
            if ('image' === (string) ($record['media_type'] ?? '')) { $summary['images_scanned']++; }
            if (! empty($record['readiness']['metadata_ready'])) { $summary['metadata_ready']++; }
            foreach (array('missing_alt','weak_alt','unused','performance_advisories') as $key) {
                if (! empty($diagnostic['flags'][$key])) { $summary[$key]++; }
            }
            $fingerprint = (string) ($diagnostic['duplicate_signal']['fingerprint'] ?? '');
            if ('' !== $fingerprint) {
                if (! isset($fingerprints[$fingerprint])) { $fingerprints[$fingerprint] = array(); }
                $fingerprints[$fingerprint][] = (int) $record['id'];
            }
        }

        $duplicate_groups = array();
        foreach ($fingerprints as $fingerprint => $ids) {
            $ids = array_values(array_unique(array_map('absint', $ids)));
            if (count($ids) < 2) { continue; }
            $duplicate_groups[] = array(
                'fingerprint'=>$fingerprint,
                'attachment_ids'=>$ids,
                'count'=>count($ids),
                'confidence'=>'candidate',
                'destructive_action'=>false,
            );
        }
        $summary['duplicate_groups'] = count($duplicate_groups);
        $duplicate_ids = array();
        foreach ($duplicate_groups as $group) {
            $duplicate_ids = array_merge($duplicate_ids, (array) $group['attachment_ids']);
        }
        $duplicate_ids = array_values(array_unique(array_map('absint', $duplicate_ids)));
        $summary['duplicate_candidates'] = count($duplicate_ids);

        foreach ($items as &$item) {
            $item['flags']['duplicate_candidate'] = in_array((int) $item['attachment_id'], $duplicate_ids, true);
            if ($item['flags']['duplicate_candidate']) {
                $item['findings'][] = $this->finding(
                    'duplicate-candidate',
                    'advisory',
                    'Attachment shares the same bounded MIME/dimension/filesize fingerprint with another Media item. Review manually; no deletion is permitted.'
                );
            }
        }
        unset($item);

        $scan_complete = (int) $query->found_posts <= $limit;
        $advisory_count = 0;
        foreach ($items as $item) { $advisory_count += count((array) ($item['findings'] ?? array())); }
        if (! $scan_complete) { $advisory_count++; }

        return array(
            'version'=>1,
            'milestone'=>'P3.4',
            'ready'=>0 === $summary['missing_alt']
                && 0 === $summary['weak_alt']
                && 0 === $summary['performance_advisories']
                && $scan_complete,
            'summary'=>$summary,
            'items'=>$items,
            'duplicate_groups'=>$duplicate_groups,
            'scan'=>array(
                'limit'=>$limit,
                'total_attachments'=>(int) $query->found_posts,
                'complete'=>$scan_complete,
                'truncated'=>! $scan_complete,
            ),
            'advisory_count'=>$advisory_count,
            'blocking_count'=>0,
            'remediation'=>array(
                'safe_metadata_gateway'=>'/research-manager/v1/media/diagnostics/remediation/plan',
                'apply_gateway'=>'/research-manager/v1/media/metadata/plans/{plan_id}/apply',
                'operator_supplied_alt_required'=>true,
                'automatic_alt_generation'=>false,
                'automatic_delete'=>false,
                'binary_replacement'=>false,
            ),
            'read_only'=>true,
            'generated_at'=>gmdate(DATE_W3C),
        );
    }

    /** @return array<string,mixed>|WP_Error */
    public function inspect(int $attachment_id): array|WP_Error {
        $record = $this->media->inspect($attachment_id);
        if (is_wp_error($record)) { return $record; }
        $item = $this->diagnose_record($record, $this->theme_assignment_usage());
        $item['read_only'] = true;
        return $item;
    }

    /**
     * Validate that an operator-supplied remediation is both required and bounded.
     * This never writes. The accepted payload is delegated to the existing P3.2 metadata lifecycle.
     *
     * @return array<string,mixed>|WP_Error
     */
    public function prepare_metadata_remediation(int $attachment_id, array $changes): array|WP_Error {
        if (array_keys($changes) !== array('alt_text')) {
            return new WP_Error(
                'validation_failed',
                'P3.4 deterministic remediation accepts exactly one operator-supplied alt_text field.',
                array('status'=>400)
            );
        }
        $item = $this->inspect($attachment_id);
        if (is_wp_error($item)) { return $item; }
        if ('image' !== (string) ($item['media_type'] ?? '')) {
            return new WP_Error('validation_failed', 'Alt-text remediation is available for image attachments only.', array('status'=>400));
        }
        if (empty($item['flags']['missing_alt']) && empty($item['flags']['weak_alt'])) {
            return new WP_Error('validation_failed', 'Attachment does not currently have a missing or weak alt-text diagnostic.', array('status'=>409));
        }
        $alt = $this->clean_alt((string) ($changes['alt_text'] ?? ''));
        if ('' === $alt) {
            return new WP_Error(
                'validation_failed',
                'P3.4 does not infer decorative intent. Provide a meaningful operator-reviewed alt_text or leave the item for manual review.',
                array('status'=>400)
            );
        }
        $weak = $this->weak_alt_reason($alt, (string) ($item['source_url'] ?? ''));
        if ('' !== $weak) {
            return new WP_Error('validation_failed', 'Replacement alt_text still matches a weak-alt diagnostic: ' . $weak, array('status'=>400));
        }
        return array(
            'attachment_id'=>$attachment_id,
            'changes'=>array('alt_text'=>$alt),
            'diagnostic_before'=>array(
                'missing_alt'=>! empty($item['flags']['missing_alt']),
                'weak_alt'=>! empty($item['flags']['weak_alt']),
            ),
            'operation'=>'media-metadata-update',
            'delegates_to'=>'P3.2 metadata lifecycle',
            'auto_generated'=>false,
        );
    }

    /** @return array<string,mixed> */
    private function diagnose_record(array $record, array $theme_usage): array {
        $id = (int) ($record['id'] ?? 0);
        $is_image = 'image' === (string) ($record['media_type'] ?? '');
        $alt = $this->clean_alt((string) ($record['alt_text'] ?? ''));
        $missing_alt = $is_image && '' === $alt;
        $weak_reason = $is_image && ! $missing_alt ? $this->weak_alt_reason($alt, (string) ($record['source_url'] ?? '')) : '';
        $weak_alt = '' !== $weak_reason;
        $parented = (int) ($record['parent_id'] ?? 0) > 0;
        $bounded_usage = is_array($theme_usage[$id] ?? null) ? $theme_usage[$id] : array();
        $unused = ! $parented && ! $bounded_usage;
        $width = max(0, (int) ($record['width'] ?? 0));
        $height = max(0, (int) ($record['height'] ?? 0));
        $large = $is_image && $width > 0 && $height > 0
            && (max($width, $height) > self::LARGE_DIMENSION || ($width * $height) > self::LARGE_PIXELS);
        $fingerprint = $this->duplicate_fingerprint($id, $record);
        $findings = array();

        if ($missing_alt) {
            $findings[] = $this->finding('missing-alt', 'advisory', 'Image alt text is empty. Confirm whether the image is informative or decorative before remediation.');
        } elseif ($weak_alt) {
            $findings[] = $this->finding('weak-alt', 'advisory', 'Image alt text is low-information: ' . $weak_reason);
        }
        if ($unused) {
            $findings[] = $this->finding('unused-attachment', 'advisory', 'Attachment has no WordPress parent and no bounded Theme Media assignment. Review before any removal decision.');
        }
        if ($large) {
            $findings[] = $this->finding('large-image-dimensions', 'advisory', sprintf('Image dimensions %dx%d exceed the bounded performance advisory threshold.', $width, $height));
        }

        return array(
            'attachment_id'=>$id,
            'media_type'=>(string) ($record['media_type'] ?? ''),
            'mime_type'=>(string) ($record['mime_type'] ?? ''),
            'title'=>(string) ($record['title'] ?? ''),
            'alt_text'=>(string) ($record['alt_text'] ?? ''),
            'source_url'=>(string) ($record['source_url'] ?? ''),
            'width'=>$record['width'] ?? null,
            'height'=>$record['height'] ?? null,
            'parent_id'=>(int) ($record['parent_id'] ?? 0),
            'usage'=>array(
                'wordpress_parent'=>$parented,
                'bounded_theme_assignments'=>$bounded_usage,
                'used'=>$parented || ! empty($bounded_usage),
            ),
            'flags'=>array(
                'missing_alt'=>$missing_alt,
                'weak_alt'=>$weak_alt,
                'unused'=>$unused,
                'duplicate_candidate'=>false,
                'performance_advisories'=>$large,
            ),
            'weak_alt_reason'=>$weak_reason,
            'duplicate_signal'=>array(
                'fingerprint'=>$fingerprint,
                'signals'=>'mime+dimensions+metadata-filesize',
                'content_hash'=>false,
            ),
            'findings'=>$findings,
            'safe_remediation'=>array(
                'eligible'=>$missing_alt || $weak_alt,
                'field'=>($missing_alt || $weak_alt) ? 'alt_text' : '',
                'operator_review_required'=>$missing_alt || $weak_alt,
                'auto_apply'=>false,
            ),
        );
    }

    /** @return array<int,array<int,array<string,mixed>>> */
    private function theme_assignment_usage(): array {
        $usage = array();
        $post_types = array('post','research_line','research_output','research_project','research_software','research_dataset');
        $ids = get_posts(array(
            'post_type'=>$post_types,
            'post_status'=>'any',
            'posts_per_page'=>self::MAX_SCAN,
            'fields'=>'ids',
            'orderby'=>'ID',
            'order'=>'ASC',
            'meta_key'=>Eduardo_Research_Manager_Media_Assignment_Editor::POST_META,
            'suppress_filters'=>true,
        ));
        foreach ($ids as $post_id) {
            $attachment_id = absint(get_post_meta((int) $post_id, Eduardo_Research_Manager_Media_Assignment_Editor::POST_META, true));
            if ($attachment_id <= 0) { continue; }
            if (! isset($usage[$attachment_id])) { $usage[$attachment_id] = array(); }
            $usage[$attachment_id][] = array('target'=>'post:' . (int) $post_id, 'slot'=>'primary');
        }

        $contract = Eduardo_Research_Manager::contract();
        $pages = $contract->pages();
        $languages = $contract->languages();
        foreach ($pages as $key => $_definition) {
            $key = sanitize_key((string) $key);
            foreach ($languages as $language) {
                $language = sanitize_key((string) $language);
                $attachment_id = absint(get_option(Eduardo_Research_Manager_Media_Assignment_Editor::PAGE_OPTION_PREFIX . $key . '_' . $language, 0));
                if ($attachment_id <= 0) { continue; }
                if (! isset($usage[$attachment_id])) { $usage[$attachment_id] = array(); }
                $usage[$attachment_id][] = array('target'=>'page:' . $key . ':' . $language, 'slot'=>'primary');
            }
        }
        return $usage;
    }

    private function duplicate_fingerprint(int $attachment_id, array $record): string {
        $metadata = wp_get_attachment_metadata($attachment_id);
        $metadata = is_array($metadata) ? $metadata : array();
        $filesize = max(0, (int) ($metadata['filesize'] ?? 0));
        $width = max(0, (int) ($record['width'] ?? 0));
        $height = max(0, (int) ($record['height'] ?? 0));
        $mime = (string) ($record['mime_type'] ?? '');
        if ($filesize <= 0 || '' === $mime) { return ''; }
        if ('image' === (string) ($record['media_type'] ?? '') && ($width <= 0 || $height <= 0)) { return ''; }
        return hash('sha256', (string) wp_json_encode(array(
            'mime'=>$mime,
            'width'=>$width,
            'height'=>$height,
            'filesize'=>$filesize,
        )));
    }

    private function weak_alt_reason(string $alt, string $source_url): string {
        $alt = $this->clean_alt($alt);
        if ('' === $alt) { return ''; }
        $normal = strtolower(remove_accents($alt));
        $normal = trim((string) preg_replace('/\s+/', ' ', $normal));
        if (strlen($normal) < 8) { return 'fewer than 8 characters'; }
        if (1 === preg_match('/^(image|imagen|img|photo|foto|picture|screenshot|captura|untitled|sin titulo|logo|banner|hero)([-_ ]?\d+)?$/i', $normal)) {
            return 'generic image label';
        }
        $path = (string) parse_url($source_url, PHP_URL_PATH);
        $stem = strtolower(remove_accents((string) pathinfo($path, PATHINFO_FILENAME)));
        $stem = (string) preg_replace('/-(scaled|\d+x\d+)$/', '', $stem);
        $compact_alt = (string) preg_replace('/[^a-z0-9]+/', '', $normal);
        $compact_stem = (string) preg_replace('/[^a-z0-9]+/', '', $stem);
        if ('' !== $compact_stem && $compact_alt === $compact_stem) { return 'matches the uploaded filename'; }
        if (1 === preg_match('/^(dsc|img|pxl|screenshot|captura)[-_ ]?\d{3,}$/i', $normal)) { return 'camera or screenshot filename pattern'; }
        return '';
    }

    private function clean_alt(string $value): string {
        $value = trim(sanitize_text_field($value));
        return function_exists('mb_substr') ? mb_substr($value, 0, 500) : substr($value, 0, 500);
    }

    /** @return array<string,string> */
    private function finding(string $id, string $severity, string $message): array {
        return array('check_id'=>$id, 'severity'=>$severity, 'message'=>$message);
    }
}
