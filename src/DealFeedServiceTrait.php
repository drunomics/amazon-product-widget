<?php

namespace Drupal\amazon_product_widget;

/**
 * Trait to provide deal service.
 */
trait DealFeedServiceTrait {

  /**
   * Deal service.
   *
   * @var \Drupal\amazon_product_widget\DealFeedService
   */
  protected $dealService;

  /**
   * Returns an instance of DealFeedService.
   *
   * @return \Drupal\amazon_product_widget\DealFeedService
   */
  public function getDealService() {
    if (!$this->dealService) {
      $this->dealService = \Drupal::service('amazon_product_widget.deal_feed_service');
    }
    return $this->dealService;
  }

}
