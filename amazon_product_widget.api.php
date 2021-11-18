<?php

/**
 * @file
 * Hook definitions.
 */

use Drupal\amazon_product_widget\Plugin\Field\FieldType\AmazonProductField;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\node\NodeInterface;

/**
 * Allows altering of the products container.
 *
 * @param array $products_container
 *   The products container.
 * @param \Drupal\amazon_product_widget\Plugin\Field\FieldType\AmazonProductField $product_field
 *   The field the product widget is attached to.
 * @param \Drupal\node\NodeInterface|null $node
 *   The node the product field is bound to. Can be NULL.
 */
function hook_amazon_product_widget_alter_product_data(array &$products_container, AmazonProductField $product_field, ?NodeInterface $node = NULL) {
}

/**
 * Alters the validation of product data.
 *
 * @param \Drupal\amazon_product_widget\Plugin\Field\FieldType\AmazonProductField $product_field
 *   The product field.
 * @param array $product_data
 *   The product data.
 *
 * @return bool
 *   TRUE if the product is valid and should be displayed, FALSE otherwise.
 */
function hook_amazon_product_widget_alter_validate_product_data(AmazonProductField $product_field, array $product_data) {
  return amazon_product_widget_validate_product_data($product_data);
}

/**
 * Alter ASIN map.
 *
 * Allows you to alter the ASINs returned for the entity. This could be useful
 * if they are stored in a different place than the AmazonProductField.
 *
 * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
 *   The entity that is being searched.
 *
 * @return array
 *   Array of ASINs that were found in the entity. Can be empty.
 */
function hook_amazon_product_widget_get_custom_asin_map(FieldableEntityInterface $entity) {
  return [];
}
