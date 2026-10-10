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
        if (! $post instanceof WP_Post || 'research_line' !== $post->post_type) {
            return new WP_Error('research_manager_line_missing', 'The requested Research Line does not exist.');
        }
        return array(
            'post_id'=>$post_id,
            'post_type'=>'research_line',
            'status'=>(string) $post->post_status,
            'slug'=>(string) $post->post_name,
            'title'=>(string) $post->post_title,
            'excerpt'=>(string) $post->post_excerpt,
            'content'=>(string) $post->post_content,
            'language'=>$this->private_meta_string($post_id, '_research_language', 'en'),
            'evidence_status'=>$this->private_meta_string($post_id, '_research_evidence_status', 'unverified'),
            'research_status'=>$this->private_meta_string($post_id, '_research_status', 'planned'),
            'central_question'=>$this->private_meta_string($post_id, '_research_central_question'),
            'order'=>$this->private_meta_string($post_id, '_research_order'),
            'topics'=>$this->private_meta_value($post_id, '_research_topics', array()),
            'methods'=>$this->private_meta_value($post_id, '_research_methods', array()),
            'creation_token'=>$this->private_meta_string($post_id, '_eduardo_research_manager_creation_token'),
            'url'=>(string) get_permalink($post),
        );
    }

    public function build_creation_plan(array $data, string $intent = '', array $context = array()): array|WP_Error {
        $valid = $this->validate_contract();
        if (is_wp_error($valid)) { return $valid; }
        if (array_key_exists('topics', $data) && ! is_array($data['topics'])) {
            return new WP_Error('research_manager_invalid_line_topics', 'Research Line topics must be supplied as an array.');
        }
        if (array_key_exists('methods', $data) && ! is_array($data['methods'])) {
            return new WP_Error('research_manager_invalid_line_methods', 'Research Line methods must be supplied as an array.');
        }
        $title = is_scalar($data['title'] ?? null) ? (string) $data['title'] : '';
        $action = array(
            'type'=>'create_line',
            'title'=>$title,
            'slug'=>is_scalar($data['slug'] ?? null) ? (string) $data['slug'] : sanitize_title($title),
            'excerpt'=>is_scalar($data['excerpt'] ?? null) ? (string) $data['excerpt'] : '',
            'content'=>is_scalar($data['content'] ?? null) ? (string) $data['content'] : '',
            'language'=>is_scalar($data['language'] ?? null) ? (string) $data['language'] : 'en',
            'status'=>is_scalar($data['status'] ?? null) ? (string) $data['status'] : 'draft',
            'evidence_status'=>is_scalar($data['evidence_status'] ?? null) ? (string) $data['evidence_status'] : 'unverified',
            'research_status'=>is_scalar($data['research_status'] ?? null) ? (string) $data['research_status'] : 'planned',
            'central_question'=>is_scalar($data['central_question'] ?? null) ? (string) $data['central_question'] : '',
            'order'=>is_scalar($data['order'] ?? null) ? (string) $data['order'] : '',
            'topics'=>$data['topics'] ?? array(),
            'methods'=>$data['methods'] ?? array(),
            'creation_token'=>wp_generate_uuid4(),
        );
        $intent = '' !== trim($intent) ? $intent : sprintf('Create verified Research Line: %s', sanitize_text_field($title));
        return Eduardo_Research_Manager_Plan::create($intent, array($action), $context);
    }

    public function build_update_plan(int $post_id, array $changes, string $intent = '', array $context = array()): array|WP_Error {
        $current = $this->inspect($post_id);
        if (is_wp_error($current)) { return $current; }
        if (! $changes) { return new WP_Error('research_manager_empty_update', 'At least one Research Line field must be supplied.'); }
        $allowed = array('title','excerpt','content','language','evidence_status','research_status','central_question','order','topics','methods');
        $unknown = array_diff(array_keys($changes), $allowed);
        if ($unknown) {
            return new WP_Error('research_manager_line_field_not_allowed', 'Research Line update contains fields outside the bounded contract: ' . implode(', ', array_map('sanitize_key', $unknown)));
        }
        foreach (array('topics','methods') as $array_field) {
            if (array_key_exists($array_field, $changes) && ! is_array($changes[$array_field])) {
                return new WP_Error('research_manager_invalid_line_list', 'Research Line topics and methods must be supplied as arrays.');
            }
        }

        $synthetic = array(
            'type'=>'create_line','title'=>$current['title'],'slug'=>$current['slug'],'excerpt'=>$current['excerpt'],'content'=>$current['content'],
            'language'=>$current['language'],'status'=>$current['status'],'evidence_status'=>$current['evidence_status'],'research_status'=>$current['research_status'],
            'central_question'=>$current['central_question'],'order'=>$current['order'],'topics'=>$current['topics'],'methods'=>$current['methods'],
            'creation_token'=>wp_generate_uuid4(),
        );
        foreach ($changes as $field => $value) { $synthetic[$field] = $value; }
        $normalization = Eduardo_Research_Manager_Plan::create('Normalize Research Line update', array($synthetic), $context);
        if (is_wp_error($normalization)) { return $normalization; }
        $next = $normalization['actions'][0];
        $actions = array();

        foreach (array('title'=>'post_title','excerpt'=>'post_excerpt','content'=>'post_content') as $field => $wp_field) {
            if (array_key_exists($field, $changes) && (string) $current[$field] !== (string) $next[$field]) {
                $actions[] = array('type'=>'post_field','post_id'=>$post_id,'field'=>$wp_field,'value'=>$next[$field]);
            }
        }
        $meta = array(
            'language'=>'_research_language','evidence_status'=>'_research_evidence_status','research_status'=>'_research_status',
            'central_question'=>'_research_central_question','order'=>'_research_order','topics'=>'_research_topics','methods'=>'_research_methods',
        );
        foreach ($meta as $field => $meta_key) {
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
        $allowed = array('status','slug','title','excerpt','content','language','evidence_status','research_status','central_question','order','topics','methods');
        $unknown = array_diff(array_keys($expected), $allowed);
        if ($unknown) { return new WP_Error('research_manager_line_field_not_allowed', 'Research Line verification requested unsupported fields.'); }

        $synthetic = array(
            'type'=>'create_line','title'=>$record['title'],'slug'=>$record['slug'],'excerpt'=>$record['excerpt'],'content'=>$record['content'],
            'language'=>$record['language'],'status'=>$record['status'],'evidence_status'=>$record['evidence_status'],'research_status'=>$record['research_status'],
            'central_question'=>$record['central_question'],'order'=>$record['order'],'topics'=>$record['topics'],'methods'=>$record['methods'],
            'creation_token'=>wp_generate_uuid4(),
        );
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
            'post_type'=>'research_line','post_status'=>'any','posts_per_page'=>2,'orderby'=>'ID','order'=>'ASC',
            'meta_key'=>'_eduardo_research_manager_creation_token','meta_value'=>$token,'suppress_filters'=>true,
        ));
        return isset($posts[0]) && $posts[0] instanceof WP_Post ? (int) $posts[0]->ID : 0;
    }

    public function find_by_slug(string $slug, ?string $language = null): int {
        $slug = sanitize_title($slug);
        if ('' === $slug) { return 0; }
        $args = array('post_type'=>'research_line','post_status'=>'any','name'=>$slug,'posts_per_page'=>10,'suppress_filters'=>true);
        $posts = get_posts($args);
        foreach ($posts as $post) {
            if (! $post instanceof WP_Post) { continue; }
            if (null === $language || sanitize_key($language) === $this->private_meta_string((int) $post->ID, '_research_language', 'en')) { return (int) $post->ID; }
        }
        return 0;
    }

    private function validate_contract(): bool|WP_Error {
        if (! $this->contract->compatible() || ! post_type_exists('research_line') || ! function_exists('eduardo_research_line_statuses') || ! function_exists('eduardo_research_line_evidence_statuses')) {
            return new WP_Error('research_manager_theme_contract_unavailable', 'A compatible Research Theme line contract is required.');
        }
        return true;
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
