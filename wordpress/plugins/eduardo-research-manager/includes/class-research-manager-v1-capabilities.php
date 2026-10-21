<?php
/** Final v1 capability reconciliation across bounded Manager surfaces. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

require_once EDUARDO_RESEARCH_MANAGER_DIR . 'includes/class-research-manager-site-profile.php';
require_once EDUARDO_RESEARCH_MANAGER_DIR . 'includes/class-research-manager-site-baseline.php';
require_once EDUARDO_RESEARCH_MANAGER_DIR . 'includes/class-research-manager-portability.php';
require_once EDUARDO_RESEARCH_MANAGER_DIR . 'includes/class-research-manager-site-discovery.php';

final class Eduardo_Research_Manager_V1_Capabilities {
    public function register(): void {
        (new Eduardo_Research_Manager_Site_Discovery())->register();
        (new Eduardo_Research_Manager_Portability())->register();
        add_action('admin_menu', static function (): void {
            if (! (new Eduardo_Research_Manager_Site_Profile())->research_available()) {
                remove_menu_page('eduardo-research-workspace');
            }
        }, 1000);
        add_filter('rest_request_after_callbacks',array($this,'augment'),99,3);
        add_filter('rest_request_after_callbacks',array($this,'strip_diagnostic_payload_from_capabilities'),1001,3);
    }

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
        $data['page_target_control']=array(
            'available'=>true,
            'slots'=>array('hero-primary-target','hero-secondary-target','final-cta-target'),
            'target_source'=>'active-theme-page-contract',
            'registered_theme_targets_only'=>true,
            'arbitrary_urls'=>false,
            'language_aware_resolution'=>true,
        );

        $data['manager_v1_development']=array(
            'status'=>'production-accepted',
            'implementation_ci_green'=>true,
            'production_acceptance_frozen'=>false,
            'production_acceptance_status'=>'complete',
            'production_pair'=>array(
                'theme'=>'1.0.0',
                'manager'=>defined('EDUARDO_RESEARCH_MANAGER_VERSION')?EDUARDO_RESEARCH_MANAGER_VERSION:'',
            ),
            'site_scope'=>'single-site',
            'future_multiclient_saas_in_v1'=>false,
            'normal_operation_ssh_required'=>false,
            'normal_operation_wordpress_credentials_shared'=>false,
            'local_admin_shared_service_parity'=>true,
            'arbitrary_wordpress_rest_proxy'=>false,
            'surfaces'=>array('identity','pages','navigation','insights','research-lines','research-objects','translations','evidence','media','seo-geo','design','connections','audit','operations'),
        );

        $payload['data']=$data;$response->set_data($payload);return $response;
    }

    /** Capability discovery is site.read; diagnostic baseline data must never piggyback on it. */
    public function strip_diagnostic_payload_from_capabilities(mixed $response,mixed $handler,WP_REST_Request $request): mixed {
        if('/'.Eduardo_Research_Manager_Remote_REST::NAMESPACE.'/capabilities'!==$request->get_route()||is_wp_error($response)||!$response instanceof WP_REST_Response){return $response;}
        $payload=$response->get_data();
        if(!is_array($payload)||!is_array($payload['data']??null)){return $response;}
        unset($payload['data']['site_baseline']);
        $payload['data']['site_discovery']=(new Eduardo_Research_Manager_Site_Discovery())->capability_payload();
        $response->set_data($payload);
        return $response;
    }
}
