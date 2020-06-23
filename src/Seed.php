<?php

namespace Drupal\quant;

use Drupal\quant\Event\QuantEvent;
use Drupal\quant\Event\NodeInsertEvent;
use Drupal\quant\Event\QuantFileEvent;
use Drupal\quant\Event\QuantRedirectEvent;

/**
 * Seed Manager.
 *
 * @todo define as a service and use dependency injection.
 */
class Seed {

  /**
   * Trigger export node via event dispatcher.
   */
  public static function exportNode($node, &$context) {
    $vid = $node->get('vid')->value;
    $message = "Processing {$node->title->value} (Revision: {$vid})";

    // Export via event dispatcher.
    \Drupal::service('event_dispatcher')->dispatch(NodeInsertEvent::NODE_INSERT_EVENT, new NodeInsertEvent($node));

    $results = [$node->nid->value];
    $context['message'] = $message;
    $context['results'][] = $results;
  }

  /**
   * Trigger export redirect via event dispatcher.
   */
  public static function exportRedirect($redirect, &$context) {
    $source = $redirect->getSourcePathWithQuery();
    $message = "Processing redirect: {$source}";

    // Export via event dispatcher.
    $source = $redirect->getSourcePathWithQuery();
    $destination = $redirect->getRedirectUrl()->toString();
    $statusCode = $redirect->getStatusCode();
    \Drupal::service('event_dispatcher')->dispatch(QuantRedirectEvent::UPDATE, new QuantRedirectEvent($source, $destination, $statusCode));

    $results = [$source];
    $context['message'] = $message;
    $context['results'][] = $results;
  }

  /**
   * Export arbitrary route (markup).
   */
  public static function exportRoute($route, &$context) {
    $message = "Processing route: {$route}";

    $markup = self::markupFromRoute($route);

    if (empty($markup)) {
      return;
    }

    $meta = [
      'info' => [
        'author' => '',
        'date_timestamp' => time(),
        'log' => '',
      ],
      'published' => TRUE,
      'transitions' => [],
    ];

    \Drupal::service('event_dispatcher')->dispatch(QuantEvent::OUTPUT, new QuantEvent($markup, $route, $meta));

    $context['message'] = $message;
    $context['results'][] = $route;
  }

  /**
   * Trigger export file via event dispatcher.
   */
  public static function exportFile($file, &$context) {
    $message = "Processing theme asset: " . basename($file);

    // Export via event dispatcher.
    if (file_exists(DRUPAL_ROOT . $file)) {
      \Drupal::service('event_dispatcher')->dispatch(QuantFileEvent::OUTPUT, new QuantFileEvent(DRUPAL_ROOT . $file, $file));
    }

    $results = [$file];
    $context['message'] = $message;
    $context['results'][] = $results;
  }

  /**
   *
   */
  public static function finishedSeedCallback($success, $results, $operations) {
    // The 'success' parameter means no fatal PHP errors were detected. All
    // other error management should be handled using 'results'.
    if ($success) {
      $message = \Drupal::translation()->formatPlural(
        count($results),
        'One item processed.', '@count items processed.'
      );
    }
    else {
      $message = t('Finished with an error.');
    }
    drupal_set_message($message);
  }

  /**
   * Find lunr assets.
   * This includes static output from the lunr module.
   */
  public static function findLunrAssets() {
    $filesPath = \Drupal::service('file_system')->realpath(file_default_scheme() . "://lunr_search");

    if (!is_dir($filesPath)) {
      $messenger = \Drupal::messenger();
      $messenger->addMessage('Lunr files not found. Ensure an index has been run.', $messenger::TYPE_WARNING);
      return [];
    }

    $files = [];
    foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($filesPath)) as $filename) {
      if ($filename->isDir()) {
        continue;
      }
      $files[] = str_replace(DRUPAL_ROOT, '', $filename->getPathname());
    }

    $files[] = '/' . drupal_get_path('module', 'lunr') . '/js/search.worker.js';
    $files[] = '/' . drupal_get_path('module', 'lunr') . '/js/vendor/lunr/lunr.min.js';

    return $files;
  }

  /**
   * Find lunr routes.
   * Determine URLs lunr indexes are exposed on.
   */
  public static function findLunrRoutes() {
    $lunr_storage = \Drupal::service('entity_type.manager')->getStorage('lunr_search');
    $routes = [];

    foreach ($lunr_storage->loadMultiple() as $search) {
      $routes[] = $search->getPath();
    }

    return $routes;
  }


  /**
   * Find redirects.
   * Return all existing redirects.
   */
  public static function findRedirects() {
    $redirects_storage = \Drupal::service('entity_type.manager')->getStorage('redirect');
    return $redirects_storage->loadMultiple();
  }

  /**
   * Find views routes.
   */
  public static function findViewRoutes() {
    $views_storage = \Drupal::service('entity_type.manager')->getStorage('view');
    $routes = [];

    $anon = \Drupal\user\Entity\User::getAnonymousUser();

    foreach ($views_storage->loadMultiple() as $view) {
      $v = \Drupal\views\Views::getView($view->get('id'));

      $displays = array_keys($v->storage->get('display'));

      foreach ($displays as $display) {
        $v->setDisplay($display);

        if ($v->access($display, $anon) && $path = $v->getPath()) {
          // Exclude contextual filters for now.
          if (strpos($path, '%') !== false) {
            continue;
          }

          $routes[] = "/".$path;
        }
      }
    }

    return $routes;
  }

  /**
   * Find theme assets.
   * Currently supports fonts: ttf/woff/otf, images: png/jpeg/svg.
   *
   * @todo: Make this configurable.
   */
  public static function findThemeAssets() {
    // @todo: Find path programatically
    // @todo: Support multiple themes (e.g site may have multiple themes changing by route).
    $config = \Drupal::config('system.theme');
    $themeName = $config->get('default');
    $themePath = DRUPAL_ROOT . '/themes/custom/' . $themeName;
    $filesPath = \Drupal::service('file_system')->realpath(file_default_scheme() . "://");

    if (!is_dir($themePath)) {
      echo "Theme dir does not exist"; die;
    }

    $files = [];

    $directoryIterator = new \RecursiveDirectoryIterator($themePath);
    $iterator = new \RecursiveIteratorIterator($directoryIterator);
    $regex = new \RegexIterator($iterator, '/^.+(.jpe?g|.png|.svg|.ttf|.woff|.otf)$/i', \RecursiveRegexIterator::GET_MATCH);

    foreach ($regex as $name => $r) {
      $files[] = str_replace(DRUPAL_ROOT, '', $name);
    }

    // Include all aggregated css/js files.
    $iterator = new \AppendIterator();

    if (is_dir($filesPath . '/css')) {
      $directoryIteratorCss = new \RecursiveDirectoryIterator($filesPath . '/css');
      $iterator->append(new \RecursiveIteratorIterator($directoryIteratorCss));
    }

    if (is_dir($filesPath . '/js')) {
      $directoryIteratorJs = new \RecursiveDirectoryIterator($filesPath . '/js');
      $iterator->append(new \RecursiveIteratorIterator($directoryIteratorJs));
    }

    foreach ($iterator as $fileInfo) {
      $files[] = str_replace(DRUPAL_ROOT, '', $fileInfo->getPathname());
    }

    return $files;
  }

  /**
   * Add/update redirect via API request.
   */
  public static function seedRedirect($redirect) {
    $source = $redirect->getSourcePathWithQuery();
    $destination = $redirect->getRedirectUrl()->toString();
    $statusCode = $redirect->getStatusCode();
    \Drupal::service('event_dispatcher')->dispatch(QuantRedirectEvent::UPDATE, new QuantRedirectEvent($source, $destination, $statusCode));
  }

  /**
   * Delete existing redirects via API request.
   */
  public static function deleteRedirect($redirect) {
    $source = $redirect->getSourcePathWithQuery();
    $destination = $redirect->getRedirectUrl()->toString();
    // @todo: Add event dispatch.
  }

  /**
   * Trigger an internal http request to retrieve node markup.
   * Seeds an individual node update to Quant.
   */
  public static function seedNode($entity) {

    $nid = $entity->get('nid')->value;
    $rid = $entity->get('vid')->value;
    $url = $entity->toUrl()->toString();

    // Special case for home-page, rewrite alias to /.
    $site_config = \Drupal::config('system.site');
    $front = $site_config->get('page.front');

    if ((strpos($front, '/node/') === 0) && $entity->get('nid')->value == substr($front, 6)) {
      $url = "/";
    }

    // Generate a request token.
    $token = \Drupal::service('quant.token_manager')->create($nid);

    $markup = self::markupFromRoute($url, [
      'quant_revision' => $rid,
      'quant_token' => $token,
    ]);
    $meta = [];

    if (empty($markup)) {
      return;
    }

    $metaManager = \Drupal::service('plugin.manager.quant.metadata');
    foreach ($metaManager->getDefinitions() as $pid => $def) {
      $plugin = $metaManager->createInstance($pid);
      if ($plugin->applies($entity)) {
        $meta = array_merge($meta, $plugin->build($entity));
      }
    }

    // This should get the entity alias.
    $url = $entity->toUrl()->toString();

    // Special case pages (403/404); 2x exports.
    // One for alias associated with page, one for "special" URLs.
    $site_config = \Drupal::config('system.site');

    $specialPages = [
      '/' => $site_config->get('page.front'),
      '/_quant404' => $site_config->get('page.404'),
      '/_quant403' => $site_config->get('page.403'),
    ];

    foreach ($specialPages as $k => $v) {
      if ((strpos($v, '/node/') === 0) && $entity->get('nid')->value == substr($v, 6)) {
        \Drupal::service('event_dispatcher')->dispatch(QuantEvent::OUTPUT, new QuantEvent($markup, $k, $meta, $rid));
      }
    }

    \Drupal::service('event_dispatcher')->dispatch(QuantEvent::OUTPUT, new QuantEvent($markup, $url, $meta, $rid));

  }

  /**
   * Returns markup for a given internal route.
   */
  protected function markupFromRoute($route, $query = []) {

    // Build internal request.
    $config = \Drupal::config('quant.settings');
    $local_host = $config->get('local_server') ?: 'http://localhost';
    $hostname = $config->get('host_domain') ?: $_SERVER['SERVER_NAME'];
    $url = $local_host . $route;

    // Support basic auth if enabled (note: will not work via drush/cli).
    $auth = !empty($_SERVER['PHP_AUTH_USER']) ? [$_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']] : [];

    // @todo; Note: Passing in the Host header fixes issues with absolute links.
    // It may also cause some redirects to the real host.
    // Best to trap redirects and re-run against the final path.
    $response = \Drupal::httpClient()->get($url . "?quant_revision=" . $rid, [
      'http_errors' => FALSE,
      'query' => $query,
      'headers' => [
        'Host' => $hostname,
      ],
      'auth' => $auth,
    ]);

    $markup = '';
    if ($response->getStatusCode() == 200) {
      $markup = self::removeQuantParams($response->getBody());
    }
    else {
      $markup = '';
      $messenger = \Drupal::messenger();
      $messenger->addMessage("Non-200 response for {$route}: " . $response->getStatusCode(), $messenger::TYPE_WARNING);
    }

    return $markup;

  }

  /**
   * Returns markup with quant params removed.
   */
  private function removeQuantParams($markup) {
    // Replace ?quant_revision=XX&quant_token=XX&additional_params with ?
    $markup = preg_replace('/\?quant_revision=(.*&)quant_token=(.*&)/i', '?', $markup);
    // Remove ?quant_revision=XX&quant_token=XX
    $markup = preg_replace("/\?quant_revision=(.*&)quant_token=[^\"']*/i", '', $markup);
    // Remove &quant_revision=XX&quant_token=XX with optional params
    $markup = preg_replace("/\&quant_revision=(.*&)quant_token=[^\"'&]*/i", '', $markup);

    // Replace ?quant_revision=XX&additional_params with ?
    $markup = preg_replace('/\?quant_revision=(.*&)/i', '?', $markup);
    // Remove ?quant_revision=XX
    $markup = preg_replace("/\?quant_revision=[^\"']*/i", '', $markup);
    // Remove &quant_revision=XX with optional params
    $markup = preg_replace("/\&quant_revision=[^\"'&]*/i", '', $markup);

    return $markup;
  }

}
