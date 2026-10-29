<?php
/** Authenticated remote gateway for the canonical Researcher Identity profile. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Remote_Identity_Profile_REST {
    private Eduardo_Research_Manager_Remote_REST $auth;
    private Eduardo_Research_Manager_Remote_Identity_Profile_Operations $operations;

    public function __construct(
        ?Eduardo_Research_Manager_Remote_REST $auth = null,
        ?Eduardo_Research_Manager_Remote_Identity_Profile_Operations $operations = null
    ) {
        $this->auth = $auth ?: Eduardo_Research_Manager::remote_rest();
        $this->operations = $operations ?: new Eduardo_Research_Manager_Remote_Identity_Profile_Operations();
    }

    public function register(): void {
        add_action('rest_api_init',array($this,'register_routes'));
        add_filter('rest_request_after_callbacks',array($this,'augment_capabilities'),20,3);
    }

    public function register_routes(): void {
        $ns=Eduardo_Research_Manager_Remote_REST::NAMESPACE;
        register_rest_route($ns,'/identity-profile',array('methods'=>WP_REST_Server::READABLE,'callback'=>array($this,'inspect'),'permission_callback'=>fn(WP_REST_Request $r)=>$this->permission($r,'evidence.read')));
        register_rest_route($ns,'/identity-profile/plan',array('methods'=>WP_REST_Server::CREATABLE,'callback'=>array($this,'create_plan'),'permission_callback'=>fn(WP_REST_Request $r)=>$this->permission($r,'operations.apply','evidence.write','identity.write')));
        register_rest_route($ns,'/identity-profile/plans/(?P<plan_id>[A-Za-z0-9._:-]+)',array('methods'=>WP_REST_Server::READABLE,'callback'=>array($this,'get_plan'),'permission_callback'=>fn(WP_REST_Request $r)=>$this->permission($r,'evidence.read')));
        register_rest_route($ns,'/identity-profile/plans/(?P<plan_id>[A-Za-z0-9._:-]+)/apply',array('methods'=>WP_REST_Server::CREATABLE,'callback'=>array($this,'apply_plan'),'permission_callback'=>fn(WP_REST_Request $r)=>$this->permission($r,'operations.apply','evidence.write','identity.write')));
        register_rest_route($ns,'/identity-profile/operations/(?P<operation_id>[A-Za-z0-9._:-]+)',array('methods'=>WP_REST_Server::READABLE,'callback'=>array($this,'get_operation'),'permission_callback'=>fn(WP_REST_Request $r)=>$this->permission($r,'evidence.read')));
        register_rest_route($ns,'/identity-profile/operations/(?P<operation_id>[A-Za-z0-9._:-]+)/verify',array('methods'=>WP_REST_Server::CREATABLE,'callback'=>array($this,'verify_operation'),'permission_callback'=>fn(WP_REST_Request $r)=>$this->permission($r,'site.diagnostics','evidence.read')));
        register_rest_route($ns,'/identity-profile/operations/(?P<operation_id>[A-Za-z0-9._:-]+)/rollback',array('methods'=>WP_REST_Server::CREATABLE,'callback'=>array($this,'rollback_operation'),'permission_callback'=>fn(WP_REST_Request $r)=>$this->permission($r,'operations.rollback','evidence.write','identity.write')));
    }

    public function inspect(WP_REST_Request $r): WP_REST_Response|WP_Error { $x=(new Eduardo_Research_Manager_Identity_Profile())->inspect(); return is_wp_error($x)?$x:$this->response($r,$x); }
    public function create_plan(WP_REST_Request $r): WP_REST_Response|WP_Error { $p=$this->json($r); return $this->idem($r,fn()=>$this->operations->create_plan($p,$this->actor($r))); }
    public function get_plan(WP_REST_Request $r): WP_REST_Response|WP_Error { $x=$this->operations->get_plan((string)$r['plan_id']); return is_wp_error($x)?$x:$this->response($r,$x); }
    public function apply_plan(WP_REST_Request $r): WP_REST_Response|WP_Error { $p=$this->json($r); return $this->idem($r,fn()=>$this->operations->apply((string)$r['plan_id'],!empty($p['confirm']),$this->actor($r))); }
    public function get_operation(WP_REST_Request $r): WP_REST_Response|WP_Error { $x=$this->operations->get_operation((string)$r['operation_id']); return is_wp_error($x)?$x:$this->response($r,$x); }
    public function verify_operation(WP_REST_Request $r): WP_REST_Response|WP_Error { return $this->idem($r,fn()=>$this->operations->verify((string)$r['operation_id'],$this->actor($r))); }
    public function rollback_operation(WP_REST_Request $r): WP_REST_Response|WP_Error { $p=$this->json($r); return $this->idem($r,fn()=>$this->operations->rollback((string)$r['operation_id'],!empty($p['confirm']),$this->actor($r))); }

    public function augment_capabilities(mixed $response,mixed $handler,WP_REST_Request $request): mixed {
        if('/'.Eduardo_Research_Manager_Remote_REST::NAMESPACE.'/capabilities'!==$request->get_route()||is_wp_error($response)||!$response instanceof WP_REST_Response){return $response;}
        $payload=$response->get_data();if(!is_array($payload)||!is_array($payload['data']??null)){return $response;}
        $descriptor=(new Eduardo_Research_Manager_Identity_Profile())->contract_descriptor();
        $available=!is_wp_error($descriptor);
        $fields=$available&&is_array($descriptor['fields']??null)?array_values($descriptor['fields']):array();
        $languages=$available&&is_array($descriptor['languages']??null)?array_values($descriptor['languages']):array();
        $payload['data']['identity_profile_control']=array(
            'available'=>$available,'transport_base'=>'/research-manager/v1/identity-profile','fields'=>$fields,'languages'=>$languages,
            'read_scope'=>'evidence.read','write_scopes'=>array('identity.write','evidence.write','operations.apply'),'rollback_scopes'=>array('identity.write','evidence.write','operations.rollback'),
            'lifecycle'=>array('inspect','plan','apply','status','verify','rollback'),'evidence_required'=>true,'stale_preview_protection'=>true,'idempotency'=>true,
            'theme_canonical_source'=>Eduardo_Research_Manager_Identity_Profile::option_key(),'arbitrary_wordpress_profile_proxy'=>false,
        );
        $response->set_data($payload);return $response;
    }

    private function permission(WP_REST_Request $r,string ...$scopes): bool|WP_Error {
        if(!$scopes){return true;}
        $ok=$this->auth->permission($r,array_shift($scopes));if(is_wp_error($ok)||true!==$ok){return $ok;}
        $credentials=Eduardo_Research_Manager::remote_credentials();$state=$credentials->state();
        foreach($scopes as $scope){if(!$credentials->has_scope($state,$scope)){return new WP_Error('scope_denied',sprintf('Remote Manager connection does not grant %s.',$scope),array('status'=>403));}}
        return true;
    }
    private function idem(WP_REST_Request $r,callable $cb): WP_REST_Response|WP_Error { $state=Eduardo_Research_Manager::remote_credentials()->state();$cid=(string)($state['connection_id']??'');$rid=trim((string)$r->get_header('x-research-manager-request-id'));$g=Eduardo_Research_Manager::remote_guard();$fp=$g->request_fingerprint($r);$prev=$g->validate_idempotency($cid,$rid,$fp);if(is_wp_error($prev)){return $prev;}if(is_array($prev)){return new WP_REST_Response(array('ok'=>true,'request_id'=>$rid,'idempotent_replay'=>true,'data'=>(array)($prev['result']??array())),200);} $x=$cb();if(is_wp_error($x)){return $x;}$x=is_array($x)?$x:array('result'=>$x);$g->remember_request($cid,$rid,$fp,$x);return new WP_REST_Response(array('ok'=>true,'request_id'=>$rid,'idempotent_replay'=>false,'data'=>$x),200); }
    private function actor(WP_REST_Request $r): array { $state=Eduardo_Research_Manager::remote_credentials()->state();return array('connection_id'=>(string)($state['connection_id']??''),'wordpress_user_id'=>get_current_user_id(),'request_id'=>(string)$r->get_header('x-research-manager-request-id')); }
    private function json(WP_REST_Request $r): array { $p=$r->get_json_params();if(!is_array($p)){$p=$r->get_body_params();}return is_array($p)?$p:array(); }
    private function response(WP_REST_Request $r,array $data): WP_REST_Response { return new WP_REST_Response(array('ok'=>true,'request_id'=>(string)$r->get_header('x-research-manager-request-id'),'data'=>$data),200); }
}
