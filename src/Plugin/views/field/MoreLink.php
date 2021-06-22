<?php

namespace Drupal\amazon_product_widget\Plugin\views\field;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\views\Plugin\views\display\DisplayPluginBase;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;
use Drupal\views\ViewExecutable;

/**
 * Shows a more info link.
 *
 * @ViewsField("amazon_product_widget_more_link")
 *
 * @package Drupal\amazon_product_widget\Plugin\views\field
 */
class MoreLink extends FieldPluginBase {

  use StringTranslationTrait;

  /**
   * {@inheritDoc}
   */
  public function init(ViewExecutable $view, DisplayPluginBase $display, array &$options = NULL) {
    parent::init($view, $display, $options);
  }

  /**
   * {@inheritDoc}
   */
  public function query() {
    return;
  }

  /**
   * {@inheritDoc}
   */
  public function render(ResultRow $values) {
    $value = $values->amazon_product_widget_key_value_name ?? NULL;
    if ($value && amazon_product_widget_is_valid_asin($value)) {
      return [
        'more_link' => [
          '#type'  => 'link',
          '#title' => $this->t('More'),
          '#url'   => Url::fromRoute('amazon_product_widget.more_info', [
            'asin' => $value,
          ]),
          '#attributes' => [
            'class' => [
              'button',
            ],
          ],
        ],
      ];
    }
    else {
      return [
        '#markup' => $this->t('Not available'),
      ];
    }
  }

}
