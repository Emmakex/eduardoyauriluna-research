<?php
/** Generic Theme fallback for non-controlled WordPress views. */
declare(strict_types=1);
if (! defined('ABSPATH')) { exit; }
get_header();
$title = is_search() ? sprintf('Search results for “%s”', get_search_query()) : (is_home() ? 'Insights' : 'Research');
?>
<main id="main" tabindex="-1">
 <header class="research-shell research-hero research-surface-hero">
  <div class="research-eyebrow">Research</div>
  <h1 class="research-title"><?php echo esc_html($title); ?></h1>
 </header>
 <section class="research-section">
  <div class="research-shell research-grid">
   <?php if (have_posts()) : while (have_posts()) : the_post(); ?>
    <article class="research-card"><p class="research-card-kicker"><?php echo esc_html(get_the_date()); ?></p><h2><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2><?php if (has_excerpt()) : ?><p><?php echo esc_html(get_the_excerpt()); ?></p><?php endif; ?></article>
   <?php endwhile; else : ?><article class="research-card research-empty"><h2>No content found</h2><p>This view contains no published records.</p></article><?php endif; ?>
  </div>
 </section>
</main>
<?php get_footer();
