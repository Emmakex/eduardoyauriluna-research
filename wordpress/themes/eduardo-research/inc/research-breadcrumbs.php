<?php
/** Semantic breadcrumbs for Theme-owned research surfaces. */
declare(strict_types=1);
if (! defined('ABSPATH')) { exit; }

function eduardo_research_breadcrumb_items(): array {
    $items = array(array('name'=>eduardo_research_page_label('home'),'url'=>eduardo_research_page_url('home')));
    $page_key = eduardo_research_current_page_key();
    if ($page_key && 'home' !== $page_key) {
        $items[] = array('name'=>eduardo_research_page_label($page_key),'url'=>eduardo_research_page_url($page_key));
        return $items;
    }
    if (is_singular()) {
        $type = get_post_type();
        $index_map = array('research_line'=>'research','research_output'=>'publications','research_project'=>'projects','research_software'=>'software','research_dataset'=>'datasets','post'=>'insights');
        if (isset($index_map[$type])) {
            $key = $index_map[$type];
            $items[] = array('name'=>eduardo_research_page_label($key),'url'=>eduardo_research_page_url($key));
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
    foreach (eduardo_research_breadcrumb_items() as $item) {
        if ('' === (string) ($item['url'] ?? '')) { continue; }
        $elements[] = array('@type'=>'ListItem','position'=>count($elements)+1,'name'=>$item['name'],'item'=>$item['url']);
    }
    if (count($elements) < 2) { return; }
    echo '<script type="application/ld+json">' . wp_json_encode(array('@context'=>'https://schema.org','@type'=>'BreadcrumbList','itemListElement'=>$elements), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>' . "\n";
}
add_action('wp_head', 'eduardo_research_breadcrumb_schema', 31);
