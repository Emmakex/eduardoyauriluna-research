<?php
/** Generic Theme fallback for non-controlled WordPress views. */
declare(strict_types=1);
if (! defined('ABSPATH')) { exit; }
get_header();
$es = 'es' === eduardo_research_current_language();
if (is_search()) {
    $title = sprintf($es ? 'Resultados de búsqueda para «%s»' : 'Search results for “%s”', get_search_query());
} elseif (is_home()) {
    $title = $es ? 'Notas de investigación' : 'Research notes';
} else {
    $title = $es ? 'Investigación' : 'Research';
}
?>
<main id="main" tabindex="-1">
 <header class="research-shell research-hero research-surface-hero">
  <div class="research-eyebrow"><?php echo esc_html($es ? 'Investigación' : 'Research'); ?></div>
  <h1 class="research-title"><?php echo esc_html($title); ?></h1>
 </header>
 <section class="research-section">
  <div class="research-shell research-grid">
   <?php if (have_posts()) : while (have_posts()) : the_post(); ?>
    <article class="research-card"><p class="research-card-kicker"><?php echo esc_html(get_the_date()); ?></p><h2><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2><?php if (has_excerpt()) : ?><p><?php echo esc_html(get_the_excerpt()); ?></p><?php endif; ?></article>
   <?php endwhile; else : ?><article class="research-card research-empty"><h2><?php echo esc_html($es ? 'No se ha encontrado contenido' : 'No content found'); ?></h2><p><?php echo esc_html($es ? 'No hay contenido publicado para esta vista.' : 'There is no published content for this view.'); ?></p></article><?php endif; ?>
  </div>
 </section>
</main>
<?php get_footer();
