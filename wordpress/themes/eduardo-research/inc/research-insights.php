<?php
/** Theme-owned editorial runtime for research Insights. */
declare(strict_types=1);
if (! defined('ABSPATH')) { exit; }

function eduardo_research_insight_types(?string $language = null): array {
    $language = $language ?: eduardo_research_current_language();
    $es = 'es' === $language;
    return array(
        'research_note'=>$es ? 'Nota de investigación' : 'Research note',
        'explainer'=>$es ? 'Explicador' : 'Explainer',
        'method_note'=>$es ? 'Nota metodológica' : 'Method note',
        'working_note'=>$es ? 'Nota de trabajo' : 'Working note',
        'commentary'=>$es ? 'Comentario' : 'Commentary',
    );
}

function eduardo_research_register_insight_meta(): void {
    register_post_meta('post', '_research_insight_type', array(
        'type'=>'string','single'=>true,'show_in_rest'=>true,
        'sanitize_callback'=>static function($value): string {
            $value = sanitize_key((string) $value);
            return array_key_exists($value, eduardo_research_insight_types('en')) ? $value : 'research_note';
        },
        'auth_callback'=>static fn() => current_user_can('edit_posts'),
    ));
}
add_action('init', 'eduardo_research_register_insight_meta', 18);

function eduardo_research_insight_type(int $post_id): string {
    $type = sanitize_key((string) get_post_meta($post_id, '_research_insight_type', true));
    return array_key_exists($type, eduardo_research_insight_types('en')) ? $type : 'research_note';
}

function eduardo_research_insight_type_label(int $post_id, ?string $language = null): string {
    $types = eduardo_research_insight_types($language);
    return (string) ($types[eduardo_research_insight_type($post_id)] ?? reset($types));
}

function eduardo_research_insight_filter_state(?string $language = null): array {
    $language = $language ?: eduardo_research_current_language();
    $types = eduardo_research_insight_types($language);
    $type = isset($_GET['insight_type']) ? sanitize_key((string) wp_unslash($_GET['insight_type'])) : '';
    if (! isset($types[$type])) { $type = ''; }
    $search = isset($_GET['insight_search']) ? sanitize_text_field((string) wp_unslash($_GET['insight_search'])) : '';
    $page = max(1, isset($_GET['insight_page']) ? absint(wp_unslash($_GET['insight_page'])) : 1);
    return array('insight_type'=>$type,'insight_search'=>$search,'insight_page'=>$page);
}

function eduardo_research_insight_query(?string $language = null): WP_Query {
    $language = $language ?: eduardo_research_current_language();
    $state = eduardo_research_insight_filter_state($language);
    $args = eduardo_research_localized_query_args(array(
        'post_type'=>'post','post_status'=>'publish','posts_per_page'=>9,'paged'=>$state['insight_page'],
        'orderby'=>'date','order'=>'DESC','no_found_rows'=>false,
    ), $language);
    if ('' !== $state['insight_search']) { $args['s'] = $state['insight_search']; }
    if ('' !== $state['insight_type']) {
        $base = $args['meta_query'] ?? array();
        $args['meta_query'] = array('relation'=>'AND', $base, array('key'=>'_research_insight_type','value'=>$state['insight_type'],'compare'=>'='));
    }
    return new WP_Query($args);
}

function eduardo_research_render_insight_filters(): void {
    $language = eduardo_research_current_language();
    $es = 'es' === $language;
    $state = eduardo_research_insight_filter_state($language);
    $types = eduardo_research_insight_types($language);
    ?>
    <form class="research-insight-filters" method="get" action="<?php echo esc_url(eduardo_research_page_url('insights')); ?>" role="search">
      <div class="research-insight-search-field">
        <label for="research-insight-search"><?php echo esc_html($es ? 'Buscar en notas' : 'Search research notes'); ?></label>
        <input id="research-insight-search" type="search" name="insight_search" value="<?php echo esc_attr($state['insight_search']); ?>" placeholder="<?php echo esc_attr($es ? 'Buscar tema, método o concepto…' : 'Search topic, method or concept…'); ?>">
      </div>
      <div class="research-insight-type-field">
        <label for="research-insight-type"><?php echo esc_html($es ? 'Tipo editorial' : 'Editorial type'); ?></label>
        <select id="research-insight-type" name="insight_type">
          <option value=""><?php echo esc_html($es ? 'Todos los tipos' : 'All types'); ?></option>
          <?php foreach ($types as $value => $label) : ?><option value="<?php echo esc_attr($value); ?>" <?php selected($state['insight_type'], $value); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="research-insight-filter-actions">
        <button class="research-button" type="submit"><?php echo esc_html($es ? 'Buscar' : 'Search'); ?></button>
        <a href="<?php echo esc_url(eduardo_research_page_url('insights')); ?>"><?php echo esc_html($es ? 'Limpiar' : 'Clear'); ?></a>
      </div>
    </form>
    <?php
}

function eduardo_research_render_insight_card(int $post_id): void {
    $es = 'es' === eduardo_research_current_language();
    ?>
    <article class="research-card research-insight-card">
      <div class="research-insight-card-meta"><span><?php echo esc_html(eduardo_research_insight_type_label($post_id)); ?></span><time datetime="<?php echo esc_attr(get_the_date('c', $post_id)); ?>"><?php echo esc_html(get_the_date('', $post_id)); ?></time></div>
      <h2><a href="<?php echo esc_url((string) get_permalink($post_id)); ?>"><?php echo esc_html(get_the_title($post_id)); ?></a></h2>
      <?php $excerpt = trim((string) get_the_excerpt($post_id)); if ('' !== $excerpt) : ?><p><?php echo esc_html($excerpt); ?></p><?php endif; ?>
      <a class="research-object-open" href="<?php echo esc_url((string) get_permalink($post_id)); ?>"><?php echo esc_html($es ? 'Leer nota' : 'Read note'); ?> <span aria-hidden="true">↗</span></a>
    </article>
    <?php
}

function eduardo_research_insight_query_url(array $state, int $page): string {
    $args = array();
    if ('' !== (string) ($state['insight_search'] ?? '')) { $args['insight_search'] = $state['insight_search']; }
    if ('' !== (string) ($state['insight_type'] ?? '')) { $args['insight_type'] = $state['insight_type']; }
    if ($page > 1) { $args['insight_page'] = $page; }
    return add_query_arg($args, eduardo_research_page_url('insights'));
}

function eduardo_research_render_insight_pagination(WP_Query $query): void {
    $max = (int) $query->max_num_pages;
    if ($max <= 1) { return; }
    $state = eduardo_research_insight_filter_state();
    $current = max(1, (int) $state['insight_page']);
    $es = 'es' === eduardo_research_current_language();
    ?>
    <nav class="research-collection-pagination" aria-label="<?php echo esc_attr($es ? 'Paginación de notas' : 'Research note pagination'); ?>">
      <div><?php if ($current > 1) : ?><a href="<?php echo esc_url(eduardo_research_insight_query_url($state, $current - 1)); ?>">← <?php echo esc_html($es ? 'Anterior' : 'Previous'); ?></a><?php endif; ?></div>
      <span><?php echo esc_html(sprintf($es ? 'Página %1$d de %2$d' : 'Page %1$d of %2$d', $current, $max)); ?></span>
      <div><?php if ($current < $max) : ?><a href="<?php echo esc_url(eduardo_research_insight_query_url($state, $current + 1)); ?>"><?php echo esc_html($es ? 'Siguiente' : 'Next'); ?> →</a><?php endif; ?></div>
    </nav>
    <?php
}
