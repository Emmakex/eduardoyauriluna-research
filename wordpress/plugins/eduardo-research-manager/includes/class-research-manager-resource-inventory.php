<?php
/** Bounded aggregate inventory over Manager-owned Research resources. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Resource_Inventory {
    public function types(): array {
        return array(
            'pages'=>array('scope'=>'pages.read','identifier'=>'key'),
            'insights'=>array('scope'=>'insights.read','identifier'=>'post_id'),
            'lines'=>array('scope'=>'research.read','identifier'=>'post_id'),
            'outputs'=>array('scope'=>'research.read','identifier'=>'post_id'),
            'projects'=>array('scope'=>'research.read','identifier'=>'post_id'),
            'software'=>array('scope'=>'research.read','identifier'=>'post_id'),
            'datasets'=>array('scope'=>'research.read','identifier'=>'post_id'),
        );
    }

    public function catalog(): array {
        $items=array(); foreach($this->types() as $type=>$spec){$items[]=array('type'=>$type,'read_scope'=>$spec['scope'],'identifier'=>$spec['identifier']);}
        return array('types'=>$items,'count'=>count($items),'arbitrary_wordpress_proxy'=>false);
    }

    public function list(string $type,array $args=array()): array|WP_Error {
        $type=sanitize_key($type); if(!isset($this->types()[$type])){return $this->error('resource_type_unknown','Unsupported Manager resource type.');}
        if('pages'===$type){return $this->list_pages();}
        $page=max(1,(int) ($args['page']??1)); $per_page=max(1,min(50,(int) ($args['per_page']??20)));
        $language=sanitize_key((string) ($args['language']??'')); if(''!==$language&&!in_array($language,Eduardo_Research_Manager::contract()->languages(),true)){return $this->error('resource_language_unknown','Language is outside the active Research contract.');}
        $status=sanitize_key((string) ($args['status']??'')); $allowed_statuses=array('publish','draft','future','private');
        if(''!==$status&&!in_array($status,$allowed_statuses,true)){return $this->error('resource_status_unknown','Unsupported resource status filter.');}
        $query_args=array('post_type'=>$this->post_type($type),'post_status'=>''!==$status?$status:$allowed_statuses,'posts_per_page'=>$per_page,'paged'=>$page,'orderby'=>array('modified'=>'DESC','ID'=>'DESC'),'no_found_rows'=>false);
        $meta_query=array(); if('insights'===$type){$meta_query[]=array('key'=>'_research_insight_type','compare'=>'EXISTS');}
        if(''!==$language){$meta_query[]=array('key'=>'_research_language','value'=>$language,'compare'=>'=');}
        if($meta_query){$query_args['meta_query']=$meta_query;}
        $query=new WP_Query($query_args); $items=array();
        foreach($query->posts as $post){if(!$post instanceof WP_Post){continue;} $items[]=$this->summary($type,$post);}
        return array('type'=>$type,'items'=>$items,'count'=>count($items),'total'=>(int) $query->found_posts,'total_pages'=>(int) $query->max_num_pages,'page'=>$page,'per_page'=>$per_page,'filters'=>array('language'=>$language,'status'=>$status),'read_only'=>true);
    }

    public function get(string $type,string $identifier): array|WP_Error {
        $type=sanitize_key($type); if(!isset($this->types()[$type])){return $this->error('resource_type_unknown','Unsupported Manager resource type.');}
        if('pages'===$type){return Eduardo_Research_Manager::pages()->inspect(sanitize_key($identifier),'en');}
        $id=absint($identifier); if($id<=0){return $this->error('resource_identifier_invalid','Resource identifier must be a positive post ID.');}
        if('insights'===$type){return Eduardo_Research_Manager::insights()->inspect($id);}
        if('lines'===$type){return Eduardo_Research_Manager::lines()->inspect($id);}
        if('outputs'===$type){return Eduardo_Research_Manager::outputs()->inspect($id);}
        if('projects'===$type){return Eduardo_Research_Manager::projects()->inspect($id);}
        if('software'===$type){return Eduardo_Research_Manager::software()->inspect($id);}
        return Eduardo_Research_Manager::datasets()->inspect($id);
    }

    private function list_pages(): array {
        $items=array();
        foreach(Eduardo_Research_Manager::contract()->pages() as $key=>$definition){
            $state=Eduardo_Research_Manager::contract()->page_state((string) $key);
            if(empty($state['exists'])){$items[]=array('type'=>'pages','key'=>(string) $key,'exists'=>false,'expected_role'=>(string) ($definition['role']??''),'expected_model'=>(string) ($definition['model']??''));continue;}
            $record=Eduardo_Research_Manager::pages()->inspect((string) $key,'en');
            if(is_wp_error($record)){$items[]=array('type'=>'pages','key'=>(string) $key,'exists'=>true,'error_code'=>$record->get_error_code());continue;}
            $items[]=array('type'=>'pages','key'=>(string) $record['key'],'page_id'=>(int) $record['page_id'],'title'=>(string) $record['title'],'slug'=>(string) $record['slug'],'status'=>(string) $record['status'],'url'=>(string) $record['url'],'contract_aligned'=>!empty($record['contract_aligned']),'exists'=>true);
        }
        return array('type'=>'pages','items'=>$items,'count'=>count($items),'total'=>count($items),'page'=>1,'per_page'=>count($items),'read_only'=>true);
    }

    private function summary(string $type,WP_Post $post): array {
        $id=(int) $post->ID; $language=sanitize_key((string) get_post_meta($id,'_research_language',true)); if(''===$language){$language='en';}
        $item=array('type'=>$type,'post_id'=>$id,'title'=>(string) $post->post_title,'slug'=>(string) $post->post_name,'status'=>(string) $post->post_status,'language'=>$language,'modified_at'=>get_post_modified_time(DATE_W3C,true,$post),'url'=>(string) get_permalink($post));
        if('insights'===$type){$item['insight_type']=sanitize_key((string) get_post_meta($id,'_research_insight_type',true));}
        return $item;
    }
    private function post_type(string $type): string { return array('insights'=>'post','lines'=>'research_line','outputs'=>'research_output','projects'=>'research_project','software'=>'research_software','datasets'=>'research_dataset')[$type]; }
    private function error(string $code,string $message): WP_Error { return new WP_Error($code,$message,array('status'=>400)); }
}
