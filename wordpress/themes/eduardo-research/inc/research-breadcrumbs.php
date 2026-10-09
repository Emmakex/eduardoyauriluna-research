<?php
/** Semantic breadcrumbs for Theme-owned research surfaces. */
declare(strict_types=1);
if (! defined('ABSPATH')) { exit; }

function eduardo_research_breadcrumb_items(): array {
    $items = array(array('name'=>'Home','url'=>eduardo_research_page_url('home')));
    if (is_singular()) {
        $type = get_post_type();
        $index_map = array(
            'research_output'=>'publications',
            'research_project'=>'projects',
            'research_software'=>'software',
            'research_dataset'=>'datasets',
            'post'=>'insights',
        );
        if (isset($index_map[$type])) {
            $preset = eduardo_research_preset();
            $key = $index_map[$type];
            $items[] = array(
                'name'=>(string) ($preset['pages'][$key]['label'] ?? ucfirst($key)),
                'url'=>eduardo_research_page_url($key),
            );
        }
        if (! is_front_page()) { $items[] = array('name'=>get_the_title(),'url'=>(string) get_permalink()); }
    } elseif (is_archive()) {
        $items[] = array('name'=>wp_strip_all_tags(get_the_archive_title()),'url'=>eduardo_research_current_url());
    }
    return $items;
}

function eduardo_research_breadcrumb_schema(): void {
    if (is_front_page() || is_admin() || is_404()) { return; }
    $elements = array();
    foreach (eduardo_research_breadcrumb_items() as $i => $item) {
        if ('' === (string) ($item['url'] ?? '')) { continue; }
        $elements[] = array('@type'=>'ListItem','position'=>count($elements) + 1,'name'=>$item['name'],'item'=>$item['url']);
    }
    if (count($elements) < 2) { return; }
    echo '<script type="application/ld+json">' . wp_json_encode(array('@context'=>'https://schema.org','@type'=>'BreadcrumbList','itemListElement'=>$elements), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>' . "\n";
}
add_action('wp_head', 'eduardo_research_breadcrumb_schema', 31);
