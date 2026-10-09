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
<header class="research-shell" role="banner">
  <nav aria-label="<?php esc_attr_e('Primary navigation', 'eduardo-research'); ?>">
    <a href="<?php echo esc_url(home_url('/')); ?>"><?php bloginfo('name'); ?></a>
  </nav>
</header>
