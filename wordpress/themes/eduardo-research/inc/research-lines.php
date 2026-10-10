<?php
/** First-class Research Line contract. */
declare(strict_types=1);
if (! defined('ABSPATH')) { exit; }

function eduardo_research_line_statuses(?string $language = null): array {
    $language = $language ?: (function_exists('eduardo_research_current_language') ? eduardo_research_current_language() : 'en');
    $es = 'es' === $language;
    return array(
        'planned'=>$es ? 'Planificada' : 'Planned',
        'active'=>$es ? 'Activa' : 'Active',
        'paused'=>$es ? 'Pausada' : 'Paused',
        'completed'=>$es ? 'Completada' : 'Completed',
        'archived'=>$es ? 'Archivada' : 'Archived',
    );
}

function eduardo_research_line_evidence_statuses(?string $language = null): array {
    $language = $language ?: (function_exists('eduardo_research_current_language') ? eduardo_research_current_language() : 'en');
    $es = 'es' === $language;
    return array(
        'unverified'=>$es ? 'No verificada' : 'Unverified',
        'verified'=>$es ? 'Verificada' : 'Verified',
    );
}
