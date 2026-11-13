<?php
/** Safe mutation executor for Site Architect. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

require_once EDUARDO_RESEARCH_MANAGER_DIR . 'includes/class-research-manager-research-execution-spec.php';
require_once EDUARDO_RESEARCH_MANAGER_DIR . 'includes/class-research-manager-research-object-execution.php';
require_once EDUARDO_RESEARCH_MANAGER_DIR . 'includes/class-research-manager-research-insight-execution.php';

final class Eduardo_Research_Manager_Executor {
    private Eduardo_Research_Manager_Snapshots $snapshots;
    private Site_Architect_Mutation_Policy $mutation_policy;
    private Eduardo_Research_Manager_Contract $theme_contract;
    private Eduardo_Research_Manager_Research_Object_Execution $research_objects;
    private Eduardo_Research_Manager_Research_Insight_Execution $research_insights;

    public function __construct(?Eduardo_Research_Manager_Snapshots $snapshots = null, ?Site_Architect_Mutation_Policy $mutation_policy = null, ?Eduardo_Research_Manager_Contract $theme_contract = null) {
        $this->snapshots = $snapshots ?: new Eduardo_Research_Manager_Snapshots();
        $this->mutation_policy = $mutation_policy ?? new Eduardo_Research_Manager_Research_Mutation_Policy();
        $this->theme_contract = $theme_contract ?? new Eduardo_Research_Manager_Contract();
        $this->research_objects = new Eduardo_Research_Manager_Research_Object_Execution($this->mutation_policy, $this->theme_contract);
        $this->research_insights = new Eduardo_Research_Manager_Research_Insight_Execution($this->mutation_policy, $this->theme_contract);
    }

    public function preview(array $plan): array|WP_Error {
        $valid = Eduardo_Research_Manager_Plan::validate($plan, $this->mutation_policy, $this->theme_contract);
        if (is_wp_error($valid)) { return $valid; }
        $rows = array();
        foreach ($plan['actions'] as $action) {
            $before = $this->read_state($action);
            if (is_wp_error($before)) { return $before; }
            $rows[] = array(
                'action'=>$action,'before'=>$before,'after'=>$this->desired_value($action),
                'changed'=>! $this->state_matches_action($before, $action),
                'risk'=>Eduardo_Research_Manager_Plan::action_risk($action, $this->mutation_policy, $this->theme_contract),
            );
        }
        $gate = Eduardo_Research_Manager_Plan::apply_gate($plan, $this->mutation_policy, $this->theme_contract);
        return array(
            'plan_id'=>$plan['id'],'intent'=>$plan['intent'],'risk'=>$plan['risk'],'checksum'=>$plan['checksum'],
            'apply_allowed'=>! is_wp_error($gate),'apply_blocker'=>is_wp_error($gate) ? $gate->get_error_message() : '',
            'actions'=>$rows,
        );
    }

    public function apply(array $plan): array|WP_Error {
        if (! current_user_can('manage_options')) { return new WP_Error('research_manager_forbidden', 'You are not allowed to apply Research Manager mutations.'); }
        $gate = Eduardo_Research_Manager_Plan::apply_gate($plan, $this->mutation_policy, $this->theme_contract);
        if (is_wp_error($gate)) { return $gate; }
        $preview = $this->preview($plan);
        if (is_wp_error($preview)) { return $preview; }

        $before = array(); $changed = false;
        foreach ($preview['actions'] as $row) {
            $before[] = array('action'=>$row['action'],'state'=>$row['before']);
            $changed = $changed || ! empty($row['changed']);
        }
        if (! $changed) {
            $verification = $this->verify($plan);
            return is_wp_error($verification) ? $verification : array('status'=>'noop','plan_id'=>$plan['id'],'snapshot_id'=>'','verification'=>$verification);
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
            return is_wp_error($verification) ? $verification : new WP_Error('research_manager_verification_failed', 'Stored state did not match the mutation plan. The Manager rolled the change back.');
        }
        return array('status'=>'applied','plan_id'=>$plan['id'],'snapshot_id'=>$snapshot_id,'verification'=>$verification);
    }

    public function verify(array $plan): array|WP_Error {
        $valid = Eduardo_Research_Manager_Plan::validate($plan, $this->mutation_policy, $this->theme_contract);
        if (is_wp_error($valid)) { return $valid; }
        $results = array(); $verified = true;
        foreach ($plan['actions'] as $action) {
            $state = $this->read_state($action);
            if (is_wp_error($state)) { return $state; }
            $matches = $this->state_matches_action($state, $action);
            $verified = $verified && $matches;
            $results[] = array('action'=>$action,'matches'=>$matches,'stored'=>$state['value'] ?? null);
        }
        return array('verified'=>$verified,'plan_id'=>$plan['id'],'actions'=>$results,'verified_at'=>gmdate(DATE_W3C));
    }

    public function rollback(string $snapshot_id): array|WP_Error {
        if (! current_user_can('manage_options')) { return new WP_Error('research_manager_forbidden', 'You are not allowed to rollback Research Manager mutations.'); }
        $snapshot = $this->snapshots->get($snapshot_id);
        if (! $snapshot) { return new WP_Error('research_manager_snapshot_missing', 'Rollback snapshot was not found.'); }
        if ('rolled-back' === (string) ($snapshot['status'] ?? '')) { return new WP_Error('research_manager_snapshot_used', 'This snapshot has already been rolled back.'); }
        $records = is_array($snapshot['before'] ?? null) ? $snapshot['before'] : array();
        $restored = $this->restore_records($records);
        if (is_wp_error($restored)) { return $restored; }
        $this->snapshots->mark_rolled_back($snapshot_id);
        return array('status'=>'rolled-back','snapshot_id'=>$snapshot_id,'plan_id'=>(string) ($snapshot['plan_id'] ?? ''),'restored'=>count($records),'rolled_back_at'=>gmdate(DATE_W3C));
    }

    private function read_state(array $action): array|WP_Error {
        $type = (string) ($action['type'] ?? '');
        if ($this->research_creation_blocked($type)) { return new WP_Error('research_manager_action_not_allowed', 'Research creation is outside the active Theme execution contract.'); }
        if (in_array($type, array('post_meta','post_field'), true)) {
            $resource_gate = Eduardo_Research_Manager_Plan::resource_mutation_gate($action, $this->mutation_policy, $this->theme_contract);
            if (is_wp_error($resource_gate)) { return $resource_gate; }
        }
        if ('option' === $type) {
            $marker = new stdClass(); $value = get_option($action['key'], $marker);
            return array('exists'=>$value !== $marker,'value'=>$value === $marker ? null : $value);
        }
        if ('post_meta' === $type) { return $this->read_post_meta_storage((int) $action['post_id'], (string) $action['key']); }
        if ('post_field' === $type) {
            $post = get_post((int) $action['post_id']);
            if (! $post instanceof WP_Post) { return new WP_Error('research_manager_resource_missing', 'WordPress resource no longer exists.'); }
            $field = (string) $action['field'];
            return array('exists'=>property_exists($post, $field),'value'=>$post->{$field} ?? null);
        }
        if ('create_page' === $type) { return $this->read_page_creation_state($action); }
        if ('create_insight' === $type) { return $this->research_insights->read_state($action); }
        if ($this->research_object_spec($type)) { return $this->research_objects->read_state($action); }
        return new WP_Error('research_manager_action_not_allowed', 'Unsupported mutation action type.');
    }

    private function read_post_meta_storage(int $post_id, string $key): array {
        global $wpdb;
        $raw = $wpdb->get_var($wpdb->prepare("SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id ASC LIMIT 1", $post_id, $key));
        return array('exists'=>null !== $raw,'value'=>null === $raw ? null : maybe_unserialize($raw));
    }

    private function read_page_creation_state(array $action): array {
        $page = $this->find_post_by_creation_token((string) $action['creation_token'], 'page');
        if (! $page instanceof WP_Post) { $page = get_page_by_path((string) $action['wp_slug'], OBJECT, 'page'); }
        $site_front = array('show_on_front'=>(string) get_option('show_on_front', 'posts'),'page_on_front'=>(int) get_option('page_on_front', 0));
        if (! $page instanceof WP_Post) { return array('exists'=>false,'value'=>null,'site_front'=>$site_front); }
        return array('exists'=>true,'value'=>array(
            'post_id'=>(int) $page->ID,'post_type'=>(string) $page->post_type,'status'=>(string) $page->post_status,
            'wp_slug'=>(string) $page->post_name,'title'=>(string) $page->post_title,
            'role'=>$this->private_meta_string((int) $page->ID, '_eduardo_research_role'),
            'model'=>$this->private_meta_string((int) $page->ID, '_eduardo_research_model'),
            'creation_token'=>$this->private_meta_string((int) $page->ID, '_eduardo_research_manager_creation_token'),
            'front_page'=>'page' === $site_front['show_on_front'] && (int) $page->ID === $site_front['page_on_front'],
            'url'=>(string) get_permalink($page),
        ),'site_front'=>$site_front);
    }

    private function write_action(array $action): bool|WP_Error {
        $type = (string) ($action['type'] ?? '');
        if ($this->research_creation_blocked($type)) { return new WP_Error('research_manager_action_not_allowed', 'Research creation is outside the active Theme execution contract.'); }
        if (in_array($type, array('post_meta','post_field'), true)) {
            $resource_gate = Eduardo_Research_Manager_Plan::resource_mutation_gate($action, $this->mutation_policy, $this->theme_contract);
            if (is_wp_error($resource_gate)) { return $resource_gate; }
        }
        if ('option' === $type) { update_option((string) $action['key'], $action['value'], false); }
        elseif ('post_meta' === $type) { update_post_meta((int) $action['post_id'], (string) $action['key'], $action['value']); }
        elseif ('post_field' === $type) {
            $result = wp_update_post(array('ID'=>(int) $action['post_id'],(string) $action['field']=>$action['value']), true);
            if (is_wp_error($result)) { return $result; }
        }
        elseif ('create_page' === $type) { $result = $this->create_page($action); if (is_wp_error($result)) { return $result; } }
        elseif ('create_insight' === $type) { $result = $this->research_insights->create($action); if (is_wp_error($result)) { return $result; } }
        elseif ($this->research_object_spec($type)) { $result = $this->research_objects->create($action); if (is_wp_error($result)) { return $result; } }
        else { return new WP_Error('research_manager_action_not_allowed', 'Unsupported mutation action type.'); }

        $state = $this->read_state($action);
        if (is_wp_error($state)) { return $state; }
        return $this->state_matches_action($state, $action) ? true : new WP_Error('research_manager_write_failed', 'WordPress did not persist the planned value.');
    }

    private function create_page(array $action): bool|WP_Error {
        if (get_page_by_path((string) $action['wp_slug'], OBJECT, 'page') instanceof WP_Post) { return new WP_Error('research_manager_page_creation_conflict', 'A WordPress Page already occupies the Theme-controlled slug. Nothing was overwritten.'); }
        if ($this->find_post_by_creation_token((string) $action['creation_token'], 'page') instanceof WP_Post) { return new WP_Error('research_manager_creation_token_collision', 'The Page creation provenance token is already in use.'); }
        $id = wp_insert_post(array(
            'post_type'=>'page','post_status'=>'publish','post_name'=>$action['wp_slug'],'post_title'=>$action['title'],'post_content'=>'','post_excerpt'=>'',
            'meta_input'=>array('_eduardo_research_role'=>$action['role'],'_eduardo_research_model'=>$action['model'],'_eduardo_research_manager_creation_token'=>$action['creation_token']),
        ), true);
        if (is_wp_error($id)) { return $id; }
        if (! empty($action['front_page'])) { update_option('show_on_front', 'page'); update_option('page_on_front', (int) $id); }
        clean_post_cache((int) $id); $this->refresh_theme_routes(); return true;
    }

    private function restore_records(array $records): bool|WP_Error {
        foreach (array_reverse($records) as $record) {
            if (! is_array($record) || ! is_array($record['action'] ?? null) || ! is_array($record['state'] ?? null)) { continue; }
            $action = $record['action']; $state = $record['state']; $type = (string) ($action['type'] ?? '');
            if ($this->research_creation_blocked($type)) { return new WP_Error('research_manager_action_not_allowed', 'Research rollback is outside the active Theme execution contract.'); }
            if (in_array($type, array('post_meta','post_field'), true)) {
                $resource_gate = Eduardo_Research_Manager_Plan::resource_mutation_gate($action, $this->mutation_policy, $this->theme_contract);
                if (is_wp_error($resource_gate)) { return $resource_gate; }
            }
            if ('option' === $type) { empty($state['exists']) ? delete_option((string) $action['key']) : update_option((string) $action['key'], $state['value'], false); }
            elseif ('post_meta' === $type) { empty($state['exists']) ? delete_post_meta((int) $action['post_id'], (string) $action['key']) : update_post_meta((int) $action['post_id'], (string) $action['key'], $state['value']); }
            elseif ('post_field' === $type) {
                $result = wp_update_post(array('ID'=>(int) $action['post_id'],(string) $action['field']=>$state['value']), true);
                if (is_wp_error($result)) { return $result; }
            }
            elseif ('create_page' === $type) {
                $result = $this->restore_created_resource($action, $state, 'page', 'research_manager_page_rollback_conflict');
                if (is_wp_error($result)) { return $result; }
                if (! empty($action['front_page']) && is_array($state['site_front'] ?? null)) {
                    update_option('show_on_front', (string) ($state['site_front']['show_on_front'] ?? 'posts'));
                    update_option('page_on_front', (int) ($state['site_front']['page_on_front'] ?? 0));
                }
                $this->refresh_theme_routes();
            }
            elseif ('create_insight' === $type) {
                $result = $this->research_insights->restore($action, $state);
                if (is_wp_error($result)) { return $result; }
            }
            elseif ($this->research_object_spec($type)) {
                $result = $this->research_objects->restore($action, $state);
                if (is_wp_error($result)) { return $result; }
            }

            $restored = $this->read_state($action);
            if (is_wp_error($restored)) { return $restored; }
            if (! $this->states_equal($restored, $state)) { return new WP_Error('research_manager_rollback_failed', 'Rollback could not restore the previous stored state.'); }
        }
        return true;
    }

    private function restore_created_resource(array $action, array $state, string $post_type, string $conflict_code): bool|WP_Error {
        if (! empty($state['exists'])) { return true; }
        $token = (string) ($action['creation_token'] ?? '');
        $owned = $this->find_post_by_creation_token($token, $post_type);
        if ($owned instanceof WP_Post) {
            return wp_delete_post((int) $owned->ID, true) ? true : new WP_Error('research_manager_resource_rollback_failed', 'The Manager could not delete the resource it created.');
        }
        $slug = (string) ($action['wp_slug'] ?? $action['slug'] ?? '');
        $occupant = 'page' === $post_type ? get_page_by_path($slug, OBJECT, 'page') : $this->find_post_by_slug($slug, $post_type);
        if ($occupant instanceof WP_Post) { return new WP_Error($conflict_code, 'Rollback found the planned slug occupied without this Manager provenance token. It was not deleted.'); }
        return true;
    }

    private function desired_value(array $action): mixed {
        $type = (string) ($action['type'] ?? '');
        if ('create_page' === $type) {
            return array('post_type'=>'page','status'=>'publish','wp_slug'=>$action['wp_slug'],'title'=>$action['title'],'role'=>$action['role'],'model'=>$action['model'],'creation_token'=>$action['creation_token'],'front_page'=>! empty($action['front_page']));
        }
        if ('create_insight' === $type) { return $this->research_insights->desired_value($action); }
        if ($this->research_object_spec($type)) { return $this->research_objects->desired_value($action); }
        return $action['value'] ?? null;
    }

    private function state_matches_action(array $state, array $action): bool {
        $type = (string) ($action['type'] ?? '');
        if (! in_array($type, array('create_page','create_insight'), true) && ! $this->research_object_spec($type)) {
            return ! empty($state['exists']) && $this->values_equal($state['value'] ?? null, $action['value'] ?? null);
        }
        if (empty($state['exists']) || ! is_array($state['value'] ?? null)) { return false; }
        $stored = $state['value']; $desired = $this->desired_value($action);
        if ('create_page' === $type) { $keys = array('post_type','status','wp_slug','title','role','model','creation_token','front_page'); }
        elseif ('create_insight' === $type) { $keys = array('post_type','status','slug','title','excerpt','content','language','insight_type','creation_token'); }
        else {
            $spec = $this->research_object_spec($type);
            $keys = array_merge(array('post_type','status','slug','title','excerpt','content','language'), array_keys((array) $spec['meta']), array('creation_token'));
        }
        foreach ($keys as $key) {
            if (! array_key_exists($key, $stored) || ! array_key_exists($key, $desired) || ! $this->values_equal($stored[$key], $desired[$key])) { return false; }
        }
        return '' !== (string) ($stored['url'] ?? '');
    }

    /** Actual Research policy and Theme identity must both match. */
    private function research_execution_allowed(): bool {
        return $this->mutation_policy instanceof Eduardo_Research_Manager_Research_Mutation_Policy
            && 'research' === (string) ($this->theme_contract->preset()['id'] ?? '');
    }

    private function research_creation_blocked(string $action_type): bool {
        return in_array($action_type, Eduardo_Research_Manager_Research_Execution_Spec::creation_actions(), true)
            && (! $this->research_execution_allowed()
                || ! in_array($action_type, $this->mutation_policy->allowed_action_types(), true));
    }

    /** Compatibility delegate; Research post types are now owned by the vertical. */
    private function research_object_spec(string $action_type): array {
        return $this->research_objects->spec($action_type);
    }

    private function states_equal(array $left, array $right): bool {
        if (! empty($left['exists']) !== ! empty($right['exists'])) { return false; }
        if (empty($left['exists'])) { return array_key_exists('site_front', $right) ? $this->values_equal($left['site_front'] ?? null, $right['site_front'] ?? null) : true; }
        if (! $this->values_equal($left['value'] ?? null, $right['value'] ?? null)) { return false; }
        return array_key_exists('site_front', $right) ? $this->values_equal($left['site_front'] ?? null, $right['site_front'] ?? null) : true;
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
    private function values_equal(mixed $left, mixed $right): bool { return maybe_serialize($left) === maybe_serialize($right); }
}
