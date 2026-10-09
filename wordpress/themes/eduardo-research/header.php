<!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php wp_head(); ?>
</head>
<body <?php body_class('seo-geo-preset-research'); ?>>
<?php wp_body_open(); ?>
<a class="screen-reader-text" href="#main"><?php esc_html_e('Skip to content', 'eduardo-research'); ?></a>
<header class="research-site-header" role="banner">
 <div class="research-shell research-header-inner">
  <a class="research-brand" href="<?php echo esc_url(home_url('/')); ?>" rel="home"><?php bloginfo('name'); ?></a>
  <nav class="research-nav" aria-label="<?php esc_attr_e('Primary navigation', 'eduardo-research'); ?>">
   <?php $preset = eduardo_research_preset(); foreach ($preset['primary_navigation'] as $key) : $page = $preset['pages'][$key]; ?>
    <a href="<?php echo esc_url(home_url('/' . $page['slug'] . '/')); ?>"><?php echo esc_html($page['label']); ?></a>
   <?php endforeach; ?>
  </nav>
 </div>
</header>
