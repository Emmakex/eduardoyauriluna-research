<?php
/** Sitemap provider for native localized Theme-owned page aliases. */
declare(strict_types=1);
if (! defined('ABSPATH')) { exit; }

if (class_exists('WP_Sitemaps_Provider')) {
    final class Eduardo_Research_Language_Sitemap_Provider extends WP_Sitemaps_Provider {
        public function __construct() {
            $this->name = 'research-languages';
            $this->object_type = 'research_language_page';
        }

        public function get_url_list($page_num, $object_subtype = ''): array {
            if (1 !== (int) $page_num) { return array(); }
            $urls = array();
            foreach ((array) (eduardo_research_preset()['pages'] ?? array()) as $key => $contract) {
                $wp_slug = (string) ($contract['wp_slug'] ?? $contract['slug'] ?? $key);
                $page = get_page_by_path($wp_slug, OBJECT, 'page');
                if (! $page instanceof WP_Post || 'publish' !== $page->post_status) { continue; }
                $entry = array('loc'=>eduardo_research_page_url((string) $key, 'es'));
                $modified = get_post_modified_time(DATE_W3C, true, $page);
                if (is_string($modified) && '' !== $modified) { $entry['lastmod'] = $modified; }
                $urls[] = $entry;
            }
            return $urls;
        }

        public function get_max_num_pages($object_subtype = ''): int { return 1; }
    }
}

function eduardo_research_register_language_sitemap($sitemaps): void {
    if (! class_exists('Eduardo_Research_Language_Sitemap_Provider')) { return; }
    $sitemaps->registry->add_provider('research-languages', new Eduardo_Research_Language_Sitemap_Provider());
}
add_action('wp_sitemaps_init', 'eduardo_research_register_language_sitemap');
