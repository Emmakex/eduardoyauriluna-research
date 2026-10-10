<?php
/** Dedicated structured authoring UI for first-class Research Lines. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Line_Admin {
    private const PREVIEW_PREFIX = 'eduardo_research_manager_line_preview_';
    private const EDIT_PREFIX = 'eduardo_research_manager_line_edit_';
    private const NOTICE_PREFIX = 'eduardo_research_manager_line_notice_';

    public function register(): void {
        add_action('admin_menu', array($this, 'menu'));
        add_action('admin_post_eduardo_research_manager_preview_line', array($this, 'handle_preview'));
        add_action('admin_post_eduardo_research_manager_apply_line_preview', array($this, 'handle_apply'));
        add_action('admin_post_eduardo_research_manager_rollback_line_edit', array($this, 'handle_rollback'));
    }

    public function menu(): void {
        add_management_page(
            'Research Lines',
            'Research Lines',
            'manage_options',
            'eduardo-research-lines',
            array($this, 'render')
        );
    }

    public function render(): void {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access Research Lines.', 'eduardo-research-manager'));
        }

        $languages = Eduardo_Research_Manager::contract()->languages();
        $language = $this->selected_language($languages);
        $rows = Eduardo_Research_Manager::line_editor()->list($language);
        $selected_id = isset($_GET['erm_line_id']) ? absint($_GET['erm_line_id']) : 0;
        $selected = $selected_id > 0 ? Eduardo_Research_Manager::line_editor()->inspect($selected_id) : null;
        $prepared = get_transient($this->preview_key());
        $prepared = is_array($prepared) ? $prepared : array();
        $last_edit = get_transient($this->edit_key());
        $last_edit = is_array($last_edit) ? $last_edit : array();
        $notice = $this->pull_notice();
        ?>
        <div class="wrap">
          <h1><?php echo esc_html__('Research Lines', 'eduardo-research-manager'); ?></h1>
          <p><?php echo esc_html__('Evidence-aware authoring for the research agenda. Claims that define a Research Line are gated by explicit evidence confirmation before Apply.', 'eduardo-research-manager'); ?></p>

          <?php if ($notice) : ?>
            <div class="notice notice-<?php echo esc_attr((string) $notice['type']); ?> is-dismissible"><p><?php echo esc_html((string) $notice['message']); ?></p></div>
          <?php endif; ?>

          <form method="get" action="<?php echo esc_url(admin_url('tools.php')); ?>" style="display:flex;gap:12px;align-items:end;margin:12px 0 18px">
            <input type="hidden" name="page" value="eduardo-research-lines">
            <p style="margin:0"><label for="erm-line-language"><strong><?php echo esc_html__('Language', 'eduardo-research-manager'); ?></strong></label><br>
              <select id="erm-line-language" name="erm_line_language">
                <?php foreach ($languages as $candidate) : ?><option value="<?php echo esc_attr((string) $candidate); ?>" <?php selected($language, (string) $candidate); ?>><?php echo esc_html(strtoupper((string) $candidate)); ?></option><?php endforeach; ?>
              </select>
            </p>
            <?php submit_button(__('Filter Research Lines', 'eduardo-research-manager'), 'secondary', 'submit', false); ?>
          </form>

          <h2><?php echo esc_html__('Create Research Line', 'eduardo-research-manager'); ?></h2>
          <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="max-width:1050px">
            <input type="hidden" name="action" value="eduardo_research_manager_preview_line">
            <input type="hidden" name="editor_mode" value="create">
            <?php wp_nonce_field('erm_line_preview'); ?>
            <?php $this->render_fields(array('language'=>$language,'status'=>'draft','evidence_status'=>'verified','research_status'=>'planned','order'=>'100','topics'=>array(),'methods'=>array()), $languages, 'create'); ?>
            <?php $this->render_evidence_fields('create'); ?>
            <?php submit_button(__('Preview new Research Line', 'eduardo-research-manager'), 'secondary', 'submit', false); ?>
          </form>

          <h2><?php echo esc_html(sprintf('Existing Research Lines — %s', strtoupper($language))); ?></h2>
          <?php if (is_wp_error($rows)) : ?>
            <div class="notice notice-error inline"><p><?php echo esc_html($rows->get_error_message()); ?></p></div>
          <?php elseif (! $rows) : ?>
            <p><?php echo esc_html__('No first-class Research Lines exist in this language yet.', 'eduardo-research-manager'); ?></p>
          <?php else : ?>
            <table class="widefat striped" style="max-width:1100px"><thead><tr><th><?php echo esc_html__('Research Line', 'eduardo-research-manager'); ?></th><th><?php echo esc_html__('Research status', 'eduardo-research-manager'); ?></th><th><?php echo esc_html__('Evidence', 'eduardo-research-manager'); ?></th><th><?php echo esc_html__('Order', 'eduardo-research-manager'); ?></th><th><?php echo esc_html__('Action', 'eduardo-research-manager'); ?></th></tr></thead><tbody>
              <?php foreach ($rows as $row) : ?>
                <tr>
                  <td><strong><?php echo esc_html((string) $row['title']); ?></strong><br><code><?php echo esc_html((string) $row['slug']); ?></code></td>
                  <td><?php echo esc_html(strtoupper((string) $row['research_status'])); ?></td>
                  <td><?php echo esc_html(strtoupper((string) $row['evidence_status'])); ?></td>
                  <td><?php echo esc_html((string) $row['order']); ?></td>
                  <td><a href="<?php echo esc_url($this->url(array('erm_line_language'=>$language,'erm_line_id'=>(int) $row['post_id']))); ?>"><?php echo esc_html__('Edit structured Line', 'eduardo-research-manager'); ?></a></td>
                </tr>
              <?php endforeach; ?>
            </tbody></table>
          <?php endif; ?>

          <?php if ($selected_id > 0) : ?>
            <h2><?php echo esc_html__('Edit selected Research Line', 'eduardo-research-manager'); ?></h2>
            <?php if (is_wp_error($selected)) : ?>
              <div class="notice notice-error inline"><p><?php echo esc_html($selected->get_error_message()); ?></p></div>
            <?php else : ?>
              <p><strong><?php echo esc_html((string) $selected['title']); ?></strong> — <a href="<?php echo esc_url((string) $selected['url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('View rendered Research Line', 'eduardo-research-manager'); ?></a></p>
              <p class="description"><?php echo esc_html__('The permanent slug and WordPress publication status remain immutable in this structured update flow to protect stable research URLs and publication state.', 'eduardo-research-manager'); ?></p>
              <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="max-width:1050px">
                <input type="hidden" name="action" value="eduardo_research_manager_preview_line">
                <input type="hidden" name="editor_mode" value="update">
                <input type="hidden" name="post_id" value="<?php echo esc_attr((string) $selected_id); ?>">
                <?php wp_nonce_field('erm_line_preview'); ?>
                <?php $this->render_fields($selected, $languages, 'update'); ?>
                <?php $this->render_evidence_fields('update'); ?>
                <?php submit_button(__('Preview Research Line changes', 'eduardo-research-manager'), 'secondary', 'submit', false); ?>
              </form>
            <?php endif; ?>
          <?php endif; ?>

          <?php if ($prepared) : ?>
            <h2><?php echo esc_html__('Prepared Research Line change', 'eduardo-research-manager'); ?></h2>
            <?php $actions = is_array($prepared['plan']['actions'] ?? null) ? count($prepared['plan']['actions']) : 0; ?>
            <table class="widefat striped" style="max-width:900px"><tbody>
              <tr><th><?php echo esc_html__('Mode', 'eduardo-research-manager'); ?></th><td><?php echo esc_html(strtoupper((string) ($prepared['mode'] ?? ''))); ?></td></tr>
              <tr><th><?php echo esc_html__('Status', 'eduardo-research-manager'); ?></th><td><?php echo esc_html(strtoupper((string) ($prepared['status'] ?? ''))); ?></td></tr>
              <tr><th><?php echo esc_html__('Risk', 'eduardo-research-manager'); ?></th><td><?php echo esc_html(strtoupper((string) ($prepared['risk'] ?? ''))); ?></td></tr>
              <tr><th><?php echo esc_html__('Mutation actions', 'eduardo-research-manager'); ?></th><td><?php echo esc_html((string) $actions); ?></td></tr>
              <tr><th><?php echo esc_html__('Apply gate', 'eduardo-research-manager'); ?></th><td><?php echo ! empty($prepared['apply_allowed']) ? esc_html__('Allowed', 'eduardo-research-manager') : esc_html__('Blocked', 'eduardo-research-manager'); ?></td></tr>
              <?php if (empty($prepared['apply_allowed']) && '' !== (string) ($prepared['apply_blocker'] ?? '')) : ?><tr><th><?php echo esc_html__('Blocker', 'eduardo-research-manager'); ?></th><td><?php echo esc_html((string) $prepared['apply_blocker']); ?></td></tr><?php endif; ?>
              <tr><th><?php echo esc_html__('Plan ID', 'eduardo-research-manager'); ?></th><td><code><?php echo esc_html((string) ($prepared['plan_id'] ?? 'already-matching')); ?></code></td></tr>
            </tbody></table>
            <?php if (in_array((string) ($prepared['status'] ?? ''), array('create','change'), true) && ! empty($prepared['apply_allowed'])) : ?>
              <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:12px"><input type="hidden" name="action" value="eduardo_research_manager_apply_line_preview"><?php wp_nonce_field('erm_line_apply'); ?><?php submit_button(__('Apply prepared Research Line change', 'eduardo-research-manager'), 'primary', 'submit', false); ?></form>
            <?php elseif ('already-matching' === (string) ($prepared['status'] ?? '')) : ?>
              <p><strong><?php echo esc_html__('No mutation required: the Research Line already matches the prepared structured state.', 'eduardo-research-manager'); ?></strong></p>
            <?php else : ?>
              <p><strong><?php echo esc_html__('Apply is blocked. Confirm the academic evidence and provide its source/reference, then prepare a new Preview.', 'eduardo-research-manager'); ?></strong></p>
            <?php endif; ?>
          <?php endif; ?>

          <?php if ($last_edit && ! empty($last_edit['snapshot_id'])) : ?>
            <h2><?php echo esc_html__('Latest reversible Research Line edit', 'eduardo-research-manager'); ?></h2>
            <p><strong><?php echo esc_html(strtoupper((string) ($last_edit['mode'] ?? ''))); ?></strong> — <?php echo esc_html((string) ($last_edit['applied_at'] ?? '')); ?></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="eduardo_research_manager_rollback_line_edit"><?php wp_nonce_field('erm_line_rollback'); ?><?php submit_button(__('Rollback latest Research Line edit', 'eduardo-research-manager'), 'secondary', 'submit', false); ?></form>
          <?php endif; ?>
        </div>
        <?php
    }

    public function handle_preview(): void {
        $this->require_admin('erm_line_preview');
        $mode = sanitize_key((string) wp_unslash($_POST['editor_mode'] ?? ''));
        $evidence_confirmed = ! empty($_POST['evidence_confirmed']);
        $evidence_reference = sanitize_text_field((string) wp_unslash($_POST['evidence_reference'] ?? ''));
        $data = $this->read_fields($_POST, $mode);
        if (is_wp_error($data)) { $this->redirect_notice('error', $data->get_error_message()); }

        if ('create' === $mode) {
            $prepared = Eduardo_Research_Manager::line_editor()->preview_create($data, $evidence_confirmed, $evidence_reference);
            $args = array('erm_line_language'=>(string) $data['language']);
        } elseif ('update' === $mode) {
            $post_id = absint($_POST['post_id'] ?? 0);
            $prepared = Eduardo_Research_Manager::line_editor()->preview_update($post_id, $data, $evidence_confirmed, $evidence_reference);
            $args = array('erm_line_language'=>(string) $data['language'],'erm_line_id'=>$post_id);
        } else {
            $this->redirect_notice('error', 'Unknown Research Line editor mode.');
        }

        if (is_wp_error($prepared)) {
            $this->redirect_notice('error', sprintf('Research Line Preview failed: %s', $prepared->get_error_message()), $args);
        }
        set_transient($this->preview_key(), $prepared, 30 * MINUTE_IN_SECONDS);
        if ('already-matching' === (string) ($prepared['status'] ?? '')) {
            $message = 'Research Line Preview verified: no mutation is required.';
        } elseif (empty($prepared['apply_allowed'])) {
            $message = 'Research Line Preview prepared, but Apply is blocked by the evidence gate.';
        } else {
            $message = 'Research Line Preview prepared and allowed. Review it before Apply.';
        }
        $this->redirect_notice(empty($prepared['apply_allowed']) ? 'warning' : 'success', $message, $args);
    }

    public function handle_apply(): void {
        $this->require_admin('erm_line_apply');
        $prepared = get_transient($this->preview_key());
        if (! is_array($prepared)) {
            $this->redirect_notice('warning', 'The prepared Research Line Preview expired or is unavailable. Prepare a new Preview before Apply.');
        }

        $result = Eduardo_Research_Manager::line_editor()->apply_preview($prepared);
        $language = sanitize_key((string) ($prepared['expected']['language'] ?? 'en'));
        $post_id = absint($prepared['post_id'] ?? 0);
        $args = array('erm_line_language'=>$language);
        if ($post_id > 0) { $args['erm_line_id'] = $post_id; }
        if (is_wp_error($result)) {
            if ('research_manager_line_editor_stale_preview' === $result->get_error_code()) { delete_transient($this->preview_key()); }
            $this->redirect_notice('error', sprintf('Research Line Apply failed: %s', $result->get_error_message()), $args);
        }

        delete_transient($this->preview_key());
        $post_id = absint($result['post_id'] ?? $post_id);
        if ($post_id > 0) { $args['erm_line_id'] = $post_id; }
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
            $this->redirect_notice('success', 'Structured Research Line change applied and verified. A reversible snapshot is available.', $args);
        }
        $this->redirect_notice('success', 'Research Line already matched the prepared Preview. No mutation was required.', $args);
    }

    public function handle_rollback(): void {
        $this->require_admin('erm_line_rollback');
        $edit = get_transient($this->edit_key());
        if (! is_array($edit) || empty($edit['snapshot_id'])) {
            $this->redirect_notice('warning', 'No reversible Research Line edit is available.');
        }

        $result = Eduardo_Research_Manager::line_editor()->rollback((string) $edit['snapshot_id']);
        $mode = sanitize_key((string) ($edit['mode'] ?? ''));
        $post_id = absint($edit['post_id'] ?? 0);
        $language = sanitize_key((string) ($edit['language'] ?? 'en'));
        $args = array('erm_line_language'=>$language);
        if ('create' !== $mode && $post_id > 0) { $args['erm_line_id'] = $post_id; }
        if (is_wp_error($result)) {
            $this->redirect_notice('error', sprintf('Research Line rollback failed: %s', $result->get_error_message()), $args);
        }
        delete_transient($this->edit_key());
        delete_transient($this->preview_key());
        $this->redirect_notice('success', 'Latest structured Research Line edit rolled back successfully.', $args);
    }

    private function render_fields(array $record, array $languages, string $mode): void {
        $prefix = 'create' === $mode ? 'erm-line-create-' : 'erm-line-edit-';
        $topics = is_array($record['topics'] ?? null) ? implode("\n", $record['topics']) : '';
        $methods = is_array($record['methods'] ?? null) ? implode("\n", $record['methods']) : '';
        ?>
        <table class="form-table" role="presentation"><tbody>
          <tr><th><label for="<?php echo esc_attr($prefix . 'title'); ?>"><?php echo esc_html__('Title', 'eduardo-research-manager'); ?></label></th><td><input class="large-text" id="<?php echo esc_attr($prefix . 'title'); ?>" name="title" value="<?php echo esc_attr((string) ($record['title'] ?? '')); ?>" required></td></tr>
          <?php if ('create' === $mode) : ?><tr><th><label for="<?php echo esc_attr($prefix . 'slug'); ?>"><?php echo esc_html__('Slug', 'eduardo-research-manager'); ?></label></th><td><input class="regular-text" id="<?php echo esc_attr($prefix . 'slug'); ?>" name="slug"><p class="description"><?php echo esc_html__('Optional; generated from the title when empty.', 'eduardo-research-manager'); ?></p></td></tr><?php else : ?><tr><th><?php echo esc_html__('Permanent slug', 'eduardo-research-manager'); ?></th><td><code><?php echo esc_html((string) ($record['slug'] ?? '')); ?></code></td></tr><?php endif; ?>
          <tr><th><label for="<?php echo esc_attr($prefix . 'language'); ?>"><?php echo esc_html__('Language', 'eduardo-research-manager'); ?></label></th><td><select id="<?php echo esc_attr($prefix . 'language'); ?>" name="language"><?php foreach ($languages as $candidate) : ?><option value="<?php echo esc_attr((string) $candidate); ?>" <?php selected((string) ($record['language'] ?? ''), (string) $candidate); ?>><?php echo esc_html(strtoupper((string) $candidate)); ?></option><?php endforeach; ?></select></td></tr>
          <?php if ('create' === $mode) : ?><tr><th><label for="<?php echo esc_attr($prefix . 'status'); ?>"><?php echo esc_html__('Initial WordPress status', 'eduardo-research-manager'); ?></label></th><td><select id="<?php echo esc_attr($prefix . 'status'); ?>" name="status"><option value="draft" <?php selected((string) ($record['status'] ?? ''), 'draft'); ?>><?php echo esc_html__('Draft', 'eduardo-research-manager'); ?></option><option value="publish" <?php selected((string) ($record['status'] ?? ''), 'publish'); ?>><?php echo esc_html__('Publish', 'eduardo-research-manager'); ?></option></select></td></tr><?php else : ?><tr><th><?php echo esc_html__('WordPress status', 'eduardo-research-manager'); ?></th><td><?php echo esc_html(strtoupper((string) ($record['status'] ?? ''))); ?></td></tr><?php endif; ?>
          <tr><th><label for="<?php echo esc_attr($prefix . 'evidence-status'); ?>"><?php echo esc_html__('Evidence status', 'eduardo-research-manager'); ?></label></th><td><select id="<?php echo esc_attr($prefix . 'evidence-status'); ?>" name="evidence_status"><option value="verified" <?php selected((string) ($record['evidence_status'] ?? ''), 'verified'); ?>><?php echo esc_html__('Verified', 'eduardo-research-manager'); ?></option><option value="unverified" <?php selected((string) ($record['evidence_status'] ?? ''), 'unverified'); ?>><?php echo esc_html__('Unverified', 'eduardo-research-manager'); ?></option></select></td></tr>
          <tr><th><label for="<?php echo esc_attr($prefix . 'research-status'); ?>"><?php echo esc_html__('Research status', 'eduardo-research-manager'); ?></label></th><td><input class="regular-text" id="<?php echo esc_attr($prefix . 'research-status'); ?>" name="research_status" value="<?php echo esc_attr((string) ($record['research_status'] ?? 'planned')); ?>"><p class="description"><?php echo esc_html__('Examples: planned, active, completed. The Manager stores only the bounded Theme metadata value.', 'eduardo-research-manager'); ?></p></td></tr>
          <tr><th><label for="<?php echo esc_attr($prefix . 'order'); ?>"><?php echo esc_html__('Display order', 'eduardo-research-manager'); ?></label></th><td><input type="number" class="small-text" id="<?php echo esc_attr($prefix . 'order'); ?>" name="order" min="0" max="9999" value="<?php echo esc_attr((string) ($record['order'] ?? '100')); ?>"></td></tr>
          <tr><th><label for="<?php echo esc_attr($prefix . 'excerpt'); ?>"><?php echo esc_html__('Summary', 'eduardo-research-manager'); ?></label></th><td><textarea class="large-text" rows="3" id="<?php echo esc_attr($prefix . 'excerpt'); ?>" name="excerpt"><?php echo esc_textarea((string) ($record['excerpt'] ?? '')); ?></textarea></td></tr>
          <tr><th><label for="<?php echo esc_attr($prefix . 'question'); ?>"><?php echo esc_html__('Central research question', 'eduardo-research-manager'); ?></label></th><td><textarea class="large-text" rows="4" id="<?php echo esc_attr($prefix . 'question'); ?>" name="central_question"><?php echo esc_textarea((string) ($record['central_question'] ?? '')); ?></textarea></td></tr>
          <tr><th><label for="<?php echo esc_attr($prefix . 'topics'); ?>"><?php echo esc_html__('Topics', 'eduardo-research-manager'); ?></label></th><td><textarea class="large-text" rows="5" id="<?php echo esc_attr($prefix . 'topics'); ?>" name="topics"><?php echo esc_textarea($topics); ?></textarea><p class="description"><?php echo esc_html__('One topic per line.', 'eduardo-research-manager'); ?></p></td></tr>
          <tr><th><label for="<?php echo esc_attr($prefix . 'methods'); ?>"><?php echo esc_html__('Methods', 'eduardo-research-manager'); ?></label></th><td><textarea class="large-text" rows="5" id="<?php echo esc_attr($prefix . 'methods'); ?>" name="methods"><?php echo esc_textarea($methods); ?></textarea><p class="description"><?php echo esc_html__('One method per line.', 'eduardo-research-manager'); ?></p></td></tr>
          <tr><th><label for="<?php echo esc_attr($prefix . 'content'); ?>"><?php echo esc_html__('Extended content', 'eduardo-research-manager'); ?></label></th><td><textarea class="large-text code" rows="8" id="<?php echo esc_attr($prefix . 'content'); ?>" name="content"><?php echo esc_textarea((string) ($record['content'] ?? '')); ?></textarea></td></tr>
        </tbody></table>
        <?php
    }

    private function render_evidence_fields(string $mode): void {
        $prefix = 'create' === $mode ? 'erm-line-create-' : 'erm-line-edit-';
        ?>
        <fieldset style="border:1px solid #c3c4c7;padding:14px 16px;margin:12px 0 16px;max-width:1000px">
          <legend><strong><?php echo esc_html__('Academic evidence gate', 'eduardo-research-manager'); ?></strong></legend>
          <label><input type="checkbox" name="evidence_confirmed" value="1"> <?php echo esc_html__('I have verified that the evidence/source supports this Research Line mutation.', 'eduardo-research-manager'); ?></label>
          <p><label for="<?php echo esc_attr($prefix . 'evidence-reference'); ?>"><strong><?php echo esc_html__('Evidence source/reference', 'eduardo-research-manager'); ?></strong></label><br><input class="large-text" id="<?php echo esc_attr($prefix . 'evidence-reference'); ?>" name="evidence_reference" placeholder="content/research-statement/... or another controlled source"></p>
          <p class="description"><?php echo esc_html__('Evidence-sensitive changes cannot be applied without confirmation and a non-empty reference. Editorial-only changes may remain applyable without it.', 'eduardo-research-manager'); ?></p>
        </fieldset>
        <?php
    }

    private function read_fields(array $input, string $mode): array|WP_Error {
        if (! in_array($mode, array('create','update'), true)) {
            return new WP_Error('research_manager_line_admin_mode_invalid', 'Unknown Research Line editor mode.');
        }
        $title = sanitize_text_field((string) wp_unslash($input['title'] ?? ''));
        if ('' === $title) { return new WP_Error('research_manager_line_admin_title_required', 'Research Line title is required.'); }
        $topics = $this->lines_from_text((string) wp_unslash($input['topics'] ?? ''));
        $methods = $this->lines_from_text((string) wp_unslash($input['methods'] ?? ''));
        $data = array(
            'title'=>$title,
            'excerpt'=>(string) wp_unslash($input['excerpt'] ?? ''),
            'content'=>(string) wp_unslash($input['content'] ?? ''),
            'language'=>sanitize_key((string) wp_unslash($input['language'] ?? 'en')),
            'evidence_status'=>sanitize_key((string) wp_unslash($input['evidence_status'] ?? 'unverified')),
            'research_status'=>sanitize_key((string) wp_unslash($input['research_status'] ?? 'planned')),
            'central_question'=>sanitize_textarea_field((string) wp_unslash($input['central_question'] ?? '')),
            'order'=>(string) max(0, min(9999, absint($input['order'] ?? 0))),
            'topics'=>$topics,
            'methods'=>$methods,
        );
        if ('create' === $mode) {
            $data['status'] = sanitize_key((string) wp_unslash($input['status'] ?? 'draft'));
            $slug = sanitize_title((string) wp_unslash($input['slug'] ?? ''));
            if ('' !== $slug) { $data['slug'] = $slug; }
        }
        return $data;
    }

    private function lines_from_text(string $raw): array {
        $items = preg_split('/\R/u', $raw) ?: array();
        return array_values(array_unique(array_filter(array_map('sanitize_text_field', array_map('trim', $items)))));
    }

    private function selected_language(array $languages): string {
        $candidate = isset($_GET['erm_line_language']) ? sanitize_key((string) wp_unslash($_GET['erm_line_language'])) : 'en';
        if (in_array($candidate, $languages, true)) { return $candidate; }
        return isset($languages[0]) ? sanitize_key((string) $languages[0]) : 'en';
    }

    private function require_admin(string $nonce_action): void {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to operate Research Lines.', 'eduardo-research-manager'));
        }
        check_admin_referer($nonce_action);
    }

    private function preview_key(): string { return self::PREVIEW_PREFIX . get_current_user_id(); }
    private function edit_key(): string { return self::EDIT_PREFIX . get_current_user_id(); }

    private function url(array $args = array()): string {
        return add_query_arg(array_merge(array('page'=>'eduardo-research-lines'), $args), admin_url('tools.php'));
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
