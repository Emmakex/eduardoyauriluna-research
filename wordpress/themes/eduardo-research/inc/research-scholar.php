<?php
/** Google Scholar / Highwire metadata contract for verified Research Outputs. */
declare(strict_types=1);
if (! defined('ABSPATH')) { exit; }

/**
 * Normalize a stored Research publication date to Scholar's preferred citation format.
 * Returns an empty string for malformed or unavailable dates instead of fabricating one.
 */
function eduardo_research_scholar_publication_date(string $value): string {
    $value = trim($value);
    if (1 === preg_match('/^\d{4}$/', $value)) { return $value; }
    if (1 === preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $value, $match)) {
        return $match[1] . '/' . (string) ((int) $match[2]);
    }
    if (1 === preg_match('/^(\d{4})-(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/', $value, $match)
        && checkdate((int) $match[2], (int) $match[3], (int) $match[1])) {
        return $match[1] . '/' . (string) ((int) $match[2]) . '/' . (string) ((int) $match[3]);
    }
    return '';
}

/**
 * Build a bounded Highwire payload from local curated metadata only.
 * Scholar's required identity core is title + author + publication date/year.
 */
function eduardo_research_scholar_citation_data(int $post_id): array {
    if ($post_id <= 0 || 'research_output' !== get_post_type($post_id) || ! eduardo_research_is_scholar_output($post_id)) {
        return array();
    }

    $title = trim((string) get_the_title($post_id));
    $authors = array_values(array_filter(array_map(
        static fn(array $author): string => trim((string) ($author['display_name'] ?? '')),
        eduardo_research_output_authors($post_id)
    )));
    $publication_date = eduardo_research_scholar_publication_date(eduardo_research_meta_value($post_id, '_research_publication_date'));

    // Do not emit a misleading partial Scholar record. Google Scholar requires all three.
    if ('' === $title || ! $authors || '' === $publication_date) { return array(); }

    $data = array(
        'citation_title'=>array($title),
        'citation_author'=>$authors,
        'citation_publication_date'=>array($publication_date),
    );

    $type = sanitize_key(eduardo_research_meta_value($post_id, '_research_output_type'));
    $venue = eduardo_research_meta_value($post_id, '_research_venue');
    if ('' !== $venue) {
        if ('journal_article' === $type) { $data['citation_journal_title'] = array($venue); }
        elseif ('conference_paper' === $type) { $data['citation_conference_title'] = array($venue); }
    }

    foreach (array(
        '_research_volume'=>'citation_volume',
        '_research_issue'=>'citation_issue',
    ) as $meta_key => $tag) {
        $value = eduardo_research_meta_value($post_id, $meta_key);
        if ('' !== $value) { $data[$tag] = array($value); }
    }

    $doi = eduardo_research_meta_value($post_id, '_research_doi');
    if ('' !== $doi && '1' === eduardo_research_meta_value($post_id, '_research_doi_verified')) {
        $data['citation_doi'] = array($doi);
    }

    $pdf_url = eduardo_research_meta_value($post_id, '_research_pdf_url');
    if ('' !== $pdf_url && wp_http_validate_url($pdf_url)) {
        $data['citation_pdf_url'] = array($pdf_url);
    }

    $pages = eduardo_research_meta_value($post_id, '_research_pages');
    if ('' !== $pages && preg_match('/^\s*([^\-–—]+)\s*[\-–—]\s*([^\-–—]+)\s*$/u', $pages, $match)) {
        $first = trim($match[1]);
        $last = trim($match[2]);
        if ('' !== $first && '' !== $last) {
            $data['citation_firstpage'] = array($first);
            $data['citation_lastpage'] = array($last);
        }
    }

    return $data;
}

function eduardo_research_scholar_citation_meta(): void {
    if (is_admin() || ! is_singular('research_output')) { return; }
    $data = eduardo_research_scholar_citation_data(get_queried_object_id());
    foreach ($data as $tag => $values) {
        foreach ((array) $values as $value) {
            if (! is_scalar($value) || '' === trim((string) $value)) { continue; }
            echo '<meta name="' . esc_attr((string) $tag) . '" content="' . esc_attr((string) $value) . '">' . "\n";
        }
    }
}

// Supersede the earlier generic citation renderer with the stricter Scholar contract.
remove_action('wp_head', 'eduardo_research_citation_meta', 8);
add_action('wp_head', 'eduardo_research_scholar_citation_meta', 8);
