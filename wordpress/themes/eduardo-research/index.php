<?php
/** Theme-owned public composition. */
declare(strict_types=1);
if (! defined('ABSPATH')) { exit; }
get_header();
$model = eduardo_research_model();
?>
<main id="main" tabindex="-1">
  <section class="research-shell research-hero" aria-labelledby="research-title">
    <div class="research-eyebrow"><?php echo esc_html($model['hero-eyebrow']); ?></div>
    <h1 id="research-title" class="research-title"><?php echo esc_html($model['researcher-name']); ?></h1>
    <p class="research-lead"><?php echo esc_html($model['researcher-headline']); ?></p>
    <p class="research-lead"><?php echo esc_html($model['hero-lead']); ?></p>
  </section>

  <section class="research-section" aria-labelledby="research-agenda">
    <div class="research-shell">
      <div class="research-eyebrow">Research</div>
      <h2 id="research-agenda"><?php echo esc_html($model['research-lines-heading']); ?></h2>
      <p class="research-lead"><?php echo esc_html($model['research-lines-intro']); ?></p>
    </div>
  </section>

  <section class="research-section" aria-labelledby="selected-outputs">
    <div class="research-shell">
      <h2 id="selected-outputs"><?php echo esc_html($model['selected-outputs-heading']); ?></h2>
      <p><?php echo esc_html($model['selected-outputs-intro']); ?></p>
      <div class="research-grid">
        <?php
        $cards = array(
          array('Publications', 'research_output'),
          array('Projects', 'research_project'),
          array('Software', 'research_software'),
          array('Datasets', 'research_dataset'),
        );
        foreach ($cards as $card) :
          $object = get_post_type_object($card[1]);
          $archive = $object && $object->has_archive ? get_post_type_archive_link($card[1]) : home_url('/');
        ?>
          <article class="research-card">
            <h3><?php echo esc_html($card[0]); ?></h3>
            <a href="<?php echo esc_url((string) $archive); ?>">Explore <?php echo esc_html(strtolower($card[0])); ?></a>
          </article>
        <?php endforeach; ?>
      </div>
    </div>
  </section>
</main>
<?php get_footer();
