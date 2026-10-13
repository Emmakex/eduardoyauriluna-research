<?php
/** Enforce semantic least-privilege scopes after authenticated REST permission checks. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Remote_Semantic_Scope_Guard {
    private const PLANS_OPTION = 'eduardo_research_manager_remote_plans';
    private const OPERATIONS_OPTION = 'eduardo_research_manager_remote_operations';
    private const EVIDENCE_PLANS_OPTION = 'eduardo_research_manager_remote_evidence_plans';
    private const EVIDENCE_OPERATIONS_OPTION = 'eduardo_research_manager_remote_evidence_operations';

    public function register(): void {
        add_filter('rest_request_before_callbacks', array($this, 'enforce'), 10, 3);
        add_filter('rest_request_after_callbacks', array($this, 'augment_capabilities'), 20, 3);
    }

    /**
     * Runs after the route permission callback has authenticated the bearer token and
     * consumed the nonce, so semantic scopes are checked without re-authentication.
     */
    public function enforce(mixed $response, mixed $handler, WP_REST_Request $request): mixed {
        if (is_wp_error($response)) { return $response; }

        $route = $request->get_route();
        $prefix = '/' . Eduardo_Research_Manager_Remote_REST::NAMESPACE;
        if (! str_starts_with($route, $prefix)) { return $response; }

        $required = $this->required_scopes_for_request($request);
        if (! $required) { return $response; }

        $credentials = Eduardo_Research_Manager::remote_credentials();
        $state = $credentials->state();
        foreach ($required as $scope) {
            if ($credentials->has_scope($state, $scope)) { continue; }

            $error = new WP_Error(
                'scope_denied',
                sprintf('Remote Manager connection does not grant %s.', $scope),
                array('status'=>403)
            );
            Eduardo_Research_Manager::remote_audit()->record(array(
                'request_id'=>(string) $request->get_header('x-research-manager-request-id'),
                'connection_id'=>(string) ($state['connection_id'] ?? ''),
                'wordpress_user_id'=>(int) ($state['wordpress_user_id'] ?? 0),
                'method'=>$request->get_method(),
                'route'=>$route,
                'scope'=>$scope,
                'event'=>'remote-request-rejected',
                'outcome'=>'blocked',
                'error_code'=>'scope_denied',
            ));
            return $error;
        }

        return $response;
    }

    public function augment_capabilities(mixed $response, mixed $handler, WP_REST_Request $request): mixed {
        if ('/' . Eduardo_Research_Manager_Remote_REST::NAMESPACE . '/capabilities' !== $request->get_route()
            || is_wp_error($response)
            || ! $response instanceof WP_REST_Response) {
            return $response;
        }

        $payload = $response->get_data();
        if (! is_array($payload) || ! is_array($payload['data'] ?? null)) { return $response; }

        $data = $payload['data'];
        $transport = is_array($data['mutation_transport'] ?? null) ? $data['mutation_transport'] : array();
        $transport['semantic_scope_enforcement'] = true;
        $transport['operation_scope_contract'] = self::operation_scope_contract();
        $transport['scope_check_order'] = 'authenticate-once-then-semantic-scopes';
        $data['mutation_transport'] = $transport;

        if (is_array($data['insight_control']['translation_pairing'] ?? null)) {
            $data['insight_control']['translation_pairing']['required_write_scopes'] = array(
                'insights.write',
                'translations.write',
            );
        }

        if (is_array($data['research_evidence_control'] ?? null)) {
            $data['research_evidence_control']['identity_policy'] = array(
                'canonical_name_option'=>'eduardo_research_identity.name',
                'critical_identifier_group'=>'identifiers',
                'critical_write_scope'=>'identity.write',
                'identity_update_scope'=>'identity.write',
                'evidence_scope_still_required'=>true,
                'arbitrary_wordpress_profile_proxy'=>false,
            );
        }

        $payload['data'] = $data;
        $response->set_data($payload);
        return $response;
    }

    public static function operation_scope_contract(): array {
        return array(
            'greenfield-canonical'=>array('operations.apply'),
            'page-slots-update'=>array('operations.apply','pages.write'),
            'page-create'=>array('operations.apply','pages.write'),
            'page-remediate'=>array('operations.apply','pages.write'),
            'insight-create'=>array('operations.apply','insights.write'),
            'insight-update'=>array('operations.apply','insights.write'),
            'insight-pair'=>array('operations.apply','insights.write','translations.write'),
            'insight-unpair'=>array('operations.apply','insights.write','translations.write'),
        );
    }

    private function required_scopes_for_request(WP_REST_Request $request): array {
        if ('POST' !== strtoupper($request->get_method())) { return array(); }

        $route = $request->get_route();
        $prefix = '/' . Eduardo_Research_Manager_Remote_REST::NAMESPACE;

        if ($prefix . '/operations/plan' === $route) {
            $params = $request->get_json_params();
            if (! is_array($params)) { $params = $request->get_body_params(); }
            $operation = $this->normalise_operation((string) ($params['operation'] ?? ''));
            return $this->semantic_scopes_for_operation($operation);
        }

        if (preg_match('#^' . preg_quote($prefix, '#') . '/plans/([^/]+)/apply$#', $route, $matches)) {
            $operation = $this->stored_plan_operation(rawurldecode((string) $matches[1]));
            return '' === $operation ? array() : $this->semantic_scopes_for_operation($operation);
        }

        if (preg_match('#^' . preg_quote($prefix, '#') . '/operations/([^/]+)/rollback$#', $route, $matches)) {
            $operation = $this->stored_operation_type(rawurldecode((string) $matches[1]));
            return '' === $operation ? array() : $this->semantic_scopes_for_operation($operation);
        }

        if ($prefix . '/insights/pairing/plan' === $route
            || preg_match('#^' . preg_quote($prefix, '#') . '/insights/pairing/plans/[^/]+/apply$#', $route)
            || preg_match('#^' . preg_quote($prefix, '#') . '/insights/pairing/operations/[^/]+/rollback$#', $route)) {
            return array('insights.write','translations.write');
        }

        if ($prefix . '/research-evidence/plan' === $route) {
            $params = $request->get_json_params();
            if (! is_array($params)) { $params = $request->get_body_params(); }
            return $this->evidence_request_requires_identity_scope($params) ? array('identity.write') : array();
        }

        if (preg_match('#^' . preg_quote($prefix, '#') . '/research-evidence/plans/([^/]+)/apply$#', $route, $matches)) {
            return $this->stored_evidence_plan_requires_identity_scope(rawurldecode((string) $matches[1]))
                ? array('identity.write')
                : array();
        }

        if (preg_match('#^' . preg_quote($prefix, '#') . '/research-evidence/operations/([^/]+)/rollback$#', $route, $matches)) {
            return $this->stored_evidence_operation_requires_identity_scope(rawurldecode((string) $matches[1]))
                ? array('identity.write')
                : array();
        }

        return array();
    }

    /**
     * Read the stored operation type without using lifecycle getters that may expire
     * or otherwise transition plan state. Scope checks must be observational only.
     */
    private function stored_plan_operation(string $plan_id): string {
        $plans = get_option(self::PLANS_OPTION, array());
        if (! is_array($plans) || ! is_array($plans[$plan_id] ?? null)) { return ''; }
        return $this->normalise_operation((string) ($plans[$plan_id]['operation'] ?? ''));
    }

    /** Scope checks must not mutate operation lifecycle state. */
    private function stored_operation_type(string $operation_id): string {
        $operations = get_option(self::OPERATIONS_OPTION, array());
        if (! is_array($operations) || ! is_array($operations[$operation_id] ?? null)) { return ''; }
        return $this->normalise_operation((string) ($operations[$operation_id]['operation'] ?? ''));
    }

    private function evidence_request_requires_identity_scope(array $params): bool {
        $operation = $this->normalise_operation((string) ($params['operation'] ?? ''));
        if ('identity-update' === $operation) { return true; }
        if (! in_array($operation, array('evidence-create','evidence-update','evidence-delete'), true)) { return false; }
        $payload = is_array($params['payload'] ?? null) ? $params['payload'] : array();
        return 'identifiers' === sanitize_key((string) ($payload['group'] ?? ''));
    }

    private function stored_evidence_plan_requires_identity_scope(string $plan_id): bool {
        $plans = get_option(self::EVIDENCE_PLANS_OPTION, array());
        if (! is_array($plans) || ! is_array($plans[$plan_id] ?? null)) { return false; }
        return $this->evidence_state_requires_identity_scope($plans[$plan_id]);
    }

    private function stored_evidence_operation_requires_identity_scope(string $operation_id): bool {
        $operations = get_option(self::EVIDENCE_OPERATIONS_OPTION, array());
        if (! is_array($operations) || ! is_array($operations[$operation_id] ?? null)) { return false; }
        return $this->evidence_state_requires_identity_scope($operations[$operation_id]);
    }

    private function evidence_state_requires_identity_scope(array $state): bool {
        $operation = $this->normalise_operation((string) ($state['operation'] ?? ''));
        if ('identity-update' === $operation) { return true; }
        $target = is_array($state['target'] ?? null) ? $state['target'] : array();
        return 'identifiers' === sanitize_key((string) ($target['group'] ?? ''));
    }

    private function semantic_scopes_for_operation(string $operation): array {
        $operation = $this->normalise_operation($operation);
        $contract = self::operation_scope_contract();
        $scopes = is_array($contract[$operation] ?? null) ? $contract[$operation] : array();
        return array_values(array_filter($scopes, static fn(string $scope): bool => 'operations.apply' !== $scope));
    }

    private function normalise_operation(string $operation): string {
        return sanitize_key(str_replace('.', '-', strtolower(trim($operation))));
    }
}
