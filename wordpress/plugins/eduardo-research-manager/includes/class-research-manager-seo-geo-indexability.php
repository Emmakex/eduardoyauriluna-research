<?php
/** Indexability, robots, sitemap and internal-link diagnostics for managed Research surfaces. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_SEO_GEO_Indexability {
    private Eduardo_Research_Manager_SEO_GEO $seo_geo;

    public function __construct(?Eduardo_Research_Manager_SEO_GEO $seo_geo = null) {
        $this->seo_geo = $seo_geo ?: new Eduardo_Research_Manager_SEO_GEO();
    }

    public function site(): array {
        $blog_public = '1' === (string) get_option('blog_public', '1');
        $sitemaps = function_exists('wp_sitemaps_get_server') ? wp_sitemaps_get_server() : null;
        $sitemaps_enabled = is_object($sitemaps) && method_exists($sitemaps, 'sitemaps_enabled')
            ? (bool) $sitemaps->sitemaps_enabled()
            : false;
        $exploration_filter = false !== has_filter('wp_robots', 'eduardo_research_exploration_robots');

        return array(
            'search_engine_visibility'=>$blog_public,
            'diagnostic_check'=>'search-indexability',
            'core_sitemaps_enabled'=>$sitemaps_enabled,
            'localized_page_sitemap_provider'=>class_exists('Eduardo_Research_Language_Sitemap_Provider'),
            'exploration_policy'=>array(
                'registered'=>$exploration_filter,
                'filtered_collection_states'=>'noindex,follow',
                'canonical_collection_surfaces'=>'indexable',
                'arbitrary_per_url_override'=>false,
            ),
            'expected_public_policy'=>'index,follow unless the Theme classifies the request as an exploration/filter state',
            'ready'=>$blog_public && $sitemaps_enabled && $exploration_filter,
            'generated_at'=>gmdate(DATE_W3C),
        );
    }

    public function resource(string $type, string|int $identifier, string $language = ''): array|WP_Error {
        $inspection = $this->seo_geo->inspect_resource($type, $identifier, $language);
        if (is_wp_error($inspection)) { return $inspection; }

        $status = 'page' === sanitize_key($type)
            ? (string) (($inspection['stored']['status'] ?? '') ?: 'publish')
            : (string) ($inspection['status'] ?? '');
        $public = 'publish' === $status;
        $url = $this->resource_url($type, $identifier, $language, $inspection);
        if (! $public || '' === $url) {
            return array(
                'type'=>sanitize_key($type),
                'target'=>$this->target($type, $identifier, $language),
                'public'=>$public,
                'expected_indexable'=>false,
                'rendered_checked'=>false,
                'indexable'=>false,
                'reason'=>'draft-or-non-public',
                'generated_at'=>gmdate(DATE_W3C),
            );
        }

        $response = wp_remote_get($url, array(
            'timeout'=>12,
            'redirection'=>3,
            'user-agent'=>'ResearchManager/' . (defined('EDUARDO_RESEARCH_MANAGER_VERSION') ? EDUARDO_RESEARCH_MANAGER_VERSION : 'dev'),
            'headers'=>array('Accept'=>'text/html,application/xhtml+xml'),
        ));
        if (is_wp_error($response)) {
            return new WP_Error('research_manager_indexability_request_failed', $response->get_error_message(), array('url'=>$url));
        }
        $http_status = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        $meta_robots = $this->extract_meta_robots($body);
        $x_robots = strtolower(trim((string) wp_remote_retrieve_header($response, 'x-robots-tag')));
        $robots = strtolower(trim($meta_robots . ',' . $x_robots, ','));
        $noindex = false !== strpos($robots, 'noindex');
        $global_public = '1' === (string) get_option('blog_public', '1');
        $indexable = 200 === $http_status && $global_public && ! $noindex;

        return array(
            'type'=>sanitize_key($type),
            'target'=>$this->target($type, $identifier, $language),
            'url'=>$url,
            'public'=>true,
            'expected_indexable'=>true,
            'rendered_checked'=>true,
            'http_status'=>$http_status,
            'meta_robots'=>$meta_robots,
            'x_robots_tag'=>$x_robots,
            'noindex_detected'=>$noindex,
            'global_search_visibility'=>$global_public,
            'indexable'=>$indexable,
            'verified'=>$indexable,
            'verified_at'=>gmdate(DATE_W3C),
        );
    }

    public function internal_links(int $limit = 200): array {
        $limit = max(1, min(500, $limit));
        $rows = array();
        $groups = array('insight'=>0,'output'=>0,'project'=>0,'software'=>0,'dataset'=>0);
        $connected = 0;
        $unlinked = 0;

        $specs = array(
            'insight'=>array('post_type'=>'post','meta_key'=>'_research_insight_type'),
            'output'=>array('post_type'=>'research_output'),
            'project'=>array('post_type'=>'research_project'),
            'software'=>array('post_type'=>'research_software'),
            'dataset'=>array('post_type'=>'research_dataset'),
        );
        foreach ($specs as $kind => $spec) {
            $args = array(
                'post_type'=>$spec['post_type'],
                'post_status'=>'publish',
                'posts_per_page'=>$limit,
                'orderby'=>'ID',
                'order'=>'ASC',
                'suppress_filters'=>true,
            );
            if (isset($spec['meta_key'])) {
                $args['meta_query'] = array(array('key'=>$spec['meta_key'],'compare'=>'EXISTS'));
            }
            $posts = get_posts($args);
            foreach ($posts as $post) {
                if (! $post instanceof WP_Post) { continue; }
                $post_id = (int) $post->ID;
                $line_ids = function_exists('eduardo_research_post_line_ids') ? eduardo_research_post_line_ids($post_id) : array();
                $has_graph_link = ! empty($line_ids);
                $groups[$kind]++;
                if ($has_graph_link) { $connected++; } else { $unlinked++; }
                $rows[] = array(
                    'kind'=>$kind,
                    'post_id'=>$post_id,
                    'language'=>function_exists('eduardo_research_post_language') ? (string) eduardo_research_post_language($post_id) : (string) get_post_meta($post_id, '_research_language', true),
                    'title'=>(string) $post->post_title,
                    'url'=>(string) get_permalink($post_id),
                    'verified_line_ids'=>array_values(array_map('absint', $line_ids)),
                    'research_graph_connected'=>$has_graph_link,
                    'status'=>$has_graph_link ? 'pass' : 'advisory',
                    'action'=>$has_graph_link ? array() : $this->relation_action($kind, $post_id),
                );
            }
        }

        $lines = array();
        $line_posts = get_posts(array(
            'post_type'=>'research_line',
            'post_status'=>'publish',
            'posts_per_page'=>$limit,
            'meta_key'=>'_research_evidence_status',
            'meta_value'=>'verified',
            'orderby'=>'ID',
            'order'=>'ASC',
            'suppress_filters'=>true,
        ));
        foreach ($line_posts as $line) {
            if (! $line instanceof WP_Post) { continue; }
            $count = 0;
            if (function_exists('eduardo_research_related_objects_query_for_line')) {
                $query = eduardo_research_related_objects_query_for_line((int) $line->ID, 200);
                $count = (int) $query->post_count;
                wp_reset_postdata();
            }
            $lines[] = array(
                'post_id'=>(int) $line->ID,
                'title'=>(string) $line->post_title,
                'language'=>function_exists('eduardo_research_post_language') ? (string) eduardo_research_post_language((int) $line->ID) : (string) get_post_meta((int) $line->ID, '_research_language', true),
                'related_object_count'=>$count,
                'status'=>$count > 0 ? 'pass' : 'advisory',
            );
        }

        return array(
            'summary'=>array(
                'managed_public_records'=>count($rows),
                'research_graph_connected'=>$connected,
                'research_graph_unlinked'=>$unlinked,
                'verified_public_lines'=>count($lines),
                'groups'=>$groups,
            ),
            'records'=>$rows,
            'lines'=>$lines,
            'remediation_model'=>'delegate-to-existing-manager-gateways',
            'direct_html_link_mutation'=>false,
            'generated_at'=>gmdate(DATE_W3C),
        );
    }

    private function relation_action(string $kind, int $post_id): array {
        if ('insight' === $kind) {
            return array(
                'gateway'=>'insights',
                'operation'=>'insight-update',
                'target'=>array('post_id'=>$post_id,'field'=>'line_ids'),
                'required_input'=>array('value'),
                'requires_evidence'=>false,
                'auto_apply'=>false,
            );
        }
        return array(
            'gateway'=>'research-objects',
            'operation'=>'object-update',
            'target'=>array('kind'=>$kind,'post_id'=>$post_id,'field'=>'line_ids'),
            'required_input'=>array('value','evidence_reference'),
            'requires_evidence'=>true,
            'auto_apply'=>false,
        );
    }

    private function resource_url(string $type, string|int $identifier, string $language, array $inspection): string {
        if ('page' === sanitize_key($type)) {
            $key = sanitize_key((string) $identifier);
            $language = sanitize_key($language ?: (string) ($inspection['language'] ?? 'en'));
            return function_exists('eduardo_research_page_url') ? (string) eduardo_research_page_url($key, $language) : '';
        }
        $post_id = absint($inspection['post_id'] ?? $identifier);
        return $post_id > 0 ? (string) get_permalink($post_id) : '';
    }

    private function target(string $type, string|int $identifier, string $language): array {
        if ('page' === sanitize_key($type)) {
            return array('key'=>sanitize_key((string) $identifier),'language'=>sanitize_key($language ?: 'en'));
        }
        return array('post_id'=>absint($identifier));
    }

    private function extract_meta_robots(string $body): string {
        $patterns = array(
            '/<meta\b[^>]*\bname=["\']robots["\'][^>]*\bcontent=["\']([^"\']*)["\'][^>]*>/i',
            '/<meta\b[^>]*\bcontent=["\']([^"\']*)["\'][^>]*\bname=["\']robots["\'][^>]*>/i',
        );
        foreach ($patterns as $pattern) {
            if (1 === preg_match($pattern, $body, $match)) {
                return strtolower(trim(html_entity_decode((string) $match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            }
        }
        return '';
    }
}
