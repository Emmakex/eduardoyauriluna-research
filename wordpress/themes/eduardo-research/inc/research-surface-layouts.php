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
                array('group'=>'profile','label'=>$es ? 'Perfil de investigación' : 'Research profile','intro'=>$es ? 'Biografía, enfoque y contexto publicados únicamente desde evidencia verificada.' : 'Biography, focus and context published only from verified evidence.'),
                array('group'=>'affiliations','label'=>$es ? 'Afiliaciones verificadas' : 'Verified affiliations','intro'=>$es ? 'Instituciones y relaciones académicas o profesionales confirmadas.' : 'Confirmed academic or professional institutional relationships.'),
                array('group'=>'identifiers','label'=>$es ? 'Identidad académica' : 'Academic identity','intro'=>$es ? 'Identificadores y perfiles externos que han sido verificados explícitamente.' : 'External identifiers and profiles that have been explicitly verified.'),
            ),
            'links' => array('research','cv','contact'),
        ),
        'research' => array(
            'sections' => array(
                array('group'=>'research_lines','label'=>$es ? 'Líneas de investigación' : 'Research lines','intro'=>$es ? 'Áreas de trabajo activas organizadas alrededor de preguntas y resultados verificables.' : 'Active areas of work organised around questions and verifiable outputs.'),
                array('group'=>'research_questions','label'=>$es ? 'Preguntas de investigación' : 'Research questions','intro'=>$es ? 'Preguntas explícitas que orientan la agenda de investigación.' : 'Explicit questions guiding the research agenda.'),
                array('group'=>'methods','label'=>$es ? 'Métodos' : 'Methods','intro'=>$es ? 'Métodos, marcos y procedimientos declarados para el trabajo reproducible.' : 'Declared methods, frameworks and procedures supporting reproducible work.'),
            ),
            'links' => array('publications','projects','software','datasets'),
        ),
        'cv' => array(
            'sections' => array(
                array('group'=>'experience','label'=>$es ? 'Experiencia' : 'Experience','intro'=>$es ? 'Trayectoria publicada desde registros verificados.' : 'Career history published from verified records.'),
                array('group'=>'education','label'=>$es ? 'Formación' : 'Education','intro'=>$es ? 'Formación y credenciales únicamente cuando exista evidencia verificada.' : 'Education and credentials only when verified evidence exists.'),
                array('group'=>'affiliations','label'=>$es ? 'Afiliaciones' : 'Affiliations','intro'=>$es ? 'Relaciones institucionales confirmadas.' : 'Confirmed institutional relationships.'),
                array('group'=>'awards','label'=>$es ? 'Reconocimientos' : 'Awards','intro'=>$es ? 'Premios o reconocimientos solo cuando estén documentados.' : 'Awards or distinctions only when documented.'),
            ),
            'links' => array('publications','projects','contact'),
        ),
        'contact' => array(
            'sections' => array(
                array('group'=>'contact','label'=>$es ? 'Consultas de investigación' : 'Research enquiries','intro'=>$es ? 'Canales de contacto publicados desde información verificada.' : 'Contact channels published from verified information.'),
                array('group'=>'identifiers','label'=>$es ? 'Perfiles académicos' : 'Academic profiles','intro'=>$es ? 'Perfiles externos verificados para identidad y descubrimiento.' : 'Verified external profiles for identity and discovery.'),
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

function eduardo_research_render_evidence_section(array $section, int $position): void {
    $group = sanitize_key((string) ($section['group'] ?? ''));
    $label = (string) ($section['label'] ?? $group);
    $intro = (string) ($section['intro'] ?? '');
    $records = eduardo_research_verified_localized_evidence($group);
    $language = eduardo_research_current_language();
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
      <?php if ($records) : ?>
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
                <?php if ('' !== $url) : ?><a class="research-text-link" href="<?php echo $url; ?>" rel="noopener"><?php echo esc_html(eduardo_research_t('open_source')); ?> <span aria-hidden="true">↗</span></a><?php endif; ?>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      <?php else : ?>
        <div class="research-interior-empty">
          <div class="research-verification-mark" aria-hidden="true">✓</div>
          <div>
            <h3><?php echo esc_html('es' === $language ? 'Pendiente de evidencia verificada' : 'Awaiting verified evidence'); ?></h3>
            <p><?php echo esc_html('es' === $language ? 'La estructura está preparada, pero no se publicará ninguna afirmación hasta que exista evidencia verificada.' : 'The structure is ready, but no claim will be published until verified evidence exists.'); ?></p>
          </div>
        </div>
      <?php endif; ?>
    </section>
    <?php
}

function eduardo_research_render_surface_links(array $keys): void {
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
    foreach ($sections as $index => $section) {
        if (! is_array($section)) { continue; }
        eduardo_research_render_evidence_section($section, $index + 1);
    }
    eduardo_research_render_surface_links(is_array($layout['links'] ?? null) ? $layout['links'] : array());
}

function eduardo_research_render_legal_surface(string $key): void {
    $language = eduardo_research_current_language();
    $model = eduardo_research_surface_model($key);
    $stored = get_option('eduardo_research_legal_' . sanitize_key($key) . ('es' === $language ? '_es' : ''), array());
    $sections = is_array($stored) ? $stored : array();
    if (! $sections) {
        $sections = array(array(
            'title' => 'es' === $language ? 'Información legal pendiente de verificación' : 'Legal information awaiting verification',
            'body' => 'es' === $language
                ? 'El Theme mantiene esta página publicada y estructurada, pero los datos legales específicos del titular deben verificarse antes de mostrarse.'
                : 'The Theme keeps this page published and structured, but owner-specific legal details must be verified before they are shown.',
        ));
    }
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
