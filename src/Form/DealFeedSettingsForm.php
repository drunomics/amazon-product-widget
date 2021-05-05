<?php

namespace Drupal\amazon_product_widget\Form;

use Drupal\amazon_product_widget\ConfigSettingsTrait;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Provides a form to administer amazon deals settings.
 */
class DealFeedSettingsForm extends ConfigFormBase {

  use ConfigSettingsTrait;

  const CONFIG_NAME = 'amazon_product_widget.deal_settings';

  /**
   * Settings in 'amazon_product_widget.deal_settings'.
   */
  const SETTINGS_DEAL_FEED_URL = 'deal_feed_url';
  const SETTINGS_DEAL_FEED_USERNAME = 'deal_feed_username';
  const SETTINGS_DEAL_FEED_PASSWORD = 'deal_feed_password';
  const SETTINGS_DEAL_FEED_ACTIVE = 'deal_feed_active';

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

    $form[static::SETTINGS_DEAL_FEED_URL] = [
      '#type' => 'url',
      '#title' => $this->t('Feed URL'),
      '#description' => $this->t('URL of the data feed.'),
      '#default_value' => $config->get(static::SETTINGS_DEAL_FEED_URL),
    ];

    $form[static::SETTINGS_DEAL_FEED_USERNAME] = [
      '#type' => 'textfield',
      '#title' => $this->t('Username'),
      '#description' => $this->t('Username for accessing the feed.'),
      '#default_value' => $config->get(static::SETTINGS_DEAL_FEED_USERNAME),
    ];

    $form[static::SETTINGS_DEAL_FEED_PASSWORD] = [
      '#type' => 'textfield',
      '#title' => $this->t('Password'),
      '#description' => $this->t('Password for accessing the feed.'),
      '#default_value' => $config->get(static::SETTINGS_DEAL_FEED_PASSWORD),
    ];

    $form[static::SETTINGS_DEAL_FEED_ACTIVE] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Activate Deal Feed'),
      '#description' => $this->t('Whether or not prices will be fetched from deals if available.'),
      '#default_value' => $config->get(static::SETTINGS_DEAL_FEED_ACTIVE),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    parent::submitForm($form, $form_state);

    $config = $this->config(static::CONFIG_NAME);

    foreach (static::getAvailableSettingsKeys() as $settings_key) {
      $config->set($settings_key, $form_state->getValue($settings_key));
    }

    $config->save();
  }

}
