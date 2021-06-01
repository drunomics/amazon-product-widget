<?php

namespace Drupal\amazon_product_widget\Plugin\views\field;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;

/**
 * Shows whether the product has data or not.
 *
 * @ViewsField("amazon_product_widget_product_has_data")
 *
 * @package Drupal\amazon_product_widget\Plugin\views\field
 */
class ProductHasData extends FieldPluginBase {

  use StringTranslationTrait;

  /**
   * {@inheritDoc}
   */
  public function render(ResultRow $values) {
    $data = unserialize($this->getValue($values));
    if ($data === NULL || $data === FALSE) {
      return [
        '#markup' => '<span class="color-warning">' . $this->t('No') . '</span>',
      ];
    }
    else {
      return [
        '#markup' => '<span class="color-success">' . $this->t('Yes') . '</span>',
      ];
    }
  }

}
