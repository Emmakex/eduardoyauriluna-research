<?php
/** Authenticated multipart gateway for managed Media binary ingest. */
declare(strict_types=1);
if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Remote_Media_Ingest_REST {
    private Eduardo_Research_Manager_Remote_REST $auth;
    private Eduardo_Research_Manager_Remote_Media_Ingest_Operations $operations;

    public function __construct(?Eduardo_Research_Manager_Remote_REST $auth = null, ?Eduardo_Research_Manager_Remote_Media_Ingest_Operations $operations = null) {
        $this->auth = $auth ?: Eduardo_Research_Manager::remote_rest();
        $this->operations = $operations ?: new Eduardo_Research_Manager_Remote_Media_Ingest_Operations(Eduardo_Research_Manager::remote_audit());
    }

    public function register(): void {
        add_action('rest_api_init', array($this, 'register_routes'));
        add_filter('rest_request_after_callbacks', array($this, 'augment_capabilities'), 10, 3);
    }

    public function register_routes(): void {
        $ns = Eduardo_Research_Manager_Remote_REST::NAMESPACE;
        register_rest_route($ns, '/media/ingest/plan', array(
            'methods'=>WP_REST_Server::CREATABLE,'callback'=>array($this, 'create_plan'),
            'permission_callback'=>fn(WP_REST_Request $r) => $this->permission($r, 'operations.apply', 'media.write'),
        ));
        register_rest_route($ns, '/media/ingest/plans/(?P<plan_id>[A-Za-z0-9._:-]+)', array(
            'methods'=>WP_REST_Server::READABLE,'callback'=>array($this, 'get_plan'),
            'permission_callback'=>fn(WP_REST_Request $r) => $this->permission($r, 'media.read'),
        ));
        register_rest_route($ns, '/media/ingest/plans/(?P<plan_id>[A-Za-z0-9._:-]+)/cancel', array(
            'methods'=>WP_REST_Server::CREATABLE,'callback'=>array($this, 'cancel_plan'),
            'permission_callback'=>fn(WP_REST_Request $r) => $this->permission($r, 'media.write'),
        ));
        register_rest_route($ns, '/media/ingest/plans/(?P<plan_id>[A-Za-z0-9._:-]+)/apply', array(
            'methods'=>WP_REST_Server::CREATABLE,'callback'=>array($this, 'apply_plan'),
            'permission_callback'=>fn(WP_REST_Request $r) => $this->permission($r, 'operations.apply', 'media.write'),
        ));
        register_rest_route($ns, '/media/ingest/operations/(?P<operation_id>[A-Za-z0-9._:-]+)', array(
            'methods'=>WP_REST_Server::READABLE,'callback'=>array($this, 'get_operation'),
            'permission_callback'=>fn(WP_REST_Request $r) => $this->permission($r, 'media.read'),
        ));
        register_rest_route($ns, '/media/ingest/operations/(?P<operation_id>[A-Za-z0-9._:-]+)/verify', array(
            'methods'=>WP_REST_Server::CREATABLE,'callback'=>array($this, 'verify_operation'),
            'permission_callback'=>fn(WP_REST_Request $r) => $this->permission($r, 'site.diagnostics', 'media.read'),
        ));
        register_rest_route($ns, '/media/ingest/operations/(?P<operation_id>[A-Za-z0-9._:-]+)/rollback', array(
            'methods'=>WP_REST_Server::CREATABLE,'callback'=>array($this, 'rollback_operation'),
            'permission_callback'=>fn(WP_REST_Request $r) => $this->permission($r, 'operations.rollback', 'media.write'),
        ));
    }

    public function create_plan(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $files = $request->get_file_params();
        $file = is_array($files['file'] ?? null) ? $files['file'] : array();
        $input = $request->get_body_params();
        $input = is_array($input) ? $input : array();
        $result = $this->operations->create_plan($file, $input, $this->actor($request));
        return is_wp_error($result) ? $result : $this->response($request, $result, false);
    }

    public function get_plan(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $result = $this->operations->get_plan((string) $request['plan_id']);
        return is_wp_error($result) ? $result : $this->response($request, $result);
    }

    public function cancel_plan(WP_REST_Request $request): WP_REST_Response|WP_Error {
        return $this->idem($request, fn() => $this->operations->cancel((string) $request['plan_id'], $this->actor($request)));
    }

    public function apply_plan(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $params = $this->json($request);
        return $this->idem($request, fn() => $this->operations->apply((string) $request['plan_id'], ! empty($params['confirm']), $this->actor($request)));
    }

    public function get_operation(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $result = $this->operations->get_operation((string) $request['operation_id']);
        return is_wp_error($result) ? $result : $this->response($request, $result);
    }

    public function verify_operation(WP_REST_Request $request): WP_REST_Response|WP_Error {
        return $this->idem($request, fn() => $this->operations->verify((string) $request['operation_id'], $this->actor($request)));
    }

    public function rollback_operation(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $params = $this->json($request);
        return $this->idem($request, fn() => $this->operations->rollback((string) $request['operation_id'], ! empty($params['confirm']), $this->actor($request)));
    }

    public function augment_capabilities(mixed $response, mixed $handler, WP_REST_Request $request): mixed {
        if ('/' . Eduardo_Research_Manager_Remote_REST::NAMESPACE . '/capabilities' !== $request->get_route() || is_wp_error($response) || ! $response instanceof WP_REST_Response) { return $response; }
        $payload = $response->get_data(); if (! is_array($payload) || ! is_array($payload['data'] ?? null)) { return $response; }
        $data = $payload['data'];
        $data['media_ingest_control'] = array(
            'available'=>true,'transport_base'=>'/research-manager/v1/media/ingest','multipart_field'=>'file',
            'allowed_mime_types'=>array('image/jpeg','image/png','image/webp','application/pdf'),'max_bytes'=>10485760,
            'image_alt_required'=>true,'checksum'=>'sha256','duplicate_detection'=>'manager-ingest-checksum',
            'staging'=>'private-temporary-ttl-15m','staging_ttl_seconds'=>900,'preview_cancel'=>true,
            'provenance_required'=>true,'rollback_requires_unused_owned_attachment'=>true,
            'read_scope'=>'media.read','write_scopes'=>array('media.write','operations.apply'),'cancel_scope'=>'media.write','rollback_scopes'=>array('media.write','operations.rollback'),
            'lifecycle'=>array('plan','cancel','apply','status','verify','rollback'),'arbitrary_url_ingest'=>false,'arbitrary_filesystem_path'=>false,
            'stale_staging_protection'=>true,'idempotency'=>true,
        );
        $payload['data'] = $data; $response->set_data($payload); return $response;
    }

    private function permission(WP_REST_Request $request, string $primary, string $secondary = ''): bool|WP_Error {
        $ok = $this->auth->permission($request, $primary); if (is_wp_error($ok) || true !== $ok || '' === $secondary) { return $ok; }
        $credentials = Eduardo_Research_Manager::remote_credentials(); $state = $credentials->state();
        if ($credentials->has_scope($state, $secondary)) { return true; }
        Eduardo_Research_Manager::remote_audit()->record(array('request_id'=>(string) $request->get_header('x-research-manager-request-id'),'connection_id'=>(string) ($state['connection_id'] ?? ''),'wordpress_user_id'=>(int) ($state['wordpress_user_id'] ?? 0),'method'=>$request->get_method(),'route'=>$request->get_route(),'scope'=>$secondary,'event'=>'remote-request-rejected','outcome'=>'blocked','error_code'=>'scope_denied'));
        return new WP_Error('scope_denied', sprintf('Remote Manager connection does not grant %s.', $secondary), array('status'=>403));
    }

    private function idem(WP_REST_Request $request, callable $callback): WP_REST_Response|WP_Error {
        $state = Eduardo_Research_Manager::remote_credentials()->state(); $connection_id = (string) ($state['connection_id'] ?? ''); $request_id = trim((string) $request->get_header('x-research-manager-request-id'));
        $guard = Eduardo_Research_Manager::remote_guard(); $fingerprint = $guard->request_fingerprint($request); $previous = $guard->validate_idempotency($connection_id, $request_id, $fingerprint);
        if (is_wp_error($previous)) { return $previous; }
        if (is_array($previous)) { return new WP_REST_Response(array('ok'=>true,'request_id'=>$request_id,'idempotent_replay'=>true,'data'=>(array) ($previous['result'] ?? array())), 200); }
        $result = $callback(); if (is_wp_error($result)) { return $result; } $result = is_array($result) ? $result : array('result'=>$result); $guard->remember_request($connection_id, $request_id, $fingerprint, $result);
        return new WP_REST_Response(array('ok'=>true,'request_id'=>$request_id,'idempotent_replay'=>false,'data'=>$result), 200);
    }

    private function actor(WP_REST_Request $request): array { $state = Eduardo_Research_Manager::remote_credentials()->state(); return array('connection_id'=>(string) ($state['connection_id'] ?? ''),'wordpress_user_id'=>get_current_user_id(),'request_id'=>(string) $request->get_header('x-research-manager-request-id')); }
    private function json(WP_REST_Request $request): array { $params = $request->get_json_params(); if (! is_array($params)) { $params = $request->get_body_params(); } return is_array($params) ? $params : array(); }
    private function response(WP_REST_Request $request, array $data, bool $idempotent = false): WP_REST_Response { return new WP_REST_Response(array('ok'=>true,'request_id'=>(string) $request->get_header('x-research-manager-request-id'),'idempotent_replay'=>$idempotent,'data'=>$data), 200); }
}