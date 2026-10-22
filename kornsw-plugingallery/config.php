<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
  exit;
}

/*
 * Fixed, versioned favorites of the gallery (shown with a star).
 * Changes to this list are shipped deliberately via a plugin update.
 *
 * Supported entries:
 * - GitHub repository URL whose /doc folder contains one or more *.update.json files
 *   (the owner of every listed repository is also searched for further "WP-*" repositories,
 *   which are shown automatically as additional plugins)
 * - WordPress.org plugin URL (https://wordpress.org/plugins/<slug>/), installed via the WordPress installer
 * - direct URL of a WordPress-compatible *.update.json file
 */
return [
  'https://github.com/KornSW/WP-PluginGallery',
  'https://github.com/KornSW/WP-SimpleRedirect',
  'https://github.com/KornSW/WP-MemberSystem',
  'https://github.com/KornSW/WP-AuthTokenHandling',
  'https://github.com/KornSW/WP-kContactWizard',
  'https://github.com/KornSW/WP-FUSEfxHost',
  'https://github.com/KornSW/WP-KlicktippSync',
  'https://github.com/KornSW/WP-PayedStreams',
  'https://github.com/KornSW/WP-AutoGallery',
  'https://github.com/KornSW/WP-GenericPosts',
  'https://github.com/KornSW/WP-GitHuberMD',
  'https://github.com/KornSW/WP-EmbedMarkdown',
  'https://github.com/KornSW/WP-EmbedSwagger',
  'https://github.com/KornSW/WP-BeitragsCleaner',
  'https://github.com/KornSW/WP-Sitescan',
  'https://github.com/KornSW/WP-PubmedCollection',
  'https://github.com/KornSW/WP-Memberlist',
  'https://github.com/KornSW/WP-KnowledgeRepo',
  'https://github.com/KornSW/WP-RemoteArticles',

  // Favorites from the WordPress.org plugin directory, e.g.:
  'https://wordpress.org/plugins/wordfence/'



];
