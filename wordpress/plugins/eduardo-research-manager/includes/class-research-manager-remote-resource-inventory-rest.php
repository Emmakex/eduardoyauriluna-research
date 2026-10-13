<?php
/** Authenticated typed Manager resource catalog/list/get surface. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Remote_Resource_Inventory_REST {
    private Eduardo_Research_Manager_Remote_REST $auth;
    private Eduardo_Research_Manager_Resource_Inventory $inventory;

    public function __construct(?Eduardo_Research_Manager_Remote_REST $auth=null,?Eduardo_Research_Manager_Resource_Inventory $inventory=null){$this->auth=$auth?:Eduardo_Research_Manager::remote_rest();$this->inventory=$inventory?:new Eduardo_Research_Manager_Resource_Inventory();}
    public function register(): void {add_action('rest_api_init',array($this,'register_routes'));add_filter('rest_request_after_callbacks',array($this,'augment_capabilities'),10,3);}
    public function register_routes(): void {
        $ns=Eduardo_Research_Manager_Remote_REST::NAMESPACE;
        register_rest_route($ns,'/resources',array('methods'=>WP_REST_Server::READABLE,'callback'=>array($this,'catalog'),'permission_callback'=>fn(WP_REST_Request $r)=>$this->auth->permission($r,'site.read')));
        register_rest_route($ns,'/resources/(?P<type>pages|insights|lines|outputs|projects|software|datasets)',array('methods'=>WP_REST_Server::READABLE,'callback'=>array($this,'listing'),'permission_callback'=>fn(WP_REST_Request $r)=>$this->type_permission($r)));
        register_rest_route($ns,'/resources/(?P<type>pages|insights|lines|outputs|projects|software|datasets)/(?P<identifier>[A-Za-z0-9._:-]+)',array('methods'=>WP_REST_Server::READABLE,'callback'=>array($this,'item'),'permission_callback'=>fn(WP_REST_Request $r)=>$this->type_permission($r)));
    }
    public function catalog(WP_REST_Request $request): WP_REST_Response {return $this->response($request,$this->inventory->catalog());}
    public function listing(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $result=$this->inventory->list((string) $request['type'],array('page'=>(int) ($request->get_param('page')?:1),'per_page'=>(int) ($request->get_param('per_page')?:20),'language'=>(string) ($request->get_param('language')?:''),'status'=>(string) ($request->get_param('status')?:'')));
        return is_wp_error($result)?$result:$this->response($request,$result);
    }
    public function item(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $result=$this->inventory->get((string) $request['type'],(string) $request['identifier'],array('language'=>(string) ($request->get_param('language')?:'')));
        return is_wp_error($result)?$result:$this->response($request,$result);
    }
    public function augment_capabilities(mixed $response,mixed $handler,WP_REST_Request $request): mixed {
        if('/'.Eduardo_Research_Manager_Remote_REST::NAMESPACE.'/capabilities'!==$request->get_route()||is_wp_error($response)||!$response instanceof WP_REST_Response){return $response;}
        $payload=$response->get_data();if(!is_array($payload)||!is_array($payload['data']??null)){return $response;}
        $payload['data']['resource_inventory']=array(
            'available'=>true,'catalog'=>'/research-manager/v1/resources','list'=>'/research-manager/v1/resources/{type}','get'=>'/research-manager/v1/resources/{type}/{identifier}',
            'types'=>$this->inventory->types(),'languages'=>Eduardo_Research_Manager::contract()->languages(),'language_filter'=>true,'max_per_page'=>50,'arbitrary_wordpress_proxy'=>false,
        );
        $response->set_data($payload);return $response;
    }
    private function type_permission(WP_REST_Request $request): bool|WP_Error {$type=sanitize_key((string) $request['type']);$types=$this->inventory->types();if(!isset($types[$type])){return new WP_Error('validation_failed','Unsupported Manager resource type.',array('status'=>400));}return $this->auth->permission($request,(string) $types[$type]['scope']);}
    private function response(WP_REST_Request $request,array $data): WP_REST_Response {return new WP_REST_Response(array('ok'=>true,'request_id'=>(string) $request->get_header('x-research-manager-request-id'),'data'=>$data),200);}
}
