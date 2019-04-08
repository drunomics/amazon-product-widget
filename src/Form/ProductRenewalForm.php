<?php

namespace Drupal\amazon_product_widget\Form;

use Drupal\amazon_product_widget\Exception\AmazonServiceException;
use Drupal\amazon_product_widget\ProductService;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a Amazon Product Widget form.
 */
class ProductRenewalForm extends FormBase {

  /**
   * Maximum number of ASINs allowed to request per form submit.
   */
  const MAX_ASINS = 100;

  /**
   * Product service.
   *
   * @var \Drupal\amazon_product_widget\ProductService
   */
  protected $productService;

  /**
   * ProductRenewalForm constructor.
   *
   * @param \Drupal\amazon_product_widget\ProductService $product_service
   *   Product service.
   */
  public function __construct(ProductService $product_service) {
    $this->productService = $product_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('amazon_product_widget.product_service')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'amazon_product_widget_product_renewal';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {

    $form['info'] = [
      '#type' => 'markup',
      '#markup' => $this->t('Amazon product data is stored locally for usage with the Amazon field widget. The products are cached locally and will be renewed regularly via cronjob. With this form the products can be fetched from Amazon and put into the system instantly.'),
    ];

    $form['asins'] = [
      '#type' => 'textarea',
      '#title' => $this->t('ASINs'),
      '#description' => $this->t('Input one or more Amazon Standard Identification Numbers (ASINs). These can be separated by using a `,` or put one ASIN per line. Since the Amazon API allows querying 10 products per single request, it is efficient to input a multiple of 10. A maximum of %max_asins ASINs are allowed in this form.', ['%max_asins' => static::MAX_ASINS]),
      '#required' => TRUE,
    ];

    $requests_per_day_options = [
      '%current_requests' => $this->productService->getTodaysRequestCount(),
      '%max_request' => $this->productService->getMaxRequestsPerDay(),
    ];

    $form['requests_per_day'] = [
      '#type' => 'markup',
      '#markup' => $this->t('Currently %current_requests out of %max_request maximum allowed requests per day used.', $requests_per_day_options),
    ];

    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Renew product data'),
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $asins = $this->getAsinValues($form_state);
    if (count($asins) > static::MAX_ASINS) {
      $form_state->setErrorByName('asins', $this->t('Maximum of %max_asins ASINs allowed.', ['%max_asins' => static::MAX_ASINS]));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $asins = $this->getAsinValues($form_state);
    try {
      $product_data = $this->productService->getProductData($asins, TRUE);
    }
    catch (AmazonServiceException $e) {
      $this->messenger()->addError($e->getMessage());
      $form_state->setRebuild(TRUE);
      return;
    }

    $invalid_asins = [];
    foreach ($product_data as $asin => $data) {
      if (empty($data)) {
        $invalid_asins[] = $asin;
      }
    }
    $valid_asins = array_diff(array_keys($product_data), $invalid_asins);

    $message_options = [
      '%valid_asins' => implode(", ", $valid_asins),
      '%invalid_asins' => implode(", ", $invalid_asins),
    ];

    if (!empty($valid_asins)) {
      $this->messenger()->addStatus($this->t('Renewed product data for: %valid_asins', $message_options));
    }
    if (!empty($invalid_asins)) {
      $this->messenger()->addWarning($this->t('No data available for: %invalid_asins', $message_options));
    }

  }

  /**
   * {@inheritdoc}
   */
  protected function getAsinValues(FormStateInterface $form_state) {
    $asins = [];
    foreach (preg_split('/\n/', $form_state->getValue('asins'), NULL, PREG_SPLIT_NO_EMPTY) as $row) {
      $asins = array_merge($asins, explode(",", $row));
    }

    $asins = array_map('trim', $asins);
    return $asins;
  }

}
