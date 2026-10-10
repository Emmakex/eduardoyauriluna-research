<?php
/** Operational admin surface for the Greenfield Research Manager. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Admin {
    private const LAST_RUN_OPTION = 'eduardo_research_manager_last_pipeline_run';
    private const NOTICE_PREFIX = 'eduardo_research_manager_notice_';

    public function register(): void {
        add_action('admin_menu', array($this, 'menu'));
        add_action('admin_post_eduardo_research_manager_apply_canonical', array($this, 'handle_apply_canonical'));
        add_action('admin_post_eduardo_research_manager_rollback_canonical', array($this, 'handle_rollback_canonical'));
        add_action('admin_post_eduardo_research_manager_download_blueprint', array($this, 'handle_download_blueprint'));
    }

    public function menu(): void {
        add_management_page('Research Manager','Research Manager','manage_options','eduardo-research-manager',array($this,'render'));
    }

    public function render(): void {
        if (! current_user_can('manage_options')) { wp_die(esc_html__('You do not have permission to access Research Manager.', 'eduardo-research-manager')); }
        $diagnostics = Eduardo_Research_Manager::diagnostics()->run();
        $summary = $diagnostics['summary'];
        $metadata = Eduardo_Research_Manager::blueprint_store()->metadata();
        $blueprint = is_wp_error($metadata) ? $metadata : Eduardo_Research_Manager::blueprint_store()->canonical();
        $preview = is_wp_error($blueprint) ? $blueprint : Eduardo_Research_Manager::pipeline()->preview($blueprint);
        $last_run = get_option(self::LAST_RUN_OPTION, array());
        $notice = $this->pull_notice();
        ?>
        <div class="wrap">
          <h1><?php echo esc_html__('Research Manager', 'eduardo-research-manager'); ?></h1>
          <p><?php echo esc_html__('Greenfield control plane for the canonical Eduardo Research site. The Theme remains the frontend rendering authority.', 'eduardo-research-manager'); ?></p>
          <?php if ($notice) : ?><div class="notice notice-<?php echo esc_attr((string) $notice['type']); ?> is-dismissible"><p><?php echo esc_html((string) $notice['message']); ?></p></div><?php endif; ?>
          <div class="notice <?php echo $diagnostics['ready'] ? 'notice-success' : 'notice-warning'; ?> inline"><p><strong><?php echo esc_html($diagnostics['ready'] ? 'Research contract ready' : 'Research contract needs attention'); ?></strong><?php echo esc_html(sprintf(' — %d passed, %d warnings, %d failures.', (int) $summary['pass'], (int) $summary['warning'], (int) $summary['fail'])); ?></p></div>
          <h2><?php echo esc_html__('Canonical Greenfield blueprint', 'eduardo-research-manager'); ?></h2>
          <?php if (is_wp_error($metadata)) : ?><div class="notice notice-error inline"><p><?php echo esc_html($metadata->get_error_message()); ?></p></div><?php else : ?>
          <table class="widefat striped" style="max-width:900px"><tbody>
            <tr><th><?php echo esc_html__('Identity', 'eduardo-research-manager'); ?></th><td><?php echo esc_html((string) $metadata['name']); ?></td></tr>
            <tr><th><?php echo esc_html__('Domain', 'eduardo-research-manager'); ?></th><td><code><?php echo esc_html((string) $metadata['canonical_domain']); ?></code></td></tr>
            <tr><th><?php echo esc_html__('Languages', 'eduardo-research-manager'); ?></th><td><?php echo esc_html(strtoupper(implode(' / ', (array) $metadata['languages']))); ?></td></tr>
            <tr><th><?php echo esc_html__('Resources', 'eduardo-research-manager'); ?></th><td><?php echo esc_html((string) $metadata['summary']['total_resources']); ?></td></tr>
            <tr><th><?php echo esc_html__('SHA-256', 'eduardo-research-manager'); ?></th><td><code><?php echo esc_html((string) $metadata['sha256']); ?></code></td></tr>
          </tbody></table><?php endif; ?>
          <p style="display:flex;gap:8px;align-items:center;flex-wrap:wrap"><a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=eduardo_research_manager_download_blueprint'), 'erm_blueprint_download')); ?>"><?php echo esc_html__('Download canonical JSON', 'eduardo-research-manager'); ?></a><code><?php echo esc_html(Eduardo_Research_Manager::blueprint_store()->export_filename()); ?></code></p>
          <h2><?php echo esc_html__('Greenfield pipeline preview', 'eduardo-research-manager'); ?></h2>
          <?php if (is_wp_error($preview)) : ?><div class="notice notice-error inline"><p><?php echo esc_html($preview->get_error_message()); ?></p></div><?php else : ?>
          <p><?php echo esc_html__('Execution order: Bootstrap → EN/ES pairing → Page hydration → Research Line relations.', 'eduardo-research-manager'); ?></p>
          <table class="widefat striped" style="max-width:900px"><thead><tr><th><?php echo esc_html__('Phase', 'eduardo-research-manager'); ?></th><th><?php echo esc_html__('State', 'eduardo-research-manager'); ?></th><th><?php echo esc_html__('Operations', 'eduardo-research-manager'); ?></th><th><?php echo esc_html__('Apply', 'eduardo-research-manager'); ?></th></tr></thead><tbody>
          <?php foreach ((array) $preview['phases'] as $phase => $phase_preview) : $state = (string) ($phase_preview['status'] ?? ((int) ($phase_preview['operation_count'] ?? 0) > 0 ? 'change' : 'steady')); ?>
            <tr><td><strong><?php echo esc_html(ucfirst((string) $phase)); ?></strong></td><td><?php echo esc_html(strtoupper($state)); ?></td><td><?php echo esc_html((string) ((int) ($phase_preview['operation_count'] ?? 0))); ?></td><td><?php echo ! empty($phase_preview['apply_allowed']) ? esc_html__('Allowed', 'eduardo-research-manager') : esc_html__('Blocked', 'eduardo-research-manager'); ?></td></tr>
          <?php endforeach; ?></tbody></table>
          <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:16px"><input type="hidden" name="action" value="eduardo_research_manager_apply_canonical"><?php wp_nonce_field('erm_greenfield_apply'); ?><?php submit_button(__('Apply canonical Greenfield site', 'eduardo-research-manager'), 'primary', 'submit', false, ! empty($preview['apply_allowed']) ? array() : array('disabled'=>'disabled')); ?></form>
          <?php endif; ?>
          <h2><?php echo esc_html__('Last reversible pipeline run', 'eduardo-research-manager'); ?></h2>
          <?php if (! is_array($last_run) || empty($last_run['snapshots'])) : ?><p><?php echo esc_html__('No reversible canonical pipeline run is currently stored.', 'eduardo-research-manager'); ?></p><?php else : ?>
          <table class="widefat striped" style="max-width:900px"><tbody><tr><th><?php echo esc_html__('Status', 'eduardo-research-manager'); ?></th><td><?php echo esc_html(strtoupper((string) ($last_run['status'] ?? 'unknown'))); ?></td></tr><tr><th><?php echo esc_html__('Applied at', 'eduardo-research-manager'); ?></th><td><?php echo esc_html((string) ($last_run['applied_at'] ?? '')); ?></td></tr><tr><th><?php echo esc_html__('Snapshots', 'eduardo-research-manager'); ?></th><td><?php echo esc_html((string) ((int) ($last_run['snapshot_count'] ?? 0))); ?></td></tr><tr><th><?php echo esc_html__('Blueprint SHA-256', 'eduardo-research-manager'); ?></th><td><code><?php echo esc_html((string) ($last_run['blueprint_sha256'] ?? '')); ?></code></td></tr></tbody></table>
          <?php if ('applied' === (string) ($last_run['status'] ?? '')) : ?><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:16px"><input type="hidden" name="action" value="eduardo_research_manager_rollback_canonical"><?php wp_nonce_field('erm_greenfield_rollback'); ?><?php submit_button(__('Rollback last canonical run', 'eduardo-research-manager'), 'secondary', 'submit', false); ?></form><?php endif; ?><?php endif; ?>
          <h2><?php echo esc_html__('Readiness checks', 'eduardo-research-manager'); ?></h2>
          <table class="widefat striped"><thead><tr><th><?php echo esc_html__('Resource', 'eduardo-research-manager'); ?></th><th><?php echo esc_html__('State', 'eduardo-research-manager'); ?></th><th><?php echo esc_html__('Result', 'eduardo-research-manager'); ?></th><th><?php echo esc_html__('Next controlled action', 'eduardo-research-manager'); ?></th></tr></thead><tbody>
          <?php foreach ($diagnostics['checks'] as $check) : ?><tr><td><strong><?php echo esc_html((string) $check['label']); ?></strong></td><td><?php echo esc_html(strtoupper((string) $check['status'])); ?></td><td><?php echo esc_html((string) $check['message']); ?></td><td><?php echo '' === (string) $check['next_action'] ? '—' : esc_html((string) $check['next_action']); ?></td></tr><?php endforeach; ?></tbody></table>
          <h2><?php echo esc_html__('Mutation discipline', 'eduardo-research-manager'); ?></h2><p><?php echo esc_html__('Every supported change follows Preview → Apply → Verify → Rollback. Evidence-sensitive academic claims are blocked at Apply until evidence is explicitly confirmed in the mutation plan.', 'eduardo-research-manager'); ?></p><p><code>Research Manager <?php echo esc_html(EDUARDO_RESEARCH_MANAGER_VERSION); ?></code></p>
        </div><?php
    }

    public function handle_apply_canonical(): void {
        $this->require_admin('erm_greenfield_apply');
        $blueprint = Eduardo_Research_Manager::blueprint_store()->canonical();
        if (is_wp_error($blueprint)) { $this->redirect_notice('error', $blueprint->get_error_message()); }
        $metadata = Eduardo_Research_Manager::blueprint_store()->metadata();
        if (is_wp_error($metadata)) { $this->redirect_notice('error', $metadata->get_error_message()); }
        $result = Eduardo_Research_Manager::pipeline()->apply($blueprint);
        if (is_wp_error($result)) { $this->redirect_notice('error', sprintf('Canonical pipeline failed: %s', $result->get_error_message())); }
        $snapshot_count = (int) ($result['snapshot_count'] ?? 0);
        if ($snapshot_count > 0) {
            update_option(self::LAST_RUN_OPTION, array('status'=>'applied','applied_at'=>(string) ($result['applied_at'] ?? gmdate(DATE_W3C)),'snapshot_count'=>$snapshot_count,'snapshots'=>$result['snapshots'] ?? array(),'blueprint_sha256'=>(string) ($metadata['sha256'] ?? '')), false);
            $this->redirect_notice('success', sprintf('Canonical Greenfield pipeline applied and verified. %d reversible snapshots stored.', $snapshot_count));
        }
        $this->redirect_notice('success', 'Canonical Greenfield site already matches the bundled blueprint. No mutations were required.');
    }

    public function handle_rollback_canonical(): void {
        $this->require_admin('erm_greenfield_rollback');
        $state = get_option(self::LAST_RUN_OPTION, array());
        if (! is_array($state) || 'applied' !== (string) ($state['status'] ?? '') || ! is_array($state['snapshots'] ?? null)) { $this->redirect_notice('warning', 'No applied canonical pipeline run is available for rollback.'); }
        $result = Eduardo_Research_Manager::pipeline()->rollback($state['snapshots']);
        if (is_wp_error($result)) { $this->redirect_notice('error', sprintf('Canonical rollback failed: %s', $result->get_error_message())); }
        $state['status'] = 'rolled-back';
        $state['rolled_back_at'] = (string) ($result['rolled_back_at'] ?? gmdate(DATE_W3C));
        update_option(self::LAST_RUN_OPTION, $state, false);
        $this->redirect_notice('success', sprintf('Canonical Greenfield run rolled back. %d snapshots restored.', (int) ($result['snapshot_count'] ?? 0)));
    }

    public function handle_download_blueprint(): void {
        if (! current_user_can('manage_options')) { wp_die(esc_html__('You do not have permission to export the Research blueprint.', 'eduardo-research-manager')); }
        check_admin_referer('erm_blueprint_download');
        $json = Eduardo_Research_Manager::blueprint_store()->export_json();
        if (is_wp_error($json)) { wp_die(esc_html($json->get_error_message())); }
        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . sanitize_file_name(Eduardo_Research_Manager::blueprint_store()->export_filename()) . '"');
        header('Content-Length: ' . strlen($json));
        echo $json;
        exit;
    }

    private function require_admin(string $nonce_action): void {
        if (! current_user_can('manage_options')) { wp_die(esc_html__('You do not have permission to operate Research Manager.', 'eduardo-research-manager')); }
        check_admin_referer($nonce_action);
    }

    private function redirect_notice(string $type, string $message): never {
        $type = in_array($type, array('success','warning','error','info'), true) ? $type : 'info';
        set_transient(self::NOTICE_PREFIX . get_current_user_id(), array('type'=>$type,'message'=>sanitize_text_field($message)), 60);
        wp_safe_redirect(admin_url('tools.php?page=eduardo-research-manager'));
        exit;
    }

    private function pull_notice(): array {
        $key = self::NOTICE_PREFIX . get_current_user_id();
        $notice = get_transient($key);
        delete_transient($key);
        return is_array($notice) ? $notice : array();
    }
}
