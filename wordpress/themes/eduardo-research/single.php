<?php
declare(strict_types=1);
if (! defined('ABSPATH')) { exit; }
get_header(); ?>
<main id="main" tabindex="-1">
<?php while (have_posts()) : the_post(); $post_type_object = get_post_type_object(get_post_type()); $post_id = get_the_ID(); ?>
 <article>
  <header class="research-shell research-hero research-surface-hero">
   <div class="research-eyebrow"><?php echo esc_html((string) ($post_type_object->labels->singular_name ?? 'Research')); ?></div>
   <h1 class="research-title"><?php the_title(); ?></h1>
   <?php if (has_excerpt()) : ?><p class="research-lead"><?php echo esc_html(get_the_excerpt()); ?></p><?php endif; ?>
   <dl class="research-meta-list">
    <div><dt>Published</dt><dd><?php echo esc_html(get_the_date()); ?></dd></div>
    <div><dt>Updated</dt><dd><?php echo esc_html(get_the_modified_date()); ?></dd></div>
    <?php if ('research_output' === get_post_type()) : $schema_type = eduardo_research_output_schema_type($post_id); ?>
     <div><dt>Output classification</dt><dd><?php echo esc_html('CreativeWork' === $schema_type ? 'Research output' : preg_replace('/(?<!^)[A-Z]/', ' $0', $schema_type)); ?></dd></div>
     <div><dt>Classification status</dt><dd><?php echo '1' === (string) get_post_meta($post_id, '_research_output_type_verified', true) ? 'Verified' : 'Unverified'; ?></dd></div>
    <?php endif; ?>
   </dl>
  </header>
  <div class="research-section"><div class="research-shell research-prose"><?php the_content(); ?></div></div>
 </article>
<?php endwhile; ?>
</main>
<?php get_footer();
