<?php
/** Theme-owned Research front page. */
declare(strict_types=1);
if (! defined('ABSPATH')) { exit; }
get_header();
$language = eduardo_research_current_language();
$model = eduardo_research_model();
$recent_outputs = new WP_Query(eduardo_research_localized_query_args(array(
    'post_type'=>array('research_output','research_project','research_software','research_dataset'),
    'post_status'=>'publish','posts_per_page'=>6,'orderby'=>'modified','order'=>'DESC','no_found_rows'=>true,
), $language));
$insights = new WP_Query(eduardo_research_localized_query_args(array(
    'post_type'=>'post','post_status'=>'publish','posts_per_page'=>3,'orderby'=>'date','order'=>'DESC','no_found_rows'=>true,
), $language));
$research_lines = eduardo_research_verified_localized_evidence('research_lines');
$identifiers = eduardo_research_verified_localized_evidence('identifiers');
$identity_lead = 'es' === $language
    ? 'Los identificadores académicos y las afiliaciones solo se muestran cuando su estado de evidencia está verificado.'
    : 'Academic identifiers and affiliations are exposed only when their evidence state is verified.';
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
    <a class="research-text-link" href="<?php echo esc_url(eduardo_research_page_url('cv')); ?>"><?php echo esc_html(eduardo_research_t('view_cv')); ?> <span aria-hidden="true">↗</span></a>
   </div>
   <div class="research-capabilities">
    <span><?php echo esc_html(eduardo_research_t('evidence_aware')); ?></span><span><?php echo esc_html(eduardo_research_t('machine_readable')); ?></span><span><?php echo esc_html(eduardo_research_t('repro_first')); ?></span>
   </div>
  </div>
  <div class="research-hero-visual" aria-hidden="true">
   <div class="research-orbit-card">
    <div class="research-orbit-meta"><span><?php echo esc_html(eduardo_research_t('research_system')); ?></span><span>01 / 04</span></div>
    <svg class="research-orbit" viewBox="0 0 520 520" role="presentation" focusable="false">
     <circle class="orbit-line orbit-line-a" cx="260" cy="260" r="190"/><circle class="orbit-line orbit-line-b" cx="260" cy="260" r="132"/><circle class="orbit-line orbit-line-c" cx="260" cy="260" r="74"/>
     <path class="orbit-axis" d="M72 260H448M260 72V448"/><circle class="orbit-core" cx="260" cy="260" r="24"/>
     <circle class="orbit-node" cx="260" cy="70" r="8"/><circle class="orbit-node" cx="447" cy="260" r="8"/><circle class="orbit-node" cx="260" cy="392" r="8"/><circle class="orbit-node" cx="128" cy="260" r="8"/>
     <text class="orbit-text" x="260" y="44" text-anchor="middle"><?php echo esc_html(strtoupper(eduardo_research_t('research'))); ?></text>
     <text class="orbit-text" x="474" y="265"><?php echo esc_html(strtoupper(eduardo_research_t('software'))); ?></text>
     <text class="orbit-text" x="260" y="432" text-anchor="middle"><?php echo esc_html(strtoupper(eduardo_research_t('datasets'))); ?></text>
     <text class="orbit-text" x="42" y="265"><?php echo esc_html(strtoupper(eduardo_research_t('evidence'))); ?></text>
    </svg>
    <div class="research-orbit-caption"><span><?php echo esc_html(eduardo_research_t('structured_knowledge')); ?></span><span><?php echo esc_html(eduardo_research_t('human_machine')); ?></span></div>
   </div>
  </div>
 </section>

 <section class="research-section research-section-bordered research-section-soft" aria-labelledby="research-agenda">
  <div class="research-shell">
   <div class="research-section-heading"><div><span class="research-section-number">01</span><div class="research-eyebrow"><?php echo esc_html(eduardo_research_t('research')); ?></div><h2 id="research-agenda"><?php echo esc_html($model['research-lines-heading']); ?></h2></div><p class="research-lead"><?php echo esc_html($model['research-lines-intro']); ?></p></div>
   <div class="research-grid research-grid-editorial">
    <?php if ($research_lines) : foreach (array_slice($research_lines, 0, 6) as $line) : ?>
     <article class="research-card research-card-feature"><p class="research-card-kicker"><?php echo esc_html('es' === $language ? 'Línea de investigación verificada' : 'Verified research line'); ?></p><h3><?php echo esc_html((string) ($line['title'] ?? $line['label'] ?? ('es' === $language ? 'Línea de investigación' : 'Research line'))); ?></h3><?php if (! empty($line['summary'])) : ?><p><?php echo esc_html((string) $line['summary']); ?></p><?php endif; ?><span class="research-card-arrow" aria-hidden="true">↗</span></article>
    <?php endforeach; else : ?>
     <article class="research-card research-empty research-empty-wide"><p class="research-card-kicker"><?php echo esc_html(eduardo_research_t('evidence_ready')); ?></p><h3><?php echo esc_html(eduardo_research_t('research_ready_title')); ?></h3><p><?php echo esc_html(eduardo_research_t('research_ready_body')); ?></p><div class="research-empty-signal" aria-hidden="true"><span></span><span></span><span></span><span></span></div></article>
    <?php endif; ?>
   </div>
  </div>
 </section>

 <section class="research-section" aria-labelledby="selected-outputs"><div class="research-shell">
  <div class="research-section-heading"><div><span class="research-section-number">02</span><div class="research-eyebrow"><?php echo esc_html(eduardo_research_t('outputs')); ?></div><h2 id="selected-outputs"><?php echo esc_html($model['selected-outputs-heading']); ?></h2></div><p><?php echo esc_html($model['selected-outputs-intro']); ?></p></div>
  <div class="research-grid research-collection-grid">
   <?php if ($recent_outputs->have_posts()) : while ($recent_outputs->have_posts()) : $recent_outputs->the_post(); $type = get_post_type_object(get_post_type()); ?>
    <article class="research-card research-card-output"><p class="research-card-kicker"><?php echo esc_html((string) ($type->labels->singular_name ?? 'Research')); ?></p><h3><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h3><?php if (has_excerpt()) : ?><p><?php echo esc_html(get_the_excerpt()); ?></p><?php endif; ?><span class="research-card-arrow" aria-hidden="true">↗</span></article>
   <?php endwhile; else : $collections=array('publications','projects','software','datasets'); foreach ($collections as $i=>$key) : $label=eduardo_research_page_label($key); ?>
    <article class="research-card research-collection-card"><div class="research-collection-index"><?php echo esc_html(str_pad((string) ($i+1),2,'0',STR_PAD_LEFT)); ?></div><p class="research-card-kicker"><?php echo esc_html(eduardo_research_t('research_collection')); ?></p><h3><?php echo esc_html($label); ?></h3><a href="<?php echo esc_url(eduardo_research_page_url($key)); ?>"><?php echo esc_html(eduardo_research_t('explore') . ' ' . strtolower($label)); ?> <span aria-hidden="true">↗</span></a></article>
   <?php endforeach; endif; wp_reset_postdata(); ?>
  </div>
 </div></section>

 <section class="research-section research-identity-section" aria-labelledby="academic-identity"><div class="research-shell research-split research-identity-layout">
  <div><span class="research-section-number">03</span><div class="research-eyebrow"><?php echo esc_html(eduardo_research_t('identity')); ?></div><h2 id="academic-identity"><?php echo esc_html($model['academic-identifiers-heading']); ?></h2><p class="research-lead"><?php echo esc_html($identity_lead); ?></p></div>
  <?php if ($identifiers) : ?><div class="research-evidence-list"><?php foreach (array_slice($identifiers,0,6) as $identifier) : $url=isset($identifier['url'])?esc_url((string)$identifier['url']):''; ?>
   <article class="research-card research-identity-card"><p class="research-card-kicker"><?php echo esc_html(eduardo_research_t('verified_identifier')); ?></p><h3><?php echo esc_html((string)($identifier['label']??$identifier['title']??('es'===$language?'Perfil académico':'Academic profile'))); ?></h3><?php if(!empty($identifier['value'])):?><p><?php echo esc_html((string)$identifier['value']);?></p><?php endif;?><?php if(''!==$url):?><a href="<?php echo $url;?>" rel="me noopener"><?php echo esc_html(eduardo_research_t('open_verified_profile')); ?> <span aria-hidden="true">↗</span></a><?php endif;?></article>
  <?php endforeach;?></div><?php else: ?>
   <div class="research-verification-card"><div class="research-verification-mark" aria-hidden="true">✓</div><p class="research-card-kicker"><?php echo esc_html(eduardo_research_t('verification_policy'));?></p><h3><?php echo esc_html(eduardo_research_t('no_fabricated'));?></h3><p><?php echo esc_html(eduardo_research_t('no_fabricated_body'));?></p><div class="research-verification-rule"><span><?php echo esc_html(eduardo_research_t('evidence'));?></span><span><?php echo esc_html(eduardo_research_t('review'));?></span><span><?php echo esc_html(eduardo_research_t('publish'));?></span></div></div>
  <?php endif;?>
 </div></section>

 <section class="research-section research-section-soft" aria-labelledby="latest-insights"><div class="research-shell">
  <div class="research-section-heading"><div><span class="research-section-number">04</span><div class="research-eyebrow"><?php echo esc_html(eduardo_research_t('editorial'));?></div><h2 id="latest-insights"><?php echo esc_html($model['latest-insights-heading']);?></h2></div><a class="research-text-link" href="<?php echo esc_url(eduardo_research_page_url('insights'));?>"><?php echo esc_html(eduardo_research_t('all_insights'));?> <span aria-hidden="true">↗</span></a></div>
  <div class="research-grid research-insights-grid"><?php if($insights->have_posts()):while($insights->have_posts()):$insights->the_post();?><article class="research-card research-card-insight"><p class="research-card-kicker"><?php echo esc_html(get_the_date());?></p><h3><a href="<?php the_permalink();?>"><?php the_title();?></a></h3><?php if(has_excerpt()):?><p><?php echo esc_html(get_the_excerpt());?></p><?php endif;?><span class="research-card-arrow" aria-hidden="true">↗</span></article><?php endwhile;else:?><article class="research-card research-empty research-empty-wide"><p class="research-card-kicker"><?php echo esc_html(eduardo_research_t('editorial_system'));?></p><h3><?php echo esc_html(eduardo_research_t('notes_ready'));?></h3><p><?php echo esc_html(eduardo_research_t('notes_ready_body'));?></p><div class="research-empty-lines" aria-hidden="true"><span></span><span></span><span></span></div></article><?php endif;wp_reset_postdata();?></div>
 </div></section>

 <section class="research-section research-cta" aria-labelledby="research-collaboration"><div class="research-shell research-cta-inner"><div><span class="research-section-number">05</span><div class="research-eyebrow"><?php echo esc_html(eduardo_research_t('contact'));?></div><h2 id="research-collaboration"><?php echo esc_html($model['final-cta-heading']);?></h2><p class="research-lead"><?php echo esc_html($model['final-cta-body']);?></p></div><div class="research-actions"><a class="research-button research-button-light" href="<?php echo esc_url(eduardo_research_page_url('contact'));?>"><?php echo esc_html(eduardo_research_t('start_conversation'));?> <span aria-hidden="true">↗</span></a></div></div></section>
</main>
<?php get_footer();
