<?php
declare(strict_types=1);
if (! defined('ABSPATH')) { exit; }
get_header(); ?>
<main id="main" tabindex="-1">
<?php while (have_posts()) : the_post();
 $post_type_object = get_post_type_object(get_post_type());
 $post_id = get_the_ID();
 $language = eduardo_research_current_language();
 $es = 'es' === $language;
 $type = get_post_type();
?>
 <article class="research-object-single research-object-single-<?php echo esc_attr((string) $type); ?>">
  <header class="research-shell research-hero research-surface-hero research-object-hero">
   <div class="research-eyebrow"><?php echo esc_html((string) ($post_type_object->labels->singular_name ?? ($es ? 'Investigación' : 'Research'))); ?></div>
   <h1 class="research-title"><?php the_title(); ?></h1>
   <?php if (has_excerpt()) : ?><p class="research-lead"><?php echo esc_html(get_the_excerpt()); ?></p><?php endif; ?>
   <?php eduardo_research_render_badges($post_id); ?>
   <?php eduardo_research_render_object_meta($post_id); ?>
  </header>

  <?php if ('research_line' === $type) :
      $question = eduardo_research_meta_value($post_id, '_research_central_question'); ?>
   <section class="research-section research-section-soft"><div class="research-shell research-object-focus">
    <div><span class="research-section-number">01</span><div class="research-eyebrow"><?php echo esc_html($es ? 'Pregunta central' : 'Central question'); ?></div><h2><?php echo esc_html($es ? 'Pregunta de investigación' : 'Research question'); ?></h2></div>
    <p class="research-object-focus-question"><?php echo esc_html($question ?: ($es ? 'La pregunta central se publicará cuando exista evidencia verificada.' : 'The central question will be published when verified evidence exists.')); ?></p>
   </div></section>
  <?php endif; ?>

  <?php if ('research_output' === $type && ($authors = eduardo_research_output_authors($post_id))) : ?>
   <section class="research-section research-object-authors"><div class="research-shell">
    <div class="research-section-heading"><div><span class="research-section-number">01</span><div class="research-eyebrow"><?php echo esc_html($es ? 'Autoría' : 'Authorship'); ?></div><h2><?php echo esc_html($es ? 'Autores' : 'Authors'); ?></h2></div><p><?php echo esc_html($es ? 'El orden de autoría se conserva exactamente como fue registrado.' : 'Author order is preserved exactly as recorded.'); ?></p></div>
    <div class="research-author-list"><?php foreach ($authors as $author) : ?><div class="research-author"><strong><?php echo esc_html((string) $author['display_name']); ?></strong><?php if (! empty($author['affiliation'])) : ?><span><?php echo esc_html((string) $author['affiliation']); ?></span><?php endif; ?><?php if (! empty($author['orcid'])) : ?><span>ORCID <?php echo esc_html((string) $author['orcid']); ?></span><?php endif; ?></div><?php endforeach; ?></div>
   </div></section>
  <?php endif; ?>

  <section class="research-section research-object-body"><div class="research-shell research-object-body-grid">
   <div><span class="research-section-number"><?php echo esc_html('research_line' === $type || 'research_output' === $type ? '02' : '01'); ?></span><div class="research-eyebrow"><?php echo esc_html($es ? 'Registro' : 'Record'); ?></div><h2><?php echo esc_html($es ? 'Descripción' : 'Description'); ?></h2></div>
   <div class="research-prose"><?php the_content(); ?></div>
  </div></section>

  <?php
  $links = array();
  if ('research_output' === $type) {
      if ($pdf = eduardo_research_meta_value($post_id, '_research_pdf_url')) { $links[$es?'PDF':'PDF'] = $pdf; }
      if ('1' === eduardo_research_meta_value($post_id, '_research_doi_verified') && ($doi = eduardo_research_meta_value($post_id, '_research_doi'))) { $links['DOI'] = 'https://doi.org/' . ltrim($doi, '/'); }
  } elseif ('research_project' === $type) {
      if ($url = eduardo_research_meta_value($post_id, '_research_project_url')) { $links[$es?'Sitio del proyecto':'Project site'] = $url; }
  } elseif ('research_software' === $type) {
      if ($url = eduardo_research_meta_value($post_id, '_research_repository_url')) { $links[$es?'Repositorio':'Repository'] = $url; }
      if ($url = eduardo_research_meta_value($post_id, '_research_documentation_url')) { $links[$es?'Documentación':'Documentation'] = $url; }
      if ($url = eduardo_research_meta_value($post_id, '_research_archive_url')) { $links[$es?'Archivo':'Archive'] = $url; }
  } elseif ('research_dataset' === $type) {
      if ($url = eduardo_research_meta_value($post_id, '_research_documentation_url')) { $links[$es?'Documentación':'Documentation'] = $url; }
      if ($url = eduardo_research_meta_value($post_id, '_research_repository')) { $links[$es?'Repositorio':'Repository'] = $url; }
  }
  if ($links) : ?>
   <section class="research-section research-section-soft"><div class="research-shell research-object-links"><div><div class="research-eyebrow"><?php echo esc_html($es ? 'Fuentes' : 'Sources'); ?></div><h2><?php echo esc_html($es ? 'Acceso y recursos' : 'Access and resources'); ?></h2></div><div class="research-object-link-grid"><?php foreach ($links as $label => $url) : ?><a href="<?php echo esc_url($url); ?>" rel="noopener"><?php echo esc_html($label); ?><span aria-hidden="true">↗</span></a><?php endforeach; ?></div></div></section>
  <?php endif; ?>
 </article>
<?php endwhile; ?>
</main>
<?php get_footer();
