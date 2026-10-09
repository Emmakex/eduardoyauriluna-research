<?php
declare(strict_types=1);
if (! defined('ABSPATH')) { exit; }
get_header(); ?>
<main id="main" tabindex="-1">
 <header class="research-shell research-hero"><div class="research-eyebrow">Research index</div><h1 class="research-title"><?php the_archive_title(); ?></h1><?php the_archive_description('<div class="research-lead">','</div>'); ?></header>
 <section class="research-section"><div class="research-shell research-grid">
 <?php if (have_posts()) : while (have_posts()) : the_post(); ?>
  <article class="research-card"><h2><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2><?php if (has_excerpt()) : ?><p><?php echo esc_html(get_the_excerpt()); ?></p><?php endif; ?></article>
 <?php endwhile; else : ?><article class="research-card"><p>No verified records published yet.</p></article><?php endif; ?>
 </div></section>
</main>
<?php get_footer();
