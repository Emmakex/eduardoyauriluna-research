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
$research_lines = eduardo_research_verified_evidence('research_lines');
$identifiers = eduardo_research_verified_evidence('identifiers');
?>
<main id="main" tabindex="-1">
 <section class="research-shell research-hero research-hero-premium" aria-labelledby="research-title">
  <div class="research-hero-copy">
   <div class="research-eyebrow"><?php echo esc_html($model['hero-eyebrow']); ?></div>
   <h1 id="research-title" class="research-title"><?php echo esc_html($model['researcher-name']); ?></h1>
   <p class="research-lead research-headline"><?php echo esc_html($model['researcher-headline']); ?></p>
   <p class="research-lead"><?php echo esc_html($model['hero-lead']); ?></p>
   <div class="research-actions">
    <a class="research-button" href="<?php echo esc_url(eduardo_research_page_url('research')); ?>"><?php echo esc_html($model['final-cta-button']); ?></a>
    <a class="research-text-link" href="<?php echo esc_url(eduardo_research_page_url('cv')); ?>">View CV <span aria-hidden="true">↗</span></a>
   </div>
   <div class="research-capabilities" aria-label="Research website capabilities">
    <span>Evidence-aware</span><span>Machine-readable</span><span>Reproducibility-first</span>
   </div>
  </div>
  <div class="research-hero-visual" aria-hidden="true">
   <div class="research-orbit-card">
    <div class="research-orbit-meta"><span>Research system</span><span>01 / 04</span></div>
    <svg class="research-orbit" viewBox="0 0 520 520" role="presentation" focusable="false">
     <circle class="orbit-line orbit-line-a" cx="260" cy="260" r="190"/>
     <circle class="orbit-line orbit-line-b" cx="260" cy="260" r="132"/>
     <circle class="orbit-line orbit-line-c" cx="260" cy="260" r="74"/>
     <path class="orbit-axis" d="M72 260H448M260 72V448"/>
     <circle class="orbit-core" cx="260" cy="260" r="24"/>
     <circle class="orbit-node" cx="260" cy="70" r="8"/>
     <circle class="orbit-node" cx="447" cy="260" r="8"/>
     <circle class="orbit-node" cx="260" cy="392" r="8"/>
     <circle class="orbit-node" cx="128" cy="260" r="8"/>
     <text class="orbit-text" x="260" y="44" text-anchor="middle">RESEARCH</text>
     <text class="orbit-text" x="474" y="265">SOFTWARE</text>
     <text class="orbit-text" x="260" y="432" text-anchor="middle">DATASETS</text>
     <text class="orbit-text" x="42" y="265">EVIDENCE</text>
    </svg>
    <div class="research-orbit-caption"><span>Structured knowledge</span><span>Human + machine discovery</span></div>
   </div>
  </div>
 </section>

 <section class="research-section research-section-bordered research-section-soft" aria-labelledby="research-agenda">
  <div class="research-shell">
   <div class="research-section-heading">
    <div><span class="research-section-number">01</span><div class="research-eyebrow">Research</div><h2 id="research-agenda"><?php echo esc_html($model['research-lines-heading']); ?></h2></div>
    <p class="research-lead"><?php echo esc_html($model['research-lines-intro']); ?></p>
   </div>
   <div class="research-grid research-grid-editorial">
    <?php if ($research_lines) : foreach (array_slice($research_lines, 0, 6) as $line) : ?>
     <article class="research-card research-card-feature"><p class="research-card-kicker">Verified research line</p><h3><?php echo esc_html((string) ($line['title'] ?? $line['label'] ?? 'Research line')); ?></h3><?php if (! empty($line['summary'])) : ?><p><?php echo esc_html((string) $line['summary']); ?></p><?php endif; ?><span class="research-card-arrow" aria-hidden="true">↗</span></article>
    <?php endforeach; else : ?>
     <article class="research-card research-empty research-empty-wide"><p class="research-card-kicker">Evidence-ready</p><h3>Research lines are ready for verified content</h3><p>The Theme reserves the semantic structure without publishing unsupported research claims.</p><div class="research-empty-signal" aria-hidden="true"><span></span><span></span><span></span><span></span></div></article>
    <?php endif; ?>
   </div>
  </div>
 </section>

 <section class="research-section" aria-labelledby="selected-outputs">
  <div class="research-shell">
   <div class="research-section-heading"><div><span class="research-section-number">02</span><div class="research-eyebrow">Outputs</div><h2 id="selected-outputs"><?php echo esc_html($model['selected-outputs-heading']); ?></h2></div><p><?php echo esc_html($model['selected-outputs-intro']); ?></p></div>
   <div class="research-grid research-collection-grid">
    <?php if ($recent_outputs->have_posts()) : while ($recent_outputs->have_posts()) : $recent_outputs->the_post(); $type = get_post_type_object(get_post_type()); ?>
     <article class="research-card research-card-output"><p class="research-card-kicker"><?php echo esc_html((string) ($type->labels->singular_name ?? 'Research')); ?></p><h3><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h3><?php if (has_excerpt()) : ?><p><?php echo esc_html(get_the_excerpt()); ?></p><?php endif; ?><span class="research-card-arrow" aria-hidden="true">↗</span></article>
    <?php endwhile; else : ?>
     <?php foreach (array('publications'=>'Publications','projects'=>'Projects','software'=>'Software','datasets'=>'Datasets') as $key=>$label) : ?><article class="research-card research-collection-card"><div class="research-collection-index"><?php echo esc_html(str_pad((string) (array_search($key, array('publications','projects','software','datasets'), true) + 1), 2, '0', STR_PAD_LEFT)); ?></div><p class="research-card-kicker">Research collection</p><h3><?php echo esc_html($label); ?></h3><a href="<?php echo esc_url(eduardo_research_page_url($key)); ?>">Explore <?php echo esc_html(strtolower($label)); ?> <span aria-hidden="true">↗</span></a></article><?php endforeach; ?>
    <?php endif; wp_reset_postdata(); ?>
   </div>
  </div>
 </section>

 <section class="research-section research-identity-section" aria-labelledby="academic-identity">
  <div class="research-shell research-split research-identity-layout">
   <div><span class="research-section-number">03</span><div class="research-eyebrow">Identity</div><h2 id="academic-identity"><?php echo esc_html($model['academic-identifiers-heading']); ?></h2><p class="research-lead">Academic identifiers and affiliations are exposed only when their evidence state is verified.</p></div>
   <?php if ($identifiers) : ?>
    <div class="research-evidence-list">
     <?php foreach (array_slice($identifiers, 0, 6) as $identifier) : $url = isset($identifier['url']) ? esc_url((string) $identifier['url']) : ''; ?>
      <article class="research-card research-identity-card"><p class="research-card-kicker">Verified identifier</p><h3><?php echo esc_html((string) ($identifier['label'] ?? $identifier['title'] ?? 'Academic profile')); ?></h3><?php if (! empty($identifier['value'])) : ?><p><?php echo esc_html((string) $identifier['value']); ?></p><?php endif; ?><?php if ('' !== $url) : ?><a href="<?php echo $url; ?>" rel="me noopener">Open verified profile <span aria-hidden="true">↗</span></a><?php endif; ?></article>
     <?php endforeach; ?>
    </div>
   <?php else : ?>
    <div class="research-verification-card"><div class="research-verification-mark" aria-hidden="true">✓</div><p class="research-card-kicker">Verification policy</p><h3>No fabricated identifiers</h3><p>ORCID, affiliations, degrees, metrics and similar claims remain unpublished until explicitly verified.</p><div class="research-verification-rule"><span>Evidence</span><span>Review</span><span>Publish</span></div></div>
   <?php endif; ?>
  </div>
 </section>

 <section class="research-section research-section-soft" aria-labelledby="latest-insights">
  <div class="research-shell">
   <div class="research-section-heading"><div><span class="research-section-number">04</span><div class="research-eyebrow">Editorial</div><h2 id="latest-insights"><?php echo esc_html($model['latest-insights-heading']); ?></h2></div><a class="research-text-link" href="<?php echo esc_url(eduardo_research_page_url('insights')); ?>">All insights <span aria-hidden="true">↗</span></a></div>
   <div class="research-grid research-insights-grid">
    <?php if ($insights->have_posts()) : while ($insights->have_posts()) : $insights->the_post(); ?>
     <article class="research-card research-card-insight"><p class="research-card-kicker"><?php echo esc_html(get_the_date()); ?></p><h3><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h3><?php if (has_excerpt()) : ?><p><?php echo esc_html(get_the_excerpt()); ?></p><?php endif; ?><span class="research-card-arrow" aria-hidden="true">↗</span></article>
    <?php endwhile; else : ?><article class="research-card research-empty research-empty-wide"><p class="research-card-kicker">Editorial system</p><h3>Research notes ready</h3><p>Published insights will appear here automatically.</p><div class="research-empty-lines" aria-hidden="true"><span></span><span></span><span></span></div></article><?php endif; wp_reset_postdata(); ?>
   </div>
  </div>
 </section>

 <section class="research-section research-cta" aria-labelledby="research-collaboration"><div class="research-shell research-cta-inner"><div><span class="research-section-number">05</span><div class="research-eyebrow">Contact</div><h2 id="research-collaboration"><?php echo esc_html($model['final-cta-heading']); ?></h2><p class="research-lead"><?php echo esc_html($model['final-cta-body']); ?></p></div><div class="research-actions"><a class="research-button research-button-light" href="<?php echo esc_url(eduardo_research_page_url('contact')); ?>">Start a conversation <span aria-hidden="true">↗</span></a></div></div></section>
</main>
<?php get_footer();
