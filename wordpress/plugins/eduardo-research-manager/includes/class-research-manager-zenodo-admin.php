<?php
/** Zenodo Preview-only surface mounted inside Academic Connections. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Zenodo_Admin {
    private const PREVIEW_PREFIX = 'eduardo_research_zenodo_preview_';
    private const NOTICE_PREFIX = 'eduardo_research_zenodo_notice_';

    public function register(): void {
        add_action('admin_post_eduardo_research_zenodo_preview', array($this, 'handle_preview'));
        add_action('admin_footer-tools_page_eduardo-research-connections', array($this, 'render'));
    }

    public function render(): void {
        if (! current_user_can('manage_options')) { return; }

        $configuration = Eduardo_Research_Manager::zenodo()->configuration();
        $overview = Eduardo_Research_Manager::connections()->overview();
        $providers = is_array($overview['providers'] ?? null) ? $overview['providers'] : array();
        $orcid = is_array($providers['orcid'] ?? null) ? $providers['orcid'] : array();
        $verified_orcid = Eduardo_Research_Manager::zenodo()->normalize_orcid((string) ($orcid['identifier'] ?? ''));
        $preview = get_transient($this->preview_key());
        $preview = is_array($preview) ? $preview : array();
        if ($preview && $verified_orcid !== (string) ($preview['orcid'] ?? '')) {
            delete_transient($this->preview_key());
            $preview = array();
        }
        $notice = $this->pull_notice();
        ?>
        <div class="wrap" id="eduardo-research-zenodo-connections" style="margin-top:28px">
          <hr style="margin:24px 0">
          <h2><?php echo esc_html__('Zenodo published records', 'eduardo-research-manager'); ?></h2>
          <p><?php echo esc_html__('Zenodo discovery is bounded to published records whose creator metadata contains the exact verified ORCID iD. Matching names are never treated as identity evidence.', 'eduardo-research-manager'); ?></p>

          <?php if ($notice) : ?>
            <div class="notice notice-<?php echo esc_attr((string) ($notice['type'] ?? 'info')); ?> inline"><p><?php echo esc_html((string) ($notice['message'] ?? '')); ?></p></div>
          <?php endif; ?>

          <table class="widefat striped" style="max-width:1000px"><tbody>
            <tr><th><?php echo esc_html__('Environment', 'eduardo-research-manager'); ?></th><td><code><?php echo esc_html(strtoupper((string) ($configuration['environment'] ?? 'production'))); ?></code></td></tr>
            <tr><th><?php echo esc_html__('Secure token configuration', 'eduardo-research-manager'); ?></th><td><strong><?php echo ! empty($configuration['configured']) ? esc_html__('READY', 'eduardo-research-manager') : esc_html__('INCOMPLETE', 'eduardo-research-manager'); ?></strong></td></tr>
            <tr><th><?php echo esc_html__('Identity basis', 'eduardo-research-manager'); ?></th><td><code><?php echo esc_html__('EXACT VERIFIED ORCID IN CREATOR METADATA', 'eduardo-research-manager'); ?></code></td></tr>
            <tr><th><?php echo esc_html__('Verified ORCID available', 'eduardo-research-manager'); ?></th><td><strong><?php echo '' !== $verified_orcid ? esc_html__('YES', 'eduardo-research-manager') : esc_html__('NO', 'eduardo-research-manager'); ?></strong><?php if ('' !== $verified_orcid) : ?> — <code><?php echo esc_html($verified_orcid); ?></code><?php endif; ?></td></tr>
            <tr><th><?php echo esc_html__('Name matching', 'eduardo-research-manager'); ?></th><td><strong><?php echo esc_html__('DISABLED', 'eduardo-research-manager'); ?></strong></td></tr>
            <tr><th><?php echo esc_html__('Deposit write / publish', 'eduardo-research-manager'); ?></th><td><strong><?php echo esc_html__('DISABLED', 'eduardo-research-manager'); ?></strong></td></tr>
            <tr><th><?php echo esc_html__('Automatic local apply', 'eduardo-research-manager'); ?></th><td><strong><?php echo esc_html__('DISABLED', 'eduardo-research-manager'); ?></strong></td></tr>
          </tbody></table>

          <?php if (empty($configuration['configured'])) : ?>
            <div class="notice notice-warning inline" style="margin-top:12px"><p><?php echo esc_html__('Configure the Zenodo token securely before running a read-only Preview.', 'eduardo-research-manager'); ?> <code>EDUARDO_RESEARCH_ZENODO_TOKEN</code></p></div>
          <?php elseif ('' === $verified_orcid) : ?>
            <div class="notice notice-warning inline" style="margin-top:12px"><p><?php echo esc_html__('Verify or authenticate an ORCID iD before searching Zenodo records. Name-based discovery is intentionally unavailable.', 'eduardo-research-manager'); ?></p></div>
          <?php else : ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:14px">
              <input type="hidden" name="action" value="eduardo_research_zenodo_preview">
              <?php wp_nonce_field('erm_zenodo_preview'); ?>
              <?php submit_button(__('Preview Zenodo records and DOI reconciliation', 'eduardo-research-manager'), 'primary', 'submit', false); ?>
            </form>
          <?php endif; ?>

          <?php if ($preview) : ?>
            <?php
            $matches = is_array($preview['local_doi_matches'] ?? null) ? $preview['local_doi_matches'] : array();
            $remote_only = is_array($preview['remote_without_local'] ?? null) ? $preview['remote_without_local'] : array();
            ?>
            <h3><?php echo esc_html__('Zenodo reconciliation preview', 'eduardo-research-manager'); ?></h3>
            <div class="notice notice-info inline"><p><strong><?php echo esc_html__('Preview only.', 'eduardo-research-manager'); ?></strong> <?php echo esc_html__('Published Zenodo records were read from the exact verified ORCID creator identity and compared with local Outputs, Software and Datasets only by exact DOI or concept DOI. Nothing was imported, deposited or published.', 'eduardo-research-manager'); ?></p></div>

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;max-width:1000px;margin:14px 0">
              <?php foreach (array(
                  'Remote records'=>(int) ($preview['remote_record_count'] ?? 0),
                  'Zenodo search total'=>(int) ($preview['search_total'] ?? 0),
                  'Local DOI matches'=>(int) ($preview['local_doi_match_count'] ?? 0),
                  'Remote without local DOI'=>count($remote_only),
              ) as $label=>$value) : ?>
                <div class="card" style="margin:0;max-width:none;padding:14px"><strong><?php echo esc_html($label); ?></strong><p style="font-size:22px;margin:6px 0 0"><?php echo esc_html((string) $value); ?></p></div>
              <?php endforeach; ?>
            </div>

            <table class="widefat striped" style="max-width:1000px"><tbody>
              <tr><th><?php echo esc_html__('Identity basis', 'eduardo-research-manager'); ?></th><td><code><?php echo esc_html((string) ($preview['identity_basis'] ?? '')); ?></code></td></tr>
              <tr><th><?php echo esc_html__('ORCID', 'eduardo-research-manager'); ?></th><td><code><?php echo esc_html((string) ($preview['orcid'] ?? '')); ?></code></td></tr>
              <tr><th><?php echo esc_html__('Search truncated', 'eduardo-research-manager'); ?></th><td><strong><?php echo ! empty($preview['truncated']) ? esc_html__('YES — review bounded result set', 'eduardo-research-manager') : esc_html__('NO', 'eduardo-research-manager'); ?></strong></td></tr>
              <tr><th><?php echo esc_html__('Automatic local apply', 'eduardo-research-manager'); ?></th><td><strong><?php echo esc_html__('DISABLED', 'eduardo-research-manager'); ?></strong></td></tr>
              <tr><th><?php echo esc_html__('External writes / publishing', 'eduardo-research-manager'); ?></th><td><strong><?php echo esc_html__('DISABLED', 'eduardo-research-manager'); ?></strong></td></tr>
            </tbody></table>

            <?php if ($matches) : ?>
              <h4><?php echo esc_html__('Exact DOI / concept DOI matches', 'eduardo-research-manager'); ?></h4>
              <table class="widefat striped" style="max-width:1180px"><thead><tr>
                <th><?php echo esc_html__('Local type', 'eduardo-research-manager'); ?></th>
                <th><?php echo esc_html__('Match', 'eduardo-research-manager'); ?></th>
                <th><?php echo esc_html__('DOI', 'eduardo-research-manager'); ?></th>
                <th><?php echo esc_html__('Local title', 'eduardo-research-manager'); ?></th>
                <th><?php echo esc_html__('Zenodo title', 'eduardo-research-manager'); ?></th>
                <th><?php echo esc_html__('Title match', 'eduardo-research-manager'); ?></th>
              </tr></thead><tbody>
                <?php foreach (array_slice($matches, 0, 30) as $match) : ?>
                  <?php
                  if (! is_array($match)) { continue; }
                  $local = is_array($match['local'] ?? null) ? $match['local'] : array();
                  $remote = is_array($match['remote'] ?? null) ? $match['remote'] : array();
                  ?>
                  <tr>
                    <td><code><?php echo esc_html(strtoupper((string) ($local['kind'] ?? 'object'))); ?></code></td>
                    <td><code><?php echo esc_html((string) ($match['match_kind'] ?? 'doi')); ?></code></td>
                    <td><code><?php echo esc_html((string) ($match['doi'] ?? '')); ?></code></td>
                    <td><?php echo esc_html((string) ($local['title'] ?? '')); ?></td>
                    <td><?php if ('' !== (string) ($remote['record_url'] ?? '')) : ?><a href="<?php echo esc_url((string) $remote['record_url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html((string) ($remote['title'] ?? 'Untitled Zenodo record')); ?></a><?php else : ?><?php echo esc_html((string) ($remote['title'] ?? 'Untitled Zenodo record')); ?><?php endif; ?></td>
                    <td><strong><?php echo ! empty($match['title_matches']) ? esc_html__('YES', 'eduardo-research-manager') : esc_html__('REVIEW', 'eduardo-research-manager'); ?></strong></td>
                  </tr>
                <?php endforeach; ?>
              </tbody></table>
            <?php endif; ?>

            <?php if ($remote_only) : ?>
              <h4><?php echo esc_html__('Zenodo records without a local exact DOI match', 'eduardo-research-manager'); ?></h4>
              <ul style="list-style:disc;padding-left:22px;max-width:1050px">
                <?php foreach (array_slice($remote_only, 0, 20) as $record) : ?>
                  <?php if (! is_array($record)) { continue; } ?>
                  <li>
                    <?php if ('' !== (string) ($record['record_url'] ?? '')) : ?><a href="<?php echo esc_url((string) $record['record_url']); ?>" target="_blank" rel="noopener noreferrer"><strong><?php echo esc_html((string) ($record['title'] ?? 'Untitled Zenodo record')); ?></strong></a><?php else : ?><strong><?php echo esc_html((string) ($record['title'] ?? 'Untitled Zenodo record')); ?></strong><?php endif; ?>
                    <?php if ('' !== (string) ($record['resource_type'] ?? '')) : ?> — <?php echo esc_html((string) $record['resource_type']); ?><?php endif; ?>
                    <?php if ('' !== (string) ($record['doi'] ?? '')) : ?> — <code><?php echo esc_html((string) $record['doi']); ?></code><?php endif; ?>
                    <?php if ('' !== (string) ($record['concept_doi'] ?? '')) : ?> — <?php echo esc_html__('concept DOI', 'eduardo-research-manager'); ?> <code><?php echo esc_html((string) $record['concept_doi']); ?></code><?php endif; ?>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
          <?php endif; ?>
        </div>
        <?php
    }

    public function handle_preview(): void {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to manage academic connections.', 'eduardo-research-manager'));
        }
        check_admin_referer('erm_zenodo_preview');

        $preview = Eduardo_Research_Manager::zenodo()->reconciliation_preview(100);
        if (is_wp_error($preview)) {
            $this->set_notice('error', $preview->get_error_message());
            $this->redirect_back();
        }

        set_transient($this->preview_key(), $preview, 15 * MINUTE_IN_SECONDS);
        $this->set_notice('success', 'Zenodo published records were read from the exact verified ORCID creator identity. Review the DOI reconciliation Preview; no local or external data was changed.');
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
        (new Eduardo_Research_Manager_Zenodo_Admin())->register();
    }
}, 20);
