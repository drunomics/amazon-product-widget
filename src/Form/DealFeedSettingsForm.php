<?php

namespace Drupal\amazon_product_widget\Form;

use Drupal\amazon_product_widget\ConfigSettingsTrait;
use Drupal\amazon_product_widget\DealFeedService;
use Drupal\amazon_product_widget\Exception\AmazonDealApiDisabledException;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a form to administer amazon deals settings.
 */
class DealFeedSettingsForm extends ConfigFormBase {

  use ConfigSettingsTrait;

  const CONFIG_NAME = 'amazon_product_widget.deal_settings';

  /**
   * Settings in 'amazon_product_widget.deal_settings'.
   */
  const SETTINGS_MAX_CSV_PROCESSING_TIME = 'max_csv_processing_time';
  const SETTINGS_DEAL_CRON_INTERVAL      = 'deal_cron_interval';
  const SETTINGS_DEAL_FEED_URL           = 'deal_feed_url';
  const SETTINGS_DEAL_FEED_USERNAME      = 'deal_feed_username';
  const SETTINGS_DEAL_FEED_PASSWORD      = 'deal_feed_password';
  const SETTINGS_DEAL_FEED_ACTIVE        = 'deal_feed_active';

  /**
   * The Deal Feed service.
   *
   * @var \Drupal\amazon_product_widget\DealFeedService
   */
  protected $dealService;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * DealFeedSettingsForm constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   Config factory.
   * @param \Drupal\amazon_product_widget\DealFeedService $dealService
   *   Deal feed service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   Entity type manager.
   */
  public function __construct(ConfigFactoryInterface $config_factory, DealFeedService $dealService, EntityTypeManagerInterface $entityTypeManager) {
    parent::__construct($config_factory);

    $this->dealService = $dealService;
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * {@inheritDoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('amazon_product_widget.deal_feed_service'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritDoc}
   */
  public function getFormId() {
    return 'amazon_product_widget_deal_settings_form';
  }

  /**
   * {@inheritDoc}
   */
  protected function getEditableConfigNames() {
    return [
      static::CONFIG_NAME,
    ];
  }

  /**
   * {@inheritDoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config(static::CONFIG_NAME);

    $form['update_deal_feed_status_group'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Deal Feed Status'),
    ];

    $totalDeals  = $this->dealService->getDealStore()->getCount();
    $activeDeals = $this->dealService->getDealStore()->getActiveCount();
    $form['update_deal_feed_status_group']['total_deals'] = [
      '#type' => 'item',
      '#title' => $this->t('<code>@total</code> deals in the store, <code>@active</code> of these are active.', [
        '@total'  => number_format($totalDeals),
        '@active' => number_format($activeDeals),
      ]),
    ];

    $form['update_deal_feed_api_group'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Deal Feed API settings'),
    ];

    $form['update_deal_feed_api_group'][static::SETTINGS_DEAL_FEED_URL] = [
      '#type' => 'url',
      '#title' => $this->t('Feed URL'),
      '#description' => $this->t('URL of the data feed.'),
      '#default_value' => $config->get(static::SETTINGS_DEAL_FEED_URL),
    ];

    $form['update_deal_feed_api_group'][static::SETTINGS_DEAL_FEED_USERNAME] = [
      '#type' => 'textfield',
      '#title' => $this->t('Username'),
      '#description' => $this->t('Username for accessing the feed.'),
      '#default_value' => $config->get(static::SETTINGS_DEAL_FEED_USERNAME),
    ];

    $form['update_deal_feed_api_group'][static::SETTINGS_DEAL_FEED_PASSWORD] = [
      '#type' => 'textfield',
      '#title' => $this->t('Password'),
      '#description' => $this->t('Password for accessing the feed.'),
      '#default_value' => $config->get(static::SETTINGS_DEAL_FEED_PASSWORD),
    ];

    $form['update_deal_feed_api_group'][static::SETTINGS_MAX_CSV_PROCESSING_TIME] = [
      '#type' => 'number',
      '#title' => $this->t('Max CSV processing time'),
      '#description' => $this->t('The maximum amount of time (in seconds) to process a chunk of the Deal Feed CSV.'),
      '#default_value' => $config->get(static::SETTINGS_MAX_CSV_PROCESSING_TIME),
    ];

    $form['update_deal_feed_api_group'][static::SETTINGS_DEAL_CRON_INTERVAL] = [
      '#type' => 'number',
      '#title' => $this->t('Cron interval'),
      '#description' => $this->t('The number of minutes to wait between cron intervals.'),
      '#default_value' => $config->get(static::SETTINGS_DEAL_CRON_INTERVAL),
    ];

    $form['update_deal_feed_api_group'][static::SETTINGS_DEAL_FEED_ACTIVE] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Activate Deal Feed'),
      '#description' => $this->t('Whether or not prices will be fetched from deals if available.'),
      '#default_value' => $config->get(static::SETTINGS_DEAL_FEED_ACTIVE),
    ];


    $form['update_deal_feed_group'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Update Deal Feed'),
    ];

    $form['update_deal_feed_group']['api_description'] = [
      '#type' => 'item',
      '#description' => $this->t('Updates the Deal Feed using the credentials provided above. (may take a while, use sparingly)'),
    ];

    $form['update_deal_feed_group']['source_api'] = [
      '#type' => 'submit',
      '#name' => 'source_api',
      '#value' => $this->t('Update from API now'),
    ];

    $form['update_deal_feed_group']['source_csv_file'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Update from CSV'),
      '#description' => $this->t('Updates the Deal Feed using a CSV source.'),
      '#upload_validators' => [
        'file_validate_extensions' => ['csv'],
      ],
    ];

    $form['update_deal_feed_group']['source_csv'] = [
      '#type' => 'submit',
      '#name' => 'source_csv',
      '#value' => $this->t('Update from CSV'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritDoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $triggering = $form_state->getTriggeringElement()['#name'];
    $csvFile = $form_state->getValue('source_csv_file');

    if ($triggering === 'source_csv' && count($csvFile) === 0) {
      $form_state->setErrorByName(
        'source_csv',
        $this->t('Please provide a valid CSV file to import.')
      );
    }
  }

  /**
   * {@inheritdoc}
   *
   * @throws \ReflectionException
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $triggering = $form_state->getTriggeringElement()['#name'];
    $importPath = NULL;
    if ($triggering !== 'op') {
      try {
        if ($triggering === 'source_csv') {
          $fileId = $form_state->getValue('source_csv_file')[0];
          /** @var \Drupal\file\FileInterface $managedFile */
          $managedFile = $this->entityTypeManager->getStorage('file')->load($fileId);

          if ($managedFile) {
            $importPath = $managedFile->getFileUri();
          }
        }

        $this->dealService->update($importPath);
        $this->messenger()->addStatus(
          $this->t('Deal Feed has been updated.')
        );
      }
      catch (AmazonDealApiDisabledException $exception) {
        $this->messenger()->addError(
          $this->t('The Deal Feed API is disabled. Please enable it and try again.')
        );
      }
      catch (\Throwable $exception) {
        $this->messenger()->addError(
          $this->t('An error has occurred: @message', [
            '@message' => $exception->getMessage(),
          ])
        );
      }
    }
    else {
      parent::submitForm($form, $form_state);
      $config = $this->config(static::CONFIG_NAME);
      foreach (static::getAvailableSettingsKeys() as $settings_key) {
        $config->set($settings_key, $form_state->getValue($settings_key));
      }
      $config->save();
    }
  }

}
