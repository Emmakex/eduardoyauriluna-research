<?php
/** Local Admin parity for canonical Identity and bounded Page state services. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_V1_Controls_Admin {
    private const NOTICE='eduardo_research_manager_v1_controls_notice_';
    private const ID_PREVIEW='eduardo_research_manager_v1_identity_preview_';
    private const ID_EDIT='eduardo_research_manager_v1_identity_edit_';
    private const PAGE_PREVIEW='eduardo_research_manager_v1_page_state_preview_';
    private const PAGE_EDIT='eduardo_research_manager_v1_page_state_edit_';

    public function register(): void {
        add_action('admin_menu',array($this,'menu'));
        add_action('admin_post_eduardo_research_manager_v1_preview_identity',array($this,'preview_identity'));
        add_action('admin_post_eduardo_research_manager_v1_apply_identity',array($this,'apply_identity'));
        add_action('admin_post_eduardo_research_manager_v1_rollback_identity',array($this,'rollback_identity'));
        add_action('admin_post_eduardo_research_manager_v1_preview_page_state',array($this,'preview_page_state'));
        add_action('admin_post_eduardo_research_manager_v1_apply_page_state',array($this,'apply_page_state'));
        add_action('admin_post_eduardo_research_manager_v1_rollback_page_state',array($this,'rollback_page_state'));
    }

    public function menu(): void {
        add_management_page('Research Manager v1 Controls','Research Manager v1 Controls','manage_options','eduardo-research-manager-v1-controls',array($this,'render'));
    }

    public function render(): void {
        if(!current_user_can('manage_options')){wp_die(esc_html__('You do not have permission to access Research Manager controls.','eduardo-research-manager'));}
        $identityService=new Eduardo_Research_Manager_Identity_Profile();
        $identity=$identityService->inspect();
        $identityPreview=get_transient($this->key(self::ID_PREVIEW));$identityPreview=is_array($identityPreview)?$identityPreview:array();
        $identityEdit=get_transient($this->key(self::ID_EDIT));$identityEdit=is_array($identityEdit)?$identityEdit:array();

        $pages=Eduardo_Research_Manager::contract()->pages();$pageKey=sanitize_key((string)($_GET['erm_page_key']??''));
        if(''===$pageKey||!isset($pages[$pageKey])){$pageKey=(string) array_key_first($pages);}
        $pageService=new Eduardo_Research_Manager_Page_State_Editor();$pageState=''!==$pageKey?$pageService->inspect($pageKey):new WP_Error('research_manager_page_state_missing','No Theme Page is available.');
        $pagePreview=get_transient($this->key(self::PAGE_PREVIEW));$pagePreview=is_array($pagePreview)&&$pageKey===(string)($pagePreview['key']??'')?$pagePreview:array();
        $pageEdit=get_transient($this->key(self::PAGE_EDIT));$pageEdit=is_array($pageEdit)?$pageEdit:array();
        $notice=get_transient($this->key(self::NOTICE));delete_transient($this->key(self::NOTICE));
        ?>
        <div class="wrap">
          <h1><?php echo esc_html__('Research Manager v1 Controls','eduardo-research-manager'); ?></h1>
          <p><?php echo esc_html__('Local Admin uses the same shared domain services as the authenticated remote Manager. No duplicate mutation rules live in this UI.','eduardo-research-manager'); ?></p>
          <?php if(is_array($notice)&&$notice): ?><div class="notice notice-<?php echo esc_attr((string)($notice['type']??'info')); ?> is-dismissible"><p><?php echo esc_html((string)($notice['message']??'')); ?></p></div><?php endif; ?>

          <h2><?php echo esc_html__('Canonical Researcher Identity','eduardo-research-manager'); ?></h2>
          <?php if(is_wp_error($identity)): ?><div class="notice notice-error inline"><p><?php echo esc_html($identity->get_error_message()); ?></p></div><?php else: ?>
          <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="max-width:900px">
            <input type="hidden" name="action" value="eduardo_research_manager_v1_preview_identity"><?php wp_nonce_field('erm_v1_identity_preview'); ?>
            <table class="form-table" role="presentation"><tbody>
              <tr><th><label for="erm-v1-name">Researcher name</label></th><td><input id="erm-v1-name" class="regular-text" style="width:100%;max-width:680px" name="name" required value="<?php echo esc_attr((string)$identity['name']); ?>"></td></tr>
              <tr><th><label for="erm-v1-headline">Researcher headline</label></th><td><input id="erm-v1-headline" class="regular-text" style="width:100%;max-width:680px" name="headline" required value="<?php echo esc_attr((string)$identity['headline']); ?>"></td></tr>
              <tr><th>Canonical URL</th><td><code><?php echo esc_html((string)$identity['url']); ?></code></td></tr>
              <tr><th><label for="erm-v1-evidence">Evidence reference</label></th><td><input id="erm-v1-evidence" class="regular-text" style="width:100%;max-width:680px" name="evidence_reference" value="<?php echo esc_attr((string)$identity['evidence_reference']); ?>"><p><label><input type="checkbox" name="evidence_confirmed" value="1"> <?php echo esc_html__('I confirm this identity evidence before Apply.','eduardo-research-manager'); ?></label></p></td></tr>
            </tbody></table>
            <?php submit_button(__('Preview Identity change','eduardo-research-manager'),'secondary','submit',false); ?>
          </form>
          <?php $this->prepared_block('Identity',$identityPreview,'eduardo_research_manager_v1_apply_identity','erm_v1_identity_apply'); ?>
          <?php if(!empty($identityEdit['snapshot_id'])):$this->rollback_block('Identity',$identityEdit,'eduardo_research_manager_v1_rollback_identity','erm_v1_identity_rollback');endif; ?>
          <?php endif; ?>

          <hr style="margin:32px 0">
          <h2><?php echo esc_html__('Theme Page title and publication state','eduardo-research-manager'); ?></h2>
          <form method="get" action="<?php echo esc_url(admin_url('tools.php')); ?>" style="margin:12px 0 18px"><input type="hidden" name="page" value="eduardo-research-manager-v1-controls"><label for="erm-v1-page"><strong>Theme Page</strong></label><br><select id="erm-v1-page" name="erm_page_key"><?php foreach($pages as $key=>$definition): ?><option value="<?php echo esc_attr((string)$key); ?>" <?php selected($pageKey,(string)$key); ?>><?php echo esc_html((string)($definition['label']??$definition['labels']['en']??ucfirst((string)$key))); ?></option><?php endforeach; ?></select><?php submit_button(__('Open Page state','eduardo-research-manager'),'secondary','submit',false); ?></form>
          <?php if(is_wp_error($pageState)): ?><div class="notice notice-error inline"><p><?php echo esc_html($pageState->get_error_message()); ?></p></div><?php else: $policy=is_array($pageState['policy']??null)?$pageState['policy']:array();$locked=!empty($policy['status_locked']); ?>
          <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="max-width:900px"><input type="hidden" name="action" value="eduardo_research_manager_v1_preview_page_state"><input type="hidden" name="page_key" value="<?php echo esc_attr($pageKey); ?>"><?php wp_nonce_field('erm_v1_page_state_preview'); ?>
            <table class="form-table" role="presentation"><tbody>
              <tr><th>Contract slug</th><td><code><?php echo esc_html((string)$pageState['slug']); ?></code><p class="description"><?php echo esc_html__('Contract Page slugs are intentionally immutable.','eduardo-research-manager'); ?></p></td></tr>
              <tr><th><label for="erm-v1-title">Title</label></th><td><input id="erm-v1-title" class="regular-text" style="width:100%;max-width:680px" name="title" required value="<?php echo esc_attr((string)$pageState['title']); ?>"></td></tr>
              <tr><th><label for="erm-v1-status">Publication state</label></th><td><select id="erm-v1-status" name="status" <?php disabled($locked); ?>><option value="draft" <?php selected((string)$pageState['status'],'draft'); ?>>Draft</option><option value="publish" <?php selected((string)$pageState['status'],'publish'); ?>>Publish</option></select><?php if($locked): ?><input type="hidden" name="status" value="<?php echo esc_attr((string)$pageState['status']); ?>"><p class="description"><?php echo esc_html__('This Theme Page has a contract-locked publication state.','eduardo-research-manager'); ?></p><?php endif; ?></td></tr>
            </tbody></table>
            <?php submit_button(__('Preview Page state change','eduardo-research-manager'),'secondary','submit',false); ?>
          </form>
          <?php $this->prepared_block('Page state',$pagePreview,'eduardo_research_manager_v1_apply_page_state','erm_v1_page_state_apply'); ?>
          <?php if(!empty($pageEdit['snapshot_id'])):$this->rollback_block('Page state',$pageEdit,'eduardo_research_manager_v1_rollback_page_state','erm_v1_page_state_rollback');endif; ?>
          <?php endif; ?>
        </div><?php
    }

    public function preview_identity(): void {
        $this->require_admin('erm_v1_identity_preview');
        $prepared=(new Eduardo_Research_Manager_Identity_Profile())->preview_update(array(
            'name'=>sanitize_text_field((string)wp_unslash($_POST['name']??'')),
            'headline'=>sanitize_text_field((string)wp_unslash($_POST['headline']??'')),
        ),!empty($_POST['evidence_confirmed']),sanitize_text_field((string)wp_unslash($_POST['evidence_reference']??'')));
        $this->store_preview(self::ID_PREVIEW,$prepared,'Identity Preview prepared.');$this->redirect();
    }
    public function apply_identity(): void {
        $this->require_admin('erm_v1_identity_apply');$prepared=get_transient($this->key(self::ID_PREVIEW));
        if(!is_array($prepared)){$this->notice('error','No prepared Identity Preview is available.');$this->redirect();}
        $result=(new Eduardo_Research_Manager_Identity_Profile())->apply_preview($prepared);if(is_wp_error($result)){$this->notice('error',$result->get_error_message());$this->redirect();}
        set_transient($this->key(self::ID_EDIT),array('snapshot_id'=>(string)($result['snapshot_id']??''),'applied_at'=>gmdate(DATE_W3C)),HOUR_IN_SECONDS);delete_transient($this->key(self::ID_PREVIEW));$this->notice('success','Canonical Identity applied and verified.');$this->redirect();
    }
    public function rollback_identity(): void {
        $this->require_admin('erm_v1_identity_rollback');$edit=get_transient($this->key(self::ID_EDIT));$snapshot=is_array($edit)?(string)($edit['snapshot_id']??''):'';$result=(new Eduardo_Research_Manager_Identity_Profile())->rollback($snapshot);if(is_wp_error($result)){$this->notice('error',$result->get_error_message());}else{delete_transient($this->key(self::ID_EDIT));$this->notice('success','Canonical Identity rollback completed.');}$this->redirect();
    }
    public function preview_page_state(): void {
        $this->require_admin('erm_v1_page_state_preview');$key=sanitize_key((string)wp_unslash($_POST['page_key']??''));
        $prepared=(new Eduardo_Research_Manager_Page_State_Editor())->preview_update($key,array('title'=>sanitize_text_field((string)wp_unslash($_POST['title']??'')),'status'=>sanitize_key((string)wp_unslash($_POST['status']??''))));
        $this->store_preview(self::PAGE_PREVIEW,$prepared,'Page State Preview prepared.');$this->redirect(array('erm_page_key'=>$key));
    }
    public function apply_page_state(): void {
        $this->require_admin('erm_v1_page_state_apply');$prepared=get_transient($this->key(self::PAGE_PREVIEW));if(!is_array($prepared)){$this->notice('error','No prepared Page State Preview is available.');$this->redirect();}
        $result=(new Eduardo_Research_Manager_Page_State_Editor())->apply_preview($prepared);if(is_wp_error($result)){$this->notice('error',$result->get_error_message());$this->redirect(array('erm_page_key'=>(string)($prepared['key']??'')));}
        set_transient($this->key(self::PAGE_EDIT),array('snapshot_id'=>(string)($result['snapshot_id']??''),'applied_at'=>gmdate(DATE_W3C)),HOUR_IN_SECONDS);delete_transient($this->key(self::PAGE_PREVIEW));$this->notice('success','Page title/publication state applied and verified.');$this->redirect(array('erm_page_key'=>(string)($prepared['key']??'')));
    }
    public function rollback_page_state(): void {
        $this->require_admin('erm_v1_page_state_rollback');$edit=get_transient($this->key(self::PAGE_EDIT));$snapshot=is_array($edit)?(string)($edit['snapshot_id']??''):'';$result=(new Eduardo_Research_Manager_Page_State_Editor())->rollback($snapshot);if(is_wp_error($result)){$this->notice('error',$result->get_error_message());}else{delete_transient($this->key(self::PAGE_EDIT));$this->notice('success','Page State rollback completed.');}$this->redirect();
    }

    private function prepared_block(string $label,array $prepared,string $action,string $nonce): void { if(!$prepared){return;} ?><h3><?php echo esc_html('Prepared '.$label.' change'); ?></h3><table class="widefat striped" style="max-width:900px"><tbody><tr><th>Status</th><td><?php echo esc_html(strtoupper((string)($prepared['status']??''))); ?></td></tr><tr><th>Risk</th><td><?php echo esc_html(strtoupper((string)($prepared['risk']??''))); ?></td></tr><tr><th>Plan ID</th><td><code><?php echo esc_html((string)($prepared['plan_id']??'')); ?></code></td></tr><tr><th>Apply gate</th><td><?php echo !empty($prepared['apply_allowed'])?'Allowed':'Blocked'; ?></td></tr></tbody></table><?php if('already-matching'!==(string)($prepared['status']??'')&&!empty($prepared['apply_allowed'])): ?><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:10px"><input type="hidden" name="action" value="<?php echo esc_attr($action); ?>"><?php wp_nonce_field($nonce); ?><?php submit_button(__('Apply prepared change','eduardo-research-manager'),'primary','submit',false); ?></form><?php endif; }
    private function rollback_block(string $label,array $edit,string $action,string $nonce): void { ?><h3><?php echo esc_html('Latest reversible '.$label.' edit'); ?></h3><p><code><?php echo esc_html((string)($edit['snapshot_id']??'')); ?></code></p><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="<?php echo esc_attr($action); ?>"><?php wp_nonce_field($nonce); ?><?php submit_button(__('Rollback latest change','eduardo-research-manager'),'secondary','submit',false); ?></form><?php }
    private function store_preview(string $prefix,array|WP_Error $prepared,string $success): void { if(is_wp_error($prepared)){$this->notice('error',$prepared->get_error_message());return;}set_transient($this->key($prefix),$prepared,HOUR_IN_SECONDS);$this->notice('success',$success); }
    private function require_admin(string $nonce): void { if(!current_user_can('manage_options')){wp_die('Forbidden');}check_admin_referer($nonce); }
    private function notice(string $type,string $message): void { set_transient($this->key(self::NOTICE),array('type'=>$type,'message'=>$message),MINUTE_IN_SECONDS); }
    private function key(string $prefix): string { return $prefix.get_current_user_id(); }
    private function redirect(array $args=array()): never { wp_safe_redirect(add_query_arg(array_merge(array('page'=>'eduardo-research-manager-v1-controls'),$args),admin_url('tools.php')));exit; }
}
