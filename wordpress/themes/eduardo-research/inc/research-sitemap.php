<?php
/** Sitemap provider for native localized Theme-owned page aliases. */
declare(strict_types=1);
if (! defined('ABSPATH')) { exit; }

if (class_exists('WP_Sitemaps_Provider')) {
    final class Eduardo_Research_Language_Sitemap_Provider extends WP_Sitemaps_Provider {
        public const PROVIDER_NAME = 'researchlanguages';

        public function __construct() {
            $this->name = self::PROVIDER_NAME;
            $this->object_type = 'research_language_page';
        }

        public function get_url_list($page_num, $object_subtype = ''): array {
            if (1 !== (int) $page_num) { return array(); }
            $urls = array();
            foreach (eduardo_research_page_contracts() as $key => $contract) {
                $wp_slug = (string) ($contract['wp_slug'] ?? $contract['slug'] ?? $key);
                $page = get_page_by_path($wp_slug, OBJECT, 'page');
                if (! $page instanceof WP_Post || 'publish' !== $page->post_status) { continue; }
                if (! eduardo_research_page_is_publicly_available((string) $key, 'es')) { continue; }
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

function eduardo_research_filter_sitemap_provider($provider, string $name) {
    if ('users' === $name) { return false; }
    return $provider;
}
add_filter('wp_sitemaps_add_provider', 'eduardo_research_filter_sitemap_provider', 10, 2);

function eduardo_research_register_language_sitemap($sitemaps): void {
    if (! class_exists('Eduardo_Research_Language_Sitemap_Provider')) { return; }
    $sitemaps->registry->add_provider(
        Eduardo_Research_Language_Sitemap_Provider::PROVIDER_NAME,
        new Eduardo_Research_Language_Sitemap_Provider()
    );
}
add_action('wp_sitemaps_init', 'eduardo_research_register_language_sitemap');

function eduardo_research_sitemap_post_query_args(array $args, string $post_type): array {
    if ('page' === $post_type) {
        $excluded = array();
        $language = function_exists('eduardo_research_default_language')
            ? eduardo_research_default_language()
            : sanitize_key((string) (eduardo_research_preset()['default_language'] ?? ''));
        foreach (eduardo_research_page_contracts() as $key => $contract) {
            if (eduardo_research_page_is_publicly_available((string) $key, $language)) { continue; }
            $wp_slug = (string) ($contract['wp_slug'] ?? $contract['slug'] ?? $key);
            $page = get_page_by_path($wp_slug, OBJECT, 'page');
            if ($page instanceof WP_Post) { $excluded[] = (int) $page->ID; }
        }
        if ($excluded) {
            $existing = isset($args['post__not_in']) && is_array($args['post__not_in']) ? array_map('absint', $args['post__not_in']) : array();
            $args['post__not_in'] = array_values(array_unique(array_merge($existing, $excluded)));
        }
    }

    if ('research_line' === $post_type) {
        $args['meta_query'] = array(array('key'=>'_research_evidence_status','value'=>'verified','compare'=>'='));
    }
    return $args;
}
add_filter('wp_sitemaps_posts_query_args', 'eduardo_research_sitemap_post_query_args', 10, 2);
