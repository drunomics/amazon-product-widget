<?php

namespace Drupal\amazon_product_widget;

use Drupal\Component\Serialization\SerializationInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\Merge;
use Drupal\Core\KeyValueStore\DatabaseStorage;
use Drupal\Core\Site\Settings;

/**
 * A custom key value storage that is extended with necessary filters.
 */
class ProductStore extends DatabaseStorage {

  /**
   * Collections for product data.
   */
  const COLLECTION_PRODUCTS = 'products';

  /**
   * Overrides Drupal\Core\KeyValueStore\StorageBase::__construct().
   *
   * @param string $collection
   *   The name of the collection holding key and value pairs.
   * @param \Drupal\Component\Serialization\SerializationInterface $serializer
   *   The serialization class to use.
   * @param \Drupal\Core\Database\Connection $connection
   *   The database connection to use.
   */
  public function __construct($collection, SerializationInterface $serializer, Connection $connection) {
    parent::__construct($collection, $serializer, $connection, 'amazon_product_widget_key_value');
  }

  /**
   * Gets the timestamp of the next renewal time.
   *
   * @return int
   */
  protected function getNextRenewalTime() {
    // Read the configured renewal time in hours.
    return time() + Settings::get('amazon_product_widget.' . $this->getCollectionName() . '.renewal_time', 48) * 3600;
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
   *   $settings['amazon_product_widget.products.limit'] = 500;
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
      ':renewal' => time(),
    ]);

    return $result->fetchCol();
  }

  /**
   * Check whether there is outdated product data in the store.
   *
   * @return bool
   */
  public function hasStaleData() {
    $result = $this->getOutdatedKeys(1);
    return (bool) count($result);
  }

}
