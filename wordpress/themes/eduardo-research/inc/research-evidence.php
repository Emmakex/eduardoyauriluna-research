<?php
/** Read-only evidence contract consumed by Theme-owned Research surfaces. */
declare(strict_types=1);
if (! defined('ABSPATH')) { exit; }

function eduardo_research_evidence_store(): array {
    $store = get_option('eduardo_research_evidence', array());
    return is_array($store) ? $store : array();
}

function eduardo_research_evidence_records(string $group, string $status = 'verified'): array {
    $store = eduardo_research_evidence_store();
    $records = $store[$group] ?? array();
    if (! is_array($records)) { return array(); }

    $filtered = array();
    foreach ($records as $record) {
        if (! is_array($record)) { continue; }
        $record_status = sanitize_key((string) ($record['status'] ?? 'unverified'));
        if ($status !== $record_status) { continue; }
        $filtered[] = $record;
    }
    return $filtered;
}

function eduardo_research_verified_evidence(string $group): array {
    return eduardo_research_evidence_records($group, 'verified');
}

function eduardo_research_surface_evidence_groups(string $surface): array {
    $groups = array(
        'about' => array(
            'profile' => 'Profile',
            'affiliations' => 'Affiliations',
        ),
        'research' => array(
            'research_lines' => 'Research lines',
            'methods' => 'Methods',
        ),
        'cv' => array(
            'experience' => 'Experience',
            'education' => 'Education',
            'affiliations' => 'Affiliations',
            'awards' => 'Awards',
        ),
        'contact' => array(
            'contact' => 'Research enquiries',
            'identifiers' => 'Academic profiles',
        ),
    );
    return $groups[$surface] ?? array();
}

function eduardo_research_verified_identifier_urls(): array {
    $urls = array();
    foreach (eduardo_research_verified_evidence('identifiers') as $record) {
        $url = isset($record['url']) && is_scalar($record['url']) ? esc_url_raw((string) $record['url']) : '';
        if ('' !== $url) { $urls[] = $url; }
    }
    return array_values(array_unique($urls));
}
