<?php
declare(strict_types=1);
if (! defined('ABSPATH')) { exit; }
get_header(); ?>
<main id="main" tabindex="-1">
<?php while (have_posts()) : the_post(); ?>
 <article>
  <header class="research-shell research-hero"><div class="research-eyebrow"><?php echo esc_html((string) get_post_type_object(get_post_type())->labels->singular_name); ?></div><h1 class="research-title"><?php the_title(); ?></h1><?php if (has_excerpt()) : ?><p class="research-lead"><?php echo esc_html(get_the_excerpt()); ?></p><?php endif; ?></header>
  <div class="research-section"><div class="research-shell research-card"><?php the_content(); ?></div></div>
 </article>
<?php endwhile; ?>
</main>
<?php get_footer();
