<?php

namespace Drupal\amazon_product_widget\Plugin\views\filter;

use Drupal\amazon_product_widget\DealFeedServiceTrait;
use Drupal\amazon_product_widget\DealStore;
use Drupal\Core\Form\FormStateInterface;
use Drupal\views\Plugin\views\filter\InOperator;

/**
 * Shows a dropdown to filter by deal status.
 *
 * @ViewsFilter("amazon_product_widget_deal_status")
 *
 * @package Drupal\amazon_product_widget\Plugin\views\filter
 */
class DealStatusFilter extends InOperator {

  use DealFeedServiceTrait;

  /**
   * {@inheritDoc}
   */
  public function getValueOptions() {
    if (!isset($this->valueOptions)) {
      $this->valueOptions = $this->getDealService()->getDealStore()->statusList();
    }
    return $this->valueOptions;
  }

}
