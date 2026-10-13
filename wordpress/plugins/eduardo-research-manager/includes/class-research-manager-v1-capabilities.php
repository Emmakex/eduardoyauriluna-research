<?php
/** Final v1 capability reconciliation across bounded Manager surfaces. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_V1_Capabilities {
    public function register(): void { add_filter('rest_request_after_callbacks',array($this,'augment'),99,3); }

    public function augment(mixed $response,mixed $handler,WP_REST_Request $request): mixed {
        if('/'.Eduardo_Research_Manager_Remote_REST::NAMESPACE.'/capabilities'!==$request->get_route()||is_wp_error($response)||!$response instanceof WP_REST_Response){return $response;}
        $payload=$response->get_data();if(!is_array($payload)||!is_array($payload['data']??null)){return $response;}
        $data=$payload['data'];

        if(is_array($data['insight_control']??null)){
            $fields=is_array($data['insight_control']['update_fields']??null)?$data['insight_control']['update_fields']:array();
            if(!in_array('slug',$fields,true)){$fields[]='slug';}
            $data['insight_control']['update_fields']=array_values($fields);
            $data['insight_control']['slug_updates']=array('available'=>true,'operation'=>'insight-update','sanitization'=>'sanitize_title','reversible'=>true,'stale_preview_protection'=>true);
        }
        if(is_array($data['research_line_control']??null)){
            $fields=array('status','slug','title','excerpt','content','language','evidence_status','research_status','central_question','order','topics','methods');
            $data['research_line_control']['fields']=$fields;
            $data['research_line_control']['update_fields']=$fields;
            $data['research_line_control']['slug_updates']=array('available'=>true,'operation'=>'line-update','sanitization'=>'sanitize_title','reversible'=>true);
            $data['research_line_control']['publication_state']=array('available'=>true,'allowed'=>array('draft','publish'),'operation'=>'line-update','reversible'=>true);
        }
        if(is_array($data['page_state_control']??null)){$data['page_state_control']['local_admin_parity']=true;}

        $data['manager_v1_development']=array(
            'status'=>'implementation-complete-pending-ci-contract-closeout',
            'production_acceptance_frozen'=>true,
            'normal_operation_ssh_required'=>false,
            'normal_operation_wordpress_credentials_shared'=>false,
            'local_admin_shared_service_parity'=>true,
            'arbitrary_wordpress_rest_proxy'=>false,
            'surfaces'=>array('identity','pages','navigation','insights','research-lines','research-objects','translations','evidence','media','seo-geo','design','connections','audit','operations'),
        );
        $payload['data']=$data;$response->set_data($payload);return $response;
    }
}
