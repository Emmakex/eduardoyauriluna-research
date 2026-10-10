<?php
/** Structured authoring UI for verified academic identity and Theme evidence records. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Evidence_Admin {
    private const PREVIEW_PREFIX = 'eduardo_research_manager_evidence_preview_';
    private const EDIT_PREFIX = 'eduardo_research_manager_evidence_edit_';
    private const NOTICE_PREFIX = 'eduardo_research_manager_evidence_notice_';

    public function register(): void {
        add_action('admin_menu', array($this, 'menu'));
        add_action('admin_post_eduardo_research_manager_preview_evidence', array($this, 'handle_preview'));
        add_action('admin_post_eduardo_research_manager_apply_evidence_preview', array($this, 'handle_apply'));
        add_action('admin_post_eduardo_research_manager_rollback_evidence_edit', array($this, 'handle_rollback'));
    }

    public function menu(): void {
        add_management_page(
            'Research Evidence',
            'Research Evidence',
            'manage_options',
            'eduardo-research-evidence',
            array($this, 'render')
        );
    }

    public function render(): void {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access Research Evidence.', 'eduardo-research-manager'));
        }

        $editor = Eduardo_Research_Manager::evidence_editor();
        $groups = $editor->groups();
        $group = isset($_GET['erm_evidence_group']) ? sanitize_key((string) wp_unslash($_GET['erm_evidence_group'])) : 'profile';
        if (! isset($groups[$group])) { $group = 'profile'; }
        $records = $editor->records($group);
        $identity = $editor->identity();
        $selected_id = isset($_GET['erm_evidence_id']) ? sanitize_key((string) wp_unslash($_GET['erm_evidence_id'])) : '';
        $selected = '' !== $selected_id ? $editor->inspect_record($group, $selected_id) : null;
        $prepared = get_transient($this->preview_key());
        $prepared = is_array($prepared) ? $prepared : array();
        $last_edit = get_transient($this->edit_key());
        $last_edit = is_array($last_edit) ? $last_edit : array();
        $notice = $this->pull_notice();
        ?>
        <div class="wrap">
          <h1><?php echo esc_html__('Research Evidence', 'eduardo-research-manager'); ?></h1>
          <p><?php echo esc_html__('Control the academic identity and evidence records that feed the Theme-owned About, Research, CV, Contact and scholarly SEO surfaces. Verified claims require an explicit source before Apply.', 'eduardo-research-manager'); ?></p>

          <?php if ($notice) : ?><div class="notice notice-<?php echo esc_attr((string) $notice['type']); ?> is-dismissible"><p><?php echo esc_html((string) $notice['message']); ?></p></div><?php endif; ?>

          <h2><?php echo esc_html__('Verified academic identity', 'eduardo-research-manager'); ?></h2>
          <?php if (is_wp_error($identity)) : ?>
            <div class="notice notice-error inline"><p><?php echo esc_html($identity->get_error_message()); ?></p></div>
          <?php else : ?>
            <p><?php echo esc_html__('This name is the canonical Person identity used by the Theme for scholarly structured data. The canonical URL remains the site home URL.', 'eduardo-research-manager'); ?></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="max-width:900px">
              <input type="hidden" name="action" value="eduardo_research_manager_preview_evidence">
              <input type="hidden" name="evidence_operation" value="identity">
              <?php wp_nonce_field('erm_evidence_preview'); ?>
              <table class="form-table" role="presentation"><tbody>
                <tr><th scope="row"><label for="erm-evidence-identity-name">Researcher name</label></th><td><input id="erm-evidence-identity-name" class="regular-text" style="width:100%;max-width:680px" type="text" name="identity_name" required value="<?php echo esc_attr((string) $identity['name']); ?>"></td></tr>
                <tr><th scope="row">Canonical URL</th><td><code><?php echo esc_html((string) $identity['url']); ?></code></td></tr>
                <tr><th scope="row">Last verification</th><td><?php echo '' !== (string) $identity['verified_at'] ? esc_html((string) $identity['verified_at']) : esc_html__('Not yet persisted as verified identity', 'eduardo-research-manager'); ?></td></tr>
                <tr><th scope="row">Stored evidence reference</th><td><?php echo '' !== (string) $identity['evidence_reference'] ? esc_html((string) $identity['evidence_reference']) : '—'; ?></td></tr>
              </tbody></table>
              <?php $this->render_gate('identity'); ?>
              <?php submit_button(__('Preview academic identity', 'eduardo-research-manager'), 'secondary', 'submit', false); ?>
            </form>
          <?php endif; ?>

          <hr style="margin:28px 0">
          <h2><?php echo esc_html__('Evidence groups', 'eduardo-research-manager'); ?></h2>
          <form method="get" action="<?php echo esc_url(admin_url('tools.php')); ?>" style="margin:12px 0 20px">
            <input type="hidden" name="page" value="eduardo-research-evidence">
            <label for="erm-evidence-group"><strong><?php echo esc_html__('Evidence group', 'eduardo-research-manager'); ?></strong></label><br>
            <select id="erm-evidence-group" name="erm_evidence_group">
              <?php foreach ($groups as $key => $spec) : ?><option value="<?php echo esc_attr($key); ?>" <?php selected($group, $key); ?>><?php echo esc_html((string) $spec['label']); ?></option><?php endforeach; ?>
            </select>
            <?php submit_button(__('Filter evidence', 'eduardo-research-manager'), 'secondary', 'submit', false); ?>
          </form>
          <p><strong><?php echo esc_html((string) $groups[$group]['label']); ?></strong> — <?php echo esc_html(sprintf('Theme surfaces: %s', implode(', ', (array) $groups[$group]['surfaces']))); ?></p>

          <h3><?php echo esc_html(sprintf('Create %s evidence', (string) $groups[$group]['label'])); ?></h3>
          <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="max-width:1100px">
            <input type="hidden" name="action" value="eduardo_research_manager_preview_evidence">
            <input type="hidden" name="evidence_operation" value="record">
            <input type="hidden" name="evidence_group" value="<?php echo esc_attr($group); ?>">
            <input type="hidden" name="record_id" value="">
            <?php wp_nonce_field('erm_evidence_preview'); ?>
            <?php $this->render_record_fields(array('status'=>'unverified')); ?>
            <?php $this->render_gate('record'); ?>
            <?php submit_button(__('Preview new evidence record', 'eduardo-research-manager'), 'secondary', 'submit', false); ?>
          </form>

          <h3><?php echo esc_html__('Existing evidence records', 'eduardo-research-manager'); ?></h3>
          <?php if (is_wp_error($records)) : ?>
            <div class="notice notice-error inline"><p><?php echo esc_html($records->get_error_message()); ?></p></div>
          <?php elseif (! $records) : ?>
            <p><?php echo esc_html__('No evidence records exist in this group yet.', 'eduardo-research-manager'); ?></p>
          <?php else : ?>
            <table class="widefat striped" style="max-width:1100px"><thead><tr><th>Record</th><th>Status</th><th>Evidence source</th><th>Action</th></tr></thead><tbody>
              <?php foreach ($records as $record) : ?>
                <tr>
                  <td><strong><?php echo esc_html($this->record_title($record)); ?></strong><br><code><?php echo esc_html((string) $record['record_id']); ?></code></td>
                  <td><?php echo esc_html(strtoupper((string) ($record['status'] ?? 'unverified'))); ?></td>
                  <td><?php echo '' !== trim((string) ($record['evidence_reference'] ?? '')) ? esc_html((string) $record['evidence_reference']) : '—'; ?></td>
                  <td><a href="<?php echo esc_url($this->url(array('erm_evidence_group'=>$group,'erm_evidence_id'=>(string) $record['record_id']))); ?>"><?php echo esc_html__('Edit evidence', 'eduardo-research-manager'); ?></a></td>
                </tr>
              <?php endforeach; ?>
            </tbody></table>
          <?php endif; ?>

          <?php if ('' !== $selected_id) : ?>
            <h3><?php echo esc_html__('Edit selected evidence record', 'eduardo-research-manager'); ?></h3>
            <?php if (is_wp_error($selected)) : ?>
              <div class="notice notice-error inline"><p><?php echo esc_html($selected->get_error_message()); ?></p></div>
            <?php else : ?>
              <p><strong><?php echo esc_html($this->record_title($selected)); ?></strong> — <code><?php echo esc_html($selected_id); ?></code></p>
              <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="max-width:1100px">
                <input type="hidden" name="action" value="eduardo_research_manager_preview_evidence">
                <input type="hidden" name="evidence_operation" value="record">
                <input type="hidden" name="evidence_group" value="<?php echo esc_attr($group); ?>">
                <input type="hidden" name="record_id" value="<?php echo esc_attr($selected_id); ?>">
                <?php wp_nonce_field('erm_evidence_preview'); ?>
                <?php $this->render_record_fields($selected); ?>
                <?php $this->render_gate('record'); ?>
                <?php submit_button(__('Preview evidence changes', 'eduardo-research-manager'), 'secondary', 'submit', false); ?>
              </form>

              <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="max-width:900px;border:1px solid #d63638;padding:12px 16px;margin-top:18px">
                <input type="hidden" name="action" value="eduardo_research_manager_preview_evidence">
                <input type="hidden" name="evidence_operation" value="delete">
                <input type="hidden" name="evidence_group" value="<?php echo esc_attr($group); ?>">
                <input type="hidden" name="record_id" value="<?php echo esc_attr($selected_id); ?>">
                <?php wp_nonce_field('erm_evidence_preview'); ?>
                <p><strong><?php echo esc_html__('Delete evidence record', 'eduardo-research-manager'); ?></strong></p>
                <?php $this->render_gate('delete'); ?>
                <?php submit_button(__('Preview evidence deletion', 'eduardo-research-manager'), 'delete', 'submit', false); ?>
              </form>
            <?php endif; ?>
          <?php endif; ?>

          <?php if ($prepared) : ?>
            <h2><?php echo esc_html__('Prepared Academic Evidence change', 'eduardo-research-manager'); ?></h2>
            <?php $actions = is_array($prepared['plan']['actions'] ?? null) ? count($prepared['plan']['actions']) : 0; ?>
            <table class="widefat striped" style="max-width:900px"><tbody>
              <tr><th>Resource</th><td><?php echo esc_html(strtoupper((string) ($prepared['resource'] ?? ''))); ?></td></tr>
              <tr><th>Operation</th><td><?php echo esc_html(strtoupper((string) ($prepared['operation'] ?? ''))); ?></td></tr>
              <?php if (! empty($prepared['group'])) : ?><tr><th>Group</th><td><?php echo esc_html(strtoupper((string) $prepared['group'])); ?></td></tr><?php endif; ?>
              <tr><th>Status</th><td><?php echo esc_html(strtoupper((string) ($prepared['status'] ?? ''))); ?></td></tr>
              <tr><th>Risk</th><td><?php echo esc_html(strtoupper((string) ($prepared['risk'] ?? ''))); ?></td></tr>
              <tr><th>Mutation actions</th><td><?php echo esc_html((string) $actions); ?></td></tr>
              <tr><th>Apply gate</th><td><?php echo ! empty($prepared['apply_allowed']) ? esc_html__('Allowed', 'eduardo-research-manager') : esc_html__('Blocked', 'eduardo-research-manager'); ?></td></tr>
              <?php if (empty($prepared['apply_allowed']) && '' !== (string) ($prepared['apply_blocker'] ?? '')) : ?><tr><th>Blocker</th><td><?php echo esc_html((string) $prepared['apply_blocker']); ?></td></tr><?php endif; ?>
              <tr><th>Plan ID</th><td><code><?php echo esc_html((string) ($prepared['plan_id'] ?? '')); ?></code></td></tr>
            </tbody></table>
            <?php if ('already-matching' === (string) ($prepared['status'] ?? '')) : ?>
              <p><strong><?php echo esc_html__('No mutation required: the stored academic evidence already matches this Preview.', 'eduardo-research-manager'); ?></strong></p>
            <?php elseif (! empty($prepared['apply_allowed'])) : ?>
              <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:12px"><input type="hidden" name="action" value="eduardo_research_manager_apply_evidence_preview"><?php wp_nonce_field('erm_evidence_apply'); ?><?php submit_button(__('Apply prepared Academic Evidence change', 'eduardo-research-manager'), 'primary', 'submit', false); ?></form>
            <?php else : ?>
              <p><strong><?php echo esc_html__('Apply is blocked. Confirm the academic evidence and provide its source/reference, then prepare a new Preview.', 'eduardo-research-manager'); ?></strong></p>
            <?php endif; ?>
          <?php endif; ?>

          <?php if ($last_edit && ! empty($last_edit['snapshot_id'])) : ?>
            <h2><?php echo esc_html__('Latest reversible Academic Evidence edit', 'eduardo-research-manager'); ?></h2>
            <p><strong><?php echo esc_html(strtoupper((string) ($last_edit['resource'] ?? ''))); ?> / <?php echo esc_html(strtoupper((string) ($last_edit['operation'] ?? ''))); ?></strong> — <?php echo esc_html((string) ($last_edit['applied_at'] ?? '')); ?></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="eduardo_research_manager_rollback_evidence_edit"><?php wp_nonce_field('erm_evidence_rollback'); ?><?php submit_button(__('Rollback latest Academic Evidence edit', 'eduardo-research-manager'), 'secondary', 'submit', false); ?></form>
          <?php endif; ?>
        </div>
        <?php
    }

    public function handle_preview(): void {
        $this->require_admin('erm_evidence_preview');
        $operation = sanitize_key((string) wp_unslash($_POST['evidence_operation'] ?? ''));
        $confirmed = ! empty($_POST['evidence_confirmed']);
        $reference = sanitize_text_field((string) wp_unslash($_POST['evidence_reference'] ?? ''));
        $args = array();

        if ('identity' === $operation) {
            $prepared = Eduardo_Research_Manager::evidence_editor()->preview_identity(
                sanitize_text_field((string) wp_unslash($_POST['identity_name'] ?? '')),
                $confirmed,
                $reference
            );
        } elseif ('record' === $operation) {
            $group = sanitize_key((string) wp_unslash($_POST['evidence_group'] ?? ''));
            $record_id = sanitize_key((string) wp_unslash($_POST['record_id'] ?? ''));
            $args['erm_evidence_group'] = $group;
            if ('' !== $record_id) { $args['erm_evidence_id'] = $record_id; }
            $prepared = Eduardo_Research_Manager::evidence_editor()->preview_record($group, $record_id, $this->record_data($_POST), $confirmed, $reference);
        } elseif ('delete' === $operation) {
            $group = sanitize_key((string) wp_unslash($_POST['evidence_group'] ?? ''));
            $record_id = sanitize_key((string) wp_unslash($_POST['record_id'] ?? ''));
            $args = array('erm_evidence_group'=>$group,'erm_evidence_id'=>$record_id);
            $prepared = Eduardo_Research_Manager::evidence_editor()->preview_delete_record($group, $record_id, $confirmed, $reference);
        } else {
            $this->redirect_notice('error', 'Unknown Academic Evidence editor operation.');
        }

        if (is_wp_error($prepared)) {
            $this->redirect_notice('error', sprintf('Academic Evidence Preview failed: %s', $prepared->get_error_message()), $args);
        }
        set_transient($this->preview_key(), $prepared, 30 * MINUTE_IN_SECONDS);
        if ('already-matching' === (string) ($prepared['status'] ?? '')) {
            $message = 'Academic Evidence Preview verified: no mutation is required.';
        } elseif (empty($prepared['apply_allowed'])) {
            $message = 'Academic Evidence Preview prepared, but Apply is blocked by the evidence gate.';
        } else {
            $message = 'Academic Evidence Preview prepared and allowed. Review it before Apply.';
        }
        $this->redirect_notice(empty($prepared['apply_allowed']) ? 'warning' : 'success', $message, $args);
    }

    public function handle_apply(): void {
        $this->require_admin('erm_evidence_apply');
        $prepared = get_transient($this->preview_key());
        if (! is_array($prepared)) {
            $this->redirect_notice('warning', 'The prepared Academic Evidence Preview expired or is unavailable. Prepare a new Preview before Apply.');
        }
        $args = array();
        if (! empty($prepared['group'])) { $args['erm_evidence_group'] = sanitize_key((string) $prepared['group']); }
        if (! empty($prepared['record_id']) && 'delete' !== (string) ($prepared['operation'] ?? '')) { $args['erm_evidence_id'] = sanitize_key((string) $prepared['record_id']); }
        $result = Eduardo_Research_Manager::evidence_editor()->apply_preview($prepared);
        if (is_wp_error($result)) {
            if ('research_manager_evidence_stale_preview' === $result->get_error_code()) { delete_transient($this->preview_key()); }
            $this->redirect_notice('error', sprintf('Academic Evidence Apply failed: %s', $result->get_error_message()), $args);
        }
        delete_transient($this->preview_key());
        $snapshot_id = sanitize_text_field((string) ($result['snapshot_id'] ?? ''));
        if ('' !== $snapshot_id) {
            set_transient($this->edit_key(), array(
                'resource'=>(string) ($result['resource'] ?? $prepared['resource'] ?? ''),
                'operation'=>(string) ($result['operation'] ?? $prepared['operation'] ?? ''),
                'group'=>(string) ($result['group'] ?? $prepared['group'] ?? ''),
                'record_id'=>(string) ($result['record_id'] ?? $prepared['record_id'] ?? ''),
                'snapshot_id'=>$snapshot_id,
                'plan_id'=>(string) ($result['plan_id'] ?? ''),
                'applied_at'=>gmdate(DATE_W3C),
            ), DAY_IN_SECONDS);
            $this->redirect_notice('success', 'Academic Evidence change applied and verified. A reversible snapshot is available.', $args);
        }
        $this->redirect_notice('success', 'Academic Evidence already matched the prepared Preview. No mutation was required.', $args);
    }

    public function handle_rollback(): void {
        $this->require_admin('erm_evidence_rollback');
        $edit = get_transient($this->edit_key());
        if (! is_array($edit) || empty($edit['snapshot_id'])) {
            $this->redirect_notice('warning', 'No reversible Academic Evidence edit is available.');
        }
        $result = Eduardo_Research_Manager::evidence_editor()->rollback((string) $edit['snapshot_id']);
        $args = array();
        if (! empty($edit['group'])) { $args['erm_evidence_group'] = sanitize_key((string) $edit['group']); }
        if ('delete' !== (string) ($edit['operation'] ?? '') && ! empty($edit['record_id'])) { $args['erm_evidence_id'] = sanitize_key((string) $edit['record_id']); }
        if (is_wp_error($result)) {
            $this->redirect_notice('error', sprintf('Academic Evidence rollback failed: %s', $result->get_error_message()), $args);
        }
        delete_transient($this->edit_key());
        $this->redirect_notice('success', 'Latest Academic Evidence edit rolled back successfully.', $args);
    }

    private function render_record_fields(array $record): void {
        $translation = isset($record['translations']['es']) && is_array($record['translations']['es']) ? $record['translations']['es'] : array();
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row"><label for="erm-evidence-status">Evidence status</label></th><td><select id="erm-evidence-status" name="status"><option value="unverified" ' . selected((string) ($record['status'] ?? 'unverified'), 'unverified', false) . '>UNVERIFIED</option><option value="verified" ' . selected((string) ($record['status'] ?? ''), 'verified', false) . '>VERIFIED</option></select><p class="description">Only VERIFIED records are exposed by public Theme evidence surfaces.</p></td></tr>';
        foreach (array('title'=>'Title','label'=>'Label','value'=>'Value','period'=>'Period','date'=>'Date','dates'=>'Dates','start_date'=>'Start date','end_date'=>'End date','organization'=>'Organization','institution'=>'Institution','company'=>'Company','affiliation'=>'Affiliation','url'=>'Public URL') as $name => $label) {
            $this->text_row($label, $name, (string) ($record[$name] ?? ''));
        }
        $this->textarea_row('Summary', 'summary', (string) ($record['summary'] ?? ''), 4);
        echo '<tr><th colspan="2"><h4 style="margin:12px 0 0">Spanish translation</h4><p class="description">The English/base record remains canonical; these overrides feed Spanish Theme surfaces.</p></th></tr>';
        foreach (array('title'=>'Title ES','label'=>'Label ES','value'=>'Value ES','period'=>'Period ES','organization'=>'Organization ES','institution'=>'Institution ES','company'=>'Company ES','affiliation'=>'Affiliation ES') as $name => $label) {
            $this->text_row($label, 'es_' . $name, (string) ($translation[$name] ?? ''));
        }
        $this->textarea_row('Summary ES', 'es_summary', (string) ($translation['summary'] ?? ''), 4);
        echo '</tbody></table>';
    }

    private function record_data(array $source): array {
        $data = array('status'=>sanitize_key($this->scalar($source, 'status', 'unverified')));
        foreach (array('title','label','value','period','date','dates','start_date','end_date','organization','institution','company','affiliation','url','summary') as $field) {
            $data[$field] = $this->scalar($source, $field);
        }
        $translation = array();
        foreach (array('title','label','value','period','organization','institution','company','affiliation','summary') as $field) {
            $translation[$field] = $this->scalar($source, 'es_' . $field);
        }
        $data['translations'] = array('es'=>$translation);
        return $data;
    }

    private function render_gate(string $context): void {
        echo '<fieldset style="border:1px solid #c3c4c7;padding:12px 16px;margin:16px 0;max-width:900px"><legend><strong>Academic evidence gate</strong></legend>';
        echo '<label style="display:block;margin-bottom:8px"><input type="checkbox" name="evidence_confirmed" value="1"> I confirm that this ' . esc_html($context) . ' change is supported by the source/reference below.</label>';
        echo '<label for="erm-evidence-reference-' . esc_attr($context) . '"><strong>Evidence source / reference</strong></label><br>';
        echo '<input id="erm-evidence-reference-' . esc_attr($context) . '" class="regular-text" style="width:100%;max-width:760px" type="text" name="evidence_reference" placeholder="DOI, ORCID record, institutional page, controlled CV source, repository, dataset or verification note">';
        echo '<p class="description">Apply remains blocked until confirmation is explicit and the verification reference is non-empty.</p></fieldset>';
    }

    private function record_title(array $record): string {
        foreach (array('title','label','value','url') as $field) {
            if (isset($record[$field]) && is_scalar($record[$field]) && '' !== trim((string) $record[$field])) { return trim((string) $record[$field]); }
        }
        return 'Evidence record';
    }

    private function text_row(string $label, string $name, string $value): void {
        echo '<tr><th scope="row"><label for="erm-evidence-' . esc_attr($name) . '">' . esc_html($label) . '</label></th><td><input id="erm-evidence-' . esc_attr($name) . '" class="regular-text" style="width:100%;max-width:760px" type="text" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '"></td></tr>';
    }

    private function textarea_row(string $label, string $name, string $value, int $rows): void {
        echo '<tr><th scope="row"><label for="erm-evidence-' . esc_attr($name) . '">' . esc_html($label) . '</label></th><td><textarea id="erm-evidence-' . esc_attr($name) . '" name="' . esc_attr($name) . '" rows="' . esc_attr((string) $rows) . '" style="width:100%;max-width:900px">' . esc_textarea($value) . '</textarea></td></tr>';
    }

    private function scalar(array $source, string $key, string $default = ''): string {
        $value = $source[$key] ?? $default;
        if (is_array($value) || is_object($value)) { return $default; }
        return (string) wp_unslash((string) $value);
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
        return add_query_arg(array_merge(array('page'=>'eduardo-research-evidence'), $args), admin_url('tools.php'));
    }
}
