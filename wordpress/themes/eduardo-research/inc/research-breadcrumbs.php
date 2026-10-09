<?php
/** Semantic breadcrumbs for Theme-owned research surfaces. */
declare(strict_types=1);
if (! defined('ABSPATH')) { exit; }

function eduardo_research_breadcrumb_items(): array {
    $items = array(array('name'=>'Home','url'=>home_url('/')));
    if (is_singular()) {
        $type = get_post_type();
        if (in_array($type, array('research_output','research_project','research_software','research_dataset'), true)) {
            $object = get_post_type_object($type);
            $items[] = array('name'=>(string) $object->labels->name,'url'=>(string) get_post_type_archive_link($type));
        }
        if (! is_front_page()) { $items[] = array('name'=>get_the_title(),'url'=>(string) get_permalink()); }
    } elseif (is_archive()) {
        $items[] = array('name'=>wp_strip_all_tags(get_the_archive_title()),'url'=>eduardo_research_current_url());
    }
    return $items;
}

function eduardo_research_breadcrumb_schema(): void {
    if (is_front_page() || is_admin()) { return; }
    $elements = array();
    foreach (eduardo_research_breadcrumb_items() as $i => $item) {
        $elements[] = array('@type'=>'ListItem','position'=>$i + 1,'name'=>$item['name'],'item'=>$item['url']);
    }
    echo '<script type="application/ld+json">' . wp_json_encode(array('@context'=>'https://schema.org','@type'=>'BreadcrumbList','itemListElement'=>$elements), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>' . "\n";
}
add_action('wp_head', 'eduardo_research_breadcrumb_schema', 31);
