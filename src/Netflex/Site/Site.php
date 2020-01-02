<?php
namespace Netflex\Site;

use Closure;
use NF;
use Exception;
use JsonException;
use stdClass;

class Site
{

  /**
   * Variables prefixed with double underscore should be lazily loaded through
   * a function named "loadVarName" when the variable is named $__VarName;
   * However, As usual the function names are not case sensitive.
   */
  public $__content;
  public $__templates;
  public $__labels;
  public $__pages;
  public $__structures;
  public $__statics;
  public $__nav;
  public $__variables;
  public $hooks;

  private $content_id;
  private $content_revision;

  public function __construct()
  {
    $this->hooks = \NF::$site
                   ? \NF::$site->hooks
                   : new stdClass;

    $this->__lazy_keys =  collect((new \ReflectionClass($this))->getProperties())
      ->filter(function($key){
        return strpos($key->name, "__") === 0;
      })
      ->map(function($key) {
        return substr($key->name, 2);
      })
      ->toArray();
  }
  /**
   *
   */
  public function __get($key) {
    if($key == "content") {
      return $this->loadPage();
    }
    if(strpos($key, "__") === 0) {
      return null;
    }
    if(in_array($key, $this->__lazy_keys)) {
      if(is_null($this->{"__" . $key})) {
        $this->{"__" . $key} = \NF::$cache->resolve("{$key}", 0, function() use ($key) {
          return \call_user_func_array([$this, "load" . $key], []);
        });
      }
    } else {
      throw new \InvalidArgumentException("The Site class does not have any property named {$key}");
    }
    return $this->{"__" . $key};
  }

  public function loadGlobals () {
    global $_mode;


    NF::$jwt = new JWT($this->variables['netflex_api']);
  }


  public function loadPage($id = null, $revision = null) {
    global $_mode;
    $id = $id ?? $this->content_id;
    $revision = $revision ?? $this->content_revision;

    $this->__content = NF::$cache->fetch("page/$this->content_id");
    if ($_mode) {
      $this->__content = [];
      $this->__content = $this->loadContent($this->content_id, $this->content_revision);
    } else if (!$this->__content) {
      $this->__content = $this->loadContent($this->content_id, $this->content_revision);
      NF::$cache->save("page/$this->content_id", $this->__content);
    }
    return $this->__content;
  }

  public function loadContent($id = null, $revision = null) {
    $content = [];
    try {
      $contentItems = json_decode(NF::$capi->get('builder/pages/' . $id . '/content' . ($revision ? ('/' . $revision) : ''))->getBody(), true);
      foreach ($contentItems as $item) {
        if ($item['published'] === '1') {
          if (isset($content[$item['area']])) {

            if (!isset($content[$item['area']][0])) {

              $existing = $content[$item['area']];
              $content[$item['area']] = null;
              $content[$item['area']] = [];
              $content[$item['area']][] = $existing;
            }

            $content[$item['area']][] = $item;
          } else {
            $content[$item['area']] = $item;
          }

          $content['id_' . $item['id']] = $item;
        }
      }
    } catch (Exception $e) {
      $content = [];
    }

    return $content;
  }

  public function loadStatics () {
    try {
      $statics = json_decode(NF::$capi->get('foundation/globals')->getBody(), true);

      foreach ($statics as $static) {
        foreach ($static['globals'] as $global) {
          $this->statics[$static['alias']][$global['alias']] = $global['content'];
        }
      }
    } catch (Exception $e) {
      $this->statics = [];
    }
  }

  public function loadPages () {
    $request = NF::$capi->get('builder/pages');
    $result = json_decode($request->getBody(), true);
    if(!$result)
      return [];

    return collect($result)
      ->mapWithKeys(function($page) {
        return [ $page['id'] => $page ];
      })
      ->toArray();
  }

  public function loadNav () {
    $pages = $this->pages;
    foreach ($pages as $id => $page) {

      if ($page['parent_id'] == 0) {

        $this->nav[$id] = $page;
      }
    }
  }

  /**
   * Loads variables from Netflex.
   *
   * @throws JsonException if Json decoding failed
   * @return array Key value associative array
   */
  public function loadVariables () {
    $variables = json_decode(NF::$capi->get('foundation/variables')->getBody(), true);
    if(!$variables)
      throw new JsonException(json_last_error_msg());

    return collect($variables)
    ->mapWithKeys(function($variable){
      return [ $variable['alias'] => $variable['value'] ];
    })
    ->toArray();
  }


  /**
   * Load templates from Netflex
   *
   * @throws JsonException If JSON decoding failed
   * @return Array Array of all registered templates
   *
   */
  public function loadTemplates () {
    $template = [];
    $templates = json_decode(NF::$capi->get('foundation/templates')->getBody(), true);
    if(!$templates) {
      throw new \JsonException(json_last_error_msg());
    }

    foreach ($templates as $tmp) {
      if ($tmp['type'] == 'builder') {
        $template['components'][$tmp['id']] = $tmp;
      } else if ($tmp['type'] == 'block') {
        $template['blocks'][$tmp['id']] = $tmp;
      } else if ($tmp['type'] == 'page') {
        $template['pages'][$tmp['id']] = $tmp;
      }
    }
    return $template;
  }

  /**
   * Load labels from Netflex
   *
   * This function does not return any errors.
   * We gracefully continue, potentially in the wrong language
   * if we encounter any errors.
   *
   * @return Array Array of labels
   */
  public function loadLabels () {
    $tmp = json_decode(NF::$capi->get('foundation/labels')->getBody(), true);
    return $tmp ?? [];
  }


  /**
   * Load Structures from Netflix.
   *
   * @return Array List of structures
   */
  public function loadStructures () {
    $structures = json_decode(NF::$capi->get('builder/structures/full')->getBody(), true);
    if(!$structures) {
      throw new JsonException(json_last_error_msg());
    }

    $return = [];
    foreach ($structures as $structure) {
      $return[$structure['id']] = $structure;
      foreach ($structure['fields'] as $field) {
        if ($field['type'] != 'collection') {
          $return[$structure['id']]['fields'][$field['alias']] = $field;
          $return[$structure['id']]['fields']['id_' . $field['id']] = $field;
        }
      }
    }
    return $return;
  }


  public function requireFile(string $filename) {

    $filename = $this->runHooks("requireFile.filename", $filename);
    require $filename;
  }
  public function addHook(string $key, \Closure $function) {
    if(!is_array($this->hooks->{$key} ?? false)) {
      $this->hooks->{$key} = [];
    }
    $this->hooks->{$key}[] = $function;
  }

  private function runHooks(string $key, $payload) {
    if(array_key_exists($key, $this->hooks) && is_array($this->hooks->{$key})) {
      foreach($this->hooks->{$key} as $hook) {
        $payload = $hook($payload);
      }
    }
    return $payload;
  }
}
