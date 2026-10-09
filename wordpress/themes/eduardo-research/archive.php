<?php
declare(strict_types=1);
if (! defined('ABSPATH')) { exit; }
get_header();
$post_type = is_post_type_archive() ? get_query_var('post_type') : '';
if (is_array($post_type)) { $post_type = reset($post_type); }
$type_object = $post_type ? get_post_type_object((string) $post_type) : null;
$title = $type_object ? (string) $type_object->labels->name : wp_strip_all_tags(get_the_archive_title());
?>
<main id="main" tabindex="-1">
 <header class="research-shell research-hero research-surface-hero">
  <div class="research-eyebrow">Research index</div>
  <h1 class="research-title"><?php echo esc_html($title); ?></h1>
  <?php $description = get_the_archive_description(); if ($description) : ?><div class="research-lead"><?php echo wp_kses_post($description); ?></div><?php else : ?><p class="research-lead">Verified records are organised here as structured research objects.</p><?php endif; ?>
 </header>
 <section class="research-section">
  <div class="research-shell">
   <div class="research-grid">
    <?php if (have_posts()) : while (have_posts()) : the_post(); $object = get_post_type_object(get_post_type()); ?>
     <article class="research-card">
      <p class="research-card-kicker"><?php echo esc_html((string) ($object->labels->singular_name ?? 'Research')); ?></p>
      <h2><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
      <?php if (has_excerpt()) : ?><p><?php echo esc_html(get_the_excerpt()); ?></p><?php endif; ?>
      <p class="research-meta-inline"><span>Updated <?php echo esc_html(get_the_modified_date()); ?></span></p>
     </article>
    <?php endwhile; else : ?><article class="research-card research-empty"><h2>No verified records published yet</h2><p>The archive is active and ready for evidence-backed research objects.</p></article><?php endif; ?>
   </div>
   <nav class="research-pagination" aria-label="<?php esc_attr_e('Archive pagination', 'eduardo-research'); ?>"><?php the_posts_pagination(array('mid_size'=>1,'prev_text'=>'Previous','next_text'=>'Next')); ?></nav>
  </div>
 </section>
</main>
<?php get_footer();
