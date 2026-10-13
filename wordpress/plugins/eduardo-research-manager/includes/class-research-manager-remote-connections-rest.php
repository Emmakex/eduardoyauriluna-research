<?php
/** Safe read-only academic connection visibility and existing adapter previews. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Remote_Connections_REST {
    private Eduardo_Research_Manager_Remote_REST $auth;

    public function __construct(?Eduardo_Research_Manager_Remote_REST $auth = null) {
        $this->auth = $auth ?: Eduardo_Research_Manager::remote_rest();
    }

    public function register(): void {
        add_action('rest_api_init',array($this,'register_routes'));
        add_filter('rest_request_after_callbacks',array($this,'augment_capabilities'),10,3);
    }

    public function register_routes(): void {
        $ns=Eduardo_Research_Manager_Remote_REST::NAMESPACE;
        register_rest_route($ns,'/connections',array('methods'=>WP_REST_Server::READABLE,'callback'=>array($this,'overview'),'permission_callback'=>fn(WP_REST_Request $r)=>$this->auth->permission($r,'connections.read')));
        register_rest_route($ns,'/connections/(?P<provider>orcid|google_scholar|crossref|zenodo|openalex|github)',array('methods'=>WP_REST_Server::READABLE,'callback'=>array($this,'provider'),'permission_callback'=>fn(WP_REST_Request $r)=>$this->auth->permission($r,'connections.read')));
        register_rest_route($ns,'/connections/(?P<provider>orcid|crossref|openalex)/preview',array('methods'=>WP_REST_Server::CREATABLE,'callback'=>array($this,'preview'),'permission_callback'=>fn(WP_REST_Request $r)=>$this->auth->permission($r,'connections.read')));
    }

    public function overview(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $overview=Eduardo_Research_Manager::connections()->overview();
        $statuses=is_array($overview['providers']??null)?$overview['providers']:array();
        $items=array();
        foreach(array_keys(Eduardo_Research_Manager::connections()->providers()) as $provider){$items[$provider]=$this->provider_state($provider,is_array($statuses[$provider]??null)?$statuses[$provider]:array());}
        return $this->response($request,array('providers'=>$items,'summary'=>is_array($overview['summary']??null)?$overview['summary']:array(),'count'=>count($items),'read_only'=>true,'secrets_exposed'=>false,'external_write'=>false));
    }

    public function provider(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $provider=sanitize_key((string) $request['provider']);
        $status=Eduardo_Research_Manager::connections()->status($provider); if(is_wp_error($status)){return $status;}
        return $this->response($request,$this->provider_state($provider,$status));
    }

    public function preview(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $provider=sanitize_key((string) $request['provider']); $params=$this->json($request);
        if('orcid'===$provider){$result=Eduardo_Research_Manager::orcid()->sync_preview();}
        elseif('openalex'===$provider){$result=Eduardo_Research_Manager::openalex()->preview_from_verified_orcid(max(1,min(100,(int) ($params['per_page']??100))));}
        elseif('crossref'===$provider){
            $post_id=absint($params['post_id']??0);$doi=is_scalar($params['doi']??null)?(string) $params['doi']:'';
            if($post_id<=0){return new WP_Error('validation_failed','Crossref preview requires a managed Research Output post_id.',array('status'=>400));}
            $result=Eduardo_Research_Manager::crossref()->preview_for_output($post_id,$doi);
        }else{return new WP_Error('validation_failed','This provider does not expose a bounded remote preview.',array('status'=>400));}
        if(is_wp_error($result)){return $result;}
        return $this->response($request,array('provider'=>$provider,'preview'=>$this->safe_map($result),'external_write'=>false,'automatic_local_apply'=>false));
    }

    public function augment_capabilities(mixed $response,mixed $handler,WP_REST_Request $request): mixed {
        if('/'.Eduardo_Research_Manager_Remote_REST::NAMESPACE.'/capabilities'!==$request->get_route()||is_wp_error($response)||!$response instanceof WP_REST_Response){return $response;}
        $payload=$response->get_data();if(!is_array($payload)||!is_array($payload['data']??null)){return $response;}
        $payload['data']['academic_connections_control']=array(
            'available'=>true,'overview'=>'/research-manager/v1/connections','provider'=>'/research-manager/v1/connections/{provider}','preview'=>'/research-manager/v1/connections/{orcid|crossref|openalex}/preview',
            'read_scope'=>'connections.read','supported_providers'=>array('orcid','google_scholar','crossref','zenodo','openalex','github'),'remote_preview_providers'=>array('orcid','crossref','openalex'),
            'secrets_exposed'=>false,'oauth_completion_remote'=>false,'configuration_write_remote'=>false,'external_publish_remote'=>false,'arbitrary_http_proxy'=>false,
        );
        $response->set_data($payload);return $response;
    }

    private function provider_state(string $provider,array $status): array {
        $capabilities=array();$configuration=is_array($status['configuration']??null)?$status['configuration']:array();
        if('orcid'===$provider){$capabilities=Eduardo_Research_Manager::orcid()->capabilities();$configuration=Eduardo_Research_Manager::orcid()->configuration();}
        elseif('crossref'===$provider){$capabilities=Eduardo_Research_Manager::crossref()->capabilities();$configuration=Eduardo_Research_Manager::crossref()->configuration();}
        elseif('openalex'===$provider){$capabilities=Eduardo_Research_Manager::openalex()->capabilities();$configuration=Eduardo_Research_Manager::openalex()->configuration();}
        elseif('zenodo'===$provider){$capabilities=Eduardo_Research_Manager::zenodo()->capabilities();$configuration=Eduardo_Research_Manager::zenodo()->configuration();}
        elseif('github'===$provider){$capabilities=Eduardo_Research_Manager::github()->capabilities();$configuration=Eduardo_Research_Manager::github()->configuration();}
        return array('provider'=>$provider,'status'=>$this->safe_map($status),'capabilities'=>$this->safe_map($capabilities),'configuration'=>$this->safe_configuration($configuration),'secrets_exposed'=>false);
    }

    private function safe_configuration(array $configuration): array {
        $allowed=array('provider','configured','required','missing','environment','redirect_uri','client_id','client_secret_set','public_api','mailto_set','secrets_required','api_key_required','api_key_set','token_set','repository_set','owner_set','sandbox','external_write','secrets_exposed');
        return array_intersect_key($configuration,array_fill_keys($allowed,true));
    }
    private function safe_map(array $value): array {$out=array();foreach($value as $key=>$item){if(in_array(strtolower((string) $key),array('token','access_token','refresh_token','client_secret','secret','authorization','password'),true)){continue;}$out[$key]=is_array($item)?$this->safe_map($item):$item;}return $out;}
    private function json(WP_REST_Request $request): array {$params=$request->get_json_params();if(!is_array($params)){$params=$request->get_body_params();}return is_array($params)?$params:array();}
    private function response(WP_REST_Request $request,array $data): WP_REST_Response {return new WP_REST_Response(array('ok'=>true,'request_id'=>(string) $request->get_header('x-research-manager-request-id'),'data'=>$data),200);}
}
