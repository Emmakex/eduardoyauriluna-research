<?php
/** Theme-owned Research front page. */
declare(strict_types=1);
if (! defined('ABSPATH')) { exit; }
get_header();
$model = eduardo_research_model();
$recent_outputs = new WP_Query(array(
    'post_type' => array('research_output','research_project','research_software','research_dataset'),
    'post_status' => 'publish',
    'posts_per_page' => 6,
    'orderby' => 'modified',
    'order' => 'DESC',
    'no_found_rows' => true,
));
$insights = new WP_Query(array(
    'post_type' => 'post',
    'post_status' => 'publish',
    'posts_per_page' => 3,
    'orderby' => 'date',
    'order' => 'DESC',
    'no_found_rows' => true,
));
$research_lines = get_option('eduardo_research_lines', array());
$research_lines = is_array($research_lines) ? array_values(array_filter($research_lines, 'is_array')) : array();
?>
<main id="main" tabindex="-1">
 <section class="research-shell research-hero" aria-labelledby="research-title">
  <div class="research-eyebrow"><?php echo esc_html($model['hero-eyebrow']); ?></div>
  <h1 id="research-title" class="research-title"><?php echo esc_html($model['researcher-name']); ?></h1>
  <p class="research-lead research-headline"><?php echo esc_html($model['researcher-headline']); ?></p>
  <p class="research-lead"><?php echo esc_html($model['hero-lead']); ?></p>
  <div class="research-actions">
   <a class="research-button" href="<?php echo esc_url(eduardo_research_page_url('research')); ?>"><?php echo esc_html($model['final-cta-button']); ?></a>
   <a class="research-text-link" href="<?php echo esc_url(eduardo_research_page_url('cv')); ?>">View CV</a>
  </div>
 </section>

 <section class="research-section research-section-bordered" aria-labelledby="research-agenda">
  <div class="research-shell">
   <div class="research-section-heading">
    <div><div class="research-eyebrow">Research</div><h2 id="research-agenda"><?php echo esc_html($model['research-lines-heading']); ?></h2></div>
    <p class="research-lead"><?php echo esc_html($model['research-lines-intro']); ?></p>
   </div>
   <div class="research-grid">
    <?php if ($research_lines) : foreach (array_slice($research_lines, 0, 6) as $line) : ?>
     <article class="research-card"><p class="research-card-kicker">Research line</p><h3><?php echo esc_html((string) ($line['title'] ?? 'Research line')); ?></h3><?php if (! empty($line['summary'])) : ?><p><?php echo esc_html((string) $line['summary']); ?></p><?php endif; ?></article>
    <?php endforeach; else : ?>
     <article class="research-card research-empty"><p class="research-card-kicker">Evidence-ready</p><h3>Research lines are ready for verified content</h3><p>The Theme reserves the semantic structure without publishing unsupported research claims.</p></article>
    <?php endif; ?>
   </div>
  </div>
 </section>

 <section class="research-section" aria-labelledby="selected-outputs">
  <div class="research-shell">
   <div class="research-section-heading"><div><div class="research-eyebrow">Outputs</div><h2 id="selected-outputs"><?php echo esc_html($model['selected-outputs-heading']); ?></h2></div><p><?php echo esc_html($model['selected-outputs-intro']); ?></p></div>
   <div class="research-grid">
    <?php if ($recent_outputs->have_posts()) : while ($recent_outputs->have_posts()) : $recent_outputs->the_post(); $type = get_post_type_object(get_post_type()); ?>
     <article class="research-card"><p class="research-card-kicker"><?php echo esc_html((string) ($type->labels->singular_name ?? 'Research')); ?></p><h3><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h3><?php if (has_excerpt()) : ?><p><?php echo esc_html(get_the_excerpt()); ?></p><?php endif; ?></article>
    <?php endwhile; else : ?>
     <?php foreach (array('publications'=>'Publications','projects'=>'Projects','software'=>'Software','datasets'=>'Datasets') as $key=>$label) : ?><article class="research-card"><p class="research-card-kicker">Research collection</p><h3><?php echo esc_html($label); ?></h3><a href="<?php echo esc_url(eduardo_research_page_url($key)); ?>">Explore <?php echo esc_html(strtolower($label)); ?></a></article><?php endforeach; ?>
    <?php endif; wp_reset_postdata(); ?>
   </div>
  </div>
 </section>

 <section class="research-section research-section-bordered" aria-labelledby="academic-identity">
  <div class="research-shell research-split">
   <div><div class="research-eyebrow">Identity</div><h2 id="academic-identity"><?php echo esc_html($model['academic-identifiers-heading']); ?></h2><p>Academic identifiers and affiliations are exposed only when their evidence state is verified.</p></div>
   <div class="research-card research-empty"><p class="research-card-kicker">Verification policy</p><h3>No fabricated identifiers</h3><p>ORCID, affiliations, degrees, metrics and similar claims remain unpublished until explicitly verified.</p></div>
  </div>
 </section>

 <section class="research-section" aria-labelledby="latest-insights">
  <div class="research-shell">
   <div class="research-section-heading"><div><div class="research-eyebrow">Editorial</div><h2 id="latest-insights"><?php echo esc_html($model['latest-insights-heading']); ?></h2></div><a href="<?php echo esc_url(eduardo_research_page_url('insights')); ?>">All insights</a></div>
   <div class="research-grid">
    <?php if ($insights->have_posts()) : while ($insights->have_posts()) : $insights->the_post(); ?>
     <article class="research-card"><p class="research-card-kicker"><?php echo esc_html(get_the_date()); ?></p><h3><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h3><?php if (has_excerpt()) : ?><p><?php echo esc_html(get_the_excerpt()); ?></p><?php endif; ?></article>
    <?php endwhile; else : ?><article class="research-card research-empty"><h3>Research notes ready</h3><p>Published insights will appear here automatically.</p></article><?php endif; wp_reset_postdata(); ?>
   </div>
  </div>
 </section>

 <section class="research-section research-cta" aria-labelledby="research-collaboration"><div class="research-shell research-split"><div><div class="research-eyebrow">Contact</div><h2 id="research-collaboration"><?php echo esc_html($model['final-cta-heading']); ?></h2><p class="research-lead"><?php echo esc_html($model['final-cta-body']); ?></p></div><div class="research-actions"><a class="research-button" href="<?php echo esc_url(eduardo_research_page_url('contact')); ?>">Contact</a></div></div></section>
</main>
<?php get_footer();
