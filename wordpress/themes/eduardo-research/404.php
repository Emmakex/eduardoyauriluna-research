<?php
/** Theme-owned 404 surface. */
declare(strict_types=1);
if (! defined('ABSPATH')) { exit; }
get_header();
$es = 'es' === eduardo_research_current_language();
?>
<main id="main" tabindex="-1">
 <section class="research-shell research-hero research-surface-hero">
  <div class="research-eyebrow">404</div>
  <h1 class="research-title"><?php echo esc_html($es ? 'Página no encontrada' : 'Page not found'); ?></h1>
  <p class="research-lead"><?php echo esc_html($es ? 'La página solicitada no existe o ha cambiado de ubicación.' : 'The requested page does not exist or has moved.'); ?></p>
  <div class="research-actions"><a class="research-button" href="<?php echo esc_url(eduardo_research_page_url('home')); ?>"><?php echo esc_html($es ? 'Volver al inicio' : 'Return home'); ?></a><a class="research-text-link" href="<?php echo esc_url(eduardo_research_page_url('research')); ?>"><?php echo esc_html($es ? 'Explorar la investigación' : 'Explore research'); ?></a></div>
 </section>
</main>
<?php get_footer();
