#!/usr/bin/env php
<?php
declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');

final class WP_Error {
    public function __construct(private string $code, private string $message='', private array $data=array()) {}
    public function get_error_code(): string { return $this->code; }
}
function is_wp_error(mixed $value): bool { return $value instanceof WP_Error; }
function sanitize_key(string $value): string { return strtolower(preg_replace('/[^a-z0-9_\-]/', '', $value) ?? ''); }
function sanitize_text_field(string $value): string { return trim(strip_tags($value)); }
function wp_json_encode(mixed $value): string|false { return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); }

final class TestPageStateStore {
    public array $pages = array(
        'research'=>array('page_id'=>20,'title'=>'Research','slug'=>'research','contract_slug'=>'research','status'=>'publish','published'=>true,'url'=>'https://example.test/research/','role'=>'archive-page'),
        'home'=>array('page_id'=>10,'title'=>'Home','slug'=>'home','contract_slug'=>'home','status'=>'publish','published'=>true,'url'=>'https://example.test/','role'=>'front-page'),
    );
    public array $snapshots = array();
}

final class Eduardo_Research_Manager_Page_Resource {
    public function __construct(public TestPageStateStore $store) {}
    public function inspect(string $key, string $language='en'): array|WP_Error {
        if (! isset($this->store->pages[$key])) { return new WP_Error('not_found'); }
        $page = $this->store->pages[$key];
        $page['key']=$key;
        $page['state_policy']=array(
            'title_editable'=>true,'status_editable'=>true,'allowed_statuses'=>array('draft','publish'),
            'archive_status'=>'draft','slug_editable'=>false,'slug_source'=>'theme-contract','destructive_delete'=>false,
        );
        return $page;
    }
}

final class Eduardo_Research_Manager_Plan {
    public static function create(string $intent, array $actions): array|WP_Error {
        return array('id'=>'plan-'.substr(hash('sha256',$intent.json_encode($actions)),0,12),'intent'=>$intent,'risk'=>'editorial-review','actions'=>$actions);
    }
}

final class Eduardo_Research_Manager_Executor {
    private int $counter = 0;
    public function __construct(private TestPageStateStore $store) {}
    public function preview(array $plan): array { return array('apply_allowed'=>true,'risk'=>$plan['risk']??'editorial-review','plan_id'=>$plan['id']??''); }
    public function apply(array $plan): array|WP_Error {
        $snapshot='snapshot-'.(++$this->counter);
        $this->store->snapshots[$snapshot]=$this->store->pages;
        foreach((array) ($plan['actions']??array()) as $action){
            $pageId=(int) ($action['post_id']??0);
            foreach($this->store->pages as $key=>$page){
                if((int) $page['page_id']!==$pageId){continue;}
                if('post_title'===($action['field']??'')){$this->store->pages[$key]['title']=(string) ($action['value']??'');}
                if('post_status'===($action['field']??'')){
                    $status=(string) ($action['value']??'');
                    $this->store->pages[$key]['status']=$status;
                    $this->store->pages[$key]['published']='publish'===$status;
                }
            }
        }
        return array('snapshot_id'=>$snapshot,'applied'=>true);
    }
    public function rollback(string $snapshot): array|WP_Error {
        if(!isset($this->store->snapshots[$snapshot])){return new WP_Error('snapshot_not_found');}
        $this->store->pages=$this->store->snapshots[$snapshot];
        return array('rolled_back'=>true,'snapshot_id'=>$snapshot);
    }
}

final class Eduardo_Research_Manager {
    public static function pages(): Eduardo_Research_Manager_Page_Resource { throw new RuntimeException('test injects service'); }
    public static function executor(): Eduardo_Research_Manager_Executor { throw new RuntimeException('test injects service'); }
}

require_once __DIR__ . '/../wordpress/plugins/eduardo-research-manager/includes/class-research-manager-page-state-editor.php';

function check(bool $condition,string $message): void { if(!$condition){fwrite(STDERR,"FAIL: {$message}\n");exit(1);} }

$store=new TestPageStateStore();
$resource=new Eduardo_Research_Manager_Page_Resource($store);
$executor=new Eduardo_Research_Manager_Executor($store);
$editor=new Eduardo_Research_Manager_Page_State_Editor($resource,$executor);

$baseline=$editor->inspect('research');
check(is_array($baseline)&&'publish'===$baseline['status'],'research baseline must load');
check(false===($baseline['policy']['slug_editable']??true),'contract slug must be immutable');

$slug=$editor->preview_update('research',array('slug'=>'renamed'));
check(is_wp_error($slug)&&'research_manager_page_slug_immutable'===$slug->get_error_code(),'slug mutation must be rejected');

$homeStatus=$editor->preview_update('home',array('status'=>'draft'));
check(is_wp_error($homeStatus)&&'research_manager_page_status_locked'===$homeStatus->get_error_code(),'front page status must be locked');

$preview=$editor->preview_update('research',array('title'=>'Research Agenda','status'=>'draft'),'Bounded Page state acceptance');
check(is_array($preview)&&!empty($preview['apply_allowed']),'bounded title/status Preview must succeed');
check('publish'===$store->pages['research']['status']&&'Research'===$store->pages['research']['title'],'Preview must not mutate Page state');
check('research'===($preview['expected']['slug']??''),'Preview must preserve contract slug');

$applied=$editor->apply_preview($preview);
check(is_array($applied)&&!empty($applied['verified']),'Apply must verify bounded Page state');
check('Research Agenda'===$store->pages['research']['title'],'Apply must update title');
check('draft'===$store->pages['research']['status'],'Apply must update status to draft');
check('research'===$store->pages['research']['slug'],'Apply must not change slug');
$snapshot=(string) ($applied['snapshot_id']??'');
check(''!==$snapshot,'Apply must expose rollback snapshot');

$rolled=$editor->rollback($snapshot);
check(is_array($rolled)&&!empty($rolled['rolled_back']),'Rollback must succeed');
$restored=$editor->inspect('research');
check(is_array($restored)&&'Research'===$restored['title']&&'publish'===$restored['status'],'Rollback must restore exact Page baseline');
check((string) $baseline['revision']===(string) $restored['revision'],'Rollback must restore exact Page revision');

$stale=$editor->preview_update('research',array('title'=>'Stale Candidate'));
check(is_array($stale),'stale fixture Preview must succeed');
$store->pages['research']['title']='Concurrent Change';
$staleApply=$editor->apply_preview($stale);
check(is_wp_error($staleApply)&&'research_manager_stale_preview'===$staleApply->get_error_code(),'concurrent Page change must reject stale Apply');
check('Concurrent Change'===$store->pages['research']['title'],'stale Apply must not overwrite newer Page state');

$invalidStatus=$editor->preview_update('research',array('status'=>'private'));
check(is_wp_error($invalidStatus)&&'research_manager_page_status_invalid'===$invalidStatus->get_error_code(),'Page state must reject status outside bounded contract');

fwrite(STDOUT,"Manager v1 bounded Page state lifecycle: PASS\n");
