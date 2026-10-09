<?php
/** Structured research objects and academic metadata contracts. */
declare(strict_types=1);
if (! defined('ABSPATH')) { exit; }

function eduardo_research_meta_value(int $post_id, string $key): string {
    $value = get_post_meta($post_id, $key, true);
    return is_scalar($value) ? trim((string) $value) : '';
}

function eduardo_research_meta_list(int $post_id, string $key): array {
    $value = get_post_meta($post_id, $key, true);
    if (is_array($value)) {
        return array_values(array_filter(array_map(static fn($item) => is_scalar($item) ? trim((string) $item) : '', $value)));
    }
    if (is_string($value) && '' !== trim($value)) {
        return array_values(array_filter(array_map('trim', preg_split('/[,\n]+/', $value) ?: array())));
    }
    return array();
}

function eduardo_research_output_authors(int $post_id): array {
    $authors = get_post_meta($post_id, '_research_authors', true);
    if (! is_array($authors)) { return array(); }
    $clean = array();
    foreach ($authors as $author) {
        if (is_string($author) && '' !== trim($author)) {
            $clean[] = array('display_name'=>trim($author));
            continue;
        }
        if (! is_array($author)) { continue; }
        $name = trim((string) ($author['display_name'] ?? ''));
        if ('' === $name) { continue; }
        $record = array('display_name'=>$name);
        foreach (array('given_name','family_name','orcid','affiliation') as $field) {
            if (isset($author[$field]) && is_scalar($author[$field]) && '' !== trim((string) $author[$field])) {
                $record[$field] = trim((string) $author[$field]);
            }
        }
        $record['is_site_researcher'] = ! empty($author['is_site_researcher']);
        $clean[] = $record;
    }
    return $clean;
}

function eduardo_research_register_object_meta(): void {
    $string_fields = array(
        'research_line'=>array('_research_evidence_status','_research_status','_research_central_question','_research_order'),
        'research_output'=>array('_research_output_type','_research_output_type_verified','_research_review_status','_research_publication_date','_research_venue','_research_publisher','_research_volume','_research_issue','_research_pages','_research_doi','_research_doi_verified','_research_pdf_url','_research_license','_research_open_access'),
        'research_project'=>array('_research_project_status','_research_question','_research_role','_research_start_date','_research_end_date','_research_partner','_research_funding','_research_project_url'),
        'research_software'=>array('_research_software_version','_research_release_date','_research_repository_url','_research_archive_url','_research_license','_research_documentation_url','_research_software_status','_research_doi','_research_doi_verified'),
        'research_dataset'=>array('_research_dataset_version','_research_publication_date','_research_repository','_research_doi','_research_doi_verified','_research_license','_research_access_level','_research_methodology','_research_provenance','_research_size','_research_documentation_url','_research_ethics_notes'),
    );
    $array_fields = array(
        'research_line'=>array('_research_topics','_research_methods'),
        'research_project'=>array('_research_methods'),
        'research_software'=>array('_research_programming_languages'),
        'research_dataset'=>array('_research_formats'),
    );

    foreach ($string_fields as $post_type => $fields) {
        foreach ($fields as $field) {
            register_post_meta($post_type, $field, array(
                'type'=>'string','single'=>true,'show_in_rest'=>true,
                'sanitize_callback'=>'sanitize_text_field','auth_callback'=>static fn() => current_user_can('edit_posts'),
            ));
        }
    }
    foreach ($array_fields as $post_type => $fields) {
        foreach ($fields as $field) {
            register_post_meta($post_type, $field, array(
                'type'=>'array','single'=>true,
                'show_in_rest'=>array('schema'=>array('type'=>'array','items'=>array('type'=>'string'))),
                'sanitize_callback'=>static function($value): array {
                    if (! is_array($value)) { return array(); }
                    return array_values(array_filter(array_map(static fn($item) => is_scalar($item) ? sanitize_text_field((string) $item) : '', $value)));
                },
                'auth_callback'=>static fn() => current_user_can('edit_posts'),
            ));
        }
    }
    register_post_meta('research_output', '_research_authors', array(
        'type'=>'array','single'=>true,
        'show_in_rest'=>array('schema'=>array('type'=>'array','items'=>array(
            'type'=>'object','properties'=>array(
                'display_name'=>array('type'=>'string'),'given_name'=>array('type'=>'string'),'family_name'=>array('type'=>'string'),
                'orcid'=>array('type'=>'string'),'affiliation'=>array('type'=>'string'),'is_site_researcher'=>array('type'=>'boolean'),
            ),
        ))),
        'sanitize_callback'=>static fn($value) => is_array($value) ? $value : array(),
        'auth_callback'=>static fn() => current_user_can('edit_posts'),
    ));
}
add_action('init', 'eduardo_research_register_object_meta', 16);

function eduardo_research_output_type_label(string $type, ?string $language = null): string {
    $language = $language ?: eduardo_research_current_language();
    $es = 'es' === $language;
    $labels = array(
        'journal_article'=>$es?'Artículo de revista':'Journal article','conference_paper'=>$es?'Ponencia':'Conference paper',
        'book_chapter'=>$es?'Capítulo de libro':'Book chapter','book'=>$es?'Libro':'Book','preprint'=>$es?'Preprint':'Preprint',
        'working_paper'=>$es?'Documento de trabajo':'Working paper','technical_report'=>$es?'Informe técnico':'Technical report',
        'thesis'=>$es?'Tesis':'Thesis','poster'=>$es?'Póster':'Poster','other'=>$es?'Otro resultado':'Other output',
    );
    return $labels[$type] ?? ($es ? 'Resultado de investigación' : 'Research output');
}

function eduardo_research_review_status_label(string $status, ?string $language = null): string {
    $language = $language ?: eduardo_research_current_language();
    $es = 'es' === $language;
    $labels = array(
        'peer_reviewed'=>$es?'Revisado por pares':'Peer reviewed','accepted'=>$es?'Aceptado':'Accepted',
        'under_review'=>$es?'En revisión':'Under review','submitted'=>$es?'Enviado':'Submitted',
        'preprint'=>'Preprint','working_draft'=>$es?'Borrador de trabajo':'Working draft','technical_output'=>$es?'Resultado técnico':'Technical output',
    );
    return $labels[$status] ?? '';
}

function eduardo_research_object_badges(int $post_id): array {
    $type = get_post_type($post_id);
    $badges = array();
    if ('research_output' === $type) {
        $output_type = sanitize_key(eduardo_research_meta_value($post_id, '_research_output_type'));
        $review = sanitize_key(eduardo_research_meta_value($post_id, '_research_review_status'));
        if ($output_type) { $badges[] = eduardo_research_output_type_label($output_type); }
        if ($review && eduardo_research_review_status_label($review)) { $badges[] = eduardo_research_review_status_label($review); }
        $year = eduardo_research_meta_value($post_id, '_research_publication_date');
        if ($year) { $badges[] = substr($year, 0, 4); }
    } elseif ('research_project' === $type) {
        $status = eduardo_research_meta_value($post_id, '_research_project_status');
        if ($status) { $badges[] = ucwords(str_replace('_',' ',$status)); }
        $role = eduardo_research_meta_value($post_id, '_research_role');
        if ($role) { $badges[] = $role; }
    } elseif ('research_software' === $type) {
        $version = eduardo_research_meta_value($post_id, '_research_software_version');
        if ($version) { $badges[] = 'v' . ltrim($version, 'vV'); }
        $license = eduardo_research_meta_value($post_id, '_research_license');
        if ($license) { $badges[] = $license; }
    } elseif ('research_dataset' === $type) {
        $version = eduardo_research_meta_value($post_id, '_research_dataset_version');
        if ($version) { $badges[] = 'v' . ltrim($version, 'vV'); }
        $access = eduardo_research_meta_value($post_id, '_research_access_level');
        if ($access) { $badges[] = ucwords(str_replace('_',' ',$access)); }
    } elseif ('research_line' === $type) {
        $status = eduardo_research_meta_value($post_id, '_research_status');
        if ($status) { $badges[] = ucwords(str_replace('_',' ',$status)); }
    }
    return array_values(array_unique(array_filter($badges)));
}

function eduardo_research_render_badges(int $post_id): void {
    $badges = eduardo_research_object_badges($post_id);
    if (! $badges) { return; }
    echo '<div class="research-object-badges">';
    foreach ($badges as $badge) { echo '<span>' . esc_html($badge) . '</span>'; }
    echo '</div>';
}

function eduardo_research_render_collection_card(int $post_id, string $surface_key): void {
    $label = eduardo_research_page_label($surface_key);
    ?>
    <article class="research-card research-object-card">
      <p class="research-card-kicker"><?php echo esc_html($label); ?></p>
      <h2><a href="<?php echo esc_url((string) get_permalink($post_id)); ?>"><?php echo esc_html(get_the_title($post_id)); ?></a></h2>
      <?php eduardo_research_render_badges($post_id); ?>
      <?php $excerpt = get_the_excerpt($post_id); if ('' !== trim($excerpt)) : ?><p><?php echo esc_html($excerpt); ?></p><?php endif; ?>
      <a class="research-object-open" href="<?php echo esc_url((string) get_permalink($post_id)); ?>"><?php echo esc_html('es' === eduardo_research_current_language() ? 'Ver registro' : 'View record'); ?> <span aria-hidden="true">↗</span></a>
    </article>
    <?php
}

function eduardo_research_object_meta_rows(int $post_id): array {
    $es = 'es' === eduardo_research_current_language();
    $type = get_post_type($post_id);
    $rows = array();
    $add = static function(array &$target, string $label, string $value): void { if ('' !== trim($value)) { $target[] = array($label,$value); } };

    if ('research_output' === $type) {
        $output_type = sanitize_key(eduardo_research_meta_value($post_id, '_research_output_type'));
        $review = sanitize_key(eduardo_research_meta_value($post_id, '_research_review_status'));
        $add($rows, $es?'Tipo':'Type', $output_type ? eduardo_research_output_type_label($output_type) : '');
        $add($rows, $es?'Estado académico':'Academic status', $review ? eduardo_research_review_status_label($review) : '');
        $add($rows, $es?'Fecha de publicación':'Publication date', eduardo_research_meta_value($post_id, '_research_publication_date'));
        $add($rows, $es?'Publicación / evento':'Venue', eduardo_research_meta_value($post_id, '_research_venue'));
        $add($rows, 'DOI', '1' === eduardo_research_meta_value($post_id, '_research_doi_verified') ? eduardo_research_meta_value($post_id, '_research_doi') : '');
        $add($rows, $es?'Acceso':'Access', eduardo_research_meta_value($post_id, '_research_open_access'));
        $add($rows, $es?'Licencia':'License', eduardo_research_meta_value($post_id, '_research_license'));
    } elseif ('research_project' === $type) {
        $add($rows, $es?'Estado':'Status', ucwords(str_replace('_',' ',eduardo_research_meta_value($post_id, '_research_project_status'))));
        $add($rows, $es?'Rol':'Role', eduardo_research_meta_value($post_id, '_research_role'));
        $add($rows, $es?'Inicio':'Start', eduardo_research_meta_value($post_id, '_research_start_date'));
        $add($rows, $es?'Fin':'End', eduardo_research_meta_value($post_id, '_research_end_date'));
        $add($rows, $es?'Institución / socio':'Institution / partner', eduardo_research_meta_value($post_id, '_research_partner'));
    } elseif ('research_software' === $type) {
        $add($rows, $es?'Versión':'Version', eduardo_research_meta_value($post_id, '_research_software_version'));
        $add($rows, $es?'Fecha de versión':'Release date', eduardo_research_meta_value($post_id, '_research_release_date'));
        $add($rows, $es?'Licencia':'License', eduardo_research_meta_value($post_id, '_research_license'));
        $languages = eduardo_research_meta_list($post_id, '_research_programming_languages');
        $add($rows, $es?'Lenguajes':'Languages', implode(', ', $languages));
        $add($rows, 'DOI', '1' === eduardo_research_meta_value($post_id, '_research_doi_verified') ? eduardo_research_meta_value($post_id, '_research_doi') : '');
    } elseif ('research_dataset' === $type) {
        $add($rows, $es?'Versión':'Version', eduardo_research_meta_value($post_id, '_research_dataset_version'));
        $add($rows, $es?'Fecha de publicación':'Publication date', eduardo_research_meta_value($post_id, '_research_publication_date'));
        $add($rows, $es?'Acceso':'Access', eduardo_research_meta_value($post_id, '_research_access_level'));
        $add($rows, $es?'Licencia':'License', eduardo_research_meta_value($post_id, '_research_license'));
        $formats = eduardo_research_meta_list($post_id, '_research_formats');
        $add($rows, $es?'Formatos':'Formats', implode(', ', $formats));
        $add($rows, 'DOI', '1' === eduardo_research_meta_value($post_id, '_research_doi_verified') ? eduardo_research_meta_value($post_id, '_research_doi') : '');
    } elseif ('research_line' === $type) {
        $add($rows, $es?'Estado':'Status', ucwords(str_replace('_',' ',eduardo_research_meta_value($post_id, '_research_status'))));
        $topics = eduardo_research_meta_list($post_id, '_research_topics');
        $add($rows, $es?'Temas':'Topics', implode(', ', $topics));
        $methods = eduardo_research_meta_list($post_id, '_research_methods');
        $add($rows, $es?'Métodos':'Methods', implode(', ', $methods));
    }
    return $rows;
}

function eduardo_research_render_object_meta(int $post_id): void {
    $rows = eduardo_research_object_meta_rows($post_id);
    if (! $rows) { return; }
    echo '<dl class="research-object-meta">';
    foreach ($rows as [$label,$value]) {
        echo '<div><dt>' . esc_html($label) . '</dt><dd>' . esc_html($value) . '</dd></div>';
    }
    echo '</dl>';
}

function eduardo_research_verified_line_query_args(?string $language = null, int $limit = 12): array {
    $language = $language ?: eduardo_research_current_language();
    $args = eduardo_research_localized_query_args(array(
        'post_type'=>'research_line','post_status'=>'publish','posts_per_page'=>$limit,
        'meta_key'=>'_research_order','orderby'=>array('meta_value_num'=>'ASC','menu_order'=>'ASC','date'=>'DESC'),'no_found_rows'=>true,
    ), $language);
    $language_query = $args['meta_query'];
    $args['meta_query'] = array('relation'=>'AND', $language_query, array('key'=>'_research_evidence_status','value'=>'verified','compare'=>'='));
    return $args;
}

function eduardo_research_render_research_line_card(int $post_id): void {
    $question = eduardo_research_meta_value($post_id, '_research_central_question');
    ?>
    <article class="research-card research-card-feature research-line-card">
      <p class="research-card-kicker"><?php echo esc_html('es' === eduardo_research_current_language() ? 'Línea de investigación verificada' : 'Verified research line'); ?></p>
      <h3><a href="<?php echo esc_url((string) get_permalink($post_id)); ?>"><?php echo esc_html(get_the_title($post_id)); ?></a></h3>
      <?php eduardo_research_render_badges($post_id); ?>
      <?php if ($question) : ?><p class="research-line-question"><?php echo esc_html($question); ?></p><?php elseif (get_the_excerpt($post_id)) : ?><p><?php echo esc_html(get_the_excerpt($post_id)); ?></p><?php endif; ?>
      <span class="research-card-arrow" aria-hidden="true">↗</span>
    </article>
    <?php
}

function eduardo_research_is_scholar_output(int $post_id): bool {
    if ('1' !== eduardo_research_meta_value($post_id, '_research_output_type_verified')) { return false; }
    return in_array(sanitize_key(eduardo_research_meta_value($post_id, '_research_output_type')), array(
        'journal_article','conference_paper','book_chapter','book','preprint','working_paper','technical_report','thesis','poster'
    ), true);
}

function eduardo_research_citation_meta(): void {
    if (is_admin() || ! is_singular('research_output')) { return; }
    $post_id = get_queried_object_id();
    if ($post_id <= 0 || ! eduardo_research_is_scholar_output($post_id)) { return; }
    echo '<meta name="citation_title" content="' . esc_attr(get_the_title($post_id)) . '">' . "\n";
    foreach (eduardo_research_output_authors($post_id) as $author) {
        echo '<meta name="citation_author" content="' . esc_attr((string) $author['display_name']) . '">' . "\n";
    }
    $map = array(
        '_research_publication_date'=>'citation_publication_date','_research_venue'=>'citation_journal_title',
        '_research_volume'=>'citation_volume','_research_issue'=>'citation_issue','_research_doi'=>'citation_doi','_research_pdf_url'=>'citation_pdf_url',
    );
    foreach ($map as $meta_key => $tag) {
        $value = eduardo_research_meta_value($post_id, $meta_key);
        if ('_research_doi' === $meta_key && '1' !== eduardo_research_meta_value($post_id, '_research_doi_verified')) { continue; }
        if ('' === $value) { continue; }
        echo '<meta name="' . esc_attr($tag) . '" content="' . esc_attr($value) . '">' . "\n";
    }
    $pages = eduardo_research_meta_value($post_id, '_research_pages');
    if ($pages && preg_match('/^\s*([^\-–—]+)\s*[\-–—]\s*([^\-–—]+)\s*$/u', $pages, $match)) {
        echo '<meta name="citation_firstpage" content="' . esc_attr(trim($match[1])) . '">' . "\n";
        echo '<meta name="citation_lastpage" content="' . esc_attr(trim($match[2])) . '">' . "\n";
    }
}
add_action('wp_head', 'eduardo_research_citation_meta', 8);
