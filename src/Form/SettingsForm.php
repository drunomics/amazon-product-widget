<?php

namespace Drupal\amazon_product_widget\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure Amazon PA API for this site.
 */
class SettingsForm extends ConfigFormBase {

  const CONFIG_NAME = 'amazon_product_widget.settings';

  /**
   * The settings keys in `amazon_product_widget.settings`.
   */
  const SETTINGS_MAX_REQUESTS_PER_DAY     = 'max_requests_per_day';
  const SETTINGS_MAX_REQUESTS_PER_SEC     = 'max_requests_per_second';
  const SETTINGS_RENDER_MAX_AGE           = 'render_max_age';
  const SETTINGS_CALL_TO_ACTION_TEXT      = 'call_to_action_text';
  const SETTINGS_PRICE_DECIMAL_SEPARATOR  = 'price_decimal_separator';
  const SETTINGS_PRICE_THOUSAND_SEPARATOR = 'price_thousand_separator';
  const SETTINGS_FILL_UP_WITH_FALLBACK    = 'fill_up_with_fallback';
  const SETTINGS_AMAZON_API_DISABLED      = 'amazon_api_disabled';

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      static::CONFIG_NAME,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'amazon_product_widget_settings';
  }

  /**
   * Gets all available settings keys.
   *
   * @return array
   *
   * @throws \ReflectionException
   */
  public static function getAvailableSettingsKeys() {
    $settings_keys = [];
    $reflect = new \ReflectionClass(static::class);
    foreach ($reflect->getConstants() as $key => $value) {
      if (strpos($key, 'SETTINGS_') === 0) {
        $settings_keys[$key] = $value;
      }
    }

    return $settings_keys;
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['description'] = [
      '#markup' => $this->t('Amazon has very specific request limit, which also vary per Amazon Partner Account. Please check back with Amazon before changing request limits as otherwise if the site goes over the threshold the account could be blocked.'),
    ];

    $form['request_limits_group'] = array(
      '#type' => 'fieldset',
      '#title' => t('Request limits'),
    );

    $config = $this->config(static::CONFIG_NAME);

    $form['request_limits_group'][static::SETTINGS_MAX_REQUESTS_PER_DAY] = [
      '#type' => 'number',
      '#title' => $this->t('Max requests per day'),
      '#required' => TRUE,
      '#min' => 0,
      '#step' => 1,
      '#default_value' => $config->get(static::SETTINGS_MAX_REQUESTS_PER_DAY),
      '#description' => $this->t('Amazons own request per day limit (default 8640)'),
    ];

    $form['request_limits_group'][static::SETTINGS_MAX_REQUESTS_PER_SEC] = [
      '#type' => 'number',
      '#title' => $this->t('Max requests per second'),
      '#required' => TRUE,
      '#min' => 1,
      '#step' => 1,
      '#default_value' => $config->get(static::SETTINGS_MAX_REQUESTS_PER_SEC),
      '#description' => $this->t('Amazons own request per second limit (default 1)'),
    ];

    $form[static::SETTINGS_RENDER_MAX_AGE] = [
      '#type' => 'number',
      '#title' => $this->t('Render max age'),
      '#required' => TRUE,
      '#min' => -1,
      '#step' => 1,
      '#default_value' => $config->get(static::SETTINGS_RENDER_MAX_AGE),
      '#description' => $this->t('Render cache for the widget in seconds (3600 is suggested)'),
    ];

    $form[static::SETTINGS_CALL_TO_ACTION_TEXT] = [
      '#type' => 'textfield',
      '#title' => $this->t('Call to action text'),
      '#required' => TRUE,
      '#default_value' => $config->get(static::SETTINGS_CALL_TO_ACTION_TEXT),
      '#description' => $this->t('The text on the call-to-action button which links to the amazon page of the product (e.g.: Buy)'),
    ];

    $form['price_group'] = array(
      '#type' => 'fieldset',
      '#title' => t('Price formatting'),
    );

    $form['price_group'][static::SETTINGS_PRICE_DECIMAL_SEPARATOR] = [
      '#type' => 'textfield',
      '#title' => $this->t('Decimal separator'),
      '#required' => FALSE,
      '#size' => 3,
      '#maxlength' => 1,
      '#default_value' => $config->get(static::SETTINGS_PRICE_DECIMAL_SEPARATOR),
    ];

    $form['price_group'][static::SETTINGS_PRICE_THOUSAND_SEPARATOR] = [
      '#type' => 'textfield',
      '#title' => $this->t('Thousand separator'),
      '#required' => FALSE,
      '#size' => 3,
      '#maxlength' => 1,
      '#default_value' => $config->get(static::SETTINGS_PRICE_THOUSAND_SEPARATOR),
    ];

    $form[static::SETTINGS_FILL_UP_WITH_FALLBACK] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Fill up with fallback search term'),
      '#default_value' => $config->get(static::SETTINGS_FILL_UP_WITH_FALLBACK),
      '#description' => $this->t('Search terms will always fill up to 3 products even if only one ASIN was entered into the widget.'),
    ];

    $form[static::SETTINGS_AMAZON_API_DISABLED] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Disable Amazon API'),
      '#default_value' => $config->get(static::SETTINGS_AMAZON_API_DISABLED),
      '#description' => $this->t('Use this to disable api calls, e.g. on ci or any non production environments to prevent using up requests.'),
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
