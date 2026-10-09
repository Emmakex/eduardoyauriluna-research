<footer class="research-site-footer" role="contentinfo">
 <div class="research-shell research-footer-inner">
  <div class="research-footer-brand">
   <span class="research-brand-mark" aria-hidden="true">EY</span>
   <div><strong><?php bloginfo('name'); ?></strong><p>Research, software and evidence-driven digital systems.</p></div>
  </div>
  <div class="research-footer-meta">
   <nav class="research-footer-nav" aria-label="<?php esc_attr_e('Legal navigation', 'eduardo-research'); ?>">
    <?php $preset = eduardo_research_preset(); foreach ($preset['footer_navigation'] as $key) : $page = $preset['pages'][$key]; ?>
     <a href="<?php echo esc_url(eduardo_research_page_url((string) $key)); ?>"><?php echo esc_html($page['label']); ?></a>
    <?php endforeach; ?>
   </nav>
   <p>&copy; <?php echo esc_html(wp_date('Y')); ?> <?php bloginfo('name'); ?></p>
  </div>
 </div>
</footer>
<?php wp_footer(); ?>
</body>
</html>
