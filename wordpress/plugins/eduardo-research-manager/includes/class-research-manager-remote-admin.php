<?php
/** Local WordPress control surface for the ChatGPT ↔ Research Manager connection. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Remote_Admin {
    private const TOKEN_TRANSIENT_PREFIX = 'erm_remote_token_';
    private const NOTICE_TRANSIENT_PREFIX = 'erm_remote_notice_';

    private Eduardo_Research_Manager_Remote_Credentials $credentials;
    private Eduardo_Research_Manager_Remote_Audit $audit;

    public function __construct(
        Eduardo_Research_Manager_Remote_Credentials $credentials,
        Eduardo_Research_Manager_Remote_Audit $audit
    ) {
        $this->credentials = $credentials;
        $this->audit = $audit;
    }

    public function register(): void {
        add_action('admin_menu', array($this, 'menu'));
        add_action('admin_post_eduardo_research_manager_remote', array($this, 'handle_action'));
    }

    public function menu(): void {
        add_management_page(
            'Research Manager Remote',
            'Research Manager Remote',
            'manage_options',
            'eduardo-research-manager-remote',
            array($this, 'render')
        );
    }

    public function render(): void {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to manage the Research Manager remote connection.', 'eduardo-research-manager'));
        }

        $state = $this->credentials->state();
        $token = get_transient($this->token_key());
        if (is_string($token) && '' !== $token) {
            delete_transient($this->token_key());
        } else {
            $token = '';
        }
        $notice = get_transient($this->notice_key());
        if (is_array($notice)) {
            delete_transient($this->notice_key());
        } else {
            $notice = array();
        }
        $supported = Eduardo_Research_Manager_Remote_Credentials::supported_scopes();
        $granted = is_array($state['scopes'] ?? null) ? $state['scopes'] : array();
        $audit = $this->audit->recent(20);
        ?>
        <div class="wrap">
          <h1><?php echo esc_html__('Research Manager Remote', 'eduardo-research-manager'); ?></h1>
          <p><?php echo esc_html__('Local security console for the authenticated ChatGPT → Research Manager bridge. Remote access can be revoked here without SSH and without changing a WordPress administrator password.', 'eduardo-research-manager'); ?></p>

          <?php if ($notice) : ?>
            <div class="notice notice-<?php echo esc_attr((string) ($notice['type'] ?? 'info')); ?> inline"><p><?php echo esc_html((string) ($notice['message'] ?? '')); ?></p></div>
          <?php endif; ?>

          <?php if ('' !== $token) : ?>
            <div class="notice notice-warning inline">
              <p><strong><?php echo esc_html__('Copy this token now. It is shown once and the Manager stores only a password hash.', 'eduardo-research-manager'); ?></strong></p>
              <p><code style="word-break:break-all;user-select:all"><?php echo esc_html($token); ?></code></p>
            </div>
          <?php endif; ?>

          <h2><?php echo esc_html__('Connection state', 'eduardo-research-manager'); ?></h2>
          <table class="widefat striped" style="max-width:1000px">
            <tbody>
              <tr><th><?php echo esc_html__('Connection ID', 'eduardo-research-manager'); ?></th><td><code><?php echo esc_html((string) ($state['connection_id'] ?? '')); ?></code></td></tr>
              <tr><th><?php echo esc_html__('Label', 'eduardo-research-manager'); ?></th><td><?php echo esc_html((string) ($state['label'] ?? '')); ?></td></tr>
              <tr><th><?php echo esc_html__('Remote access', 'eduardo-research-manager'); ?></th><td><strong><?php echo ! empty($state['enabled']) ? esc_html__('ENABLED', 'eduardo-research-manager') : esc_html__('DISABLED', 'eduardo-research-manager'); ?></strong></td></tr>
              <tr><th><?php echo esc_html__('Credential', 'eduardo-research-manager'); ?></th><td><?php echo ! empty($state['credential_present']) ? esc_html__('Present', 'eduardo-research-manager') : esc_html__('Not generated', 'eduardo-research-manager'); ?><?php if (! empty($state['token_prefix'])) : ?> — <code><?php echo esc_html((string) $state['token_prefix']); ?>…</code><?php endif; ?></td></tr>
              <tr><th><?php echo esc_html__('Writes granted by scopes', 'eduardo-research-manager'); ?></th><td><?php echo ! empty($state['writes_enabled']) ? esc_html__('Yes (M2 transport still not exposed)', 'eduardo-research-manager') : esc_html__('No', 'eduardo-research-manager'); ?></td></tr>
              <tr><th><?php echo esc_html__('Bound WordPress administrator', 'eduardo-research-manager'); ?></th><td><?php echo esc_html((string) ((int) ($state['wordpress_user_id'] ?? 0))); ?></td></tr>
              <tr><th><?php echo esc_html__('Generation', 'eduardo-research-manager'); ?></th><td><?php echo esc_html((string) ((int) ($state['generation'] ?? 0))); ?></td></tr>
              <tr><th><?php echo esc_html__('Last rotated', 'eduardo-research-manager'); ?></th><td><?php echo esc_html((string) ($state['rotated_at'] ?? '')); ?></td></tr>
              <tr><th><?php echo esc_html__('Last used', 'eduardo-research-manager'); ?></th><td><?php echo esc_html((string) ($state['last_used_at'] ?? '')); ?></td></tr>
              <tr><th><?php echo esc_html__('Last request ID', 'eduardo-research-manager'); ?></th><td><code><?php echo esc_html((string) ($state['last_request_id'] ?? '')); ?></code></td></tr>
            </tbody>
          </table>

          <h2><?php echo esc_html__('Connection controls', 'eduardo-research-manager'); ?></h2>
          <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:18px">
            <input type="hidden" name="action" value="eduardo_research_manager_remote">
            <?php wp_nonce_field('erm_remote_connection'); ?>
            <button class="button button-primary" name="remote_action" value="generate" type="submit"><?php echo esc_html__('Generate / replace token', 'eduardo-research-manager'); ?></button>
            <button class="button" name="remote_action" value="rotate" type="submit" <?php disabled(empty($state['credential_present'])); ?>><?php echo esc_html__('Rotate token', 'eduardo-research-manager'); ?></button>
            <?php if (! empty($state['enabled'])) : ?>
              <button class="button" name="remote_action" value="disable" type="submit"><?php echo esc_html__('Disable', 'eduardo-research-manager'); ?></button>
            <?php else : ?>
              <button class="button" name="remote_action" value="enable" type="submit" <?php disabled(empty($state['credential_present'])); ?>><?php echo esc_html__('Enable', 'eduardo-research-manager'); ?></button>
            <?php endif; ?>
            <button class="button" name="remote_action" value="revoke" type="submit" <?php disabled(empty($state['credential_present'])); ?>><?php echo esc_html__('Revoke credential', 'eduardo-research-manager'); ?></button>
          </form>

          <h2><?php echo esc_html__('Scopes', 'eduardo-research-manager'); ?></h2>
          <p><?php echo esc_html__('M1 exposes read-only endpoints. Write scopes can be prepared now but no remote write endpoint exists until M2.', 'eduardo-research-manager'); ?></p>
          <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="max-width:1000px">
            <input type="hidden" name="action" value="eduardo_research_manager_remote">
            <input type="hidden" name="remote_action" value="save_scopes">
            <?php wp_nonce_field('erm_remote_connection'); ?>
            <fieldset style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:8px 18px;margin:12px 0 18px">
              <?php foreach ($supported as $scope) : ?>
                <label><input type="checkbox" name="scopes[]" value="<?php echo esc_attr($scope); ?>" <?php checked(in_array($scope, $granted, true)); ?>> <code><?php echo esc_html($scope); ?></code></label>
              <?php endforeach; ?>
            </fieldset>
            <?php submit_button(__('Save scopes', 'eduardo-research-manager'), 'secondary', 'submit', false); ?>
          </form>

          <h2><?php echo esc_html__('M1 REST endpoints', 'eduardo-research-manager'); ?></h2>
          <p><code>/wp-json/<?php echo esc_html(Eduardo_Research_Manager_Remote_REST::NAMESPACE); ?>/status</code></p>
          <p><code>/wp-json/<?php echo esc_html(Eduardo_Research_Manager_Remote_REST::NAMESPACE); ?>/versions</code></p>
          <p><code>/wp-json/<?php echo esc_html(Eduardo_Research_Manager_Remote_REST::NAMESPACE); ?>/capabilities</code></p>
          <p><code>/wp-json/<?php echo esc_html(Eduardo_Research_Manager_Remote_REST::NAMESPACE); ?>/readiness</code></p>
          <p><code>/wp-json/<?php echo esc_html(Eduardo_Research_Manager_Remote_REST::NAMESPACE); ?>/diagnostics</code></p>

          <h2><?php echo esc_html__('Recent remote audit', 'eduardo-research-manager'); ?></h2>
          <?php if (! $audit) : ?>
            <p><?php echo esc_html__('No remote events recorded yet.', 'eduardo-research-manager'); ?></p>
          <?php else : ?>
            <table class="widefat striped" style="max-width:1200px">
              <thead><tr><th>Time</th><th>Event</th><th>Outcome</th><th>Route</th><th>Scope</th><th>Request ID</th><th>Error</th></tr></thead>
              <tbody>
                <?php foreach ($audit as $entry) : ?>
                  <tr>
                    <td><?php echo esc_html((string) ($entry['timestamp'] ?? '')); ?></td>
                    <td><?php echo esc_html((string) ($entry['event'] ?? '')); ?></td>
                    <td><?php echo esc_html((string) ($entry['outcome'] ?? '')); ?></td>
                    <td><code><?php echo esc_html((string) ($entry['route'] ?? '')); ?></code></td>
                    <td><code><?php echo esc_html((string) ($entry['scope'] ?? '')); ?></code></td>
                    <td><code><?php echo esc_html((string) ($entry['request_id'] ?? '')); ?></code></td>
                    <td><code><?php echo esc_html((string) ($entry['error_code'] ?? '')); ?></code></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>
        <?php
    }

    public function handle_action(): void {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to manage the Research Manager remote connection.', 'eduardo-research-manager'));
        }
        check_admin_referer('erm_remote_connection');

        $action = sanitize_key((string) ($_POST['remote_action'] ?? ''));
        $user_id = get_current_user_id();
        $result = null;
        $message = '';

        switch ($action) {
            case 'generate':
                $state = $this->credentials->state();
                $scopes = is_array($state['scopes'] ?? null) && $state['scopes']
                    ? $state['scopes']
                    : Eduardo_Research_Manager_Remote_Credentials::default_scopes();
                $result = $this->credentials->issue_token($scopes, $user_id);
                $message = 'Remote Manager token generated. Copy the one-time token shown on this page.';
                break;
            case 'rotate':
                $result = $this->credentials->rotate($user_id);
                $message = 'Remote Manager token rotated. The previous token is no longer valid.';
                break;
            case 'enable':
                $result = $this->credentials->set_enabled(true);
                $message = 'Remote Manager access enabled.';
                break;
            case 'disable':
                $result = $this->credentials->set_enabled(false);
                $message = 'Remote Manager access disabled. The credential remains stored for later re-enable.';
                break;
            case 'revoke':
                $result = $this->credentials->revoke();
                $message = 'Remote Manager credential revoked. A new token must be generated before remote access can resume.';
                break;
            case 'save_scopes':
                $scopes = isset($_POST['scopes']) && is_array($_POST['scopes'])
                    ? array_map('sanitize_text_field', wp_unslash($_POST['scopes']))
                    : array();
                $result = $this->credentials->set_scopes($scopes);
                $message = 'Remote Manager scopes updated.';
                break;
            default:
                $result = new WP_Error('validation_failed', 'Unknown remote connection action.');
        }

        if (is_wp_error($result)) {
            $this->notice('error', $result->get_error_message());
        } else {
            if (is_array($result) && isset($result['token']) && is_string($result['token'])) {
                set_transient($this->token_key(), $result['token'], 5 * MINUTE_IN_SECONDS);
            }
            $this->notice('success', $message);
            $state = $this->credentials->state();
            $this->audit->record(array(
                'connection_id' => (string) ($state['connection_id'] ?? ''),
                'wordpress_user_id' => $user_id,
                'event' => 'local-connection-' . $action,
                'outcome' => 'success',
                'route' => 'wp-admin',
                'scope' => 'credentials.manage',
            ));
        }

        wp_safe_redirect(admin_url('tools.php?page=eduardo-research-manager-remote'));
        exit;
    }

    private function token_key(): string {
        return self::TOKEN_TRANSIENT_PREFIX . get_current_user_id();
    }

    private function notice_key(): string {
        return self::NOTICE_TRANSIENT_PREFIX . get_current_user_id();
    }

    private function notice(string $type, string $message): void {
        set_transient($this->notice_key(), array('type'=>$type,'message'=>$message), 5 * MINUTE_IN_SECONDS);
    }
}
