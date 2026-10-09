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
                'after'=>$action['value'],
                'changed'=>! $this->values_equal($before['value'] ?? null, $action['value']) || empty($before['exists']),
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
            $matches = ! empty($state['exists']) && $this->values_equal($state['value'] ?? null, $action['value']);
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
            $exists = metadata_exists('post', (int) $action['post_id'], (string) $action['key']);
            return array(
                'exists'=>$exists,
                'value'=>$exists ? get_post_meta((int) $action['post_id'], (string) $action['key'], true) : null,
            );
        }
        if ('post_field' === $action['type']) {
            $post = get_post((int) $action['post_id']);
            if (! $post instanceof WP_Post) {
                return new WP_Error('research_manager_resource_missing', 'WordPress resource no longer exists.');
            }
            $field = (string) $action['field'];
            return array('exists'=>property_exists($post, $field),'value'=>$post->{$field} ?? null);
        }
        return new WP_Error('research_manager_action_not_allowed', 'Unsupported mutation action type.');
    }

    private function write_action(array $action): true|WP_Error {
        if ('option' === $action['type']) {
            update_option((string) $action['key'], $action['value'], false);
        } elseif ('post_meta' === $action['type']) {
            update_post_meta((int) $action['post_id'], (string) $action['key'], $action['value']);
        } elseif ('post_field' === $action['type']) {
            $result = wp_update_post(array('ID'=>(int) $action['post_id'],(string) $action['field']=>$action['value']), true);
            if (is_wp_error($result)) { return $result; }
        } else {
            return new WP_Error('research_manager_action_not_allowed', 'Unsupported mutation action type.');
        }

        $state = $this->read_state($action);
        if (is_wp_error($state)) { return $state; }
        if (empty($state['exists']) || ! $this->values_equal($state['value'] ?? null, $action['value'])) {
            return new WP_Error('research_manager_write_failed', 'WordPress did not persist the planned value.');
        }
        return true;
    }

    private function restore_records(array $records): true|WP_Error {
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
            }
        }
        return true;
    }

    private function values_equal(mixed $left, mixed $right): bool {
        return maybe_serialize($left) === maybe_serialize($right);
    }
}
