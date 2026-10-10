<?php
/** Verified relationships between Research Lines, Insights and public research objects. */
declare(strict_types=1);
if (! defined('ABSPATH')) { exit; }

function eduardo_research_relation_object_types(): array {
    return array('research_output','research_project','research_software','research_dataset');
}

function eduardo_research_relation_source_types(): array {
    return array_merge(eduardo_research_relation_object_types(), array('post'));
}

function eduardo_research_sanitize_relation_ids($value): array {
    if (! is_array($value)) { return array(); }
    $ids = array_map('absint', $value);
    return array_values(array_unique(array_filter($ids)));
}

function eduardo_research_register_relation_meta(): void {
    foreach (eduardo_research_relation_source_types() as $post_type) {
        register_post_meta($post_type, '_research_line_ids', array(
            'type'=>'array',
            'single'=>true,
            'show_in_rest'=>array('schema'=>array('type'=>'array','items'=>array('type'=>'integer'))),
            'sanitize_callback'=>'eduardo_research_sanitize_relation_ids',
            'auth_callback'=>static fn() => current_user_can('edit_posts'),
        ));
    }
}
add_action('init', 'eduardo_research_register_relation_meta', 17);

function eduardo_research_line_is_verified_public(int $line_id, ?string $language = null): bool {
    $post = get_post($line_id);
    if (! $post instanceof WP_Post || 'research_line' !== $post->post_type || 'publish' !== $post->post_status) { return false; }
    if ('verified' !== sanitize_key((string) get_post_meta($line_id, '_research_evidence_status', true))) { return false; }
    $language = $language ?: eduardo_research_post_language($line_id);
    return eduardo_research_post_language($line_id) === $language;
}

function eduardo_research_is_managed_insight(int $post_id): bool {
    if ('post' !== get_post_type($post_id) || ! metadata_exists('post', $post_id, '_research_insight_type')) { return false; }
    $type = sanitize_key((string) get_post_meta($post_id, '_research_insight_type', true));
    return function_exists('eduardo_research_insight_types') && array_key_exists($type, eduardo_research_insight_types('en'));
}

function eduardo_research_post_line_ids(int $post_id, ?string $language = null): array {
    $post_type = (string) get_post_type($post_id);
    if (! in_array($post_type, eduardo_research_relation_source_types(), true)) { return array(); }
    if ('post' === $post_type && ! eduardo_research_is_managed_insight($post_id)) { return array(); }
    $language = $language ?: eduardo_research_post_language($post_id);
    $ids = eduardo_research_sanitize_relation_ids(get_post_meta($post_id, '_research_line_ids', true));
    return array_values(array_filter($ids, static fn(int $line_id): bool => eduardo_research_line_is_verified_public($line_id, $language)));
}

function eduardo_research_relation_meta_clause(array $line_ids): array {
    $line_ids = eduardo_research_sanitize_relation_ids($line_ids);
    if (! $line_ids) { return array(array('key'=>'_research_line_ids','value'=>'__none__','compare'=>'=')); }
    $clauses = array('relation'=>'OR');
    foreach ($line_ids as $line_id) {
        // Registered meta stores integer arrays, but retain compatibility with older string arrays.
        $clauses[] = array('key'=>'_research_line_ids','value'=>'i:' . $line_id . ';','compare'=>'LIKE');
        $clauses[] = array('key'=>'_research_line_ids','value'=>'"' . $line_id . '"','compare'=>'LIKE');
    }
    return $clauses;
}

function eduardo_research_related_objects_query_for_lines(array $line_ids, ?string $language = null, int $limit = 12, int $exclude_post_id = 0): WP_Query {
    $language = $language ?: eduardo_research_current_language();
    $meta_query = array(
        'relation'=>'AND',
        eduardo_research_content_language_meta_query($language),
        eduardo_research_relation_meta_clause($line_ids),
    );
    return new WP_Query(array(
        'post_type'=>eduardo_research_relation_object_types(),
        'post_status'=>'publish',
        'posts_per_page'=>max(1, min(48, $limit)),
        'post__not_in'=>$exclude_post_id > 0 ? array($exclude_post_id) : array(),
        'orderby'=>'modified',
        'order'=>'DESC',
        'no_found_rows'=>true,
        'meta_query'=>$meta_query,
    ));
}

function eduardo_research_related_objects_query_for_line(int $line_id, int $limit = 24): WP_Query {
    if (! eduardo_research_line_is_verified_public($line_id)) {
        return new WP_Query(array('post__in'=>array(0)));
    }
    return eduardo_research_related_objects_query_for_lines(array($line_id), eduardo_research_post_language($line_id), $limit);
}

function eduardo_research_related_objects_query_for_post(int $post_id, int $limit = 12): WP_Query {
    $line_ids = eduardo_research_post_line_ids($post_id);
    if (! $line_ids) { return new WP_Query(array('post__in'=>array(0))); }
    return eduardo_research_related_objects_query_for_lines($line_ids, eduardo_research_post_language($post_id), $limit, $post_id);
}

function eduardo_research_surface_key_for_type(string $post_type): string {
    return array(
        'research_output'=>'publications',
        'research_project'=>'projects',
        'research_software'=>'software',
        'research_dataset'=>'datasets',
    )[$post_type] ?? 'research';
}

function eduardo_research_render_research_context(int $post_id): void {
    $line_ids = eduardo_research_post_line_ids($post_id);
    if (! $line_ids) { return; }
    $es = 'es' === eduardo_research_current_language();
    ?>
    <section class="research-section research-relations-section research-section-soft" data-research-relations="lines">
      <div class="research-shell">
        <div class="research-section-heading">
          <div><div class="research-eyebrow"><?php echo esc_html($es ? 'Contexto de investigación' : 'Research context'); ?></div><h2><?php echo esc_html($es ? 'Líneas relacionadas' : 'Related research lines'); ?></h2></div>
          <p><?php echo esc_html($es ? 'Este registro está vinculado únicamente a líneas de investigación verificadas.' : 'This record is linked only to verified research lines.'); ?></p>
        </div>
        <div class="research-grid research-relation-line-grid">
          <?php foreach ($line_ids as $line_id) : $line = get_post($line_id); if (! $line instanceof WP_Post) { continue; } ?>
            <a class="research-relation-line-card" data-research-line-id="<?php echo esc_attr((string) $line_id); ?>" href="<?php echo esc_url((string) get_permalink($line_id)); ?>">
              <span class="research-card-kicker"><?php echo esc_html($es ? 'Línea verificada' : 'Verified line'); ?></span>
              <strong><?php echo esc_html(get_the_title($line_id)); ?></strong>
              <?php $question = eduardo_research_meta_value($line_id, '_research_central_question'); if ($question) : ?><span><?php echo esc_html($question); ?></span><?php endif; ?>
              <span class="research-card-arrow" aria-hidden="true">↗</span>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
    </section>
    <?php
}

function eduardo_research_render_related_objects(int $post_id): void {
    $type = get_post_type($post_id);
    if ('research_line' === $type) {
        $query = eduardo_research_related_objects_query_for_line($post_id, 24);
    } elseif (in_array($type, eduardo_research_relation_object_types(), true) || ('post' === $type && eduardo_research_is_managed_insight($post_id))) {
        $query = eduardo_research_related_objects_query_for_post($post_id, 12);
    } else {
        return;
    }
    if (! $query->have_posts()) { wp_reset_postdata(); return; }
    $es = 'es' === eduardo_research_current_language();
    ?>
    <section class="research-section research-relations-section" data-research-relations="objects">
      <div class="research-shell">
        <div class="research-section-heading">
          <div><div class="research-eyebrow"><?php echo esc_html($es ? 'Red de investigación' : 'Research network'); ?></div><h2><?php echo esc_html($es ? 'Trabajos relacionados' : 'Related research objects'); ?></h2></div>
          <p><?php echo esc_html($es ? 'Relaciones derivadas de líneas de investigación verificadas compartidas.' : 'Relationships derived from shared verified research lines.'); ?></p>
        </div>
        <div class="research-grid research-grid-editorial">
          <?php while ($query->have_posts()) : $query->the_post(); $related_id = get_the_ID(); $surface_key = eduardo_research_surface_key_for_type((string) get_post_type($related_id)); eduardo_research_render_collection_card($related_id, $surface_key); endwhile; ?>
        </div>
      </div>
    </section>
    <?php
    wp_reset_postdata();
}

function eduardo_research_legacy_line_title(array $record, string $fallback = ''): string {
    foreach (array('title','label','value') as $field) {
        if (isset($record[$field]) && is_scalar($record[$field]) && '' !== trim((string) $record[$field])) { return trim((string) $record[$field]); }
    }
    return $fallback;
}

function eduardo_research_legacy_line_has_spanish(array $record): bool {
    foreach (array('title_es','label_es','summary_es','value_es') as $field) {
        if (isset($record[$field]) && is_scalar($record[$field]) && '' !== trim((string) $record[$field])) { return true; }
    }
    return isset($record['translations']['es']) && is_array($record['translations']['es']);
}

function eduardo_research_insert_migrated_line(array $record, string $language, int $order): int {
    $localized = 'es' === $language ? eduardo_research_localize_record($record, 'es') : $record;
    $title = eduardo_research_legacy_line_title($localized);
    if ('' === $title) { return 0; }
    $summary = isset($localized['summary']) && is_scalar($localized['summary']) ? trim((string) $localized['summary']) : '';
    $id = wp_insert_post(array(
        'post_type'=>'research_line', 'post_status'=>'publish', 'post_title'=>$title,
        'post_name'=>sanitize_title($title), 'post_excerpt'=>$summary, 'post_content'=>'',
        'menu_order'=>$order,
    ), true);
    if (is_wp_error($id)) { return 0; }
    $id = (int) $id;
    update_post_meta($id, '_research_language', $language);
    update_post_meta($id, '_research_evidence_status', 'verified');
    update_post_meta($id, '_research_order', (string) $order);
    foreach (array('question'=>'_research_central_question','central_question'=>'_research_central_question','research_status'=>'_research_status') as $source => $target) {
        if (isset($localized[$source]) && is_scalar($localized[$source]) && '' !== trim((string) $localized[$source])) { update_post_meta($id, $target, sanitize_text_field((string) $localized[$source])); }
    }
    foreach (array('topics'=>'_research_topics','methods'=>'_research_methods') as $source => $target) {
        if (isset($localized[$source]) && is_array($localized[$source])) { update_post_meta($id, $target, array_values(array_filter(array_map('sanitize_text_field', $localized[$source])))); }
    }
    update_post_meta($id, '_research_legacy_migration', 'v1');
    return $id;
}

/** Move the deprecated evidence-store research_lines group into first-class CPT records once. */
function eduardo_research_maybe_migrate_legacy_lines(): void {
    if ('1' === (string) get_option('eduardo_research_legacy_lines_migrated_v1', '0')) { return; }
    if (wp_installing()) { return; }
    $store = eduardo_research_evidence_store();
    $records = isset($store['research_lines']) && is_array($store['research_lines']) ? $store['research_lines'] : array();
    $migrated = 0;
    $order = 0;
    foreach ($records as $record) {
        if (! is_array($record) || 'verified' !== sanitize_key((string) ($record['status'] ?? 'unverified'))) { continue; }
        $order++;
        $en_id = eduardo_research_insert_migrated_line($record, 'en', $order);
        if ($en_id <= 0) { continue; }
        $migrated++;
        if (eduardo_research_legacy_line_has_spanish($record)) {
            $es_id = eduardo_research_insert_migrated_line($record, 'es', $order);
            if ($es_id > 0) {
                update_post_meta($en_id, '_research_translation_es', $es_id);
                update_post_meta($es_id, '_research_translation_en', $en_id);
            }
        }
    }
    if ($records) {
        update_option('eduardo_research_legacy_research_lines_v1', $records, false);
        unset($store['research_lines']);
        update_option('eduardo_research_evidence', $store);
    }
    update_option('eduardo_research_legacy_lines_migrated_v1', '1', false);
    update_option('eduardo_research_legacy_lines_migrated_count_v1', $migrated, false);
}
add_action('init', 'eduardo_research_maybe_migrate_legacy_lines', 31);
