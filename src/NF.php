<?php

use Apility\WebpackAssets\WebpackAssets;
use Netflex\Site\JWT;
use GuzzleHttp\Client;
use Netflex\Site\Site;
use Netflex\Site\Util;
use Netflex\Site\Cache;
use Netflex\Site\Store;
use Netflex\Site\Console;
use Netflex\Site\Security;
use Netflex\Site\Commerce;
use Netflex\Site\ElasticSearch;
use Netflex\API;

class NF
{
  /** @var JWT */
  public static $jwt;

  /** @var string */
  public static $path;

  /** @var Site */
  public static $site;

  /** @var Client */
  public static $capi;

  /** @var Util */
  public static $util;

  /** @var Store */
  public static $store;

  /** @var Cache */
  public static $cache;

  /** @var ElasticSearch */
  public static $search;

  /** @var string */
  public static $branch;

  /** @var array[string]string */
  public static $config;

  /** @var array[string]string */
  public static $routes;

  /** @var string */
  public static $sitename;

  /** @var Security */
  public static $security;

  /** @var Commerce */
  public static $commerce;

  /** @var string */
  public static $cacheDir;

  /** @var string */
  public static $site_root;

  /** @var WebpackAssets */
  public static $webpackAssets;

  /**
   * Initializes a new instance
   *
   * @param string $site The name of the site
   * @param string $branch The branch name
   * @param array $path Path variables
   * @return void
   */
  public static function init($site = null, $branch = null, $path = [])
  {
    if (!isset(self::$site_root) || self::$site_root == null) {
      if (getenv('ENV') !== 'master') {
        self::setRoot($_SERVER['DOCUMENT_ROOT'] . '/../');
      } else {
        self::setRoot($_SERVER['DOCUMENT_ROOT'] . '/');
      }
    }

    if ($site == null) {
      $site = md5(self::$site_root);
    }

    self::$sitename = $site;

    // Caching
    self::$cacheDir = self::$site_root . 'storage/cache/';
    // Memcached caching
    self::$cache = new Cache();

    // Site configuration
    self::$config = self::getConfig();
    if (isset($_REQUEST['_clearcache'])) {
      self::clearCache();
    }

    self::$site = new Site();

    // Datastore for Netflex
    self::$store = new Store();

    // Guzzle api client
    self::$capi = self::initGuzzle(self::$config);

    // Security library
    self::$security = new Security();

    // Utils library
    self::$util = new Util();
    self::$commerce = new Commerce();

    self::$search = new ElasticSearch();
  }

  /** Sets webpackAssets */
  public static function setWebpackAssets () {
    global $payload;

    $basePath = $payload->domain ?? null;

    self::$webpackAssets = isset(self::$config['webpackManifest'])
      ? new WebpackAssets(self::$config['webpackManifest'], [
        'defaultEntrypoint' => 'app',
        'preload' => true,
        'basePath' => $basePath,
      ])
      : null;

    return self::$webpackAssets;
  }

  /**
   * Sets root folder for project
   *
   * @return void
   */
  public static function setRoot($path)
  {
    self::$site_root = $path;
    chdir($path);
  }

  /**
   * Purges Memcache
   * Blocks exectution for 3 seconds
   *
   * @return void
   */
  public static function clearCache()
  {
    self::$cache->purge();
    sleep(3);
  }

  /**
   * Retrieves site config
   *
   * @return array
   */
  public static function getConfig()
  {
    if (!self::$config) {
      $config = self::$cache->fetch('config');

      if (!$config) {
        $configvars = scandir(self::$site_root . 'config');
        $config = [];
        foreach ($configvars as $configfile) {
          if (strpos($configfile, '.json') !== false) {
            $cnfvar = str_replace('.json', '', $configfile);
            $config[$cnfvar] = json_decode(file_get_contents(self::$site_root . '/config/' . $configfile), true);
          }
        }
        self::$cache->save('config', $config, 300);
      }
      return $config;
    } else {
      return self::$config;
    }
  }

  /**
   * Prepends a filename with path
   *
   * @return string
   */
  public static function nfPath($file)
  {
    return __DIR__ . '/' . $file;
  }

  /**
   * Write a debug message to the PhpConsole
   *
   * @param string $text The message to log
   * @param string $label = null Label for log message
   */
  public static function debug($text, $label = null)
  {
    Console::getInstance()
      ->log($text, $label);
  }

  /**
   * Instantiates a authenticated Guzzle client
   *
   * @param array $config
   * @return GuzzleHttp\Client
   */
  private static function initGuzzle($config)
  {
    API::setCredentials(
      $config['api']['pubkey'],
      $config['api']['privkey']
    );

    return API::getClient()
      ->getGuzzleInstance();
  }

  /**
   * Performs API request with HTTP GET method and returns the response
   *
   * @param string $url Relative to the /v1/ root of the API
   * @param bool $assoc = false Determines if the response should be parsed as associative array or object
   * @return object|array
   */
  public static function get ($url, $assoc = false) {
    return API::getClient()
      ->get($url, $assoc);
  }

  /**
   * Performs API request with HTTP PUT method and returns the response
   *
   * @param string $url Relative to the /v1/ root of the API
   * @param array $payload = []
   * @param bool $assoc = false Determines if the response should be parsed as associative array or object
   * @return object|array
   */
  public static function put ($url, $payload = [], $assoc = false) {
    return API::getClient()
      ->put($url, $payload, $assoc);
  }

  /**
   * Performs API request with HTTP POST method and returns the response
   *
   * @param string $url Relative to the /v1/ root of the API
   * @param array $payload = []
   * @param bool $assoc = false Determines if the response should be parsed as associative array or object
   * @return object|array|string
   */
  public static function post ($url, $payload = [], $assoc = false) {
    return API::getClient()
      ->post($url, $payload, $assoc);
  }

  /**
   * Performs API request with HTTP DELETE method and returns the response
   *
   * @param string $url Relative to the /v1/ root of the API
   * @param bool $assoc = false Determines if the response should be parsed as associative array or object
   * @return object|array|string
   */
  public static function delete ($url, $assoc = false) {
    return API::getClient()
      ->delete($url, $assoc);
  }

  /**
   * Returns a Search instance
   *
   * @return ElasticSearch
   */
  public static function search()
  {
    return new self::$search;
  }
}
