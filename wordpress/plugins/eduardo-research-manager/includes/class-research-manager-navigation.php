<?php
/** Exact bounded lifecycle for Research Theme navigation state. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Navigation {
    private const PLANS_OPTION = 'eduardo_research_manager_navigation_plans';
    private const OPERATIONS_OPTION = 'eduardo_research_manager_navigation_operations';
    private const STATE_OPTION = 'eduardo_research_navigation';
    private const PLAN_TTL = 15 * MINUTE_IN_SECONDS;
    private const PLAN_LIMIT = 40;
    private const OPERATION_LIMIT = 80;

    private Eduardo_Research_Manager_Remote_Audit $audit;

    public function __construct(?Eduardo_Research_Manager_Remote_Audit $audit = null) {
        $this->audit = $audit ?: Eduardo_Research_Manager::remote_audit();
    }

    public function inspect(): array|WP_Error {
        $contract = $this->contract();
        if (is_wp_error($contract)) { return $contract; }
        return array('contract'=>$contract,'state'=>eduardo_research_navigation_state(),'revision'=>$this->revision_fingerprint(),'arbitrary_urls'=>false,'verified_at'=>gmdate(DATE_W3C));
    }

    public function create_plan(string $location, array $items, string $intent, array $actor): array|WP_Error {
        $contract = $this->contract();
        if (is_wp_error($contract)) { return $contract; }
        $location = sanitize_key($location);
        if (! isset($contract['locations'][$location])) { return $this->error('validation_failed', 'Navigation location is outside the active Research Theme contract.', 400); }
        $clean = $this->validate_items($items, $contract);
        if (is_wp_error($clean)) { return $clean; }
        if (count($clean) < max(1, (int) ($contract['locations'][$location]['min_items'] ?? 1))) { return $this->error('validation_failed', 'Navigation location must retain at least one registered Theme target.', 400); }
        $before = eduardo_research_navigation_state();
        $after = $before; $after[$location] = $clean; $after = eduardo_research_navigation_sanitize_state($after);
        if (($after[$location] ?? array()) !== $clean) { return $this->error('validation_failed', 'Requested navigation state does not satisfy the Theme contract.', 400); }
        if ($before === $after) { return $this->error('research_manager_remediation_not_needed', 'Requested navigation state is already active.', 409); }

        $now = time();
        $connection_id = sanitize_text_field((string) ($actor['connection_id'] ?? ''));
        $source_revision = $this->revision_fingerprint();
        $intent = sanitize_text_field($intent ?: 'Update bounded Research Theme navigation');
        $checksum = hash('sha256', (string) wp_json_encode(array('location'=>$location,'before'=>$before,'after'=>$after,'source_revision'=>$source_revision,'connection_id'=>$connection_id,'intent'=>$intent)));
        $plan_id = 'erm-nav-plan-' . substr($checksum, 0, 18) . '-' . substr(str_replace('-', '', wp_generate_uuid4()), 0, 8);
        $plan = array(
            'plan_id'=>$plan_id,'operation'=>'navigation-update','intent'=>$intent,'risk'=>'standard','confirmation_required'=>true,'confirmation_class'=>'navigation-explicit',
            'target'=>array('resource'=>'theme:navigation','location'=>$location),'requested'=>array('location'=>$location,'items'=>$clean),'before'=>$before,'after'=>$after,
            'preview'=>array('location'=>$location,'before'=>(array) ($before[$location] ?? array()),'after'=>$clean,'added'=>array_values(array_diff($clean, (array) ($before[$location] ?? array()))),'removed'=>array_values(array_diff((array) ($before[$location] ?? array()), $clean)),'reordered'=>$this->same_members_different_order((array) ($before[$location] ?? array()), $clean),'arbitrary_urls'=>false),
            'apply_allowed'=>true,'source_revision'=>$source_revision,'connection_id'=>$connection_id,'created_by'=>(int) ($actor['wordpress_user_id'] ?? 0),'created_request_id'=>(string) ($actor['request_id'] ?? ''),
            'created_at'=>gmdate(DATE_W3C, $now),'expires_at'=>gmdate(DATE_W3C, $now + self::PLAN_TTL),'expires_unix'=>$now + self::PLAN_TTL,'status'=>'planned','checksum'=>$checksum,
        );
        $this->put(self::PLANS_OPTION, $plan_id, $plan, self::PLAN_LIMIT);
        $this->audit('remote-navigation-plan-created', 'success', $actor, $plan_id, '', $intent);
        return $plan;
    }

    public function get_plan(string $plan_id): array|WP_Error {
        $plan = $this->find(self::PLANS_OPTION, $plan_id);
        if (! $plan) { return $this->error('operation_not_found', 'Navigation plan was not found.', 404); }
        if ('planned' === (string) ($plan['status'] ?? '') && time() > (int) ($plan['expires_unix'] ?? 0)) { $plan['status'] = 'expired'; $this->put(self::PLANS_OPTION, $plan_id, $plan, self::PLAN_LIMIT); }
        return $plan;
    }

    public function apply(string $plan_id, bool $confirmed, array $actor): array|WP_Error {
        if (! current_user_can('manage_options')) { return $this->error('capability_unavailable', 'The local WordPress execution identity cannot apply navigation operations.', 403); }
        $plan = $this->find(self::PLANS_OPTION, $plan_id);
        if (! $plan) { return $this->error('operation_not_found', 'Navigation plan was not found.', 404); }
        if ((string) ($plan['connection_id'] ?? '') !== (string) ($actor['connection_id'] ?? '')) { return $this->error('scope_denied', 'This navigation plan belongs to a different remote connection.', 403); }
        if ('planned' !== (string) ($plan['status'] ?? '')) {
            if ('applied' === (string) ($plan['status'] ?? '') && '' !== (string) ($plan['operation_id'] ?? '')) { return $this->get_operation((string) $plan['operation_id']); }
            return $this->error('validation_failed', 'Only a planned navigation operation can be applied.', 409);
        }
        if (time() > (int) ($plan['expires_unix'] ?? 0)) { return $this->error('plan_expired', 'Navigation plan has expired. Create a fresh Preview.', 409); }
        if (! $confirmed) { return $this->error('confirmation_required', 'Navigation Apply requires explicit confirmation.', 409, array('confirmation_class'=>'navigation-explicit')); }
        if (! hash_equals((string) ($plan['source_revision'] ?? ''), $this->revision_fingerprint())) { return $this->error('stale_revision', 'Navigation state changed after Preview. Create a fresh plan.', 409); }
        $after = eduardo_research_navigation_sanitize_state((array) ($plan['after'] ?? array()));
        update_option(self::STATE_OPTION, $after, false);
        $stored = eduardo_research_navigation_state();
        if ($stored !== $after) { update_option(self::STATE_OPTION, (array) ($plan['before'] ?? array()), false); return $this->error('research_manager_write_failed', 'WordPress did not persist the exact bounded navigation state.', 409); }
        $operation_id = 'erm-nav-op-' . str_replace('-', '', wp_generate_uuid4());
        $operation = array('operation_id'=>$operation_id,'plan_id'=>$plan_id,'operation'=>'navigation-update','target'=>(array) $plan['target'],'before'=>(array) $plan['before'],'after'=>$after,'source_revision'=>(string) $plan['source_revision'],'post_apply_revision'=>$this->revision_fingerprint(),'connection_id'=>(string) ($actor['connection_id'] ?? ''),'wordpress_user_id'=>(int) ($actor['wordpress_user_id'] ?? 0),'status'=>'applied','result'=>array('verified'=>true,'state'=>$stored),'created_at'=>gmdate(DATE_W3C),'applied_at'=>gmdate(DATE_W3C),'verified_at'=>'','rolled_back_at'=>'');
        $this->put(self::OPERATIONS_OPTION, $operation_id, $operation, self::OPERATION_LIMIT);
        $plan['status'] = 'applied'; $plan['operation_id'] = $operation_id; $plan['applied_at'] = gmdate(DATE_W3C); $this->put(self::PLANS_OPTION, $plan_id, $plan, self::PLAN_LIMIT);
        $this->audit('remote-navigation-operation-applied', 'success', $actor, $plan_id, $operation_id, (string) $plan['intent']);
        return $operation;
    }

    public function get_operation(string $operation_id): array|WP_Error {
        $operation = $this->find(self::OPERATIONS_OPTION, $operation_id);
        return $operation ?: $this->error('operation_not_found', 'Navigation operation was not found.', 404);
    }

    public function verify(string $operation_id, bool $include_rendered, array $actor): array|WP_Error {
        $operation = $this->find(self::OPERATIONS_OPTION, $operation_id);
        if (! $operation) { return $this->error('operation_not_found', 'Navigation operation was not found.', 404); }
        if ((string) ($operation['connection_id'] ?? '') !== (string) ($actor['connection_id'] ?? '')) { return $this->error('scope_denied', 'This navigation operation belongs to a different remote connection.', 403); }
        if (! in_array((string) ($operation['status'] ?? ''), array('applied','verified','verification-failed'), true)) { return $this->error('validation_failed', 'Only applied navigation operations can be verified.', 409); }
        $state = eduardo_research_navigation_state();
        $state_matches = $state === (array) ($operation['after'] ?? array());
        $revision_matches = hash_equals((string) ($operation['post_apply_revision'] ?? ''), $this->revision_fingerprint());
        $location = sanitize_key((string) ($operation['target']['location'] ?? ''));
        $rendered = array('requested'=>$include_rendered,'verified'=>null,'languages'=>array());
        if ($include_rendered) { $rendered = $this->verify_rendered($location, (array) ($state[$location] ?? array())); }
        $verified = $state_matches && $revision_matches && (! $include_rendered || ! empty($rendered['verified']));
        $operation['verification'] = array('verified'=>$verified,'state_matches'=>$state_matches,'revision_matches'=>$revision_matches,'rendered'=>$rendered,'verified_at'=>gmdate(DATE_W3C));
        $operation['status'] = $verified ? 'verified' : 'verification-failed'; $operation['verified_at'] = gmdate(DATE_W3C);
        $this->put(self::OPERATIONS_OPTION, $operation_id, $operation, self::OPERATION_LIMIT);
        $this->audit('remote-navigation-operation-verified', $verified ? 'success' : 'failed', $actor, (string) $operation['plan_id'], $operation_id, 'Verify bounded Theme navigation');
        return $operation;
    }

    public function rollback(string $operation_id, bool $confirmed, array $actor): array|WP_Error {
        if (! current_user_can('manage_options')) { return $this->error('capability_unavailable', 'The local WordPress execution identity cannot rollback navigation operations.', 403); }
        $operation = $this->find(self::OPERATIONS_OPTION, $operation_id);
        if (! $operation) { return $this->error('operation_not_found', 'Navigation operation was not found.', 404); }
        if ((string) ($operation['connection_id'] ?? '') !== (string) ($actor['connection_id'] ?? '')) { return $this->error('scope_denied', 'This navigation operation belongs to a different remote connection.', 403); }
        if (! $confirmed) { return $this->error('confirmation_required', 'Navigation rollback requires explicit confirmation.', 409); }
        if (! in_array((string) ($operation['status'] ?? ''), array('applied','verified','verification-failed'), true)) {
            if ('rolled-back' === (string) ($operation['status'] ?? '')) { return $operation; }
            return $this->error('rollback_unavailable', 'This navigation operation cannot be rolled back.', 409);
        }
        if (! hash_equals((string) ($operation['post_apply_revision'] ?? ''), $this->revision_fingerprint())) { return $this->error('stale_revision', 'Navigation changed after Apply. Rollback was not performed.', 409); }
        $before = eduardo_research_navigation_sanitize_state((array) ($operation['before'] ?? array()));
        update_option(self::STATE_OPTION, $before, false);
        if (eduardo_research_navigation_state() !== $before) { return $this->error('research_manager_write_failed', 'Navigation rollback did not restore the exact baseline.', 409); }
        $operation['status'] = 'rolled-back'; $operation['rolled_back_at'] = gmdate(DATE_W3C); $operation['rollback_result'] = array('verified'=>true,'state'=>$before,'revision'=>$this->revision_fingerprint());
        $this->put(self::OPERATIONS_OPTION, $operation_id, $operation, self::OPERATION_LIMIT);
        $this->audit('remote-navigation-operation-rolled-back', 'success', $actor, (string) $operation['plan_id'], $operation_id, 'Rollback bounded Theme navigation');
        return $operation;
    }

    public function revision_fingerprint(): string {
        $contract = function_exists('eduardo_research_navigation_contract') ? eduardo_research_navigation_contract() : array('version'=>0);
        $state = function_exists('eduardo_research_navigation_state') ? eduardo_research_navigation_state() : array();
        return hash('sha256', (string) wp_json_encode(array('contract_version'=>(int) ($contract['version'] ?? 0),'state'=>$state)));
    }

    private function contract(): array|WP_Error {
        if (! function_exists('eduardo_research_navigation_contract') || ! function_exists('eduardo_research_navigation_state') || ! function_exists('eduardo_research_navigation_sanitize_state')) { return $this->error('capability_unavailable', 'Compatible Research Theme navigation contract is required.', 409); }
        return eduardo_research_navigation_contract();
    }

    private function validate_items(array $items, array $contract): array|WP_Error {
        $allowed = array_fill_keys((array) ($contract['allowed_targets'] ?? array()), true);
        if (count($items) > count($allowed)) { return $this->error('validation_failed', 'Navigation contains more targets than the Theme contract allows.', 400); }
        $clean = array();
        foreach ($items as $item) {
            $key = sanitize_key((string) $item);
            if ('' === $key || ! isset($allowed[$key])) { return $this->error('validation_failed', 'Navigation may only target registered Research Theme pages.', 400); }
            if (in_array($key, $clean, true)) { return $this->error('validation_failed', 'Navigation targets must be unique.', 400); }
            $clean[] = $key;
        }
        return $clean;
    }

    private function verify_rendered(string $location, array $items): array {
        $languages = function_exists('eduardo_research_languages') ? array_keys((array) eduardo_research_languages()) : array('en','es');
        $results = array(); $all = true;
        foreach ($languages as $language) {
            $url = eduardo_research_page_url('home', (string) $language);
            $response = wp_remote_get($url, array('timeout'=>15,'redirection'=>3,'user-agent'=>'Research-Manager-Navigation-Verify/1.0'));
            if (is_wp_error($response)) { $results[$language] = array('verified'=>false,'url'=>$url,'error_code'=>$response->get_error_code(),'error'=>$response->get_error_message()); $all = false; continue; }
            $status = (int) wp_remote_retrieve_response_code($response); $segment = $this->navigation_segment((string) wp_remote_retrieve_body($response), $location);
            $missing = array(); $positions = array();
            foreach ($items as $key) {
                $needle = 'href="' . esc_url(eduardo_research_page_url((string) $key, (string) $language)) . '"';
                $pos = strpos($segment, $needle); if (false === $pos) { $missing[] = $key; } else { $positions[] = $pos; }
            }
            $ordered = count($positions) === count($items);
            for ($i = 1; $ordered && $i < count($positions); $i++) { if ($positions[$i] <= $positions[$i - 1]) { $ordered = false; } }
            $verified = $status >= 200 && $status < 400 && '' !== $segment && ! $missing && $ordered;
            $results[$language] = array('verified'=>$verified,'url'=>$url,'http_status'=>$status,'missing'=>$missing,'ordered'=>$ordered,'location'=>$location); $all = $all && $verified;
        }
        return array('requested'=>true,'verified'=>$all,'languages'=>$results,'verified_at'=>gmdate(DATE_W3C));
    }

    private function navigation_segment(string $body, string $location): string {
        $class = 'footer' === $location ? 'research-footer-nav' : 'research-nav';
        return preg_match('/<nav\b[^>]*class=["\'][^"\']*\b' . preg_quote($class, '/') . '\b[^"\']*["\'][^>]*>.*?<\/nav>/is', $body, $matches) ? (string) ($matches[0] ?? '') : '';
    }

    private function same_members_different_order(array $before, array $after): bool { $left = $before; $right = $after; sort($left); sort($right); return $left === $right && $before !== $after; }
    private function find(string $option, string $id): array { $rows = get_option($option, array()); return is_array($rows) && is_array($rows[$id] ?? null) ? $rows[$id] : array(); }
    private function put(string $option, string $id, array $value, int $limit): void { $rows = get_option($option, array()); $rows = is_array($rows) ? $rows : array(); $rows[$id] = $value; if (count($rows) > $limit) { $rows = array_slice($rows, -$limit, null, true); } update_option($option, $rows, false); }
    private function audit(string $event, string $outcome, array $actor, string $plan_id, string $operation_id, string $reason): void { $this->audit->record(array('request_id'=>(string) ($actor['request_id'] ?? ''),'connection_id'=>(string) ($actor['connection_id'] ?? ''),'wordpress_user_id'=>(int) ($actor['wordpress_user_id'] ?? 0),'event'=>$event,'outcome'=>$outcome,'scope'=>'pages.write','plan_id'=>$plan_id,'operation_id'=>$operation_id,'reason'=>$reason)); }
    private function error(string $code, string $message, int $status, array $data = array()): WP_Error { return new WP_Error($code, $message, array_merge(array('status'=>$status), $data)); }
}
