<?php
/** Theme-owned renderer for controlled Research pages. */
declare(strict_types=1);
if (! defined('ABSPATH')) { exit; }
$key = eduardo_research_current_page_key();
$contract = null === $key ? null : eduardo_research_page_contract($key);
if (null === $key || null === $contract) { get_template_part('index'); return; }
$model = eduardo_research_surface_model($key);
get_header();
$title = get_the_title() ?: (string) ($contract['label'] ?? ucfirst($key));
$evidence_groups = eduardo_research_surface_evidence_groups($key);
?>
<main id="main" tabindex="-1">
 <header class="research-shell research-hero research-surface-hero">
  <div class="research-eyebrow"><?php echo esc_html((string) ($model['eyebrow'] ?? $contract['role'])); ?></div>
  <h1 class="research-title"><?php echo esc_html($title); ?></h1>
  <p class="research-lead"><?php echo esc_html((string) ($model['lead'] ?? '')); ?></p>
 </header>
 <section class="research-section" aria-label="<?php echo esc_attr($title); ?>">
  <div class="research-shell">
   <?php if (in_array($key, array('publications','projects','software','datasets'), true)) :
     $map = array('publications'=>'research_output','projects'=>'research_project','software'=>'research_software','datasets'=>'research_dataset');
     $query = new WP_Query(array('post_type'=>$map[$key], 'post_status'=>'publish', 'posts_per_page'=>24, 'no_found_rows'=>true)); ?>
     <div class="research-grid">
      <?php if ($query->have_posts()) : while ($query->have_posts()) : $query->the_post(); ?>
       <article class="research-card"><p class="research-card-kicker"><?php echo esc_html((string) $contract['role']); ?></p><h2><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2><?php if (has_excerpt()) : ?><p><?php echo esc_html(get_the_excerpt()); ?></p><?php endif; ?></article>
      <?php endwhile; else : ?><article class="research-card research-empty"><h2>Evidence-ready index</h2><p>No research records have been published yet. The Theme keeps this surface structurally complete without inventing research claims.</p></article><?php endif; wp_reset_postdata(); ?>
     </div>
   <?php elseif ('insights' === $key) :
     $query = new WP_Query(array('post_type'=>'post','post_status'=>'publish','posts_per_page'=>12,'no_found_rows'=>true)); ?>
     <div class="research-grid">
      <?php if ($query->have_posts()) : while ($query->have_posts()) : $query->the_post(); ?><article class="research-card"><p class="research-card-kicker">Insight</p><h2><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2><?php if (has_excerpt()) : ?><p><?php echo esc_html(get_the_excerpt()); ?></p><?php endif; ?></article><?php endwhile; else : ?><article class="research-card research-empty"><h2>Editorial surface ready</h2><p>Research notes will appear here when published.</p></article><?php endif; wp_reset_postdata(); ?>
     </div>
   <?php elseif ($evidence_groups) : ?>
     <div class="research-grid research-grid-sections">
      <?php foreach ($evidence_groups as $group => $label) : $records = eduardo_research_verified_evidence((string) $group); ?>
       <section class="research-card research-evidence-group">
        <p class="research-card-kicker">Verified evidence</p>
        <h2><?php echo esc_html((string) $label); ?></h2>
        <?php if ($records) : ?>
         <div class="research-evidence-records">
          <?php foreach ($records as $record) : $url = isset($record['url']) && is_scalar($record['url']) ? esc_url((string) $record['url']) : ''; ?>
           <article class="research-evidence-record">
            <h3><?php echo esc_html((string) ($record['title'] ?? $record['label'] ?? $record['value'] ?? $label)); ?></h3>
            <?php if (! empty($record['summary'])) : ?><p><?php echo esc_html((string) $record['summary']); ?></p><?php elseif (! empty($record['value']) && ($record['value'] !== ($record['title'] ?? null))) : ?><p><?php echo esc_html((string) $record['value']); ?></p><?php endif; ?>
            <?php if ('' !== $url) : ?><a href="<?php echo $url; ?>" rel="noopener">Open source</a><?php endif; ?>
           </article>
          <?php endforeach; ?>
         </div>
        <?php else : ?>
         <p class="research-evidence-empty">No verified evidence is published in this section yet.</p>
        <?php endif; ?>
       </section>
      <?php endforeach; ?>
     </div>
   <?php else : ?>
     <div class="research-grid research-grid-sections">
      <?php foreach ((array) ($model['sections'] ?? array()) as $section) : ?>
       <section class="research-card"><h2><?php echo esc_html((string) $section); ?></h2><p>This Theme-owned section is ready for structured content.</p></section>
      <?php endforeach; ?>
     </div>
   <?php endif; ?>
  </div>
 </section>
</main>
<?php get_footer();
