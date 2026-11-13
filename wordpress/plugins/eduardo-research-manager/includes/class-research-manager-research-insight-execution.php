<?php
/** Research vertical execution for Insight creation; the generic Executor only orchestrates. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

require_once EDUARDO_RESEARCH_MANAGER_DIR . 'includes/class-research-manager-research-mutation-policy.php';

/**
 * Preserve the installed 0.11.1 Insight read/create/rollback/Verify semantics.
 * This adapter is intentionally Research-only; a Theme preset or nominally
 * matching action never grants another Theme the ability to create Insights.
 */
final class Eduardo_Research_Manager_Research_Insight_Execution {
    public function __construct(
        private Site_Architect_Mutation_Policy $mutation_policy,
        private Eduardo_Research_Manager_Contract $theme_contract
    ) {}

    private function allowed(array $action): bool {
        return 'create_insight' === (string) ($action['type'] ?? '')
            && $this->mutation_policy instanceof Eduardo_Research_Manager_Research_Mutation_Policy
            && 'research' === (string) ($this->theme_contract->preset()['id'] ?? '')
            && in_array('create_insight', $this->mutation_policy->allowed_action_types(), true);
    }

    private function denied(): WP_Error {
        return new WP_Error('research_manager_action_not_allowed', 'Research creation is outside the active Theme execution contract.');
    }


    public function read_state(array $action): array|WP_Error {
        if (! $this->allowed($action)) { return $this->denied(); }
        $post = $this->find_post_by_creation_token((string) $action['creation_token'], 'post');
        if (! $post instanceof WP_Post) { $post = $this->find_post_by_slug((string) $action['slug'], 'post'); }
        if (! $post instanceof WP_Post) { return array('exists'=>false,'value'=>null); }
        return array('exists'=>true,'value'=>array(
            'post_id'=>(int) $post->ID,'post_type'=>(string) $post->post_type,'status'=>(string) $post->post_status,
            'slug'=>(string) $post->post_name,'title'=>(string) $post->post_title,'excerpt'=>(string) $post->post_excerpt,
            'content'=>(string) $post->post_content,'language'=>$this->private_meta_string((int) $post->ID, '_research_language', Eduardo_Research_Manager::contract()->default_language()),
            'insight_type'=>$this->private_meta_string((int) $post->ID, '_research_insight_type', 'research_note'),
            'creation_token'=>$this->private_meta_string((int) $post->ID, '_eduardo_research_manager_creation_token'),
            'url'=>(string) get_permalink($post),
        ));
    }


    public function create(array $action): bool|WP_Error {
        if (! $this->allowed($action)) { return $this->denied(); }
        if ($this->find_post_by_slug((string) $action['slug'], 'post') instanceof WP_Post) { return new WP_Error('research_manager_insight_creation_conflict', 'A WordPress post already occupies the planned Insight slug. Nothing was overwritten.'); }
        if ($this->find_post_by_creation_token((string) $action['creation_token'], 'post') instanceof WP_Post) { return new WP_Error('research_manager_creation_token_collision', 'The Insight creation provenance token is already in use.'); }
        $id = wp_insert_post(array(
            'post_type'=>'post','post_status'=>$action['status'],'post_name'=>$action['slug'],'post_title'=>$action['title'],
            'post_excerpt'=>$action['excerpt'],'post_content'=>$action['content'],
            'meta_input'=>array('_research_language'=>$action['language'],'_research_insight_type'=>$action['insight_type'],'_eduardo_research_manager_creation_token'=>$action['creation_token']),
        ), true);
        if (is_wp_error($id)) { return $id; }
        clean_post_cache((int) $id); $this->refresh_theme_routes(); return true;
    }

    /** Only remove a WordPress post carrying the Manager's own provenance token. */
    public function restore(array $action, array $state): bool|WP_Error {
        if (! $this->allowed($action)) { return $this->denied(); }
        if (empty($state['exists'])) {
            $token = (string) ($action['creation_token'] ?? '');
            $owned = $this->find_post_by_creation_token($token, 'post');
            if ($owned instanceof WP_Post) {
                if (! wp_delete_post((int) $owned->ID, true)) {
                    return new WP_Error('research_manager_resource_rollback_failed', 'The Manager could not delete the resource it created.');
                }
            } elseif ($this->find_post_by_slug((string) ($action['slug'] ?? ''), 'post') instanceof WP_Post) {
                return new WP_Error('research_manager_insight_rollback_conflict', 'Rollback found the planned slug occupied without this Manager provenance token. It was not deleted.');
            }
        }
        $this->refresh_theme_routes();
        return true;
    }

    public function desired_value(array $action): array|WP_Error {
        if (! $this->allowed($action)) { return $this->denied(); }
        return array('post_type'=>'post','status'=>$action['status'],'slug'=>$action['slug'],'title'=>$action['title'],'excerpt'=>$action['excerpt'],'content'=>$action['content'],'language'=>$action['language'],'insight_type'=>$action['insight_type'],'creation_token'=>$action['creation_token']);
    }


    private function read_post_meta_storage(int $post_id, string $key): array {
        global $wpdb;
        $raw = $wpdb->get_var($wpdb->prepare("SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id ASC LIMIT 1", $post_id, $key));
        return array('exists'=>null !== $raw,'value'=>null === $raw ? null : maybe_unserialize($raw));
    }


    private function private_meta_value(int $post_id, string $key, mixed $default = null): mixed {
        $state = $this->read_post_meta_storage($post_id, $key);
        return ! empty($state['exists']) ? $state['value'] : $default;
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
