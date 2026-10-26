<?php
/** Theme-owned bounded Media relationship and rendering helpers. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

function eduardo_research_primary_media_option_key(string $page_key, string $language): string {
    return 'eduardo_research_media_primary_' . sanitize_key($page_key) . '_' . sanitize_key($language);
}

function eduardo_research_primary_media_id(int $post_id = 0, string $page_key = '', string $language = ''): int {
    if ($post_id > 0) {
        $attachment_id = absint(get_post_meta($post_id, '_eduardo_research_primary_media_id', true));
    } else {
        $page_key = sanitize_key($page_key);
        $language = sanitize_key($language ?: eduardo_research_current_language());
        if ('' === $page_key || ! isset(eduardo_research_preset()['pages'][$page_key]) || ! isset(eduardo_research_languages()[$language])) {
            return 0;
        }
        $attachment_id = absint(get_option(eduardo_research_primary_media_option_key($page_key, $language), 0));
    }

    if ($attachment_id <= 0) { return 0; }
    $attachment = get_post($attachment_id);
    if (! $attachment instanceof WP_Post || 'attachment' !== $attachment->post_type || 'inherit' !== $attachment->post_status) {
        return 0;
    }
    $mime = (string) get_post_mime_type($attachment_id);
    return str_starts_with($mime, 'image/') ? $attachment_id : 0;
}

function eduardo_research_render_primary_media(int $post_id = 0, string $page_key = '', string $language = ''): void {
    $attachment_id = eduardo_research_primary_media_id($post_id, $page_key, $language);
    if ($attachment_id <= 0) { return; }
    $image = wp_get_attachment_image($attachment_id, 'large', false, array(
        'class'=>'research-primary-media-image',
        'decoding'=>'async',
    ));
    if (! is_string($image) || '' === trim($image)) { return; }
    $caption = trim((string) wp_get_attachment_caption($attachment_id));
    ?>
    <section class="research-primary-media-section" aria-label="<?php echo esc_attr('es' === eduardo_research_current_language() ? 'Imagen principal' : 'Primary image'); ?>">
     <div class="research-shell">
      <figure class="research-primary-media" data-research-media-slot="primary" data-attachment-id="<?php echo esc_attr((string) $attachment_id); ?>">
       <?php echo wp_kses_post($image); ?>
       <?php if ('' !== $caption) : ?><figcaption><?php echo esc_html($caption); ?></figcaption><?php endif; ?>
      </figure>
     </div>
    </section>
    <?php
}
