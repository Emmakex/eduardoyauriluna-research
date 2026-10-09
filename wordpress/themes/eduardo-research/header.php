<!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php wp_head(); ?>
</head>
<body <?php body_class('seo-geo-preset-research'); ?>>
<?php wp_body_open(); ?>
<a class="screen-reader-text" href="#main"><?php echo esc_html(eduardo_research_t('skip')); ?></a>
<header class="research-site-header" role="banner">
 <div class="research-shell research-header-inner">
  <a class="research-brand" href="<?php echo esc_url(eduardo_research_page_url('home')); ?>" rel="home">
   <span class="research-brand-mark" aria-hidden="true">EY</span>
   <span class="research-brand-name"><?php bloginfo('name'); ?></span>
  </a>
  <div class="research-header-actions">
   <nav class="research-nav" aria-label="<?php echo esc_attr(eduardo_research_t('primary_nav')); ?>">
    <?php $preset = eduardo_research_preset(); $current_key = eduardo_research_current_page_key(); foreach ($preset['primary_navigation'] as $key) : ?>
     <a href="<?php echo esc_url(eduardo_research_page_url((string) $key)); ?>"<?php echo $current_key === $key ? ' aria-current="page"' : ''; ?>><?php echo esc_html(eduardo_research_page_label((string) $key)); ?></a>
    <?php endforeach; ?>
   </nav>
   <nav class="research-language-switcher" aria-label="<?php echo esc_attr(eduardo_research_t('language_nav')); ?>">
    <?php $current_language = eduardo_research_current_language(); foreach (eduardo_research_languages() as $code => $language) : ?>
     <a hreflang="<?php echo esc_attr((string) $code); ?>" lang="<?php echo esc_attr((string) $code); ?>" href="<?php echo esc_url(eduardo_research_translation_url((string) $code)); ?>"<?php echo $current_language === $code ? ' aria-current="true"' : ''; ?>><?php echo esc_html((string) $language['short']); ?></a>
    <?php endforeach; ?>
   </nav>
  </div>
 </div>
</header>
