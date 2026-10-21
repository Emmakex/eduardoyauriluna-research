<?php
declare(strict_types=1);
if (! defined('ABSPATH')) { exit; }
get_header();
$language = eduardo_research_current_language();
$es = 'es' === $language;
$post_type = is_post_type_archive() ? get_query_var('post_type') : '';
if (is_array($post_type)) { $post_type = reset($post_type); }
$type_object = $post_type ? get_post_type_object((string) $post_type) : null;
$title = $type_object ? (string) $type_object->labels->name : wp_strip_all_tags(get_the_archive_title());
?>
<main id="main" tabindex="-1">
 <header class="research-shell research-hero research-surface-hero">
  <div class="research-eyebrow"><?php echo esc_html($es ? 'Investigación' : 'Research'); ?></div>
  <h1 class="research-title"><?php echo esc_html($title); ?></h1>
  <?php $description = get_the_archive_description(); if ($description) : ?><div class="research-lead"><?php echo wp_kses_post($description); ?></div><?php else : ?><p class="research-lead"><?php echo esc_html($es ? 'Trabajos y recursos publicados vinculados a la agenda de investigación.' : 'Published work and resources connected to the research agenda.'); ?></p><?php endif; ?>
 </header>
 <section class="research-section">
  <div class="research-shell">
   <div class="research-grid">
    <?php if (have_posts()) : while (have_posts()) : the_post(); $object = get_post_type_object(get_post_type()); ?>
     <article class="research-card">
      <p class="research-card-kicker"><?php echo esc_html((string) ($object->labels->singular_name ?? ($es ? 'Investigación' : 'Research'))); ?></p>
      <h2><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
      <?php if (has_excerpt()) : ?><p><?php echo esc_html(get_the_excerpt()); ?></p><?php endif; ?>
      <p class="research-meta-inline"><span><?php echo esc_html(($es ? 'Actualizado ' : 'Updated ') . get_the_modified_date()); ?></span></p>
     </article>
    <?php endwhile; else : ?><article class="research-card research-empty"><h2><?php echo esc_html($es ? 'Todavía no hay elementos publicados' : 'No entries have been published yet'); ?></h2><p><?php echo esc_html($es ? 'Esta sección se completará a medida que se publiquen trabajos vinculados a la agenda de investigación.' : 'This section will grow as work connected to the research agenda is published.'); ?></p></article><?php endif; ?>
   </div>
   <nav class="research-pagination" aria-label="<?php echo esc_attr($es ? 'Paginación del archivo' : 'Archive pagination'); ?>"><?php the_posts_pagination(array('mid_size'=>1,'prev_text'=>$es?'Anterior':'Previous','next_text'=>$es?'Siguiente':'Next')); ?></nav>
  </div>
 </section>
</main>
<?php get_footer();
