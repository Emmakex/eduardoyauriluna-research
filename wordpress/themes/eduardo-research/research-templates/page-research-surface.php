<?php
/** Theme-owned renderer for controlled Research pages. */
declare(strict_types=1);
if (! defined('ABSPATH')) { exit; }
$key = eduardo_research_current_page_key();
$contract = null === $key ? null : eduardo_research_page_contract($key);
if (null === $key || null === $contract) { get_template_part('index'); return; }
$language = eduardo_research_current_language();
$model = eduardo_research_surface_model($key);
get_header();
$title = eduardo_research_page_label($key);
?>
<main id="main" tabindex="-1">
 <header class="research-shell research-hero research-surface-hero">
  <div class="research-eyebrow"><?php echo esc_html((string) ($model['eyebrow'] ?? $contract['role'])); ?></div>
  <h1 class="research-title"><?php echo esc_html($title); ?></h1>
  <p class="research-lead"><?php echo esc_html((string) ($model['lead'] ?? '')); ?></p>
 </header>

 <?php if (in_array($key, array('about','research','cv','contact'), true)) : ?>
  <section class="research-interior" aria-label="<?php echo esc_attr($title); ?>">
   <div class="research-shell research-interior-stack">
    <?php eduardo_research_render_structured_surface($key); ?>
   </div>
  </section>
 <?php elseif (in_array($key, array('privacy-policy','legal-notice'), true)) : ?>
  <section class="research-interior" aria-label="<?php echo esc_attr($title); ?>">
   <div class="research-shell">
    <?php eduardo_research_render_legal_surface($key); ?>
   </div>
  </section>
 <?php elseif (in_array($key, array('publications','projects','software','datasets'), true)) :
   $map = array('publications'=>'research_output','projects'=>'research_project','software'=>'research_software','datasets'=>'research_dataset');
   $args = eduardo_research_localized_query_args(array('post_type'=>$map[$key], 'post_status'=>'publish', 'posts_per_page'=>24, 'no_found_rows'=>true), $language);
   $query = new WP_Query($args); ?>
  <section class="research-section" aria-label="<?php echo esc_attr($title); ?>">
   <div class="research-shell">
    <div class="research-grid">
     <?php if ($query->have_posts()) : while ($query->have_posts()) : $query->the_post(); ?>
      <article class="research-card"><p class="research-card-kicker"><?php echo esc_html((string) $model['eyebrow']); ?></p><h2><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2><?php if (has_excerpt()) : ?><p><?php echo esc_html(get_the_excerpt()); ?></p><?php endif; ?></article>
     <?php endwhile; else : ?><article class="research-card research-empty"><h2><?php echo esc_html(eduardo_research_t('evidence_index')); ?></h2><p><?php echo esc_html(eduardo_research_t('no_records')); ?></p></article><?php endif; wp_reset_postdata(); ?>
    </div>
   </div>
  </section>
 <?php elseif ('insights' === $key) :
   $args = eduardo_research_localized_query_args(array('post_type'=>'post','post_status'=>'publish','posts_per_page'=>12,'no_found_rows'=>true), $language);
   $query = new WP_Query($args); ?>
  <section class="research-section" aria-label="<?php echo esc_attr($title); ?>">
   <div class="research-shell">
    <div class="research-grid">
     <?php if ($query->have_posts()) : while ($query->have_posts()) : $query->the_post(); ?><article class="research-card"><p class="research-card-kicker"><?php echo esc_html(eduardo_research_t('insight')); ?></p><h2><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2><?php if (has_excerpt()) : ?><p><?php echo esc_html(get_the_excerpt()); ?></p><?php endif; ?></article><?php endwhile; else : ?><article class="research-card research-empty"><h2><?php echo esc_html(eduardo_research_t('editorial_ready')); ?></h2><p><?php echo esc_html(eduardo_research_t('editorial_ready_body')); ?></p></article><?php endif; wp_reset_postdata(); ?>
    </div>
   </div>
  </section>
 <?php endif; ?>
</main>
<?php get_footer();
