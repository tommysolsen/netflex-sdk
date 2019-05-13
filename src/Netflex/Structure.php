<?php

namespace Netflex;

global $previewmode;

use NF;
use Exception;
use ArrayAccess;
use Serializable;
use Carbon\Carbon;
use JsonSerializable;
use Illuminate\Support\Collection;

/**
 * @property RevisionCollection $revisions
 */
abstract class Structure implements ArrayAccess, Serializable, JsonSerializable
{
  use FieldMapping;

  protected static $_booted = false;
  protected static $_hooks = [];
  protected static $_existing_hooks = [
    'retrieved',
    'creating',
    'created',
    'updating',
    'updated',
    'saving',
    'saved',
    'deleting',
    'deleted'
  ];
  private $_client;

  protected $_modified = [];
  protected $attributes = [];
  protected $hidden = [];
  protected $directory = null;
  protected $typecasting = true;
  protected $hideDefaultFields = false;
  protected $dates = ['created', 'updated'];
  protected $mapFieldCodes = false;
  protected $_append = [];
  protected $_revisions = null;

  /**
   * Constructs a new Structure instance
   * @param array $attributes = []
   */
  public function __construct($attributes = [])
  {
    if (is_string($attributes)) {
      $this->attributes['name'] = $attributes;
      $this->_modified[] = 'name';
    }

    if (!is_string($attributes) && (is_object($attributes) || is_array($attributes))) {
      $attributes = json_decode(json_encode($attributes));
      foreach ($attributes as $key => $value) {
        $this->attributes[$key] = $value;
        $this->_modified[] = $key;
      }

      if (in_array('id', $this->_modified)) {
        $this->_modified = [];
        static::performHookOn($this, 'retrieved');
      }
    }

    static::bootUnlessBooted();
  }

  /**
   * Saves the current changes
   *
   * @throws Exception
   * @return static
   */
  public function save()
  {
    static::performHookOn($this, 'saving');

    $payload = ['revision_publish' => true];

    foreach ($this->_modified as $key) {
      $payload[$key] = $this->attributes[$key];
    }

    $payload = ['json' => $payload];

    if (count($this->_modified)) {
      if ($this->id) {
        static::performHookOn($this, 'updating');
        NF::$capi->put('builder/structures/entry/' . $this->id, $payload);
        static::performHookOn($this, 'updated');
      } else {
        static::performHookOn($this, 'creating');
        $response = NF::$capi->post('builder/structures/' . $this->directory . '/entry', $payload);
        static::performHookOn($this, 'created');
        $response = json_decode($response->getBody());
        $this->attributes['id'] = $response->entry_id;
        $response = NF::$capi->get('builder/structures/entry/' . $this->attributes['id']);
        $response = json_decode($response->getBody());
        foreach ($response as $key => $value) {
          $this->attributes[$key] = $value;
        }
      }
    }

    $this->_modified = [];
    NF::$cache->save('builder_structures_entry_' . $this->id, serialize($this->attributes));

    static::performHookOn($this, 'saved');
    return $this;
  }

  /**
   * Deletes the entry
   *
   * @throws Exception
   * @return static
   */
  public function delete()
  {
    if (!$this->id) {
      throw new Exception('Unable to delete entry');
    }

    static::performHookOn($this, 'deleting');
    NF::$capi->delete('builder/structures/entry/' . $this->id);
    NF::$cache->delete('builder_structures_entry_' . $this->id);
    static::performHookOn($this, 'deleted');
    return $this;
  }

  /**
   * Get the entry content as assoc array
   *
   * @return array
   */
  public function toArray()
  {
    return $this->jsonSerialize();
  }

  /**
   * Getter magic method
   *
   * @param string $key
   * @return mixed
   */
  public function __get($key)
  {
    $value = null;

    if ($key === 'revisions') {
      if ($this->_revisions === null) {
        $this->_revisions = new RevisionCollection(static::class, $this->id);
      }
      $value = $this->_revisions;
    }

    if (array_key_exists($key, $this->attributes)) {
      $value = $this->attributes[$key];
    }

    $getter = str_replace('_', '', 'get' . $key . 'attribute');
    $value = $this->__typeCast($key, $value);

    if (method_exists($this, $getter)) {
      $value = $this->{$getter}($value);
    }

    if (in_array($key, $this->dates) && $this->typecasting) {
      return Carbon::parse($value);
    }

    return $value;
  }

  /**
   * Setter magic method
   *
   * @param string $key
   * @param mixed $value
   */
  public function __set($key, $value)
  {
    if ($key === "revisions") {
      return;
    }
    if ($this->offsetExists($key)) {
      $setter = str_replace('_', '', 'set' . $key . 'attribute');

      if (method_exists($this, $setter)) {
        $value = $this->{$setter}($value);
      }

      if ($this->attributes[$key] !== $value) {
        $this->attributes[$key] = $value;
        if (!in_array($key, $this->_modified)) {
          $this->_modified[] = $key;
        }
      }
    }
  }

  /**
   * Unset magic method
   *
   * @param string $key
   * @return void
   */
  public function __unset($key)
  {
    if (array_key_exists($key, $this->attributes)) {
      $this->__set($key, null);
    }
  }

  /**
   * toString magic method
   *
   * @return string
   */
  public function __toString()
  {
    return json_encode($this->jsonSerialize());
  }

  /**
   * DebugInfo magic method
   *
   * @return array
   */
  public function __debugInfo()
  {
    return $this->jsonSerialize(true);
  }

  /**
   * Custom json serialization
   *
   * @param bool $useGetters = false
   * @return void
   */
  public function jsonSerialize($useGetters = false)
  {
    $json = [];
    foreach ($this->attributes as $key => $value) {
      $hidden = array_merge($this->hidden, $this->hideDefaultFields ? $this->defaultFields : []);
      if (in_array($key, $hidden)) {
        continue;
      }
      if (in_array($key, $this->dates) && !$useGetters) {
        $json[$key] = $this->attributes[$key];
        continue;
      }

      $json[$key] = $this->__get($key);
    }

    if (is_array($this->_append))
      foreach ($this->_append as $key) {
        $json[$key] = $this->__get($key);
      }

    return $json;
  }

  /**
   * Custom serialization
   *
   * @return string
   */
  public function serialize()
  {
    return serialize($this->attributes);
  }

  /**
   * Custom deserialization
   *
   * @param string $attributes
   * @return void
   */
  public function unserialize($attributes)
  {
    $this->attributes = unserialize($attributes);
  }

  /**
   * Check if array offset exists
   *
   * @param string $key
   * @return bool
   */
  public function offsetExists($key)
  {
    return method_exists($this, str_replace('_', '', 'set' . $key . 'attribute')) || array_key_exists($key, $this->attributes);
  }

  /**
   * Get value at offset
   *
   * @param string $key
   * @return mixed
   */
  public function offsetGet($key)
  {
    return $this->__get($key);
  }

  /**
   * Set value at offset
   *
   * @param string $key
   * @param mixed $value
   * @return void
   */
  public function offsetSet($key, $value)
  {
    $this->__set($key, $value);
  }

  /**
   * Unset value at offset
   *
   * @param string $key
   * @return void
   */
  public function offsetUnset($key)
  {
    $this->__unset($key);
  }

  /**
   * Update entry data
   *
   * @param array $attributes
   * @return static
   */
  public function update($attributes = [])
  {
    foreach ($attributes as $key => $value) {
      $this->__set($key, $value);
    }

    return $this;
  }

  /**
   * Retrieves all entries for structure
   *
   * @return Collection<static>
   */
  public static function all()
  {
    $structureId = (new static)->directory;
    $response = NF::$capi->get('builder/structures/' . $structureId . '/entries');
    $response = json_decode($response->getBody(), true);

    return collect(array_map(function ($entry) {
      $cacheKey = 'entry/' . $entry['id'];
      NF::$cache->save($cacheKey, $entry);
      return static::generateObject($entry);
    }, $response))->values();
  }

  /**
   * Retrieve entry by ID
   *
   * @param int|array<int> $id = null
   * @return static|array<static>|null
   */
  public static function find($id = null)
  {
    if (is_null($id)) {
      return null;
    }

    $structureId = (new static)->directory;

    if (is_array($id)) {
      $id = collect($id);
    }

    if ($id instanceof Collection) {
      $id = $id->filter();
      return $id->map(function ($id) {
        return static::find($id);
      }, $id)->values();
    }

    try {
      $data = null;
      $cacheKey = 'entry/' . $id;

      if (NF::$cache->has($cacheKey)) {
        $data = NF::$cache->fetch($cacheKey);
      }

      if (!$data) {
        $response = NF::$capi->get('builder/structures/entry/' . $id);
        $data = json_decode($response->getBody(), true);

        if (!$data || $data['directory_id'] != $structureId) {
          $data = null;
        }

        if ($data) {
          NF::$cache->save($cacheKey, $data);
        }
      }

      if ($data) {
        return static::generateObject($data);
      }
    } catch (Exception $ex) {
      /* intentionally left blank */ }

    return null;
  }

  /**
   * Retrieve entry or throw
   *
   * @param int $id
   * @throws Exception
   * @return static|array<static>
   */
  public static function findOrFail($id)
  {
    $entry = static::find($id);

    if (!$entry) {
      throw new Exception('Entry not found');
    }

    if (is_array($entry)) {
      if (count(array_filter($entry)) < count($entry)) {
        throw new Exception('Entry not found');
      }
    }

    return $entry;
  }

  /**
   * Resolve entry by url
   *
   * @param string $slug
   * @return static
   */
  public static function resolve($slug)
  {
    $structureId = (new static)->directory;
    $entry = resolve_entry([
      'url' => $slug . '/',
      'directory_id' => $structureId,
      'fetch' => true
    ]);
    if ($entry) {
      return static::generateObject($entry);
    }
  }

  /**
    * Resolve entry by url or fail
    *
    * @param string $slug
    * @throws Exception
    * @return static
    */
  public static function resolveOrFail($slug)
  {
    $entry = static::resolve($slug);
    if ($entry) {
      return $entry;
    }
    throw new Exception('Entry not resolved');
  }

  /**
   * Query
   *
   * @param mixed ...$args
   * @return StructureQuery
   */
  public static function query(...$args)
  {
    $structureId = (new static)->directory;
    $query = new StructureQuery($structureId, new static);

    return call_user_func_array([$query, 'query'], $args);
  }

  /**
   * Query count
   *
   * @return int
   */
  public static function count()
  {
    $structureId = (new static)->directory;
    $query = new StructureQuery($structureId, new static);

    return call_user_func_array([$query, 'count'], []);
  }

  /**
   * Query where
   *
   * @param string $key
   * @param string $comparator = '=',
   * @param mixed $valye
   * @return StructureQuery
   */
  public static function where(...$args)
  {
    $structureId = (new static)->directory;
    $query = new StructureQuery($structureId, new static);

    return call_user_func_array([$query, 'where'], $args);
  }

  /**
   * Query pluck field
   *
   * @param string $field
   * @return array
   */
  public static function pluck(...$args)
  {
    $structureId = (new static)->directory;
    $query = new StructureQuery($structureId, new static);

    return call_user_func_array([$query, 'pluck'], $args);
  }

  /**
   * Query where between
   *
   * @param string $field
   * @param mixed $from
   * @param mixed $to
   * @return StructureQuery
   */
  public static function whereBetween(...$args)
  {
    $structureId = (new static)->directory;
    $query = new StructureQuery($structureId, new static);

    return call_user_func_array([$query, 'whereBetween'], $args);
  }

  /**
   * Query order by
   *
   * @param string $field
   * @param string $dir = 'asc'
   * @return StructureQuery
   */
  public static function orderBy(...$args)
  {
    $structureId = (new static)->directory;
    $query = new StructureQuery($structureId, new static);

    return call_user_func_array([$query, 'orderBy'], $args);
  }

  /**
   * Query first
   *
   * @return static|null
   */
  public static function first()
  {
    $structureId = (new static)->directory;
    $query = new StructureQuery($structureId, new static);

    return call_user_func_array([$query, 'firstOrFail'], []);
  }

  /**
   * Paginate structure
   *
   * @param int $size
   * @param int $page = 1
   * @return StructureQueryPage
   */
  public static function paginate(...$args)
  {
    $structureId = (new static)->directory;
    $query = new StructureQuery($structureId, new static);

    return call_user_func_array([$query, 'paginate'], $args);
  }

  /**
   * Generates object from data
   *
   * @param array $data
   * @return void
   */
  public static function generateObject($data)
  {
    global $previewmode;

    if (isset($previewmode)) {
      $r = new static($data);
      if (array_key_exists('revision', $_GET) && array_key_exists('id', $_GET) && $r->id == $_GET['id']) {
        return $r->revisions[$_GET['revision']];
      } else {
        return $r;
      }
    } else {
      return new static($data);
    }
  }

  /**
   * Initialize hooks
   *
   * @return void
   */
  private static function bootUnlessBooted()
  {
    if (!static::$_booted) {
      static::boot();
      static::$_booted = true;
    }
  }

  /**
   * Boot
   *
   * @return void
   */
  private static function boot()
  { }

  /**
   * Perform hook
   *
   * @param string $subject
   * @param string $domain
   * @return void
   */
  public static function performHookOn($subject, $domain)
  {
    if (in_array($domain, array_keys(static::$_hooks))) {
      foreach (static::$_hooks[$domain] as $hook) {
        $hook->call($subject);
      }
    }
  }

  /**
   * Attach hook handler
   *
   * @param string $domain
   * @param callable $func
   * @return void
   */
  private static function addHook($domain, callable $func)
  {
    if (!in_array($domain, array_keys(static::$_hooks))) {
      static::$_hooks[$domain] = [];
    }
    static::$_hooks[$domain][] = $func;
  }

  /**
   * CallStatic magic method
   *
   * @param string $name
   * @param array $arguments
   * @return void
   */
  public static function __callStatic($name, $arguments)
  {
    if (in_array($name, static::$_existing_hooks) && sizeof($arguments) == 1) {
      static::addHook($name, $arguments[0]);
    }
  }
}
