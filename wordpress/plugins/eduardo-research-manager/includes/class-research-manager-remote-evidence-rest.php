<?php
/** Authenticated remote gateway for academic identity and Research evidence/provenance. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Remote_Evidence_REST {
    private Eduardo_Research_Manager_Remote_REST $auth;
    private Eduardo_Research_Manager_Remote_Evidence_Operations $operations;

    public function __construct(
        ?Eduardo_Research_Manager_Remote_REST $auth = null,
        ?Eduardo_Research_Manager_Remote_Evidence_Operations $operations = null
    ) {
        $this->auth = $auth ?: Eduardo_Research_Manager::remote_rest();
        $this->operations = $operations ?: new Eduardo_Research_Manager_Remote_Evidence_Operations();
    }

    public function register(): void {
        add_action('rest_api_init', array($this, 'register_routes'));
        add_filter('rest_request_after_callbacks', array($this, 'augment_capabilities'), 10, 3);
    }

    public function register_routes(): void {
        $namespace = Eduardo_Research_Manager_Remote_REST::NAMESPACE;

        register_rest_route($namespace, '/research-evidence', array(
            'methods'=>WP_REST_Server::READABLE,
            'callback'=>array($this, 'inventory'),
            'permission_callback'=>fn(WP_REST_Request $request) => $this->permission($request, 'evidence.read'),
        ));
        register_rest_route($namespace, '/research-evidence/identity', array(
            'methods'=>WP_REST_Server::READABLE,
            'callback'=>array($this, 'identity'),
            'permission_callback'=>fn(WP_REST_Request $request) => $this->permission($request, 'evidence.read'),
        ));
        register_rest_route($namespace, '/research-evidence/(?P<group>[a-z_]+)', array(
            'methods'=>WP_REST_Server::READABLE,
            'callback'=>array($this, 'records'),
            'permission_callback'=>fn(WP_REST_Request $request) => $this->permission($request, 'evidence.read'),
        ));
        register_rest_route($namespace, '/research-evidence/(?P<group>[a-z_]+)/(?P<record_id>[A-Za-z0-9._:-]+)', array(
            'methods'=>WP_REST_Server::READABLE,
            'callback'=>array($this, 'inspect_record'),
            'permission_callback'=>fn(WP_REST_Request $request) => $this->permission($request, 'evidence.read'),
        ));
        register_rest_route($namespace, '/research-evidence/plan', array(
            'methods'=>WP_REST_Server::CREATABLE,
            'callback'=>array($this, 'create_plan'),
            'permission_callback'=>fn(WP_REST_Request $request) => $this->permission($request, 'operations.apply', 'evidence.write'),
        ));
        register_rest_route($namespace, '/research-evidence/plans/(?P<plan_id>[A-Za-z0-9._:-]+)', array(
            'methods'=>WP_REST_Server::READABLE,
            'callback'=>array($this, 'get_plan'),
            'permission_callback'=>fn(WP_REST_Request $request) => $this->permission($request, 'evidence.read'),
        ));
        register_rest_route($namespace, '/research-evidence/plans/(?P<plan_id>[A-Za-z0-9._:-]+)/apply', array(
            'methods'=>WP_REST_Server::CREATABLE,
            'callback'=>array($this, 'apply_plan'),
            'permission_callback'=>fn(WP_REST_Request $request) => $this->permission($request, 'operations.apply', 'evidence.write'),
        ));
        register_rest_route($namespace, '/research-evidence/operations/(?P<operation_id>[A-Za-z0-9._:-]+)', array(
            'methods'=>WP_REST_Server::READABLE,
            'callback'=>array($this, 'get_operation'),
            'permission_callback'=>fn(WP_REST_Request $request) => $this->permission($request, 'evidence.read'),
        ));
        register_rest_route($namespace, '/research-evidence/operations/(?P<operation_id>[A-Za-z0-9._:-]+)/verify', array(
            'methods'=>WP_REST_Server::CREATABLE,
            'callback'=>array($this, 'verify_operation'),
            'permission_callback'=>fn(WP_REST_Request $request) => $this->permission($request, 'site.diagnostics', 'evidence.read'),
        ));
        register_rest_route($namespace, '/research-evidence/operations/(?P<operation_id>[A-Za-z0-9._:-]+)/rollback', array(
            'methods'=>WP_REST_Server::CREATABLE,
            'callback'=>array($this, 'rollback_operation'),
            'permission_callback'=>fn(WP_REST_Request $request) => $this->permission($request, 'operations.rollback', 'evidence.write'),
        ));
    }

    public function inventory(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $editor = Eduardo_Research_Manager::evidence_editor();
        $identity = $editor->identity();
        $store = $editor->store();
        if (is_wp_error($identity)) { return $identity; }
        if (is_wp_error($store)) { return $store; }

        $groups = array();
        $total = 0;
        foreach ($editor->groups() as $group => $spec) {
            $records = $editor->records((string) $group);
            if (is_wp_error($records)) { return $records; }
            $groups[$group] = array(
                'label'=>(string) ($spec['label'] ?? ''),
                'surfaces'=>array_values((array) ($spec['surfaces'] ?? array())),
                'count'=>count($records),
            );
            $total += count($records);
        }

        return $this->response($request, array(
            'identity'=>$identity,
            'record_count'=>$total,
            'groups'=>$groups,
        ));
    }

    public function identity(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $identity = Eduardo_Research_Manager::evidence_editor()->identity();
        return is_wp_error($identity) ? $identity : $this->response($request, $identity);
    }

    public function records(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $group = sanitize_key((string) $request['group']);
        $editor = Eduardo_Research_Manager::evidence_editor();
        $groups = $editor->groups();
        if (! isset($groups[$group])) { return $this->error('validation_failed', 'Unsupported Research evidence group.', 400); }
        $records = $editor->records($group);
        if (is_wp_error($records)) { return $records; }
        return $this->response($request, array(
            'group'=>$group,
            'label'=>(string) ($groups[$group]['label'] ?? ''),
            'surfaces'=>array_values((array) ($groups[$group]['surfaces'] ?? array())),
            'count'=>count($records),
            'items'=>$records,
        ));
    }

    public function inspect_record(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $group = sanitize_key((string) $request['group']);
        $record_id = sanitize_key((string) $request['record_id']);
        $record = Eduardo_Research_Manager::evidence_editor()->inspect_record($group, $record_id);
        return is_wp_error($record) ? $record : $this->response($request, $record);
    }

    public function create_plan(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $params = $this->json_params($request);
        $operation = sanitize_key(str_replace('.', '-', strtolower(trim((string) ($params['operation'] ?? '')))));
        if ('identity-update' === $operation) {
            $identity_scope = $this->require_scope('identity.write');
            if (is_wp_error($identity_scope)) { return $identity_scope; }
        }
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
        $plan = $this->operations->get_plan((string) $request['plan_id']);
        if (is_wp_error($plan)) { return $plan; }
        if ('identity-update' === (string) ($plan['operation'] ?? '')) {
            $identity_scope = $this->require_scope('identity.write');
            if (is_wp_error($identity_scope)) { return $identity_scope; }
        }
        $params = $this->json_params($request);
        return $this->idempotent_mutation($request, function () use ($params, $request) {
            return $this->operations->apply((string) $request['plan_id'], ! empty($params['confirm']), $this->actor($request));
        });
    }

    public function get_operation(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $result = $this->operations->get_operation((string) $request['operation_id']);
        return is_wp_error($result) ? $result : $this->response($request, $result);
    }

    public function verify_operation(WP_REST_Request $request): WP_REST_Response|WP_Error {
        return $this->idempotent_mutation($request, function () use ($request) {
            return $this->operations->verify((string) $request['operation_id'], $this->actor($request));
        });
    }

    public function rollback_operation(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $operation = $this->operations->get_operation((string) $request['operation_id']);
        if (is_wp_error($operation)) { return $operation; }
        if ('identity-update' === (string) ($operation['operation'] ?? '')) {
            $identity_scope = $this->require_scope('identity.write');
            if (is_wp_error($identity_scope)) { return $identity_scope; }
        }
        $params = $this->json_params($request);
        return $this->idempotent_mutation($request, function () use ($params, $request) {
            return $this->operations->rollback((string) $request['operation_id'], ! empty($params['confirm']), $this->actor($request));
        });
    }

    public function augment_capabilities(mixed $response, mixed $handler, WP_REST_Request $request): mixed {
        if ('/' . Eduardo_Research_Manager_Remote_REST::NAMESPACE . '/capabilities' !== $request->get_route()) { return $response; }
        if (is_wp_error($response) || ! $response instanceof WP_REST_Response) { return $response; }
        $payload = $response->get_data();
        if (! is_array($payload) || ! is_array($payload['data'] ?? null)) { return $response; }

        $data = $payload['data'];
        $read = is_array($data['read_endpoints'] ?? null) ? $data['read_endpoints'] : array();
        $read['research_evidence'] = 'evidence.read';
        $read['research_evidence_identity'] = 'evidence.read';
        $data['read_endpoints'] = $read;

        $groups = array();
        foreach (Eduardo_Research_Manager::evidence_editor()->groups() as $group => $spec) {
            $groups[$group] = array(
                'label'=>(string) ($spec['label'] ?? ''),
                'surfaces'=>array_values((array) ($spec['surfaces'] ?? array())),
            );
        }
        $data['research_evidence_control'] = array(
            'milestone'=>'M5',
            'available'=>true,
            'groups'=>$groups,
            'operations'=>array('identity-update','evidence-create','evidence-update','evidence-delete'),
            'transport_base'=>'/research-manager/v1/research-evidence',
            'lifecycle'=>array('plan','apply','status','verify','rollback'),
            'read_scope'=>'evidence.read',
            'write_scope'=>'evidence.write',
            'identity_extra_scope'=>'identity.write',
            'evidence_gate'=>array(
                'required_for_mutation'=>true,
                'confirmation_field'=>'evidence_confirmed',
                'reference_field'=>'evidence_reference',
                'confirmation_class'=>'evidence-explicit',
            ),
            'stale_preview_protection'=>true,
            'idempotency'=>true,
            'provenance_readback'=>true,
            'public_surface_mapping'=>true,
            'rendered_verification'=>'aggregate-m5-public-schema-acceptance',
            'arbitrary_wordpress_proxy'=>false,
        );
        $payload['data'] = $data;
        $response->set_data($payload);
        return $response;
    }

    private function permission(WP_REST_Request $request, string $primary_scope, string $secondary_scope = ''): bool|WP_Error {
        $allowed = $this->auth->permission($request, $primary_scope);
        if (is_wp_error($allowed) || true !== $allowed || '' === $secondary_scope) { return $allowed; }
        return $this->require_scope($secondary_scope);
    }

    private function require_scope(string $scope): bool|WP_Error {
        $connection = Eduardo_Research_Manager::remote_credentials()->state();
        if (! Eduardo_Research_Manager::remote_credentials()->has_scope($connection, $scope)) {
            return $this->error('scope_denied', sprintf('Remote Manager connection does not grant %s.', $scope), 403);
        }
        return true;
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
            return new WP_REST_Response(array('ok'=>true,'request_id'=>$request_id,'idempotent_replay'=>true,'data'=>$stored), 200);
        }
        $result = $callback();
        if (is_wp_error($result)) { return $result; }
        $result = is_array($result) ? $result : array('result'=>$result);
        $guard->remember_request($connection_id, $request_id, $fingerprint, $result);
        return new WP_REST_Response(array('ok'=>true,'request_id'=>$request_id,'idempotent_replay'=>false,'data'=>$result), 200);
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

    private function error(string $code, string $message, int $status): WP_Error {
        return new WP_Error($code, $message, array('status'=>$status));
    }
}
