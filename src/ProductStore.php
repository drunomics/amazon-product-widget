<?php

namespace Drupal\amazon_product_widget;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Serialization\SerializationInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\Merge;
use Drupal\Core\KeyValueStore\DatabaseStorage;
use Drupal\Core\Site\Settings;

/**
 * Key value store with renewal field for amazon products.
 *
 * The products will be stored indefinitely but will be refreshed via cronjob.
 * The day they will be refreshed (renewal date) can controlled by the setting:
 * `amazon_product_widget.products.renewal_time` which sets the hours for the
 * next renewal (default 48) for products.
 *
 * @see ProductStore::getNextRenewalTime()
 */
class ProductStore extends DatabaseStorage {

  /**
   * Collection for product data.
   */
  const COLLECTION_PRODUCTS = 'products';
  const COLLECTION_SEARCH_RESULTS = 'search_results';

  /**
   * TimeInterface.
   *
   * @var \Drupal\Component\Datetime\TimeInterface 
   */
  protected $time;

  /**
   * Overrides Drupal\Core\KeyValueStore\StorageBase::__construct().
   *
   * @param string $collection
   *   The name of the collection holding key and value pairs.
   * @param \Drupal\Component\Serialization\SerializationInterface $serializer
   *   The serialization class to use.
   * @param \Drupal\Core\Database\Connection $connection
   *   The database connection to use.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The TimeInterface object.
   */
  public function __construct($collection, SerializationInterface $serializer, Connection $connection, TimeInterface $time) {
    parent::__construct($collection, $serializer, $connection, 'amazon_product_widget_key_value');
    $this->time = $time;
  }

  /**
   * Gets the timestamp of the next renewal time.
   *
   * @return int
   */
  protected function getNextRenewalTime() {
    // Read the configured renewal time in hours.
    return $this->time->getRequestTime() + Settings::get('amazon_product_widget.' . $this->getCollectionName() . '.renewal_time', 48) * 3600;
  }

  /**
   * {@inheritdoc}
   *
   * @param string $key
   *   The key of the data to store.
   * @param mixed $value
   *   The data to store.
   * @param int $renewal
   *   (optional) The timestamp when the entry should be renewed again.
   *   Defaults to renewal as configured for the plugin. Pass 0 for immediate
   *   renewal.
   *
   * @throws \Exception
   */
  public function set($key, $value, $renewal = NULL) {
    $renewal = isset($renewal) ? $renewal : $this->getNextRenewalTime();
    $this->connection->merge($this->table)
      ->keys([
        'name' => $key,
        'collection' => $this->collection,
      ])
      ->fields(['value' => $this->serializer->encode($value), 'renewal' => $renewal])
      ->execute();
  }

  /**
   * Sets overrides for the given key.
   *
   * @param string $key
   *   Key.
   * @param array $overrides
   *   The overrides.
   *
   * @throws \Exception
   */
  public function setOverride($key, array $overrides) {
    $this->connection->merge($this->table)
      ->keys([
        'name' => $key,
        'collection' => $this->collection
      ])
      ->fields([
        'overrides' => $this->serializer->encode($overrides),
      ])
      ->execute();
  }

  /**
   * Gets overrides for the given ASIN numbers.
   *
   * @param array $asins
   *   The ASINs.
   *
   * @return array
   *   Overrides, keyed by ASIN.
   */
  public function getOverrides($asins) {
    $values = [];
    try {
      $result = $this->connection->query('SELECT name, overrides  FROM {' . $this->connection->escapeTable($this->table) . '} WHERE name IN ( :keys[] ) AND collection = :collection', [':keys[]' => $asins, ':collection' => $this->collection])->fetchAllAssoc('name');
      foreach ($asins as $asin) {
        if (isset($result[$asin])) {
          $values[$asin] = $this->serializer->decode($result[$asin]->overrides);
        }
      }
    }
    catch (\Exception $exception) {
      watchdog_exception('amazon_product_widget', $exception);
    }
    return $values;
  }

  /**
   * The same as DatabaseStorage::getMultiple() but with our overrides added.
   *
   * {@inheritDoc}
   */
  public function getMultipleWithOverrides(array $keys) {
    $values = [];
    try {
      $result = $this->connection->query('SELECT name, value, overrides FROM {' . $this->connection->escapeTable($this->table) . '} WHERE name IN ( :keys[] ) AND collection = :collection', [':keys[]' => $keys, ':collection' => $this->collection])->fetchAllAssoc('name');
      foreach ($keys as $key) {
        if (isset($result[$key])) {
          $values[$key] = $this->serializer->decode($result[$key]->value);
          $values[$key]['overrides'] = $this->serializer->decode($result[$key]->overrides);
        }
      }
    }
    catch (\Exception $exception) {
      watchdog_exception('amazon_product_widget', $exception);
    }
    return $values;
  }

  /**
   * {@inheritdoc}
   *
   * @param array $data
   *   An associative array of key/value pairs.
   * @param int $renewal
   *   (optional) The timestamp when the entry should be renewed again.
   *   Defaults to renewal as configured for the plugin. Pass 0 for immediate
   *   renewal.
   *
   * @throws \Exception
   */
  public function setMultiple(array $data, $renewal = NULL) {
    foreach ($data as $key => $value) {
      $this->set($key, $value, $renewal);
    }
  }

  /**
   * {@inheritdoc}
   *
   * @param string $key
   *   The key of the data to store.
   * @param mixed $value
   *   The data to store.
   * @param int $renewal
   *   (optional) The timestamp when the entry should be renewed again.
   *   Defaults to renewal as configured for the plugin. Pass 0 for immediate
   *   renewal.
   *
   * @throws \Exception
   */
  public function setIfNotExists($key, $value, $renewal = NULL) {
    $renewal = isset($renewal) ? $renewal : $this->getNextRenewalTime();
    $result = $this->connection->merge($this->table)
      ->insertFields([
        'collection' => $this->collection,
        'name' => $key,
        'value' => $this->serializer->encode($value),
        'renewal' => $renewal,
      ])
      ->condition('collection', $this->collection)
      ->condition('name', $key)
      ->execute();
    return $result == Merge::STATUS_INSERT;
  }

  /**
   * Returns all stored keys in the collection that are outdated.
   *
   * The limit can be configured like this:
   * @code
   *   $settings['amazon_product_widget.products.renewal_limit'] = 500;
   * @endcode
   *
   * @param int $limit
   *   (optional) The maximum number of items to return. Defaults to the
   *   configured limit per plugin-ID. If nothing is configured, 100 is used.
   *
   * @return string[]
   */
  public function getOutdatedKeys($limit = NULL) {
    $limit = isset($limit) ? $limit : Settings::get('amazon_product_widget.' . $this->getCollectionName() . '.renewal_limit', 100);

    $result = $this->connection->queryRange('SELECT name FROM {' . $this->connection->escapeTable($this->table) . '} WHERE collection = :collection AND renewal < :renewal', 0, $limit, [
      ':collection' => $this->collection,
      ':renewal' => $this->time->getRequestTime(),
    ]);

    return $result->fetchCol();
  }

  /**
   * Gets the number of stale entries in the product database.
   *
   * @return int
   *   The number of stale entries.
   */
  public function getOutdatedKeysCount() {
    $query = $this->connection->select($this->table, 'ta');
    $query->condition('collection', $this->collection);
    $query->condition('renewal', $this->time->getRequestTime(), '<');
    return $query->countQuery()->execute()->fetchField();
  }

  /**
   * Check whether there is data available for renewal.
   *
   * @return bool
   */
  public function hasStaleData() {
    $result = $this->getOutdatedKeys(1);
    return (bool) count($result);
  }

  /**
   * Sets the renewal time to zero for all entries.
   *
   * All items will then be fetched fresh on the next cron run.
   */
  public function resetAll() {
    $this->connection->query('UPDATE {' . $this->connection->escapeTable($this->table) . '} SET renewal = 0 WHERE collection = :collection', [':collection' => $this->collection]);
  }

  /**
   * Gets a hash for the search result store.
   *
   * Used for setting the search store key.
   *
   * @param string $search_terms
   *   Add a search string like you would input in the Amazon search
   *   bar to fetch results for this string as well.
   * @param string $category
   *   Amazon Search index.
   *
   * @return string
   */
  public static function createSearchResultKey($search_terms, $category) {
    $elements = [
      $category,
      $search_terms,
    ];
    return md5(serialize($elements));
  }

  /**
   * Gets data container for the search store.
   *
   * Used for setting the search store value.
   *
   * @param string $search_terms
   *   Add a search string like you would input in the Amazon search
   *   bar to fetch results for this string as well.
   * @param string $category
   *   Amazon Search index.
   * @param string[] $asins
   *   (optional) Provide ASINs which are not in the store yet, so that they
   *   get fetched too.
   *
   * @return array
   */
  public static function createSearchResultData($search_terms, $category, $asins = []) {
    return [
      'search_terms' => $search_terms,
      'category' => $category,
      'result' => $asins,
    ];
  }

}
