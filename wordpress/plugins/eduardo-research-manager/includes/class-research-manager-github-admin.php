<?php
/** GitHub explicit-repository Preview-only surface mounted inside Academic Connections. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_GitHub_Admin {
    private const PREVIEW_PREFIX = 'eduardo_research_github_preview_';
    private const NOTICE_PREFIX = 'eduardo_research_github_notice_';

    public function register(): void {
        add_action('admin_post_eduardo_research_github_preview', array($this, 'handle_preview'));
        add_action('admin_footer-tools_page_eduardo-research-connections', array($this, 'render'));
    }

    public function render(): void {
        if (! current_user_can('manage_options')) { return; }

        $configuration = Eduardo_Research_Manager::github()->configuration();
        $preview = get_transient($this->preview_key());
        $preview = is_array($preview) ? $preview : array();
        $notice = $this->pull_notice();
        $selected = (string) ($preview['selected_repository'] ?? '');
        $remote = is_array($preview['remote'] ?? null) ? $preview['remote'] : array();
        $release = is_array($preview['latest_release'] ?? null) ? $preview['latest_release'] : array();
        $citation = is_array($preview['citation_cff'] ?? null) ? $preview['citation_cff'] : array();
        $candidate = is_array($preview['research_software_candidate'] ?? null) ? $preview['research_software_candidate'] : array();
        $local = is_array($preview['local_software'] ?? null) ? $preview['local_software'] : array();
        $comparison = is_array($preview['comparison'] ?? null) ? $preview['comparison'] : array();
        ?>
        <div class="wrap" id="eduardo-research-github-connections" style="margin-top:28px">
          <hr style="margin:24px 0">
          <h2><?php echo esc_html__('GitHub Research Software repository', 'eduardo-research-manager'); ?></h2>
          <p><?php echo esc_html__('Select one exact GitHub repository. Research Manager reads only that repository; it never lists, scans or auto-classifies repositories from an account or organization.', 'eduardo-research-manager'); ?></p>

          <?php if ($notice) : ?>
            <div class="notice notice-<?php echo esc_attr((string) ($notice['type'] ?? 'info')); ?> inline"><p><?php echo esc_html((string) ($notice['message'] ?? '')); ?></p></div>
          <?php endif; ?>

          <table class="widefat striped" style="max-width:1000px"><tbody>
            <tr><th><?php echo esc_html__('Selection basis', 'eduardo-research-manager'); ?></th><td><code><?php echo esc_html__('ONE EXPLICIT EXACT REPOSITORY', 'eduardo-research-manager'); ?></code></td></tr>
            <tr><th><?php echo esc_html__('GitHub API', 'eduardo-research-manager'); ?></th><td><strong><?php echo esc_html__('PUBLIC READ', 'eduardo-research-manager'); ?></strong></td></tr>
            <tr><th><?php echo esc_html__('Optional token', 'eduardo-research-manager'); ?></th><td><strong><?php echo ! empty($configuration['optional_token_set']) ? esc_html__('CONFIGURED', 'eduardo-research-manager') : esc_html__('NOT SET — public API still available', 'eduardo-research-manager'); ?></strong></td></tr>
            <tr><th><?php echo esc_html__('Account / organization repository listing', 'eduardo-research-manager'); ?></th><td><strong><?php echo esc_html__('DISABLED', 'eduardo-research-manager'); ?></strong></td></tr>
            <tr><th><?php echo esc_html__('Repository search / GraphQL discovery', 'eduardo-research-manager'); ?></th><td><strong><?php echo esc_html__('DISABLED', 'eduardo-research-manager'); ?></strong></td></tr>
            <tr><th><?php echo esc_html__('Automatic Research Software apply', 'eduardo-research-manager'); ?></th><td><strong><?php echo esc_html__('DISABLED', 'eduardo-research-manager'); ?></strong></td></tr>
            <tr><th><?php echo esc_html__('GitHub writes', 'eduardo-research-manager'); ?></th><td><strong><?php echo esc_html__('DISABLED', 'eduardo-research-manager'); ?></strong></td></tr>
          </tbody></table>

          <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:16px;max-width:1000px">
            <input type="hidden" name="action" value="eduardo_research_github_preview">
            <?php wp_nonce_field('erm_github_preview'); ?>
            <label for="eduardo-research-github-repository"><strong><?php echo esc_html__('Selected repository', 'eduardo-research-manager'); ?></strong></label>
            <p class="description"><?php echo esc_html__('Enter exactly github.com/owner/repository or owner/repository. Paths such as /issues, /tree, account URLs and search URLs are rejected.', 'eduardo-research-manager'); ?></p>
            <div style="display:flex;gap:8px;align-items:center;max-width:900px">
              <input id="eduardo-research-github-repository" name="repository" type="text" class="regular-text code" style="flex:1;max-width:none" value="<?php echo esc_attr($selected); ?>" placeholder="Emmakex/research-tool" required>
              <?php submit_button(__('Preview selected GitHub repository', 'eduardo-research-manager'), 'primary', 'submit', false); ?>
            </div>
          </form>

          <?php if ($preview) : ?>
            <h3><?php echo esc_html__('GitHub Research Software preview', 'eduardo-research-manager'); ?></h3>
            <div class="notice notice-info inline"><p><strong><?php echo esc_html__('Preview only.', 'eduardo-research-manager'); ?></strong> <?php echo esc_html__('Repository metadata was read only for the explicitly selected repository and compared with local Research Software by normalized owner/repository identity. No Research Software record or GitHub repository was changed.', 'eduardo-research-manager'); ?></p></div>

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px;max-width:1000px;margin:14px 0">
              <?php foreach (array(
                  __('Local exact repository match', 'eduardo-research-manager')=>! empty($preview['has_local_match']) ? __('YES', 'eduardo-research-manager') : __('NO', 'eduardo-research-manager'),
                  __('Latest release', 'eduardo-research-manager')=>'' !== (string) ($release['tag_name'] ?? '') ? (string) $release['tag_name'] : __('None detected', 'eduardo-research-manager'),
                  __('CITATION.cff', 'eduardo-research-manager')=>! empty($citation['present']) ? __('PRESENT', 'eduardo-research-manager') : __('NOT FOUND', 'eduardo-research-manager'),
                  __('Primary language', 'eduardo-research-manager')=>'' !== (string) ($remote['language'] ?? '') ? (string) $remote['language'] : __('Not reported', 'eduardo-research-manager'),
              ) as $label=>$value) : ?>
                <div class="card" style="margin:0;max-width:none;padding:14px"><strong><?php echo esc_html((string) $label); ?></strong><p style="font-size:18px;margin:6px 0 0"><?php echo esc_html((string) $value); ?></p></div>
              <?php endforeach; ?>
            </div>

            <table class="widefat striped" style="max-width:1100px"><tbody>
              <tr><th><?php echo esc_html__('Selected repository', 'eduardo-research-manager'); ?></th><td><code><?php echo esc_html($selected); ?></code><?php if ('' !== (string) ($remote['html_url'] ?? '')) : ?> — <a href="<?php echo esc_url((string) $remote['html_url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('Open repository', 'eduardo-research-manager'); ?></a><?php endif; ?></td></tr>
              <tr><th><?php echo esc_html__('Description', 'eduardo-research-manager'); ?></th><td><?php echo esc_html((string) ($remote['description'] ?? '')); ?></td></tr>
              <tr><th><?php echo esc_html__('Default branch', 'eduardo-research-manager'); ?></th><td><code><?php echo esc_html((string) ($remote['default_branch'] ?? '')); ?></code></td></tr>
              <tr><th><?php echo esc_html__('License', 'eduardo-research-manager'); ?></th><td><code><?php echo esc_html((string) ($remote['license_spdx'] ?? '')); ?></code><?php if ('' !== (string) ($remote['license_name'] ?? '')) : ?> — <?php echo esc_html((string) $remote['license_name']); ?><?php endif; ?></td></tr>
              <tr><th><?php echo esc_html__('Topics', 'eduardo-research-manager'); ?></th><td><?php echo esc_html(implode(', ', array_map('strval', is_array($remote['topics'] ?? null) ? $remote['topics'] : array()))); ?></td></tr>
              <tr><th><?php echo esc_html__('Repository state', 'eduardo-research-manager'); ?></th><td><?php echo ! empty($remote['archived']) ? esc_html__('ARCHIVED', 'eduardo-research-manager') : esc_html__('ACTIVE', 'eduardo-research-manager'); ?> · <?php echo ! empty($remote['fork']) ? esc_html__('FORK', 'eduardo-research-manager') : esc_html__('SOURCE / NON-FORK', 'eduardo-research-manager'); ?><?php if ('' !== (string) ($remote['visibility'] ?? '')) : ?> · <code><?php echo esc_html(strtoupper((string) $remote['visibility'])); ?></code><?php endif; ?></td></tr>
              <tr><th><?php echo esc_html__('Activity', 'eduardo-research-manager'); ?></th><td><?php echo esc_html(sprintf('★ %d · forks %d · open issues %d', (int) ($remote['stargazers_count'] ?? 0), (int) ($remote['forks_count'] ?? 0), (int) ($remote['open_issues_count'] ?? 0))); ?></td></tr>
              <tr><th><?php echo esc_html__('Last push', 'eduardo-research-manager'); ?></th><td><code><?php echo esc_html((string) ($remote['pushed_at'] ?? '')); ?></code></td></tr>
              <tr><th><?php echo esc_html__('Latest release', 'eduardo-research-manager'); ?></th><td><?php if ($release) : ?><code><?php echo esc_html((string) ($release['tag_name'] ?? '')); ?></code><?php if ('' !== (string) ($release['published_at'] ?? '')) : ?> — <?php echo esc_html((string) $release['published_at']); ?><?php endif; ?><?php if ('' !== (string) ($release['html_url'] ?? '')) : ?> — <a href="<?php echo esc_url((string) $release['html_url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('Open release', 'eduardo-research-manager'); ?></a><?php endif; ?><?php else : ?><?php echo esc_html__('No latest release was returned.', 'eduardo-research-manager'); ?><?php endif; ?></td></tr>
              <tr><th><?php echo esc_html__('CITATION.cff', 'eduardo-research-manager'); ?></th><td><strong><?php echo ! empty($citation['present']) ? esc_html__('PRESENT', 'eduardo-research-manager') : esc_html__('NOT FOUND', 'eduardo-research-manager'); ?></strong><?php if (! empty($citation['present']) && '' !== (string) ($citation['html_url'] ?? '')) : ?> — <a href="<?php echo esc_url((string) $citation['html_url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('Open citation file', 'eduardo-research-manager'); ?></a><?php endif; ?></td></tr>
              <tr><th><?php echo esc_html__('Automatic local apply', 'eduardo-research-manager'); ?></th><td><strong><?php echo esc_html__('DISABLED', 'eduardo-research-manager'); ?></strong></td></tr>
              <tr><th><?php echo esc_html__('External writes', 'eduardo-research-manager'); ?></th><td><strong><?php echo esc_html__('DISABLED', 'eduardo-research-manager'); ?></strong></td></tr>
            </tbody></table>

            <h4><?php echo esc_html__('Research Software candidate', 'eduardo-research-manager'); ?></h4>
            <table class="widefat striped" style="max-width:1100px"><thead><tr><th><?php echo esc_html__('Field', 'eduardo-research-manager'); ?></th><th><?php echo esc_html__('Candidate from selected repository', 'eduardo-research-manager'); ?></th></tr></thead><tbody>
              <?php foreach (array('title','excerpt','repository_url','version','release_date','license','programming_languages') as $field) : ?>
                <?php $value = $candidate[$field] ?? ''; ?>
                <tr><th><code><?php echo esc_html($field); ?></code></th><td><?php echo esc_html(is_array($value) ? implode(', ', array_map('strval', $value)) : (string) $value); ?></td></tr>
              <?php endforeach; ?>
            </tbody></table>

            <?php if ($local) : ?>
              <h4><?php echo esc_html__('Exact local Research Software reconciliation', 'eduardo-research-manager'); ?></h4>
              <p><?php echo esc_html(sprintf('Matched local Research Software #%d: %s', (int) ($local['post_id'] ?? 0), (string) ($local['title'] ?? ''))); ?></p>
              <table class="widefat striped" style="max-width:1180px"><thead><tr>
                <th><?php echo esc_html__('Field', 'eduardo-research-manager'); ?></th>
                <th><?php echo esc_html__('Local', 'eduardo-research-manager'); ?></th>
                <th><?php echo esc_html__('Selected repository', 'eduardo-research-manager'); ?></th>
                <th><?php echo esc_html__('Status', 'eduardo-research-manager'); ?></th>
              </tr></thead><tbody>
              <?php foreach ($comparison as $field=>$values) : ?>
                <?php if (! is_array($values)) { continue; } $local_value = $values['local'] ?? ''; $remote_value = $values['remote'] ?? ''; ?>
                <tr>
                  <th><code><?php echo esc_html((string) $field); ?></code></th>
                  <td><?php echo esc_html(is_array($local_value) ? implode(', ', array_map('strval', $local_value)) : (string) $local_value); ?></td>
                  <td><?php echo esc_html(is_array($remote_value) ? implode(', ', array_map('strval', $remote_value)) : (string) $remote_value); ?></td>
                  <td><strong><?php echo ! empty($values['matches']) ? esc_html__('MATCH', 'eduardo-research-manager') : esc_html__('REVIEW', 'eduardo-research-manager'); ?></strong></td>
                </tr>
              <?php endforeach; ?>
              </tbody></table>
            <?php else : ?>
              <div class="notice notice-warning inline" style="margin-top:14px"><p><?php echo esc_html__('No local Research Software record uses this exact normalized GitHub repository. This is only a candidate Preview; nothing will be created automatically.', 'eduardo-research-manager'); ?></p></div>
            <?php endif; ?>
          <?php endif; ?>
        </div>
        <?php
    }

    public function handle_preview(): void {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to manage academic connections.', 'eduardo-research-manager'));
        }
        check_admin_referer('erm_github_preview');

        $selected = isset($_POST['repository']) && is_scalar($_POST['repository'])
            ? sanitize_text_field(wp_unslash((string) $_POST['repository']))
            : '';
        $identity = Eduardo_Research_Manager::github()->normalize_repository($selected);
        if (! $identity) {
            delete_transient($this->preview_key());
            $this->set_notice('error', 'Select exactly one GitHub repository URL or owner/repository slug. Account URLs, subpaths and searches are not accepted.');
            $this->redirect_back();
        }

        $preview = Eduardo_Research_Manager::github()->selected_repository_preview((string) $identity['full_name']);
        if (is_wp_error($preview)) {
            delete_transient($this->preview_key());
            $this->set_notice('error', $preview->get_error_message());
            $this->redirect_back();
        }

        set_transient($this->preview_key(), $preview, 15 * MINUTE_IN_SECONDS);
        $this->set_notice('success', 'The explicitly selected GitHub repository was read and reconciled in Preview mode. No Research Software record or GitHub data was changed.');
        $this->redirect_back();
    }

    private function preview_key(): string {
        return self::PREVIEW_PREFIX . get_current_user_id();
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

    private function redirect_back(): never {
        wp_safe_redirect(add_query_arg('page', 'eduardo-research-connections', admin_url('admin.php')));
        exit;
    }
}

add_action('plugins_loaded', static function (): void {
    if (is_admin()) {
        (new Eduardo_Research_Manager_GitHub_Admin())->register();
    }
}, 20);
