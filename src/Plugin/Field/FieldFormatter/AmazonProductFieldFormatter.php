<?php

namespace Drupal\amazon_product_widget\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Cache\CacheableMetadata;

/**
 * Plugin implementation of the Amazon Product Widget field formatter.
 *
 * @FieldFormatter(
 *   id = "amazon_product_widget_field_formatter",
 *   module = "amazon_product_widget",
 *   label = @Translation("Amazon Product Widget"),
 *   field_types = {
 *     "amazon_product_widget_field_type"
 *   }
 * )
 */
class AmazonProductFieldFormatter extends FormatterBase {

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    /** @var \Drupal\amazon_product_widget\Plugin\Field\FieldType\AmazonProductField $field */
    $field = $items->first();

    if (empty($field)) {
      return [];
    }

    $build = [
      '#theme' => 'amazon_product_widget',
      '#attached' => [
        'library' => [
          'amazon_product_widget/amazon_product_widget',
        ],
      ],
      '#entity_id' => $field->getEntity()->id(),
      '#entity_type' => $field->getEntity()->getEntityTypeId(),
      '#bundle' => $field->getEntity()->bundle(),
      '#field' => $field->getParent()->getName(),
    ];

    CacheableMetadata::createFromObject($items->getEntity())->applyTo($build);
    return $build;
  }

}
