<?php
/** Historical Research identity source and Home legacy aliases for Blueprint v1. */
declare(strict_types=1);
if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager_Research_Blueprint_Identity implements Site_Architect_Blueprint_Identity_Resolver {
    public function theme_id(): string { return 'research'; }

    public function filter_page_slots(string $page_key, array $values): array {
        if ('home' === $page_key) {
            unset($values['researcher-name'], $values['researcher-headline']);
        }
        return $values;
    }

    /**
     * Seed canonical Identity once from instance data in the active blueprint.
     * Canonical site.identity is authoritative when present. Legacy Home
     * identity aliases remain only a v1 fallback when that block is absent.
     */
    public function seed(array $normalized): array|WP_Error {
        $stored = get_option(Eduardo_Research_Manager_Identity_Profile::option_key(), array());
        if (is_array($stored) && $stored) { return array(); }

        $seed = array();
        $site = is_array($normalized['site'] ?? null) ? $normalized['site'] : array();
        $has_canonical_identity = array_key_exists('identity', $site) && is_array($site['identity']);
        $identity = $has_canonical_identity ? $site['identity'] : array();
        $name = is_scalar($identity['name'] ?? null)
            ? trim(sanitize_text_field((string) $identity['name']))
            : '';
        if ('' !== $name) { $seed['name'] = $name; }

        $contract = Eduardo_Research_Manager::contract();
        $headlines = is_array($identity['headlines'] ?? null) ? $identity['headlines'] : array();
        foreach ((array) ($normalized['languages'] ?? array()) as $language) {
            $language = sanitize_key((string) $language);
            $headline = is_scalar($headlines[$language] ?? null)
                ? trim(sanitize_text_field((string) $headlines[$language]))
                : '';
            if ('' === $headline) { continue; }
            $field = $contract->identity_headline_field($language);
            if (is_wp_error($field)) { return $field; }
            $seed[$field] = $headline;
        }

        if (! $has_canonical_identity) {
            $legacy_names = array();
            foreach ((array) ($normalized['pages'] ?? array()) as $record) {
                if (! is_array($record) || 'home' !== sanitize_key((string) ($record['key'] ?? ''))) { continue; }
                $slots = is_array($record['slots'] ?? null) ? $record['slots'] : array();
                foreach ($contract->languages() as $language) {
                    $language = sanitize_key((string) $language);
                    $values = is_array($slots[$language] ?? null) ? $slots[$language] : array();
                    $legacy_name = trim(sanitize_text_field((string) ($values['researcher-name'] ?? '')));
                    if ('' !== $legacy_name) { $legacy_names[$language] = $legacy_name; }

                    $legacy_headline = trim(sanitize_text_field((string) ($values['researcher-headline'] ?? '')));
                    if ('' === $legacy_headline) { continue; }
                    $field = $contract->identity_headline_field($language);
                    if (is_wp_error($field)) { return $field; }
                    $seed[$field] = $legacy_headline;
                }
                break;
            }

            $unique_names = array_values(array_unique(array_values($legacy_names)));
            if (count($unique_names) > 1) {
                return new WP_Error('research_manager_blueprint_identity_mismatch', 'Greenfield blueprint researcher name differs between EN and ES.');
            }
            if ($unique_names) { $seed['name'] = (string) $unique_names[0]; }
        }
        return $seed;
    }

    public function has_seed_data(array $normalized): bool {
        $site = is_array($normalized['site'] ?? null) ? $normalized['site'] : array();
        $has_canonical_identity = array_key_exists('identity', $site) && is_array($site['identity']);
        $identity = $has_canonical_identity ? $site['identity'] : array();
        $name = is_scalar($identity['name'] ?? null) ? trim((string) $identity['name']) : '';
        if ('' !== $name) { return true; }
        $headlines = is_array($identity['headlines'] ?? null) ? $identity['headlines'] : array();
        foreach ((array) ($normalized['languages'] ?? array()) as $language) {
            $language = sanitize_key((string) $language);
            if (is_scalar($headlines[$language] ?? null) && '' !== trim((string) $headlines[$language])) { return true; }
        }
        if ($has_canonical_identity) { return false; }

        foreach ((array) ($normalized['pages'] ?? array()) as $record) {
            if (! is_array($record) || 'home' !== sanitize_key((string) ($record['key'] ?? ''))) { continue; }
            foreach ((array) ($record['slots'] ?? array()) as $values) {
                if (is_array($values) && (array_key_exists('researcher-name', $values) || array_key_exists('researcher-headline', $values))) { return true; }
            }
        }
        return false;
    }

}
