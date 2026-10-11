<?php
/** Authenticated remote gateway for bounded Media metadata mutation. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Remote_Media_Metadata_REST {
    private Eduardo_Research_Manager_Remote_REST $auth;
    private Eduardo_Research_Manager_Remote_Media_Metadata_Operations $operations;

    public function __construct(
        ?Eduardo_Research_Manager_Remote_REST $auth = null,
        ?Eduardo_Research_Manager_Remote_Media_Metadata_Operations $operations = null
    ) {
        $this->auth = $auth ?: Eduardo_Research_Manager::remote_rest();
        $this->operations = $operations ?: new Eduardo_Research_Manager_Remote_Media_Metadata_Operations();
    }

    public function register(): void {
        add_action('rest_api_init', array($this, 'register_routes'));
        add_filter('rest_request_after_callbacks', array($this, 'augment_capabilities'), 15, 3);
    }

    public function register_routes(): void {
        $namespace = Eduardo_Research_Manager_Remote_REST::NAMESPACE;
        register_rest_route($namespace, '/media/metadata/plan', array(
            'methods'=>WP_REST_Server::CREATABLE,
            'callback'=>array($this, 'create_plan'),
            'permission_callback'=>fn(WP_REST_Request $request) => $this->permission($request, 'operations.apply', 'media.write'),
        ));
        register_rest_route($namespace, '/media/metadata/plans/(?P<plan_id>[A-Za-z0-9._:-]+)', array(
            'methods'=>WP_REST_Server::READABLE,
            'callback'=>array($this, 'get_plan'),
            'permission_callback'=>fn(WP_REST_Request $request) => $this->auth->permission($request, 'media.read'),
        ));
        register_rest_route($namespace, '/media/metadata/plans/(?P<plan_id>[A-Za-z0-9._:-]+)/apply', array(
            'methods'=>WP_REST_Server::CREATABLE,
            'callback'=>array($this, 'apply_plan'),
            'permission_callback'=>fn(WP_REST_Request $request) => $this->permission($request, 'operations.apply', 'media.write'),
        ));
        register_rest_route($namespace, '/media/metadata/operations/(?P<operation_id>[A-Za-z0-9._:-]+)', array(
            'methods'=>WP_REST_Server::READABLE,
            'callback'=>array($this, 'get_operation'),
            'permission_callback'=>fn(WP_REST_Request $request) => $this->auth->permission($request, 'media.read'),
        ));
        register_rest_route($namespace, '/media/metadata/operations/(?P<operation_id>[A-Za-z0-9._:-]+)/verify', array(
            'methods'=>WP_REST_Server::CREATABLE,
            'callback'=>array($this, 'verify_operation'),
            'permission_callback'=>fn(WP_REST_Request $request) => $this->permission($request, 'site.diagnostics', 'media.read'),
        ));
        register_rest_route($namespace, '/media/metadata/operations/(?P<operation_id>[A-Za-z0-9._:-]+)/rollback', array(
            'methods'=>WP_REST_Server::CREATABLE,
            'callback'=>array($this, 'rollback_operation'),
            'permission_callback'=>fn(WP_REST_Request $request) => $this->permission($request, 'operations.rollback', 'media.write'),
        ));
    }

    public function create_plan(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $params = $this->json($request);
        $operation = sanitize_key(str_replace('.', '-', strtolower(trim((string) ($params['operation'] ?? '')))));
        return $this->idem($request, fn() => $this->operations->create_plan(
            $operation,
            is_array($params['payload'] ?? null) ? $params['payload'] : array(),
            $this->actor($request)
        ));
    }

    public function get_plan(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $result = $this->operations->get_plan((string) $request['plan_id']);
        return is_wp_error($result) ? $result : $this->response($request, $result);
    }

    public function apply_plan(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $params = $this->json($request);
        return $this->idem($request, fn() => $this->operations->apply(
            (string) $request['plan_id'],
            ! empty($params['confirm']),
            $this->actor($request)
        ));
    }

    public function get_operation(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $result = $this->operations->get_operation((string) $request['operation_id']);
        return is_wp_error($result) ? $result : $this->response($request, $result);
    }

    public function verify_operation(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $params = $this->json($request);
        return $this->idem($request, fn() => $this->operations->verify(
            (string) $request['operation_id'],
            ! empty($params['rendered']),
            $this->actor($request)
        ));
    }

    public function rollback_operation(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $params = $this->json($request);
        return $this->idem($request, fn() => $this->operations->rollback(
            (string) $request['operation_id'],
            ! empty($params['confirm']),
            $this->actor($request)
        ));
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
        $media = is_array($data['media_control'] ?? null) ? $data['media_control'] : array();
        $media['write_available'] = true;
        $media['write_scope'] = 'media.write';
        $media['operations'] = array('media-metadata-update');
        $media['metadata_fields'] = Eduardo_Research_Manager_Media_Editor::FIELDS;
        $media['metadata_transport'] = array(
            'base'=>'/research-manager/v1/media/metadata',
            'lifecycle'=>array('plan','apply','status','verify','rollback'),
            'required_write_scopes'=>array('operations.apply','media.write'),
            'required_rollback_scopes'=>array('operations.rollback','media.write'),
            'stale_attachment_revision_protection'=>true,
            'idempotency'=>true,
            'rendered_verification'=>'not-applicable-until-assignment',
        );
        $media['arbitrary_upload_proxy'] = false;
        $media['destructive_delete'] = false;
        $media['arbitrary_wordpress_proxy'] = false;
        $data['media_control'] = $media;
        $payload['data'] = $data;
        $response->set_data($payload);
        return $response;
    }

    private function permission(WP_REST_Request $request, string $primary, string $secondary = ''): bool|WP_Error {
        $ok = $this->auth->permission($request, $primary);
        if (is_wp_error($ok) || true !== $ok || '' === $secondary) { return $ok; }
        $credentials = Eduardo_Research_Manager::remote_credentials();
        $state = $credentials->state();
        if ($credentials->has_scope($state, $secondary)) { return true; }
        return new WP_Error(
            'scope_denied',
            sprintf('Remote Manager connection does not grant %s.', $secondary),
            array('status'=>403)
        );
    }

    private function idem(WP_REST_Request $request, callable $callback): WP_REST_Response|WP_Error {
        $connection = Eduardo_Research_Manager::remote_credentials()->state();
        $connection_id = (string) ($connection['connection_id'] ?? '');
        $request_id = trim((string) $request->get_header('x-research-manager-request-id'));
        $guard = Eduardo_Research_Manager::remote_guard();
        $fingerprint = $guard->request_fingerprint($request);
        $previous = $guard->validate_idempotency($connection_id, $request_id, $fingerprint);
        if (is_wp_error($previous)) { return $previous; }
        if (is_array($previous)) {
            return new WP_REST_Response(array(
                'ok'=>true,
                'request_id'=>$request_id,
                'idempotent_replay'=>true,
                'data'=>(array) ($previous['result'] ?? array()),
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

    private function json(WP_REST_Request $request): array {
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
