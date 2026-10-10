<?php
/** Dedicated structured authoring UI for Research Insights. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Insight_Admin {
    private const PREVIEW_PREFIX = 'eduardo_research_manager_insight_preview_';
    private const EDIT_PREFIX = 'eduardo_research_manager_insight_edit_';
    private const NOTICE_PREFIX = 'eduardo_research_manager_insight_notice_';

    public function register(): void {
        add_action('admin_menu', array($this, 'menu'));
        add_action('admin_post_eduardo_research_manager_preview_insight', array($this, 'handle_preview'));
        add_action('admin_post_eduardo_research_manager_apply_insight_preview', array($this, 'handle_apply'));
        add_action('admin_post_eduardo_research_manager_rollback_insight_edit', array($this, 'handle_rollback'));
    }

    public function menu(): void {
        add_management_page(
            'Research Insights',
            'Research Insights',
            'manage_options',
            'eduardo-research-insights',
            array($this, 'render')
        );
    }

    public function render(): void {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access Research Insights.', 'eduardo-research-manager'));
        }

        $languages = Eduardo_Research_Manager::insights()->languages();
        $language = $this->selected_language($languages);
        $types = function_exists('eduardo_research_insight_types') ? eduardo_research_insight_types($language) : Eduardo_Research_Manager::insights()->types();
        $rows = Eduardo_Research_Manager::insight_editor()->list($language);
        $selected_id = isset($_GET['erm_insight_id']) ? absint($_GET['erm_insight_id']) : 0;
        $selected = $selected_id > 0 ? Eduardo_Research_Manager::insight_editor()->inspect($selected_id) : null;
        $prepared = get_transient($this->preview_key());
        $prepared = is_array($prepared) ? $prepared : array();
        $last_edit = get_transient($this->edit_key());
        $last_edit = is_array($last_edit) ? $last_edit : array();
        $notice = $this->pull_notice();
        ?>
        <div class="wrap">
          <h1><?php echo esc_html__('Research Insights', 'eduardo-research-manager'); ?></h1>
          <p><?php echo esc_html__('Structured editorial authoring for Research notes. The Manager controls content and metadata; the Research Theme controls rendering and layout.', 'eduardo-research-manager'); ?></p>

          <?php if ($notice) : ?>
            <div class="notice notice-<?php echo esc_attr((string) $notice['type']); ?> is-dismissible"><p><?php echo esc_html((string) $notice['message']); ?></p></div>
          <?php endif; ?>

          <form method="get" action="<?php echo esc_url(admin_url('tools.php')); ?>" style="display:flex;gap:12px;align-items:end;margin:12px 0 18px">
            <input type="hidden" name="page" value="eduardo-research-insights">
            <p style="margin:0">
              <label for="erm-insight-language"><strong><?php echo esc_html__('Language', 'eduardo-research-manager'); ?></strong></label><br>
              <select id="erm-insight-language" name="erm_insight_language">
                <?php foreach ($languages as $candidate) : ?><option value="<?php echo esc_attr((string) $candidate); ?>" <?php selected($language, (string) $candidate); ?>><?php echo esc_html(strtoupper((string) $candidate)); ?></option><?php endforeach; ?>
              </select>
            </p>
            <?php submit_button(__('Filter Insights', 'eduardo-research-manager'), 'secondary', 'submit', false); ?>
          </form>

          <h2><?php echo esc_html__('Create Research Insight', 'eduardo-research-manager'); ?></h2>
          <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="max-width:1000px">
            <input type="hidden" name="action" value="eduardo_research_manager_preview_insight">
            <input type="hidden" name="editor_mode" value="create">
            <?php wp_nonce_field('erm_insight_preview'); ?>
            <table class="form-table" role="presentation"><tbody>
              <tr><th><label for="erm-create-title"><?php echo esc_html__('Title', 'eduardo-research-manager'); ?></label></th><td><input class="regular-text" id="erm-create-title" name="title" required></td></tr>
              <tr><th><label for="erm-create-slug"><?php echo esc_html__('Slug', 'eduardo-research-manager'); ?></label></th><td><input class="regular-text" id="erm-create-slug" name="slug"><p class="description"><?php echo esc_html__('Optional; generated from the title when empty.', 'eduardo-research-manager'); ?></p></td></tr>
              <tr><th><label for="erm-create-language"><?php echo esc_html__('Language', 'eduardo-research-manager'); ?></label></th><td><select id="erm-create-language" name="language"><?php foreach ($languages as $candidate) : ?><option value="<?php echo esc_attr((string) $candidate); ?>" <?php selected($language, (string) $candidate); ?>><?php echo esc_html(strtoupper((string) $candidate)); ?></option><?php endforeach; ?></select></td></tr>
              <tr><th><label for="erm-create-type"><?php echo esc_html__('Editorial type', 'eduardo-research-manager'); ?></label></th><td><select id="erm-create-type" name="insight_type"><?php foreach ($types as $value => $label) : ?><option value="<?php echo esc_attr((string) $value); ?>"><?php echo esc_html((string) $label); ?></option><?php endforeach; ?></select></td></tr>
              <tr><th><label for="erm-create-status"><?php echo esc_html__('Initial status', 'eduardo-research-manager'); ?></label></th><td><select id="erm-create-status" name="status"><option value="draft"><?php echo esc_html__('Draft', 'eduardo-research-manager'); ?></option><option value="publish"><?php echo esc_html__('Publish', 'eduardo-research-manager'); ?></option></select></td></tr>
              <tr><th><label for="erm-create-excerpt"><?php echo esc_html__('Excerpt', 'eduardo-research-manager'); ?></label></th><td><textarea class="large-text" rows="3" id="erm-create-excerpt" name="excerpt"></textarea></td></tr>
              <tr><th><label for="erm-create-content"><?php echo esc_html__('Content', 'eduardo-research-manager'); ?></label></th><td><textarea class="large-text code" rows="10" id="erm-create-content" name="content"></textarea><p class="description"><?php echo esc_html__('Safe editorial HTML is permitted. Layout remains Theme-controlled.', 'eduardo-research-manager'); ?></p></td></tr>
            </tbody></table>
            <?php submit_button(__('Preview new Insight', 'eduardo-research-manager'), 'secondary', 'submit', false); ?>
          </form>

          <h2><?php echo esc_html(sprintf('Existing Insights — %s', strtoupper($language))); ?></h2>
          <?php if (is_wp_error($rows)) : ?>
            <div class="notice notice-error inline"><p><?php echo esc_html($rows->get_error_message()); ?></p></div>
          <?php elseif (! $rows) : ?>
            <p><?php echo esc_html__('No structured Research Insights exist in this language yet.', 'eduardo-research-manager'); ?></p>
          <?php else : ?>
            <table class="widefat striped" style="max-width:1100px"><thead><tr><th><?php echo esc_html__('Title', 'eduardo-research-manager'); ?></th><th><?php echo esc_html__('Type', 'eduardo-research-manager'); ?></th><th><?php echo esc_html__('Status', 'eduardo-research-manager'); ?></th><th><?php echo esc_html__('Action', 'eduardo-research-manager'); ?></th></tr></thead><tbody>
              <?php foreach ($rows as $row) : ?>
                <tr><td><strong><?php echo esc_html((string) $row['title']); ?></strong></td><td><?php echo esc_html((string) ($types[$row['insight_type']] ?? $row['insight_type'])); ?></td><td><?php echo esc_html(strtoupper((string) $row['status'])); ?></td><td><a href="<?php echo esc_url($this->url(array('erm_insight_language'=>$language,'erm_insight_id'=>(int) $row['post_id']))); ?>"><?php echo esc_html__('Edit structured content', 'eduardo-research-manager'); ?></a></td></tr>
              <?php endforeach; ?>
            </tbody></table>
          <?php endif; ?>

          <?php if ($selected_id > 0) : ?>
            <h2><?php echo esc_html__('Edit selected Insight', 'eduardo-research-manager'); ?></h2>
            <?php if (is_wp_error($selected)) : ?>
              <div class="notice notice-error inline"><p><?php echo esc_html($selected->get_error_message()); ?></p></div>
            <?php else : ?>
              <?php $selected_types = function_exists('eduardo_research_insight_types') ? eduardo_research_insight_types((string) $selected['language']) : $types; ?>
              <p><strong><?php echo esc_html((string) $selected['title']); ?></strong> — <a href="<?php echo esc_url((string) $selected['url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('View rendered Insight', 'eduardo-research-manager'); ?></a></p>
              <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="max-width:1000px">
                <input type="hidden" name="action" value="eduardo_research_manager_preview_insight">
                <input type="hidden" name="editor_mode" value="update">
                <input type="hidden" name="post_id" value="<?php echo esc_attr((string) $selected_id); ?>">
                <?php wp_nonce_field('erm_insight_preview'); ?>
                <table class="form-table" role="presentation"><tbody>
                  <tr><th><label for="erm-edit-title"><?php echo esc_html__('Title', 'eduardo-research-manager'); ?></label></th><td><input class="large-text" id="erm-edit-title" name="title" value="<?php echo esc_attr((string) $selected['title']); ?>"></td></tr>
                  <tr><th><?php echo esc_html__('Permanent slug', 'eduardo-research-manager'); ?></th><td><code><?php echo esc_html((string) $selected['slug']); ?></code><p class="description"><?php echo esc_html__('Slug is intentionally immutable in the structured editor to protect stable research URLs.', 'eduardo-research-manager'); ?></p></td></tr>
                  <tr><th><label for="erm-edit-language"><?php echo esc_html__('Language', 'eduardo-research-manager'); ?></label></th><td><select id="erm-edit-language" name="language"><?php foreach ($languages as $candidate) : ?><option value="<?php echo esc_attr((string) $candidate); ?>" <?php selected((string) $selected['language'], (string) $candidate); ?>><?php echo esc_html(strtoupper((string) $candidate)); ?></option><?php endforeach; ?></select></td></tr>
                  <tr><th><label for="erm-edit-type"><?php echo esc_html__('Editorial type', 'eduardo-research-manager'); ?></label></th><td><select id="erm-edit-type" name="insight_type"><?php foreach ($selected_types as $value => $label) : ?><option value="<?php echo esc_attr((string) $value); ?>" <?php selected((string) $selected['insight_type'], (string) $value); ?>><?php echo esc_html((string) $label); ?></option><?php endforeach; ?></select></td></tr>
                  <tr><th><?php echo esc_html__('Status', 'eduardo-research-manager'); ?></th><td><?php echo esc_html(strtoupper((string) $selected['status'])); ?><p class="description"><?php echo esc_html__('Existing status is read-only in this microphase; creation can start as draft or published.', 'eduardo-research-manager'); ?></p></td></tr>
                  <tr><th><label for="erm-edit-excerpt"><?php echo esc_html__('Excerpt', 'eduardo-research-manager'); ?></label></th><td><textarea class="large-text" rows="3" id="erm-edit-excerpt" name="excerpt"><?php echo esc_textarea((string) $selected['excerpt']); ?></textarea></td></tr>
                  <tr><th><label for="erm-edit-content"><?php echo esc_html__('Content', 'eduardo-research-manager'); ?></label></th><td><textarea class="large-text code" rows="12" id="erm-edit-content" name="content"><?php echo esc_textarea((string) $selected['content']); ?></textarea></td></tr>
                </tbody></table>
                <?php submit_button(__('Preview Insight changes', 'eduardo-research-manager'), 'secondary', 'submit', false); ?>
              </form>
            <?php endif; ?>
          <?php endif; ?>

          <?php if ($prepared) : ?>
            <h2><?php echo esc_html__('Prepared Insight change', 'eduardo-research-manager'); ?></h2>
            <?php $actions = is_array($prepared['plan']['actions'] ?? null) ? count($prepared['plan']['actions']) : 0; ?>
            <table class="widefat striped" style="max-width:900px"><tbody>
              <tr><th><?php echo esc_html__('Mode', 'eduardo-research-manager'); ?></th><td><?php echo esc_html(strtoupper((string) ($prepared['mode'] ?? ''))); ?></td></tr>
              <tr><th><?php echo esc_html__('Status', 'eduardo-research-manager'); ?></th><td><?php echo esc_html(strtoupper((string) ($prepared['status'] ?? ''))); ?></td></tr>
              <tr><th><?php echo esc_html__('Mutation actions', 'eduardo-research-manager'); ?></th><td><?php echo esc_html((string) $actions); ?></td></tr>
              <tr><th><?php echo esc_html__('Apply gate', 'eduardo-research-manager'); ?></th><td><?php echo ! empty($prepared['apply_allowed']) ? esc_html__('Allowed', 'eduardo-research-manager') : esc_html__('Blocked', 'eduardo-research-manager'); ?></td></tr>
              <tr><th><?php echo esc_html__('Plan ID', 'eduardo-research-manager'); ?></th><td><code><?php echo esc_html((string) ($prepared['plan_id'] ?? 'already-matching')); ?></code></td></tr>
            </tbody></table>
            <?php if (in_array((string) ($prepared['status'] ?? ''), array('create','change'), true) && ! empty($prepared['apply_allowed'])) : ?>
              <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:12px"><input type="hidden" name="action" value="eduardo_research_manager_apply_insight_preview"><?php wp_nonce_field('erm_insight_apply'); ?><?php submit_button(__('Apply prepared Insight change', 'eduardo-research-manager'), 'primary', 'submit', false); ?></form>
            <?php elseif ('already-matching' === (string) ($prepared['status'] ?? '')) : ?>
              <p><strong><?php echo esc_html__('No mutation required: the Insight already matches the prepared structured state.', 'eduardo-research-manager'); ?></strong></p>
            <?php endif; ?>
          <?php endif; ?>

          <?php if ($last_edit && ! empty($last_edit['snapshot_id'])) : ?>
            <h2><?php echo esc_html__('Latest reversible Insight edit', 'eduardo-research-manager'); ?></h2>
            <p><strong><?php echo esc_html(strtoupper((string) ($last_edit['mode'] ?? ''))); ?></strong> — <?php echo esc_html((string) ($last_edit['applied_at'] ?? '')); ?></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="eduardo_research_manager_rollback_insight_edit"><?php wp_nonce_field('erm_insight_rollback'); ?><?php submit_button(__('Rollback latest Insight edit', 'eduardo-research-manager'), 'secondary', 'submit', false); ?></form>
          <?php endif; ?>
        </div>
        <?php
    }

    public function handle_preview(): void {
        $this->require_admin('erm_insight_preview');
        $mode = sanitize_key((string) wp_unslash($_POST['editor_mode'] ?? ''));
        if ('create' === $mode) {
            $data = array(
                'title'=>(string) wp_unslash($_POST['title'] ?? ''),
                'excerpt'=>(string) wp_unslash($_POST['excerpt'] ?? ''),
                'content'=>(string) wp_unslash($_POST['content'] ?? ''),
                'language'=>sanitize_key((string) wp_unslash($_POST['language'] ?? 'en')),
                'insight_type'=>sanitize_key((string) wp_unslash($_POST['insight_type'] ?? 'research_note')),
                'status'=>sanitize_key((string) wp_unslash($_POST['status'] ?? 'draft')),
            );
            $slug = sanitize_title((string) wp_unslash($_POST['slug'] ?? ''));
            if ('' !== $slug) { $data['slug'] = $slug; }
            $prepared = Eduardo_Research_Manager::insight_editor()->preview_create($data);
            $args = array('erm_insight_language'=>$data['language']);
        } elseif ('update' === $mode) {
            $post_id = absint($_POST['post_id'] ?? 0);
            $changes = array(
                'title'=>(string) wp_unslash($_POST['title'] ?? ''),
                'excerpt'=>(string) wp_unslash($_POST['excerpt'] ?? ''),
                'content'=>(string) wp_unslash($_POST['content'] ?? ''),
                'language'=>sanitize_key((string) wp_unslash($_POST['language'] ?? 'en')),
                'insight_type'=>sanitize_key((string) wp_unslash($_POST['insight_type'] ?? 'research_note')),
            );
            $prepared = Eduardo_Research_Manager::insight_editor()->preview_update($post_id, $changes);
            $args = array('erm_insight_language'=>$changes['language'],'erm_insight_id'=>$post_id);
        } else {
            $this->redirect_notice('error', 'Unknown Insight editor mode.');
        }

        if (is_wp_error($prepared)) {
            $this->redirect_notice('error', sprintf('Insight Preview failed: %s', $prepared->get_error_message()), $args);
        }
        set_transient($this->preview_key(), $prepared, 30 * MINUTE_IN_SECONDS);
        $message = 'already-matching' === (string) ($prepared['status'] ?? '')
            ? 'Insight Preview verified: no mutation is required.'
            : 'Insight Preview prepared. Review the Apply gate before committing the change.';
        $this->redirect_notice('success', $message, $args);
    }

    public function handle_apply(): void {
        $this->require_admin('erm_insight_apply');
        $prepared = get_transient($this->preview_key());
        if (! is_array($prepared)) {
            $this->redirect_notice('warning', 'The prepared Insight Preview expired or is unavailable. Prepare a new Preview before Apply.');
        }

        $result = Eduardo_Research_Manager::insight_editor()->apply_preview($prepared);
        $language = sanitize_key((string) ($prepared['expected']['language'] ?? 'en'));
        $post_id = absint($prepared['post_id'] ?? 0);
        $args = array('erm_insight_language'=>$language);
        if ($post_id > 0) { $args['erm_insight_id'] = $post_id; }
        if (is_wp_error($result)) {
            if ('research_manager_insight_editor_stale_preview' === $result->get_error_code()) { delete_transient($this->preview_key()); }
            $this->redirect_notice('error', sprintf('Insight Apply failed: %s', $result->get_error_message()), $args);
        }

        delete_transient($this->preview_key());
        $post_id = absint($result['post_id'] ?? $post_id);
        if ($post_id > 0) { $args['erm_insight_id'] = $post_id; }
        $snapshot_id = sanitize_text_field((string) ($result['snapshot_id'] ?? ''));
        if ('' !== $snapshot_id) {
            set_transient(
                $this->edit_key(),
                array(
                    'mode'=>(string) ($result['mode'] ?? $prepared['mode'] ?? ''),
                    'post_id'=>$post_id,
                    'language'=>$language,
                    'snapshot_id'=>$snapshot_id,
                    'applied_at'=>gmdate(DATE_W3C),
                    'plan_id'=>(string) ($result['plan_id'] ?? ''),
                ),
                DAY_IN_SECONDS
            );
            $this->redirect_notice('success', 'Structured Insight change applied and verified. A reversible snapshot is available.', $args);
        }
        $this->redirect_notice('success', 'Insight already matched the prepared Preview. No mutation was required.', $args);
    }

    public function handle_rollback(): void {
        $this->require_admin('erm_insight_rollback');
        $edit = get_transient($this->edit_key());
        if (! is_array($edit) || empty($edit['snapshot_id'])) {
            $this->redirect_notice('warning', 'No reversible Insight edit is available.');
        }

        $result = Eduardo_Research_Manager::insight_editor()->rollback((string) $edit['snapshot_id']);
        $mode = sanitize_key((string) ($edit['mode'] ?? ''));
        $post_id = absint($edit['post_id'] ?? 0);
        $language = sanitize_key((string) ($edit['language'] ?? 'en'));
        $args = array('erm_insight_language'=>$language);
        if ('create' !== $mode && $post_id > 0) { $args['erm_insight_id'] = $post_id; }
        if (is_wp_error($result)) {
            $this->redirect_notice('error', sprintf('Insight rollback failed: %s', $result->get_error_message()), $args);
        }
        delete_transient($this->edit_key());
        delete_transient($this->preview_key());
        $this->redirect_notice('success', 'Latest structured Insight edit rolled back successfully.', $args);
    }

    private function selected_language(array $languages): string {
        $candidate = isset($_GET['erm_insight_language']) ? sanitize_key((string) wp_unslash($_GET['erm_insight_language'])) : 'en';
        if (in_array($candidate, $languages, true)) { return $candidate; }
        return isset($languages[0]) ? sanitize_key((string) $languages[0]) : 'en';
    }

    private function require_admin(string $nonce_action): void {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to operate Research Insights.', 'eduardo-research-manager'));
        }
        check_admin_referer($nonce_action);
    }

    private function preview_key(): string { return self::PREVIEW_PREFIX . get_current_user_id(); }
    private function edit_key(): string { return self::EDIT_PREFIX . get_current_user_id(); }

    private function url(array $args = array()): string {
        return add_query_arg(array_merge(array('page'=>'eduardo-research-insights'), $args), admin_url('tools.php'));
    }

    private function redirect_notice(string $type, string $message, array $args = array()): never {
        $type = in_array($type, array('success','warning','error','info'), true) ? $type : 'info';
        set_transient(self::NOTICE_PREFIX . get_current_user_id(), array('type'=>$type,'message'=>sanitize_text_field($message)), 60);
        wp_safe_redirect($this->url($args));
        exit;
    }

    private function pull_notice(): array {
        $key = self::NOTICE_PREFIX . get_current_user_id();
        $notice = get_transient($key);
        delete_transient($key);
        return is_array($notice) ? $notice : array();
    }
}
