<?php

namespace Drupal\amazon_product_widget\Form;

use Drupal\amazon_product_widget\DealFeedService;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class DealFeedCheckForm.
 *
 * @package Drupal\amazon_product_widget\Form
 */
class DealFeedCheckForm extends FormBase implements ContainerInjectionInterface {

  /**
   * The deal feed service.
   *
   * @var \Drupal\amazon_product_widget\DealFeedService
   */
  protected $dealFeedService;

  /**
   * DealFeedCheckForm constructor.
   *
   * @param \Drupal\amazon_product_widget\DealFeedService $dealFeedService
   *   The deal feed service.
   */
  public function __construct(DealFeedService $dealFeedService) {
    $this->dealFeedService = $dealFeedService;
  }

  /**
   * {@inheritDoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('amazon_product_widget.deal_feed_service')
    );
  }

  /**
   * {@inheritDoc}
   */
  public function getFormId() {
    return 'amazon_product_widget_deal_check_form';
  }

  /**
   * {@inheritDoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['asin'] = [
      '#type' => 'textfield',
      '#title' => $this->t('ASIN'),
      '#description' => $this->t('ASIN of the product to check for deals.'),
    ];

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Check'),
    ];

    $form['output'] = [
      '#type' => 'textarea',
      '#disabled' => TRUE,
      '#default_value' => $form_state->getValue('output', ''),
      '#attributes' => [
        'style' => [
          'min-height: 200px',
        ],
      ],
    ];

    return $form;
  }

  /**
   * {@inheritDoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    if (FALSE === amazon_product_widget_is_valid_asin($form_state->getValue('asin'))) {
      $form_state->setErrorByName('asin', $this->t('Please provide a valid ASIN.'));
    }
  }

  /**
   * {@inheritDoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $asin = $form_state->getValue('asin');
    $deal = $this->dealFeedService->getDealStore()->getByAsin($asin);
    $deal = $this->dealFeedService->getDealStore()->prettifyDeal($deal);

    if ($deal) {
      $form_state->setValue('output', var_export($deal, TRUE));
    }
    else {
      $form_state->setValue('output', $this->t('No deal found for @asin.', [
        '@asin' => $asin,
      ]));
    }
    $form_state->setRebuild();
  }

}
