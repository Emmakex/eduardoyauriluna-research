<?php
/** Rendered-frontend verification adapter for the Research Theme. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Frontend_Verifier {
    private const MAX_RESPONSE_BYTES = 2097152;

    public function verify_surface(string $page_key, string $language = 'en'): array|WP_Error {
        if (! function_exists('eduardo_research_page_url') || ! function_exists('eduardo_research_page_schema_type')) {
            return new WP_Error('research_manager_frontend_contract_unavailable', 'A compatible Research Theme frontend contract is required.');
        }
        $page_key = sanitize_key($page_key);
        $language = sanitize_key($language);
        if (! in_array($language, array('en','es'), true)) {
            return new WP_Error('research_manager_frontend_language_invalid', 'Frontend verification only supports active Research languages.');
        }
        $url = (string) eduardo_research_page_url($page_key, $language);
        if ('' === $url) {
            return new WP_Error('research_manager_frontend_surface_missing', 'The Theme did not resolve a public URL for this surface.');
        }
        return $this->verify_url($url, array(
            'language'=>$language,
            'schema_type'=>(string) eduardo_research_page_schema_type($page_key),
            'theme_marker'=>true,
        ));
    }

    public function verify_resource(int $post_id): array|WP_Error {
        $post = get_post($post_id);
        if (! $post instanceof WP_Post) {
            return new WP_Error('research_manager_frontend_resource_missing', 'The requested WordPress resource does not exist.');
        }
        $allowed = array('research_line','research_output','research_project','research_software','research_dataset','post');
        if (! in_array((string) $post->post_type, $allowed, true)) {
            return new WP_Error('research_manager_frontend_resource_not_supported', 'This WordPress resource is outside the rendered Research verification contract.');
        }
        $language = function_exists('eduardo_research_post_language')
            ? sanitize_key((string) eduardo_research_post_language($post_id))
            : sanitize_key((string) get_post_meta($post_id, '_research_language', true));
        if (! in_array($language, array('en','es'), true)) { $language = 'en'; }

        $schema_type = '';
        if ('research_output' === $post->post_type && function_exists('eduardo_research_output_schema_type')) {
            $schema_type = (string) eduardo_research_output_schema_type($post_id);
        } elseif ('research_project' === $post->post_type || 'research_line' === $post->post_type) {
            $schema_type = 'CreativeWork';
        } elseif ('research_software' === $post->post_type) {
            $schema_type = 'SoftwareSourceCode';
        } elseif ('research_dataset' === $post->post_type) {
            $schema_type = 'Dataset';
        }

        return $this->verify_url((string) get_permalink($post_id), array(
            'language'=>$language,
            'title_contains'=>(string) $post->post_title,
            'schema_type'=>$schema_type,
            'theme_marker'=>true,
        ));
    }

    public function verify_url(string $url, array $expected = array()): array|WP_Error {
        $url = esc_url_raw(trim($url), array('http','https'));
        if ('' === $url || ! $this->same_origin($url, home_url('/'))) {
            return new WP_Error('research_manager_frontend_url_not_allowed', 'Rendered verification is restricted to URLs on the current WordPress origin.');
        }

        $response = wp_remote_get($url, array(
            'timeout'=>10,
            'redirection'=>0,
            'limit_response_size'=>self::MAX_RESPONSE_BYTES,
            'headers'=>array('Accept'=>'text/html,application/xhtml+xml','User-Agent'=>'Eduardo-Research-Manager/' . (defined('EDUARDO_RESEARCH_MANAGER_VERSION') ? EDUARDO_RESEARCH_MANAGER_VERSION : 'dev')),
        ));
        if (is_wp_error($response)) {
            return new WP_Error('research_manager_frontend_transport_failed', 'The public Research URL could not be fetched.', array('transport_error'=>$response->get_error_message()));
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        $content_type = strtolower((string) wp_remote_retrieve_header($response, 'content-type'));
        if (strlen($body) >= self::MAX_RESPONSE_BYTES) {
            return new WP_Error('research_manager_frontend_response_too_large', 'Rendered verification response reached the safety size limit.');
        }

        $canonical = $this->extract_canonical($body);
        $document_title = $this->extract_document_title($body);
        $html_language = $this->extract_html_language($body);
        $checks = array(
            'http_200'=>200 === $status,
            'html_response'=>'' === $content_type || str_contains($content_type, 'text/html') || str_contains($content_type, 'application/xhtml+xml'),
            'theme_marker'=>empty($expected['theme_marker']) || str_contains($body, 'seo-geo-preset-research'),
            'canonical_present'=>'' !== $canonical,
            'canonical_matches'=>'' !== $canonical && $this->urls_equal($canonical, $url),
            'indexable'=>! $this->contains_noindex($body),
        );

        $expected_language = sanitize_key((string) ($expected['language'] ?? ''));
        if ('' !== $expected_language) {
            $checks['language'] = '' !== $html_language && ($html_language === $expected_language || str_starts_with(strtolower($html_language), strtolower($expected_language) . '-'));
        }

        $title_contains = trim((string) ($expected['title_contains'] ?? ''));
        if ('' !== $title_contains) {
            $checks['title'] = '' !== $document_title && false !== mb_stripos($document_title, $title_contains);
        }

        $schema_type = trim((string) ($expected['schema_type'] ?? ''));
        if ('' !== $schema_type) {
            $checks['schema_type'] = $this->contains_schema_type($body, $schema_type);
        }

        return array(
            'verified'=>! in_array(false, $checks, true),
            'url'=>$url,
            'status'=>$status,
            'canonical'=>$canonical,
            'document_title'=>$document_title,
            'html_language'=>$html_language,
            'checks'=>$checks,
            'verified_at'=>gmdate(DATE_W3C),
        );
    }

    private function same_origin(string $left, string $right): bool {
        $a = wp_parse_url($left);
        $b = wp_parse_url($right);
        if (! is_array($a) || ! is_array($b)) { return false; }
        foreach (array('scheme','host') as $key) {
            if (strtolower((string) ($a[$key] ?? '')) !== strtolower((string) ($b[$key] ?? ''))) { return false; }
        }
        return $this->effective_port($a) === $this->effective_port($b);
    }

    private function effective_port(array $parts): int {
        if (isset($parts['port'])) { return (int) $parts['port']; }
        return 'https' === strtolower((string) ($parts['scheme'] ?? '')) ? 443 : 80;
    }

    private function urls_equal(string $left, string $right): bool {
        return untrailingslashit(esc_url_raw($left)) === untrailingslashit(esc_url_raw($right));
    }

    private function extract_canonical(string $html): string {
        if (! preg_match_all('/<link\b[^>]*>/i', $html, $matches)) { return ''; }
        foreach ($matches[0] as $tag) {
            if (! preg_match('/\brel\s*=\s*["\']([^"\']*)["\']/i', $tag, $rel)) { continue; }
            $rels = preg_split('/\s+/', strtolower(trim((string) $rel[1]))) ?: array();
            if (! in_array('canonical', $rels, true)) { continue; }
            if (preg_match('/\bhref\s*=\s*["\']([^"\']+)["\']/i', $tag, $href)) {
                return html_entity_decode((string) $href[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }
        return '';
    }

    private function extract_document_title(string $html): string {
        if (! preg_match('/<title\b[^>]*>(.*?)<\/title>/is', $html, $match)) { return ''; }
        return trim(wp_strip_all_tags(html_entity_decode((string) $match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    private function extract_html_language(string $html): string {
        if (! preg_match('/<html\b[^>]*\blang\s*=\s*["\']([^"\']+)["\']/i', $html, $match)) { return ''; }
        return strtolower(trim((string) $match[1]));
    }

    private function contains_noindex(string $html): bool {
        if (! preg_match_all('/<meta\b[^>]*>/i', $html, $matches)) { return false; }
        foreach ($matches[0] as $tag) {
            if (! preg_match('/\bname\s*=\s*["\']robots["\']/i', $tag)) { continue; }
            if (preg_match('/\bcontent\s*=\s*["\']([^"\']*)["\']/i', $tag, $content) && str_contains(strtolower((string) $content[1]), 'noindex')) { return true; }
        }
        return false;
    }

    private function contains_schema_type(string $html, string $schema_type): bool {
        $quoted = preg_quote($schema_type, '/');
        return 1 === preg_match('/["\']@type["\']\s*:\s*["\']' . $quoted . '["\']/i', $html);
    }
}
