<?php
/** Academic connection readiness plus controlled ORCID OAuth/read-preview actions. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Connections_Admin {
    private const NOTICE_PREFIX = 'eduardo_research_connections_notice_';
    private const ORCID_PREVIEW_PREFIX = 'eduardo_research_orcid_preview_';

    public function register(): void {
        add_action('admin_menu', array($this, 'menu'));
        add_action('admin_post_eduardo_research_orcid_connect', array($this, 'handle_orcid_connect'));
        add_action('admin_post_eduardo_research_orcid_callback', array($this, 'handle_orcid_callback'));
        add_action('admin_post_eduardo_research_orcid_preview', array($this, 'handle_orcid_preview'));
        add_action('admin_post_eduardo_research_orcid_disconnect', array($this, 'handle_orcid_disconnect'));
    }

    public function menu(): void {
        add_management_page(
            'Academic Connections',
            'Academic Connections',
            'manage_options',
            'eduardo-research-connections',
            array($this, 'render')
        );
    }

    public function render(): void {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access Academic Connections.', 'eduardo-research-manager'));
        }

        $overview = Eduardo_Research_Manager::connections()->overview();
        $providers = is_array($overview['providers'] ?? null) ? $overview['providers'] : array();
        $summary = is_array($overview['summary'] ?? null) ? $overview['summary'] : array();
        $orcid = is_array($providers['orcid'] ?? null) ? $providers['orcid'] : array();
        $orcid_config = Eduardo_Research_Manager::orcid()->configuration();
        $orcid_preview = get_transient($this->orcid_preview_key());
        $orcid_preview = is_array($orcid_preview) ? $orcid_preview : array();
        $notice = $this->pull_notice();
        ?>
        <div class="wrap">
          <h1><?php echo esc_html__('Academic Connections', 'eduardo-research-manager'); ?></h1>
          <p><?php echo esc_html__('Read-only connection readiness for research identity, discovery and scholarly metadata providers. External writes remain disabled.', 'eduardo-research-manager'); ?></p>

          <?php if ($notice) : ?>
            <div class="notice notice-<?php echo esc_attr((string) $notice['type']); ?> is-dismissible"><p><?php echo esc_html((string) $notice['message']); ?></p></div>
          <?php endif; ?>

          <div class="notice notice-info inline"><p><strong><?php echo esc_html__('Read before write.', 'eduardo-research-manager'); ?></strong> <?php echo esc_html__('Connections can authenticate identity and read scholarly metadata, but they cannot publish or mutate any external academic profile.', 'eduardo-research-manager'); ?></p></div>

          <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;max-width:1000px;margin:18px 0">
            <?php foreach (array('connected'=>'Connected','linked'=>'Verified links','available'=>'Public APIs','configured'=>'Configured','disconnected'=>'Needs setup','error'=>'Errors') as $key=>$label) : ?>
              <div class="card" style="margin:0;max-width:none;padding:14px">
                <strong><?php echo esc_html($label); ?></strong>
                <p style="font-size:22px;margin:6px 0 0"><?php echo esc_html((string) ((int) ($summary[$key] ?? 0))); ?></p>
              </div>
            <?php endforeach; ?>
          </div>

          <table class="widefat striped" style="max-width:1180px">
            <thead><tr>
              <th><?php echo esc_html__('Provider', 'eduardo-research-manager'); ?></th>
              <th><?php echo esc_html__('Status', 'eduardo-research-manager'); ?></th>
              <th><?php echo esc_html__('Mode', 'eduardo-research-manager'); ?></th>
              <th><?php echo esc_html__('Verified identifier', 'eduardo-research-manager'); ?></th>
              <th><?php echo esc_html__('Capabilities', 'eduardo-research-manager'); ?></th>
              <th><?php echo esc_html__('Next action', 'eduardo-research-manager'); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($providers as $provider) : ?>
              <?php
              $status = (string) ($provider['status'] ?? 'disconnected');
              $configuration = is_array($provider['configuration'] ?? null) ? $provider['configuration'] : array();
              $identifier = (string) ($provider['identifier'] ?? '');
              $identifier_url = (string) ($provider['identifier_url'] ?? '');
              ?>
              <tr>
                <td><strong><?php echo esc_html((string) ($provider['label'] ?? $provider['provider'] ?? '')); ?></strong><br><code><?php echo esc_html((string) ($provider['provider'] ?? '')); ?></code></td>
                <td><strong><?php echo esc_html(strtoupper($status)); ?></strong><?php if (! empty($provider['last_error'])) : ?><br><span><?php echo esc_html((string) $provider['last_error']); ?></span><?php endif; ?></td>
                <td><code><?php echo esc_html((string) ($provider['mode'] ?? '')); ?></code></td>
                <td>
                  <?php if ('' !== $identifier_url) : ?><a href="<?php echo esc_url($identifier_url); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html('' !== $identifier ? $identifier : $identifier_url); ?></a>
                  <?php elseif ('' !== $identifier) : ?><code><?php echo esc_html($identifier); ?></code>
                  <?php else : ?>—<?php endif; ?>
                </td>
                <td><?php echo esc_html(implode(', ', array_map('strval', (array) ($provider['capabilities'] ?? array())))); ?></td>
                <td><?php echo esc_html($this->next_action($provider)); ?>
                  <?php if (! empty($configuration['missing'])) : ?><br><small><?php echo esc_html(sprintf('Secure configuration: %s', implode(', ', array_map('strval', (array) $configuration['missing'])))); ?></small><?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>

          <h2><?php echo esc_html__('ORCID authenticated connection', 'eduardo-research-manager'); ?></h2>
          <p><?php echo esc_html__('ORCID authentication verifies the researcher iD and enables controlled profile/works reads. No ORCID record is written by Research Manager.', 'eduardo-research-manager'); ?></p>
          <table class="widefat striped" style="max-width:1000px"><tbody>
            <tr><th><?php echo esc_html__('Environment', 'eduardo-research-manager'); ?></th><td><code><?php echo esc_html(strtoupper((string) ($orcid_config['environment'] ?? 'sandbox'))); ?></code></td></tr>
            <tr><th><?php echo esc_html__('OAuth configuration', 'eduardo-research-manager'); ?></th><td><?php echo esc_html(! empty($orcid_config['configured']) ? 'READY' : 'INCOMPLETE'); ?></td></tr>
            <tr><th><?php echo esc_html__('Connection status', 'eduardo-research-manager'); ?></th><td><strong><?php echo esc_html(strtoupper((string) ($orcid['status'] ?? 'disconnected'))); ?></strong></td></tr>
            <tr><th><?php echo esc_html__('Authenticated / linked iD', 'eduardo-research-manager'); ?></th><td><?php echo '' !== (string) ($orcid['identifier'] ?? '') ? '<code>' . esc_html((string) $orcid['identifier']) . '</code>' : '—'; ?></td></tr>
            <tr><th><?php echo esc_html__('External writes', 'eduardo-research-manager'); ?></th><td><strong><?php echo esc_html__('DISABLED', 'eduardo-research-manager'); ?></strong></td></tr>
          </tbody></table>

          <?php if (empty($orcid_config['configured'])) : ?>
            <div class="notice notice-warning inline" style="margin-top:12px"><p><?php echo esc_html__('Complete the secure ORCID constants before starting OAuth:', 'eduardo-research-manager'); ?> <code><?php echo esc_html(implode(', ', array_map('strval', (array) ($orcid_config['missing'] ?? array())))); ?></code></p></div>
          <?php else : ?>
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:14px">
              <?php if ('connected' !== (string) ($orcid['status'] ?? '')) : ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                  <input type="hidden" name="action" value="eduardo_research_orcid_connect">
                  <?php wp_nonce_field('erm_orcid_connect'); ?>
                  <?php submit_button(__('Connect ORCID', 'eduardo-research-manager'), 'primary', 'submit', false); ?>
                </form>
              <?php else : ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                  <input type="hidden" name="action" value="eduardo_research_orcid_preview">
                  <?php wp_nonce_field('erm_orcid_preview'); ?>
                  <?php submit_button(__('Preview ORCID reconciliation', 'eduardo-research-manager'), 'primary', 'submit', false); ?>
                </form>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('Disconnect ORCID and remove the local OAuth token?');">
                  <input type="hidden" name="action" value="eduardo_research_orcid_disconnect">
                  <?php wp_nonce_field('erm_orcid_disconnect'); ?>
                  <?php submit_button(__('Disconnect ORCID', 'eduardo-research-manager'), 'secondary', 'submit', false); ?>
                </form>
              <?php endif; ?>
            </div>
          <?php endif; ?>

          <?php if ($orcid_preview) : ?>
            <?php $remote_profile = is_array($orcid_preview['remote_profile'] ?? null) ? $orcid_preview['remote_profile'] : array(); ?>
            <h3><?php echo esc_html__('ORCID reconciliation preview', 'eduardo-research-manager'); ?></h3>
            <div class="notice notice-info inline"><p><strong><?php echo esc_html__('Preview only.', 'eduardo-research-manager'); ?></strong> <?php echo esc_html__('No local Academic Evidence and no external ORCID data has been changed.', 'eduardo-research-manager'); ?></p></div>
            <table class="widefat striped" style="max-width:1000px;margin-top:10px"><tbody>
              <tr><th><?php echo esc_html__('Authenticated iD', 'eduardo-research-manager'); ?></th><td><code><?php echo esc_html((string) ($orcid_preview['identifier'] ?? '')); ?></code></td></tr>
              <tr><th><?php echo esc_html__('Remote display name', 'eduardo-research-manager'); ?></th><td><?php echo esc_html((string) ($remote_profile['display_name'] ?? '')); ?></td></tr>
              <tr><th><?php echo esc_html__('Remote works', 'eduardo-research-manager'); ?></th><td><?php echo esc_html((string) ((int) ($orcid_preview['remote_work_count'] ?? 0))); ?></td></tr>
              <tr><th><?php echo esc_html__('Identifier match', 'eduardo-research-manager'); ?></th><td><?php echo ! empty($orcid_preview['identifier_matches']) ? esc_html__('YES', 'eduardo-research-manager') : esc_html__('NO — review required', 'eduardo-research-manager'); ?></td></tr>
              <tr><th><?php echo esc_html__('Automatic local apply', 'eduardo-research-manager'); ?></th><td><strong><?php echo esc_html__('DISABLED', 'eduardo-research-manager'); ?></strong></td></tr>
            </tbody></table>
            <?php if (! empty($orcid_preview['remote_works']) && is_array($orcid_preview['remote_works'])) : ?>
              <h4><?php echo esc_html__('Remote work sample', 'eduardo-research-manager'); ?></h4>
              <ul style="list-style:disc;padding-left:22px;max-width:950px">
                <?php foreach (array_slice($orcid_preview['remote_works'], 0, 10) as $work) : ?>
                  <?php if (! is_array($work)) { continue; } ?>
                  <li><?php echo esc_html(trim((string) ($work['title'] ?? 'Untitled work') . ('' !== (string) ($work['publication_date'] ?? '') ? ' — ' . (string) $work['publication_date'] : ''))); ?></li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
          <?php endif; ?>

          <h2><?php echo esc_html__('Connection policy', 'eduardo-research-manager'); ?></h2>
          <ul style="list-style:disc;padding-left:22px;max-width:950px">
            <li><?php echo esc_html__('Verified identifiers take precedence over name matching. A matching researcher name never creates a claimed connection.', 'eduardo-research-manager'); ?></li>
            <li><?php echo esc_html__('Credentials and OAuth token material remain in secure server-side storage and are never rendered by this screen.', 'eduardo-research-manager'); ?></li>
            <li><?php echo esc_html__('Imported metadata requires reconciliation Preview before it can replace curated local content.', 'eduardo-research-manager'); ?></li>
            <li><?php echo esc_html__('Google Scholar remains manual-link/import-only; no Scholar scraping dependency is introduced.', 'eduardo-research-manager'); ?></li>
          </ul>

          <p><a class="button" href="<?php echo esc_url(add_query_arg('page', 'eduardo-research-evidence', admin_url('admin.php'))); ?>"><?php echo esc_html__('Manage verified academic identifiers', 'eduardo-research-manager'); ?></a></p>
        </div>
        <?php
    }

    public function handle_orcid_connect(): void {
        $this->authorize_action('erm_orcid_connect');
        $result = Eduardo_Research_Manager::orcid()->begin_authorization();
        if (is_wp_error($result)) {
            $this->set_notice('error', $result->get_error_message());
            $this->redirect_back();
        }
        $url = (string) ($result['authorization_url'] ?? '');
        $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        if (! in_array($host, array('orcid.org','sandbox.orcid.org'), true)) {
            $this->set_notice('error', 'The generated ORCID authorization target was not trusted.');
            $this->redirect_back();
        }
        wp_redirect($url, 302, 'Research Manager');
        exit;
    }

    public function handle_orcid_callback(): void {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to complete an ORCID connection.', 'eduardo-research-manager'));
        }
        $error = isset($_GET['error']) ? sanitize_text_field(wp_unslash((string) $_GET['error'])) : '';
        if ('' !== $error) {
            $description = isset($_GET['error_description']) ? sanitize_text_field(wp_unslash((string) $_GET['error_description'])) : $error;
            $this->set_notice('error', 'ORCID authorization was not completed: ' . $description);
            $this->redirect_back();
        }
        $code = isset($_GET['code']) ? sanitize_text_field(wp_unslash((string) $_GET['code'])) : '';
        $state = isset($_GET['state']) ? sanitize_text_field(wp_unslash((string) $_GET['state'])) : '';
        $result = Eduardo_Research_Manager::orcid()->exchange_code($code, $state);
        if (is_wp_error($result)) {
            $this->set_notice('error', $result->get_error_message());
            $this->redirect_back();
        }
        delete_transient($this->orcid_preview_key());
        $this->set_notice('success', sprintf('ORCID connected and authenticated as %s. External writes remain disabled.', (string) ($result['identifier'] ?? '')));
        $this->redirect_back();
    }

    public function handle_orcid_preview(): void {
        $this->authorize_action('erm_orcid_preview');
        $preview = Eduardo_Research_Manager::orcid()->sync_preview();
        if (is_wp_error($preview)) {
            $this->set_notice('error', $preview->get_error_message());
            $this->redirect_back();
        }
        set_transient($this->orcid_preview_key(), $preview, 15 * MINUTE_IN_SECONDS);
        $this->set_notice('success', 'ORCID profile and works were read successfully. Review the reconciliation preview before any future local import.');
        $this->redirect_back();
    }

    public function handle_orcid_disconnect(): void {
        $this->authorize_action('erm_orcid_disconnect');
        $result = Eduardo_Research_Manager::orcid()->disconnect();
        if (is_wp_error($result)) {
            $this->set_notice('error', $result->get_error_message());
            $this->redirect_back();
        }
        delete_transient($this->orcid_preview_key());
        $this->set_notice('success', 'ORCID OAuth token removed. Curated Research content and Academic Evidence were not deleted.');
        $this->redirect_back();
    }

    private function authorize_action(string $nonce_action): void {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to manage academic connections.', 'eduardo-research-manager'));
        }
        check_admin_referer($nonce_action);
    }

    private function next_action(array $provider): string {
        $state = (string) ($provider['status'] ?? 'disconnected');
        $mode = (string) ($provider['mode'] ?? '');
        if ('connected' === $state) { return 'Connection is established; run a controlled read/reconciliation preview.'; }
        if ('linked' === $state) { return 'Verified identifier is linked; authenticate when secure OAuth configuration is ready.'; }
        if ('configured' === $state) { return 'Secure credentials are ready; authenticate the provider connection.'; }
        if ('available' === $state) { return 'Public read API is available; next step is a bounded read adapter.'; }
        if ('error' === $state) { return 'Review the recoverable connection error before syncing.'; }
        if ('manual_link' === $mode) { return 'Add and verify the external profile identifier in Academic Evidence.'; }
        return 'Complete secure provider configuration before connection or sync.';
    }

    private function set_notice(string $type, string $message): void {
        set_transient(self::NOTICE_PREFIX . get_current_user_id(), array('type'=>$type,'message'=>sanitize_text_field($message)), 120);
    }

    private function pull_notice(): array {
        $key = self::NOTICE_PREFIX . get_current_user_id();
        $notice = get_transient($key);
        delete_transient($key);
        return is_array($notice) ? $notice : array();
    }

    private function orcid_preview_key(): string {
        return self::ORCID_PREVIEW_PREFIX . get_current_user_id();
    }

    private function redirect_back(): never {
        wp_safe_redirect(add_query_arg('page', 'eduardo-research-connections', admin_url('admin.php')));
        exit;
    }
}
