<?php
/** Authenticated remote gateway for first-class Research Lines. */
declare(strict_types=1);
if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Remote_Lines_REST {
    private Eduardo_Research_Manager_Remote_REST $auth;
    private Eduardo_Research_Manager_Remote_Line_Operations $operations;

    public function __construct(?Eduardo_Research_Manager_Remote_REST $auth=null, ?Eduardo_Research_Manager_Remote_Line_Operations $operations=null) {
        $this->auth=$auth?:Eduardo_Research_Manager::remote_rest();
        $this->operations=$operations?:new Eduardo_Research_Manager_Remote_Line_Operations();
    }

    public function register(): void { add_action('rest_api_init',array($this,'register_routes')); add_filter('rest_request_after_callbacks',array($this,'augment_capabilities'),10,3); }

    public function register_routes(): void {
        $ns=Eduardo_Research_Manager_Remote_REST::NAMESPACE;
        register_rest_route($ns,'/research-lines',array('methods'=>WP_REST_Server::READABLE,'callback'=>array($this,'inventory'),'permission_callback'=>fn(WP_REST_Request $r)=>$this->permission($r,'research.read')));
        register_rest_route($ns,'/research-lines/(?P<post_id>\d+)',array('methods'=>WP_REST_Server::READABLE,'callback'=>array($this,'inspect'),'permission_callback'=>fn(WP_REST_Request $r)=>$this->permission($r,'research.read')));
        register_rest_route($ns,'/research-lines/plan',array('methods'=>WP_REST_Server::CREATABLE,'callback'=>array($this,'create_plan'),'permission_callback'=>fn(WP_REST_Request $r)=>$this->permission($r,'operations.apply','research.write')));
        register_rest_route($ns,'/research-lines/plans/(?P<plan_id>[A-Za-z0-9._:-]+)',array('methods'=>WP_REST_Server::READABLE,'callback'=>array($this,'get_plan'),'permission_callback'=>fn(WP_REST_Request $r)=>$this->permission($r,'research.read')));
        register_rest_route($ns,'/research-lines/plans/(?P<plan_id>[A-Za-z0-9._:-]+)/apply',array('methods'=>WP_REST_Server::CREATABLE,'callback'=>array($this,'apply_plan'),'permission_callback'=>fn(WP_REST_Request $r)=>$this->permission($r,'operations.apply','research.write')));
        register_rest_route($ns,'/research-lines/operations/(?P<operation_id>[A-Za-z0-9._:-]+)',array('methods'=>WP_REST_Server::READABLE,'callback'=>array($this,'get_operation'),'permission_callback'=>fn(WP_REST_Request $r)=>$this->permission($r,'research.read')));
        register_rest_route($ns,'/research-lines/operations/(?P<operation_id>[A-Za-z0-9._:-]+)/verify',array('methods'=>WP_REST_Server::CREATABLE,'callback'=>array($this,'verify_operation'),'permission_callback'=>fn(WP_REST_Request $r)=>$this->permission($r,'site.diagnostics','research.read')));
        register_rest_route($ns,'/research-lines/operations/(?P<operation_id>[A-Za-z0-9._:-]+)/rollback',array('methods'=>WP_REST_Server::CREATABLE,'callback'=>array($this,'rollback_operation'),'permission_callback'=>fn(WP_REST_Request $r)=>$this->permission($r,'operations.rollback','research.write')));
    }

    public function inventory(WP_REST_Request $r): WP_REST_Response|WP_Error { $lang=sanitize_key((string)($r->get_param('language')?:'')); $result=Eduardo_Research_Manager::line_editor()->list($lang); return is_wp_error($result)?$result:$this->response($r,array('language'=>$lang,'count'=>count($result),'items'=>$result)); }
    public function inspect(WP_REST_Request $r): WP_REST_Response|WP_Error { $result=Eduardo_Research_Manager::line_editor()->inspect(absint($r['post_id'])); return is_wp_error($result)?$result:$this->response($r,$result); }

    public function create_plan(WP_REST_Request $r): WP_REST_Response|WP_Error { $p=$this->json($r); $op=sanitize_key(str_replace('.','-',strtolower(trim((string)($p['operation']??''))))); return $this->idem($r,fn()=>$this->operations->create_plan($op,is_array($p['payload']??null)?$p['payload']:array(),$this->actor($r))); }
    public function get_plan(WP_REST_Request $r): WP_REST_Response|WP_Error { $x=$this->operations->get_plan((string)$r['plan_id']); return is_wp_error($x)?$x:$this->response($r,$x); }
    public function apply_plan(WP_REST_Request $r): WP_REST_Response|WP_Error { $p=$this->json($r); return $this->idem($r,fn()=>$this->operations->apply((string)$r['plan_id'],!empty($p['confirm']),$this->actor($r))); }
    public function get_operation(WP_REST_Request $r): WP_REST_Response|WP_Error { $x=$this->operations->get_operation((string)$r['operation_id']); return is_wp_error($x)?$x:$this->response($r,$x); }
    public function verify_operation(WP_REST_Request $r): WP_REST_Response|WP_Error { $p=$this->json($r); return $this->idem($r,fn()=>$this->operations->verify((string)$r['operation_id'],!empty($p['rendered']),$this->actor($r))); }
    public function rollback_operation(WP_REST_Request $r): WP_REST_Response|WP_Error { $p=$this->json($r); return $this->idem($r,fn()=>$this->operations->rollback((string)$r['operation_id'],!empty($p['confirm']),$this->actor($r))); }

    public function augment_capabilities(mixed $response,mixed $handler,WP_REST_Request $request): mixed {
        if ('/'.Eduardo_Research_Manager_Remote_REST::NAMESPACE.'/capabilities'!==$request->get_route() || is_wp_error($response) || !$response instanceof WP_REST_Response) return $response;
        $payload=$response->get_data(); if(!is_array($payload)||!is_array($payload['data']??null)) return $response; $data=$payload['data'];
        $read=is_array($data['read_endpoints']??null)?$data['read_endpoints']:array(); $read['research_lines']='research.read'; $read['research_line']='research.read'; $data['read_endpoints']=$read;
        $data['research_line_control']=array(
            'milestone'=>'M5','inventory'=>true,'inspection'=>true,'operations'=>array('line-create','line-update'),
            'fields'=>array('status','slug','title','excerpt','content','language','evidence_status','research_status','central_question','order','topics','methods'),
            'transport_base'=>'/research-manager/v1/research-lines','lifecycle'=>array('plan','apply','status','verify','rollback'),
            'evidence_gate'=>array('required_for_creation'=>true,'confirmation_field'=>'evidence_confirmed','reference_field'=>'evidence_reference','confirmation_class'=>'evidence-explicit'),
            'stale_preview_protection'=>true,'idempotency'=>true,'rendered_verification'=>true,'arbitrary_wordpress_proxy'=>false,
            'translations'=>'next-m5-slice'
        );
        $payload['data']=$data; $response->set_data($payload); return $response;
    }

    private function permission(WP_REST_Request $r,string $primary,string $secondary=''): bool|WP_Error { $ok=$this->auth->permission($r,$primary); if(is_wp_error($ok)||true!==$ok||''===$secondary) return $ok; $c=Eduardo_Research_Manager::remote_credentials()->state(); return Eduardo_Research_Manager::remote_credentials()->has_scope($c,$secondary)?true:$this->error('scope_denied',sprintf('Remote Manager connection does not grant %s.',$secondary),403); }
    private function idem(WP_REST_Request $r,callable $cb): WP_REST_Response|WP_Error { $c=Eduardo_Research_Manager::remote_credentials()->state(); $cid=(string)($c['connection_id']??''); $rid=trim((string)$r->get_header('x-research-manager-request-id')); $g=Eduardo_Research_Manager::remote_guard(); $fp=$g->request_fingerprint($r); $prev=$g->validate_idempotency($cid,$rid,$fp); if(is_wp_error($prev)) return $prev; if(is_array($prev)) return new WP_REST_Response(array('ok'=>true,'request_id'=>$rid,'idempotent_replay'=>true,'data'=>(array)($prev['result']??array())),200); $x=$cb(); if(is_wp_error($x)) return $x; $x=is_array($x)?$x:array('result'=>$x); $g->remember_request($cid,$rid,$fp,$x); return new WP_REST_Response(array('ok'=>true,'request_id'=>$rid,'idempotent_replay'=>false,'data'=>$x),200); }
    private function actor(WP_REST_Request $r): array { $c=Eduardo_Research_Manager::remote_credentials()->state(); return array('connection_id'=>(string)($c['connection_id']??''),'wordpress_user_id'=>get_current_user_id(),'request_id'=>(string)$r->get_header('x-research-manager-request-id')); }
    private function json(WP_REST_Request $r): array { $p=$r->get_json_params(); if(!is_array($p)) $p=$r->get_body_params(); return is_array($p)?$p:array(); }
    private function response(WP_REST_Request $r,array $data): WP_REST_Response { return new WP_REST_Response(array('ok'=>true,'request_id'=>(string)$r->get_header('x-research-manager-request-id'),'data'=>$data),200); }
    private function error(string $c,string $m,int $s): WP_Error { return new WP_Error($c,$m,array('status'=>$s)); }
}
