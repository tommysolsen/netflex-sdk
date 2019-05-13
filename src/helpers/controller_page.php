<?php

$real_url = explode('?', urldecode($_GET['_path']))[0];
$url = trim($real_url, '/');

if (strpos($url, '_/') === 0) {
  global $url_asset;
  $url_part = explode('/', $url);
  $url_asset[1] = array_pop($url_part);
  $token = $url_asset[1];

  if (NF::$jwt->verify($token)) {
    global $payload;
    $payload = NF::$jwt->decode($token);
    $controller = NF::nfPath('helpers/pagefinder/' . $payload->scope . '_' . $payload->relation . '.php');

    if (file_exists($controller)) {
      require $controller;
      die();
    }
  }

  http_response_code(401);
  die('Invalid or expired token');
}

if (empty($url)) {
  $url = 'index';
}

$url .= '/';

// Get full url for checking redirects
$fullUrl = ltrim($_SERVER['REQUEST_URI'], '/');

// Log url and full url
NF::debug($url, 'Path');
NF::debug($fullUrl, 'Full URI');

if ($url == 'CacheStore/') {
  if (isset($_GET['key'])) {
    if (NF::$cache->delete($_GET['key'])) {
      die('Key deleted');
    }

    die('Key does not exist');
  }

  die('Key is missing');
}

// Prepare url
$url_part = explode('/', $url);
$url_levels = count($url_part) - 1;
unset($url_part[$url_levels]);

// Check for redirects
$url_redirect = get_redirect($url, 'target_url');

if ($url_redirect !== 0) {
  header('Location: ' . $url_redirect . '', true, get_redirect($url, 'type'));
  die();
}

// Check for full url redirects
$url_redirect = get_redirect($fullUrl, 'target_url');

if ($url_redirect !== 0) {
  header('Location: ' . $url_redirect . '', true, get_redirect($fullUrl, 'type'));
  die();
}

// Regular redirects
switch ($url) {
  case 'sitemap.xml/':
    require_once(NF::nfPath('helpers/seo/sitemap.xml.php'));
    break;
  case 'sitemap.xsl/':
    require_once(NF::nfPath('helpers/seo/sitemap.xsl.php'));
    break;
  case 'robots.txt/':
    require_once(NF::nfPath('helpers/seo/robots.php'));
    break;
  default:
    break;
}

// Find page
$found_page = 0;
$process_url = $url;
$found_url_level = $url_levels;
$new_url_part = $url_part;

if (isset(NF::$config['domains']['default'])) {
  require_once(NF::nfPath('helpers/pagefinder/domainrouting.php'));
}

require_once(NF::nfPath('helpers/pagefinder/default.php'));
