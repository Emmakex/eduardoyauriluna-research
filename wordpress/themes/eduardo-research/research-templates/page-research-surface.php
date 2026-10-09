<?php
/** Theme-owned renderer for controlled Research pages. */
declare(strict_types=1);
if (! defined('ABSPATH')) { exit; }
$key = eduardo_research_current_page_key();
$contract = null === $key ? null : eduardo_research_page_contract($key);
if (null === $key || null === $contract) { get_template_part('index'); return; }
$language = eduardo_research_current_language();
$model = eduardo_research_surface_model($key);
get_header();
$title = eduardo_research_page_label($key);
?>
<main id="main" tabindex="-1">
 <header class="research-shell research-hero research-surface-hero">
  <div class="research-eyebrow"><?php echo esc_html((string) ($model['eyebrow'] ?? $contract['role'])); ?></div>
  <h1 class="research-title"><?php echo esc_html($title); ?></h1>
  <p class="research-lead"><?php echo esc_html((string) ($model['lead'] ?? '')); ?></p>
 </header>

 <?php if (in_array($key, array('about','contact'), true)) : ?>
  <section class="research-interior" aria-label="<?php echo esc_attr($title); ?>">
   <div class="research-shell research-interior-stack">
    <?php eduardo_research_render_structured_surface($key); ?>
   </div>
  </section>
 <?php elseif ('cv' === $key) : ?>
  <section class="research-interior research-cv-page" aria-label="<?php echo esc_attr($title); ?>">
   <div class="research-shell">
    <?php eduardo_research_render_cv_surface(); ?>
   </div>
  </section>
 <?php elseif ('research' === $key) :
   $lines = new WP_Query(eduardo_research_verified_line_query_args($language, 24));
   $layout = eduardo_research_surface_layout('research');
   $sections = is_array($layout['sections'] ?? null) ? $layout['sections'] : array(); ?>
  <section class="research-interior" aria-label="<?php echo esc_attr($title); ?>">
   <div class="research-shell research-interior-stack">
    <section class="research-interior-block research-lines-index" data-evidence-group="research_lines">
     <header class="research-interior-block-heading">
      <span class="research-section-number">01</span>
      <div><div class="research-eyebrow"><?php echo esc_html('es' === $language ? 'Objeto de investigación' : 'Research object'); ?></div><h2><?php echo esc_html('es' === $language ? 'Líneas de investigación' : 'Research lines'); ?></h2><p><?php echo esc_html('es' === $language ? 'Líneas verificadas con pregunta central, temas, métodos y URL estable.' : 'Verified lines with a central question, topics, methods and a stable URL.'); ?></p></div>
     </header>
     <div class="research-grid research-grid-editorial">
      <?php if ($lines->have_posts()) : while ($lines->have_posts()) : $lines->the_post(); eduardo_research_render_research_line_card(get_the_ID()); endwhile; else : ?>
       <article class="research-card research-empty research-empty-wide"><p class="research-card-kicker"><?php echo esc_html('es' === $language ? 'Preparado para evidencia' : 'Evidence ready'); ?></p><h3><?php echo esc_html('es' === $language ? 'Las líneas verificadas aparecerán aquí' : 'Verified research lines will appear here'); ?></h3><p><?php echo esc_html('es' === $language ? 'No se publica ninguna línea hasta que su estado de evidencia sea verified.' : 'No research line is published here until its evidence status is verified.'); ?></p></article>
      <?php endif; wp_reset_postdata(); ?>
     </div>
    </section>
    <?php foreach (array_slice($sections, 1) as $index => $section) { if (is_array($section)) { eduardo_research_render_evidence_section($section, $index + 2); } } ?>
    <?php eduardo_research_render_surface_links(is_array($layout['links'] ?? null) ? $layout['links'] : array()); ?>
   </div>
  </section>
 <?php elseif (in_array($key, array('privacy-policy','legal-notice'), true)) : ?>
  <section class="research-interior" aria-label="<?php echo esc_attr($title); ?>">
   <div class="research-shell">
    <?php eduardo_research_render_legal_surface($key); ?>
   </div>
  </section>
 <?php elseif (in_array($key, array('publications','projects','software','datasets'), true)) :
   $query = eduardo_research_collection_query($key, $language); ?>
  <section class="research-section research-object-index" aria-label="<?php echo esc_attr($title); ?>">
   <div class="research-shell">
    <div class="research-object-index-note"><span><?php echo esc_html('es' === $language ? 'Registros estructurados' : 'Structured records'); ?></span><p><?php echo esc_html('es' === $language ? 'Filtra por línea de investigación y metadatos académicos sin alterar la URL canónica de la colección.' : 'Filter by Research Line and academic metadata without changing the collection canonical URL.'); ?></p></div>
    <?php eduardo_research_render_collection_filters($key); ?>
    <?php eduardo_research_render_collection_summary($key, $query); ?>
    <div class="research-grid research-grid-editorial">
     <?php if ($query->have_posts()) : while ($query->have_posts()) : $query->the_post(); eduardo_research_render_collection_card(get_the_ID(), $key); endwhile; else : ?>
      <article class="research-card research-empty research-empty-wide"><h2><?php echo esc_html('es' === $language ? 'No hay resultados para estos filtros' : 'No records match these filters'); ?></h2><p><?php echo esc_html('es' === $language ? 'Prueba a limpiar uno o más filtros. El Theme nunca crea registros para rellenar un resultado vacío.' : 'Clear one or more filters. The Theme never invents records to fill an empty result set.'); ?></p></article>
     <?php endif; wp_reset_postdata(); ?>
    </div>
    <?php eduardo_research_render_collection_pagination($key, $query); ?>
   </div>
  </section>
 <?php elseif ('insights' === $key) : $query = eduardo_research_insight_query($language); ?>
  <section class="research-section research-insight-index" aria-label="<?php echo esc_attr($title); ?>">
   <div class="research-shell">
    <div class="research-object-index-note"><span><?php echo esc_html('es' === $language ? 'Sistema editorial' : 'Editorial system'); ?></span><p><?php echo esc_html('es' === $language ? 'Notas de investigación, explicadores, métodos y comentarios con URL propia y separación estricta por idioma.' : 'Research notes, explainers, methods and commentary with stable URLs and strict language separation.'); ?></p></div>
    <?php eduardo_research_render_insight_filters(); ?>
    <div class="research-collection-summary"><strong><?php echo esc_html(sprintf('es' === $language ? '%d notas' : '%d notes', (int) $query->found_posts)); ?></strong><span><?php echo esc_html('es' === $language ? 'Resultados editoriales publicados.' : 'Published editorial results.'); ?></span></div>
    <div class="research-grid research-grid-editorial research-insight-grid">
     <?php if ($query->have_posts()) : while ($query->have_posts()) : $query->the_post(); eduardo_research_render_insight_card(get_the_ID()); endwhile; else : ?>
      <article class="research-card research-empty research-empty-wide"><h2><?php echo esc_html('es' === $language ? 'No hay notas para esta búsqueda' : 'No research notes match this search'); ?></h2><p><?php echo esc_html('es' === $language ? 'Prueba con otro término o limpia el tipo editorial seleccionado.' : 'Try another term or clear the selected editorial type.'); ?></p></article>
     <?php endif; wp_reset_postdata(); ?>
    </div>
    <?php eduardo_research_render_insight_pagination($query); ?>
   </div>
  </section>
 <?php endif; ?>
</main>
<?php get_footer();
