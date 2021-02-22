<?php

namespace Drupal\amazon_product_widget;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Serialization\SerializationInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\KeyValueStore\KeyValueDatabaseFactory;

/**
 * Defines the key/value store factory for the database backend.
 */
class ProductStoreFactory extends KeyValueDatabaseFactory {

  /**
   * TimeInterface.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected $time;

  /**
   * Constructs this factory object.
   *
   * @param \Drupal\Component\Serialization\SerializationInterface $serializer
   *   The serialization class to use.
   * @param \Drupal\Core\Database\Connection $connection
   *   The Connection object containing the key-value tables.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The TimeInterface object that keeps time.
   */
  public function __construct(SerializationInterface $serializer, Connection $connection, TimeInterface $time) {
    $this->serializer = $serializer;
    $this->connection = $connection;
    $this->time = $time;
  }

  /**
   * {@inheritdoc}
   *
   * @return \Drupal\amazon_product_widget\ProductStore
   */
  public function get($collection) {
    return new ProductStore($collection, $this->serializer, $this->connection, $this->time);
  }

}
