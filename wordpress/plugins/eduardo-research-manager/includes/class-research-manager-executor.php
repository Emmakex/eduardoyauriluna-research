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
                'action'=>$action,'before'=>$before,'after'=>$this->desired_value($action),
                'changed'=>! $this->state_matches_action($before, $action),
                'risk'=>Eduardo_Research_Manager_Plan::action_risk($action),
            );
        }
        $gate = Eduardo_Research_Manager_Plan::apply_gate($plan);
        return array(
            'plan_id'=>$plan['id'],'intent'=>$plan['intent'],'risk'=>$plan['risk'],'checksum'=>$plan['checksum'],
            'apply_allowed'=>! is_wp_error($gate),'apply_blocker'=>is_wp_error($gate) ? $gate->get_error_message() : '',
            'actions'=>$rows,
        );
    }

    public function apply(array $plan): array|WP_Error {
        if (! current_user_can('manage_options')) { return new WP_Error('research_manager_forbidden', 'You are not allowed to apply Research Manager mutations.'); }
        $gate = Eduardo_Research_Manager_Plan::apply_gate($plan);
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
        $valid = Eduardo_Research_Manager_Plan::validate($plan);
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
        if ('create_insight' === $type) { return $this->read_insight_creation_state($action); }
        if ($this->research_object_spec($type)) { return $this->read_research_object_creation_state($action); }
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

    private function read_insight_creation_state(array $action): array {
        $post = $this->find_post_by_creation_token((string) $action['creation_token'], 'post');
        if (! $post instanceof WP_Post) { $post = $this->find_post_by_slug((string) $action['slug'], 'post'); }
        if (! $post instanceof WP_Post) { return array('exists'=>false,'value'=>null); }
        return array('exists'=>true,'value'=>array(
            'post_id'=>(int) $post->ID,'post_type'=>(string) $post->post_type,'status'=>(string) $post->post_status,
            'slug'=>(string) $post->post_name,'title'=>(string) $post->post_title,'excerpt'=>(string) $post->post_excerpt,
            'content'=>(string) $post->post_content,'language'=>$this->private_meta_string((int) $post->ID, '_research_language', 'en'),
            'insight_type'=>$this->private_meta_string((int) $post->ID, '_research_insight_type', 'research_note'),
            'creation_token'=>$this->private_meta_string((int) $post->ID, '_eduardo_research_manager_creation_token'),
            'url'=>(string) get_permalink($post),
        ));
    }

    private function read_research_object_creation_state(array $action): array {
        $spec = $this->research_object_spec((string) ($action['type'] ?? ''));
        if (! $spec) { return array('exists'=>false,'value'=>null); }
        $post_type = (string) $spec['post_type'];
        $post = $this->find_post_by_creation_token((string) $action['creation_token'], $post_type);
        if (! $post instanceof WP_Post) { $post = $this->find_post_by_slug((string) $action['slug'], $post_type); }
        if (! $post instanceof WP_Post) { return array('exists'=>false,'value'=>null); }
        $value = array(
            'post_id'=>(int) $post->ID,'post_type'=>$post_type,'status'=>(string) $post->post_status,
            'slug'=>(string) $post->post_name,'title'=>(string) $post->post_title,'excerpt'=>(string) $post->post_excerpt,
            'content'=>(string) $post->post_content,'language'=>$this->private_meta_string((int) $post->ID, '_research_language', 'en'),
        );
        foreach ((array) $spec['meta'] as $field => $meta_key) {
            $default = in_array($field, (array) ($spec['array_fields'] ?? array()), true) ? array() : ('0' === (string) ($spec['defaults'][$field] ?? '') ? '0' : '');
            $value[$field] = $this->private_meta_value((int) $post->ID, (string) $meta_key, $default);
        }
        $value['creation_token'] = $this->private_meta_string((int) $post->ID, '_eduardo_research_manager_creation_token');
        $value['url'] = (string) get_permalink($post);
        return array('exists'=>true,'value'=>$value);
    }

    private function write_action(array $action): bool|WP_Error {
        $type = (string) ($action['type'] ?? '');
        if ('option' === $type) { update_option((string) $action['key'], $action['value'], false); }
        elseif ('post_meta' === $type) { update_post_meta((int) $action['post_id'], (string) $action['key'], $action['value']); }
        elseif ('post_field' === $type) {
            $result = wp_update_post(array('ID'=>(int) $action['post_id'],(string) $action['field']=>$action['value']), true);
            if (is_wp_error($result)) { return $result; }
        }
        elseif ('create_page' === $type) { $result = $this->create_page($action); if (is_wp_error($result)) { return $result; } }
        elseif ('create_insight' === $type) { $result = $this->create_insight($action); if (is_wp_error($result)) { return $result; } }
        elseif ($this->research_object_spec($type)) { $result = $this->create_research_object($action); if (is_wp_error($result)) { return $result; } }
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

    private function create_insight(array $action): bool|WP_Error {
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

    private function create_research_object(array $action): bool|WP_Error {
        $spec = $this->research_object_spec((string) ($action['type'] ?? ''));
        if (! $spec) { return new WP_Error('research_manager_action_not_allowed', 'Unsupported Research object creation action.'); }
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

    private function restore_records(array $records): bool|WP_Error {
        foreach (array_reverse($records) as $record) {
            if (! is_array($record) || ! is_array($record['action'] ?? null) || ! is_array($record['state'] ?? null)) { continue; }
            $action = $record['action']; $state = $record['state']; $type = (string) ($action['type'] ?? '');
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
                $result = $this->restore_created_resource($action, $state, 'post', 'research_manager_insight_rollback_conflict');
                if (is_wp_error($result)) { return $result; }
                $this->refresh_theme_routes();
            }
            elseif ($spec = $this->research_object_spec($type)) {
                $result = $this->restore_created_resource($action, $state, (string) $spec['post_type'], (string) $spec['rollback_conflict']);
                if (is_wp_error($result)) { return $result; }
                $this->refresh_theme_routes();
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
        if ('create_insight' === $type) {
            return array('post_type'=>'post','status'=>$action['status'],'slug'=>$action['slug'],'title'=>$action['title'],'excerpt'=>$action['excerpt'],'content'=>$action['content'],'language'=>$action['language'],'insight_type'=>$action['insight_type'],'creation_token'=>$action['creation_token']);
        }
        $spec = $this->research_object_spec($type);
        if ($spec) {
            $value = array(
                'post_type'=>$spec['post_type'],'status'=>$action['status'],'slug'=>$action['slug'],'title'=>$action['title'],
                'excerpt'=>$action['excerpt'],'content'=>$action['content'],'language'=>$action['language'],
            );
            foreach ((array) $spec['meta'] as $field => $_) { $value[$field] = $action[$field]; }
            $value['creation_token'] = $action['creation_token'];
            return $value;
        }
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

    private function research_object_spec(string $action_type): array {
        $specs = array(
            'create_line'=>array(
                'post_type'=>'research_line','creation_conflict'=>'research_manager_line_creation_conflict','rollback_conflict'=>'research_manager_line_rollback_conflict',
                'meta'=>array(
                    'evidence_status'=>'_research_evidence_status','research_status'=>'_research_status','central_question'=>'_research_central_question',
                    'order'=>'_research_order','topics'=>'_research_topics','methods'=>'_research_methods',
                ),
                'array_fields'=>array('topics','methods'),'defaults'=>array('evidence_status'=>'unverified','research_status'=>'planned'),
            ),
            'create_output'=>array(
                'post_type'=>'research_output','creation_conflict'=>'research_manager_output_creation_conflict','rollback_conflict'=>'research_manager_output_rollback_conflict',
                'meta'=>array(
                    'output_type'=>'_research_output_type','output_type_verified'=>'_research_output_type_verified',
                    'review_status'=>'_research_review_status','review_status_verified'=>'_research_review_status_verified',
                    'publication_date'=>'_research_publication_date','venue'=>'_research_venue','doi'=>'_research_doi','doi_verified'=>'_research_doi_verified',
                    'authors'=>'_research_authors','line_ids'=>'_research_line_ids',
                ),
                'array_fields'=>array('authors','line_ids'),'defaults'=>array('output_type_verified'=>'0','review_status_verified'=>'0','doi_verified'=>'0'),
            ),
            'create_project'=>array(
                'post_type'=>'research_project','creation_conflict'=>'research_manager_project_creation_conflict','rollback_conflict'=>'research_manager_project_rollback_conflict',
                'meta'=>array(
                    'project_status'=>'_research_project_status','question'=>'_research_question','role'=>'_research_role',
                    'start_date'=>'_research_start_date','end_date'=>'_research_end_date','partner'=>'_research_partner','funding'=>'_research_funding',
                    'project_url'=>'_research_project_url','methods'=>'_research_methods','line_ids'=>'_research_line_ids',
                ),
                'array_fields'=>array('methods','line_ids'),'defaults'=>array(),
            ),
            'create_software'=>array(
                'post_type'=>'research_software','creation_conflict'=>'research_manager_software_creation_conflict','rollback_conflict'=>'research_manager_software_rollback_conflict',
                'meta'=>array(
                    'software_status'=>'_research_software_status','version'=>'_research_software_version','release_date'=>'_research_release_date',
                    'repository_url'=>'_research_repository_url','archive_url'=>'_research_archive_url','license'=>'_research_license',
                    'documentation_url'=>'_research_documentation_url','doi'=>'_research_doi','doi_verified'=>'_research_doi_verified',
                    'programming_languages'=>'_research_programming_languages','line_ids'=>'_research_line_ids',
                ),
                'array_fields'=>array('programming_languages','line_ids'),'defaults'=>array('doi_verified'=>'0'),
            ),
            'create_dataset'=>array(
                'post_type'=>'research_dataset','creation_conflict'=>'research_manager_dataset_creation_conflict','rollback_conflict'=>'research_manager_dataset_rollback_conflict',
                'meta'=>array(
                    'version'=>'_research_dataset_version','publication_date'=>'_research_publication_date','repository'=>'_research_repository',
                    'doi'=>'_research_doi','doi_verified'=>'_research_doi_verified','license'=>'_research_license','access_level'=>'_research_access_level',
                    'methodology'=>'_research_methodology','provenance'=>'_research_provenance','size'=>'_research_size',
                    'documentation_url'=>'_research_documentation_url','ethics_notes'=>'_research_ethics_notes','formats'=>'_research_formats','line_ids'=>'_research_line_ids',
                ),
                'array_fields'=>array('formats','line_ids'),'defaults'=>array('doi_verified'=>'0'),
            ),
        );
        return is_array($specs[$action_type] ?? null) ? $specs[$action_type] : array();
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
