<?php

namespace Drupal\amazon_product_widget\Plugin\Field\FieldWidget;

use Drupal\amazon_product_widget\ProductService;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the Amazon Product Widget.
 *
 * @FieldWidget(
 *   id = "amazon_product_widget",
 *   module = "amazon_product_widget",
 *   label = @Translation("Amazon Product Widget"),
 *   field_types = {
 *     "amazon_product_widget_field_type"
 *   }
 * )
 */
class AmazonProductWidget extends WidgetBase implements ContainerFactoryPluginInterface {

  use StringTranslationTrait;

  /**
   * Product service.
   *
   * @var \Drupal\amazon_product_widget\ProductService
   */
  protected $productService;

  /**
   * {@inheritdoc}
   */
  public function __construct($plugin_id, $plugin_definition, FieldDefinitionInterface $field_definition, array $settings, array $third_party_settings, ProductService $product_service) {
    parent::__construct($plugin_id, $plugin_definition, $field_definition, $settings, $third_party_settings);
    $this->productService = $product_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['field_definition'],
      $configuration['settings'],
      $configuration['third_party_settings'],
      $container->get('amazon_product_widget.product_service')
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return [
      'max_asins' => 3,
      'render_inline' => FALSE,
    ] + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $elements = [];

    $elements['max_asins'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum number of products to display.'),
      '#default_value' => $this->getSetting('max_asins'),
      '#required' => TRUE,
      '#min' => 1,
      '#max' => 10,
    ];

    return $elements;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = [];

    $summary[] = $this->t('Display a maximum number of @max_asins products.', ['@max_asins' => $this->getSetting('max_asins')]);

    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $max_asins = $this->getSetting('max_asins');
    if (!empty($items[$delta]->asins) && !is_array($items[$delta]->asins)) {
      $asins = explode(',', $items[$delta]->asins);
    }

    $element['title'] = [
      '#title' => $this->t('Title'),
      '#type' => 'textfield',
      '#default_value' => isset($items[$delta]->title) ? $items[$delta]->title : NULL,
      '#size' => 60,
      '#maxlength' => 255,
    ];

    $element['asins'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('ASIN(s)'),
      '#description' => $this->t("Input at least one ASIN for the products which should be displayed by default. Amazon Standard Identification Numbers (ASINs) are unique blocks of 10 letters and/or numbers that identify items. You can find the ASIN on the item's product information page at Amazon."),
      '#element_validate' => [
        [$this, 'validateAsins'],
      ],
    ];

    for ($i = 0; $i < $max_asins; $i++) {
      $element['asins'][$i] = [
        '#type' => 'textfield',
        '#default_value' => isset($asins[$i]) ? $asins[$i] : NULL,
        '#title' => '',
        '#size' => 15,
        '#maxlength' => 10,
      ];
    }

    $element['search_terms'] = [
      '#title' => $this->t('Fallback search terms'),
      '#description' => $this->t('When the above products are unavailable, these terms are used to search for alternative products on Amazon. The top items from the search result will be displayed as a fallback.'),
      '#type' => 'textfield',
      '#default_value' => isset($items[$delta]->search_terms) ? $items[$delta]->search_terms : NULL,
      '#size' => 60,
      '#maxlength' => 255,
    ];

    return $element;
  }

  /**
   * Validate ASINs.
   *
   * @param array $element
   *   Form element.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   */
  public function validateAsins(array $element, FormStateInterface $form_state) {
    if ($form_state->getValue('form_id') == 'field_config_edit_form') {
      return;
    }

    $asins = [];
    foreach (Element::children($element) as $key) {
      if (!empty($element[$key]['#value'])) {
        $asin = $element[$key]['#value'];
        if (!amazon_product_widget_is_valid_asin($asin)) {
          $form_state->setError($element[$key], $this->t('Invalid ASIN: %asin', ['%asin' => $asin]));
        }
        $asins[$key] = $asin;
      }
    }

    if (empty($asins)) {
      $form_state->setError($element, $this->t('Input at least one ASIN.'));
      return;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function massageFormValues(array $values, array $form, FormStateInterface $form_state) {
    // This method is called multiple times per submit, queue only once.
    static $asins_queued = FALSE;

    foreach ($values as &$value) {
      if (!empty($value['asins']) && is_array($value['asins'])) {
        // Make sure new products will be fetched from Amazon eventually.
        if (!$asins_queued) {
          $this->productService->queueProductRenewal($value['asins']);
          $asins_queued = TRUE;
        }
        // Convert to internal format (comma separated list of asins).
        $value['asins'] = implode(",", array_filter($value['asins']));
      }
    }
    return $values;
  }

}
