<?php
/** Academic collection filtering for Publications, Projects, Software and Datasets. */
declare(strict_types=1);
if (! defined('ABSPATH')) { exit; }

function eduardo_research_collection_post_type(string $surface): string {
    return array(
        'publications'=>'research_output',
        'projects'=>'research_project',
        'software'=>'research_software',
        'datasets'=>'research_dataset',
    )[$surface] ?? '';
}

function eduardo_research_collection_options(string $surface, ?string $language = null): array {
    $language = $language ?: eduardo_research_current_language();
    $es = 'es' === $language;
    $options = array(
        'sort'=>array(
            'recent'=>$es?'Añadidos recientemente':'Recently added',
            'updated'=>$es?'Actualizados recientemente':'Recently updated',
            'oldest'=>$es?'Más antiguos primero':'Oldest first',
            'title'=>$es?'Título A–Z':'Title A–Z',
        ),
    );
    if ('publications' === $surface) {
        $options['output_type'] = array(
            'journal_article'=>eduardo_research_output_type_label('journal_article',$language),
            'conference_paper'=>eduardo_research_output_type_label('conference_paper',$language),
            'book_chapter'=>eduardo_research_output_type_label('book_chapter',$language),
            'book'=>eduardo_research_output_type_label('book',$language),
            'preprint'=>eduardo_research_output_type_label('preprint',$language),
            'working_paper'=>eduardo_research_output_type_label('working_paper',$language),
            'technical_report'=>eduardo_research_output_type_label('technical_report',$language),
            'thesis'=>eduardo_research_output_type_label('thesis',$language),
            'poster'=>eduardo_research_output_type_label('poster',$language),
            'other'=>eduardo_research_output_type_label('other',$language),
        );
        $options['review_status'] = array(
            'peer_reviewed'=>eduardo_research_review_status_label('peer_reviewed',$language),
            'accepted'=>eduardo_research_review_status_label('accepted',$language),
            'under_review'=>eduardo_research_review_status_label('under_review',$language),
            'submitted'=>eduardo_research_review_status_label('submitted',$language),
            'preprint'=>eduardo_research_review_status_label('preprint',$language),
            'working_draft'=>eduardo_research_review_status_label('working_draft',$language),
            'technical_output'=>eduardo_research_review_status_label('technical_output',$language),
        );
    } elseif ('projects' === $surface) {
        $options['project_status'] = array(
            'planning'=>$es?'Planificación':'Planning','active'=>$es?'Activo':'Active','completed'=>$es?'Completado':'Completed',
            'paused'=>$es?'Pausado':'Paused','archived'=>$es?'Archivado':'Archived',
        );
    } elseif ('software' === $surface) {
        $options['software_status'] = array(
            'active'=>$es?'Activo':'Active','maintained'=>$es?'Mantenido':'Maintained','experimental'=>$es?'Experimental':'Experimental',
            'archived'=>$es?'Archivado':'Archived',
        );
    } elseif ('datasets' === $surface) {
        $options['access_level'] = array(
            'open'=>$es?'Abierto':'Open','restricted'=>$es?'Restringido':'Restricted','embargoed'=>$es?'Embargado':'Embargoed',
            'request_access'=>$es?'Acceso bajo solicitud':'Request access','closed'=>$es?'Cerrado':'Closed',
        );
    }
    return $options;
}

function eduardo_research_collection_filter_state(string $surface, ?string $language = null): array {
    $language = $language ?: eduardo_research_current_language();
    $options = eduardo_research_collection_options($surface, $language);
    $line_id = isset($_GET['research_line']) ? absint(wp_unslash($_GET['research_line'])) : 0;
    if ($line_id > 0 && ! eduardo_research_line_is_verified_public($line_id, $language)) { $line_id = 0; }
    $sort = isset($_GET['sort']) ? sanitize_key((string) wp_unslash($_GET['sort'])) : 'recent';
    if (! isset($options['sort'][$sort])) { $sort = 'recent'; }
    $state = array(
        'research_line'=>$line_id,
        'sort'=>$sort,
        'research_page'=>max(1, isset($_GET['research_page']) ? absint(wp_unslash($_GET['research_page'])) : 1),
    );
    foreach (array('output_type','review_status','project_status','software_status','access_level') as $key) {
        if (! isset($options[$key])) { continue; }
        $value = isset($_GET[$key]) ? sanitize_key((string) wp_unslash($_GET[$key])) : '';
        $state[$key] = isset($options[$key][$value]) ? $value : '';
    }
    $year = isset($_GET['year']) ? preg_replace('/[^0-9]/', '', (string) wp_unslash($_GET['year'])) : '';
    $current_year = (int) gmdate('Y');
    $state['year'] = (4 === strlen($year) && (int) $year >= 1900 && (int) $year <= $current_year + 1) ? $year : '';
    return $state;
}

function eduardo_research_collection_query_args(string $surface, ?string $language = null): array {
    $language = $language ?: eduardo_research_current_language();
    $state = eduardo_research_collection_filter_state($surface, $language);
    $post_type = eduardo_research_collection_post_type($surface);
    if ('' === $post_type) { return array('post__in'=>array(0)); }

    $meta_query = array('relation'=>'AND', eduardo_research_content_language_meta_query($language));
    if ($state['research_line'] > 0) { $meta_query[] = eduardo_research_relation_meta_clause(array($state['research_line'])); }
    if (! empty($state['output_type'])) { $meta_query[] = array('key'=>'_research_output_type','value'=>$state['output_type'],'compare'=>'='); }
    if (! empty($state['review_status'])) { $meta_query[] = array('key'=>'_research_review_status','value'=>$state['review_status'],'compare'=>'='); }
    if (! empty($state['project_status'])) { $meta_query[] = array('key'=>'_research_project_status','value'=>$state['project_status'],'compare'=>'='); }
    if (! empty($state['software_status'])) { $meta_query[] = array('key'=>'_research_software_status','value'=>$state['software_status'],'compare'=>'='); }
    if (! empty($state['access_level'])) { $meta_query[] = array('key'=>'_research_access_level','value'=>$state['access_level'],'compare'=>'='); }
    if ('publications' === $surface && ! empty($state['year'])) { $meta_query[] = array('key'=>'_research_publication_date','value'=>$state['year'],'compare'=>'LIKE'); }

    $args = array(
        'post_type'=>$post_type,
        'post_status'=>'publish',
        'posts_per_page'=>12,
        'paged'=>$state['research_page'],
        'no_found_rows'=>false,
        'meta_query'=>$meta_query,
    );
    if ('title' === $state['sort']) { $args['orderby'] = 'title'; $args['order'] = 'ASC'; }
    elseif ('oldest' === $state['sort']) { $args['orderby'] = 'date'; $args['order'] = 'ASC'; }
    elseif ('updated' === $state['sort']) { $args['orderby'] = 'modified'; $args['order'] = 'DESC'; }
    else { $args['orderby'] = 'date'; $args['order'] = 'DESC'; }
    return $args;
}

function eduardo_research_collection_query(string $surface, ?string $language = null): WP_Query {
    return new WP_Query(eduardo_research_collection_query_args($surface, $language));
}

function eduardo_research_collection_lines(?string $language = null): array {
    $language = $language ?: eduardo_research_current_language();
    $query = new WP_Query(eduardo_research_verified_line_query_args($language, 100));
    $lines = array();
    foreach ($query->posts as $post) {
        if ($post instanceof WP_Post) { $lines[(int) $post->ID] = $post->post_title; }
    }
    return $lines;
}

function eduardo_research_render_collection_filters(string $surface): void {
    $language = eduardo_research_current_language();
    $es = 'es' === $language;
    $state = eduardo_research_collection_filter_state($surface, $language);
    $options = eduardo_research_collection_options($surface, $language);
    $lines = eduardo_research_collection_lines($language);
    ?>
    <form class="research-collection-filters" method="get" action="<?php echo esc_url(eduardo_research_page_url($surface)); ?>">
      <div class="research-filter-field">
        <label for="research-line-filter"><?php echo esc_html($es ? 'Línea de investigación' : 'Research line'); ?></label>
        <select id="research-line-filter" name="research_line">
          <option value="0"><?php echo esc_html($es ? 'Todas las líneas' : 'All research lines'); ?></option>
          <?php foreach ($lines as $line_id => $title) : ?><option value="<?php echo esc_attr((string) $line_id); ?>" <?php selected($state['research_line'], $line_id); ?>><?php echo esc_html($title); ?></option><?php endforeach; ?>
        </select>
      </div>
      <?php if (isset($options['output_type'])) : ?>
       <div class="research-filter-field"><label for="output-type-filter"><?php echo esc_html($es?'Tipo':'Type'); ?></label><select id="output-type-filter" name="output_type"><option value=""><?php echo esc_html($es?'Todos los tipos':'All types'); ?></option><?php foreach ($options['output_type'] as $value=>$label) : ?><option value="<?php echo esc_attr($value); ?>" <?php selected($state['output_type'], $value); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select></div>
       <div class="research-filter-field"><label for="review-filter"><?php echo esc_html($es?'Estado académico':'Academic status'); ?></label><select id="review-filter" name="review_status"><option value=""><?php echo esc_html($es?'Todos los estados':'All statuses'); ?></option><?php foreach ($options['review_status'] as $value=>$label) : ?><option value="<?php echo esc_attr($value); ?>" <?php selected($state['review_status'], $value); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select></div>
       <div class="research-filter-field research-filter-year"><label for="year-filter"><?php echo esc_html($es?'Año':'Year'); ?></label><input id="year-filter" type="number" name="year" min="1900" max="<?php echo esc_attr((string) ((int) gmdate('Y') + 1)); ?>" value="<?php echo esc_attr($state['year']); ?>" placeholder="<?php echo esc_attr((string) gmdate('Y')); ?>"></div>
      <?php elseif (isset($options['project_status'])) : ?>
       <div class="research-filter-field"><label for="project-status-filter"><?php echo esc_html($es?'Estado':'Status'); ?></label><select id="project-status-filter" name="project_status"><option value=""><?php echo esc_html($es?'Todos los estados':'All statuses'); ?></option><?php foreach ($options['project_status'] as $value=>$label) : ?><option value="<?php echo esc_attr($value); ?>" <?php selected($state['project_status'], $value); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select></div>
      <?php elseif (isset($options['software_status'])) : ?>
       <div class="research-filter-field"><label for="software-status-filter"><?php echo esc_html($es?'Estado':'Status'); ?></label><select id="software-status-filter" name="software_status"><option value=""><?php echo esc_html($es?'Todos los estados':'All statuses'); ?></option><?php foreach ($options['software_status'] as $value=>$label) : ?><option value="<?php echo esc_attr($value); ?>" <?php selected($state['software_status'], $value); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select></div>
      <?php elseif (isset($options['access_level'])) : ?>
       <div class="research-filter-field"><label for="access-filter"><?php echo esc_html($es?'Acceso':'Access'); ?></label><select id="access-filter" name="access_level"><option value=""><?php echo esc_html($es?'Todos los niveles':'All access levels'); ?></option><?php foreach ($options['access_level'] as $value=>$label) : ?><option value="<?php echo esc_attr($value); ?>" <?php selected($state['access_level'], $value); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select></div>
      <?php endif; ?>
      <div class="research-filter-field"><label for="sort-filter"><?php echo esc_html($es?'Ordenar':'Sort'); ?></label><select id="sort-filter" name="sort"><?php foreach ($options['sort'] as $value=>$label) : ?><option value="<?php echo esc_attr($value); ?>" <?php selected($state['sort'], $value); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select></div>
      <div class="research-filter-actions"><button class="research-button" type="submit"><?php echo esc_html($es?'Aplicar filtros':'Apply filters'); ?></button><a class="research-filter-reset" href="<?php echo esc_url(eduardo_research_page_url($surface)); ?>"><?php echo esc_html($es?'Limpiar':'Clear'); ?></a></div>
    </form>
    <?php
}

function eduardo_research_collection_query_url(string $surface, array $state, int $page): string {
    $args = array();
    foreach (array('research_line','output_type','review_status','project_status','software_status','access_level','year','sort') as $key) {
        if (! isset($state[$key]) || '' === (string) $state[$key] || 0 === (int) $state[$key] && 'research_line' === $key) { continue; }
        if ('sort' === $key && 'recent' === $state[$key]) { continue; }
        $args[$key] = $state[$key];
    }
    if ($page > 1) { $args['research_page'] = $page; }
    return add_query_arg($args, eduardo_research_page_url($surface));
}

function eduardo_research_render_collection_summary(string $surface, WP_Query $query): void {
    $es = 'es' === eduardo_research_current_language();
    $count = (int) $query->found_posts;
    ?><div class="research-collection-summary"><strong><?php echo esc_html(sprintf($es ? '%d registros' : '%d records', $count)); ?></strong><span><?php echo esc_html($es ? 'Resultados que cumplen los filtros activos.' : 'Results matching the active filters.'); ?></span></div><?php
}

function eduardo_research_render_collection_pagination(string $surface, WP_Query $query): void {
    $max = (int) $query->max_num_pages;
    if ($max <= 1) { return; }
    $state = eduardo_research_collection_filter_state($surface);
    $current = max(1, (int) $state['research_page']);
    $es = 'es' === eduardo_research_current_language();
    ?>
    <nav class="research-collection-pagination" aria-label="<?php echo esc_attr($es?'Paginación de resultados':'Results pagination'); ?>">
      <div><?php if ($current > 1) : ?><a href="<?php echo esc_url(eduardo_research_collection_query_url($surface, $state, $current - 1)); ?>">← <?php echo esc_html($es?'Anterior':'Previous'); ?></a><?php endif; ?></div>
      <span><?php echo esc_html(sprintf($es?'Página %1$d de %2$d':'Page %1$d of %2$d', $current, $max)); ?></span>
      <div><?php if ($current < $max) : ?><a href="<?php echo esc_url(eduardo_research_collection_query_url($surface, $state, $current + 1)); ?>"><?php echo esc_html($es?'Siguiente':'Next'); ?> →</a><?php endif; ?></div>
    </nav>
    <?php
}
