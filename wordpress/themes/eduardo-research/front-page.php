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
$research_lines = new WP_Query(eduardo_research_verified_line_query_args($language, 6));
$identifiers = eduardo_research_verified_localized_evidence('identifiers');
$has_research_lines = $research_lines->have_posts();
$has_outputs = $recent_outputs->have_posts();
$has_insights = $insights->have_posts();
$hero_primary_target = sanitize_key((string) ($model['hero-primary-target'] ?? 'research')) ?: 'research';
$hero_secondary_target = sanitize_key((string) ($model['hero-secondary-target'] ?? 'cv')) ?: 'cv';
$final_cta_target = sanitize_key((string) ($model['final-cta-target'] ?? 'contact')) ?: 'contact';
$section_number = 1;
?>
<main id="main" tabindex="-1">
 <section class="research-shell research-hero research-hero-premium" aria-labelledby="research-title">
  <div class="research-hero-copy">
   <div class="research-eyebrow"><?php echo esc_html($model['hero-eyebrow']); ?></div>
   <h1 id="research-title" class="research-title"><?php echo esc_html($model['researcher-name']); ?></h1>
   <p class="research-lead research-headline"><?php echo esc_html($model['researcher-headline']); ?></p>
   <p class="research-lead"><?php echo esc_html($model['hero-lead']); ?></p>
   <div class="research-actions">
    <a class="research-button" href="<?php echo esc_url(eduardo_research_page_url($hero_primary_target)); ?>"><?php echo esc_html($model['final-cta-button']); ?></a>
    <a class="research-text-link" href="<?php echo esc_url(eduardo_research_page_url($hero_secondary_target)); ?>"><?php echo esc_html(eduardo_research_t('view_cv')); ?> <span aria-hidden="true">↗</span></a>
   </div>
   <div class="research-capabilities">
    <span><?php echo esc_html(eduardo_research_t('evidence_aware')); ?></span><span><?php echo esc_html(eduardo_research_t('machine_readable')); ?></span><span><?php echo esc_html(eduardo_research_t('repro_first')); ?></span>
   </div>
  </div>
  <div class="research-hero-visual" aria-hidden="true">
   <div class="research-orbit-card">
    <div class="research-orbit-meta"><span><?php echo esc_html(eduardo_research_t('research_agenda')); ?></span><span>01 / 04</span></div>
    <svg class="research-orbit" viewBox="0 0 520 520" role="presentation" focusable="false">
     <circle class="orbit-line orbit-line-a" cx="260" cy="260" r="190"/><circle class="orbit-line orbit-line-b" cx="260" cy="260" r="132"/><circle class="orbit-line orbit-line-c" cx="260" cy="260" r="74"/>
     <path class="orbit-axis" d="M72 260H448M260 72V448"/><circle class="orbit-core" cx="260" cy="260" r="24"/>
     <circle class="orbit-node" cx="260" cy="70" r="8"/><circle class="orbit-node" cx="447" cy="260" r="8"/><circle class="orbit-node" cx="260" cy="392" r="8"/><circle class="orbit-node" cx="128" cy="260" r="8"/>
     <text class="orbit-text" x="260" y="44" text-anchor="middle"><?php echo esc_html(strtoupper(eduardo_research_t('ai_agents'))); ?></text>
     <text class="orbit-text" x="474" y="265"><?php echo esc_html(strtoupper(eduardo_research_t('human_ai'))); ?></text>
     <text class="orbit-text" x="260" y="432" text-anchor="middle"><?php echo esc_html(strtoupper(eduardo_research_t('smes'))); ?></text>
     <text class="orbit-text" x="42" y="265"><?php echo esc_html(strtoupper(eduardo_research_t('ai_search'))); ?></text>
    </svg>
    <div class="research-orbit-caption"><span><?php echo esc_html(eduardo_research_t('applied_ai_systems')); ?></span><span><?php echo esc_html('Eduardo Jose Yauri Luna'); ?></span></div>
   </div>
  </div>
 </section>

 <?php eduardo_research_render_primary_media(0, 'home', $language); ?>

 <?php if ($has_research_lines) : ?>
 <section class="research-section research-section-bordered research-section-soft" aria-labelledby="research-agenda">
  <div class="research-shell">
   <div class="research-section-heading"><div><span class="research-section-number"><?php echo esc_html(str_pad((string) $section_number++, 2, '0', STR_PAD_LEFT)); ?></span><div class="research-eyebrow"><?php echo esc_html(eduardo_research_t('research')); ?></div><h2 id="research-agenda"><?php echo esc_html($model['research-lines-heading']); ?></h2></div><p class="research-lead"><?php echo esc_html($model['research-lines-intro']); ?></p></div>
   <div class="research-grid research-grid-editorial">
    <?php while ($research_lines->have_posts()) : $research_lines->the_post(); eduardo_research_render_research_line_card(get_the_ID()); endwhile; wp_reset_postdata(); ?>
   </div>
  </div>
 </section>
 <?php endif; ?>

 <?php if ($has_outputs) : ?>
 <section class="research-section" aria-labelledby="selected-outputs"><div class="research-shell">
  <div class="research-section-heading"><div><span class="research-section-number"><?php echo esc_html(str_pad((string) $section_number++, 2, '0', STR_PAD_LEFT)); ?></span><div class="research-eyebrow"><?php echo esc_html(eduardo_research_t('outputs')); ?></div><h2 id="selected-outputs"><?php echo esc_html($model['selected-outputs-heading']); ?></h2></div><p><?php echo esc_html($model['selected-outputs-intro']); ?></p></div>
  <div class="research-grid research-collection-grid">
   <?php while ($recent_outputs->have_posts()) : $recent_outputs->the_post(); $type = get_post_type_object(get_post_type()); ?>
    <article class="research-card research-card-output"><p class="research-card-kicker"><?php echo esc_html((string) ($type->labels->singular_name ?? 'Research')); ?></p><h3><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h3><?php if (has_excerpt()) : ?><p><?php echo esc_html(get_the_excerpt()); ?></p><?php endif; ?><span class="research-card-arrow" aria-hidden="true">↗</span></article>
   <?php endwhile; wp_reset_postdata(); ?>
  </div>
 </div></section>
 <?php endif; ?>

 <?php if ($identifiers) : ?>
 <section class="research-section research-identity-section" aria-labelledby="academic-identity"><div class="research-shell research-split research-identity-layout">
  <div><span class="research-section-number"><?php echo esc_html(str_pad((string) $section_number++, 2, '0', STR_PAD_LEFT)); ?></span><div class="research-eyebrow"><?php echo esc_html(eduardo_research_t('identity')); ?></div><h2 id="academic-identity"><?php echo esc_html($model['academic-identifiers-heading']); ?></h2><p class="research-lead"><?php echo esc_html('es' === $language ? 'Perfiles académicos e identificadores públicos de Eduardo Jose Yauri Luna.' : 'Public academic profiles and identifiers for Eduardo Jose Yauri Luna.'); ?></p></div>
  <div class="research-evidence-list"><?php foreach (array_slice($identifiers,0,6) as $identifier) : $url=isset($identifier['url'])?esc_url((string)$identifier['url']):''; ?>
   <article class="research-card research-identity-card"><p class="research-card-kicker"><?php echo esc_html(eduardo_research_t('verified_identifier')); ?></p><h3><?php echo esc_html((string)($identifier['label']??$identifier['title']??('es'===$language?'Perfil académico':'Academic profile'))); ?></h3><?php if(!empty($identifier['value'])):?><p><?php echo esc_html((string)$identifier['value']);?></p><?php endif;?><?php if(''!==$url):?><a href="<?php echo $url;?>" rel="me noopener"><?php echo esc_html(eduardo_research_t('open_verified_profile')); ?> <span aria-hidden="true">↗</span></a><?php endif;?></article>
  <?php endforeach;?></div>
 </div></section>
 <?php endif; ?>

 <?php if ($has_insights) : ?>
 <section class="research-section research-section-soft" aria-labelledby="latest-insights"><div class="research-shell">
  <div class="research-section-heading"><div><span class="research-section-number"><?php echo esc_html(str_pad((string) $section_number++, 2, '0', STR_PAD_LEFT)); ?></span><div class="research-eyebrow"><?php echo esc_html(eduardo_research_t('editorial'));?></div><h2 id="latest-insights"><?php echo esc_html($model['latest-insights-heading']);?></h2></div><a class="research-text-link" href="<?php echo esc_url(eduardo_research_page_url('insights'));?>"><?php echo esc_html(eduardo_research_t('all_insights'));?> <span aria-hidden="true">↗</span></a></div>
  <div class="research-grid research-insights-grid"><?php while($insights->have_posts()):$insights->the_post();?><article class="research-card research-card-insight"><p class="research-card-kicker"><?php echo esc_html(get_the_date());?></p><h3><a href="<?php the_permalink();?>"><?php the_title();?></a></h3><?php if(has_excerpt()):?><p><?php echo esc_html(get_the_excerpt());?></p><?php endif;?><span class="research-card-arrow" aria-hidden="true">↗</span></article><?php endwhile;wp_reset_postdata();?></div>
 </div></section>
 <?php endif; ?>

 <section class="research-section research-cta" aria-labelledby="research-collaboration"><div class="research-shell research-cta-inner"><div><span class="research-section-number"><?php echo esc_html(str_pad((string) $section_number, 2, '0', STR_PAD_LEFT)); ?></span><div class="research-eyebrow"><?php echo esc_html(eduardo_research_t('contact'));?></div><h2 id="research-collaboration"><?php echo esc_html($model['final-cta-heading']);?></h2><p class="research-lead"><?php echo esc_html($model['final-cta-body']);?></p></div><div class="research-actions"><a class="research-button research-button-light" href="<?php echo esc_url(eduardo_research_page_url($final_cta_target));?>"><?php echo esc_html(eduardo_research_t('start_conversation'));?> <span aria-hidden="true">↗</span></a></div></div></section>
</main>
<?php get_footer();
