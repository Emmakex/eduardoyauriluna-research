<?php
/** Shared bounded Page title/publication-state lifecycle for Theme-owned Pages. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Page_State_Editor {
    private Eduardo_Research_Manager_Page_Resource $pages;
    private Eduardo_Research_Manager_Executor $executor;

    public function __construct(?Eduardo_Research_Manager_Page_Resource $pages = null, ?Eduardo_Research_Manager_Executor $executor = null) {
        $this->pages = $pages ?: Eduardo_Research_Manager::pages();
        $this->executor = $executor ?: Eduardo_Research_Manager::executor();
    }

    public function inspect(string $key): array|WP_Error {
        $page = $this->pages->inspect(sanitize_key($key), 'en');
        if (is_wp_error($page)) { return $page; }
        $policy = (array) $page['state_policy'];
        if ('front-page' === (string) ($page['role'] ?? '')) {
            $policy['status_editable'] = false;
            $policy['allowed_statuses'] = array('publish');
            $policy['archive_status'] = null;
            $policy['front_page_status_locked'] = true;
        }
        return array(
            'key'=>(string) $page['key'],'page_id'=>(int) $page['page_id'],'title'=>(string) $page['title'],'slug'=>(string) $page['slug'],'contract_slug'=>(string) $page['contract_slug'],
            'status'=>(string) $page['status'],'published'=>! empty($page['published']),'url'=>(string) $page['url'],'policy'=>$policy,'revision'=>$this->revision($page),
        );
    }

    public function preview_update(string $key, array $changes, string $intent = ''): array|WP_Error {
        $before = $this->inspect($key); if (is_wp_error($before)) { return $before; }
        if (! $changes) { return new WP_Error('research_manager_page_state_empty', 'Page state update requires at least one bounded change.'); }
        $unknown = array_diff(array_keys($changes), array('title','status','slug'));
        if ($unknown) { return new WP_Error('research_manager_page_state_field_unknown', 'Unsupported Page state field: ' . implode(', ', array_map('sanitize_key', $unknown))); }
        if (array_key_exists('slug', $changes)) { return new WP_Error('research_manager_page_slug_immutable', 'Theme contract Page slugs are immutable through the Manager.'); }

        $expected = array('title'=>(string) $before['title'],'status'=>(string) $before['status'],'slug'=>(string) $before['slug']);
        $actions = array();
        if (array_key_exists('title', $changes)) {
            if (! is_scalar($changes['title'])) { return new WP_Error('research_manager_page_title_invalid', 'Page title must be scalar text.'); }
            $title = trim(sanitize_text_field((string) $changes['title']));
            if ('' === $title) { return new WP_Error('research_manager_page_title_required', 'Page title cannot be empty.'); }
            $expected['title'] = $title;
            if ($title !== (string) $before['title']) { $actions[] = array('type'=>'post_field','post_id'=>(int) $before['page_id'],'field'=>'post_title','value'=>$title); }
        }
        if (array_key_exists('status', $changes)) {
            if (empty($before['policy']['status_editable'])) { return new WP_Error('research_manager_page_status_locked', 'This Theme Page publication status is locked by the active contract.'); }
            $status = sanitize_key(is_scalar($changes['status']) ? (string) $changes['status'] : '');
            if (! in_array($status, (array) ($before['policy']['allowed_statuses'] ?? array()), true)) { return new WP_Error('research_manager_page_status_invalid', 'Page status is outside the active Theme Page state policy.'); }
            $expected['status'] = $status;
            if ($status !== (string) $before['status']) { $actions[] = array('type'=>'post_field','post_id'=>(int) $before['page_id'],'field'=>'post_status','value'=>$status); }
        }
        if (! $actions) { return new WP_Error('research_manager_no_change', 'The requested Page state already matches stored state.'); }

        $intent = '' !== trim($intent) ? sanitize_text_field($intent) : sprintf('Update bounded Theme Page state: %s', (string) $before['key']);
        $plan = Eduardo_Research_Manager_Plan::create($intent, $actions); if (is_wp_error($plan)) { return $plan; }
        $preview = $this->executor->preview($plan); if (is_wp_error($preview)) { return $preview; }
        return array(
            'resource'=>'page-state','operation'=>'update','key'=>(string) $before['key'],'page_id'=>(int) $before['page_id'],'before'=>$before,'expected'=>$expected,
            'baseline_revision'=>(string) $before['revision'],'plan'=>$plan,'plan_id'=>(string) ($plan['id'] ?? ''),'preview'=>$preview,
            'apply_allowed'=>! empty($preview['apply_allowed']),'risk'=>(string) ($preview['risk'] ?? $plan['risk'] ?? 'editorial-review'),
        );
    }

    public function apply_preview(array $prepared): array|WP_Error {
        $key=sanitize_key((string) ($prepared['key']??''));$baseline=(string) ($prepared['baseline_revision']??'');$plan=is_array($prepared['plan']??null)?$prepared['plan']:array();
        if(''===$key||''===$baseline||!$plan){return new WP_Error('research_manager_page_state_preview_invalid','Prepared Page state Preview is incomplete.');}
        $current=$this->inspect($key);if(is_wp_error($current)){return $current;}if(!hash_equals($baseline,(string) $current['revision'])){return new WP_Error('research_manager_stale_preview','Page state changed after Preview. Prepare a fresh plan.');}
        $applied=$this->executor->apply($plan);if(is_wp_error($applied)){return $applied;}$verification=$this->verify($key,is_array($prepared['expected']??null)?$prepared['expected']:array());if(is_wp_error($verification)){return $verification;}
        $applied['verification']=$verification;$applied['verified']=!empty($verification['verified']);return $applied;
    }

    public function verify(string $key,array $expected): array|WP_Error {
        $actual=$this->inspect($key);if(is_wp_error($actual)){return $actual;}
        $checks=array('title'=>!array_key_exists('title',$expected)||(string) $expected['title']===(string) $actual['title'],'status'=>!array_key_exists('status',$expected)||(string) $expected['status']===(string) $actual['status'],'slug_contract'=>(string) $actual['slug']===(string) $actual['contract_slug']);
        return array('verified'=>!in_array(false,$checks,true),'key'=>$key,'actual'=>$actual,'expected'=>$expected,'checks'=>$checks,'verified_at'=>gmdate(DATE_W3C));
    }

    public function rollback(string $snapshot_id): array|WP_Error {$snapshot_id=sanitize_text_field($snapshot_id);if(''===$snapshot_id){return new WP_Error('research_manager_page_state_snapshot_required','Page state rollback requires a snapshot ID.');}return $this->executor->rollback($snapshot_id);}

    private function revision(array $page): string {return hash('sha256',(string) wp_json_encode(array('page_id'=>(int) ($page['page_id']??0),'title'=>(string) ($page['title']??''),'slug'=>(string) ($page['slug']??''),'contract_slug'=>(string) ($page['contract_slug']??''),'status'=>(string) ($page['status']??''))));}
}
