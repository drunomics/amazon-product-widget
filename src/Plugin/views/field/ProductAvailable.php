<?php

namespace Drupal\amazon_product_widget\Plugin\views\field;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;

/**
 * Shows whether the product is available or not.
 *
 * @ViewsField("amazon_product_widget_product_available")
 *
 * @package Drupal\amazon_product_widget\Plugin\views\field
 */
class ProductAvailable extends FieldPluginBase {

  use StringTranslationTrait;

  /**
   * {@inheritDoc}
   */
  public function render(ResultRow $values) {
    $value = intval($this->getValue($values));
    if ($value === 0) {
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
