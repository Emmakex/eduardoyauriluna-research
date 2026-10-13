<?php
/** Authenticated read-only REST surface for bounded non-secret Manager audit correlation. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Remote_Audit_REST {
    private Eduardo_Research_Manager_Remote_Audit $audit;
    public function __construct(Eduardo_Research_Manager_Remote_Audit $audit){$this->audit=$audit;}
    public function register(): void {add_action('rest_api_init',array($this,'register_routes'));add_filter('rest_request_after_callbacks',array($this,'augment_capabilities'),10,3);}
    public function register_routes(): void {
        register_rest_route(Eduardo_Research_Manager_Remote_REST::NAMESPACE,'/audit',array(
            'methods'=>WP_REST_Server::READABLE,'callback'=>array($this,'index'),'permission_callback'=>fn(WP_REST_Request $r)=>Eduardo_Research_Manager::remote_rest()->permission($r,'site.diagnostics'),
            'args'=>array(
                'page'=>array('type'=>'integer','minimum'=>1,'default'=>1),'per_page'=>array('type'=>'integer','minimum'=>1,'maximum'=>100,'default'=>50),
                'request_id'=>array('type'=>'string'),'operation_id'=>array('type'=>'string'),'connection_id'=>array('type'=>'string'),'outcome'=>array('type'=>'string'),
                'operation'=>array('type'=>'string'),'apply_status'=>array('type'=>'string'),'rollback_status'=>array('type'=>'string'),'lifecycle_status'=>array('type'=>'string'),
            ),
        ));
    }
    public function index(WP_REST_Request $request): WP_REST_Response {
        $result=$this->audit->query(array(
            'page'=>(int) ($request->get_param('page')?:1),'per_page'=>(int) ($request->get_param('per_page')?:50),
            'request_id'=>(string) ($request->get_param('request_id')?:''),'operation_id'=>(string) ($request->get_param('operation_id')?:''),'connection_id'=>(string) ($request->get_param('connection_id')?:''),'outcome'=>(string) ($request->get_param('outcome')?:''),
            'operation'=>(string) ($request->get_param('operation')?:''),'apply_status'=>(string) ($request->get_param('apply_status')?:''),'rollback_status'=>(string) ($request->get_param('rollback_status')?:''),'lifecycle_status'=>(string) ($request->get_param('lifecycle_status')?:''),
        ));
        return new WP_REST_Response(array('ok'=>true,'request_id'=>(string) $request->get_header('x-research-manager-request-id'),'data'=>$result),200);
    }
    public function augment_capabilities(mixed $response,mixed $handler,WP_REST_Request $request): mixed {
        if('/'.Eduardo_Research_Manager_Remote_REST::NAMESPACE.'/capabilities'!==$request->get_route()||is_wp_error($response)||!$response instanceof WP_REST_Response){return $response;}
        $payload=$response->get_data();if(!is_array($payload)||!is_array($payload['data']??null)){return $response;}
        $data=$payload['data'];$read=is_array($data['read_endpoints']??null)?$data['read_endpoints']:array();$read['audit']='site.diagnostics';$data['read_endpoints']=$read;
        $data['audit_control']=array(
            'available'=>true,'read_only'=>true,'transport'=>'/research-manager/v1/audit','scope'=>'site.diagnostics','max_page_size'=>100,
            'filters'=>array('request_id','operation_id','connection_id','outcome','operation','apply_status','rollback_status','lifecycle_status'),
            'lifecycle_fields'=>array('operation','target','source_revision','resulting_revision','apply_status','lifecycle_status','stored_verification_status','rendered_verification_status','rollback_available','rollback_status'),
            'whitelist_only'=>true,'secret_fields_exposed'=>false,'remote_clear_delete'=>false,
        );
        $payload['data']=$data;$response->set_data($payload);return $response;
    }
}
