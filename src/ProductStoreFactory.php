<?php

namespace Drupal\amazon_product_widget;

use Drupal\Core\KeyValueStore\KeyValueDatabaseFactory;

/**
 * Defines the key/value store factory for the database backend.
 */
class ProductStoreFactory extends KeyValueDatabaseFactory {

  /**
   * {@inheritdoc}
   *
   * @return \Drupal\amazon_product_widget\ProductStore
   */
  public function get($collection) {
    return new ProductStore($collection, $this->serializer, $this->connection);
  }

}
