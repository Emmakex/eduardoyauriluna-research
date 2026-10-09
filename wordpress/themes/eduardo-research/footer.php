<footer class="research-site-footer research-section" role="contentinfo">
 <div class="research-shell research-footer-inner">
  <p>&copy; <?php echo esc_html(wp_date('Y')); ?> <?php bloginfo('name'); ?></p>
  <nav class="research-footer-nav" aria-label="<?php esc_attr_e('Legal navigation', 'eduardo-research'); ?>">
   <?php $preset = eduardo_research_preset(); foreach ($preset['footer_navigation'] as $key) : $page = $preset['pages'][$key]; ?>
    <a href="<?php echo esc_url(home_url('/' . $page['slug'] . '/')); ?>"><?php echo esc_html($page['label']); ?></a>
   <?php endforeach; ?>
  </nav>
 </div>
</footer>
<?php wp_footer(); ?>
</body>
</html>
