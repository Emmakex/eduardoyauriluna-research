<?php
/** Authenticated read-only aggregate M7 design readiness endpoint. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Remote_Design_Readiness_REST {
    private Eduardo_Research_Manager_Remote_REST $auth;
    private Eduardo_Research_Manager_Design_Readiness $readiness;

    public function __construct(
        ?Eduardo_Research_Manager_Remote_REST $auth = null,
        ?Eduardo_Research_Manager_Design_Readiness $readiness = null
    ) {
        $this->auth = $auth ?: Eduardo_Research_Manager::remote_rest();
        $this->readiness = $readiness ?: new Eduardo_Research_Manager_Design_Readiness();
    }

    public function register(): void {
        add_action('rest_api_init', array($this, 'register_routes'));
        add_filter('rest_request_after_callbacks', array($this, 'augment_capabilities'), 50, 3);
    }

    public function register_routes(): void {
        register_rest_route(Eduardo_Research_Manager_Remote_REST::NAMESPACE, '/design/readiness', array(
            'methods'=>WP_REST_Server::READABLE,
            'callback'=>array($this, 'readiness'),
            'permission_callback'=>fn(WP_REST_Request $request) => $this->permission($request),
        ));
    }

    public function readiness(WP_REST_Request $request): WP_REST_Response {
        $raw = $request->get_param('rendered');
        $rendered = null === $raw ? true : ! in_array(strtolower((string) $raw), array('0','false','no','off'), true);
        return new WP_REST_Response(array(
            'ok'=>true,
            'request_id'=>(string) $request->get_header('x-research-manager-request-id'),
            'data'=>$this->readiness->report($rendered),
        ), 200);
    }

    public function augment_capabilities(mixed $response, mixed $handler, WP_REST_Request $request): mixed {
        if ('/' . Eduardo_Research_Manager_Remote_REST::NAMESPACE . '/capabilities' !== $request->get_route()) { return $response; }
        if (is_wp_error($response) || ! $response instanceof WP_REST_Response) { return $response; }
        $payload = $response->get_data();
        if (! is_array($payload) || ! is_array($payload['data'] ?? null)) { return $response; }

        $data = $payload['data'];
        $read = is_array($data['read_endpoints'] ?? null) ? $data['read_endpoints'] : array();
        $read['design_readiness'] = 'design.read';
        $data['read_endpoints'] = $read;

        $control = is_array($data['design_control'] ?? null) ? $data['design_control'] : array();
        $control['aggregate_readiness'] = array(
            'milestone'=>'M7c',
            'available'=>true,
            'read_only'=>true,
            'rendered_home_en_es'=>true,
            'includes'=>array(
                'active-palette-accessibility',
                'bounded-css-and-body-classes',
                'semantic-content-order-boundary',
                'responsive-mobile-guards',
                'reduced-motion-contract',
                'representative-rendered-design-state',
                'residual-design-actions',
            ),
            'residual_groups'=>array('bounded_change','manual_review'),
            'direct_mutation'=>false,
            'repository_ci_m7_complete_when'=>'rendered aggregate readiness acceptance green',
        );
        $data['design_control'] = $control;

        $payload['data'] = $data;
        $response->set_data($payload);
        return $response;
    }

    private function permission(WP_REST_Request $request): bool|WP_Error {
        return $this->auth->permission($request, 'design.read');
    }
}
