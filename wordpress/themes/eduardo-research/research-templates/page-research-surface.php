<?php
/** Generic Theme-owned renderer for controlled Research pages. */
declare(strict_types=1);
if (! defined('ABSPATH')) { exit; }
$key = eduardo_research_current_page_key();
$contract = null === $key ? null : eduardo_research_page_contract($key);
if (null === $key || null === $contract) { get_template_part('index'); return; }
get_header();
$title = get_the_title();
$descriptions = array(
 'about' => 'Researcher profile, background, verified identity and professional context.',
 'research' => 'Research agenda, active lines, questions, methods and reproducible work.',
 'publications' => 'Scholarly and research outputs with explicit status, provenance and citation guidance.',
 'projects' => 'Research projects, objectives, methods, collaborators and verifiable outcomes.',
 'software' => 'Research software and technical artefacts supporting reproducible work.',
 'datasets' => 'Research datasets with provenance, scope, access conditions and reuse context.',
 'cv' => 'Academic and research curriculum presented from verified structured evidence.',
 'insights' => 'Research notes, explainers and working ideas published inside a Theme-owned editorial surface.',
 'contact' => 'Research contact channels and verified academic profile identifiers.',
);
?>
<main id="main" tabindex="-1">
 <header class="research-shell research-hero">
  <div class="research-eyebrow"><?php echo esc_html((string) $contract['role']); ?></div>
  <h1 class="research-title"><?php echo esc_html($title); ?></h1>
  <p class="research-lead"><?php echo esc_html($descriptions[$key] ?? 'Research profile surface.'); ?></p>
 </header>
 <section class="research-section">
  <div class="research-shell">
   <?php if (in_array($key, array('publications','projects','software','datasets'), true)) :
     $map = array('publications'=>'research_output','projects'=>'research_project','software'=>'research_software','datasets'=>'research_dataset');
     $query = new WP_Query(array('post_type'=>$map[$key], 'post_status'=>'publish', 'posts_per_page'=>24)); ?>
     <div class="research-grid">
      <?php if ($query->have_posts()) : while ($query->have_posts()) : $query->the_post(); ?>
       <article class="research-card"><h2><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2><?php if (has_excerpt()) : ?><p><?php echo esc_html(get_the_excerpt()); ?></p><?php endif; ?></article>
      <?php endwhile; else : ?><article class="research-card"><h2>Structured index ready</h2><p>Verified research records will appear here without changing the page composition.</p></article><?php endif; wp_reset_postdata(); ?>
     </div>
   <?php elseif ('insights' === $key) :
     $query = new WP_Query(array('post_type'=>'post','post_status'=>'publish','posts_per_page'=>12)); ?>
     <div class="research-grid"><?php while ($query->have_posts()) : $query->the_post(); ?><article class="research-card"><h2><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2><p><?php echo esc_html(get_the_excerpt()); ?></p></article><?php endwhile; wp_reset_postdata(); ?></div>
   <?php else : ?>
     <div class="research-card"><p>This surface is controlled by the Research Theme and is ready for evidence-based hydration by the Research Manager.</p></div>
   <?php endif; ?>
  </div>
 </section>
</main>
<?php get_footer();
