<footer class="research-site-footer" role="contentinfo">
 <div class="research-shell research-footer-inner">
  <a class="research-brand" href="<?php echo esc_url(eduardo_research_page_url('home')); ?>" rel="home">
   <span class="research-brand-mark" aria-hidden="true">EY</span>
   <span class="research-brand-name"><?php bloginfo('name'); ?></span>
  </a>
  <nav class="research-footer-nav" aria-label="<?php echo esc_attr(eduardo_research_t('legal_nav')); ?>">
   <?php $preset = eduardo_research_preset(); foreach ($preset['footer_navigation'] as $key) : ?>
    <a href="<?php echo esc_url(eduardo_research_page_url((string) $key)); ?>"><?php echo esc_html(eduardo_research_page_label((string) $key)); ?></a>
   <?php endforeach; ?>
  </nav>
  <p>&copy; <?php echo esc_html(wp_date('Y')); ?> <?php bloginfo('name'); ?></p>
 </div>
</footer>
<?php wp_footer(); ?>
</body>
</html>
