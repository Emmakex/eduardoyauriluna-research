<?php
/** Authenticated read-only aggregate M6 SEO/GEO readiness endpoint. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

require_once EDUARDO_RESEARCH_MANAGER_DIR . 'includes/class-research-manager-seo-geo-readiness.php';

final class Eduardo_Research_Manager_Remote_SEO_GEO_Readiness_REST {
    private Eduardo_Research_Manager_Remote_REST $auth;
    private Eduardo_Research_Manager_SEO_GEO_Readiness $readiness;

    public function __construct(
        ?Eduardo_Research_Manager_Remote_REST $auth = null,
        ?Eduardo_Research_Manager_SEO_GEO_Readiness $readiness = null
    ) {
        $this->auth = $auth ?: Eduardo_Research_Manager::remote_rest();
        $this->readiness = $readiness ?: new Eduardo_Research_Manager_SEO_GEO_Readiness();
    }

    public function register(): void {
        add_action('rest_api_init', array($this, 'register_routes'));
        add_filter('rest_request_after_callbacks', array($this, 'augment_capabilities'), 30, 3);
    }

    public function register_routes(): void {
        register_rest_route(Eduardo_Research_Manager_Remote_REST::NAMESPACE, '/seo-geo/readiness', array(
            'methods'=>WP_REST_Server::READABLE,
            'callback'=>array($this, 'readiness'),
            'permission_callback'=>fn(WP_REST_Request $request) => $this->permission($request),
        ));
    }

    public function readiness(WP_REST_Request $request): WP_REST_Response {
        $limit = absint($request->get_param('per_type_limit') ?: 1);
        $limit = max(1, min(5, $limit));
        return new WP_REST_Response(array(
            'ok'=>true,
            'request_id'=>(string) $request->get_header('x-research-manager-request-id'),
            'data'=>$this->readiness->report($limit),
        ), 200);
    }

    public function augment_capabilities(mixed $response, mixed $handler, WP_REST_Request $request): mixed {
        if ('/' . Eduardo_Research_Manager_Remote_REST::NAMESPACE . '/capabilities' !== $request->get_route()) { return $response; }
        if (is_wp_error($response) || ! $response instanceof WP_REST_Response) { return $response; }
        $payload = $response->get_data();
        if (! is_array($payload) || ! is_array($payload['data'] ?? null)) { return $response; }

        $data = $payload['data'];
        $read = is_array($data['read_endpoints'] ?? null) ? $data['read_endpoints'] : array();
        $read['seo_geo_readiness'] = 'site.diagnostics + seo.read';
        $data['read_endpoints'] = $read;

        $control = is_array($data['seo_geo_control'] ?? null) ? $data['seo_geo_control'] : array();
        $control['aggregate_readiness'] = array(
            'available'=>true,
            'read_only'=>true,
            'representative_sampling'=>true,
            'max_per_type'=>5,
            'includes'=>array(
                'site-diagnostics',
                'indexability-robots-sitemap',
                'internal-research-graph',
                'representative-rendered-resource-checks',
                'residual-action-classification',
            ),
            'residual_groups'=>array('safe_remediation','structured_input','evidence_required','manual_review'),
            'direct_mutation'=>false,
        );
        $control['aggregate_geo_readiness'] = 'available';
        $control['repository_ci_m6_complete_when'] = 'aggregate-readiness-acceptance-green';
        $data['seo_geo_control'] = $control;

        $payload['data'] = $data;
        $response->set_data($payload);
        return $response;
    }

    private function permission(WP_REST_Request $request): bool|WP_Error {
        $allowed = $this->auth->permission($request, 'site.diagnostics');
        if (is_wp_error($allowed) || true !== $allowed) { return $allowed; }
        $connection = Eduardo_Research_Manager::remote_credentials()->state();
        if (! Eduardo_Research_Manager::remote_credentials()->has_scope($connection, 'seo.read')) {
            return new WP_Error('scope_denied', 'Remote Manager connection does not grant seo.read.', array('status'=>403));
        }
        return true;
    }
}
