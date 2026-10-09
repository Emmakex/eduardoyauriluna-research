<?php
/** Safe mutation executor for the Research Manager. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Executor {
    private Eduardo_Research_Manager_Snapshots $snapshots;

    public function __construct(?Eduardo_Research_Manager_Snapshots $snapshots = null) {
        $this->snapshots = $snapshots ?: new Eduardo_Research_Manager_Snapshots();
    }

    public function preview(array $plan): array|WP_Error {
        $valid = Eduardo_Research_Manager_Plan::validate($plan);
        if (is_wp_error($valid)) { return $valid; }

        $rows = array();
        foreach ($plan['actions'] as $action) {
            $before = $this->read_state($action);
            if (is_wp_error($before)) { return $before; }
            $rows[] = array(
                'action'=>$action,
                'before'=>$before,
                'after'=>$this->desired_value($action),
                'changed'=>! $this->state_matches_action($before, $action),
                'risk'=>Eduardo_Research_Manager_Plan::action_risk($action),
            );
        }

        $gate = Eduardo_Research_Manager_Plan::apply_gate($plan);
        return array(
            'plan_id'=>$plan['id'],
            'intent'=>$plan['intent'],
            'risk'=>$plan['risk'],
            'checksum'=>$plan['checksum'],
            'apply_allowed'=>! is_wp_error($gate),
            'apply_blocker'=>is_wp_error($gate) ? $gate->get_error_message() : '',
            'actions'=>$rows,
        );
    }

    public function apply(array $plan): array|WP_Error {
        if (! current_user_can('manage_options')) {
            return new WP_Error('research_manager_forbidden', 'You are not allowed to apply Research Manager mutations.');
        }
        $gate = Eduardo_Research_Manager_Plan::apply_gate($plan);
        if (is_wp_error($gate)) { return $gate; }

        $preview = $this->preview($plan);
        if (is_wp_error($preview)) { return $preview; }

        $before = array();
        $changed = false;
        foreach ($preview['actions'] as $row) {
            $before[] = array('action'=>$row['action'],'state'=>$row['before']);
            $changed = $changed || ! empty($row['changed']);
        }

        if (! $changed) {
            $verification = $this->verify($plan);
            return is_wp_error($verification) ? $verification : array(
                'status'=>'noop',
                'plan_id'=>$plan['id'],
                'snapshot_id'=>'',
                'verification'=>$verification,
            );
        }

        $snapshot_id = $this->snapshots->create($plan, $before);
        foreach ($plan['actions'] as $action) {
            $written = $this->write_action($action);
            if (is_wp_error($written)) {
                $this->restore_records($before);
                $this->snapshots->mark_rolled_back($snapshot_id);
                return $written;
            }
        }

        $verification = $this->verify($plan);
        if (is_wp_error($verification) || empty($verification['verified'])) {
            $this->restore_records($before);
            $this->snapshots->mark_rolled_back($snapshot_id);
            return is_wp_error($verification)
                ? $verification
                : new WP_Error('research_manager_verification_failed', 'Stored state did not match the mutation plan. The Manager rolled the change back.');
        }

        return array(
            'status'=>'applied',
            'plan_id'=>$plan['id'],
            'snapshot_id'=>$snapshot_id,
            'verification'=>$verification,
        );
    }

    public function verify(array $plan): array|WP_Error {
        $valid = Eduardo_Research_Manager_Plan::validate($plan);
        if (is_wp_error($valid)) { return $valid; }
        $results = array();
        $verified = true;
        foreach ($plan['actions'] as $action) {
            $state = $this->read_state($action);
            if (is_wp_error($state)) { return $state; }
            $matches = $this->state_matches_action($state, $action);
            $verified = $verified && $matches;
            $results[] = array(
                'action'=>$action,
                'matches'=>$matches,
                'stored'=>$state['value'] ?? null,
            );
        }
        return array('verified'=>$verified,'plan_id'=>$plan['id'],'actions'=>$results,'verified_at'=>gmdate(DATE_W3C));
    }

    public function rollback(string $snapshot_id): array|WP_Error {
        if (! current_user_can('manage_options')) {
            return new WP_Error('research_manager_forbidden', 'You are not allowed to rollback Research Manager mutations.');
        }
        $snapshot = $this->snapshots->get($snapshot_id);
        if (! $snapshot) {
            return new WP_Error('research_manager_snapshot_missing', 'Rollback snapshot was not found.');
        }
        if ('rolled-back' === (string) ($snapshot['status'] ?? '')) {
            return new WP_Error('research_manager_snapshot_used', 'This snapshot has already been rolled back.');
        }
        $records = is_array($snapshot['before'] ?? null) ? $snapshot['before'] : array();
        $restored = $this->restore_records($records);
        if (is_wp_error($restored)) { return $restored; }
        $this->snapshots->mark_rolled_back($snapshot_id);
        return array(
            'status'=>'rolled-back',
            'snapshot_id'=>$snapshot_id,
            'plan_id'=>(string) ($snapshot['plan_id'] ?? ''),
            'restored'=>count($records),
            'rolled_back_at'=>gmdate(DATE_W3C),
        );
    }

    private function read_state(array $action): array|WP_Error {
        if ('option' === $action['type']) {
            $marker = new stdClass();
            $value = get_option($action['key'], $marker);
            return array('exists'=>$value !== $marker,'value'=>$value === $marker ? null : $value);
        }
        if ('post_meta' === $action['type']) {
            return $this->read_post_meta_storage((int) $action['post_id'], (string) $action['key']);
        }
        if ('post_field' === $action['type']) {
            $post = get_post((int) $action['post_id']);
            if (! $post instanceof WP_Post) {
                return new WP_Error('research_manager_resource_missing', 'WordPress resource no longer exists.');
            }
            $field = (string) $action['field'];
            return array('exists'=>property_exists($post, $field),'value'=>$post->{$field} ?? null);
        }
        if ('create_page' === $action['type']) {
            return $this->read_page_creation_state($action);
        }
        if ('create_insight' === $action['type']) {
            return $this->read_insight_creation_state($action);
        }
        return new WP_Error('research_manager_action_not_allowed', 'Unsupported mutation action type.');
    }

    /** Read persisted private meta without Theme presentation filters. */
    private function read_post_meta_storage(int $post_id, string $key): array {
        global $wpdb;
        $raw = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id ASC LIMIT 1",
            $post_id,
            $key
        ));
        return array('exists'=>null !== $raw,'value'=>null === $raw ? null : maybe_unserialize($raw));
    }

    private function read_page_creation_state(array $action): array {
        $page = $this->find_page_for_creation_action($action);
        $site_front = array(
            'show_on_front'=>(string) get_option('show_on_front', 'posts'),
            'page_on_front'=>(int) get_option('page_on_front', 0),
        );
        if (! $page instanceof WP_Post) {
            return array('exists'=>false,'value'=>null,'site_front'=>$site_front);
        }

        $role = $this->read_post_meta_storage((int) $page->ID, '_eduardo_research_role');
        $model = $this->read_post_meta_storage((int) $page->ID, '_eduardo_research_model');
        $token = $this->read_post_meta_storage((int) $page->ID, '_eduardo_research_manager_creation_token');
        return array(
            'exists'=>true,
            'value'=>array(
                'post_id'=>(int) $page->ID,
                'post_type'=>(string) $page->post_type,
                'status'=>(string) $page->post_status,
                'wp_slug'=>(string) $page->post_name,
                'title'=>(string) $page->post_title,
                'role'=>! empty($role['exists']) ? (string) $role['value'] : '',
                'model'=>! empty($model['exists']) ? (string) $model['value'] : '',
                'creation_token'=>! empty($token['exists']) ? (string) $token['value'] : '',
                'front_page'=>'page' === $site_front['show_on_front'] && (int) $page->ID === $site_front['page_on_front'],
                'url'=>(string) get_permalink($page),
            ),
            'site_front'=>$site_front,
        );
    }

    private function read_insight_creation_state(array $action): array {
        $post = $this->find_post_by_creation_token((string) $action['creation_token'], 'post');
        if (! $post instanceof WP_Post) {
            $post = $this->find_post_by_slug((string) $action['slug'], 'post');
        }
        if (! $post instanceof WP_Post) {
            return array('exists'=>false,'value'=>null);
        }
        $language = $this->read_post_meta_storage((int) $post->ID, '_research_language');
        $insight_type = $this->read_post_meta_storage((int) $post->ID, '_research_insight_type');
        $token = $this->read_post_meta_storage((int) $post->ID, '_eduardo_research_manager_creation_token');
        return array(
            'exists'=>true,
            'value'=>array(
                'post_id'=>(int) $post->ID,
                'post_type'=>(string) $post->post_type,
                'status'=>(string) $post->post_status,
                'slug'=>(string) $post->post_name,
                'title'=>(string) $post->post_title,
                'excerpt'=>(string) $post->post_excerpt,
                'content'=>(string) $post->post_content,
                'language'=>! empty($language['exists']) ? (string) $language['value'] : 'en',
                'insight_type'=>! empty($insight_type['exists']) ? (string) $insight_type['value'] : 'research_note',
                'creation_token'=>! empty($token['exists']) ? (string) $token['value'] : '',
                'url'=>(string) get_permalink($post),
            ),
        );
    }

    private function write_action(array $action): bool|WP_Error {
        if ('option' === $action['type']) {
            update_option((string) $action['key'], $action['value'], false);
        } elseif ('post_meta' === $action['type']) {
            update_post_meta((int) $action['post_id'], (string) $action['key'], $action['value']);
        } elseif ('post_field' === $action['type']) {
            $result = wp_update_post(array('ID'=>(int) $action['post_id'],(string) $action['field']=>$action['value']), true);
            if (is_wp_error($result)) { return $result; }
        } elseif ('create_page' === $action['type']) {
            $created = $this->create_page($action);
            if (is_wp_error($created)) { return $created; }
        } elseif ('create_insight' === $action['type']) {
            $created = $this->create_insight($action);
            if (is_wp_error($created)) { return $created; }
        } else {
            return new WP_Error('research_manager_action_not_allowed', 'Unsupported mutation action type.');
        }

        $state = $this->read_state($action);
        if (is_wp_error($state)) { return $state; }
        if (! $this->state_matches_action($state, $action)) {
            return new WP_Error('research_manager_write_failed', 'WordPress did not persist the planned value.');
        }
        return true;
    }

    private function create_page(array $action): bool|WP_Error {
        $collision = get_page_by_path((string) $action['wp_slug'], OBJECT, 'page');
        if ($collision instanceof WP_Post) {
            return new WP_Error('research_manager_page_creation_conflict', 'A WordPress Page already occupies the Theme-controlled slug. Nothing was overwritten.');
        }
        if ($this->find_post_by_creation_token((string) $action['creation_token'], 'page') instanceof WP_Post) {
            return new WP_Error('research_manager_creation_token_collision', 'The Page creation provenance token is already in use.');
        }

        $page_id = wp_insert_post(array(
            'post_type'=>'page',
            'post_status'=>'publish',
            'post_name'=>(string) $action['wp_slug'],
            'post_title'=>(string) $action['title'],
            'post_content'=>'',
            'post_excerpt'=>'',
            'meta_input'=>array(
                '_eduardo_research_role'=>(string) $action['role'],
                '_eduardo_research_model'=>(string) $action['model'],
                '_eduardo_research_manager_creation_token'=>(string) $action['creation_token'],
            ),
        ), true);
        if (is_wp_error($page_id)) { return $page_id; }

        if (! empty($action['front_page'])) {
            update_option('show_on_front', 'page');
            update_option('page_on_front', (int) $page_id);
        }
        clean_post_cache((int) $page_id);
        $this->refresh_theme_routes();
        return true;
    }

    private function create_insight(array $action): bool|WP_Error {
        if ($this->find_post_by_slug((string) $action['slug'], 'post') instanceof WP_Post) {
            return new WP_Error('research_manager_insight_creation_conflict', 'A WordPress post already occupies the planned Insight slug. Nothing was overwritten.');
        }
        if ($this->find_post_by_creation_token((string) $action['creation_token'], 'post') instanceof WP_Post) {
            return new WP_Error('research_manager_creation_token_collision', 'The Insight creation provenance token is already in use.');
        }

        $post_id = wp_insert_post(array(
            'post_type'=>'post',
            'post_status'=>(string) $action['status'],
            'post_name'=>(string) $action['slug'],
            'post_title'=>(string) $action['title'],
            'post_excerpt'=>(string) $action['excerpt'],
            'post_content'=>(string) $action['content'],
            'meta_input'=>array(
                '_research_language'=>(string) $action['language'],
                '_research_insight_type'=>(string) $action['insight_type'],
                '_eduardo_research_manager_creation_token'=>(string) $action['creation_token'],
            ),
        ), true);
        if (is_wp_error($post_id)) { return $post_id; }

        clean_post_cache((int) $post_id);
        $this->refresh_theme_routes();
        return true;
    }

    private function restore_records(array $records): bool|WP_Error {
        foreach (array_reverse($records) as $record) {
            if (! is_array($record) || ! is_array($record['action'] ?? null) || ! is_array($record['state'] ?? null)) { continue; }
            $action = $record['action'];
            $state = $record['state'];
            if ('option' === $action['type']) {
                if (empty($state['exists'])) { delete_option((string) $action['key']); }
                else { update_option((string) $action['key'], $state['value'], false); }
            } elseif ('post_meta' === $action['type']) {
                if (empty($state['exists'])) { delete_post_meta((int) $action['post_id'], (string) $action['key']); }
                else { update_post_meta((int) $action['post_id'], (string) $action['key'], $state['value']); }
            } elseif ('post_field' === $action['type']) {
                $result = wp_update_post(array('ID'=>(int) $action['post_id'],(string) $action['field']=>$state['value']), true);
                if (is_wp_error($result)) { return $result; }
            } elseif ('create_page' === $action['type']) {
                $restored_page = $this->restore_page_creation($action, $state);
                if (is_wp_error($restored_page)) { return $restored_page; }
            } elseif ('create_insight' === $action['type']) {
                $restored_insight = $this->restore_insight_creation($action, $state);
                if (is_wp_error($restored_insight)) { return $restored_insight; }
            }

            $restored = $this->read_state($action);
            if (is_wp_error($restored)) { return $restored; }
            if (! $this->states_equal($restored, $state)) {
                return new WP_Error('research_manager_rollback_failed', 'Rollback could not restore the previous stored state.');
            }
        }
        return true;
    }

    private function restore_page_creation(array $action, array $state): bool|WP_Error {
        if (empty($state['exists'])) {
            $owned = $this->find_post_by_creation_token((string) $action['creation_token'], 'page');
            if ($owned instanceof WP_Post) {
                $deleted = wp_delete_post((int) $owned->ID, true);
                if (! $deleted) {
                    return new WP_Error('research_manager_page_rollback_failed', 'The Manager could not delete the Page it created.');
                }
            } else {
                $occupant = get_page_by_path((string) $action['wp_slug'], OBJECT, 'page');
                if ($occupant instanceof WP_Post) {
                    return new WP_Error('research_manager_page_rollback_conflict', 'Rollback found a Page at the controlled slug without the Manager provenance token. It was not deleted.');
                }
            }
        }

        if (! empty($action['front_page']) && is_array($state['site_front'] ?? null)) {
            update_option('show_on_front', (string) ($state['site_front']['show_on_front'] ?? 'posts'));
            update_option('page_on_front', (int) ($state['site_front']['page_on_front'] ?? 0));
        }
        $this->refresh_theme_routes();
        return true;
    }

    private function restore_insight_creation(array $action, array $state): bool|WP_Error {
        if (empty($state['exists'])) {
            $owned = $this->find_post_by_creation_token((string) $action['creation_token'], 'post');
            if ($owned instanceof WP_Post) {
                $deleted = wp_delete_post((int) $owned->ID, true);
                if (! $deleted) {
                    return new WP_Error('research_manager_insight_rollback_failed', 'The Manager could not delete the Insight it created.');
                }
            } else {
                $occupant = $this->find_post_by_slug((string) $action['slug'], 'post');
                if ($occupant instanceof WP_Post) {
                    return new WP_Error('research_manager_insight_rollback_conflict', 'Rollback found an Insight slug occupied without this Manager provenance token. It was not deleted.');
                }
            }
        }
        $this->refresh_theme_routes();
        return true;
    }

    private function desired_value(array $action): mixed {
        $type = (string) ($action['type'] ?? '');
        if ('create_page' === $type) {
            return array(
                'post_type'=>'page',
                'status'=>'publish',
                'wp_slug'=>(string) $action['wp_slug'],
                'title'=>(string) $action['title'],
                'role'=>(string) $action['role'],
                'model'=>(string) $action['model'],
                'creation_token'=>(string) $action['creation_token'],
                'front_page'=>! empty($action['front_page']),
            );
        }
        if ('create_insight' === $type) {
            return array(
                'post_type'=>'post',
                'status'=>(string) $action['status'],
                'slug'=>(string) $action['slug'],
                'title'=>(string) $action['title'],
                'excerpt'=>(string) $action['excerpt'],
                'content'=>(string) $action['content'],
                'language'=>(string) $action['language'],
                'insight_type'=>(string) $action['insight_type'],
                'creation_token'=>(string) $action['creation_token'],
            );
        }
        return $action['value'] ?? null;
    }

    private function state_matches_action(array $state, array $action): bool {
        $type = (string) ($action['type'] ?? '');
        if ('create_page' !== $type && 'create_insight' !== $type) {
            return ! empty($state['exists']) && $this->values_equal($state['value'] ?? null, $action['value'] ?? null);
        }
        if (empty($state['exists']) || ! is_array($state['value'] ?? null)) { return false; }
        $stored = $state['value'];
        $desired = $this->desired_value($action);
        $keys = 'create_page' === $type
            ? array('post_type','status','wp_slug','title','role','model','creation_token','front_page')
            : array('post_type','status','slug','title','excerpt','content','language','insight_type','creation_token');
        foreach ($keys as $key) {
            if (! array_key_exists($key, $stored) || ! array_key_exists($key, $desired) || $stored[$key] !== $desired[$key]) {
                return false;
            }
        }
        return '' !== (string) ($stored['url'] ?? '');
    }

    private function states_equal(array $left, array $right): bool {
        if (! empty($left['exists']) !== ! empty($right['exists'])) { return false; }
        if (empty($left['exists'])) {
            if (array_key_exists('site_front', $right)) {
                return $this->values_equal($left['site_front'] ?? null, $right['site_front'] ?? null);
            }
            return true;
        }
        if (! $this->values_equal($left['value'] ?? null, $right['value'] ?? null)) { return false; }
        if (array_key_exists('site_front', $right)) {
            return $this->values_equal($left['site_front'] ?? null, $right['site_front'] ?? null);
        }
        return true;
    }

    private function find_page_for_creation_action(array $action): ?WP_Post {
        $owned = $this->find_post_by_creation_token((string) $action['creation_token'], 'page');
        if ($owned instanceof WP_Post) { return $owned; }
        $page = get_page_by_path((string) $action['wp_slug'], OBJECT, 'page');
        return $page instanceof WP_Post ? $page : null;
    }

    private function find_post_by_creation_token(string $token, string $post_type): ?WP_Post {
        if ('' === $token) { return null; }
        $posts = get_posts(array(
            'post_type'=>$post_type,
            'post_status'=>'any',
            'posts_per_page'=>2,
            'orderby'=>'ID',
            'order'=>'ASC',
            'meta_key'=>'_eduardo_research_manager_creation_token',
            'meta_value'=>$token,
            'suppress_filters'=>true,
        ));
        return isset($posts[0]) && $posts[0] instanceof WP_Post ? $posts[0] : null;
    }

    private function find_post_by_slug(string $slug, string $post_type): ?WP_Post {
        if ('' === $slug) { return null; }
        $posts = get_posts(array(
            'post_type'=>$post_type,
            'post_status'=>'any',
            'name'=>$slug,
            'posts_per_page'=>1,
            'suppress_filters'=>true,
        ));
        return isset($posts[0]) && $posts[0] instanceof WP_Post ? $posts[0] : null;
    }

    private function refresh_theme_routes(): void {
        if (function_exists('eduardo_research_multilingual_rewrites')) {
            eduardo_research_multilingual_rewrites();
        }
        if (function_exists('eduardo_research_content_language_rewrites')) {
            eduardo_research_content_language_rewrites();
        }
        flush_rewrite_rules(false);
    }

    private function values_equal(mixed $left, mixed $right): bool {
        return maybe_serialize($left) === maybe_serialize($right);
    }
}
