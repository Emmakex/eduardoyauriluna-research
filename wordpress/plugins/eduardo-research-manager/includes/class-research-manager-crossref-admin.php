<?php
/** Read-only exact-DOI reconciliation UI for Research Outputs. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Crossref_Admin {
    private const PREVIEW_PREFIX = 'eduardo_research_crossref_preview_';
    private const NOTICE_PREFIX = 'eduardo_research_crossref_notice_';

    public function register(): void {
        add_action('admin_menu', array($this, 'menu'), 12);
        add_action('admin_post_eduardo_research_crossref_preview_output', array($this, 'handle_preview'));
    }

    public function menu(): void {
        add_submenu_page(
            'eduardo-research-workspace',
            'Crossref DOI Reconciliation',
            'DOI Reconciliation',
            'manage_options',
            'eduardo-research-crossref',
            array($this, 'render')
        );
        add_management_page(
            'Crossref DOI Reconciliation',
            'Crossref DOI Reconciliation',
            'manage_options',
            'eduardo-research-crossref',
            array($this, 'render')
        );
    }

    public function render(): void {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access Crossref DOI Reconciliation.', 'eduardo-research-manager'));
        }

        $rows = Eduardo_Research_Manager::object_editor()->list('output');
        $rows = is_wp_error($rows) ? $rows : array_values($rows);
        $selected_id = isset($_GET['erm_output_id']) ? absint($_GET['erm_output_id']) : 0;
        $selected = $selected_id > 0 ? Eduardo_Research_Manager::outputs()->inspect($selected_id) : null;
        $preview = get_transient($this->preview_key());
        $preview = is_array($preview) && $selected_id === absint($preview['post_id'] ?? 0) ? $preview : array();
        $notice = $this->pull_notice();
        ?>
        <div class="wrap">
          <h1><?php echo esc_html__('Crossref DOI Reconciliation', 'eduardo-research-manager'); ?></h1>
          <p><?php echo esc_html__('Compare a local Research Output with authoritative Crossref metadata using its exact DOI. This surface is read-only and never searches by researcher name.', 'eduardo-research-manager'); ?></p>

          <?php if ($notice) : ?>
            <div class="notice notice-<?php echo esc_attr((string) $notice['type']); ?> is-dismissible"><p><?php echo esc_html((string) $notice['message']); ?></p></div>
          <?php endif; ?>

          <div class="notice notice-info inline"><p><strong><?php echo esc_html__('Exact DOI only.', 'eduardo-research-manager'); ?></strong> <?php echo esc_html__('Crossref can propose metadata differences, but no local Research Output or Academic Evidence is modified from this screen.', 'eduardo-research-manager'); ?></p></div>

          <?php if (is_wp_error($rows)) : ?>
            <div class="notice notice-error inline"><p><?php echo esc_html($rows->get_error_message()); ?></p></div>
          <?php elseif (! $rows) : ?>
            <p><?php echo esc_html__('Create a Research Output before running DOI reconciliation.', 'eduardo-research-manager'); ?></p>
            <p><a class="button button-primary" href="<?php echo esc_url($this->objects_url()); ?>"><?php echo esc_html__('Open Research Objects', 'eduardo-research-manager'); ?></a></p>
          <?php else : ?>
            <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" style="display:flex;gap:12px;align-items:end;flex-wrap:wrap;margin:18px 0">
              <input type="hidden" name="page" value="eduardo-research-crossref">
              <p style="margin:0;min-width:420px">
                <label for="erm-crossref-output"><strong><?php echo esc_html__('Research Output', 'eduardo-research-manager'); ?></strong></label><br>
                <select id="erm-crossref-output" name="erm_output_id" style="min-width:420px">
                  <option value="0"><?php echo esc_html__('Select an output…', 'eduardo-research-manager'); ?></option>
                  <?php foreach ($rows as $row) : ?>
                    <?php
                    if (! is_array($row)) { continue; }
                    $id = absint($row['post_id'] ?? 0);
                    $doi = (string) ($row['doi'] ?? '');
                    $label = sprintf('%s [%s]%s', (string) ($row['title'] ?? ('#' . $id)), strtoupper((string) ($row['language'] ?? 'en')), '' !== $doi ? ' — ' . $doi : ' — no DOI');
                    ?>
                    <option value="<?php echo esc_attr((string) $id); ?>" <?php selected($selected_id, $id); ?>><?php echo esc_html($label); ?></option>
                  <?php endforeach; ?>
                </select>
              </p>
              <?php submit_button(__('Open output', 'eduardo-research-manager'), 'secondary', 'submit', false); ?>
            </form>
          <?php endif; ?>

          <?php if ($selected_id > 0) : ?>
            <?php if (is_wp_error($selected)) : ?>
              <div class="notice notice-error inline"><p><?php echo esc_html($selected->get_error_message()); ?></p></div>
            <?php else : ?>
              <h2><?php echo esc_html__('Selected Research Output', 'eduardo-research-manager'); ?></h2>
              <table class="widefat striped" style="max-width:1000px"><tbody>
                <tr><th><?php echo esc_html__('Title', 'eduardo-research-manager'); ?></th><td><?php echo esc_html((string) $selected['title']); ?></td></tr>
                <tr><th><?php echo esc_html__('Language', 'eduardo-research-manager'); ?></th><td><?php echo esc_html(strtoupper((string) $selected['language'])); ?></td></tr>
                <tr><th><?php echo esc_html__('Stored DOI', 'eduardo-research-manager'); ?></th><td><?php echo '' !== (string) $selected['doi'] ? '<code>' . esc_html((string) $selected['doi']) . '</code>' : '—'; ?></td></tr>
                <tr><th><?php echo esc_html__('DOI verified flag', 'eduardo-research-manager'); ?></th><td><?php echo '1' === (string) ($selected['doi_verified'] ?? '0') ? esc_html__('VERIFIED', 'eduardo-research-manager') : esc_html__('NOT VERIFIED', 'eduardo-research-manager'); ?></td></tr>
              </tbody></table>

              <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="max-width:1000px;margin-top:16px">
                <input type="hidden" name="action" value="eduardo_research_crossref_preview_output">
                <input type="hidden" name="post_id" value="<?php echo esc_attr((string) $selected_id); ?>">
                <?php wp_nonce_field('erm_crossref_preview_output'); ?>
                <p>
                  <label for="erm-crossref-doi"><strong><?php echo esc_html__('Exact DOI', 'eduardo-research-manager'); ?></strong></label><br>
                  <input class="regular-text code" id="erm-crossref-doi" name="doi" value="<?php echo esc_attr((string) $selected['doi']); ?>" placeholder="10.xxxx/xxxxx" required>
                </p>
                <p class="description"><?php echo esc_html__('The DOI may be supplied as a DOI string or doi.org URL. Names, titles and fuzzy searches are rejected.', 'eduardo-research-manager'); ?></p>
                <?php submit_button(__('Preview Crossref metadata', 'eduardo-research-manager'), 'primary', 'submit', false); ?>
              </form>

              <p><a class="button" href="<?php echo esc_url($this->edit_output_url($selected)); ?>"><?php echo esc_html__('Edit this Research Output', 'eduardo-research-manager'); ?></a> <a class="button" href="<?php echo esc_url((string) $selected['url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('View rendered output', 'eduardo-research-manager'); ?></a></p>
            <?php endif; ?>
          <?php endif; ?>

          <?php if ($preview) : ?>
            <?php $remote = is_array($preview['remote'] ?? null) ? $preview['remote'] : array(); ?>
            <h2><?php echo esc_html__('Crossref reconciliation preview', 'eduardo-research-manager'); ?></h2>
            <div class="notice notice-info inline"><p><strong><?php echo esc_html__('Preview only.', 'eduardo-research-manager'); ?></strong> <?php echo esc_html__('Review differences below. No automatic Apply exists in this microphase.', 'eduardo-research-manager'); ?></p></div>
            <table class="widefat striped" style="max-width:1100px;margin-top:12px">
              <thead><tr><th><?php echo esc_html__('Field', 'eduardo-research-manager'); ?></th><th><?php echo esc_html__('Local Research Output', 'eduardo-research-manager'); ?></th><th><?php echo esc_html__('Crossref', 'eduardo-research-manager'); ?></th><th><?php echo esc_html__('State', 'eduardo-research-manager'); ?></th></tr></thead>
              <tbody>
              <?php foreach ((array) ($preview['comparison'] ?? array()) as $field => $comparison) : ?>
                <?php if (! is_array($comparison)) { continue; } ?>
                <tr>
                  <th><?php echo esc_html(ucwords(str_replace('_', ' ', (string) $field))); ?></th>
                  <td><?php echo esc_html($this->display_value($comparison['local'] ?? '')); ?></td>
                  <td><?php echo esc_html($this->display_value($comparison['remote'] ?? '')); ?></td>
                  <td><strong><?php echo ! empty($comparison['matches']) ? esc_html__('MATCH', 'eduardo-research-manager') : (! empty($comparison['candidate']) ? esc_html__('DIFFERS', 'eduardo-research-manager') : esc_html__('NO REMOTE VALUE', 'eduardo-research-manager')); ?></strong></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>

            <h3><?php echo esc_html__('Crossref provenance', 'eduardo-research-manager'); ?></h3>
            <table class="widefat striped" style="max-width:1000px"><tbody>
              <tr><th><?php echo esc_html__('Exact DOI', 'eduardo-research-manager'); ?></th><td><code><?php echo esc_html((string) ($preview['doi'] ?? '')); ?></code></td></tr>
              <tr><th><?php echo esc_html__('Publisher', 'eduardo-research-manager'); ?></th><td><?php echo esc_html((string) ($remote['publisher'] ?? '')); ?></td></tr>
              <tr><th><?php echo esc_html__('Crossref type', 'eduardo-research-manager'); ?></th><td><?php echo esc_html((string) ($remote['crossref_type'] ?? '')); ?></td></tr>
              <tr><th><?php echo esc_html__('References', 'eduardo-research-manager'); ?></th><td><?php echo esc_html((string) ((int) ($remote['reference_count'] ?? 0))); ?></td></tr>
              <tr><th><?php echo esc_html__('Cited by', 'eduardo-research-manager'); ?></th><td><?php echo esc_html((string) ((int) ($remote['is_referenced_by_count'] ?? 0))); ?></td></tr>
              <tr><th><?php echo esc_html__('Automatic local Apply', 'eduardo-research-manager'); ?></th><td><strong><?php echo esc_html__('DISABLED', 'eduardo-research-manager'); ?></strong></td></tr>
              <tr><th><?php echo esc_html__('External writes', 'eduardo-research-manager'); ?></th><td><strong><?php echo esc_html__('DISABLED', 'eduardo-research-manager'); ?></strong></td></tr>
            </tbody></table>
          <?php endif; ?>
        </div>
        <?php
    }

    public function handle_preview(): void {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to reconcile Crossref metadata.', 'eduardo-research-manager'));
        }
        check_admin_referer('erm_crossref_preview_output');
        $post_id = absint($_POST['post_id'] ?? 0);
        $doi = sanitize_text_field((string) wp_unslash($_POST['doi'] ?? ''));
        if ($post_id <= 0) {
            $this->redirect_notice('error', 'Select a valid Research Output before Crossref reconciliation.');
        }
        $preview = Eduardo_Research_Manager::crossref()->preview_for_output($post_id, $doi);
        if (is_wp_error($preview)) {
            $this->redirect_notice('error', sprintf('Crossref Preview failed: %s', $preview->get_error_message()), $post_id);
        }
        set_transient($this->preview_key(), $preview, 30 * MINUTE_IN_SECONDS);
        $message = ! empty($preview['has_differences'])
            ? 'Crossref metadata read successfully. Differences require human review before any future local Apply.'
            : 'Crossref metadata read successfully. Compared fields already match the local Research Output.';
        $this->redirect_notice('success', $message, $post_id);
    }

    private function display_value(mixed $value): string {
        if (is_array($value)) {
            return implode(', ', array_values(array_filter(array_map(static fn($item): string => is_scalar($item) ? trim((string) $item) : '', $value), static fn(string $item): bool => '' !== $item)));
        }
        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function edit_output_url(array $selected): string {
        return add_query_arg(array(
            'page'=>'eduardo-research-objects',
            'erm_object_kind'=>'output',
            'erm_object_language'=>(string) ($selected['language'] ?? 'en'),
            'erm_object_id'=>absint($selected['post_id'] ?? 0),
        ), admin_url('admin.php'));
    }

    private function objects_url(): string {
        return add_query_arg(array('page'=>'eduardo-research-objects','erm_object_kind'=>'output'), admin_url('admin.php'));
    }

    private function preview_key(): string {
        return self::PREVIEW_PREFIX . get_current_user_id();
    }

    private function notice_key(): string {
        return self::NOTICE_PREFIX . get_current_user_id();
    }

    private function set_notice(string $type, string $message): void {
        set_transient($this->notice_key(), array('type'=>$type,'message'=>sanitize_text_field($message)), 120);
    }

    private function pull_notice(): array {
        $key = $this->notice_key();
        $notice = get_transient($key);
        delete_transient($key);
        return is_array($notice) ? $notice : array();
    }

    private function redirect_notice(string $type, string $message, int $post_id = 0): never {
        $this->set_notice($type, $message);
        $args = array('page'=>'eduardo-research-crossref');
        if ($post_id > 0) { $args['erm_output_id'] = $post_id; }
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }
}
