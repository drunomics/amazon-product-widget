<?php

namespace Drupal\amazon_product_widget\Plugin\Field\FieldFormatter;

use Drupal\amazon_product_widget\ProductServiceTrait;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Form\FormStateInterface;

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

  use ProductServiceTrait;

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

    if ($this->getSetting('render_inline')) {
      /** @var \Drupal\amazon_product_widget\ProductService $product_service */
      $build['#products'] = $this->getProductService()
        ->buildProducts(
          $field->getEntity()->getEntityTypeId(),
          $field->getEntity()->id(),
          $field->getParent()->getName()
        );
    }

    CacheableMetadata::createFromObject($items->getEntity())->applyTo($build);
    return $build;
  }

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return parent::defaultSettings() + [
      'render_inline' => FALSE,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $form = parent::settingsForm($form, $form_state);

    $form['render_inline'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Render element inline.'),
      '#default_value' => $this->getSetting('render_inline'),
    ];

    return $form;
  }

}
