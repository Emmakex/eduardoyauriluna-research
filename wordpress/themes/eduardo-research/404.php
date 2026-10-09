<?php
/** Theme-owned 404 surface. */
declare(strict_types=1);
if (! defined('ABSPATH')) { exit; }
get_header(); ?>
<main id="main" tabindex="-1">
 <section class="research-shell research-hero research-surface-hero">
  <div class="research-eyebrow">404</div>
  <h1 class="research-title">Page not found</h1>
  <p class="research-lead">The requested research page does not exist or has moved.</p>
  <div class="research-actions"><a class="research-button" href="<?php echo esc_url(eduardo_research_page_url('home')); ?>">Return home</a><a class="research-text-link" href="<?php echo esc_url(eduardo_research_page_url('research')); ?>">Explore research</a></div>
 </section>
</main>
<?php get_footer();
