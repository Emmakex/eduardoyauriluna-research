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
            $expected = $this->expected_value($action);
            $rows[] = array(
                'action'=>$action,
                'before'=>$before,
                'after'=>$expected,
                'changed'=>! $this->state_matches_value($before, $expected),
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
            $expected = $this->expected_value($action);
            $matches = $this->state_matches_value($state, $expected);
            $verified = $verified && $matches;
            $results[] = array('action'=>$action,'matches'=>$matches,'stored'=>$state['value'] ?? null);
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
            return $this->read_created_page_state($action);
        }
        return new WP_Error('research_manager_action_not_allowed', 'Unsupported mutation action type.');
    }

    /**
     * Read the persisted private meta value without Theme presentation filters.
     * The Manager verifies storage; the Theme independently decides what is safe to expose publicly.
     */
    private function read_post_meta_storage(int $post_id, string $key): array {
        global $wpdb;
        $raw = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id ASC LIMIT 1",
            $post_id,
            $key
        ));
        return array('exists'=>null !== $raw,'value'=>null === $raw ? null : maybe_unserialize($raw));
    }

    private function read_created_page_state(array $action): array {
        $routing = array(
            'show_on_front'=>(string) get_option('show_on_front', 'posts'),
            'page_on_front'=>(int) get_option('page_on_front', 0),
        );
        $page = get_page_by_path((string) $action['wp_slug'], OBJECT, 'page');
        if (! $page instanceof WP_Post) {
            return array('exists'=>false,'value'=>null,'id'=>0,'routing'=>$routing);
        }
        return array(
            'exists'=>true,
            'id'=>(int) $page->ID,
            'routing'=>$routing,
            'value'=>array(
                'post_name'=>(string) $page->post_name,
                'post_status'=>(string) $page->post_status,
                'post_title'=>(string) $page->post_title,
                'role'=>(string) get_post_meta($page->ID, '_eduardo_research_role', true),
                'model'=>(string) get_post_meta($page->ID, '_eduardo_research_model', true),
                'creation_token'=>(string) get_post_meta($page->ID, '_eduardo_research_manager_creation_token', true),
                'front_page'=>'page' === $routing['show_on_front'] && (int) $page->ID === (int) $routing['page_on_front'],
            ),
        );
    }

    private function expected_value(array $action): mixed {
        if ('create_page' === $action['type']) {
            return array(
                'post_name'=>(string) $action['wp_slug'],
                'post_status'=>'publish',
                'post_title'=>(string) $action['title'],
                'role'=>(string) $action['role'],
                'model'=>(string) $action['model'],
                'creation_token'=>(string) $action['creation_token'],
                'front_page'=>! empty($action['front_page']),
            );
        }
        return $action['value'] ?? null;
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
        } else {
            return new WP_Error('research_manager_action_not_allowed', 'Unsupported mutation action type.');
        }

        $state = $this->read_state($action);
        if (is_wp_error($state)) { return $state; }
        if (! $this->state_matches_value($state, $this->expected_value($action))) {
            return new WP_Error('research_manager_write_failed', 'WordPress did not persist the planned value.');
        }
        return true;
    }

    private function create_page(array $action): int|WP_Error {
        $contract_valid = $this->validate_create_page_contract($action);
        if (is_wp_error($contract_valid)) { return $contract_valid; }
        $current = $this->read_created_page_state($action);
        if (! empty($current['exists'])) {
            return new WP_Error('research_manager_page_creation_conflict', 'A Page already occupies the planned Theme slug. Re-run diagnostics before applying this plan.');
        }

        $id = wp_insert_post(array(
            'post_type'=>'page',
            'post_status'=>'publish',
            'post_title'=>(string) $action['title'],
            'post_name'=>(string) $action['wp_slug'],
            'post_content'=>'',
            'post_excerpt'=>'',
        ), true);
        if (is_wp_error($id)) { return $id; }
        $id = (int) $id;

        update_post_meta($id, '_eduardo_research_manager_creation_token', (string) $action['creation_token']);
        update_post_meta($id, '_eduardo_research_role', (string) $action['role']);
        update_post_meta($id, '_eduardo_research_model', (string) $action['model']);
        if (! empty($action['front_page'])) {
            update_option('show_on_front', 'page');
            update_option('page_on_front', $id);
        }
        flush_rewrite_rules(false);
        return $id;
    }

    private function validate_create_page_contract(array $action): bool|WP_Error {
        if (! function_exists('eduardo_research_preset')) {
            return new WP_Error('research_manager_theme_contract_unavailable', 'A compatible Research Theme contract is required to create Theme-owned Pages.');
        }
        $preset = eduardo_research_preset();
        $definition = is_array($preset['pages'][$action['page_key']] ?? null) ? $preset['pages'][$action['page_key']] : array();
        if (! $definition) {
            return new WP_Error('research_manager_page_contract_missing', 'The requested Page is not defined by the active Research preset.');
        }
        $expected_slug = (string) ($definition['wp_slug'] ?? $definition['slug'] ?? $action['page_key']);
        $labels = is_array($definition['labels'] ?? null) ? $definition['labels'] : array();
        $expected_title = (string) ($labels['en'] ?? $definition['label'] ?? ucfirst((string) $action['page_key']));
        $expected_role = (string) ($definition['role'] ?? '');
        $expected_model = (string) ($definition['model'] ?? '');
        $expected_front = 'home' === (string) $action['page_key'];
        if (
            $expected_slug !== (string) $action['wp_slug']
            || $expected_title !== (string) $action['title']
            || $expected_role !== (string) $action['role']
            || $expected_model !== (string) $action['model']
            || $expected_front !== ! empty($action['front_page'])
        ) {
            return new WP_Error('research_manager_page_contract_changed', 'Page creation plan no longer matches the active Research Theme contract. Re-preview the resource.');
        }
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
                $restored_page = $this->restore_created_page($action, $state);
                if (is_wp_error($restored_page)) { return $restored_page; }
            }

            $restored = $this->read_state($action);
            if (is_wp_error($restored)) { return $restored; }
            if (! $this->states_equal($restored, $state)) {
                return new WP_Error('research_manager_rollback_failed', 'Rollback could not restore the previous stored state.');
            }
        }
        return true;
    }

    private function restore_created_page(array $action, array $state): bool|WP_Error {
        if (empty($state['exists'])) {
            $current = $this->read_created_page_state($action);
            if (! empty($current['exists'])) {
                $token = (string) ($current['value']['creation_token'] ?? '');
                if (! hash_equals((string) $action['creation_token'], $token)) {
                    return new WP_Error('research_manager_page_rollback_conflict', 'Rollback refused to delete a Page whose provenance token does not match this Manager plan.');
                }
                $deleted = wp_delete_post((int) $current['id'], true);
                if (! $deleted instanceof WP_Post) {
                    return new WP_Error('research_manager_page_rollback_failed', 'Manager-created Page could not be deleted during rollback.');
                }
            }
        }

        if (isset($state['routing']) && is_array($state['routing'])) {
            update_option('show_on_front', (string) ($state['routing']['show_on_front'] ?? 'posts'));
            update_option('page_on_front', (int) ($state['routing']['page_on_front'] ?? 0));
        }
        flush_rewrite_rules(false);
        return true;
    }

    private function state_matches_value(array $state, mixed $value): bool {
        return ! empty($state['exists']) && $this->values_equal($state['value'] ?? null, $value);
    }

    private function states_equal(array $left, array $right): bool {
        if (! empty($left['exists']) !== ! empty($right['exists'])) { return false; }
        if (! empty($left['exists']) && ! $this->values_equal($left['value'] ?? null, $right['value'] ?? null)) { return false; }
        if (array_key_exists('routing', $right) && ! $this->values_equal($left['routing'] ?? null, $right['routing'])) { return false; }
        return true;
    }

    private function values_equal(mixed $left, mixed $right): bool {
        return maybe_serialize($left) === maybe_serialize($right);
    }
}
