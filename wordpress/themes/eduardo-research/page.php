<?php
/** Fallback for WordPress pages outside the controlled Research preset. */
declare(strict_types=1);
if (! defined('ABSPATH')) { exit; }
get_header(); ?>
<main id="main" tabindex="-1">
 <?php while (have_posts()) : the_post(); ?>
  <article>
   <header class="research-shell research-hero research-surface-hero"><div class="research-eyebrow">Page</div><h1 class="research-title"><?php the_title(); ?></h1></header>
   <div class="research-section"><div class="research-shell research-prose"><?php the_content(); ?></div></div>
  </article>
 <?php endwhile; ?>
</main>
<?php get_footer();
