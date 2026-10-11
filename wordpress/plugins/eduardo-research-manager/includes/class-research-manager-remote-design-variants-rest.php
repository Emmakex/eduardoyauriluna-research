<?php
/** Authenticated M7b bounded Theme component variant control. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Remote_Design_Variants_REST {
    private Eduardo_Research_Manager_Remote_REST $auth;
    private Eduardo_Research_Manager_Design_Variants $variants;

    public function __construct(?Eduardo_Research_Manager_Remote_REST $auth = null, ?Eduardo_Research_Manager_Design_Variants $variants = null) {
        $this->auth = $auth ?: Eduardo_Research_Manager::remote_rest();
        $this->variants = $variants ?: new Eduardo_Research_Manager_Design_Variants();
    }

    public function register(): void {
        add_action('rest_api_init', array($this, 'register_routes'));
        add_filter('rest_request_after_callbacks', array($this, 'augment_capabilities'), 45, 3);
    }

    public function register_routes(): void {
        $ns = Eduardo_Research_Manager_Remote_REST::NAMESPACE;
        register_rest_route($ns, '/design/variants', array(
            'methods'=>WP_REST_Server::READABLE,
            'callback'=>array($this, 'inspect'),
            'permission_callback'=>fn(WP_REST_Request $request) => $this->permission($request, 'design.read'),
        ));
        register_rest_route($ns, '/design/variants/plan', array(
            'methods'=>WP_REST_Server::CREATABLE,
            'callback'=>array($this, 'create_plan'),
            'permission_callback'=>fn(WP_REST_Request $request) => $this->permission($request, 'design.write', 'operations.apply'),
        ));
        register_rest_route($ns, '/design/variants/plans/(?P<plan_id>[A-Za-z0-9._:-]+)', array(
            'methods'=>WP_REST_Server::READABLE,
            'callback'=>array($this, 'get_plan'),
            'permission_callback'=>fn(WP_REST_Request $request) => $this->permission($request, 'design.read'),
        ));
        register_rest_route($ns, '/design/variants/plans/(?P<plan_id>[A-Za-z0-9._:-]+)/apply', array(
            'methods'=>WP_REST_Server::CREATABLE,
            'callback'=>array($this, 'apply_plan'),
            'permission_callback'=>fn(WP_REST_Request $request) => $this->permission($request, 'design.write', 'operations.apply'),
        ));
        register_rest_route($ns, '/design/variants/operations/(?P<operation_id>[A-Za-z0-9._:-]+)', array(
            'methods'=>WP_REST_Server::READABLE,
            'callback'=>array($this, 'get_operation'),
            'permission_callback'=>fn(WP_REST_Request $request) => $this->permission($request, 'design.read'),
        ));
        register_rest_route($ns, '/design/variants/operations/(?P<operation_id>[A-Za-z0-9._:-]+)/verify', array(
            'methods'=>WP_REST_Server::CREATABLE,
            'callback'=>array($this, 'verify_operation'),
            'permission_callback'=>fn(WP_REST_Request $request) => $this->permission($request, 'design.read'),
        ));
        register_rest_route($ns, '/design/variants/operations/(?P<operation_id>[A-Za-z0-9._:-]+)/rollback', array(
            'methods'=>WP_REST_Server::CREATABLE,
            'callback'=>array($this, 'rollback_operation'),
            'permission_callback'=>fn(WP_REST_Request $request) => $this->permission($request, 'design.write', 'operations.rollback'),
        ));
    }

    public function inspect(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $result = $this->variants->inspect();
        return is_wp_error($result) ? $result : $this->response($request, $result);
    }

    public function create_plan(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $params = $this->params($request);
        return $this->idempotent($request, fn() => $this->variants->create_plan(
            is_array($params['changes'] ?? null) ? $params['changes'] : array(),
            sanitize_text_field((string) ($params['intent'] ?? '')),
            $this->actor($request)
        ));
    }

    public function get_plan(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $result = $this->variants->get_plan((string) $request['plan_id']);
        return is_wp_error($result) ? $result : $this->response($request, $result);
    }

    public function apply_plan(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $params = $this->params($request);
        return $this->idempotent($request, fn() => $this->variants->apply((string) $request['plan_id'], ! empty($params['confirm']), $this->actor($request)));
    }

    public function get_operation(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $result = $this->variants->get_operation((string) $request['operation_id']);
        return is_wp_error($result) ? $result : $this->response($request, $result);
    }

    public function verify_operation(WP_REST_Request $request): WP_REST_Response|WP_Error {
        return $this->idempotent($request, fn() => $this->variants->verify((string) $request['operation_id'], $this->actor($request)));
    }

    public function rollback_operation(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $params = $this->params($request);
        return $this->idempotent($request, fn() => $this->variants->rollback((string) $request['operation_id'], ! empty($params['confirm']), $this->actor($request)));
    }

    public function augment_capabilities(mixed $response, mixed $handler, WP_REST_Request $request): mixed {
        if ('/' . Eduardo_Research_Manager_Remote_REST::NAMESPACE . '/capabilities' !== $request->get_route()) { return $response; }
        if (is_wp_error($response) || ! $response instanceof WP_REST_Response) { return $response; }
        $payload = $response->get_data();
        if (! is_array($payload) || ! is_array($payload['data'] ?? null)) { return $response; }
        $data = $payload['data'];
        $read = is_array($data['read_endpoints'] ?? null) ? $data['read_endpoints'] : array();
        $read['design_variants'] = 'design.read';
        $data['read_endpoints'] = $read;
        $control = is_array($data['design_control'] ?? null) ? $data['design_control'] : array();
        $control['section_component_variants'] = array(
            'available'=>true,
            'endpoint'=>'/design/variants',
            'dimensions'=>array('hero','sections','cards','collections'),
            'lifecycle'=>array('inspect','plan','apply','status','verify','rollback'),
            'semantic_markup_unchanged'=>true,
            'content_order_unchanged'=>true,
            'reduced_motion_respected'=>true,
            'arbitrary_html'=>false,
            'arbitrary_class'=>false,
            'arbitrary_css'=>false,
            'section_hiding'=>false,
        );
        $data['design_control'] = $control;
        $payload['data'] = $data;
        $response->set_data($payload);
        return $response;
    }

    private function permission(WP_REST_Request $request, string $primary, string $secondary = ''): bool|WP_Error {
        $allowed = $this->auth->permission($request, $primary);
        if (is_wp_error($allowed) || true !== $allowed || '' === $secondary) { return $allowed; }
        $connection = Eduardo_Research_Manager::remote_credentials()->state();
        if (! Eduardo_Research_Manager::remote_credentials()->has_scope($connection, $secondary)) {
            return new WP_Error('scope_denied', sprintf('Remote Manager connection does not grant %s.', $secondary), array('status'=>403));
        }
        return true;
    }

    private function idempotent(WP_REST_Request $request, callable $callback): WP_REST_Response|WP_Error {
        $connection = Eduardo_Research_Manager::remote_credentials()->state();
        $connection_id = (string) ($connection['connection_id'] ?? '');
        $request_id = trim((string) $request->get_header('x-research-manager-request-id'));
        $guard = Eduardo_Research_Manager::remote_guard();
        $fingerprint = $guard->request_fingerprint($request);
        $replay = $guard->validate_idempotency($connection_id, $request_id, $fingerprint);
        if (is_wp_error($replay)) { return $replay; }
        if (is_array($replay)) {
            return new WP_REST_Response(array('ok'=>true,'request_id'=>$request_id,'idempotent_replay'=>true,'data'=>is_array($replay['result'] ?? null) ? $replay['result'] : array()), 200);
        }
        $result = $callback();
        if (is_wp_error($result)) { return $result; }
        $result = is_array($result) ? $result : array('result'=>$result);
        $guard->remember_request($connection_id, $request_id, $fingerprint, $result);
        return $this->response($request, $result);
    }

    private function actor(WP_REST_Request $request): array {
        $connection = Eduardo_Research_Manager::remote_credentials()->state();
        return array('connection_id'=>(string) ($connection['connection_id'] ?? ''),'wordpress_user_id'=>get_current_user_id(),'request_id'=>(string) $request->get_header('x-research-manager-request-id'));
    }

    private function params(WP_REST_Request $request): array {
        $params = $request->get_json_params();
        if (! is_array($params)) { $params = $request->get_body_params(); }
        return is_array($params) ? $params : array();
    }

    private function response(WP_REST_Request $request, array $data): WP_REST_Response {
        return new WP_REST_Response(array('ok'=>true,'request_id'=>(string) $request->get_header('x-research-manager-request-id'),'data'=>$data), 200);
    }
}
