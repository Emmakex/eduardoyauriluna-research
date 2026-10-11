<?php
/** Bounded read model for WordPress Media attachments. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Media_Resource {
    private const MAX_PER_PAGE = 50;

    /**
     * @return array<string,mixed>|WP_Error
     */
    public function inventory(array $args = array()): array|WP_Error {
        $page = max(1, (int) ($args['page'] ?? 1));
        $per_page = max(1, min(self::MAX_PER_PAGE, (int) ($args['per_page'] ?? 20)));
        $search = trim(sanitize_text_field((string) ($args['search'] ?? '')));
        if (strlen($search) > 100) {
            $search = substr($search, 0, 100);
        }

        $mime = $this->normalise_mime_filter((string) ($args['mime_type'] ?? ''));
        if (is_wp_error($mime)) {
            return $mime;
        }

        $query_args = array(
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => $per_page,
            'paged' => $page,
            'orderby' => array('date' => 'DESC', 'ID' => 'DESC'),
            'no_found_rows' => false,
        );
        if ('' !== $search) {
            $query_args['s'] = $search;
        }
        if ('' !== $mime) {
            $query_args['post_mime_type'] = $mime;
        }

        $query = new WP_Query($query_args);
        $items = array();
        foreach ($query->posts as $post) {
            if (! $post instanceof WP_Post) { continue; }
            $items[] = $this->normalise_attachment($post);
        }

        return array(
            'items' => $items,
            'count' => count($items),
            'total' => (int) $query->found_posts,
            'total_pages' => (int) $query->max_num_pages,
            'page' => $page,
            'per_page' => $per_page,
            'filters' => array(
                'search' => $search,
                'mime_type' => $mime,
            ),
            'read_only' => true,
        );
    }

    /**
     * @return array<string,mixed>|WP_Error
     */
    public function inspect(int $attachment_id): array|WP_Error {
        if ($attachment_id <= 0) {
            return new WP_Error('media_not_found', 'Media attachment not found.', array('status' => 404));
        }
        $post = get_post($attachment_id);
        if (! $post instanceof WP_Post || 'attachment' !== $post->post_type || 'inherit' !== $post->post_status) {
            return new WP_Error('media_not_found', 'Media attachment not found.', array('status' => 404));
        }
        return $this->normalise_attachment($post);
    }

    /** @return array<string,mixed> */
    private function normalise_attachment(WP_Post $post): array {
        $id = (int) $post->ID;
        $mime = (string) get_post_mime_type($id);
        $media_type = '' !== $mime ? (string) strtok($mime, '/') : '';
        $is_image = 'image' === $media_type;
        $url = wp_get_attachment_url($id);
        $url = is_string($url) ? $url : '';
        $metadata = wp_get_attachment_metadata($id);
        $metadata = is_array($metadata) ? $metadata : array();
        $width = isset($metadata['width']) ? max(0, (int) $metadata['width']) : 0;
        $height = isset($metadata['height']) ? max(0, (int) $metadata['height']) : 0;
        $alt = trim((string) get_post_meta($id, '_wp_attachment_image_alt', true));
        $parent_id = max(0, (int) $post->post_parent);

        $readiness = array(
            'public_url_available' => '' !== $url,
            'unattached' => 0 === $parent_id,
            'missing_alt' => $is_image && '' === $alt,
            'dimensions_known' => ! $is_image || ($width > 0 && $height > 0),
        );
        $readiness['metadata_ready'] = $readiness['public_url_available']
            && ! $readiness['missing_alt']
            && $readiness['dimensions_known'];

        return array(
            'id' => $id,
            'title' => get_the_title($id),
            'alt_text' => $alt,
            'caption' => (string) $post->post_excerpt,
            'description' => (string) $post->post_content,
            'mime_type' => $mime,
            'media_type' => $media_type,
            'source_url' => $url,
            'parent_id' => $parent_id,
            'status' => (string) $post->post_status,
            'uploaded_at' => get_post_time(DATE_W3C, true, $post),
            'modified_at' => get_post_modified_time(DATE_W3C, true, $post),
            'width' => $is_image ? $width : null,
            'height' => $is_image ? $height : null,
            'readiness' => $readiness,
        );
    }

    private function normalise_mime_filter(string $value): string|WP_Error {
        $value = strtolower(trim(sanitize_text_field($value)));
        if ('' === $value || 'all' === $value) {
            return '';
        }
        if ('pdf' === $value) {
            return 'application/pdf';
        }
        $allowed = array('image', 'audio', 'video', 'application/pdf');
        if (! in_array($value, $allowed, true)) {
            return new WP_Error(
                'validation_failed',
                'mime_type must be one of image, audio, video, pdf or application/pdf.',
                array('status' => 400)
            );
        }
        return $value;
    }
}
