<?php
/** Research vertical owns execution of its five code-reviewed resource families. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

require_once EDUARDO_RESEARCH_MANAGER_DIR . 'includes/class-research-manager-research-execution-spec.php';

/**
 * The generic Executor owns Plan/Preview/Apply/Verify/Rollback orchestration.
 * This adapter owns ONLY executable Research CPT read/create/restore semantics.
 * Every entrypoint rechecks the concrete Research policy and Theme identity;
 * neither Registry descriptors nor a foreign Theme can enable WordPress writes.
 */
final class Eduardo_Research_Manager_Research_Object_Execution {
    public function __construct(
        private Site_Architect_Mutation_Policy $mutation_policy,
        private Eduardo_Research_Manager_Contract $theme_contract
    ) {}

    public function spec(string $action_type): array {
        if (! $this->mutation_policy instanceof Eduardo_Research_Manager_Research_Mutation_Policy
            || 'research' !== (string) ($this->theme_contract->preset()['id'] ?? '')
            || ! in_array($action_type, $this->mutation_policy->allowed_action_types(), true)) {
            return array();
        }
        return Eduardo_Research_Manager_Research_Execution_Spec::for_action($action_type);
    }

    public function read_state(array $action): array|WP_Error {
        $spec = $this->spec((string) ($action['type'] ?? ''));
        if (! $spec) { return $this->denied(); }
        $post_type = (string) $spec['post_type'];
        $post = $this->find_post_by_creation_token((string) $action['creation_token'], $post_type);
        if (! $post instanceof WP_Post) { $post = $this->find_post_by_slug((string) $action['slug'], $post_type); }
        if (! $post instanceof WP_Post) { return array('exists'=>false,'value'=>null); }
        $value = array(
            'post_id'=>(int) $post->ID,'post_type'=>$post_type,'status'=>(string) $post->post_status,
            'slug'=>(string) $post->post_name,'title'=>(string) $post->post_title,'excerpt'=>(string) $post->post_excerpt,
            'content'=>(string) $post->post_content,'language'=>$this->private_meta_string((int) $post->ID, '_research_language', Eduardo_Research_Manager::contract()->default_language()),
        );
        foreach ((array) $spec['meta'] as $field => $meta_key) {
            $default = in_array($field, (array) ($spec['array_fields'] ?? array()), true) ? array() : ('0' === (string) ($spec['defaults'][$field] ?? '') ? '0' : '');
            $value[$field] = $this->private_meta_value((int) $post->ID, (string) $meta_key, $default);
        }
        $value['creation_token'] = $this->private_meta_string((int) $post->ID, '_eduardo_research_manager_creation_token');
        $value['url'] = (string) get_permalink($post);
        return array('exists'=>true,'value'=>$value);
    }

    public function create(array $action): bool|WP_Error {
        $spec = $this->spec((string) ($action['type'] ?? ''));
        if (! $spec) { return $this->denied(); }
        $post_type = (string) $spec['post_type'];
        if ($this->find_post_by_slug((string) $action['slug'], $post_type) instanceof WP_Post) {
            return new WP_Error((string) $spec['creation_conflict'], 'A Research resource already occupies the planned slug. Nothing was overwritten.');
        }
        if ($this->find_post_by_creation_token((string) $action['creation_token'], $post_type) instanceof WP_Post) {
            return new WP_Error('research_manager_creation_token_collision', 'The Research resource provenance token is already in use.');
        }
        $meta = array('_research_language'=>$action['language'],'_eduardo_research_manager_creation_token'=>$action['creation_token']);
        foreach ((array) $spec['meta'] as $field => $meta_key) { $meta[(string) $meta_key] = $action[$field]; }
        $id = wp_insert_post(array(
            'post_type'=>$post_type,'post_status'=>$action['status'],'post_name'=>$action['slug'],'post_title'=>$action['title'],
            'post_excerpt'=>$action['excerpt'],'post_content'=>$action['content'],'meta_input'=>$meta,
        ), true);
        if (is_wp_error($id)) { return $id; }
        clean_post_cache((int) $id); $this->refresh_theme_routes(); return true;
    }

    /** Preserve provenance-only rollback; never delete a slug occupant without token. */
    public function restore(array $action, array $state): bool|WP_Error {
        $spec = $this->spec((string) ($action['type'] ?? ''));
        if (! $spec) { return $this->denied(); }
        if (empty($state['exists'])) {
            $post_type = (string) $spec['post_type'];
            $token = (string) ($action['creation_token'] ?? '');
            $owned = $this->find_post_by_creation_token($token, $post_type);
            if ($owned instanceof WP_Post) {
                if (! wp_delete_post((int) $owned->ID, true)) {
                    return new WP_Error('research_manager_resource_rollback_failed', 'The Manager could not delete the resource it created.');
                }
            } elseif ($this->find_post_by_slug((string) ($action['slug'] ?? ''), $post_type) instanceof WP_Post) {
                return new WP_Error((string) $spec['rollback_conflict'], 'Rollback found the planned slug occupied without this Manager provenance token. It was not deleted.');
            }
        }
        $this->refresh_theme_routes();
        return true;
    }

    public function desired_value(array $action): array|WP_Error {
        $spec = $this->spec((string) ($action['type'] ?? ''));
        if (! $spec) { return $this->denied(); }
        $value = array(
            'post_type'=>$spec['post_type'],'status'=>$action['status'],'slug'=>$action['slug'],'title'=>$action['title'],
            'excerpt'=>$action['excerpt'],'content'=>$action['content'],'language'=>$action['language'],
        );
        foreach ((array) $spec['meta'] as $field => $_) { $value[$field] = $action[$field]; }
        $value['creation_token'] = $action['creation_token'];
        return $value;
    }

    private function denied(): WP_Error {
        return new WP_Error('research_manager_action_not_allowed', 'Research object execution is outside the active Theme execution contract.');
    }

    /** WordPress metadata storage semantics must remain identical to Executor. */
    private function private_meta_value(int $post_id, string $key, mixed $default = null): mixed {
        global $wpdb;
        $raw = $wpdb->get_var($wpdb->prepare("SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id ASC LIMIT 1", $post_id, $key));
        return null !== $raw ? maybe_unserialize($raw) : $default;
    }

    private function private_meta_string(int $post_id, string $key, string $default = ''): string {
        $value = $this->private_meta_value($post_id, $key, $default);
        return is_scalar($value) ? (string) $value : $default;
    }

    private function find_post_by_creation_token(string $token, string $post_type): ?WP_Post {
        if ('' === $token) { return null; }
        $posts = get_posts(array('post_type'=>$post_type,'post_status'=>'any','posts_per_page'=>2,'orderby'=>'ID','order'=>'ASC','meta_key'=>'_eduardo_research_manager_creation_token','meta_value'=>$token,'suppress_filters'=>true));
        return isset($posts[0]) && $posts[0] instanceof WP_Post ? $posts[0] : null;
    }

    private function find_post_by_slug(string $slug, string $post_type): ?WP_Post {
        if ('' === $slug) { return null; }
        $posts = get_posts(array('post_type'=>$post_type,'post_status'=>'any','name'=>$slug,'posts_per_page'=>1,'suppress_filters'=>true));
        return isset($posts[0]) && $posts[0] instanceof WP_Post ? $posts[0] : null;
    }

    private function refresh_theme_routes(): void {
        if (function_exists('eduardo_research_multilingual_rewrites')) { eduardo_research_multilingual_rewrites(); }
        if (function_exists('eduardo_research_content_language_rewrites')) { eduardo_research_content_language_rewrites(); }
        flush_rewrite_rules(false);
    }
}
