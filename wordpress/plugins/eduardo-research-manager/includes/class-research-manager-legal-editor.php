<?php
/** Bounded editor for Theme-owned Privacy / Legal section content. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Legal_Editor {
    private const SNAPSHOTS_OPTION = 'eduardo_research_manager_legal_snapshots';
    private const SNAPSHOT_LIMIT = 80;
    private const MIN_SECTIONS = 2;
    private const MAX_SECTIONS = 6;
    private const TITLE_MAX = 120;
    private const BODY_MIN = 45;
    private const BODY_MAX = 4000;

    public static function keys(): array {
        return array('privacy-policy', 'legal-notice');
    }

    public static function languages(): array {
        return array_values(array_intersect(array('en', 'es'), Eduardo_Research_Manager::contract()->languages()));
    }

    public function inspect(string $key, string $language): array|WP_Error {
        $target = $this->target($key, $language);
        if (is_wp_error($target)) { return $target; }
        $option = (string) $target['option'];
        $exists = false !== get_option($option, false);
        $value = get_option($option, array());
        $sections = is_array($value) ? $this->normalise_existing($value) : array();
        return array(
            'key'=>(string) $target['key'],
            'language'=>(string) $target['language'],
            'exists'=>$exists,
            'sections'=>$sections,
            'section_count'=>count($sections),
            'revision'=>$this->revision($exists, $sections),
        );
    }

    public function preview_update(string $key, string $language, array $sections, string $reason = ''): array|WP_Error {
        $before = $this->inspect($key, $language);
        if (is_wp_error($before)) { return $before; }
        $expected = $this->normalise_requested($sections);
        if (is_wp_error($expected)) { return $expected; }
        $already_matching = $this->same((array) ($before['sections'] ?? array()), $expected);
        return array(
            'status'=>$already_matching ? 'already-matching' : 'change',
            'key'=>(string) $before['key'],
            'language'=>(string) $before['language'],
            'before'=>(array) ($before['sections'] ?? array()),
            'before_exists'=>! empty($before['exists']),
            'baseline_revision'=>(string) ($before['revision'] ?? ''),
            'expected'=>$expected,
            'reason'=>sanitize_text_field($reason),
            'risk'=>'editorial-review',
            'apply_allowed'=>true,
        );
    }

    public function apply_preview(array $prepared): array|WP_Error {
        if (! current_user_can('manage_options')) {
            return new WP_Error('capability_unavailable', 'Local execution identity cannot update legal content.');
        }
        $key = sanitize_key((string) ($prepared['key'] ?? ''));
        $language = sanitize_key((string) ($prepared['language'] ?? ''));
        $expected = is_array($prepared['expected'] ?? null) ? $prepared['expected'] : array();
        $baseline = sanitize_text_field((string) ($prepared['baseline_revision'] ?? ''));
        if ('' === $key || '' === $language || ! $expected || '' === $baseline || empty($prepared['apply_allowed'])) {
            return new WP_Error('validation_failed', 'Stored legal Preview is incomplete or cannot be applied.');
        }
        $current = $this->inspect($key, $language);
        if (is_wp_error($current)) { return $current; }
        if (! hash_equals($baseline, (string) ($current['revision'] ?? ''))) {
            return new WP_Error('stale_revision', 'Legal content changed after Preview. Create a fresh Preview before Apply.');
        }
        if ('already-matching' === (string) ($prepared['status'] ?? '')) {
            $verification = $this->verify($key, $language, $expected);
            if (is_wp_error($verification)) { return $verification; }
            return array('status'=>'already-matching','verified'=>! empty($verification['verified']),'snapshot_id'=>'','verification'=>$verification);
        }

        $target = $this->target($key, $language);
        if (is_wp_error($target)) { return $target; }
        $snapshot_id = $this->snapshot((string) $target['option'], ! empty($current['exists']), (array) ($current['sections'] ?? array()));
        $this->write((string) $target['option'], $expected);
        $verification = $this->verify($key, $language, $expected);
        if (is_wp_error($verification) || empty($verification['verified'])) {
            $this->rollback($snapshot_id);
            return is_wp_error($verification)
                ? $verification
                : new WP_Error('verification_failed', 'Legal content failed exact post-Apply verification.');
        }
        return array(
            'status'=>'applied',
            'verified'=>true,
            'snapshot_id'=>$snapshot_id,
            'verification'=>$verification,
        );
    }

    public function verify(string $key, string $language, array $expected): array|WP_Error {
        $normalised = $this->normalise_requested($expected);
        if (is_wp_error($normalised)) { return $normalised; }
        $current = $this->inspect($key, $language);
        if (is_wp_error($current)) { return $current; }
        $verified = $this->same((array) ($current['sections'] ?? array()), $normalised);
        return array(
            'verified'=>$verified,
            'key'=>$key,
            'language'=>$language,
            'section_count'=>count((array) ($current['sections'] ?? array())),
            'revision'=>(string) ($current['revision'] ?? ''),
            'verified_at'=>gmdate(DATE_W3C),
        );
    }

    public function verify_rendered(string $key, string $language, array $expected): array|WP_Error {
        $normalised = $this->normalise_requested($expected);
        if (is_wp_error($normalised)) { return $normalised; }
        $page = Eduardo_Research_Manager::page_editor()->inspect($key, $language);
        if (is_wp_error($page)) { return $page; }
        $url = esc_url_raw((string) ($page['url'] ?? ''));
        if ('' === $url) { return new WP_Error('verification_failed', 'Legal Page has no rendered URL.'); }
        $response = wp_remote_get($url, array('timeout'=>20, 'redirection'=>3, 'user-agent'=>'Site-Architect-Legal-Verify/' . EDUARDO_RESEARCH_MANAGER_VERSION));
        if (is_wp_error($response)) { return $response; }
        $status = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        $text = $this->normalise_text(html_entity_decode(wp_strip_all_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $checks = array();
        $verified = 200 === $status;
        foreach ($normalised as $section) {
            $title = $this->normalise_text((string) $section['title']);
            $section_body = $this->normalise_text((string) $section['body']);
            $title_ok = '' !== $title && str_contains($text, $title);
            $body_ok = '' !== $section_body && str_contains($text, $section_body);
            $checks[] = array('title'=>$title,'title_present'=>$title_ok,'body_present'=>$body_ok);
            $verified = $verified && $title_ok && $body_ok;
        }
        return array(
            'verified'=>$verified,
            'url'=>$url,
            'status'=>$status,
            'sections'=>$checks,
            'verified_at'=>gmdate(DATE_W3C),
        );
    }

    public function rollback(string $snapshot_id): array|WP_Error {
        $all = get_option(self::SNAPSHOTS_OPTION, array());
        $all = is_array($all) ? $all : array();
        $snapshot = is_array($all[$snapshot_id] ?? null) ? $all[$snapshot_id] : array();
        if (! $snapshot) { return new WP_Error('rollback_unavailable', 'Legal content snapshot was not found.'); }
        $option = (string) ($snapshot['option'] ?? '');
        if (! $this->is_allowed_option($option)) { return new WP_Error('rollback_unavailable', 'Legal content snapshot targets an unknown option.'); }
        if (! empty($snapshot['existed'])) {
            $this->write($option, is_array($snapshot['before'] ?? null) ? $snapshot['before'] : array());
        } else {
            delete_option($option);
        }
        $exists = false !== get_option($option, false);
        $value = get_option($option, array());
        $sections = is_array($value) ? $this->normalise_existing($value) : array();
        $restored = $exists === ! empty($snapshot['existed']) && $this->same($sections, (array) ($snapshot['before'] ?? array()));
        if (! $restored) { return new WP_Error('rollback_failed', 'Legal content rollback did not restore the exact baseline.'); }
        $all[$snapshot_id]['status'] = 'rolled-back';
        $all[$snapshot_id]['rolled_back_at'] = gmdate(DATE_W3C);
        update_option(self::SNAPSHOTS_OPTION, $all, false);
        return array('verified'=>true,'snapshot_id'=>$snapshot_id,'restored'=>true,'rolled_back_at'=>gmdate(DATE_W3C));
    }

    private function target(string $key, string $language): array|WP_Error {
        $key = sanitize_key($key);
        $language = sanitize_key($language);
        if (! in_array($key, self::keys(), true)) {
            return new WP_Error('validation_failed', 'Legal content key must be privacy-policy or legal-notice.');
        }
        if (! in_array($language, self::languages(), true)) {
            return new WP_Error('validation_failed', 'Legal content language must be an enabled EN/ES language.');
        }
        $option = 'eduardo_research_legal_' . $key . ('es' === $language ? '_es' : '');
        return array('key'=>$key,'language'=>$language,'option'=>$option);
    }

    private function normalise_requested(array $sections): array|WP_Error {
        if (count($sections) < self::MIN_SECTIONS || count($sections) > self::MAX_SECTIONS) {
            return new WP_Error('validation_failed', 'Legal content must contain between 2 and 6 sections.');
        }
        $normalised = array();
        foreach ($sections as $section) {
            if (! is_array($section) || array_diff(array_keys($section), array('title','body')) || ! array_key_exists('title', $section) || ! array_key_exists('body', $section)) {
                return new WP_Error('validation_failed', 'Each legal section must contain only title and body.');
            }
            $title_raw = is_scalar($section['title']) ? (string) $section['title'] : '';
            $body_raw = is_scalar($section['body']) ? (string) $section['body'] : '';
            if (str_contains($title_raw, '<') || str_contains($title_raw, '>') || str_contains($body_raw, '<') || str_contains($body_raw, '>')) {
                return new WP_Error('validation_failed', 'Legal content accepts plain text only.');
            }
            $title = $this->normalise_text(sanitize_text_field($title_raw));
            $body = $this->normalise_text(sanitize_text_field($body_raw));
            if ('' === $title || mb_strlen($title) > self::TITLE_MAX) {
                return new WP_Error('validation_failed', 'Legal section titles must be non-empty and at most 120 characters.');
            }
            $body_length = mb_strlen($body);
            if ($body_length < self::BODY_MIN || $body_length > self::BODY_MAX) {
                return new WP_Error('validation_failed', 'Legal section bodies must contain between 45 and 4000 characters.');
            }
            $normalised[] = array('title'=>$title,'body'=>$body);
        }
        return $normalised;
    }

    private function normalise_existing(array $sections): array {
        $normalised = array();
        foreach ($sections as $section) {
            if (! is_array($section)) { continue; }
            $normalised[] = array(
                'title'=>$this->normalise_text((string) ($section['title'] ?? '')),
                'body'=>$this->normalise_text((string) ($section['body'] ?? '')),
            );
        }
        return $normalised;
    }

    private function normalise_text(string $value): string {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    private function same(array $left, array $right): bool {
        return wp_json_encode(array_values($left)) === wp_json_encode(array_values($right));
    }

    private function revision(bool $exists, array $sections): string {
        return hash('sha256', (string) wp_json_encode(array('exists'=>$exists,'sections'=>array_values($sections))));
    }

    private function write(string $option, array $sections): void {
        if (false === get_option($option, false)) { add_option($option, array_values($sections), '', false); return; }
        update_option($option, array_values($sections), false);
    }

    private function snapshot(string $option, bool $existed, array $before): string {
        $all = get_option(self::SNAPSHOTS_OPTION, array());
        $all = is_array($all) ? $all : array();
        $id = 'erm-legal-snapshot-' . str_replace('-', '', wp_generate_uuid4());
        $all[$id] = array('id'=>$id,'option'=>$option,'existed'=>$existed,'before'=>array_values($before),'status'=>'applied','created_at'=>gmdate(DATE_W3C));
        while (count($all) > self::SNAPSHOT_LIMIT) { array_shift($all); }
        if (false === get_option(self::SNAPSHOTS_OPTION, false)) { add_option(self::SNAPSHOTS_OPTION, $all, '', false); }
        else { update_option(self::SNAPSHOTS_OPTION, $all, false); }
        return $id;
    }

    private function is_allowed_option(string $option): bool {
        foreach (self::keys() as $key) {
            foreach (self::languages() as $language) {
                $target = $this->target($key, $language);
                if (! is_wp_error($target) && hash_equals((string) $target['option'], $option)) { return true; }
            }
        }
        return false;
    }
}
