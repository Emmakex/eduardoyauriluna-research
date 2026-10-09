<?php
/** Minimal operational admin surface for Research Manager readiness. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Admin {
    public function register(): void {
        add_action('admin_menu', array($this, 'menu'));
    }

    public function menu(): void {
        add_management_page(
            'Research Manager',
            'Research Manager',
            'manage_options',
            'eduardo-research-manager',
            array($this, 'render')
        );
    }

    public function render(): void {
        if (! current_user_can('manage_options')) { wp_die(esc_html__('You do not have permission to access Research Manager.', 'eduardo-research-manager')); }
        $diagnostics = (new Eduardo_Research_Manager_Diagnostics())->run();
        $summary = $diagnostics['summary'];
        ?>
        <div class="wrap">
          <h1><?php echo esc_html__('Research Manager', 'eduardo-research-manager'); ?></h1>
          <p><?php echo esc_html__('Control plane for structured Research content. The Theme remains the frontend rendering authority.', 'eduardo-research-manager'); ?></p>

          <div class="notice <?php echo $diagnostics['ready'] ? 'notice-success' : 'notice-warning'; ?> inline">
            <p><strong><?php echo esc_html($diagnostics['ready'] ? 'Research contract ready' : 'Research contract needs attention'); ?></strong>
            <?php echo esc_html(sprintf(' — %d passed, %d warnings, %d failures.', (int) $summary['pass'], (int) $summary['warning'], (int) $summary['fail'])); ?></p>
          </div>

          <h2><?php echo esc_html__('Readiness checks', 'eduardo-research-manager'); ?></h2>
          <table class="widefat striped">
            <thead><tr><th><?php echo esc_html__('Resource', 'eduardo-research-manager'); ?></th><th><?php echo esc_html__('State', 'eduardo-research-manager'); ?></th><th><?php echo esc_html__('Result', 'eduardo-research-manager'); ?></th><th><?php echo esc_html__('Next controlled action', 'eduardo-research-manager'); ?></th></tr></thead>
            <tbody>
            <?php foreach ($diagnostics['checks'] as $check) : ?>
              <tr>
                <td><strong><?php echo esc_html((string) $check['label']); ?></strong></td>
                <td><?php echo esc_html(strtoupper((string) $check['status'])); ?></td>
                <td><?php echo esc_html((string) $check['message']); ?></td>
                <td><?php echo '' === (string) $check['next_action'] ? '—' : esc_html((string) $check['next_action']); ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>

          <h2><?php echo esc_html__('Mutation discipline', 'eduardo-research-manager'); ?></h2>
          <p><?php echo esc_html__('Every supported change follows Preview → Apply → Verify → Rollback. Evidence-sensitive academic claims are blocked at Apply until evidence is explicitly confirmed in the mutation plan.', 'eduardo-research-manager'); ?></p>
          <p><code>Research Manager <?php echo esc_html(EDUARDO_RESEARCH_MANAGER_VERSION); ?></code></p>
        </div>
        <?php
    }
}
