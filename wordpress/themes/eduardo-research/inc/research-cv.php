<?php
/** Print-ready academic CV renderer sourced only from verified evidence. */
declare(strict_types=1);
if (! defined('ABSPATH')) { exit; }

function eduardo_research_cv_record_period(array $record): string {
    foreach (array('period','date','dates') as $field) {
        if (isset($record[$field]) && is_scalar($record[$field]) && '' !== trim((string) $record[$field])) { return trim((string) $record[$field]); }
    }
    $start = isset($record['start_date']) && is_scalar($record['start_date']) ? trim((string) $record['start_date']) : '';
    $end = isset($record['end_date']) && is_scalar($record['end_date']) ? trim((string) $record['end_date']) : '';
    if ('' === $start && '' === $end) { return ''; }
    return trim($start . ('es' === eduardo_research_current_language() ? ' — ' : ' — ') . ($end ?: ('es' === eduardo_research_current_language() ? 'Actualidad' : 'Present')));
}

function eduardo_research_cv_record_context(array $record): string {
    foreach (array('organization','institution','company','affiliation') as $field) {
        if (isset($record[$field]) && is_scalar($record[$field]) && '' !== trim((string) $record[$field])) { return trim((string) $record[$field]); }
    }
    return '';
}

function eduardo_research_render_cv_record(array $record, string $fallback): void {
    $title = eduardo_research_surface_record_title($record, $fallback);
    $summary = isset($record['summary']) && is_scalar($record['summary']) ? trim((string) $record['summary']) : '';
    $value = isset($record['value']) && is_scalar($record['value']) ? trim((string) $record['value']) : '';
    $period = eduardo_research_cv_record_period($record);
    $context = eduardo_research_cv_record_context($record);
    $url = isset($record['url']) && is_scalar($record['url']) ? esc_url((string) $record['url']) : '';
    ?>
    <article class="research-cv-entry">
      <div class="research-cv-entry-period"><?php echo esc_html($period); ?></div>
      <div class="research-cv-entry-body">
        <h3><?php echo esc_html($title); ?></h3>
        <?php if ('' !== $context) : ?><p class="research-cv-context"><?php echo esc_html($context); ?></p><?php endif; ?>
        <?php if ('' !== $summary) : ?><p><?php echo esc_html($summary); ?></p><?php elseif ('' !== $value && $value !== $title) : ?><p><?php echo esc_html($value); ?></p><?php endif; ?>
        <?php if ('' !== $url) : ?><a class="research-cv-source" href="<?php echo $url; ?>" rel="noopener"><?php echo esc_html(eduardo_research_t('open_source')); ?> <span aria-hidden="true">↗</span></a><?php endif; ?>
      </div>
    </article>
    <?php
}

function eduardo_research_cv_publication_query(?string $language = null): WP_Query {
    $language = $language ?: eduardo_research_current_language();
    return new WP_Query(eduardo_research_localized_query_args(array(
        'post_type'=>'research_output','post_status'=>'publish','posts_per_page'=>6,
        'orderby'=>'date','order'=>'DESC','no_found_rows'=>true,
    ), $language));
}

function eduardo_research_render_cv_surface(): void {
    $language = eduardo_research_current_language();
    $es = 'es' === $language;
    $identity = eduardo_research_identity();
    $sections = array(
        'experience'=>$es ? 'Experiencia' : 'Experience',
        'education'=>$es ? 'Formación' : 'Education',
        'affiliations'=>$es ? 'Afiliaciones' : 'Affiliations',
        'awards'=>$es ? 'Reconocimientos' : 'Awards',
    );
    $publications = eduardo_research_cv_publication_query($language);
    ?>
    <div class="research-cv-surface">
      <div class="research-cv-toolbar" aria-label="<?php echo esc_attr($es ? 'Acciones del currículum' : 'CV actions'); ?>">
        <p><?php echo esc_html($es ? 'Vista web preparada para impresión o guardado como PDF desde el navegador.' : 'Web view prepared for printing or saving as PDF from the browser.'); ?></p>
        <button class="research-button research-cv-print" type="button" data-research-print><?php echo esc_html($es ? 'Imprimir / Guardar PDF' : 'Print / Save PDF'); ?></button>
      </div>

      <header class="research-cv-print-header">
        <p class="research-eyebrow"><?php echo esc_html($es ? 'Currículum académico' : 'Academic curriculum vitae'); ?></p>
        <h2><?php echo esc_html((string) $identity['name']); ?></h2>
        <p><?php echo esc_html((string) eduardo_research_model()['researcher-headline']); ?></p>
        <span><?php echo esc_html(eduardo_research_page_url('cv')); ?></span>
      </header>

      <?php $position = 0; foreach ($sections as $group => $label) : $records = eduardo_research_verified_localized_evidence($group); if (! $records) { continue; } $position++; ?>
        <section class="research-cv-section" data-evidence-group="<?php echo esc_attr($group); ?>">
          <header><span class="research-section-number"><?php echo esc_html(str_pad((string) $position, 2, '0', STR_PAD_LEFT)); ?></span><h2><?php echo esc_html($label); ?></h2></header>
          <div class="research-cv-entries">
            <?php foreach ($records as $record) : if (is_array($record)) { eduardo_research_render_cv_record($record, $label); } endforeach; ?>
          </div>
        </section>
      <?php endforeach; ?>

      <?php if ($publications->have_posts()) : $position++; ?>
        <section class="research-cv-section research-cv-publications">
          <header><span class="research-section-number"><?php echo esc_html(str_pad((string) $position, 2, '0', STR_PAD_LEFT)); ?></span><h2><?php echo esc_html($es ? 'Resultados de investigación seleccionados' : 'Selected research outputs'); ?></h2></header>
          <ol class="research-cv-output-list">
            <?php while ($publications->have_posts()) : $publications->the_post(); $post_id = get_the_ID(); ?>
              <li><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a><?php $venue = eduardo_research_meta_value($post_id, '_research_venue'); $date = eduardo_research_meta_value($post_id, '_research_publication_date'); if ($venue || $date) : ?><span><?php echo esc_html(trim($venue . ($venue && $date ? ' · ' : '') . $date)); ?></span><?php endif; ?></li>
            <?php endwhile; wp_reset_postdata(); ?>
          </ol>
          <a class="research-text-link research-cv-all-outputs" href="<?php echo esc_url(eduardo_research_page_url('publications')); ?>"><?php echo esc_html($es ? 'Ver todos los resultados' : 'View all research outputs'); ?> <span aria-hidden="true">↗</span></a>
        </section>
      <?php else : wp_reset_postdata(); endif; ?>

      <?php if (0 === $position) : ?>
        <div class="research-interior-empty"><div class="research-verification-mark" aria-hidden="true">✓</div><div><h3><?php echo esc_html($es ? 'Currículum preparado para evidencia verificada' : 'CV ready for verified evidence'); ?></h3><p><?php echo esc_html($es ? 'No se publicará experiencia, formación, afiliaciones ni reconocimientos hasta que exista evidencia verificada.' : 'Experience, education, affiliations and awards remain unpublished until verified evidence exists.'); ?></p></div></div>
      <?php endif; ?>
    </div>
    <?php
}
