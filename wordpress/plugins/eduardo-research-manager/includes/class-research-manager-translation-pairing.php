<?php
/** Bilateral EN/ES pairing for existing Research records. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Translation_Pairing {
    private const SUPPORTED_TYPES = array('research_output','research_project','research_software','research_dataset','post');
    private const STRUCTURED_TYPES = array('research_output','research_project','research_software','research_dataset');
    private const EVIDENCE_META = '_eduardo_research_translation_evidence';

    public function inspect(int $post_id): array|WP_Error {
        $record = $this->record($post_id, false);
        if (is_wp_error($record)) { return $record; }
        $other_language = 'en' === $record['language'] ? 'es' : 'en';
        $target_id = (int) get_post_meta($post_id, '_research_translation_' . $other_language, true);
        $target = $target_id > 0 ? get_post($target_id) : null;
        return array(
            'post_id'=>$post_id,
            'post_type'=>$record['post_type'],
            'status'=>$record['status'],
            'language'=>$record['language'],
            'url'=>(string) get_permalink($post_id),
            'counterpart_language'=>$other_language,
            'counterpart_id'=>$target_id,
            'counterpart_exists'=>$target instanceof WP_Post,
            'counterpart_type'=>$target instanceof WP_Post ? (string) $target->post_type : '',
            'counterpart_status'=>$target instanceof WP_Post ? (string) $target->post_status : '',
            'counterpart_url'=>$target instanceof WP_Post ? (string) get_permalink($target_id) : '',
            'evidence_reference'=>in_array($record['post_type'], self::STRUCTURED_TYPES, true)
                ? (string) get_post_meta($post_id, self::EVIDENCE_META, true) : '',
        );
    }

    public function build_pair_plan(int $first_id, int $second_id, string $intent = '', array $context = array()): array|WP_Error {
        if ($first_id === $second_id) { return new WP_Error('research_manager_translation_same_record', 'A record cannot be paired with itself.'); }
        $first = $this->record($first_id, true);
        if (is_wp_error($first)) { return $first; }
        $second = $this->record($second_id, true);
        if (is_wp_error($second)) { return $second; }
        if ($first['post_type'] !== $second['post_type']) {
            return new WP_Error('research_manager_translation_type_mismatch', 'Translation pairs must use the same supported WordPress resource type.');
        }
        if ($first['language'] === $second['language']) {
            return new WP_Error('research_manager_translation_language_mismatch', 'Translation pairs must contain one English and one Spanish record.');
        }

        $en_id = 'en' === $first['language'] ? $first_id : $second_id;
        $es_id = 'es' === $first['language'] ? $first_id : $second_id;
        $slot = $this->assert_pair_slot_available($en_id, 'es', $es_id);
        if (is_wp_error($slot)) { return $slot; }
        $slot = $this->assert_pair_slot_available($es_id, 'en', $en_id);
        if (is_wp_error($slot)) { return $slot; }

        $actions = array();
        if ((int) get_post_meta($en_id, '_research_translation_es', true) !== $es_id) {
            $actions[] = array('type'=>'post_meta','post_id'=>$en_id,'key'=>'_research_translation_es','value'=>(string) $es_id);
        }
        if ((int) get_post_meta($es_id, '_research_translation_en', true) !== $en_id) {
            $actions[] = array('type'=>'post_meta','post_id'=>$es_id,'key'=>'_research_translation_en','value'=>(string) $en_id);
        }

        $post_type = (string) $first['post_type'];
        if (in_array($post_type, self::STRUCTURED_TYPES, true)) {
            $reference = sanitize_text_field((string) ($context['evidence_reference'] ?? ''));
            $actions[] = array('type'=>'post_meta','post_id'=>$en_id,'key'=>self::EVIDENCE_META,'value'=>$reference);
            $actions[] = array('type'=>'post_meta','post_id'=>$es_id,'key'=>self::EVIDENCE_META,'value'=>$reference);
        } else {
            // Risk anchor: keeps Insight pairing editorial-review without changing its bounded narrative.
            $post = get_post($en_id);
            $actions[] = array('type'=>'post_field','post_id'=>$en_id,'field'=>'post_excerpt','value'=>$post instanceof WP_Post ? (string) $post->post_excerpt : '');
        }

        if (! $actions) { return new WP_Error('research_manager_translation_no_change', 'These records are already paired.'); }
        $intent = '' !== trim($intent) ? $intent : sprintf('Pair EN/ES translations for %s records %d and %d', $post_type, $en_id, $es_id);
        return Eduardo_Research_Manager_Plan::create($intent, $actions, $context);
    }

    public function build_unpair_plan(int $post_id, string $intent = '', array $context = array()): array|WP_Error {
        $record = $this->record($post_id, false);
        if (is_wp_error($record)) { return $record; }
        $other_language = 'en' === $record['language'] ? 'es' : 'en';
        $key = '_research_translation_' . $other_language;
        $counterpart_id = (int) get_post_meta($post_id, $key, true);
        if ($counterpart_id <= 0) { return new WP_Error('research_manager_translation_not_paired', 'This record does not currently reference a translation counterpart.'); }

        $actions = array(array('type'=>'post_meta','post_id'=>$post_id,'key'=>$key,'value'=>'0'));
        $counterpart = get_post($counterpart_id);
        if ($counterpart instanceof WP_Post && $counterpart->post_type === $record['post_type']) {
            $counterpart_language = function_exists('eduardo_research_post_language')
                ? eduardo_research_post_language($counterpart_id)
                : sanitize_key((string) get_post_meta($counterpart_id, '_research_language', true));
            if (in_array($counterpart_language, array('en','es'), true) && $counterpart_language !== $record['language']) {
                $reverse_key = '_research_translation_' . $record['language'];
                if ((int) get_post_meta($counterpart_id, $reverse_key, true) === $post_id) {
                    $actions[] = array('type'=>'post_meta','post_id'=>$counterpart_id,'key'=>$reverse_key,'value'=>'0');
                }
            }
        }

        if (in_array($record['post_type'], self::STRUCTURED_TYPES, true)) {
            $reference = sanitize_text_field((string) ($context['evidence_reference'] ?? ''));
            $actions[] = array('type'=>'post_meta','post_id'=>$post_id,'key'=>self::EVIDENCE_META,'value'=>$reference);
            if ($counterpart instanceof WP_Post && $counterpart->post_type === $record['post_type']) {
                $actions[] = array('type'=>'post_meta','post_id'=>$counterpart_id,'key'=>self::EVIDENCE_META,'value'=>$reference);
            }
        } else {
            $post = get_post($post_id);
            $actions[] = array('type'=>'post_field','post_id'=>$post_id,'field'=>'post_excerpt','value'=>$post instanceof WP_Post ? (string) $post->post_excerpt : '');
        }

        $intent = '' !== trim($intent) ? $intent : sprintf('Remove EN/ES translation pairing for %s record %d', $record['post_type'], $post_id);
        return Eduardo_Research_Manager_Plan::create($intent, $actions, $context);
    }

    public function verify_pair(int $first_id, int $second_id): array|WP_Error {
        $first = $this->record($first_id, true);
        if (is_wp_error($first)) { return $first; }
        $second = $this->record($second_id, true);
        if (is_wp_error($second)) { return $second; }
        if ($first['post_type'] !== $second['post_type'] || $first['language'] === $second['language']) {
            return new WP_Error('research_manager_translation_pair_invalid', 'The records no longer satisfy the translation-pair contract.');
        }
        $en_id = 'en' === $first['language'] ? $first_id : $second_id;
        $es_id = 'es' === $first['language'] ? $first_id : $second_id;
        $checks = array(
            'en_points_es'=>(int) get_post_meta($en_id, '_research_translation_es', true) === $es_id,
            'es_points_en'=>(int) get_post_meta($es_id, '_research_translation_en', true) === $en_id,
            'theme_en_to_es'=>function_exists('eduardo_research_translation_post_id') ? eduardo_research_translation_post_id($en_id, 'es') === $es_id : false,
            'theme_es_to_en'=>function_exists('eduardo_research_translation_post_id') ? eduardo_research_translation_post_id($es_id, 'en') === $en_id : false,
            'en_url'=>'' !== (string) get_permalink($en_id),
            'es_url'=>'' !== (string) get_permalink($es_id),
        );
        return array(
            'verified'=>! in_array(false, $checks, true),
            'post_type'=>$first['post_type'],
            'en_id'=>$en_id,
            'es_id'=>$es_id,
            'en_url'=>(string) get_permalink($en_id),
            'es_url'=>(string) get_permalink($es_id),
            'checks'=>$checks,
            'verified_at'=>gmdate(DATE_W3C),
        );
    }

    public function verify_unpaired(int $post_id): array|WP_Error {
        $record = $this->record($post_id, false);
        if (is_wp_error($record)) { return $record; }
        $other_language = 'en' === $record['language'] ? 'es' : 'en';
        $target = (int) get_post_meta($post_id, '_research_translation_' . $other_language, true);
        return array(
            'verified'=>$target <= 0,
            'post_id'=>$post_id,
            'language'=>$record['language'],
            'counterpart_language'=>$other_language,
            'stored_counterpart_id'=>$target,
            'verified_at'=>gmdate(DATE_W3C),
        );
    }

    private function record(int $post_id, bool $require_publish): array|WP_Error {
        $post = get_post($post_id);
        if (! $post instanceof WP_Post || ! in_array((string) $post->post_type, self::SUPPORTED_TYPES, true)) {
            return new WP_Error('research_manager_translation_resource_unsupported', 'Translation pairing supports Insights, Publications, Projects, Software and Datasets only.');
        }
        if ($require_publish && 'publish' !== (string) $post->post_status) {
            return new WP_Error('research_manager_translation_not_public', 'Both translation records must be published before they can be paired.');
        }
        $language = function_exists('eduardo_research_post_language')
            ? eduardo_research_post_language($post_id)
            : sanitize_key((string) get_post_meta($post_id, '_research_language', true));
        if (! in_array($language, array('en','es'), true)) {
            return new WP_Error('research_manager_translation_language_invalid', 'Translation records must use the Research EN/ES language contract.');
        }
        return array('post_id'=>$post_id,'post_type'=>(string) $post->post_type,'status'=>(string) $post->post_status,'language'=>$language);
    }

    private function assert_pair_slot_available(int $post_id, string $target_language, int $expected_id): bool|WP_Error {
        $stored = (int) get_post_meta($post_id, '_research_translation_' . $target_language, true);
        if ($stored > 0 && $stored !== $expected_id) {
            return new WP_Error('research_manager_translation_collision', 'One of the records is already paired with a different translation. Nothing was overwritten.');
        }
        return true;
    }
}
