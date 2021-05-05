<?php

namespace Drupal\amazon_product_widget\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Provides a form to administer amazon deals settings.
 */
class DealFeedSettingsForm extends ConfigFormBase {

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
    // TODO: Implement getEditableConfigNames() method.
  }

  /**
   * {@inheritDoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['feedUrl'] = [
      '#type' => 'url',
      '#title' => $this->t('Feed URL'),
      '#description' => $this->t('URL of the data feed.'),
    ];

    $form['username'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Username'),
      '#description' => $this->t('Username for accessing the feed.'),
    ];

    $form['password'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Password'),
      '#description' => $this->t('Password for accessing the feed.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritDoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // TODO: Implement submitForm() method.
  }

}