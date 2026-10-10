<?php
/** Unified structured authoring UI for Research Outputs, Projects, Software and Datasets. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Object_Admin {
    private const PREVIEW_PREFIX = 'eduardo_research_manager_object_preview_';
    private const EDIT_PREFIX = 'eduardo_research_manager_object_edit_';
    private const NOTICE_PREFIX = 'eduardo_research_manager_object_notice_';

    public function register(): void {
        add_action('admin_menu', array($this, 'menu'));
        add_action('admin_post_eduardo_research_manager_preview_object', array($this, 'handle_preview'));
        add_action('admin_post_eduardo_research_manager_apply_object_preview', array($this, 'handle_apply'));
        add_action('admin_post_eduardo_research_manager_rollback_object_edit', array($this, 'handle_rollback'));
    }

    public function menu(): void {
        add_management_page(
            'Research Objects',
            'Research Objects',
            'manage_options',
            'eduardo-research-objects',
            array($this, 'render')
        );
    }

    public function render(): void {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access Research Objects.', 'eduardo-research-manager'));
        }

        $editor = Eduardo_Research_Manager::object_editor();
        $kinds = $this->kinds();
        $kind = $this->selected_kind($kinds);
        $languages = Eduardo_Research_Manager::contract()->languages();
        $language = $this->selected_language($languages);
        $rows = $editor->list($kind, $language);
        $selected_id = isset($_GET['erm_object_id']) ? absint($_GET['erm_object_id']) : 0;
        $selected = $selected_id > 0 ? $editor->inspect($kind, $selected_id) : null;
        $prepared = get_transient($this->preview_key());
        $prepared = is_array($prepared) ? $prepared : array();
        $last_edit = get_transient($this->edit_key());
        $last_edit = is_array($last_edit) ? $last_edit : array();
        $notice = $this->pull_notice();
        $spec = $kinds[$kind];
        ?>
        <div class="wrap">
          <h1><?php echo esc_html__('Research Objects', 'eduardo-research-manager'); ?></h1>
          <p><?php echo esc_html__('Evidence-aware structured authoring for publications, research projects, research software and datasets. The Theme remains responsible for public layouts and rendering.', 'eduardo-research-manager'); ?></p>

          <?php if ($notice) : ?>
            <div class="notice notice-<?php echo esc_attr((string) $notice['type']); ?> is-dismissible"><p><?php echo esc_html((string) $notice['message']); ?></p></div>
          <?php endif; ?>

          <form method="get" action="<?php echo esc_url(admin_url('tools.php')); ?>" style="display:flex;gap:12px;align-items:end;margin:12px 0 18px;flex-wrap:wrap">
            <input type="hidden" name="page" value="eduardo-research-objects">
            <p style="margin:0"><label for="erm-object-kind"><strong><?php echo esc_html__('Object type', 'eduardo-research-manager'); ?></strong></label><br>
              <select id="erm-object-kind" name="erm_object_kind">
                <?php foreach ($kinds as $candidate => $candidate_spec) : ?><option value="<?php echo esc_attr($candidate); ?>" <?php selected($kind, $candidate); ?>><?php echo esc_html((string) $candidate_spec['plural']); ?></option><?php endforeach; ?>
              </select>
            </p>
            <p style="margin:0"><label for="erm-object-language"><strong><?php echo esc_html__('Language', 'eduardo-research-manager'); ?></strong></label><br>
              <select id="erm-object-language" name="erm_object_language">
                <?php foreach ($languages as $candidate) : ?><option value="<?php echo esc_attr((string) $candidate); ?>" <?php selected($language, (string) $candidate); ?>><?php echo esc_html(strtoupper((string) $candidate)); ?></option><?php endforeach; ?>
              </select>
            </p>
            <?php submit_button(__('Filter Research Objects', 'eduardo-research-manager'), 'secondary', 'submit', false); ?>
          </form>

          <h2><?php echo esc_html(sprintf('Create %s', (string) $spec['singular'])); ?></h2>
          <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="max-width:1100px">
            <input type="hidden" name="action" value="eduardo_research_manager_preview_object">
            <input type="hidden" name="editor_mode" value="create">
            <input type="hidden" name="object_kind" value="<?php echo esc_attr($kind); ?>">
            <?php wp_nonce_field('erm_object_preview'); ?>
            <?php $this->render_fields($kind, $this->defaults($kind, $language), $languages, 'create'); ?>
            <?php $this->render_evidence_fields(); ?>
            <?php submit_button(sprintf(__('Preview new %s', 'eduardo-research-manager'), (string) $spec['singular']), 'secondary', 'submit', false); ?>
          </form>

          <h2><?php echo esc_html(sprintf('%s — %s', (string) $spec['plural'], strtoupper($language))); ?></h2>
          <?php if (is_wp_error($rows)) : ?>
            <div class="notice notice-error inline"><p><?php echo esc_html($rows->get_error_message()); ?></p></div>
          <?php elseif (! $rows) : ?>
            <p><?php echo esc_html(sprintf('No %s exist in this language yet.', strtolower((string) $spec['plural']))); ?></p>
          <?php else : ?>
            <table class="widefat striped" style="max-width:1100px"><thead><tr><th><?php echo esc_html__('Record', 'eduardo-research-manager'); ?></th><th><?php echo esc_html__('Structured status', 'eduardo-research-manager'); ?></th><th><?php echo esc_html__('WordPress state', 'eduardo-research-manager'); ?></th><th><?php echo esc_html__('Action', 'eduardo-research-manager'); ?></th></tr></thead><tbody>
              <?php foreach ($rows as $row) : ?>
                <tr>
                  <td><strong><?php echo esc_html((string) $row['title']); ?></strong><br><code><?php echo esc_html((string) $row['slug']); ?></code></td>
                  <td><?php echo esc_html($this->structured_status($kind, $row)); ?></td>
                  <td><?php echo esc_html(strtoupper((string) $row['status'])); ?></td>
                  <td><a href="<?php echo esc_url($this->url(array('erm_object_kind'=>$kind,'erm_object_language'=>$language,'erm_object_id'=>(int) $row['post_id']))); ?>"><?php echo esc_html__('Edit structured record', 'eduardo-research-manager'); ?></a></td>
                </tr>
              <?php endforeach; ?>
            </tbody></table>
          <?php endif; ?>

          <?php if ($selected_id > 0) : ?>
            <h2><?php echo esc_html(sprintf('Edit selected %s', (string) $spec['singular'])); ?></h2>
            <?php if (is_wp_error($selected)) : ?>
              <div class="notice notice-error inline"><p><?php echo esc_html($selected->get_error_message()); ?></p></div>
            <?php else : ?>
              <p><strong><?php echo esc_html((string) $selected['title']); ?></strong> — <a href="<?php echo esc_url((string) $selected['url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('View rendered record', 'eduardo-research-manager'); ?></a></p>
              <p class="description"><?php echo esc_html__('The permanent slug and WordPress publication state stay immutable in this structured update flow. Use the Research Manager fields below; the Theme still owns layout.', 'eduardo-research-manager'); ?></p>
              <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="max-width:1100px">
                <input type="hidden" name="action" value="eduardo_research_manager_preview_object">
                <input type="hidden" name="editor_mode" value="update">
                <input type="hidden" name="object_kind" value="<?php echo esc_attr($kind); ?>">
                <input type="hidden" name="post_id" value="<?php echo esc_attr((string) $selected_id); ?>">
                <?php wp_nonce_field('erm_object_preview'); ?>
                <?php $this->render_fields($kind, $selected, $languages, 'update'); ?>
                <?php $this->render_evidence_fields(); ?>
                <?php submit_button(sprintf(__('Preview %s changes', 'eduardo-research-manager'), (string) $spec['singular']), 'secondary', 'submit', false); ?>
              </form>
            <?php endif; ?>
          <?php endif; ?>

          <?php if ($prepared) : ?>
            <h2><?php echo esc_html__('Prepared Research Object change', 'eduardo-research-manager'); ?></h2>
            <?php $actions = is_array($prepared['plan']['actions'] ?? null) ? count($prepared['plan']['actions']) : 0; ?>
            <table class="widefat striped" style="max-width:900px"><tbody>
              <tr><th><?php echo esc_html__('Object type', 'eduardo-research-manager'); ?></th><td><?php echo esc_html(strtoupper((string) ($prepared['kind'] ?? ''))); ?></td></tr>
              <tr><th><?php echo esc_html__('Mode', 'eduardo-research-manager'); ?></th><td><?php echo esc_html(strtoupper((string) ($prepared['mode'] ?? ''))); ?></td></tr>
              <tr><th><?php echo esc_html__('Status', 'eduardo-research-manager'); ?></th><td><?php echo esc_html(strtoupper((string) ($prepared['status'] ?? ''))); ?></td></tr>
              <tr><th><?php echo esc_html__('Risk', 'eduardo-research-manager'); ?></th><td><?php echo esc_html(strtoupper((string) ($prepared['risk'] ?? ''))); ?></td></tr>
              <tr><th><?php echo esc_html__('Mutation actions', 'eduardo-research-manager'); ?></th><td><?php echo esc_html((string) $actions); ?></td></tr>
              <tr><th><?php echo esc_html__('Apply gate', 'eduardo-research-manager'); ?></th><td><?php echo ! empty($prepared['apply_allowed']) ? esc_html__('Allowed', 'eduardo-research-manager') : esc_html__('Blocked', 'eduardo-research-manager'); ?></td></tr>
              <?php if (empty($prepared['apply_allowed']) && '' !== (string) ($prepared['apply_blocker'] ?? '')) : ?><tr><th><?php echo esc_html__('Blocker', 'eduardo-research-manager'); ?></th><td><?php echo esc_html((string) $prepared['apply_blocker']); ?></td></tr><?php endif; ?>
              <tr><th><?php echo esc_html__('Plan ID', 'eduardo-research-manager'); ?></th><td><code><?php echo esc_html((string) ($prepared['plan_id'] ?? 'already-matching')); ?></code></td></tr>
            </tbody></table>
            <?php if (in_array((string) ($prepared['status'] ?? ''), array('create','change'), true) && ! empty($prepared['apply_allowed'])) : ?>
              <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:12px"><input type="hidden" name="action" value="eduardo_research_manager_apply_object_preview"><?php wp_nonce_field('erm_object_apply'); ?><?php submit_button(__('Apply prepared Research Object change', 'eduardo-research-manager'), 'primary', 'submit', false); ?></form>
            <?php elseif ('already-matching' === (string) ($prepared['status'] ?? '')) : ?>
              <p><strong><?php echo esc_html__('No mutation required: this Research Object already matches the prepared structured state.', 'eduardo-research-manager'); ?></strong></p>
            <?php else : ?>
              <p><strong><?php echo esc_html__('Apply is blocked. Confirm the academic evidence and provide its source/reference, then prepare a new Preview.', 'eduardo-research-manager'); ?></strong></p>
            <?php endif; ?>
          <?php endif; ?>

          <?php if ($last_edit && ! empty($last_edit['snapshot_id'])) : ?>
            <h2><?php echo esc_html__('Latest reversible Research Object edit', 'eduardo-research-manager'); ?></h2>
            <p><strong><?php echo esc_html(strtoupper((string) ($last_edit['kind'] ?? ''))); ?> / <?php echo esc_html(strtoupper((string) ($last_edit['mode'] ?? ''))); ?></strong> — <?php echo esc_html((string) ($last_edit['applied_at'] ?? '')); ?></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="eduardo_research_manager_rollback_object_edit"><?php wp_nonce_field('erm_object_rollback'); ?><?php submit_button(__('Rollback latest Research Object edit', 'eduardo-research-manager'), 'secondary', 'submit', false); ?></form>
          <?php endif; ?>
        </div>
        <?php
    }

    public function handle_preview(): void {
        $this->require_admin('erm_object_preview');
        $kind = sanitize_key((string) wp_unslash($_POST['object_kind'] ?? ''));
        if (! isset($this->kinds()[$kind])) { $this->redirect_notice('error', 'Unknown Research Object type.'); }
        $mode = sanitize_key((string) wp_unslash($_POST['editor_mode'] ?? ''));
        $evidence_confirmed = ! empty($_POST['evidence_confirmed']);
        $evidence_reference = sanitize_text_field((string) wp_unslash($_POST['evidence_reference'] ?? ''));
        $data = $this->read_fields($_POST, $kind, $mode);
        if (is_wp_error($data)) { $this->redirect_notice('error', $data->get_error_message(), array('erm_object_kind'=>$kind)); }

        $args = array('erm_object_kind'=>$kind,'erm_object_language'=>(string) $data['language']);
        if ('create' === $mode) {
            $prepared = Eduardo_Research_Manager::object_editor()->preview_create($kind, $data, $evidence_confirmed, $evidence_reference);
        } elseif ('update' === $mode) {
            $post_id = absint($_POST['post_id'] ?? 0);
            $prepared = Eduardo_Research_Manager::object_editor()->preview_update($kind, $post_id, $data, $evidence_confirmed, $evidence_reference);
            $args['erm_object_id'] = $post_id;
        } else {
            $this->redirect_notice('error', 'Unknown Research Object editor mode.', $args);
        }

        if (is_wp_error($prepared)) {
            $this->redirect_notice('error', sprintf('Research Object Preview failed: %s', $prepared->get_error_message()), $args);
        }
        set_transient($this->preview_key(), $prepared, 30 * MINUTE_IN_SECONDS);
        if ('already-matching' === (string) ($prepared['status'] ?? '')) {
            $message = 'Research Object Preview verified: no mutation is required.';
        } elseif (empty($prepared['apply_allowed'])) {
            $message = 'Research Object Preview prepared, but Apply is blocked by the evidence gate.';
        } else {
            $message = 'Research Object Preview prepared and allowed. Review it before Apply.';
        }
        $this->redirect_notice(empty($prepared['apply_allowed']) ? 'warning' : 'success', $message, $args);
    }

    public function handle_apply(): void {
        $this->require_admin('erm_object_apply');
        $prepared = get_transient($this->preview_key());
        if (! is_array($prepared)) {
            $this->redirect_notice('warning', 'The prepared Research Object Preview expired or is unavailable. Prepare a new Preview before Apply.');
        }

        $result = Eduardo_Research_Manager::object_editor()->apply_preview($prepared);
        $kind = sanitize_key((string) ($prepared['kind'] ?? 'output'));
        $language = sanitize_key((string) ($prepared['expected']['language'] ?? 'en'));
        $post_id = absint($prepared['post_id'] ?? 0);
        $args = array('erm_object_kind'=>$kind,'erm_object_language'=>$language);
        if ($post_id > 0) { $args['erm_object_id'] = $post_id; }
        if (is_wp_error($result)) {
            if ('research_manager_object_editor_stale_preview' === $result->get_error_code()) { delete_transient($this->preview_key()); }
            $this->redirect_notice('error', sprintf('Research Object Apply failed: %s', $result->get_error_message()), $args);
        }

        delete_transient($this->preview_key());
        $post_id = absint($result['post_id'] ?? $post_id);
        if ($post_id > 0) { $args['erm_object_id'] = $post_id; }
        $snapshot_id = sanitize_text_field((string) ($result['snapshot_id'] ?? ''));
        if ('' !== $snapshot_id) {
            set_transient(
                $this->edit_key(),
                array(
                    'kind'=>$kind,
                    'mode'=>(string) ($result['mode'] ?? $prepared['mode'] ?? ''),
                    'post_id'=>$post_id,
                    'language'=>$language,
                    'snapshot_id'=>$snapshot_id,
                    'applied_at'=>gmdate(DATE_W3C),
                    'plan_id'=>(string) ($result['plan_id'] ?? ''),
                ),
                DAY_IN_SECONDS
            );
            $this->redirect_notice('success', 'Structured Research Object change applied and verified. A reversible snapshot is available.', $args);
        }
        $this->redirect_notice('success', 'Research Object already matched the prepared Preview. No mutation was required.', $args);
    }

    public function handle_rollback(): void {
        $this->require_admin('erm_object_rollback');
        $edit = get_transient($this->edit_key());
        if (! is_array($edit) || empty($edit['snapshot_id'])) {
            $this->redirect_notice('warning', 'No reversible Research Object edit is available.');
        }
        $result = Eduardo_Research_Manager::object_editor()->rollback((string) $edit['snapshot_id']);
        $args = array(
            'erm_object_kind'=>sanitize_key((string) ($edit['kind'] ?? 'output')),
            'erm_object_language'=>sanitize_key((string) ($edit['language'] ?? 'en')),
        );
        $post_id = absint($edit['post_id'] ?? 0);
        if ('create' !== (string) ($edit['mode'] ?? '') && $post_id > 0) { $args['erm_object_id'] = $post_id; }
        if (is_wp_error($result)) {
            $this->redirect_notice('error', sprintf('Research Object rollback failed: %s', $result->get_error_message()), $args);
        }
        delete_transient($this->edit_key());
        $this->redirect_notice('success', 'Latest Research Object edit rolled back successfully.', $args);
    }

    private function render_fields(string $kind, array $record, array $languages, string $mode): void {
        $language = sanitize_key((string) ($record['language'] ?? 'en'));
        echo '<table class="form-table" role="presentation"><tbody>';
        $this->text_row('Title', 'title', (string) ($record['title'] ?? ''), true);
        if ('create' === $mode) {
            $this->text_row('Permanent slug', 'slug', (string) ($record['slug'] ?? ''), true, 'Stable URL slug. It is immutable after creation in this Manager flow.');
        } else {
            echo '<tr><th scope="row">Permanent slug</th><td><code>' . esc_html((string) ($record['slug'] ?? '')) . '</code></td></tr>';
        }

        echo '<tr><th scope="row"><label for="erm-object-language-field">Language</label></th><td><select id="erm-object-language-field" name="language">';
        foreach ($languages as $candidate) {
            echo '<option value="' . esc_attr((string) $candidate) . '" ' . selected($language, (string) $candidate, false) . '>' . esc_html(strtoupper((string) $candidate)) . '</option>';
        }
        echo '</select></td></tr>';

        if ('create' === $mode) {
            $status = sanitize_key((string) ($record['status'] ?? 'draft'));
            echo '<tr><th scope="row"><label for="erm-object-status">WordPress state</label></th><td><select id="erm-object-status" name="status"><option value="draft" ' . selected($status, 'draft', false) . '>DRAFT</option><option value="publish" ' . selected($status, 'publish', false) . '>PUBLISH</option></select><p class="description">Creation only. Existing publication state is immutable in the structured update flow.</p></td></tr>';
        } else {
            echo '<tr><th scope="row">WordPress state</th><td><strong>' . esc_html(strtoupper((string) ($record['status'] ?? ''))) . '</strong></td></tr>';
        }

        $this->textarea_row('Summary / excerpt', 'excerpt', (string) ($record['excerpt'] ?? ''), 4);
        $this->textarea_row('Extended content', 'content', (string) ($record['content'] ?? ''), 8, 'Safe HTML is accepted and normalized by the Manager contract.');
        $this->render_kind_fields($kind, $record, $language);
        echo '</tbody></table>';
    }

    private function render_kind_fields(string $kind, array $record, string $language): void {
        $spec = $this->kinds()[$kind];
        $options = function_exists('eduardo_research_collection_options')
            ? eduardo_research_collection_options((string) $spec['surface'], $language)
            : array();

        if ('output' === $kind) {
            $this->select_row('Output type', 'output_type', (string) ($record['output_type'] ?? ''), (array) ($options['output_type'] ?? array()), true);
            $this->select_row('Academic review status', 'review_status', (string) ($record['review_status'] ?? ''), (array) ($options['review_status'] ?? array()), true);
            $this->text_row('Publication date', 'publication_date', (string) ($record['publication_date'] ?? ''), false, 'YYYY, YYYY-MM or YYYY-MM-DD.');
            $this->text_row('Venue / publication', 'venue', (string) ($record['venue'] ?? ''));
            $this->text_row('DOI', 'doi', (string) ($record['doi'] ?? ''), false, 'Use the canonical 10.xxxx/... identifier.');
            $authors = wp_json_encode($record['authors'] ?? array(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $this->textarea_row('Authors JSON', 'authors_json', is_string($authors) ? $authors : '[]', 7, 'JSON array of names or structured author objects. This preserves ORCID and affiliation fields when present.');
        } elseif ('project' === $kind) {
            $this->select_row('Project status', 'project_status', (string) ($record['project_status'] ?? ''), (array) ($options['project_status'] ?? array()), true);
            $this->textarea_row('Research question', 'question', (string) ($record['question'] ?? ''), 3);
            $this->text_row('Research role', 'role', (string) ($record['role'] ?? ''));
            $this->text_row('Start date', 'start_date', (string) ($record['start_date'] ?? ''), false, 'YYYY, YYYY-MM or YYYY-MM-DD.');
            $this->text_row('End date', 'end_date', (string) ($record['end_date'] ?? ''), false, 'YYYY, YYYY-MM or YYYY-MM-DD.');
            $this->text_row('Institution / partner', 'partner', (string) ($record['partner'] ?? ''));
            $this->text_row('Funding', 'funding', (string) ($record['funding'] ?? ''));
            $this->text_row('Project URL', 'project_url', (string) ($record['project_url'] ?? ''));
            $this->textarea_row('Methods', 'methods', implode("\n", $this->string_list($record['methods'] ?? array())), 4, 'One method per line.');
        } elseif ('software' === $kind) {
            $this->select_row('Software status', 'software_status', (string) ($record['software_status'] ?? ''), (array) ($options['software_status'] ?? array()), true);
            $this->text_row('Version', 'version', (string) ($record['version'] ?? ''));
            $this->text_row('Release date', 'release_date', (string) ($record['release_date'] ?? ''), false, 'YYYY, YYYY-MM or YYYY-MM-DD.');
            $this->text_row('Repository URL', 'repository_url', (string) ($record['repository_url'] ?? ''));
            $this->text_row('Archive URL', 'archive_url', (string) ($record['archive_url'] ?? ''));
            $this->text_row('License', 'license', (string) ($record['license'] ?? ''));
            $this->text_row('Documentation URL', 'documentation_url', (string) ($record['documentation_url'] ?? ''));
            $this->text_row('DOI', 'doi', (string) ($record['doi'] ?? ''));
            $this->textarea_row('Programming languages', 'programming_languages', implode("\n", $this->string_list($record['programming_languages'] ?? array())), 4, 'One language per line.');
        } elseif ('dataset' === $kind) {
            $this->text_row('Version', 'version', (string) ($record['version'] ?? ''));
            $this->text_row('Publication date', 'publication_date', (string) ($record['publication_date'] ?? ''), false, 'YYYY, YYYY-MM or YYYY-MM-DD.');
            $this->text_row('Repository', 'repository', (string) ($record['repository'] ?? ''));
            $this->text_row('DOI', 'doi', (string) ($record['doi'] ?? ''));
            $this->text_row('License', 'license', (string) ($record['license'] ?? ''));
            $this->select_row('Access level', 'access_level', (string) ($record['access_level'] ?? ''), (array) ($options['access_level'] ?? array()), true);
            $this->textarea_row('Methodology', 'methodology', (string) ($record['methodology'] ?? ''), 4);
            $this->textarea_row('Provenance', 'provenance', (string) ($record['provenance'] ?? ''), 4);
            $this->text_row('Size', 'size', (string) ($record['size'] ?? ''));
            $this->text_row('Documentation URL', 'documentation_url', (string) ($record['documentation_url'] ?? ''));
            $this->textarea_row('Ethics notes', 'ethics_notes', (string) ($record['ethics_notes'] ?? ''), 4);
            $this->textarea_row('Formats', 'formats', implode("\n", $this->string_list($record['formats'] ?? array())), 4, 'One format per line.');
        }

        $this->render_line_relations($language, is_array($record['line_ids'] ?? null) ? $record['line_ids'] : array());
    }

    private function render_line_relations(string $language, array $selected): void {
        $selected = array_map('absint', $selected);
        $lines = function_exists('eduardo_research_collection_lines') ? eduardo_research_collection_lines($language) : array();
        echo '<tr><th scope="row">Verified Research Lines</th><td>';
        if (! $lines) {
            echo '<p class="description">No verified public Research Lines are available in this language. Relations can be added after Lines are verified.</p>';
        } else {
            foreach ($lines as $line_id => $title) {
                echo '<label style="display:block;margin:4px 0"><input type="checkbox" name="line_ids[]" value="' . esc_attr((string) $line_id) . '" ' . checked(in_array((int) $line_id, $selected, true), true, false) . '> ' . esc_html((string) $title) . '</label>';
            }
        }
        echo '</td></tr>';
    }

    private function render_evidence_fields(): void {
        echo '<fieldset style="border:1px solid #c3c4c7;padding:12px 16px;margin:16px 0;max-width:900px"><legend><strong>Academic evidence gate</strong></legend>';
        echo '<label style="display:block;margin-bottom:8px"><input type="checkbox" name="evidence_confirmed" value="1"> I confirm the evidence-sensitive claims in this Preview are supported by the source/reference below.</label>';
        echo '<label for="erm-object-evidence-reference"><strong>Evidence source / reference</strong></label><br>';
        echo '<input id="erm-object-evidence-reference" class="regular-text" style="width:100%;max-width:760px" type="text" name="evidence_reference" value="" placeholder="DOI, repository URL, controlled document, dataset, CV source or verification note">';
        echo '<p class="description">Create operations and evidence-sensitive metadata changes remain blocked until the underlying mutation plan has explicit evidence confirmation and a non-empty reference.</p>';
        echo '</fieldset>';
    }

    private function read_fields(array $source, string $kind, string $mode): array|WP_Error {
        $data = array(
            'title'=>$this->scalar($source, 'title'),
            'excerpt'=>$this->scalar($source, 'excerpt'),
            'content'=>$this->scalar($source, 'content'),
            'language'=>sanitize_key($this->scalar($source, 'language', 'en')),
        );
        if ('create' === $mode) {
            $data['slug'] = sanitize_title($this->scalar($source, 'slug'));
            $data['status'] = sanitize_key($this->scalar($source, 'status', 'draft'));
        } elseif ('update' !== $mode) {
            return new WP_Error('research_manager_object_admin_mode_invalid', 'Unknown Research Object editor mode.');
        }

        if ('output' === $kind) {
            $data['output_type'] = sanitize_key($this->scalar($source, 'output_type'));
            $data['review_status'] = sanitize_key($this->scalar($source, 'review_status'));
            $data['publication_date'] = $this->scalar($source, 'publication_date');
            $data['venue'] = $this->scalar($source, 'venue');
            $data['doi'] = $this->scalar($source, 'doi');
            $authors_json = trim($this->scalar($source, 'authors_json', '[]'));
            $authors = json_decode('' === $authors_json ? '[]' : $authors_json, true);
            if (! is_array($authors) || JSON_ERROR_NONE !== json_last_error()) {
                return new WP_Error('research_manager_object_admin_authors_invalid', 'Authors must be valid JSON containing an array.');
            }
            $data['authors'] = $authors;
        } elseif ('project' === $kind) {
            $data['project_status'] = sanitize_key($this->scalar($source, 'project_status'));
            $data['question'] = $this->scalar($source, 'question');
            $data['role'] = $this->scalar($source, 'role');
            $data['start_date'] = $this->scalar($source, 'start_date');
            $data['end_date'] = $this->scalar($source, 'end_date');
            $data['partner'] = $this->scalar($source, 'partner');
            $data['funding'] = $this->scalar($source, 'funding');
            $data['project_url'] = $this->scalar($source, 'project_url');
            $data['methods'] = $this->lines($this->scalar($source, 'methods'));
        } elseif ('software' === $kind) {
            $data['software_status'] = sanitize_key($this->scalar($source, 'software_status'));
            $data['version'] = $this->scalar($source, 'version');
            $data['release_date'] = $this->scalar($source, 'release_date');
            $data['repository_url'] = $this->scalar($source, 'repository_url');
            $data['archive_url'] = $this->scalar($source, 'archive_url');
            $data['license'] = $this->scalar($source, 'license');
            $data['documentation_url'] = $this->scalar($source, 'documentation_url');
            $data['doi'] = $this->scalar($source, 'doi');
            $data['programming_languages'] = $this->lines($this->scalar($source, 'programming_languages'));
        } elseif ('dataset' === $kind) {
            $data['version'] = $this->scalar($source, 'version');
            $data['publication_date'] = $this->scalar($source, 'publication_date');
            $data['repository'] = $this->scalar($source, 'repository');
            $data['doi'] = $this->scalar($source, 'doi');
            $data['license'] = $this->scalar($source, 'license');
            $data['access_level'] = sanitize_key($this->scalar($source, 'access_level'));
            $data['methodology'] = $this->scalar($source, 'methodology');
            $data['provenance'] = $this->scalar($source, 'provenance');
            $data['size'] = $this->scalar($source, 'size');
            $data['documentation_url'] = $this->scalar($source, 'documentation_url');
            $data['ethics_notes'] = $this->scalar($source, 'ethics_notes');
            $data['formats'] = $this->lines($this->scalar($source, 'formats'));
        } else {
            return new WP_Error('research_manager_unknown_object_kind', 'Unsupported Research Object type.');
        }

        $line_ids = $source['line_ids'] ?? array();
        if (! is_array($line_ids)) { $line_ids = array($line_ids); }
        $data['line_ids'] = array_values(array_unique(array_filter(array_map('absint', $line_ids))));
        return $data;
    }

    private function defaults(string $kind, string $language): array {
        $base = array('title'=>'','slug'=>'','language'=>$language,'status'=>'draft','excerpt'=>'','content'=>'','line_ids'=>array());
        if ('output' === $kind) { return array_merge($base, array('output_type'=>'','review_status'=>'','publication_date'=>'','venue'=>'','doi'=>'','authors'=>array())); }
        if ('project' === $kind) { return array_merge($base, array('project_status'=>'','question'=>'','role'=>'','start_date'=>'','end_date'=>'','partner'=>'','funding'=>'','project_url'=>'','methods'=>array())); }
        if ('software' === $kind) { return array_merge($base, array('software_status'=>'','version'=>'','release_date'=>'','repository_url'=>'','archive_url'=>'','license'=>'','documentation_url'=>'','doi'=>'','programming_languages'=>array())); }
        return array_merge($base, array('version'=>'','publication_date'=>'','repository'=>'','doi'=>'','license'=>'','access_level'=>'','methodology'=>'','provenance'=>'','size'=>'','documentation_url'=>'','ethics_notes'=>'','formats'=>array()));
    }

    private function kinds(): array {
        return array(
            'output'=>array('singular'=>'Research Output','plural'=>'Research Outputs','surface'=>'publications'),
            'project'=>array('singular'=>'Research Project','plural'=>'Research Projects','surface'=>'projects'),
            'software'=>array('singular'=>'Research Software','plural'=>'Research Software','surface'=>'software'),
            'dataset'=>array('singular'=>'Research Dataset','plural'=>'Research Datasets','surface'=>'datasets'),
        );
    }

    private function structured_status(string $kind, array $row): string {
        $value = match ($kind) {
            'output' => trim((string) ($row['review_status'] ?? $row['output_type'] ?? '')),
            'project' => trim((string) ($row['project_status'] ?? '')),
            'software' => trim((string) ($row['software_status'] ?? '')),
            'dataset' => trim((string) ($row['access_level'] ?? '')),
            default => '',
        };
        return '' !== $value ? strtoupper(str_replace('_', ' ', $value)) : '—';
    }

    private function selected_kind(array $kinds): string {
        $kind = isset($_GET['erm_object_kind']) ? sanitize_key((string) wp_unslash($_GET['erm_object_kind'])) : 'output';
        return isset($kinds[$kind]) ? $kind : 'output';
    }

    private function selected_language(array $languages): string {
        $language = isset($_GET['erm_object_language']) ? sanitize_key((string) wp_unslash($_GET['erm_object_language'])) : (string) ($languages[0] ?? 'en');
        return in_array($language, $languages, true) ? $language : (string) ($languages[0] ?? 'en');
    }

    private function text_row(string $label, string $name, string $value, bool $required = false, string $description = ''): void {
        echo '<tr><th scope="row"><label for="erm-object-' . esc_attr($name) . '">' . esc_html($label) . '</label></th><td><input id="erm-object-' . esc_attr($name) . '" class="regular-text" style="width:100%;max-width:760px" type="text" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '"' . ($required ? ' required' : '') . '>';
        if ('' !== $description) { echo '<p class="description">' . esc_html($description) . '</p>'; }
        echo '</td></tr>';
    }

    private function textarea_row(string $label, string $name, string $value, int $rows = 4, string $description = ''): void {
        echo '<tr><th scope="row"><label for="erm-object-' . esc_attr($name) . '">' . esc_html($label) . '</label></th><td><textarea id="erm-object-' . esc_attr($name) . '" name="' . esc_attr($name) . '" rows="' . esc_attr((string) $rows) . '" style="width:100%;max-width:900px">' . esc_textarea($value) . '</textarea>';
        if ('' !== $description) { echo '<p class="description">' . esc_html($description) . '</p>'; }
        echo '</td></tr>';
    }

    private function select_row(string $label, string $name, string $value, array $options, bool $allow_empty = false): void {
        echo '<tr><th scope="row"><label for="erm-object-' . esc_attr($name) . '">' . esc_html($label) . '</label></th><td><select id="erm-object-' . esc_attr($name) . '" name="' . esc_attr($name) . '">';
        if ($allow_empty) { echo '<option value="">—</option>'; }
        foreach ($options as $option => $option_label) {
            echo '<option value="' . esc_attr((string) $option) . '" ' . selected($value, (string) $option, false) . '>' . esc_html((string) $option_label) . '</option>';
        }
        echo '</select></td></tr>';
    }

    private function scalar(array $source, string $key, string $default = ''): string {
        $value = $source[$key] ?? $default;
        if (is_array($value) || is_object($value)) { return $default; }
        return (string) wp_unslash((string) $value);
    }

    private function lines(string $value): array {
        $parts = preg_split('/\r\n|\r|\n/', $value) ?: array();
        return array_values(array_filter(array_map('trim', $parts), static fn(string $item): bool => '' !== $item));
    }

    private function string_list(mixed $value): array {
        if (! is_array($value)) { return array(); }
        return array_values(array_filter(array_map(static fn($item): string => is_scalar($item) ? (string) $item : '', $value), static fn(string $item): bool => '' !== trim($item)));
    }

    private function require_admin(string $nonce_action): void {
        if (! current_user_can('manage_options')) { wp_die('Forbidden', 403); }
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
        return add_query_arg(array_merge(array('page'=>'eduardo-research-objects'), $args), admin_url('tools.php'));
    }
}
