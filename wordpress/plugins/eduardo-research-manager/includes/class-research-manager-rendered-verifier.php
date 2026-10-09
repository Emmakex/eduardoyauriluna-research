<?php
/** HTTP-level rendered frontend verification for Theme-owned Research surfaces. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Rendered_Verifier {
    private Eduardo_Research_Manager_Contract $contract;

    public function __construct(?Eduardo_Research_Manager_Contract $contract = null) {
        $this->contract = $contract ?: new Eduardo_Research_Manager_Contract();
    }

    public function verify_page(string $key, string $language = 'en', array $expected_text = array()): array|WP_Error {
        if (! $this->contract->compatible()) {
            return new WP_Error('research_manager_theme_contract_unavailable', 'A compatible Research Theme contract is required for rendered verification.');
        }
        if (! isset($this->contract->pages()[$key])) {
            return new WP_Error('research_manager_unknown_page', 'The requested Page key is outside the active Research preset.');
        }
        if (! in_array($language, $this->contract->languages(), true)) {
            return new WP_Error('research_manager_unknown_language', 'The requested language is outside the active Research preset.');
        }
        $id = $this->contract->page_id($key);
        if ($id <= 0) { return new WP_Error('research_manager_page_missing', 'The Theme-controlled Page does not exist.'); }
        $url = function_exists('eduardo_research_page_url') ? (string) eduardo_research_page_url($key, $language) : (string) get_permalink($id);
        $result = $this->verify_url($url, $language, $expected_text, array(), true);
        if (is_wp_error($result)) { return $result; }
        $result['resource'] = 'page:' . $key;
        $result['page_id'] = $id;
        return $result;
    }

    public function verify_record(int $post_id, array $expected_text = array(), array $forbidden_text = array()): array|WP_Error {
        if (! $this->contract->compatible()) {
            return new WP_Error('research_manager_theme_contract_unavailable', 'A compatible Research Theme contract is required for rendered verification.');
        }
        $post = get_post($post_id);
        $supported = array('research_line','research_output','research_project','research_software','research_dataset','post');
        if (! $post instanceof WP_Post || ! in_array($post->post_type, $supported, true)) {
            return new WP_Error('research_manager_render_resource_missing', 'Rendered verification requires a supported Research resource.');
        }
        if ('publish' !== $post->post_status) {
            return new WP_Error('research_manager_render_resource_not_public', 'Rendered verification only checks publicly accessible published resources.');
        }
        $language = function_exists('eduardo_research_post_language') ? eduardo_research_post_language($post_id) : 'en';
        $url = (string) get_permalink($post_id);
        $expected = array_merge(array((string) $post->post_title), $expected_text);
        $forbidden = array_merge($forbidden_text, $this->sensitive_forbidden_values($post_id, $post->post_type));
        $result = $this->verify_url($url, $language, $expected, $forbidden, false);
        if (is_wp_error($result)) { return $result; }
        $schema_type = $this->expected_schema_type($post_id, $post->post_type);
        $schema_ok = '' === $schema_type || $this->body_has_schema_type((string) $result['body'], $schema_type);
        $checks = (array) $result['checks'];
        $checks['schema_type'] = $schema_ok;
        $result['checks'] = $checks;
        $result['verified'] = ! in_array(false, $checks, true);
        $result['resource'] = $post->post_type . ':' . $post_id;
        $result['post_id'] = $post_id;
        $result['expected_schema_type'] = $schema_type;
        unset($result['body']);
        return $result;
    }

    public function verify_url(
        string $url,
        string $language,
        array $expected_text = array(),
        array $forbidden_text = array(),
        bool $require_bilingual_alternates = false
    ): array|WP_Error {
        if (! in_array($language, array('en','es'), true)) {
            return new WP_Error('research_manager_unknown_language', 'Rendered verification supports the active EN/ES Research runtime.');
        }
        $url = esc_url_raw($url, array('http','https'));
        if ('' === $url) { return new WP_Error('research_manager_invalid_render_url', 'Rendered verification requires a valid http/https URL.'); }

        $response = wp_remote_get($url, array(
            'timeout'=>12,
            'redirection'=>3,
            'user-agent'=>'ResearchManager/' . (defined('EDUARDO_RESEARCH_MANAGER_VERSION') ? EDUARDO_RESEARCH_MANAGER_VERSION : 'dev'),
            'headers'=>array('Accept'=>'text/html,application/xhtml+xml'),
        ));
        if (is_wp_error($response)) {
            return new WP_Error('research_manager_render_request_failed', $response->get_error_message(), array('url'=>$url));
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        if ('' === $body) { return new WP_Error('research_manager_render_empty', 'Rendered verification received an empty HTML response.', array('url'=>$url,'status'=>$status)); }

        $canonical = $this->extract_link_href($body, 'canonical');
        $alternates = $this->extract_hreflangs($body);
        $checks = array(
            'http_200'=>200 === $status,
            'canonical'=>$this->urls_equal($canonical, $url),
            'html_language'=>$this->body_has_language($body, $language),
            'current_hreflang'=>isset($alternates[$language]) && $this->urls_equal((string) $alternates[$language], $url),
            'og_url'=>$this->body_has_meta_url($body, 'og:url', $url),
            'json_ld'=>false !== stripos($body, 'application/ld+json'),
        );
        if ($require_bilingual_alternates) {
            $checks['hreflang_en']=isset($alternates['en']) && '' !== (string) $alternates['en'];
            $checks['hreflang_es']=isset($alternates['es']) && '' !== (string) $alternates['es'];
            $checks['hreflang_x_default']=isset($alternates['x-default']) && isset($alternates['en']) && $this->urls_equal((string) $alternates['x-default'], (string) $alternates['en']);
        }

        $text_checks = array();
        foreach ($expected_text as $index => $needle) {
            if (! is_scalar($needle) || '' === trim((string) $needle)) { continue; }
            $text_checks['expected_' . $index] = false !== stripos($body, (string) $needle);
        }
        foreach ($forbidden_text as $index => $needle) {
            if (! is_scalar($needle) || '' === trim((string) $needle)) { continue; }
            $text_checks['forbidden_' . $index] = false === stripos($body, (string) $needle);
        }
        $checks = array_merge($checks, $text_checks);

        return array(
            'verified'=>! in_array(false, $checks, true),
            'url'=>$url,
            'status'=>$status,
            'canonical'=>$canonical,
            'hreflang'=>$alternates,
            'checks'=>$checks,
            'body'=>$body,
            'verified_at'=>gmdate(DATE_W3C),
        );
    }

    private function sensitive_forbidden_values(int $post_id, string $post_type): array {
        $forbidden = array();
        if (in_array($post_type, array('research_output','research_software','research_dataset'), true)) {
            $doi = $this->private_meta_string($post_id, '_research_doi');
            $verified = '1' === $this->private_meta_string($post_id, '_research_doi_verified', '0');
            if ('' !== $doi && ! $verified) { $forbidden[] = $doi; }
        }
        if ('research_output' === $post_type) {
            $review = $this->private_meta_string($post_id, '_research_review_status');
            if ('' !== $review && '1' !== $this->private_meta_string($post_id, '_research_review_status_verified', '0')) { $forbidden[] = $review; }
            $type = $this->private_meta_string($post_id, '_research_output_type');
            if ('' !== $type && '1' !== $this->private_meta_string($post_id, '_research_output_type_verified', '0')) { $forbidden[] = $type; }
        }
        return array_values(array_unique($forbidden));
    }

    private function expected_schema_type(int $post_id, string $post_type): string {
        if ('research_dataset' === $post_type) { return 'Dataset'; }
        if ('research_software' === $post_type) { return 'SoftwareSourceCode'; }
        if (in_array($post_type, array('research_line','research_project'), true)) { return 'CreativeWork'; }
        if ('research_output' === $post_type && function_exists('eduardo_research_output_schema_type')) { return eduardo_research_output_schema_type($post_id); }
        if ('post' === $post_type) { return 'Article'; }
        return '';
    }

    private function body_has_schema_type(string $body, string $type): bool {
        return 1 === preg_match('/"@type"\s*:\s*"' . preg_quote($type, '/') . '"/i', $body);
    }

    private function extract_link_href(string $body, string $rel): string {
        if (1 === preg_match('/<link\b[^>]*\brel=["\']' . preg_quote($rel, '/') . '["\'][^>]*\bhref=["\']([^"\']+)["\'][^>]*>/i', $body, $match)) {
            return html_entity_decode((string) $match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        if (1 === preg_match('/<link\b[^>]*\bhref=["\']([^"\']+)["\'][^>]*\brel=["\']' . preg_quote($rel, '/') . '["\'][^>]*>/i', $body, $match)) {
            return html_entity_decode((string) $match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return '';
    }

    private function extract_hreflangs(string $body): array {
        $result = array();
        if (preg_match_all('/<link\b[^>]*\brel=["\']alternate["\'][^>]*>/i', $body, $links)) {
            foreach ($links[0] as $tag) {
                if (1 !== preg_match('/\bhreflang=["\']([^"\']+)["\']/i', $tag, $lang)) { continue; }
                if (1 !== preg_match('/\bhref=["\']([^"\']+)["\']/i', $tag, $href)) { continue; }
                $result[strtolower((string) $lang[1])] = html_entity_decode((string) $href[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }
        return $result;
    }

    private function body_has_language(string $body, string $language): bool {
        return 1 === preg_match('/<html\b[^>]*\blang=["\']' . preg_quote($language, '/') . '(?:[-_][^"\']+)?["\']/i', $body);
    }

    private function body_has_meta_url(string $body, string $property, string $expected): bool {
        $pattern1 = '/<meta\b[^>]*\bproperty=["\']' . preg_quote($property, '/') . '["\'][^>]*\bcontent=["\']([^"\']+)["\'][^>]*>/i';
        $pattern2 = '/<meta\b[^>]*\bcontent=["\']([^"\']+)["\'][^>]*\bproperty=["\']' . preg_quote($property, '/') . '["\'][^>]*>/i';
        if (1 === preg_match($pattern1, $body, $match) || 1 === preg_match($pattern2, $body, $match)) {
            return $this->urls_equal(html_entity_decode((string) $match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), $expected);
        }
        return false;
    }

    private function urls_equal(string $left, string $right): bool {
        return untrailingslashit($left) === untrailingslashit($right);
    }

    private function private_meta_string(int $post_id, string $key, string $default = ''): string {
        global $wpdb;
        $raw = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id ASC LIMIT 1",
            $post_id,
            $key
        ));
        if (null === $raw) { return $default; }
        $value = maybe_unserialize($raw);
        return is_scalar($value) ? (string) $value : $default;
    }
}
