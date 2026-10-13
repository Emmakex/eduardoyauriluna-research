<?php
/** Authenticated read-only inventory over all typed Manager operation lifecycles. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Remote_Operation_Inventory_REST {
    private Eduardo_Research_Manager_Remote_REST $auth;
    private Eduardo_Research_Manager_Remote_Operation_Inventory $inventory;

    public function __construct(?Eduardo_Research_Manager_Remote_REST $auth = null, ?Eduardo_Research_Manager_Remote_Operation_Inventory $inventory = null) {
        $this->auth = $auth ?: Eduardo_Research_Manager::remote_rest();
        $this->inventory = $inventory ?: new Eduardo_Research_Manager_Remote_Operation_Inventory();
    }

    public function register(): void {
        add_action('rest_api_init', array($this, 'register_routes'));
        add_filter('rest_request_after_callbacks', array($this, 'augment_capabilities'), 10, 3);
    }

    public function register_routes(): void {
        register_rest_route(Eduardo_Research_Manager_Remote_REST::NAMESPACE, '/operations', array(
            'methods'=>WP_REST_Server::READABLE,
            'callback'=>array($this, 'index'),
            'permission_callback'=>fn(WP_REST_Request $request)=>$this->auth->permission($request, 'site.diagnostics'),
            'args'=>array(
                'page'=>array('type'=>'integer','minimum'=>1,'default'=>1),
                'per_page'=>array('type'=>'integer','minimum'=>1,'maximum'=>100,'default'=>50),
                'status'=>array('type'=>'string'),
                'operation'=>array('type'=>'string'),
                'connection_id'=>array('type'=>'string'),
            ),
        ));
    }

    public function index(WP_REST_Request $request): WP_REST_Response {
        $data = $this->inventory->query(array(
            'page'=>(int) ($request->get_param('page') ?: 1),
            'per_page'=>(int) ($request->get_param('per_page') ?: 50),
            'status'=>(string) ($request->get_param('status') ?: ''),
            'operation'=>(string) ($request->get_param('operation') ?: ''),
            'connection_id'=>(string) ($request->get_param('connection_id') ?: ''),
        ));
        return new WP_REST_Response(array('ok'=>true,'request_id'=>(string) $request->get_header('x-research-manager-request-id'),'data'=>$data), 200);
    }

    public function augment_capabilities(mixed $response, mixed $handler, WP_REST_Request $request): mixed {
        if ('/' . Eduardo_Research_Manager_Remote_REST::NAMESPACE . '/capabilities' !== $request->get_route() || is_wp_error($response) || ! $response instanceof WP_REST_Response) { return $response; }
        $payload = $response->get_data();
        if (! is_array($payload) || ! is_array($payload['data'] ?? null)) { return $response; }
        $payload['data']['operation_inventory'] = array(
            'available'=>true,
            'read_only'=>true,
            'transport'=>'/research-manager/v1/operations',
            'scope'=>'site.diagnostics',
            'max_page_size'=>100,
            'statuses'=>array('pending-verification','verified','verification-failed','rolled-back'),
            'filters'=>array('status','operation','connection_id'),
            'cross_lifecycle'=>true,
            'secret_fields_exposed'=>false,
        );
        $response->set_data($payload);
        return $response;
    }
}
