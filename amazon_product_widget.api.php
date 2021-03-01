<?php

/**
 * @file
 * Hook definitions.
 */

use Drupal\amazon_product_widget\Plugin\Field\FieldType\AmazonProductField;
use Drupal\node\NodeInterface;

/**
 * Allows altering of the prodcuts container.
 *
 * @param array $products_container
 *   The products container.
 * @param AmazonProductField $product_field
 *   The field the product widget is attached to.
 * @param \Drupal\node\NodeInterface $node
 *   The node the product field is bound to. Can be NULL.
 */
function amazon_product_widget_alter_product_data(array &$products_container, AmazonProductField $product_field, NodeInterface $node = NULL) {
}