<?php
/** Structured Theme-owned layouts for the Research preset interior pages. */
declare(strict_types=1);
if (! defined('ABSPATH')) { exit; }

function eduardo_research_surface_layout(string $key, ?string $language = null): array {
    $language = $language ?: eduardo_research_current_language();
    $es = 'es' === $language;

    $layouts = array(
        'about' => array(
            'sections' => array(
                array('group'=>'profile','label'=>$es ? 'Perfil de investigación' : 'Research profile','intro'=>$es ? 'Enfoque de investigación, trayectoria y contexto profesional relevante.' : 'Research focus, background and relevant professional context.'),
                array('group'=>'affiliations','label'=>$es ? 'Afiliaciones' : 'Affiliations','intro'=>$es ? 'Relaciones académicas o profesionales vinculadas al perfil investigador.' : 'Academic or professional relationships connected to the research profile.'),
                array('group'=>'identifiers','label'=>$es ? 'Perfiles académicos' : 'Academic profiles','intro'=>$es ? 'Identificadores y perfiles académicos públicos de Eduardo Jose Yauri Luna.' : 'Public academic identifiers and profiles for Eduardo Jose Yauri Luna.'),
            ),
            'links' => array('research','cv','contact'),
        ),
        'research' => array(
            'sections' => array(
                array('group'=>'research_lines','label'=>$es ? 'Líneas de investigación' : 'Research lines','intro'=>$es ? 'Líneas que estructuran la agenda de investigación.' : 'Research lines that structure the research agenda.'),
                array('group'=>'research_questions','label'=>$es ? 'Preguntas de investigación' : 'Research questions','intro'=>$es ? 'Preguntas que orientan el desarrollo de la agenda de investigación.' : 'Questions guiding the development of the research agenda.'),
                array('group'=>'methods','label'=>$es ? 'Métodos' : 'Methods','intro'=>$es ? 'Métodos y enfoques planteados para desarrollar y evaluar el trabajo de investigación.' : 'Methods and approaches proposed to develop and evaluate the research work.'),
            ),
            'links' => array('publications','projects','software','datasets'),
        ),
        'cv' => array(
            'sections' => array(
                array('group'=>'experience','label'=>$es ? 'Experiencia' : 'Experience','intro'=>$es ? 'Trayectoria profesional relevante para la agenda de investigación.' : 'Professional background relevant to the research agenda.'),
                array('group'=>'education','label'=>$es ? 'Formación' : 'Education','intro'=>$es ? 'Formación académica y credenciales.' : 'Academic education and credentials.'),
                array('group'=>'affiliations','label'=>$es ? 'Afiliaciones' : 'Affiliations','intro'=>$es ? 'Relaciones institucionales vinculadas al perfil investigador.' : 'Institutional relationships connected to the research profile.'),
                array('group'=>'awards','label'=>$es ? 'Reconocimientos' : 'Awards','intro'=>$es ? 'Premios y reconocimientos académicos o profesionales.' : 'Academic or professional awards and distinctions.'),
            ),
            'links' => array('publications','projects','contact'),
        ),
        'contact' => array(
            'sections' => array(
                array('group'=>'contact','label'=>$es ? 'Contacto' : 'Contact','intro'=>$es ? 'Canales de contacto para conversaciones académicas y colaboración.' : 'Contact channels for academic conversations and collaboration.'),
                array('group'=>'identifiers','label'=>$es ? 'Perfiles académicos' : 'Academic profiles','intro'=>$es ? 'Perfiles académicos públicos de Eduardo Jose Yauri Luna.' : 'Public academic profiles for Eduardo Jose Yauri Luna.'),
            ),
            'links' => array('research','publications'),
        ),
    );

    return $layouts[$key] ?? array('sections'=>array(),'links'=>array());
}

function eduardo_research_surface_record_title(array $record, string $fallback): string {
    foreach (array('title','label','value') as $field) {
        if (isset($record[$field]) && is_scalar($record[$field]) && '' !== trim((string) $record[$field])) {
            return trim((string) $record[$field]);
        }
    }
    return $fallback;
}

function eduardo_research_surface_records(string $group): array {
    $records = eduardo_research_verified_localized_evidence($group);
    if ('identifiers' !== $group) { return $records; }

    return array_values(array_filter($records, static function ($record): bool {
        if (! is_array($record) || empty($record['url']) || ! is_scalar($record['url'])) { return false; }
        $host = strtolower((string) wp_parse_url((string) $record['url'], PHP_URL_HOST));
        $host = preg_replace('/^www\./', '', $host);
        return 'orcid.org' === $host
            || 'openalex.org' === $host
            || 0 === strpos($host, 'scholar.google.');
    }));
}

function eduardo_research_surface_record_link_label(string $group): string {
    $es = 'es' === eduardo_research_current_language();
    $labels = array(
        'identifiers' => $es ? 'Abrir perfil académico' : 'Open academic profile',
        'affiliations' => $es ? 'Visitar sitio' : 'Visit website',
        'profile' => $es ? 'Ver perfil' : 'View profile',
        'contact' => $es ? 'Contactar' : 'Contact',
    );
    return $labels[$group] ?? ($es ? 'Ver registro' : 'View record');
}

function eduardo_research_render_evidence_section(array $section, int $position): void {
    $group = sanitize_key((string) ($section['group'] ?? ''));
    $label = (string) ($section['label'] ?? $group);
    $intro = (string) ($section['intro'] ?? '');
    $records = eduardo_research_surface_records($group);
    if (! $records) { return; }
    ?>
    <section class="research-interior-block" data-evidence-group="<?php echo esc_attr($group); ?>">
      <header class="research-interior-block-heading">
        <span class="research-section-number"><?php echo esc_html(str_pad((string) $position, 2, '0', STR_PAD_LEFT)); ?></span>
        <div>
          <div class="research-eyebrow"><?php echo esc_html(eduardo_research_t('verified_evidence')); ?></div>
          <h2><?php echo esc_html($label); ?></h2>
          <?php if ('' !== $intro) : ?><p><?php echo esc_html($intro); ?></p><?php endif; ?>
        </div>
      </header>
      <div class="research-interior-records">
        <?php foreach ($records as $record) :
            $title = eduardo_research_surface_record_title($record, $label);
            $summary = isset($record['summary']) && is_scalar($record['summary']) ? trim((string) $record['summary']) : '';
            $value = isset($record['value']) && is_scalar($record['value']) ? trim((string) $record['value']) : '';
            $url = isset($record['url']) && is_scalar($record['url']) ? esc_url((string) $record['url']) : '';
        ?>
          <article class="research-interior-record">
            <div class="research-interior-record-mark" aria-hidden="true"></div>
            <div>
              <h3><?php echo esc_html($title); ?></h3>
              <?php if ('' !== $summary) : ?><p><?php echo esc_html($summary); ?></p><?php elseif ('' !== $value && $value !== $title) : ?><p><?php echo esc_html($value); ?></p><?php endif; ?>
              <?php if ('' !== $url) : ?><a class="research-text-link" href="<?php echo $url; ?>" rel="noopener"><?php echo esc_html(eduardo_research_surface_record_link_label($group)); ?> <span aria-hidden="true">↗</span></a><?php endif; ?>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    </section>
    <?php
}

function eduardo_research_render_surface_links(array $keys): void {
    if (function_exists('eduardo_research_navigation_target_is_visible')) {
        $keys = array_values(array_filter($keys, 'eduardo_research_navigation_target_is_visible'));
    }
    if (! $keys) { return; }
    $language = eduardo_research_current_language();
    ?>
    <section class="research-interior-links" aria-label="<?php echo esc_attr('es' === $language ? 'Explorar el perfil de investigación' : 'Explore the research profile'); ?>">
      <div class="research-eyebrow"><?php echo esc_html('es' === $language ? 'Continuar explorando' : 'Continue exploring'); ?></div>
      <div class="research-interior-link-grid">
        <?php foreach ($keys as $index => $key) : ?>
          <a class="research-interior-link-card" href="<?php echo esc_url(eduardo_research_page_url((string) $key)); ?>">
            <span><?php echo esc_html(str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT)); ?></span>
            <strong><?php echo esc_html(eduardo_research_page_label((string) $key)); ?></strong>
            <span aria-hidden="true">↗</span>
          </a>
        <?php endforeach; ?>
      </div>
    </section>
    <?php
}

function eduardo_research_render_structured_surface(string $key): void {
    $layout = eduardo_research_surface_layout($key);
    $sections = is_array($layout['sections'] ?? null) ? $layout['sections'] : array();
    $position = 0;
    foreach ($sections as $section) {
        if (! is_array($section)) { continue; }
        $group = sanitize_key((string) ($section['group'] ?? ''));
        if (! eduardo_research_surface_records($group)) { continue; }
        $position++;
        eduardo_research_render_evidence_section($section, $position);
    }
    eduardo_research_render_surface_links(is_array($layout['links'] ?? null) ? $layout['links'] : array());
}

function eduardo_research_render_legal_surface(string $key): void {
    $language = eduardo_research_current_language();
    $model = eduardo_research_surface_model($key);
    $stored = get_option('eduardo_research_legal_' . sanitize_key($key) . ('es' === $language ? '_es' : ''), array());
    $sections = is_array($stored) ? $stored : array();
    if (! $sections) { return; }
    ?>
    <div class="research-legal-layout">
      <?php foreach ($sections as $index => $section) :
          if (! is_array($section)) { continue; }
          $title = isset($section['title']) && is_scalar($section['title']) ? (string) $section['title'] : (string) (($model['sections'][$index] ?? '') ?: ('Section ' . ($index + 1)));
          $body = isset($section['body']) && is_scalar($section['body']) ? (string) $section['body'] : '';
      ?>
        <section class="research-legal-section">
          <span class="research-section-number"><?php echo esc_html(str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT)); ?></span>
          <h2><?php echo esc_html($title); ?></h2>
          <?php if ('' !== trim($body)) : ?><p><?php echo esc_html($body); ?></p><?php endif; ?>
        </section>
      <?php endforeach; ?>
    </div>
    <?php
}
