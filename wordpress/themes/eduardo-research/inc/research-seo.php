<?php
/** Native SEO/GEO discovery runtime for the Research preset. */
declare(strict_types=1);
if (! defined('ABSPATH')) { exit; }

function eduardo_research_current_url(): string {
    if (is_404()) { return ''; }
    $key = eduardo_research_current_page_key();
    if ($key) { return eduardo_research_page_url($key); }
    if (is_singular()) { return (string) get_permalink(); }
    if (is_post_type_archive()) {
        $type = get_query_var('post_type');
        if (is_array($type)) { $type = reset($type); }
        $url = get_post_type_archive_link((string) $type);
        return $url ? (string) $url : eduardo_research_page_url('home');
    }
    if (is_search()) { return (string) get_search_link(get_search_query()); }
    if (is_archive()) { return (string) get_pagenum_link(max(1, (int) get_query_var('paged'))); }
    return eduardo_research_page_url('home');
}

function eduardo_research_description(): string {
    if (is_singular() && has_excerpt()) { return wp_strip_all_tags(get_the_excerpt()); }
    if (eduardo_research_current_page_key()) {
        $key = eduardo_research_current_page_key();
        if ('home' === $key) { return wp_strip_all_tags(eduardo_research_slot('hero-lead')); }
        $model = eduardo_research_surface_model((string) $key);
        return wp_strip_all_tags((string) ($model['lead'] ?? ''));
    }
    if (is_archive()) { return wp_strip_all_tags((string) get_the_archive_description()); }
    return wp_strip_all_tags((string) get_bloginfo('description'));
}

function eduardo_research_identity(): array {
    $stored = get_option('eduardo_research_identity', array());
    if (! is_array($stored)) { $stored = array(); }
    $name = isset($stored['name']) && is_scalar($stored['name']) && '' !== trim((string) $stored['name'])
        ? trim((string) $stored['name']) : (string) get_bloginfo('name');
    return array('name'=>$name,'url'=>home_url('/'));
}

function eduardo_research_output_schema_type(int $post_id): string {
    $verified = '1' === (string) get_post_meta($post_id, '_research_output_type_verified', true);
    if (! $verified) { return 'CreativeWork'; }
    $map = array(
        'journal_article'=>'ScholarlyArticle','conference_paper'=>'ScholarlyArticle','preprint'=>'ScholarlyArticle','working_paper'=>'ScholarlyArticle',
        'book'=>'Book','book_chapter'=>'Chapter','technical_report'=>'Report','article'=>'Article','scholarly_article'=>'ScholarlyArticle','report'=>'Report',
    );
    $type = sanitize_key((string) get_post_meta($post_id, '_research_output_type', true));
    return $map[$type] ?? 'CreativeWork';
}

function eduardo_research_page_schema_type(?string $key = null): string {
    $key = $key ?: eduardo_research_current_page_key();
    if ('about' === $key) { return 'ProfilePage'; }
    if (in_array($key, array('research','publications','projects','software','datasets','insights'), true)) { return 'CollectionPage'; }
    return 'WebPage';
}

function eduardo_research_alternate_urls(): array {
    $key = eduardo_research_current_page_key();
    if ($key) {
        return array('en'=>eduardo_research_page_url($key, 'en'), 'es'=>eduardo_research_page_url($key, 'es'));
    }

    if (is_singular(array('research_line','research_output','research_project','research_software','research_dataset','post'))) {
        $post_id = get_queried_object_id();
        if ($post_id <= 0 || ! function_exists('eduardo_research_post_language')) { return array(); }
        $record_language = eduardo_research_post_language($post_id);
        $urls = array($record_language=>(string) get_permalink($post_id));
        $other = 'es' === $record_language ? 'en' : 'es';
        $alternate = eduardo_research_record_translation_url($post_id, $other);
        if ('' !== $alternate) { $urls[$other] = $alternate; }
        return $urls;
    }

    return array();
}

function eduardo_research_head_metadata(): void {
    if (is_admin()) { return; }
    $url = eduardo_research_current_url();
    $description = eduardo_research_description();
    $title = wp_get_document_title();
    $language = eduardo_research_current_language();
    $locale = eduardo_research_current_locale();
    $alternates = eduardo_research_alternate_urls();

    if ('' !== $url) { echo '<link rel="canonical" href="' . esc_url($url) . '">' . "\n"; }
    foreach ($alternates as $code => $alternate_url) {
        echo '<link rel="alternate" hreflang="' . esc_attr((string) $code) . '" href="' . esc_url((string) $alternate_url) . '">' . "\n";
    }
    if (isset($alternates['en'])) {
        echo '<link rel="alternate" hreflang="x-default" href="' . esc_url((string) $alternates['en']) . '">' . "\n";
    }
    if ('' !== $description) { echo '<meta name="description" content="' . esc_attr($description) . '">' . "\n"; }
    $article = is_singular(array('research_output','post'));
    echo '<meta property="og:type" content="' . esc_attr($article ? 'article' : 'website') . '">' . "\n";
    echo '<meta property="og:title" content="' . esc_attr($title) . '">' . "\n";
    echo '<meta property="og:site_name" content="' . esc_attr((string) get_bloginfo('name')) . '">' . "\n";
    echo '<meta property="og:locale" content="' . esc_attr($locale) . '">' . "\n";
    $other = 'es' === $language ? 'en' : 'es';
    if (isset($alternates[$other])) {
        echo '<meta property="og:locale:alternate" content="' . esc_attr('es' === $language ? 'en_US' : 'es_ES') . '">' . "\n";
    }
    if ('' !== $url) { echo '<meta property="og:url" content="' . esc_url($url) . '">' . "\n"; }
    if ('' !== $description) { echo '<meta property="og:description" content="' . esc_attr($description) . '">' . "\n"; }
    echo '<meta name="twitter:card" content="summary_large_image">' . "\n";
    echo '<meta name="twitter:title" content="' . esc_attr($title) . '">' . "\n";
    if ('' !== $description) { echo '<meta name="twitter:description" content="' . esc_attr($description) . '">' . "\n"; }
}
add_action('wp_head', 'eduardo_research_head_metadata', 5);

function eduardo_research_schema_graph(): void {
    if (is_admin() || is_404()) { return; }
    $identity = eduardo_research_identity();
    $url = eduardo_research_current_url();
    $language = eduardo_research_current_language();
    $key = eduardo_research_current_page_key();
    $person = array('@type'=>'Person','@id'=>home_url('/#researcher'),'name'=>$identity['name'],'url'=>$identity['url']);
    $same_as = eduardo_research_verified_identifier_urls();
    if ($same_as) { $person['sameAs'] = $same_as; }

    $affiliations = array();
    foreach (eduardo_research_verified_localized_evidence('affiliations') as $record) {
        $name = trim((string) ($record['title'] ?? $record['label'] ?? $record['value'] ?? ''));
        if ('' === $name) { continue; }
        $organization = array('@type'=>'Organization','name'=>$name);
        if (! empty($record['url']) && is_scalar($record['url'])) { $organization['url'] = esc_url_raw((string) $record['url']); }
        $affiliations[] = $organization;
    }
    if ($affiliations) { $person['affiliation'] = $affiliations; }

    $topics = array();
    $line_query = new WP_Query(eduardo_research_verified_line_query_args($language, 50));
    foreach ($line_query->posts as $line_post) {
        if ($line_post instanceof WP_Post && '' !== trim($line_post->post_title)) { $topics[] = $line_post->post_title; }
    }
    wp_reset_postdata();
    foreach (eduardo_research_verified_localized_evidence('research_lines') as $record) {
        $topic = trim((string) ($record['title'] ?? $record['label'] ?? $record['value'] ?? ''));
        if ('' !== $topic) { $topics[] = $topic; }
    }
    if ($topics) { $person['knowsAbout'] = array_values(array_unique($topics)); }

    $website = array('@type'=>'WebSite','@id'=>home_url('/#website'),'url'=>home_url('/'),'name'=>get_bloginfo('name'),'inLanguage'=>array('en','es'),'publisher'=>array('@id'=>home_url('/#researcher')));
    $page = array('@type'=>eduardo_research_page_schema_type($key),'@id'=>$url . '#webpage','url'=>$url,'name'=>wp_get_document_title(),'inLanguage'=>$language,'isPartOf'=>array('@id'=>home_url('/#website')),'about'=>array('@id'=>home_url('/#researcher')));
    if ('about' === $key) { $page['mainEntity'] = array('@id'=>home_url('/#researcher')); }
    $graph = array($person,$website,$page);

    if (is_singular('research_line')) {
        $post_id = get_queried_object_id();
        $line = array('@type'=>'CreativeWork','@id'=>get_permalink() . '#research-line','name'=>get_the_title(),'url'=>get_permalink(),'inLanguage'=>$language,'mainEntityOfPage'=>array('@id'=>get_permalink() . '#webpage'));
        if (has_excerpt($post_id)) { $line['description'] = wp_strip_all_tags(get_the_excerpt($post_id)); }
        $question = eduardo_research_meta_value($post_id, '_research_central_question');
        if ($question) { $line['abstract'] = $question; }
        $keywords = eduardo_research_meta_list($post_id, '_research_topics');
        if ($keywords) { $line['keywords'] = $keywords; }
        $graph[] = $line;
    } elseif (is_singular('research_output')) {
        $post_id = get_queried_object_id();
        $output = array('@type'=>eduardo_research_output_schema_type($post_id),'@id'=>get_permalink() . '#research-output','name'=>get_the_title(),'url'=>get_permalink(),'inLanguage'=>$language,'mainEntityOfPage'=>array('@id'=>get_permalink() . '#webpage'),'dateModified'=>get_the_modified_date(DATE_W3C));
        if (has_excerpt($post_id)) { $output['abstract'] = wp_strip_all_tags(get_the_excerpt($post_id)); }
        $publication_date = eduardo_research_meta_value($post_id, '_research_publication_date');
        if ($publication_date) { $output['datePublished'] = $publication_date; }
        $authors = array();
        foreach (eduardo_research_output_authors($post_id) as $author) {
            $author_node = array('@type'=>'Person','name'=>(string) $author['display_name']);
            if (! empty($author['orcid'])) { $author_node['sameAs'] = 'https://orcid.org/' . ltrim((string) $author['orcid'], '/'); }
            if (! empty($author['affiliation'])) { $author_node['affiliation'] = array('@type'=>'Organization','name'=>(string) $author['affiliation']); }
            $authors[] = $author_node;
        }
        if ($authors) { $output['author'] = $authors; }
        $doi = eduardo_research_meta_value($post_id, '_research_doi');
        if ('' !== $doi && '1' === eduardo_research_meta_value($post_id, '_research_doi_verified')) { $output['identifier'] = array('@type'=>'PropertyValue','propertyID'=>'DOI','value'=>$doi); }
        $venue = eduardo_research_meta_value($post_id, '_research_venue');
        if ($venue) { $output['isPartOf'] = array('@type'=>'CreativeWork','name'=>$venue); }
        $license = eduardo_research_meta_value($post_id, '_research_license');
        if ($license) { $output['license'] = $license; }
        $review = eduardo_research_meta_value($post_id, '_research_review_status');
        if ($review) { $output['additionalProperty'] = array('@type'=>'PropertyValue','name'=>'reviewStatus','value'=>$review); }
        $graph[] = $output;
    } elseif (is_singular('research_dataset')) {
        $post_id = get_queried_object_id();
        $dataset = array('@type'=>'Dataset','@id'=>get_permalink() . '#dataset','name'=>get_the_title(),'url'=>get_permalink(),'inLanguage'=>$language);
        if (has_excerpt($post_id)) { $dataset['description'] = wp_strip_all_tags(get_the_excerpt($post_id)); }
        $doi = eduardo_research_meta_value($post_id, '_research_doi');
        if ($doi && '1' === eduardo_research_meta_value($post_id, '_research_doi_verified')) { $dataset['identifier'] = array('@type'=>'PropertyValue','propertyID'=>'DOI','value'=>$doi); }
        $license = eduardo_research_meta_value($post_id, '_research_license');
        if ($license) { $dataset['license'] = $license; }
        $graph[] = $dataset;
    } elseif (is_singular('research_software')) {
        $post_id = get_queried_object_id();
        $software = array('@type'=>'SoftwareSourceCode','@id'=>get_permalink() . '#software','name'=>get_the_title(),'url'=>get_permalink(),'inLanguage'=>$language);
        if (has_excerpt($post_id)) { $software['description'] = wp_strip_all_tags(get_the_excerpt($post_id)); }
        $repo = eduardo_research_meta_value($post_id, '_research_repository_url');
        if ($repo) { $software['codeRepository'] = $repo; }
        $version = eduardo_research_meta_value($post_id, '_research_software_version');
        if ($version) { $software['version'] = $version; }
        $languages = eduardo_research_meta_list($post_id, '_research_programming_languages');
        if ($languages) { $software['programmingLanguage'] = $languages; }
        $license = eduardo_research_meta_value($post_id, '_research_license');
        if ($license) { $software['license'] = $license; }
        $graph[] = $software;
    }

    echo '<script type="application/ld+json">' . wp_json_encode(array('@context'=>'https://schema.org','@graph'=>$graph), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>' . "\n";
}
add_action('wp_head', 'eduardo_research_schema_graph', 30);
