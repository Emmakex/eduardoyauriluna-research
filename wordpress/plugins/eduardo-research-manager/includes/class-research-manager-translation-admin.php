<?php
/** Structured EN/ES pairing UI for Research records. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Translation_Admin {
    private const PREVIEW_PREFIX = 'eduardo_research_manager_translation_preview_';
    private const EDIT_PREFIX = 'eduardo_research_manager_translation_edit_';
    private const NOTICE_PREFIX = 'eduardo_research_manager_translation_notice_';

    public function register(): void {
        add_action('admin_menu', array($this, 'menu'));
        add_action('admin_post_eduardo_research_manager_preview_translation', array($this, 'handle_preview'));
        add_action('admin_post_eduardo_research_manager_apply_translation_preview', array($this, 'handle_apply'));
        add_action('admin_post_eduardo_research_manager_rollback_translation_edit', array($this, 'handle_rollback'));
    }

    public function menu(): void {
        add_management_page(
            'Research Translations',
            'Research Translations',
            'manage_options',
            'eduardo-research-translations',
            array($this, 'render')
        );
    }

    public function render(): void {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access Research Translations.', 'eduardo-research-manager'));
        }

        $editor = Eduardo_Research_Manager::translation_editor();
        $types = $editor->supported_types();
        $post_type = isset($_GET['erm_translation_type']) ? sanitize_key((string) wp_unslash($_GET['erm_translation_type'])) : 'research_line';
        if (! isset($types[$post_type])) { $post_type = 'research_line'; }
        $en = $editor->list_candidates($post_type, 'en');
        $es = $editor->list_candidates($post_type, 'es');
        $prepared = get_transient($this->preview_key());
        $prepared = is_array($prepared) ? $prepared : array();
        $last_edit = get_transient($this->edit_key());
        $last_edit = is_array($last_edit) ? $last_edit : array();
        $notice = $this->pull_notice();
        ?>
        <div class="wrap">
          <h1><?php echo esc_html__('Research Translations', 'eduardo-research-manager'); ?></h1>
          <p><?php echo esc_html__('Pair existing English and Spanish Research records so the Theme can emit deterministic alternate-language navigation, hreflang and related academic metadata.', 'eduardo-research-manager'); ?></p>

          <?php if ($notice) : ?><div class="notice notice-<?php echo esc_attr((string) $notice['type']); ?> is-dismissible"><p><?php echo esc_html((string) $notice['message']); ?></p></div><?php endif; ?>

          <form method="get" action="<?php echo esc_url(admin_url('tools.php')); ?>" style="margin:12px 0 18px">
            <input type="hidden" name="page" value="eduardo-research-translations">
            <label for="erm-translation-type"><strong><?php echo esc_html__('Research resource type', 'eduardo-research-manager'); ?></strong></label><br>
            <select id="erm-translation-type" name="erm_translation_type">
              <?php foreach ($types as $candidate => $label) : ?><option value="<?php echo esc_attr($candidate); ?>" <?php selected($post_type, $candidate); ?>><?php echo esc_html((string) $label); ?></option><?php endforeach; ?>
            </select>
            <?php submit_button(__('Filter records', 'eduardo-research-manager'), 'secondary', 'submit', false); ?>
          </form>

          <h2><?php echo esc_html(sprintf('Pair %s', (string) $types[$post_type])); ?></h2>
          <?php if (is_wp_error($en) || is_wp_error($es)) : ?>
            <div class="notice notice-error inline"><p><?php echo esc_html(is_wp_error($en) ? $en->get_error_message() : $es->get_error_message()); ?></p></div>
          <?php elseif (! $en || ! $es) : ?>
            <p><?php echo esc_html__('A published English and Spanish record are both required before a translation pair can be prepared.', 'eduardo-research-manager'); ?></p>
          <?php else : ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="max-width:1000px">
              <input type="hidden" name="action" value="eduardo_research_manager_preview_translation">
              <input type="hidden" name="translation_operation" value="pair">
              <input type="hidden" name="post_type" value="<?php echo esc_attr($post_type); ?>">
              <?php wp_nonce_field('erm_translation_preview'); ?>
              <table class="form-table" role="presentation"><tbody>
                <tr><th scope="row"><label for="erm-translation-en">English record</label></th><td><?php $this->candidate_select('erm-translation-en', 'first_id', $en); ?></td></tr>
                <tr><th scope="row"><label for="erm-translation-es">Spanish record</label></th><td><?php $this->candidate_select('erm-translation-es', 'second_id', $es); ?></td></tr>
              </tbody></table>
              <?php $this->render_evidence_fields($post_type); ?>
              <?php submit_button(__('Preview EN/ES pairing', 'eduardo-research-manager'), 'secondary', 'submit', false); ?>
            </form>
          <?php endif; ?>

          <h2><?php echo esc_html__('Current bilateral pairs', 'eduardo-research-manager'); ?></h2>
          <?php $pairs = ! is_wp_error($en) ? array_values(array_filter($en, static fn(array $row): bool => ! empty($row['counterpart_exists']) && (int) ($row['counterpart_id'] ?? 0) > 0)) : array(); ?>
          <?php if (! $pairs) : ?>
            <p><?php echo esc_html__('No bilateral EN/ES pairs exist for this Research resource type yet.', 'eduardo-research-manager'); ?></p>
          <?php else : ?>
            <table class="widefat striped" style="max-width:1100px"><thead><tr><th>English</th><th>Spanish counterpart</th><th>Evidence</th><th>Action</th></tr></thead><tbody>
              <?php foreach ($pairs as $row) : ?>
                <?php $counterpart = $editor->inspect((int) $row['counterpart_id']); ?>
                <tr>
                  <td><strong><?php echo esc_html((string) $row['title']); ?></strong><br><code>#<?php echo esc_html((string) $row['post_id']); ?></code></td>
                  <td><?php if (! is_wp_error($counterpart)) : ?><strong><?php echo esc_html((string) $counterpart['title']); ?></strong><br><code>#<?php echo esc_html((string) $counterpart['post_id']); ?></code><?php else : ?><?php echo esc_html__('Unavailable', 'eduardo-research-manager'); ?><?php endif; ?></td>
                  <td><?php echo '' !== trim((string) ($row['evidence_reference'] ?? '')) ? esc_html((string) $row['evidence_reference']) : esc_html__('Editorial pairing / no academic evidence reference', 'eduardo-research-manager'); ?></td>
                  <td>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                      <input type="hidden" name="action" value="eduardo_research_manager_preview_translation">
                      <input type="hidden" name="translation_operation" value="unpair">
                      <input type="hidden" name="post_type" value="<?php echo esc_attr($post_type); ?>">
                      <input type="hidden" name="post_id" value="<?php echo esc_attr((string) $row['post_id']); ?>">
                      <?php wp_nonce_field('erm_translation_preview'); ?>
                      <?php if ('post' !== $post_type) : ?>
                        <label><input type="checkbox" name="evidence_confirmed" value="1"> Confirm evidence change</label><br>
                        <input type="text" name="evidence_reference" class="regular-text" placeholder="Evidence/source for unpair">
                      <?php endif; ?>
                      <?php submit_button(__('Preview unpair', 'eduardo-research-manager'), 'secondary small', 'submit', false); ?>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody></table>
          <?php endif; ?>

          <?php if ($prepared) : ?>
            <h2><?php echo esc_html__('Prepared Translation change', 'eduardo-research-manager'); ?></h2>
            <?php $actions = is_array($prepared['plan']['actions'] ?? null) ? count($prepared['plan']['actions']) : 0; ?>
            <table class="widefat striped" style="max-width:900px"><tbody>
              <tr><th>Operation</th><td><?php echo esc_html(strtoupper((string) ($prepared['operation'] ?? ''))); ?></td></tr>
              <tr><th>Status</th><td><?php echo esc_html(strtoupper((string) ($prepared['status'] ?? ''))); ?></td></tr>
              <tr><th>Risk</th><td><?php echo esc_html(strtoupper((string) ($prepared['risk'] ?? ''))); ?></td></tr>
              <tr><th>Mutation actions</th><td><?php echo esc_html((string) $actions); ?></td></tr>
              <tr><th>Apply gate</th><td><?php echo ! empty($prepared['apply_allowed']) ? esc_html__('Allowed', 'eduardo-research-manager') : esc_html__('Blocked', 'eduardo-research-manager'); ?></td></tr>
              <?php if (empty($prepared['apply_allowed']) && '' !== (string) ($prepared['apply_blocker'] ?? '')) : ?><tr><th>Blocker</th><td><?php echo esc_html((string) $prepared['apply_blocker']); ?></td></tr><?php endif; ?>
              <tr><th>Plan ID</th><td><code><?php echo esc_html((string) ($prepared['plan_id'] ?? '')); ?></code></td></tr>
            </tbody></table>
            <?php if ('already-matching' === (string) ($prepared['status'] ?? '')) : ?>
              <p><strong><?php echo esc_html__('No mutation required: these records are already paired with the prepared evidence state.', 'eduardo-research-manager'); ?></strong></p>
            <?php elseif (! empty($prepared['apply_allowed'])) : ?>
              <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:12px"><input type="hidden" name="action" value="eduardo_research_manager_apply_translation_preview"><?php wp_nonce_field('erm_translation_apply'); ?><?php submit_button(__('Apply prepared Translation change', 'eduardo-research-manager'), 'primary', 'submit', false); ?></form>
            <?php else : ?>
              <p><strong><?php echo esc_html__('Apply is blocked. Structured Research pair changes require explicit evidence confirmation and a source/reference.', 'eduardo-research-manager'); ?></strong></p>
            <?php endif; ?>
          <?php endif; ?>

          <?php if ($last_edit && ! empty($last_edit['snapshot_id'])) : ?>
            <h2><?php echo esc_html__('Latest reversible Translation edit', 'eduardo-research-manager'); ?></h2>
            <p><strong><?php echo esc_html(strtoupper((string) ($last_edit['operation'] ?? ''))); ?></strong> — <?php echo esc_html((string) ($last_edit['applied_at'] ?? '')); ?></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="eduardo_research_manager_rollback_translation_edit"><?php wp_nonce_field('erm_translation_rollback'); ?><?php submit_button(__('Rollback latest Translation edit', 'eduardo-research-manager'), 'secondary', 'submit', false); ?></form>
          <?php endif; ?>
        </div>
        <?php
    }

    public function handle_preview(): void {
        $this->require_admin('erm_translation_preview');
        $operation = sanitize_key((string) wp_unslash($_POST['translation_operation'] ?? ''));
        $post_type = sanitize_key((string) wp_unslash($_POST['post_type'] ?? 'research_line'));
        $confirmed = ! empty($_POST['evidence_confirmed']);
        $reference = sanitize_text_field((string) wp_unslash($_POST['evidence_reference'] ?? ''));
        $args = array('erm_translation_type'=>$post_type);

        if ('pair' === $operation) {
            $first_id = absint($_POST['first_id'] ?? 0);
            $second_id = absint($_POST['second_id'] ?? 0);
            $prepared = Eduardo_Research_Manager::translation_editor()->preview_pair($first_id, $second_id, $confirmed, $reference);
        } elseif ('unpair' === $operation) {
            $post_id = absint($_POST['post_id'] ?? 0);
            $prepared = Eduardo_Research_Manager::translation_editor()->preview_unpair($post_id, $confirmed, $reference);
        } else {
            $this->redirect_notice('error', 'Unknown Translation editor operation.', $args);
        }

        if (is_wp_error($prepared)) {
            $this->redirect_notice('error', sprintf('Translation Preview failed: %s', $prepared->get_error_message()), $args);
        }
        set_transient($this->preview_key(), $prepared, 30 * MINUTE_IN_SECONDS);
        if ('already-matching' === (string) ($prepared['status'] ?? '')) {
            $message = 'Translation Preview verified: these records are already paired.';
        } elseif (empty($prepared['apply_allowed'])) {
            $message = 'Translation Preview prepared, but Apply is blocked by the evidence gate.';
        } else {
            $message = 'Translation Preview prepared and allowed. Review it before Apply.';
        }
        $this->redirect_notice(empty($prepared['apply_allowed']) ? 'warning' : 'success', $message, $args);
    }

    public function handle_apply(): void {
        $this->require_admin('erm_translation_apply');
        $prepared = get_transient($this->preview_key());
        if (! is_array($prepared)) {
            $this->redirect_notice('warning', 'The prepared Translation Preview expired or is unavailable. Prepare a new Preview before Apply.');
        }
        $first_id = absint($prepared['first_id'] ?? 0);
        $state = $first_id > 0 ? Eduardo_Research_Manager::translation_editor()->inspect($first_id) : new WP_Error('missing', 'Missing record.');
        $post_type = ! is_wp_error($state) ? (string) ($state['post_type'] ?? 'research_line') : 'research_line';
        $args = array('erm_translation_type'=>$post_type);

        $result = Eduardo_Research_Manager::translation_editor()->apply_preview($prepared);
        if (is_wp_error($result)) {
            if ('research_manager_translation_editor_stale_preview' === $result->get_error_code()) { delete_transient($this->preview_key()); }
            $this->redirect_notice('error', sprintf('Translation Apply failed: %s', $result->get_error_message()), $args);
        }
        delete_transient($this->preview_key());
        $snapshot_id = sanitize_text_field((string) ($result['snapshot_id'] ?? ''));
        if ('' !== $snapshot_id) {
            set_transient($this->edit_key(), array(
                'post_type'=>$post_type,
                'operation'=>(string) ($result['operation'] ?? $prepared['operation'] ?? ''),
                'first_id'=>absint($result['first_id'] ?? $first_id),
                'second_id'=>absint($result['second_id'] ?? $prepared['second_id'] ?? 0),
                'snapshot_id'=>$snapshot_id,
                'plan_id'=>(string) ($result['plan_id'] ?? ''),
                'applied_at'=>gmdate(DATE_W3C),
            ), DAY_IN_SECONDS);
            $this->redirect_notice('success', 'Translation relationship change applied and verified. A reversible snapshot is available.', $args);
        }
        $this->redirect_notice('success', 'Translation relationship already matched the prepared Preview. No mutation was required.', $args);
    }

    public function handle_rollback(): void {
        $this->require_admin('erm_translation_rollback');
        $edit = get_transient($this->edit_key());
        if (! is_array($edit) || empty($edit['snapshot_id'])) {
            $this->redirect_notice('warning', 'No reversible Translation edit is available.');
        }
        $result = Eduardo_Research_Manager::translation_editor()->rollback((string) $edit['snapshot_id']);
        $args = array('erm_translation_type'=>sanitize_key((string) ($edit['post_type'] ?? 'research_line')));
        if (is_wp_error($result)) {
            $this->redirect_notice('error', sprintf('Translation rollback failed: %s', $result->get_error_message()), $args);
        }
        delete_transient($this->edit_key());
        $this->redirect_notice('success', 'Latest Translation edit rolled back successfully.', $args);
    }

    private function candidate_select(string $id, string $name, array $rows): void {
        echo '<select id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" required style="min-width:420px">';
        echo '<option value="">Select a published record</option>';
        foreach ($rows as $row) {
            $suffix = ! empty($row['counterpart_exists']) ? ' — paired #' . absint($row['counterpart_id']) : ' — unpaired';
            echo '<option value="' . esc_attr((string) $row['post_id']) . '">' . esc_html((string) $row['title'] . ' (#' . (int) $row['post_id'] . ')' . $suffix) . '</option>';
        }
        echo '</select>';
    }

    private function render_evidence_fields(string $post_type): void {
        echo '<fieldset style="border:1px solid #c3c4c7;padding:12px 16px;margin:16px 0;max-width:900px"><legend><strong>Translation evidence gate</strong></legend>';
        if ('post' === $post_type) {
            echo '<p>Research Insight translation pairing is an editorial relationship. It is reviewed but does not require academic evidence confirmation.</p>';
        } else {
            echo '<label style="display:block;margin-bottom:8px"><input type="checkbox" name="evidence_confirmed" value="1"> I confirm that these EN/ES records are translations of the same Research object.</label>';
            echo '<label for="erm-translation-evidence"><strong>Evidence source / reference</strong></label><br>';
            echo '<input id="erm-translation-evidence" class="regular-text" style="width:100%;max-width:760px" type="text" name="evidence_reference" placeholder="Controlled document, repository record, DOI metadata or verification note">';
            echo '<p class="description">Structured Research pairings and unpairings remain blocked until the relationship change has an explicit verification reference.</p>';
        }
        echo '</fieldset>';
    }

    private function require_admin(string $nonce_action): void {
        if (! current_user_can('manage_options')) { wp_die('Forbidden', '', array('response'=>403)); }
        check_admin_referer($nonce_action);
    }

    private function preview_key(): string { return self::PREVIEW_PREFIX . get_current_user_id(); }
    private function edit_key(): string { return self::EDIT_PREFIX . get_current_user_id(); }
    private function notice_key(): string { return self::NOTICE_PREFIX . get_current_user_id(); }

    private function pull_notice(): array {
        $notice = get_transient($this->notice_key());
        delete_transient($this->notice_key());
        return is_array($notice) ? $notice : array();
    }

    private function redirect_notice(string $type, string $message, array $args = array()): never {
        $type = in_array($type, array('success','warning','error','info'), true) ? $type : 'info';
        set_transient($this->notice_key(), array('type'=>$type,'message'=>$message), 5 * MINUTE_IN_SECONDS);
        wp_safe_redirect($this->url($args));
        exit;
    }

    private function url(array $args = array()): string {
        return add_query_arg(array_merge(array('page'=>'eduardo-research-translations'), $args), admin_url('tools.php'));
    }
}
