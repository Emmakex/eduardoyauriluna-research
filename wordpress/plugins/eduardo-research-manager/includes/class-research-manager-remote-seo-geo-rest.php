<?php
/** Authenticated M6 SEO/GEO diagnostics, planning and exact remediation gateway. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Remote_SEO_GEO_REST {
    private Eduardo_Research_Manager_Remote_REST $auth;
    private Eduardo_Research_Manager_SEO_GEO $seo_geo;
    private Eduardo_Research_Manager_SEO_GEO_Action_Planner $action_planner;
    private Eduardo_Research_Manager_Remote_SEO_GEO_Operations $operations;

    public function __construct(
        ?Eduardo_Research_Manager_Remote_REST $auth = null,
        ?Eduardo_Research_Manager_SEO_GEO $seo_geo = null,
        ?Eduardo_Research_Manager_Remote_SEO_GEO_Operations $operations = null,
        ?Eduardo_Research_Manager_SEO_GEO_Action_Planner $action_planner = null
    ) {
        $this->auth = $auth ?: Eduardo_Research_Manager::remote_rest();
        $this->seo_geo = $seo_geo ?: new Eduardo_Research_Manager_SEO_GEO();
        $this->operations = $operations ?: new Eduardo_Research_Manager_Remote_SEO_GEO_Operations();
        $this->action_planner = $action_planner ?: new Eduardo_Research_Manager_SEO_GEO_Action_Planner($this->seo_geo);
    }

    public function register(): void {
        add_action('rest_api_init', array($this, 'register_routes'));
        add_filter('rest_request_after_callbacks', array($this, 'augment_capabilities'), 10, 3);
    }

    public function register_routes(): void {
        $ns = Eduardo_Research_Manager_Remote_REST::NAMESPACE;
        register_rest_route($ns, '/seo-geo/site', array(
            'methods'=>WP_REST_Server::READABLE,
            'callback'=>array($this, 'site'),
            'permission_callback'=>fn(WP_REST_Request $request) => $this->permission($request, 'site.diagnostics', 'seo.read'),
        ));
        register_rest_route($ns, '/seo-geo/resource', array(
            'methods'=>WP_REST_Server::READABLE,
            'callback'=>array($this, 'resource'),
            'permission_callback'=>fn(WP_REST_Request $request) => $this->permission($request, 'site.diagnostics', 'seo.read'),
        ));
        register_rest_route($ns, '/seo-geo/resource/actions', array(
            'methods'=>WP_REST_Server::READABLE,
            'callback'=>array($this, 'resource_actions'),
            'permission_callback'=>fn(WP_REST_Request $request) => $this->permission($request, 'site.diagnostics', 'seo.read'),
        ));
        register_rest_route($ns, '/seo-geo/remediation/plan', array(
            'methods'=>WP_REST_Server::CREATABLE,
            'callback'=>array($this, 'create_plan'),
            'permission_callback'=>fn(WP_REST_Request $request) => $this->permission($request, 'operations.apply', 'seo.write'),
        ));
        register_rest_route($ns, '/seo-geo/plans/(?P<plan_id>[A-Za-z0-9._:-]+)', array(
            'methods'=>WP_REST_Server::READABLE,
            'callback'=>array($this, 'get_plan'),
            'permission_callback'=>fn(WP_REST_Request $request) => $this->permission($request, 'seo.read'),
        ));
        register_rest_route($ns, '/seo-geo/plans/(?P<plan_id>[A-Za-z0-9._:-]+)/apply', array(
            'methods'=>WP_REST_Server::CREATABLE,
            'callback'=>array($this, 'apply_plan'),
            'permission_callback'=>fn(WP_REST_Request $request) => $this->permission($request, 'operations.apply', 'seo.write'),
        ));
        register_rest_route($ns, '/seo-geo/operations/(?P<operation_id>[A-Za-z0-9._:-]+)', array(
            'methods'=>WP_REST_Server::READABLE,
            'callback'=>array($this, 'get_operation'),
            'permission_callback'=>fn(WP_REST_Request $request) => $this->permission($request, 'seo.read'),
        ));
        register_rest_route($ns, '/seo-geo/operations/(?P<operation_id>[A-Za-z0-9._:-]+)/verify', array(
            'methods'=>WP_REST_Server::CREATABLE,
            'callback'=>array($this, 'verify_operation'),
            'permission_callback'=>fn(WP_REST_Request $request) => $this->permission($request, 'site.diagnostics', 'seo.read'),
        ));
        register_rest_route($ns, '/seo-geo/operations/(?P<operation_id>[A-Za-z0-9._:-]+)/rollback', array(
            'methods'=>WP_REST_Server::CREATABLE,
            'callback'=>array($this, 'rollback_operation'),
            'permission_callback'=>fn(WP_REST_Request $request) => $this->permission($request, 'operations.rollback', 'seo.write'),
        ));
    }

    public function site(WP_REST_Request $request): WP_REST_Response {
        return $this->response($request, $this->seo_geo->site());
    }

    public function resource(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $target = $this->resource_target($request);
        if (is_wp_error($target)) { return $target; }
        $result = $this->seo_geo->inspect_resource($target['type'], $target['identifier'], $target['language']);
        return is_wp_error($result) ? $result : $this->response($request, $result);
    }

    public function resource_actions(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $target = $this->resource_target($request);
        if (is_wp_error($target)) { return $target; }
        $result = $this->action_planner->plan($target['type'], $target['identifier'], $target['language']);
        return is_wp_error($result) ? $result : $this->response($request, $result);
    }

    public function create_plan(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $params = $this->params($request);
        return $this->idempotent($request, function () use ($params, $request) {
            return $this->operations->create_plan(
                sanitize_key((string) ($params['check_id'] ?? '')),
                sanitize_text_field((string) ($params['intent'] ?? '')),
                $this->actor($request)
            );
        });
    }

    public function get_plan(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $result = $this->operations->get_plan((string) $request['plan_id']);
        return is_wp_error($result) ? $result : $this->response($request, $result);
    }

    public function apply_plan(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $params = $this->params($request);
        return $this->idempotent($request, fn() => $this->operations->apply((string) $request['plan_id'], ! empty($params['confirm']), $this->actor($request)));
    }

    public function get_operation(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $result = $this->operations->get_operation((string) $request['operation_id']);
        return is_wp_error($result) ? $result : $this->response($request, $result);
    }

    public function verify_operation(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $params = $this->params($request);
        return $this->idempotent($request, fn() => $this->operations->verify((string) $request['operation_id'], ! empty($params['rendered']), $this->actor($request)));
    }

    public function rollback_operation(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $params = $this->params($request);
        return $this->idempotent($request, fn() => $this->operations->rollback((string) $request['operation_id'], ! empty($params['confirm']), $this->actor($request)));
    }

    public function augment_capabilities(mixed $response, mixed $handler, WP_REST_Request $request): mixed {
        if ('/' . Eduardo_Research_Manager_Remote_REST::NAMESPACE . '/capabilities' !== $request->get_route()) { return $response; }
        if (is_wp_error($response) || ! $response instanceof WP_REST_Response) { return $response; }
        $payload = $response->get_data();
        if (! is_array($payload) || ! is_array($payload['data'] ?? null)) { return $response; }
        $data = $payload['data'];
        $read = is_array($data['read_endpoints'] ?? null) ? $data['read_endpoints'] : array();
        $read['seo_geo_site'] = 'site.diagnostics + seo.read';
        $read['seo_geo_resource'] = 'site.diagnostics + seo.read';
        $read['seo_geo_resource_actions'] = 'site.diagnostics + seo.read';
        $data['read_endpoints'] = $read;
        $data['seo_geo_control'] = array(
            'milestone'=>'M6',
            'slice'=>'diagnostics-remediation-and-structured-action-planning',
            'available'=>true,
            'site_diagnostics'=>true,
            'resource_rendered_inspection'=>array('page','insight','line','output','project','software','dataset'),
            'rendered_checks'=>array('http_200','canonical','html_language','current_hreflang','og_url','json_ld','schema_type'),
            'structured_action_planner'=>true,
            'planner_dimensions'=>array('seo','geo','provenance','seo+geo','geo+provenance','seo+geo+provenance'),
            'planner_delegates_to'=>array('pages','insights','insight-translations','research-lines','research-objects','research-translations','research-evidence','seo-geo'),
            'operations'=>array('seo-remediate'),
            'lifecycle'=>array('plan','apply','status','verify','rollback'),
            'read_scope'=>'seo.read',
            'write_scope'=>'seo.write',
            'exact_plan_required'=>true,
            'stale_target_protection'=>true,
            'idempotency'=>true,
            'current_remediation_boundary'=>'Diagnostics findings already classified auto-remediable by the shared Remediation service.',
            'structured_optimization_boundary'=>'SEO/GEO source findings are routed to existing bounded Manager gateways instead of direct metadata writes.',
            'advanced_indexability_and_link_remediation'=>'later-m6-slice',
            'arbitrary_meta_editor'=>false,
            'arbitrary_wordpress_proxy'=>false,
        );
        $payload['data'] = $data;
        $response->set_data($payload);
        return $response;
    }

    private function resource_target(WP_REST_Request $request): array|WP_Error {
        $type = sanitize_key((string) $request->get_param('type'));
        $identifier = $request->get_param('id');
        if (null === $identifier || '' === (string) $identifier) { $identifier = (string) $request->get_param('key'); }
        $language = sanitize_key((string) ($request->get_param('language') ?: ''));
        if ('' === $type || null === $identifier || '' === (string) $identifier) {
            return new WP_Error('validation_failed', 'SEO/GEO resource inspection requires type plus id or key.', array('status'=>400));
        }
        return array(
            'type'=>$type,
            'identifier'=>is_numeric($identifier) ? (int) $identifier : (string) $identifier,
            'language'=>$language,
        );
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
        return new WP_REST_Response(array('ok'=>true,'request_id'=>$request_id,'idempotent_replay'=>false,'data'=>$result), 200);
    }

    private function actor(WP_REST_Request $request): array {
        $connection = Eduardo_Research_Manager::remote_credentials()->state();
        return array('connection_id'=>(string)($connection['connection_id']??''),'wordpress_user_id'=>get_current_user_id(),'request_id'=>(string)$request->get_header('x-research-manager-request-id'));
    }

    private function params(WP_REST_Request $request): array {
        $params = $request->get_json_params(); if (! is_array($params)) { $params = $request->get_body_params(); } return is_array($params) ? $params : array();
    }

    private function response(WP_REST_Request $request, array $data): WP_REST_Response {
        return new WP_REST_Response(array('ok'=>true,'request_id'=>(string)$request->get_header('x-research-manager-request-id'),'data'=>$data),200);
    }
}
