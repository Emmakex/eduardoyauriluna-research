<?php
/** Unified stored + rendered SEO/GEO inspection for bounded Research surfaces. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_SEO_GEO {
    private Eduardo_Research_Manager_Diagnostics $diagnostics;
    private Eduardo_Research_Manager_Remediation $remediation;
    private Eduardo_Research_Manager_Rendered_Verifier $rendered;

    public function __construct(
        ?Eduardo_Research_Manager_Diagnostics $diagnostics = null,
        ?Eduardo_Research_Manager_Remediation $remediation = null,
        ?Eduardo_Research_Manager_Rendered_Verifier $rendered = null
    ) {
        $this->diagnostics = $diagnostics ?: Eduardo_Research_Manager::diagnostics();
        $this->remediation = $remediation ?: Eduardo_Research_Manager::remediation();
        $this->rendered = $rendered ?: Eduardo_Research_Manager::rendered();
    }

    public function site(): array {
        $diagnostics = $this->diagnostics->run();
        $remediation = $this->remediation->inspect();
        $auto = array_values(array_filter((array) ($remediation['items'] ?? array()), static fn($item): bool => is_array($item) && ! empty($item['auto_remediable'])));
        $manual = array_values(array_filter((array) ($remediation['items'] ?? array()), static fn($item): bool => is_array($item) && empty($item['auto_remediable'])));

        return array(
            'ready'=>(bool) ($diagnostics['ready'] ?? false),
            'summary'=>is_array($diagnostics['summary'] ?? null) ? $diagnostics['summary'] : array(),
            'findings'=>is_array($diagnostics['checks'] ?? null) ? $diagnostics['checks'] : array(),
            'remediation'=>array(
                'auto_remediable'=>$auto,
                'manual_or_evidence'=>$manual,
                'auto_count'=>count($auto),
                'manual_count'=>count($manual),
            ),
            'resource_counts'=>$this->resource_counts(),
            'rendered_contract'=>array(
                'checks'=>array('http_200','canonical','html_language','current_hreflang','og_url','json_ld','schema_type'),
                'pages_require_bilingual_alternates'=>true,
                'published_records_only'=>true,
            ),
            'generated_at'=>gmdate(DATE_W3C),
        );
    }

    public function inspect_resource(string $type, string|int $identifier, string $language = ''): array|WP_Error {
        $type = sanitize_key($type);
        if ('page' === $type) {
            $key = sanitize_key((string) $identifier);
            $language = sanitize_key($language ?: 'en');
            if (! isset(Eduardo_Research_Manager::contract()->pages()[$key])) {
                return new WP_Error('validation_failed', 'The requested Page is outside the active Research preset.', array('status'=>400));
            }
            if (! in_array($language, Eduardo_Research_Manager::contract()->languages(), true)) {
                return new WP_Error('validation_failed', 'The requested Page language is outside the active Research preset.', array('status'=>400));
            }
            $stored = Eduardo_Research_Manager::page_editor()->inspect($key, $language);
            if (is_wp_error($stored)) { return $stored; }
            $rendered = $this->rendered->verify_page($key, $language);
            return array(
                'type'=>'page','key'=>$key,'language'=>$language,'stored'=>$stored,
                'rendered'=>$this->public_rendered($rendered),
                'ready'=>! is_wp_error($rendered) && ! empty($rendered['verified']),
                'verified_at'=>gmdate(DATE_W3C),
            );
        }

        $post_id = absint($identifier);
        if ($post_id <= 0) { return new WP_Error('validation_failed', 'A positive Research post ID is required.', array('status'=>400)); }
        $post = get_post($post_id);
        if (! $post instanceof WP_Post) { return new WP_Error('operation_not_found', 'Research resource was not found.', array('status'=>404)); }

        $kind_map = array(
            'insight'=>array('post_type'=>'post','kind'=>'insight'),
            'line'=>array('post_type'=>'research_line','kind'=>'line'),
            'output'=>array('post_type'=>'research_output','kind'=>'output'),
            'project'=>array('post_type'=>'research_project','kind'=>'project'),
            'software'=>array('post_type'=>'research_software','kind'=>'software'),
            'dataset'=>array('post_type'=>'research_dataset','kind'=>'dataset'),
        );
        if (! isset($kind_map[$type]) || $kind_map[$type]['post_type'] !== (string) $post->post_type) {
            return new WP_Error('validation_failed', 'The requested resource type does not match the managed Research resource.', array('status'=>400));
        }
        if ('insight' === $type && '' === (string) get_post_meta($post_id, '_research_insight_type', true)) {
            return new WP_Error('validation_failed', 'The WordPress post is not an explicitly managed Research Insight.', array('status'=>400));
        }

        $stored = $this->inspect_stored_record($type, $post_id);
        if (is_wp_error($stored)) { return $stored; }
        $language = function_exists('eduardo_research_post_language') ? (string) eduardo_research_post_language($post_id) : (string) get_post_meta($post_id, '_research_language', true);
        $rendered = null;
        $rendered_state = 'skipped-non-public';
        if ('publish' === (string) $post->post_status) {
            $verified = $this->rendered->verify_record($post_id);
            $rendered = $this->public_rendered($verified);
            $rendered_state = is_wp_error($verified) ? 'failed' : (! empty($verified['verified']) ? 'verified' : 'failed');
        }

        return array(
            'type'=>$type,'post_id'=>$post_id,'language'=>$language,'status'=>(string) $post->post_status,
            'stored'=>$stored,'rendered'=>$rendered,'rendered_state'=>$rendered_state,
            'ready'=>'publish' === (string) $post->post_status ? ('verified' === $rendered_state) : true,
            'verified_at'=>gmdate(DATE_W3C),
        );
    }

    private function inspect_stored_record(string $type, int $post_id): array|WP_Error {
        if ('insight' === $type) { return Eduardo_Research_Manager::insight_editor()->inspect($post_id); }
        if ('line' === $type) { return Eduardo_Research_Manager::line_editor()->inspect($post_id); }
        return Eduardo_Research_Manager::object_editor()->inspect($type, $post_id);
    }

    private function public_rendered(array|WP_Error $result): array {
        if (is_wp_error($result)) {
            return array('verified'=>false,'error_code'=>$result->get_error_code(),'error'=>$result->get_error_message());
        }
        unset($result['body']);
        return $result;
    }

    private function resource_counts(): array {
        $counts = array('pages'=>count(Eduardo_Research_Manager::contract()->pages()));
        $specs = array(
            'insights'=>array('post_type'=>'post','meta_key'=>'_research_insight_type'),
            'lines'=>array('post_type'=>'research_line'),
            'outputs'=>array('post_type'=>'research_output'),
            'projects'=>array('post_type'=>'research_project'),
            'software'=>array('post_type'=>'research_software'),
            'datasets'=>array('post_type'=>'research_dataset'),
        );
        foreach ($specs as $key => $spec) {
            $args = array(
                'post_type'=>$spec['post_type'],'post_status'=>array('publish','draft','future','private','pending'),
                'posts_per_page'=>1,'fields'=>'ids','no_found_rows'=>false,'suppress_filters'=>true,
            );
            if (isset($spec['meta_key'])) { $args['meta_key'] = $spec['meta_key']; $args['meta_compare'] = 'EXISTS'; }
            $query = new WP_Query($args);
            $counts[$key] = (int) $query->found_posts;
        }
        return $counts;
    }
}
