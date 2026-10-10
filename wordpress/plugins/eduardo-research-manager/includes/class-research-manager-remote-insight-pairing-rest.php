<?php
/** Authenticated remote lifecycle for bounded EN/ES Research Insight pairing. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Remote_Insight_Pairing_REST {
    private Eduardo_Research_Manager_Remote_REST $auth;
    private Eduardo_Research_Manager_Remote_Insight_Pairing_Operations $operations;

    public function __construct(
        ?Eduardo_Research_Manager_Remote_REST $auth = null,
        ?Eduardo_Research_Manager_Remote_Insight_Pairing_Operations $operations = null
    ) {
        $this->auth = $auth ?: Eduardo_Research_Manager::remote_rest();
        $this->operations = $operations ?: new Eduardo_Research_Manager_Remote_Insight_Pairing_Operations();
    }

    public function register(): void {
        add_action('rest_api_init', array($this, 'register_routes'));
    }

    public function register_routes(): void {
        $namespace = Eduardo_Research_Manager_Remote_REST::NAMESPACE;
        register_rest_route($namespace, '/insights/pairing/plan', array(
            'methods'=>WP_REST_Server::CREATABLE,
            'callback'=>array($this, 'create_plan'),
            'permission_callback'=>fn(WP_REST_Request $request) => $this->auth->permission($request, 'operations.apply'),
        ));
        register_rest_route($namespace, '/insights/pairing/plans/(?P<plan_id>[A-Za-z0-9._:-]+)', array(
            'methods'=>WP_REST_Server::READABLE,
            'callback'=>array($this, 'get_plan'),
            'permission_callback'=>fn(WP_REST_Request $request) => $this->auth->permission($request, 'operations.apply'),
        ));
        register_rest_route($namespace, '/insights/pairing/plans/(?P<plan_id>[A-Za-z0-9._:-]+)/apply', array(
            'methods'=>WP_REST_Server::CREATABLE,
            'callback'=>array($this, 'apply_plan'),
            'permission_callback'=>fn(WP_REST_Request $request) => $this->auth->permission($request, 'operations.apply'),
        ));
        register_rest_route($namespace, '/insights/pairing/operations/(?P<operation_id>[A-Za-z0-9._:-]+)', array(
            'methods'=>WP_REST_Server::READABLE,
            'callback'=>array($this, 'get_operation'),
            'permission_callback'=>fn(WP_REST_Request $request) => $this->auth->permission($request, 'site.read'),
        ));
        register_rest_route($namespace, '/insights/pairing/operations/(?P<operation_id>[A-Za-z0-9._:-]+)/verify', array(
            'methods'=>WP_REST_Server::CREATABLE,
            'callback'=>array($this, 'verify_operation'),
            'permission_callback'=>fn(WP_REST_Request $request) => $this->auth->permission($request, 'site.diagnostics'),
        ));
        register_rest_route($namespace, '/insights/pairing/operations/(?P<operation_id>[A-Za-z0-9._:-]+)/rollback', array(
            'methods'=>WP_REST_Server::CREATABLE,
            'callback'=>array($this, 'rollback_operation'),
            'permission_callback'=>fn(WP_REST_Request $request) => $this->auth->permission($request, 'operations.rollback'),
        ));
    }

    public function create_plan(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $params = $this->json_params($request);
        $operation = sanitize_key(str_replace('.', '-', strtolower(trim((string) ($params['operation'] ?? '')))));
        return $this->idempotent_mutation($request, function () use ($operation, $params, $request) {
            return $this->operations->create_plan(
                $operation,
                is_array($params['payload'] ?? null) ? $params['payload'] : array(),
                $this->actor($request)
            );
        });
    }

    public function get_plan(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $result = $this->operations->get_plan((string) $request['plan_id']);
        return is_wp_error($result) ? $result : $this->response($request, $result);
    }

    public function apply_plan(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $params = $this->json_params($request);
        return $this->idempotent_mutation($request, function () use ($params, $request) {
            return $this->operations->apply(
                (string) $request['plan_id'],
                ! empty($params['confirm']),
                $this->actor($request)
            );
        });
    }

    public function get_operation(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $result = $this->operations->get_operation((string) $request['operation_id']);
        return is_wp_error($result) ? $result : $this->response($request, $result);
    }

    public function verify_operation(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $params = $this->json_params($request);
        return $this->idempotent_mutation($request, function () use ($params, $request) {
            return $this->operations->verify(
                (string) $request['operation_id'],
                ! empty($params['rendered']),
                $this->actor($request)
            );
        });
    }

    public function rollback_operation(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $params = $this->json_params($request);
        return $this->idempotent_mutation($request, function () use ($params, $request) {
            return $this->operations->rollback(
                (string) $request['operation_id'],
                ! empty($params['confirm']),
                $this->actor($request)
            );
        });
    }

    private function idempotent_mutation(WP_REST_Request $request, callable $callback): WP_REST_Response|WP_Error {
        $connection = Eduardo_Research_Manager::remote_credentials()->state();
        $connection_id = (string) ($connection['connection_id'] ?? '');
        $request_id = trim((string) $request->get_header('x-research-manager-request-id'));
        $guard = Eduardo_Research_Manager::remote_guard();
        $fingerprint = $guard->request_fingerprint($request);
        $idempotency = $guard->validate_idempotency($connection_id, $request_id, $fingerprint);
        if (is_wp_error($idempotency)) { return $idempotency; }
        if (is_array($idempotency)) {
            $stored = is_array($idempotency['result'] ?? null) ? $idempotency['result'] : array();
            return new WP_REST_Response(array(
                'ok'=>true,
                'request_id'=>$request_id,
                'idempotent_replay'=>true,
                'data'=>$stored,
            ), 200);
        }

        $result = $callback();
        if (is_wp_error($result)) { return $result; }
        $result = is_array($result) ? $result : array('result'=>$result);
        $guard->remember_request($connection_id, $request_id, $fingerprint, $result);
        return new WP_REST_Response(array(
            'ok'=>true,
            'request_id'=>$request_id,
            'idempotent_replay'=>false,
            'data'=>$result,
        ), 200);
    }

    private function actor(WP_REST_Request $request): array {
        $connection = Eduardo_Research_Manager::remote_credentials()->state();
        return array(
            'connection_id'=>(string) ($connection['connection_id'] ?? ''),
            'wordpress_user_id'=>get_current_user_id(),
            'request_id'=>(string) $request->get_header('x-research-manager-request-id'),
        );
    }

    private function json_params(WP_REST_Request $request): array {
        $params = $request->get_json_params();
        if (! is_array($params)) { $params = $request->get_body_params(); }
        return is_array($params) ? $params : array();
    }

    private function response(WP_REST_Request $request, array $data): WP_REST_Response {
        return new WP_REST_Response(array(
            'ok'=>true,
            'request_id'=>(string) $request->get_header('x-research-manager-request-id'),
            'data'=>$data,
        ), 200);
    }
}
