<?php
/** Evidence-aware service for first-class Research Lines. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Line_Resource {
    private Eduardo_Research_Manager_Contract $contract;

    public function __construct(?Eduardo_Research_Manager_Contract $contract = null) {
        $this->contract = $contract ?: new Eduardo_Research_Manager_Contract();
    }

    public function inspect(int $post_id): array|WP_Error {
        $valid = $this->validate_contract();
        if (is_wp_error($valid)) { return $valid; }
        $post = get_post($post_id);
        $post_type = $this->post_type();
        if (! $post instanceof WP_Post || $post_type !== $post->post_type) {
            return new WP_Error('research_manager_line_missing', 'The requested Research Line does not exist.');
        }

        $record = array(
            'post_id'=>$post_id,
            'post_type'=>$post_type,
            'status'=>(string) $post->post_status,
            'slug'=>(string) $post->post_name,
            'title'=>(string) $post->post_title,
            'excerpt'=>(string) $post->post_excerpt,
            'content'=>(string) $post->post_content,
        );
        foreach ($this->meta_fields() as $field => $meta_key) {
            $default = $this->default_for_field($field);
            $record[$field] = in_array($field, $this->array_fields(), true)
                ? $this->private_meta_value($post_id, $meta_key, $default)
                : $this->private_meta_string($post_id, $meta_key, (string) $default);
        }
        $record['creation_token'] = $this->private_meta_string($post_id, '_eduardo_research_manager_creation_token');
        $record['url'] = (string) get_permalink($post);
        return $record;
    }

    public function build_creation_plan(array $data, string $intent = '', array $context = array()): array|WP_Error {
        $valid = $this->validate_contract();
        if (is_wp_error($valid)) { return $valid; }
        foreach ($this->array_fields() as $array_field) {
            if (array_key_exists($array_field, $data) && ! is_array($data[$array_field])) {
                return new WP_Error(
                    'research_manager_invalid_line_' . sanitize_key($array_field),
                    sprintf('Research Line %s must be supplied as an array.', str_replace('_', ' ', $array_field))
                );
            }
        }

        $title = is_scalar($data['title'] ?? null) ? (string) $data['title'] : '';
        $action = array(
            'type'=>'create_line',
            'title'=>$title,
            'slug'=>is_scalar($data['slug'] ?? null) ? (string) $data['slug'] : sanitize_title($title),
            'excerpt'=>is_scalar($data['excerpt'] ?? null) ? (string) $data['excerpt'] : '',
            'content'=>is_scalar($data['content'] ?? null) ? (string) $data['content'] : '',
            'language'=>is_scalar($data['language'] ?? null) ? (string) $data['language'] : $this->contract->default_language(),
            'status'=>is_scalar($data['status'] ?? null) ? (string) $data['status'] : 'draft',
            'creation_token'=>wp_generate_uuid4(),
        );
        foreach ($this->meta_fields() as $field => $_meta_key) {
            if ('language' === $field) { continue; }
            if (in_array($field, $this->array_fields(), true)) {
                $action[$field] = $data[$field] ?? array();
                continue;
            }
            $default = $this->default_for_field($field);
            $action[$field] = is_scalar($data[$field] ?? null) ? (string) $data[$field] : $default;
        }

        $intent = '' !== trim($intent) ? $intent : sprintf('Create verified Research Line: %s', sanitize_text_field($title));
        return Eduardo_Research_Manager_Plan::create($intent, array($action), $context);
    }

    public function build_update_plan(int $post_id, array $changes, string $intent = '', array $context = array()): array|WP_Error {
        $current = $this->inspect($post_id);
        if (is_wp_error($current)) { return $current; }
        if (! $changes) { return new WP_Error('research_manager_empty_update', 'At least one Research Line field must be supplied.'); }
        $allowed = $this->resource_fields();
        $unknown = array_diff(array_keys($changes), $allowed);
        if ($unknown) {
            return new WP_Error('research_manager_line_field_not_allowed', 'Research Line update contains fields outside the bounded contract: ' . implode(', ', array_map('sanitize_key', $unknown)));
        }
        foreach ($this->array_fields() as $array_field) {
            if (array_key_exists($array_field, $changes) && ! is_array($changes[$array_field])) {
                return new WP_Error('research_manager_invalid_line_list', 'Research Line list fields must be supplied as arrays.');
            }
        }

        $synthetic = $this->creation_action_from_record($current);
        foreach ($changes as $field => $value) { $synthetic[$field] = $value; }
        $normalization = Eduardo_Research_Manager_Plan::create('Normalize Research Line update', array($synthetic), $context);
        if (is_wp_error($normalization)) { return $normalization; }
        $next = $normalization['actions'][0];
        $actions = array();

        foreach (array('status'=>'post_status','slug'=>'post_name','title'=>'post_title','excerpt'=>'post_excerpt','content'=>'post_content') as $field => $wp_field) {
            if (! array_key_exists($field, $changes)) { continue; }
            if ('slug' === $field && '' === sanitize_title((string) ($next[$field] ?? ''))) {
                return new WP_Error('research_manager_line_slug_required', 'Research Line slug cannot be empty.');
            }
            if ((string) $current[$field] !== (string) $next[$field]) {
                $actions[] = array('type'=>'post_field','post_id'=>$post_id,'field'=>$wp_field,'value'=>$next[$field]);
            }
        }
        foreach ($this->meta_fields() as $field => $meta_key) {
            if (! array_key_exists($field, $changes)) { continue; }
            if (maybe_serialize($current[$field]) !== maybe_serialize($next[$field])) {
                $actions[] = array('type'=>'post_meta','post_id'=>$post_id,'key'=>$meta_key,'value'=>$next[$field]);
            }
        }
        if (! $actions) { return new WP_Error('research_manager_no_change', 'The requested Research Line update already matches stored state.'); }
        $intent = '' !== trim($intent) ? $intent : sprintf('Update Research Line #%d', $post_id);
        return Eduardo_Research_Manager_Plan::create($intent, $actions, $context);
    }

    public function verify(int $post_id, array $expected, ?string $creation_token = null): array|WP_Error {
        $record = $this->inspect($post_id);
        if (is_wp_error($record)) { return $record; }
        $allowed = $this->resource_fields();
        $unknown = array_diff(array_keys($expected), $allowed);
        if ($unknown) { return new WP_Error('research_manager_line_field_not_allowed', 'Research Line verification requested unsupported fields.'); }

        $synthetic = $this->creation_action_from_record($record);
        foreach ($expected as $field => $value) { $synthetic[$field] = $value; }
        $normalized = Eduardo_Research_Manager_Plan::create(
            'Normalize Research Line verification', array($synthetic),
            array('evidence_confirmed'=>true,'evidence_reference'=>'verification-only')
        );
        if (is_wp_error($normalized)) { return $normalized; }
        $next = $normalized['actions'][0];
        $checks = array('route'=>'' !== (string) $record['url']);
        foreach ($expected as $field => $_) {
            $checks[$field] = maybe_serialize($record[$field] ?? null) === maybe_serialize($next[$field] ?? null);
        }
        if (null !== $creation_token) {
            $checks['provenance'] = '' !== $creation_token && hash_equals($creation_token, (string) $record['creation_token']);
        }
        return array('verified'=>! in_array(false, $checks, true),'post_id'=>$post_id,'url'=>$record['url'],'checks'=>$checks,'verified_at'=>gmdate(DATE_W3C));
    }

    public function find_created_by_token(string $token): int {
        if ('' === trim($token)) { return 0; }
        $posts = get_posts(array(
            'post_type'=>$this->post_type(),'post_status'=>'any','posts_per_page'=>2,'orderby'=>'ID','order'=>'ASC',
            'meta_key'=>'_eduardo_research_manager_creation_token','meta_value'=>$token,'suppress_filters'=>true,
        ));
        return isset($posts[0]) && $posts[0] instanceof WP_Post ? (int) $posts[0]->ID : 0;
    }

    public function find_by_slug(string $slug, ?string $language = null): int {
        $slug = sanitize_title($slug);
        if ('' === $slug) { return 0; }
        $args = array('post_type'=>$this->post_type(),'post_status'=>'any','name'=>$slug,'posts_per_page'=>10,'suppress_filters'=>true);
        $posts = get_posts($args);
        $language_meta = (string) ($this->meta_fields()['language'] ?? '');
        foreach ($posts as $post) {
            if (! $post instanceof WP_Post) { continue; }
            if (null === $language || sanitize_key($language) === $this->private_meta_string((int) $post->ID, $language_meta, $this->contract->default_language())) { return (int) $post->ID; }
        }
        return 0;
    }

    private function validate_contract(): bool|WP_Error {
        if (! $this->contract->compatible() || ! post_type_exists($this->post_type())) {
            return new WP_Error('research_manager_theme_contract_unavailable', 'A compatible Research Theme line contract is required.');
        }
        $catalogs = $this->contract->line_catalogs();
        if (is_wp_error($catalogs)) {
            return new WP_Error('research_manager_theme_contract_unavailable', 'A compatible Research Theme line contract is required.');
        }
        return true;
    }

    private function post_type(): string {
        $registry = Eduardo_Research_Manager_Resource_Registry::get('line');
        return (string) ($registry['post_type'] ?? '');
    }

    private function resource_fields(): array {
        return Eduardo_Research_Manager_Resource_Registry::resource_fields('line');
    }

    private function meta_fields(): array {
        return Eduardo_Research_Manager_Resource_Registry::resource_meta_fields('line');
    }

    private function array_fields(): array {
        return Eduardo_Research_Manager_Resource_Registry::array_fields('line');
    }

    private function defaults(): array {
        return Eduardo_Research_Manager_Resource_Registry::defaults('line');
    }

    private function default_for_field(string $field): mixed {
        if ('language' === $field) { return $this->contract->default_language(); }
        if (in_array($field, $this->array_fields(), true)) { return array(); }
        $defaults = $this->defaults();
        return $defaults[$field] ?? '';
    }

    private function creation_action_from_record(array $record): array {
        $action = array('type'=>'create_line','creation_token'=>wp_generate_uuid4());
        foreach ($this->resource_fields() as $field) {
            $action[$field] = $record[$field] ?? $this->default_for_field($field);
        }
        return $action;
    }

    private function private_meta_value(int $post_id, string $key, mixed $default = null): mixed {
        global $wpdb;
        $raw = $wpdb->get_var($wpdb->prepare("SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id ASC LIMIT 1", $post_id, $key));
        return null === $raw ? $default : maybe_unserialize($raw);
    }

    private function private_meta_string(int $post_id, string $key, string $default = ''): string {
        $value = $this->private_meta_value($post_id, $key, $default);
        return is_scalar($value) ? (string) $value : $default;
    }
}
