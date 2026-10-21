<?php
/** Fallback for WordPress pages outside the controlled Research preset. */
declare(strict_types=1);
if (! defined('ABSPATH')) { exit; }
get_header();
$language = function_exists('eduardo_research_current_language') ? eduardo_research_current_language() : 'en';
?>
<main id="main" tabindex="-1">
 <?php while (have_posts()) : the_post(); ?>
  <article>
   <header class="research-shell research-hero research-surface-hero"><div class="research-eyebrow"><?php echo esc_html('es' === $language ? 'Eduardo Jose Yauri Luna' : 'Eduardo Jose Yauri Luna'); ?></div><h1 class="research-title"><?php the_title(); ?></h1></header>
   <div class="research-section"><div class="research-shell research-prose"><?php the_content(); ?></div></div>
  </article>
 <?php endwhile; ?>
</main>
<?php get_footer();
