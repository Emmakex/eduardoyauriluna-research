<?php
/** Authenticated gateway for bounded Theme-owned Media assignment. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Remote_Media_Assignment_REST {
    private Eduardo_Research_Manager_Remote_REST $auth;
    private Eduardo_Research_Manager_Remote_Media_Assignment_Operations $operations;
    private Eduardo_Research_Manager_Media_Assignment_Editor $editor;

    public function __construct(
        ?Eduardo_Research_Manager_Remote_REST $auth = null,
        ?Eduardo_Research_Manager_Remote_Media_Assignment_Operations $operations = null,
        ?Eduardo_Research_Manager_Media_Assignment_Editor $editor = null
    ) {
        $this->auth = $auth ?: Eduardo_Research_Manager::remote_rest();
        $this->editor = $editor ?: new Eduardo_Research_Manager_Media_Assignment_Editor();
        $this->operations = $operations ?: new Eduardo_Research_Manager_Remote_Media_Assignment_Operations($this->editor);
    }

    public function register(): void {
        add_action('rest_api_init', array($this, 'register_routes'));
        add_filter('rest_request_after_callbacks', array($this, 'augment_capabilities'), 16, 3);
    }

    public function register_routes(): void {
        $namespace = Eduardo_Research_Manager_Remote_REST::NAMESPACE;
        register_rest_route($namespace, '/media/assignments', array(
            'methods'=>WP_REST_Server::READABLE,
            'callback'=>array($this, 'inspect_assignment'),
            'permission_callback'=>fn(WP_REST_Request $request) => $this->permission_inspection($request),
        ));
        register_rest_route($namespace, '/media/assignments/plan', array(
            'methods'=>WP_REST_Server::CREATABLE,
            'callback'=>array($this, 'create_plan'),
            'permission_callback'=>fn(WP_REST_Request $request) => $this->permission_plan($request),
        ));
        register_rest_route($namespace, '/media/assignments/plans/(?P<plan_id>[A-Za-z0-9._:-]+)', array(
            'methods'=>WP_REST_Server::READABLE,
            'callback'=>array($this, 'get_plan'),
            'permission_callback'=>fn(WP_REST_Request $request) => $this->permission_stored_plan($request, 'media.read', false),
        ));
        register_rest_route($namespace, '/media/assignments/plans/(?P<plan_id>[A-Za-z0-9._:-]+)/apply', array(
            'methods'=>WP_REST_Server::CREATABLE,
            'callback'=>array($this, 'apply_plan'),
            'permission_callback'=>fn(WP_REST_Request $request) => $this->permission_stored_plan($request, 'operations.apply', true),
        ));
        register_rest_route($namespace, '/media/assignments/operations/(?P<operation_id>[A-Za-z0-9._:-]+)', array(
            'methods'=>WP_REST_Server::READABLE,
            'callback'=>array($this, 'get_operation'),
            'permission_callback'=>fn(WP_REST_Request $request) => $this->permission_stored_operation($request, 'media.read', false),
        ));
        register_rest_route($namespace, '/media/assignments/operations/(?P<operation_id>[A-Za-z0-9._:-]+)/verify', array(
            'methods'=>WP_REST_Server::CREATABLE,
            'callback'=>array($this, 'verify_operation'),
            'permission_callback'=>fn(WP_REST_Request $request) => $this->permission_stored_operation($request, 'site.diagnostics', false),
        ));
        register_rest_route($namespace, '/media/assignments/operations/(?P<operation_id>[A-Za-z0-9._:-]+)/rollback', array(
            'methods'=>WP_REST_Server::CREATABLE,
            'callback'=>array($this, 'rollback_operation'),
            'permission_callback'=>fn(WP_REST_Request $request) => $this->permission_stored_operation($request, 'operations.rollback', true),
        ));
    }

    public function inspect_assignment(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $target = $this->target_from_request($request);
        $slot = sanitize_key((string) ($request->get_param('slot') ?: Eduardo_Research_Manager_Media_Assignment_Editor::SLOT));
        $state = $this->editor->inspect_assignment($target, $slot);
        if (is_wp_error($state)) { return $state; }
        $attachment_id = (int) ($state['attachment_id'] ?? 0);
        $attachment = null;
        if ($attachment_id > 0) {
            $inspected = (new Eduardo_Research_Manager_Media_Resource())->inspect($attachment_id);
            if (is_wp_error($inspected)) { return $inspected; }
            $attachment = array(
                'id'=>(int) ($inspected['id'] ?? 0),
                'title'=>(string) ($inspected['title'] ?? ''),
                'alt_text'=>(string) ($inspected['alt_text'] ?? ''),
                'mime_type'=>(string) ($inspected['mime_type'] ?? ''),
                'media_type'=>(string) ($inspected['media_type'] ?? ''),
                'source_url'=>(string) ($inspected['source_url'] ?? ''),
                'width'=>$inspected['width'] ?? null,
                'height'=>$inspected['height'] ?? null,
                'readiness'=>(array) ($inspected['readiness'] ?? array()),
            );
        }
        return $this->response($request, array(
            'target'=>(array) $state['target'],
            'resource_id'=>(string) $state['resource_id'],
            'url'=>(string) $state['url'],
            'language'=>(string) $state['language'],
            'slot'=>(string) $state['slot'],
            'attachment_id'=>$attachment_id,
            'attachment'=>$attachment,
            'target_revision'=>(string) $state['target_revision'],
            'read_only'=>true,
        ));
    }

    public function create_plan(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $params = $this->json($request);
        return $this->idem($request, fn() => $this->operations->create_plan(
            is_array($params['payload'] ?? null) ? $params['payload'] : $params,
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
        $rendered = ! array_key_exists('rendered', $params) || ! empty($params['rendered']);
        return $this->idem($request, fn() => $this->operations->verify(
            (string) $request['operation_id'],
            $rendered,
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
        $media['assignment'] = array(
            'available'=>true,
            'operation'=>'media-assignment',
            'slot'=>Eduardo_Research_Manager_Media_Assignment_Editor::SLOT,
            'attachment_type'=>'image',
            'targets'=>array(
                'page'=>array('write_scope'=>'pages.write','read_scope'=>'pages.read'),
                'insight'=>array('write_scope'=>'insights.write','read_scope'=>'insights.read'),
                'research'=>array(
                    'write_scope'=>'research.write','read_scope'=>'research.read',
                    'kinds'=>array('line','output','project','software','dataset'),
                ),
            ),
            'transport'=>array(
                'base'=>'/research-manager/v1/media/assignments',
                'lifecycle'=>array('inspect','plan','apply','status','verify','rollback'),
                'inspect_scopes'=>array('media.read','target.read'),
                'write_scopes'=>array('operations.apply','media.write','target.write'),
                'rollback_scopes'=>array('operations.rollback','media.write','target.write'),
                'verify_scopes'=>array('site.diagnostics','media.read','target.read'),
                'dual_resource_stale_protection'=>true,
                'rendered_verification'=>true,
                'idempotency'=>true,
            ),
            'arbitrary_post_meta'=>false,
            'layout_authority'=>'theme',
        );
        $data['media_control'] = $media;
        $payload['data'] = $data;
        $response->set_data($payload);
        return $response;
    }

    private function permission_inspection(WP_REST_Request $request): bool|WP_Error {
        $base = $this->auth->permission($request, 'media.read');
        if (is_wp_error($base) || true !== $base) { return $base; }
        $contract = $this->scope_contract_for_target($this->target_from_request($request));
        if (is_wp_error($contract)) { return $contract; }
        return $this->check_after_auth(array((string) $contract['read']));
    }

    private function permission_plan(WP_REST_Request $request): bool|WP_Error {
        $base = $this->auth->permission($request, 'operations.apply');
        if (is_wp_error($base) || true !== $base) { return $base; }
        $params = $this->json($request);
        $payload = is_array($params['payload'] ?? null) ? $params['payload'] : $params;
        $target = is_array($payload['target'] ?? null) ? $payload['target'] : array();
        $contract = $this->scope_contract_for_target($target);
        if (is_wp_error($contract)) { return $contract; }
        return $this->check_after_auth(array('media.write', (string) $contract['write']));
    }

    private function permission_stored_plan(WP_REST_Request $request, string $primary, bool $write): bool|WP_Error {
        $base = $this->auth->permission($request, $primary);
        if (is_wp_error($base) || true !== $base) { return $base; }
        $plan = $this->operations->get_plan((string) $request['plan_id']);
        if (is_wp_error($plan)) { return true; }
        $owner = $this->enforce_connection_owner((string) ($plan['connection_id'] ?? ''));
        if (is_wp_error($owner)) { return $owner; }
        $extra = $write
            ? array('media.write', (string) ($plan['semantic_scope'] ?? ''))
            : array((string) ($plan['read_scope'] ?? ''));
        return $this->check_after_auth($extra);
    }

    private function permission_stored_operation(
        WP_REST_Request $request,
        string $primary,
        bool $write
    ): bool|WP_Error {
        $base = $this->auth->permission($request, $primary);
        if (is_wp_error($base) || true !== $base) { return $base; }
        $operation = $this->operations->get_operation((string) $request['operation_id']);
        if (is_wp_error($operation)) { return true; }
        $owner = $this->enforce_connection_owner((string) ($operation['connection_id'] ?? ''));
        if (is_wp_error($owner)) { return $owner; }
        if ($write) {
            return $this->check_after_auth(array('media.write', (string) ($operation['semantic_scope'] ?? '')));
        }
        return $this->check_after_auth(array('media.read', (string) ($operation['read_scope'] ?? '')));
    }

    private function scope_contract_for_target(array $target): array|WP_Error {
        $type = sanitize_key((string) ($target['type'] ?? ''));
        if ('page' === $type) {
            return array('read'=>'pages.read', 'write'=>'pages.write');
        }
        if ('insight' === $type) {
            return array('read'=>'insights.read', 'write'=>'insights.write');
        }
        if ('research' === $type) {
            return array('read'=>'research.read', 'write'=>'research.write');
        }
        return new WP_Error(
            'validation_failed',
            'Media assignment target type must be page, insight or research.',
            array('status'=>400)
        );
    }

    private function enforce_connection_owner(string $stored_connection_id): bool|WP_Error {
        $state = Eduardo_Research_Manager::remote_credentials()->state();
        $current = (string) ($state['connection_id'] ?? '');
        if ('' !== $stored_connection_id && '' !== $current && hash_equals($stored_connection_id, $current)) {
            return true;
        }
        return new WP_Error('scope_denied', 'This Media assignment resource belongs to another connection.', array('status'=>403));
    }

    private function check_after_auth(array $scopes): bool|WP_Error {
        $credentials = Eduardo_Research_Manager::remote_credentials();
        $state = $credentials->state();
        foreach (array_values(array_unique(array_filter(array_map('strval', $scopes)))) as $scope) {
            if ($credentials->has_scope($state, $scope)) { continue; }
            return new WP_Error('scope_denied', sprintf('Remote Manager connection does not grant %s.', $scope), array('status'=>403));
        }
        return true;
    }

    private function target_from_request(WP_REST_Request $request): array {
        $type = sanitize_key((string) $request->get_param('type'));
        $target = array('type'=>$type);
        if ('page' === $type) {
            $target['key'] = sanitize_key((string) $request->get_param('key'));
            $target['language'] = sanitize_key((string) ($request->get_param('language') ?: 'en'));
        } elseif ('insight' === $type) {
            $target['post_id'] = absint($request->get_param('post_id'));
        } elseif ('research' === $type) {
            $target['kind'] = sanitize_key((string) $request->get_param('kind'));
            $target['post_id'] = absint($request->get_param('post_id'));
        }
        return $target;
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
