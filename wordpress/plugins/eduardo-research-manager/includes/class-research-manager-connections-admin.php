<?php
/** Read-only admin surface for academic connection readiness and provenance. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Connections_Admin {
    public function register(): void {
        add_action('admin_menu', array($this, 'menu'));
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
        ?>
        <div class="wrap">
          <h1><?php echo esc_html__('Academic Connections', 'eduardo-research-manager'); ?></h1>
          <p><?php echo esc_html__('Read-only connection readiness for research identity, discovery and scholarly metadata providers. External writes remain disabled.', 'eduardo-research-manager'); ?></p>

          <div class="notice notice-info inline"><p><strong><?php echo esc_html__('Read before write.', 'eduardo-research-manager'); ?></strong> <?php echo esc_html__('This phase can expose verified links, secure configuration readiness and public read capabilities, but it does not publish or mutate any external academic profile.', 'eduardo-research-manager'); ?></p></div>

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

          <h2><?php echo esc_html__('Connection policy', 'eduardo-research-manager'); ?></h2>
          <ul style="list-style:disc;padding-left:22px;max-width:950px">
            <li><?php echo esc_html__('Verified identifiers take precedence over name matching. A matching researcher name never creates a claimed connection.', 'eduardo-research-manager'); ?></li>
            <li><?php echo esc_html__('Credentials remain in secure configuration and are never rendered by this screen.', 'eduardo-research-manager'); ?></li>
            <li><?php echo esc_html__('Imported metadata will require reconciliation Preview before it can replace curated local content.', 'eduardo-research-manager'); ?></li>
            <li><?php echo esc_html__('Google Scholar remains manual-link/import-only; no Scholar scraping dependency is introduced.', 'eduardo-research-manager'); ?></li>
          </ul>

          <p><a class="button" href="<?php echo esc_url(add_query_arg('page', 'eduardo-research-evidence', admin_url('admin.php'))); ?>"><?php echo esc_html__('Manage verified academic identifiers', 'eduardo-research-manager'); ?></a></p>
        </div>
        <?php
    }

    private function next_action(array $provider): string {
        $state = (string) ($provider['status'] ?? 'disconnected');
        $mode = (string) ($provider['mode'] ?? '');
        if ('connected' === $state) { return 'Connection is established; next step is controlled read/sync preview.'; }
        if ('linked' === $state) { return 'Verified identifier is linked; authenticated access is not claimed.'; }
        if ('configured' === $state) { return 'Secure credentials are ready; authentication can be implemented next.'; }
        if ('available' === $state) { return 'Public read API is available; next step is a bounded read adapter.'; }
        if ('error' === $state) { return 'Review the recoverable connection error before syncing.'; }
        if ('manual_link' === $mode) { return 'Add and verify the external profile identifier in Academic Evidence.'; }
        return 'Complete secure provider configuration before connection or sync.';
    }
}
