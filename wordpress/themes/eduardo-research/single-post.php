<?php
/** Theme-owned single surface for research Insights. */
declare(strict_types=1);
if (! defined('ABSPATH')) { exit; }
get_header();
$es = 'es' === eduardo_research_current_language();
?>
<main id="main" tabindex="-1" class="research-insight-single">
<?php while (have_posts()) : the_post(); $post_id = get_the_ID(); ?>
 <article>
  <header class="research-shell research-hero research-surface-hero research-object-hero">
   <div class="research-eyebrow"><?php echo esc_html($es ? 'Insight de investigación' : 'Research insight'); ?></div>
   <h1 class="research-title"><?php the_title(); ?></h1>
   <?php if (has_excerpt()) : ?><p class="research-lead"><?php echo esc_html(get_the_excerpt()); ?></p><?php endif; ?>
   <div class="research-insight-single-meta">
    <span><?php echo esc_html(eduardo_research_insight_type_label($post_id)); ?></span>
    <time datetime="<?php echo esc_attr(get_the_date('c')); ?>"><?php echo esc_html(get_the_date()); ?></time>
    <?php if (get_the_modified_time('U') > get_the_time('U')) : ?><span><?php echo esc_html(($es ? 'Actualizado ' : 'Updated ') . get_the_modified_date()); ?></span><?php endif; ?>
   </div>
  </header>
  <section class="research-section research-object-body">
   <div class="research-shell research-object-body-grid">
    <div><span class="research-section-number">01</span><div class="research-eyebrow"><?php echo esc_html($es ? 'Nota' : 'Note'); ?></div><h2><?php echo esc_html($es ? 'Texto completo' : 'Full text'); ?></h2></div>
    <div class="research-prose"><?php the_content(); ?><a class="research-insight-back" href="<?php echo esc_url(eduardo_research_page_url('insights')); ?>">← <?php echo esc_html($es ? 'Volver a todas las notas' : 'Back to all insights'); ?></a></div>
   </div>
  </section>
 </article>
<?php endwhile; ?>
</main>
<?php get_footer();
