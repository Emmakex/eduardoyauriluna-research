<?php
/** Authenticated remote gateway for bounded Research Outputs, Projects, Software and Datasets. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Remote_Objects_REST {
    private Eduardo_Research_Manager_Remote_REST $auth;
    private Eduardo_Research_Manager_Remote_Object_Operations $operations;

    public function __construct(
        ?Eduardo_Research_Manager_Remote_REST $auth = null,
        ?Eduardo_Research_Manager_Remote_Object_Operations $operations = null
    ) {
        $this->auth = $auth ?: Eduardo_Research_Manager::remote_rest();
        $this->operations = $operations ?: new Eduardo_Research_Manager_Remote_Object_Operations();
    }

    public function register(): void {
        add_action('rest_api_init', array($this, 'register_routes'));
        add_filter('rest_request_after_callbacks', array($this, 'augment_capabilities'), 10, 3);
    }

    public function register_routes(): void {
        $namespace = Eduardo_Research_Manager_Remote_REST::NAMESPACE;

        register_rest_route($namespace, '/research-objects', array(
            'methods'=>WP_REST_Server::READABLE,
            'callback'=>array($this, 'inventory'),
            'permission_callback'=>fn(WP_REST_Request $request) => $this->permission($request, 'research.read'),
        ));
        register_rest_route($namespace, '/research-objects/(?P<kind>[a-z-]+)/(?P<post_id>\d+)', array(
            'methods'=>WP_REST_Server::READABLE,
            'callback'=>array($this, 'inspect'),
            'permission_callback'=>fn(WP_REST_Request $request) => $this->permission($request, 'research.read'),
        ));
        register_rest_route($namespace, '/research-objects/plan', array(
            'methods'=>WP_REST_Server::CREATABLE,
            'callback'=>array($this, 'create_plan'),
            'permission_callback'=>fn(WP_REST_Request $request) => $this->permission($request, 'operations.apply', 'research.write'),
        ));
        register_rest_route($namespace, '/research-objects/plans/(?P<plan_id>[A-Za-z0-9._:-]+)', array(
            'methods'=>WP_REST_Server::READABLE,
            'callback'=>array($this, 'get_plan'),
            'permission_callback'=>fn(WP_REST_Request $request) => $this->permission($request, 'research.read'),
        ));
        register_rest_route($namespace, '/research-objects/plans/(?P<plan_id>[A-Za-z0-9._:-]+)/apply', array(
            'methods'=>WP_REST_Server::CREATABLE,
            'callback'=>array($this, 'apply_plan'),
            'permission_callback'=>fn(WP_REST_Request $request) => $this->permission($request, 'operations.apply', 'research.write'),
        ));
        register_rest_route($namespace, '/research-objects/operations/(?P<operation_id>[A-Za-z0-9._:-]+)', array(
            'methods'=>WP_REST_Server::READABLE,
            'callback'=>array($this, 'get_operation'),
            'permission_callback'=>fn(WP_REST_Request $request) => $this->permission($request, 'research.read'),
        ));
        register_rest_route($namespace, '/research-objects/operations/(?P<operation_id>[A-Za-z0-9._:-]+)/verify', array(
            'methods'=>WP_REST_Server::CREATABLE,
            'callback'=>array($this, 'verify_operation'),
            'permission_callback'=>fn(WP_REST_Request $request) => $this->permission($request, 'site.diagnostics', 'research.read'),
        ));
        register_rest_route($namespace, '/research-objects/operations/(?P<operation_id>[A-Za-z0-9._:-]+)/rollback', array(
            'methods'=>WP_REST_Server::CREATABLE,
            'callback'=>array($this, 'rollback_operation'),
            'permission_callback'=>fn(WP_REST_Request $request) => $this->permission($request, 'operations.rollback', 'research.write'),
        ));
    }

    public function inventory(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $kind = sanitize_key((string) ($request->get_param('kind') ?: ''));
        $language = sanitize_key((string) ($request->get_param('language') ?: ''));
        $editor = Eduardo_Research_Manager::object_editor();
        $supported = $editor->supported_kinds();

        if ('' !== $kind) {
            if (! isset($supported[$kind])) { return $this->error('validation_failed', 'Unsupported Research Object kind.', 400); }
            $items = $editor->list($kind, $language);
            if (is_wp_error($items)) { return $items; }
            return $this->response($request, array('kind'=>$kind,'language'=>$language,'count'=>count($items),'items'=>$items));
        }

        $groups = array();
        $total = 0;
        foreach (array_keys($supported) as $supported_kind) {
            $items = $editor->list((string) $supported_kind, $language);
            if (is_wp_error($items)) { return $items; }
            $groups[$supported_kind] = array('count'=>count($items),'items'=>$items);
            $total += count($items);
        }
        return $this->response($request, array('kind'=>'','language'=>$language,'count'=>$total,'groups'=>$groups));
    }

    public function inspect(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $kind = sanitize_key((string) $request['kind']);
        $post_id = absint($request['post_id']);
        $result = Eduardo_Research_Manager::object_editor()->inspect($kind, $post_id);
        return is_wp_error($result) ? $result : $this->response($request, $result);
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
            return $this->operations->apply((string) $request['plan_id'], ! empty($params['confirm']), $this->actor($request));
        });
    }

    public function get_operation(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $result = $this->operations->get_operation((string) $request['operation_id']);
        return is_wp_error($result) ? $result : $this->response($request, $result);
    }

    public function verify_operation(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $params = $this->json_params($request);
        return $this->idempotent_mutation($request, function () use ($params, $request) {
            return $this->operations->verify((string) $request['operation_id'], ! empty($params['rendered']), $this->actor($request));
        });
    }

    public function rollback_operation(WP_REST_Request $request): WP_REST_Response|WP_Error {
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
        $read['research_objects'] = 'research.read';
        $read['research_object'] = 'research.read';
        $data['read_endpoints'] = $read;

        $supported = Eduardo_Research_Manager::object_editor()->supported_kinds();
        $kinds = array();
        foreach ($supported as $kind => $spec) {
            $kinds[$kind] = array(
                'post_type'=>(string) ($spec['post_type'] ?? ''),
                'label'=>(string) ($spec['label'] ?? ''),
                'fields'=>array_values((array) ($spec['fields'] ?? array())),
            );
        }
        $data['research_object_control'] = array(
            'milestone'=>'M5',
            'foundation'=>true,
            'inventory'=>true,
            'inspection'=>true,
            'kinds'=>$kinds,
            'operations'=>array('object-create','object-update'),
            'transport_base'=>'/research-manager/v1/research-objects',
            'lifecycle'=>array('plan','apply','status','verify','rollback'),
            'evidence_gate'=>array(
                'required_for_mutation'=>true,
                'confirmation_field'=>'evidence_confirmed',
                'reference_field'=>'evidence_reference',
                'confirmation_class'=>'evidence-explicit',
            ),
            'stale_preview_protection'=>true,
            'idempotency'=>true,
            'rendered_verification'=>true,
            'arbitrary_wordpress_proxy'=>false,
            'research_lines'=>'next-m5-slice',
            'translations'=>'next-m5-slice',
        );
        $payload['data'] = $data;
        $response->set_data($payload);
        return $response;
    }

    private function permission(WP_REST_Request $request, string $primary_scope, string $secondary_scope = ''): bool|WP_Error {
        $allowed = $this->auth->permission($request, $primary_scope);
        if (is_wp_error($allowed) || true !== $allowed || '' === $secondary_scope) { return $allowed; }
        $connection = Eduardo_Research_Manager::remote_credentials()->state();
        if (! Eduardo_Research_Manager::remote_credentials()->has_scope($connection, $secondary_scope)) {
            return $this->error('scope_denied', sprintf('Remote Manager connection does not grant %s.', $secondary_scope), 403);
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
