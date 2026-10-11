<?php
/** Enforce semantic least-privilege scopes after authenticated REST permission checks. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Remote_Semantic_Scope_Guard {
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
            $plan = Eduardo_Research_Manager::remote_operations()->get_plan(rawurldecode((string) $matches[1]));
            if (is_wp_error($plan)) { return array(); }
            return $this->semantic_scopes_for_operation((string) ($plan['operation'] ?? ''));
        }

        if (preg_match('#^' . preg_quote($prefix, '#') . '/operations/([^/]+)/rollback$#', $route, $matches)) {
            $operation = Eduardo_Research_Manager::remote_operations()->get_operation(rawurldecode((string) $matches[1]));
            if (is_wp_error($operation)) { return array(); }
            return $this->semantic_scopes_for_operation((string) ($operation['operation'] ?? ''));
        }

        if ($prefix . '/insights/pairing/plan' === $route
            || preg_match('#^' . preg_quote($prefix, '#') . '/insights/pairing/plans/[^/]+/apply$#', $route)
            || preg_match('#^' . preg_quote($prefix, '#') . '/insights/pairing/operations/[^/]+/rollback$#', $route)) {
            return array('insights.write','translations.write');
        }

        return array();
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
