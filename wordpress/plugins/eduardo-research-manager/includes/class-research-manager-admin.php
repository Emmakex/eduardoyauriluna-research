<?php
/** Operational admin surface for the Greenfield Research Manager. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Admin {
    private const LAST_RUN_OPTION = 'eduardo_research_manager_last_pipeline_run';
    private const NOTICE_PREFIX = 'eduardo_research_manager_notice_';
    private const PAGE_PREVIEW_PREFIX = 'eduardo_research_manager_page_preview_';
    private const PAGE_EDIT_PREFIX = 'eduardo_research_manager_page_edit_';

    public function register(): void {
        add_action('admin_menu', array($this, 'menu'));
        add_action('admin_post_eduardo_research_manager_apply_canonical', array($this, 'handle_apply_canonical'));
        add_action('admin_post_eduardo_research_manager_rollback_canonical', array($this, 'handle_rollback_canonical'));
        add_action('admin_post_eduardo_research_manager_download_blueprint', array($this, 'handle_download_blueprint'));
        add_action('admin_post_eduardo_research_manager_preview_page', array($this, 'handle_preview_page'));
        add_action('admin_post_eduardo_research_manager_apply_page_preview', array($this, 'handle_apply_page_preview'));
        add_action('admin_post_eduardo_research_manager_rollback_page_edit', array($this, 'handle_rollback_page_edit'));
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
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access Research Manager.', 'eduardo-research-manager'));
        }

        $diagnostics = Eduardo_Research_Manager::diagnostics()->run();
        $summary = $diagnostics['summary'];
        $metadata = Eduardo_Research_Manager::blueprint_store()->metadata();
        $blueprint = is_wp_error($metadata) ? $metadata : Eduardo_Research_Manager::blueprint_store()->canonical();
        $preview = is_wp_error($blueprint) ? $blueprint : Eduardo_Research_Manager::pipeline()->preview($blueprint);
        $last_run = get_option(self::LAST_RUN_OPTION, array());
        $notice = $this->pull_notice();

        $page_contracts = Eduardo_Research_Manager::contract()->pages();
        $languages = Eduardo_Research_Manager::contract()->languages();
        $page_key = $this->selected_page_key($page_contracts);
        $page_language = $this->selected_language($languages);
        $page_resource = '' !== $page_key && '' !== $page_language
            ? Eduardo_Research_Manager::page_editor()->inspect($page_key, $page_language)
            : new WP_Error('research_manager_page_editor_selection_missing', 'No Theme Page/language selection is available.');
        $page_schema = is_wp_error($page_resource)
            ? array()
            : Eduardo_Research_Manager::pages()->slot_schema($page_key, $page_language);
        $prepared_page = get_transient($this->page_preview_transient_key());
        $prepared_page = is_array($prepared_page)
            && $page_key === (string) ($prepared_page['key'] ?? '')
            && $page_language === (string) ($prepared_page['language'] ?? '')
            ? $prepared_page
            : array();
        $last_page_edit = get_transient($this->page_edit_transient_key());
        $last_page_edit = is_array($last_page_edit) ? $last_page_edit : array();
        ?>
        <div class="wrap">
          <h1><?php echo esc_html__('Research Manager', 'eduardo-research-manager'); ?></h1>
          <p><?php echo esc_html__('Greenfield control plane for the canonical Eduardo Research site. The Theme remains the frontend rendering authority.', 'eduardo-research-manager'); ?></p>

          <?php if ($notice) : ?>
            <div class="notice notice-<?php echo esc_attr((string) $notice['type']); ?> is-dismissible">
              <p><?php echo esc_html((string) $notice['message']); ?></p>
            </div>
          <?php endif; ?>

          <div class="notice <?php echo $diagnostics['ready'] ? 'notice-success' : 'notice-warning'; ?> inline">
            <p><strong><?php echo esc_html($diagnostics['ready'] ? 'Research contract ready' : 'Research contract needs attention'); ?></strong>
            <?php echo esc_html(sprintf(' — %d passed, %d warnings, %d failures.', (int) $summary['pass'], (int) $summary['warning'], (int) $summary['fail'])); ?></p>
          </div>

          <h2><?php echo esc_html__('Canonical Greenfield blueprint', 'eduardo-research-manager'); ?></h2>
          <?php if (is_wp_error($metadata)) : ?>
            <div class="notice notice-error inline"><p><?php echo esc_html($metadata->get_error_message()); ?></p></div>
          <?php else : ?>
            <table class="widefat striped" style="max-width:900px">
              <tbody>
                <tr><th><?php echo esc_html__('Identity', 'eduardo-research-manager'); ?></th><td><?php echo esc_html((string) $metadata['name']); ?></td></tr>
                <tr><th><?php echo esc_html__('Domain', 'eduardo-research-manager'); ?></th><td><code><?php echo esc_html((string) $metadata['canonical_domain']); ?></code></td></tr>
                <tr><th><?php echo esc_html__('Languages', 'eduardo-research-manager'); ?></th><td><?php echo esc_html(strtoupper(implode(' / ', (array) $metadata['languages']))); ?></td></tr>
                <tr><th><?php echo esc_html__('Resources', 'eduardo-research-manager'); ?></th><td><?php echo esc_html((string) ($metadata['summary']['total_resources'] ?? 0)); ?></td></tr>
                <tr><th><?php echo esc_html__('SHA-256', 'eduardo-research-manager'); ?></th><td><code><?php echo esc_html((string) $metadata['sha256']); ?></code></td></tr>
              </tbody>
            </table>
          <?php endif; ?>

          <p style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
            <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=eduardo_research_manager_download_blueprint'), 'erm_blueprint_download')); ?>"><?php echo esc_html__('Download canonical JSON', 'eduardo-research-manager'); ?></a>
            <code><?php echo esc_html(Eduardo_Research_Manager::blueprint_store()->export_filename()); ?></code>
          </p>

          <h2><?php echo esc_html__('Greenfield pipeline preview', 'eduardo-research-manager'); ?></h2>
          <?php if (is_wp_error($preview)) : ?>
            <div class="notice notice-error inline"><p><?php echo esc_html($preview->get_error_message()); ?></p></div>
          <?php else : ?>
            <p><?php echo esc_html__('Execution order: Bootstrap → EN/ES pairing → Page hydration → Research Line relations.', 'eduardo-research-manager'); ?></p>
            <table class="widefat striped" style="max-width:900px">
              <thead>
                <tr>
                  <th><?php echo esc_html__('Phase', 'eduardo-research-manager'); ?></th>
                  <th><?php echo esc_html__('State', 'eduardo-research-manager'); ?></th>
                  <th><?php echo esc_html__('Operations', 'eduardo-research-manager'); ?></th>
                  <th><?php echo esc_html__('Apply', 'eduardo-research-manager'); ?></th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ((array) ($preview['phases'] ?? array()) as $phase => $phase_preview) : ?>
                <?php $state = (string) ($phase_preview['status'] ?? ((int) ($phase_preview['operation_count'] ?? 0) > 0 ? 'change' : 'steady')); ?>
                <tr>
                  <td><strong><?php echo esc_html(ucfirst((string) $phase)); ?></strong></td>
                  <td><?php echo esc_html(strtoupper($state)); ?></td>
                  <td><?php echo esc_html((string) ((int) ($phase_preview['operation_count'] ?? 0))); ?></td>
                  <td><?php echo ! empty($phase_preview['apply_allowed']) ? esc_html__('Allowed', 'eduardo-research-manager') : esc_html__('Blocked', 'eduardo-research-manager'); ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:16px">
              <input type="hidden" name="action" value="eduardo_research_manager_apply_canonical">
              <?php wp_nonce_field('erm_greenfield_apply'); ?>
              <?php
              submit_button(
                  __('Apply canonical Greenfield site', 'eduardo-research-manager'),
                  'primary',
                  'submit',
                  false,
                  ! empty($preview['apply_allowed']) ? array() : array('disabled'=>'disabled')
              );
              ?>
            </form>
          <?php endif; ?>

          <h2><?php echo esc_html__('Structured Page editor', 'eduardo-research-manager'); ?></h2>
          <p><?php echo esc_html__('Edit only Theme-owned content slots. Layout, components, routes and rendering remain controlled by the Research Theme; Gutenberg layout composition is not used.', 'eduardo-research-manager'); ?></p>

          <form method="get" action="<?php echo esc_url(admin_url('tools.php')); ?>" style="display:flex;gap:12px;align-items:end;flex-wrap:wrap;margin:12px 0 18px">
            <input type="hidden" name="page" value="eduardo-research-manager">
            <p style="margin:0">
              <label for="erm-page-key"><strong><?php echo esc_html__('Theme Page', 'eduardo-research-manager'); ?></strong></label><br>
              <select id="erm-page-key" name="erm_page_key">
                <?php foreach ($page_contracts as $candidate_key => $definition) : ?>
                  <?php $label = is_array($definition) ? (string) (($definition['label'] ?? $definition['labels']['en'] ?? ucfirst((string) $candidate_key))) : ucfirst((string) $candidate_key); ?>
                  <option value="<?php echo esc_attr((string) $candidate_key); ?>" <?php selected($page_key, (string) $candidate_key); ?>><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
              </select>
            </p>
            <p style="margin:0">
              <label for="erm-page-language"><strong><?php echo esc_html__('Language', 'eduardo-research-manager'); ?></strong></label><br>
              <select id="erm-page-language" name="erm_page_language">
                <?php foreach ($languages as $candidate_language) : ?>
                  <option value="<?php echo esc_attr((string) $candidate_language); ?>" <?php selected($page_language, (string) $candidate_language); ?>><?php echo esc_html(strtoupper((string) $candidate_language)); ?></option>
                <?php endforeach; ?>
              </select>
            </p>
            <?php submit_button(__('Open structured Page', 'eduardo-research-manager'), 'secondary', 'submit', false); ?>
          </form>

          <?php if (is_wp_error($page_resource)) : ?>
            <div class="notice notice-warning inline"><p><?php echo esc_html($page_resource->get_error_message()); ?></p></div>
          <?php elseif (! $page_schema) : ?>
            <div class="notice notice-warning inline"><p><?php echo esc_html__('The selected Theme Page does not expose editable structured slots.', 'eduardo-research-manager'); ?></p></div>
          <?php else : ?>
            <p>
              <strong><?php echo esc_html(strtoupper($page_language) . ' · ' . $page_key); ?></strong>
              — <a href="<?php echo esc_url((string) $page_resource['url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('View rendered Page', 'eduardo-research-manager'); ?></a>
            </p>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="max-width:1000px">
              <input type="hidden" name="action" value="eduardo_research_manager_preview_page">
              <input type="hidden" name="page_key" value="<?php echo esc_attr($page_key); ?>">
              <input type="hidden" name="page_language" value="<?php echo esc_attr($page_language); ?>">
              <?php wp_nonce_field('erm_page_preview'); ?>
              <table class="form-table" role="presentation">
                <tbody>
                <?php foreach ($page_schema as $slot => $default) : ?>
                  <?php
                  $value = $page_resource['effective_slots'][$slot] ?? $default;
                  $is_list = is_array($default);
                  $textarea = $is_list
                      ? implode("\n", array_map(static fn($item): string => is_scalar($item) ? (string) $item : '', is_array($value) ? $value : array()))
                      : (is_scalar($value) ? (string) $value : '');
                  $label = ucwords(str_replace(array('_','-'), ' ', (string) $slot));
                  ?>
                  <tr>
                    <th scope="row"><label for="erm-slot-<?php echo esc_attr((string) $slot); ?>"><?php echo esc_html($label); ?></label></th>
                    <td>
                      <textarea class="large-text" rows="<?php echo esc_attr($is_list ? '5' : '3'); ?>" id="erm-slot-<?php echo esc_attr((string) $slot); ?>" name="slots[<?php echo esc_attr((string) $slot); ?>]"><?php echo esc_textarea($textarea); ?></textarea>
                      <?php if ($is_list) : ?><p class="description"><?php echo esc_html__('One item per line.', 'eduardo-research-manager'); ?></p><?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
              <?php submit_button(__('Preview structured changes', 'eduardo-research-manager'), 'secondary', 'submit', false); ?>
            </form>

            <?php if ($prepared_page) : ?>
              <?php
              $prepared_status = (string) ($prepared_page['status'] ?? 'unknown');
              $prepared_actions = is_array($prepared_page['plan']['actions'] ?? null) ? count($prepared_page['plan']['actions']) : 0;
              ?>
              <h3><?php echo esc_html__('Prepared Page change', 'eduardo-research-manager'); ?></h3>
              <table class="widefat striped" style="max-width:900px">
                <tbody>
                  <tr><th><?php echo esc_html__('Status', 'eduardo-research-manager'); ?></th><td><?php echo esc_html(strtoupper($prepared_status)); ?></td></tr>
                  <tr><th><?php echo esc_html__('Mutation actions', 'eduardo-research-manager'); ?></th><td><?php echo esc_html((string) $prepared_actions); ?></td></tr>
                  <tr><th><?php echo esc_html__('Apply gate', 'eduardo-research-manager'); ?></th><td><?php echo ! empty($prepared_page['apply_allowed']) ? esc_html__('Allowed', 'eduardo-research-manager') : esc_html__('Blocked', 'eduardo-research-manager'); ?></td></tr>
                  <tr><th><?php echo esc_html__('Plan ID', 'eduardo-research-manager'); ?></th><td><code><?php echo esc_html((string) ($prepared_page['plan_id'] ?? 'already-matching')); ?></code></td></tr>
                </tbody>
              </table>
              <?php if ('change' === $prepared_status && ! empty($prepared_page['apply_allowed'])) : ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:12px">
                  <input type="hidden" name="action" value="eduardo_research_manager_apply_page_preview">
                  <?php wp_nonce_field('erm_page_apply'); ?>
                  <?php submit_button(__('Apply prepared Page change', 'eduardo-research-manager'), 'primary', 'submit', false); ?>
                </form>
              <?php elseif ('already-matching' === $prepared_status) : ?>
                <p><strong><?php echo esc_html__('No mutation required: the selected Page already matches this structured content.', 'eduardo-research-manager'); ?></strong></p>
              <?php endif; ?>
            <?php endif; ?>

            <?php if ($last_page_edit && ! empty($last_page_edit['snapshot_id'])) : ?>
              <h3><?php echo esc_html__('Latest interactive Page edit', 'eduardo-research-manager'); ?></h3>
              <p>
                <strong><?php echo esc_html(strtoupper((string) ($last_page_edit['language'] ?? '')) . ' · ' . (string) ($last_page_edit['key'] ?? '')); ?></strong>
                — <?php echo esc_html((string) ($last_page_edit['applied_at'] ?? '')); ?>
              </p>
              <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="eduardo_research_manager_rollback_page_edit">
                <?php wp_nonce_field('erm_page_rollback'); ?>
                <?php submit_button(__('Rollback latest Page edit', 'eduardo-research-manager'), 'secondary', 'submit', false); ?>
              </form>
            <?php endif; ?>
          <?php endif; ?>

          <h2><?php echo esc_html__('Last reversible pipeline run', 'eduardo-research-manager'); ?></h2>
          <?php if (! is_array($last_run) || empty($last_run['snapshots'])) : ?>
            <p><?php echo esc_html__('No reversible canonical pipeline run is currently stored.', 'eduardo-research-manager'); ?></p>
          <?php else : ?>
            <table class="widefat striped" style="max-width:900px">
              <tbody>
                <tr><th><?php echo esc_html__('Status', 'eduardo-research-manager'); ?></th><td><?php echo esc_html(strtoupper((string) ($last_run['status'] ?? 'unknown'))); ?></td></tr>
                <tr><th><?php echo esc_html__('Applied at', 'eduardo-research-manager'); ?></th><td><?php echo esc_html((string) ($last_run['applied_at'] ?? '')); ?></td></tr>
                <tr><th><?php echo esc_html__('Snapshots', 'eduardo-research-manager'); ?></th><td><?php echo esc_html((string) ((int) ($last_run['snapshot_count'] ?? 0))); ?></td></tr>
                <tr><th><?php echo esc_html__('Blueprint SHA-256', 'eduardo-research-manager'); ?></th><td><code><?php echo esc_html((string) ($last_run['blueprint_sha256'] ?? '')); ?></code></td></tr>
              </tbody>
            </table>
            <?php if ('applied' === (string) ($last_run['status'] ?? '')) : ?>
              <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:16px">
                <input type="hidden" name="action" value="eduardo_research_manager_rollback_canonical">
                <?php wp_nonce_field('erm_greenfield_rollback'); ?>
                <?php submit_button(__('Rollback last canonical run', 'eduardo-research-manager'), 'secondary', 'submit', false); ?>
              </form>
            <?php endif; ?>
          <?php endif; ?>

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

    public function handle_apply_canonical(): void {
        $this->require_admin('erm_greenfield_apply');
        $blueprint = Eduardo_Research_Manager::blueprint_store()->canonical();
        if (is_wp_error($blueprint)) { $this->redirect_notice('error', $blueprint->get_error_message()); }

        $metadata = Eduardo_Research_Manager::blueprint_store()->metadata();
        if (is_wp_error($metadata)) { $this->redirect_notice('error', $metadata->get_error_message()); }

        $result = Eduardo_Research_Manager::pipeline()->apply($blueprint);
        if (is_wp_error($result)) {
            $this->redirect_notice('error', sprintf('Canonical pipeline failed: %s', $result->get_error_message()));
        }

        $snapshot_count = (int) ($result['snapshot_count'] ?? 0);
        if ($snapshot_count > 0) {
            update_option(
                self::LAST_RUN_OPTION,
                array(
                    'status'=>'applied',
                    'applied_at'=>(string) ($result['applied_at'] ?? gmdate(DATE_W3C)),
                    'snapshot_count'=>$snapshot_count,
                    'snapshots'=>$result['snapshots'] ?? array(),
                    'blueprint_sha256'=>(string) ($metadata['sha256'] ?? ''),
                ),
                false
            );
            $this->redirect_notice('success', sprintf('Canonical Greenfield pipeline applied and verified. %d reversible snapshots stored.', $snapshot_count));
        }

        $this->redirect_notice('success', 'Canonical Greenfield site already matches the bundled blueprint. No mutations were required.');
    }

    public function handle_rollback_canonical(): void {
        $this->require_admin('erm_greenfield_rollback');
        $state = get_option(self::LAST_RUN_OPTION, array());
        if (! is_array($state) || 'applied' !== (string) ($state['status'] ?? '') || ! is_array($state['snapshots'] ?? null)) {
            $this->redirect_notice('warning', 'No applied canonical pipeline run is available for rollback.');
        }

        $result = Eduardo_Research_Manager::pipeline()->rollback($state['snapshots']);
        if (is_wp_error($result)) {
            $this->redirect_notice('error', sprintf('Canonical rollback failed: %s', $result->get_error_message()));
        }

        $state['status'] = 'rolled-back';
        $state['rolled_back_at'] = (string) ($result['rolled_back_at'] ?? gmdate(DATE_W3C));
        update_option(self::LAST_RUN_OPTION, $state, false);
        $this->redirect_notice('success', sprintf('Canonical Greenfield run rolled back. %d snapshots restored.', (int) ($result['snapshot_count'] ?? 0)));
    }

    public function handle_download_blueprint(): void {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to export the Research blueprint.', 'eduardo-research-manager'));
        }
        check_admin_referer('erm_blueprint_download');

        $json = Eduardo_Research_Manager::blueprint_store()->export_json();
        if (is_wp_error($json)) { wp_die(esc_html($json->get_error_message())); }

        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . sanitize_file_name(Eduardo_Research_Manager::blueprint_store()->export_filename()) . '"');
        header('Content-Length: ' . strlen($json));
        echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- controlled JSON download.
        exit;
    }

    public function handle_preview_page(): void {
        $this->require_admin('erm_page_preview');
        $selection = $this->validated_page_selection($_POST);
        if (is_wp_error($selection)) { $this->redirect_notice('error', $selection->get_error_message()); }
        [$key, $language] = $selection;

        $schema = Eduardo_Research_Manager::pages()->slot_schema($key, $language);
        $raw_slots = isset($_POST['slots']) && is_array($_POST['slots']) ? wp_unslash($_POST['slots']) : array();
        $slots = array();
        foreach ($schema as $slot => $default) {
            if (! array_key_exists($slot, $raw_slots)) { continue; }
            $raw = $raw_slots[$slot];
            if (is_array($default)) {
                if (! is_scalar($raw)) {
                    $this->redirect_notice('error', sprintf('Page slot "%s" must be supplied as newline-separated text.', (string) $slot), $this->page_selection_args($key, $language));
                }
                $lines = preg_split('/\R/u', (string) $raw) ?: array();
                $slots[$slot] = array_values(array_filter(array_map('trim', $lines), static fn(string $value): bool => '' !== $value));
                continue;
            }
            if (! is_scalar($raw)) {
                $this->redirect_notice('error', sprintf('Page slot "%s" must be scalar text.', (string) $slot), $this->page_selection_args($key, $language));
            }
            $slots[$slot] = (string) $raw;
        }

        if (! $slots) {
            $this->redirect_notice('warning', 'No Theme Page slots were supplied for Preview.', $this->page_selection_args($key, $language));
        }

        $prepared = Eduardo_Research_Manager::page_editor()->preview($key, $language, $slots);
        if (is_wp_error($prepared)) {
            $this->redirect_notice('error', sprintf('Page Preview failed: %s', $prepared->get_error_message()), $this->page_selection_args($key, $language));
        }

        set_transient($this->page_preview_transient_key(), $prepared, 30 * MINUTE_IN_SECONDS);
        $message = 'already-matching' === (string) ($prepared['status'] ?? '')
            ? 'Structured Page Preview verified: no mutation is required.'
            : 'Structured Page Preview prepared. Review the Apply gate before committing the change.';
        $this->redirect_notice('success', $message, $this->page_selection_args($key, $language));
    }

    public function handle_apply_page_preview(): void {
        $this->require_admin('erm_page_apply');
        $prepared = get_transient($this->page_preview_transient_key());
        if (! is_array($prepared)) {
            $this->redirect_notice('warning', 'The prepared Page Preview expired or is unavailable. Prepare a new Preview before Apply.');
        }

        $key = sanitize_key((string) ($prepared['key'] ?? ''));
        $language = sanitize_key((string) ($prepared['language'] ?? ''));
        $args = $this->page_selection_args($key, $language);
        $result = Eduardo_Research_Manager::page_editor()->apply_preview($prepared);
        if (is_wp_error($result)) {
            if ('research_manager_page_editor_stale_preview' === $result->get_error_code()) {
                delete_transient($this->page_preview_transient_key());
            }
            $this->redirect_notice('error', sprintf('Page Apply failed: %s', $result->get_error_message()), $args);
        }

        delete_transient($this->page_preview_transient_key());
        $snapshot_id = sanitize_text_field((string) ($result['snapshot_id'] ?? ''));
        if ('' !== $snapshot_id) {
            set_transient(
                $this->page_edit_transient_key(),
                array(
                    'key'=>$key,
                    'language'=>$language,
                    'snapshot_id'=>$snapshot_id,
                    'applied_at'=>gmdate(DATE_W3C),
                    'plan_id'=>(string) ($result['plan_id'] ?? ''),
                ),
                DAY_IN_SECONDS
            );
            $this->redirect_notice('success', 'Structured Page change applied and verified. A reversible snapshot is available.', $args);
        }

        $this->redirect_notice('success', 'Structured Page state already matched the prepared Preview. No mutation was required.', $args);
    }

    public function handle_rollback_page_edit(): void {
        $this->require_admin('erm_page_rollback');
        $edit = get_transient($this->page_edit_transient_key());
        if (! is_array($edit) || empty($edit['snapshot_id'])) {
            $this->redirect_notice('warning', 'No reversible interactive Page edit is available.');
        }

        $key = sanitize_key((string) ($edit['key'] ?? ''));
        $language = sanitize_key((string) ($edit['language'] ?? ''));
        $result = Eduardo_Research_Manager::page_editor()->rollback((string) $edit['snapshot_id']);
        if (is_wp_error($result)) {
            $this->redirect_notice('error', sprintf('Page rollback failed: %s', $result->get_error_message()), $this->page_selection_args($key, $language));
        }

        delete_transient($this->page_edit_transient_key());
        delete_transient($this->page_preview_transient_key());
        $this->redirect_notice('success', 'Latest interactive Page edit rolled back successfully.', $this->page_selection_args($key, $language));
    }

    private function require_admin(string $nonce_action): void {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to operate Research Manager.', 'eduardo-research-manager'));
        }
        check_admin_referer($nonce_action);
    }

    private function selected_page_key(array $page_contracts): string {
        $candidate = isset($_GET['erm_page_key']) ? sanitize_key((string) wp_unslash($_GET['erm_page_key'])) : 'home';
        if (isset($page_contracts[$candidate])) { return $candidate; }
        $keys = array_keys($page_contracts);
        return isset($keys[0]) ? sanitize_key((string) $keys[0]) : '';
    }

    private function selected_language(array $languages): string {
        $candidate = isset($_GET['erm_page_language']) ? sanitize_key((string) wp_unslash($_GET['erm_page_language'])) : 'en';
        if (in_array($candidate, $languages, true)) { return $candidate; }
        return isset($languages[0]) ? sanitize_key((string) $languages[0]) : '';
    }

    private function validated_page_selection(array $input): array|WP_Error {
        $key = sanitize_key((string) wp_unslash($input['page_key'] ?? ''));
        $language = sanitize_key((string) wp_unslash($input['page_language'] ?? ''));
        if (! isset(Eduardo_Research_Manager::contract()->pages()[$key])) {
            return new WP_Error('research_manager_page_editor_unknown_page', 'The selected Page is outside the active Research Theme contract.');
        }
        if (! in_array($language, Eduardo_Research_Manager::contract()->languages(), true)) {
            return new WP_Error('research_manager_page_editor_unknown_language', 'The selected language is outside the active Research Theme contract.');
        }
        return array($key, $language);
    }

    private function page_selection_args(string $key, string $language): array {
        return array('erm_page_key'=>sanitize_key($key),'erm_page_language'=>sanitize_key($language));
    }

    private function page_preview_transient_key(): string {
        return self::PAGE_PREVIEW_PREFIX . get_current_user_id();
    }

    private function page_edit_transient_key(): string {
        return self::PAGE_EDIT_PREFIX . get_current_user_id();
    }

    private function redirect_notice(string $type, string $message, array $args = array()): never {
        $type = in_array($type, array('success','warning','error','info'), true) ? $type : 'info';
        set_transient(
            self::NOTICE_PREFIX . get_current_user_id(),
            array('type'=>$type,'message'=>sanitize_text_field($message)),
            60
        );
        $url = add_query_arg(array_merge(array('page'=>'eduardo-research-manager'), $args), admin_url('tools.php'));
        wp_safe_redirect($url);
        exit;
    }

    private function pull_notice(): array {
        $key = self::NOTICE_PREFIX . get_current_user_id();
        $notice = get_transient($key);
        delete_transient($key);
        return is_array($notice) ? $notice : array();
    }
}
