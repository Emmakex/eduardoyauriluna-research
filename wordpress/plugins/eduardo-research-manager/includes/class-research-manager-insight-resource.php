<?php
/** Structured editorial service for Theme-owned research Insights. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Insight_Resource {
    private Eduardo_Research_Manager_Contract $contract;

    public function __construct(?Eduardo_Research_Manager_Contract $contract = null) {
        $this->contract = $contract ?: new Eduardo_Research_Manager_Contract();
    }

    public function inspect(int $post_id): array|WP_Error {
        $valid = $this->validate_contract();
        if (is_wp_error($valid)) { return $valid; }
        $post = get_post($post_id);
        if (! $post instanceof WP_Post || 'post' !== $post->post_type) {
            return new WP_Error('research_manager_insight_missing', 'The requested Insight does not exist or is not a Theme editorial post.');
        }

        $language = sanitize_key((string) get_post_meta($post_id, '_research_language', true));
        if ('' === $language) { $language = 'en'; }
        $insight_type = sanitize_key((string) get_post_meta($post_id, '_research_insight_type', true));
        if ('' === $insight_type) { $insight_type = 'research_note'; }

        return array(
            'post_id'=>$post_id,
            'post_type'=>'post',
            'status'=>(string) $post->post_status,
            'slug'=>(string) $post->post_name,
            'title'=>(string) $post->post_title,
            'excerpt'=>(string) $post->post_excerpt,
            'content'=>(string) $post->post_content,
            'language'=>$language,
            'insight_type'=>$insight_type,
            'creation_token'=>(string) get_post_meta($post_id, '_eduardo_research_manager_creation_token', true),
            'url'=>(string) get_permalink($post),
        );
    }

    public function build_creation_plan(array $data, string $intent = ''): array|WP_Error {
        $valid = $this->validate_contract();
        if (is_wp_error($valid)) { return $valid; }

        $title = is_scalar($data['title'] ?? null) ? (string) $data['title'] : '';
        $slug = is_scalar($data['slug'] ?? null) ? (string) $data['slug'] : sanitize_title($title);
        $status = $this->normalise_status(is_scalar($data['status'] ?? null) ? (string) $data['status'] : 'draft');
        if (is_wp_error($status)) { return $status; }
        $language = sanitize_key(is_scalar($data['language'] ?? null) ? (string) $data['language'] : 'en');
        if (! in_array($language, $this->languages(), true)) {
            return new WP_Error('research_manager_unknown_language', 'Insight language is outside the active Research preset.');
        }
        $insight_type = sanitize_key(is_scalar($data['insight_type'] ?? null) ? (string) $data['insight_type'] : 'research_note');
        if (! array_key_exists($insight_type, $this->types())) {
            return new WP_Error('research_manager_unknown_insight_type', 'Insight type is outside the active Research Theme editorial contract.');
        }
        if ('' === trim($title) || '' === sanitize_title($slug)) {
            return new WP_Error('research_manager_invalid_insight_identity', 'Insight title and slug must produce a valid editorial identity.');
        }

        $action = array(
            'type'=>'create_insight',
            'title'=>$this->sanitize_editorial_field('title', $title),
            'slug'=>sanitize_title($slug),
            'excerpt'=>is_scalar($data['excerpt'] ?? null) ? (string) $data['excerpt'] : '',
            'content'=>is_scalar($data['content'] ?? null) ? (string) $data['content'] : '',
            'language'=>$language,
            'insight_type'=>$insight_type,
            'status'=>$status,
            'creation_token'=>wp_generate_uuid4(),
        );
        $intent = '' !== trim($intent)
            ? $intent
            : sprintf('Create Research Insight: %s', sanitize_text_field($title));
        return Eduardo_Research_Manager_Plan::create($intent, array($action));
    }

    public function build_update_plan(int $post_id, array $changes, string $intent = ''): array|WP_Error {
        $current = $this->inspect($post_id);
        if (is_wp_error($current)) { return $current; }
        if (! $changes) {
            return new WP_Error('research_manager_empty_update', 'At least one Insight field must be supplied.');
        }

        $allowed = array('title','excerpt','content','language','insight_type','status');
        $unknown = array_diff(array_keys($changes), $allowed);
        if ($unknown) {
            return new WP_Error(
                'research_manager_insight_field_not_allowed',
                'Insight update contains fields outside the bounded editorial contract: ' . implode(', ', array_map('sanitize_key', $unknown))
            );
        }

        $actions = array();
        foreach ($changes as $field => $value) {
            if (! is_scalar($value)) {
                return new WP_Error('research_manager_invalid_insight_value', sprintf('Insight field "%s" must be scalar.', $field));
            }
            if ('language' === $field) {
                $value = sanitize_key((string) $value);
                if (! in_array($value, $this->languages(), true)) {
                    return new WP_Error('research_manager_unknown_language', 'Insight language is outside the active Research preset.');
                }
                if ($value !== (string) $current['language']) {
                    $actions[] = array('type'=>'post_meta','post_id'=>$post_id,'key'=>'_research_language','value'=>$value);
                }
                continue;
            }
            if ('insight_type' === $field) {
                $value = sanitize_key((string) $value);
                if (! array_key_exists($value, $this->types())) {
                    return new WP_Error('research_manager_unknown_insight_type', 'Insight type is outside the active Research Theme editorial contract.');
                }
                if ($value !== (string) $current['insight_type']) {
                    $actions[] = array('type'=>'post_meta','post_id'=>$post_id,'key'=>'_research_insight_type','value'=>$value);
                }
                continue;
            }
            if ('status' === $field) {
                $status = $this->normalise_status((string) $value);
                if (is_wp_error($status)) { return $status; }
                if ($status !== (string) $current['status']) {
                    $actions[] = array('type'=>'post_field','post_id'=>$post_id,'field'=>'post_status','value'=>$status);
                }
                continue;
            }

            $wp_field = array('title'=>'post_title','excerpt'=>'post_excerpt','content'=>'post_content')[$field];
            $clean = $this->sanitize_editorial_field($field, (string) $value);
            if ($clean !== (string) $current[$field]) {
                $actions[] = array('type'=>'post_field','post_id'=>$post_id,'field'=>$wp_field,'value'=>$clean);
            }
        }

        if (! $actions) {
            return new WP_Error('research_manager_no_change', 'The requested Insight update already matches stored state.');
        }
        $intent = '' !== trim($intent)
            ? $intent
            : sprintf('Update Research Insight #%d', $post_id);
        return Eduardo_Research_Manager_Plan::create($intent, $actions);
    }

    public function verify(int $post_id, array $expected, ?string $creation_token = null): array|WP_Error {
        $record = $this->inspect($post_id);
        if (is_wp_error($record)) { return $record; }
        $allowed = array('status','slug','title','excerpt','content','language','insight_type');
        $unknown = array_diff(array_keys($expected), $allowed);
        if ($unknown) {
            return new WP_Error('research_manager_insight_field_not_allowed', 'Insight verification requested unsupported fields.');
        }

        $checks = array('route'=>'' !== (string) $record['url']);
        foreach ($expected as $field => $value) {
            if ('language' === $field) { $value = sanitize_key((string) $value); }
            elseif ('insight_type' === $field || 'status' === $field) { $value = sanitize_key((string) $value); }
            elseif ('slug' === $field) { $value = sanitize_title((string) $value); }
            else { $value = $this->sanitize_editorial_field($field, (string) $value); }
            $checks[$field] = maybe_serialize($record[$field] ?? null) === maybe_serialize($value);
        }
        if (null !== $creation_token) {
            $checks['provenance'] = '' !== $creation_token
                && hash_equals($creation_token, (string) $record['creation_token']);
        }

        return array(
            'verified'=>! in_array(false, $checks, true),
            'post_id'=>$post_id,
            'url'=>$record['url'],
            'checks'=>$checks,
            'verified_at'=>gmdate(DATE_W3C),
        );
    }

    public function find_created_by_token(string $token): int {
        if ('' === trim($token)) { return 0; }
        $posts = get_posts(array(
            'post_type'=>'post',
            'post_status'=>'any',
            'posts_per_page'=>2,
            'orderby'=>'ID',
            'order'=>'ASC',
            'meta_key'=>'_eduardo_research_manager_creation_token',
            'meta_value'=>$token,
            'suppress_filters'=>true,
        ));
        return isset($posts[0]) && $posts[0] instanceof WP_Post ? (int) $posts[0]->ID : 0;
    }

    public function languages(): array {
        return $this->contract->languages();
    }

    public function types(): array {
        if (! function_exists('eduardo_research_insight_types')) { return array(); }
        $types = eduardo_research_insight_types('en');
        return is_array($types) ? $types : array();
    }

    private function validate_contract(): bool|WP_Error {
        if (! $this->contract->compatible() || ! function_exists('eduardo_research_insight_types')) {
            return new WP_Error('research_manager_theme_contract_unavailable', 'A compatible Research Theme editorial contract is required.');
        }
        if (! in_array('en', $this->languages(), true) || ! $this->types()) {
            return new WP_Error('research_manager_insight_contract_invalid', 'The active Research Theme does not expose a usable Insight contract.');
        }
        return true;
    }

    private function normalise_status(string $status): string|WP_Error {
        $status = sanitize_key($status);
        if (! in_array($status, array('draft','publish'), true)) {
            return new WP_Error('research_manager_insight_status_not_allowed', 'Research Insights may transition only between draft and publish.');
        }
        return $status;
    }

    private function sanitize_editorial_field(string $field, string $value): string {
        if ('content' === $field) { return wp_kses_post($value); }
        if ('excerpt' === $field) { return sanitize_textarea_field($value); }
        if ('slug' === $field) { return sanitize_title($value); }
        if ('status' === $field) { return sanitize_key($value); }
        return sanitize_text_field($value);
    }
}
