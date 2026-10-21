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

 <?php eduardo_research_render_primary_media(0, $key, $language); ?>

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
   $sections = is_array($layout['sections'] ?? null) ? $layout['sections'] : array();
   $es = 'es' === $language; ?>
  <section class="research-interior" aria-label="<?php echo esc_attr($title); ?>">
   <div class="research-shell research-interior-stack">
    <section class="research-interior-block research-agenda-overview" data-research-agenda-status="planned">
     <header class="research-interior-block-heading">
      <span class="research-section-number">01</span>
      <div>
       <div class="research-eyebrow"><?php echo esc_html($es ? 'Agenda de investigación planificada' : 'Planned research agenda'); ?></div>
       <h2><?php echo esc_html($es ? 'Pregunta principal de investigación' : 'Core research question'); ?></h2>
       <p><?php echo esc_html($es
         ? '¿Cómo pueden diseñarse, integrarse y evaluarse sistemas de IA autónomos y semiautónomos para mejorar procesos empresariales manteniendo fiabilidad, trazabilidad, supervisión humana y gobernanza?'
         : 'How can autonomous and semi-autonomous AI systems be designed, integrated and evaluated to improve business processes while remaining reliable, traceable, governable and subject to appropriate human oversight?'); ?></p>
      </div>
     </header>
     <div class="research-grid research-grid-editorial">
      <article class="research-card">
       <p class="research-card-kicker"><?php echo esc_html($es ? 'Alcance' : 'Scope'); ?></p>
       <h3><?php echo esc_html($es ? 'Cuatro líneas conectadas' : 'Four connected research lines'); ?></h3>
       <p><?php echo esc_html($es
         ? 'La agenda se estructura en agentes autónomos de IA para procesos empresariales, colaboración humano–IA y autonomía responsable, transformación digital y adopción de IA en pymes, y descubrimiento de información mediado por IA y búsqueda generativa.'
         : 'The agenda is structured around autonomous AI agents for business processes, human–AI collaboration and responsible autonomy, digital transformation and AI adoption in SMEs, and AI-mediated information discovery and generative search.'); ?></p>
      </article>
      <article class="research-card">
       <p class="research-card-kicker"><?php echo esc_html($es ? 'Métodos previstos' : 'Planned methods'); ?></p>
       <h3><?php echo esc_html($es ? 'Evaluación aplicada y reproducible' : 'Applied and reproducible evaluation'); ?></h3>
       <p><?php echo esc_html($es
         ? 'Según la pregunta concreta, se prevé combinar Design Science Research, prototipado de software, experimentos controlados, evaluación cuantitativa, estudios de caso comparativos, encuestas, entrevistas y métodos mixtos.'
         : 'Depending on the question, the planned approach may combine Design Science Research, software prototyping, controlled experiments, quantitative evaluation, comparative case studies, surveys, interviews and mixed methods.'); ?></p>
      </article>
      <article class="research-card">
       <p class="research-card-kicker"><?php echo esc_html($es ? 'Evolución' : 'Evolution'); ?></p>
       <h3><?php echo esc_html($es ? 'De agenda amplia a preguntas acotadas' : 'From broad agenda to bounded questions'); ?></h3>
       <p><?php echo esc_html($es
         ? 'La agenda se irá acotando a medida que se obtenga evidencia, se definan estudios concretos y una futura investigación doctoral se alinee con un grupo y una supervisión académica adecuados.'
         : 'The agenda is expected to narrow as evidence accumulates, specific studies are defined and a future doctoral project is aligned with an appropriate research group and academic supervision.'); ?></p>
      </article>
      <article class="research-card">
       <p class="research-card-kicker"><?php echo esc_html($es ? 'Estado de la investigación' : 'Research status'); ?></p>
       <h3><?php echo esc_html($es ? 'Agenda no equivale a resultados' : 'Agenda is not the same as results'); ?></h3>
       <p><?php echo esc_html($es
         ? 'Esta página describe direcciones, preguntas y métodos previstos. Las publicaciones, proyectos, software, datos y demás resultados solo se presentan como resultados cuando existe evidencia real y verificable.'
         : 'This page describes intended directions, questions and methods. Publications, projects, software, datasets and other outputs are presented as research results only when real, verifiable evidence exists.'); ?></p>
      </article>
     </div>
    </section>

    <section class="research-interior-block research-lines-index" data-evidence-group="research_lines">
     <header class="research-interior-block-heading">
      <span class="research-section-number">02</span>
      <div><div class="research-eyebrow"><?php echo esc_html($es ? 'Agenda de investigación' : 'Research agenda'); ?></div><h2><?php echo esc_html($es ? 'Líneas de investigación' : 'Research lines'); ?></h2><p><?php echo esc_html($es ? 'Líneas, preguntas centrales y métodos propuestos para desarrollar la agenda de investigación.' : 'Research lines, central questions and proposed methods that shape the research agenda.'); ?></p></div>
     </header>
     <div class="research-grid research-grid-editorial">
      <?php if ($lines->have_posts()) : while ($lines->have_posts()) : $lines->the_post(); eduardo_research_render_research_line_card(get_the_ID()); endwhile; else : ?>
       <article class="research-card research-empty research-empty-wide"><h3><?php echo esc_html($es ? 'Agenda de investigación en desarrollo' : 'Research agenda in development'); ?></h3><p><?php echo esc_html($es ? 'Las líneas de investigación se incorporarán aquí a medida que avance la agenda.' : 'Research lines will be added here as the agenda develops.'); ?></p></article>
      <?php endif; wp_reset_postdata(); ?>
     </div>
    </section>
    <?php $position = 2; foreach (array_slice($sections, 1) as $section) { if (! is_array($section)) { continue; } $group = sanitize_key((string) ($section['group'] ?? '')); if (! eduardo_research_verified_localized_evidence($group)) { continue; } $position++; eduardo_research_render_evidence_section($section, $position); } ?>
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
    <?php if ($query->have_posts()) : ?>
     <?php eduardo_research_render_collection_filters($key); ?>
     <?php eduardo_research_render_collection_summary($key, $query); ?>
     <div class="research-grid research-grid-editorial">
      <?php while ($query->have_posts()) : $query->the_post(); eduardo_research_render_collection_card(get_the_ID(), $key); endwhile; wp_reset_postdata(); ?>
     </div>
     <?php eduardo_research_render_collection_pagination($key, $query); ?>
    <?php else : ?>
     <div class="research-grid research-grid-editorial">
      <article class="research-card research-empty research-empty-wide">
       <h2><?php echo esc_html('es' === $language ? 'Todavía no hay elementos publicados' : 'No entries have been published yet'); ?></h2>
       <p><?php echo esc_html('es' === $language ? 'Esta colección se completará a medida que se publiquen resultados vinculados a la agenda de investigación.' : 'This collection will grow as outputs connected to the research agenda are published.'); ?></p>
      </article>
     </div>
    <?php endif; ?>
   </div>
  </section>
 <?php elseif ('insights' === $key) : $query = eduardo_research_insight_query($language); ?>
  <?php if ($query->have_posts()) : ?>
   <section class="research-section research-insight-index" aria-label="<?php echo esc_attr($title); ?>">
    <div class="research-shell">
     <div class="research-object-index-note"><span><?php echo esc_html('es' === $language ? 'Notas de investigación' : 'Research notes'); ?></span><p><?php echo esc_html('es' === $language ? 'Notas de trabajo, explicaciones y observaciones metodológicas relacionadas con la agenda de investigación.' : 'Working notes, explainers and methodological observations related to the research agenda.'); ?></p></div>
     <?php eduardo_research_render_insight_filters(); ?>
     <div class="research-collection-summary"><strong><?php echo esc_html(sprintf('es' === $language ? '%d notas' : '%d notes', (int) $query->found_posts)); ?></strong><span><?php echo esc_html('es' === $language ? 'Notas publicadas.' : 'Published notes.'); ?></span></div>
     <div class="research-grid research-grid-editorial research-insight-grid">
      <?php while ($query->have_posts()) : $query->the_post(); eduardo_research_render_insight_card(get_the_ID()); endwhile; wp_reset_postdata(); ?>
     </div>
     <?php eduardo_research_render_insight_pagination($query); ?>
    </div>
   </section>
  <?php else : wp_reset_postdata(); endif; ?>
 <?php endif; ?>
</main>
<?php get_footer();
