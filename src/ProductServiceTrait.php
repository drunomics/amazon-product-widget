<?php

namespace Drupal\amazon_product_widget;

/**
 * Trait to provide product service.
 */
trait ProductServiceTrait {

  /**
   * Product service.
   *
   * @var \Drupal\amazon_product_widget\ProductService
   */
  protected $productService;

  /**
   * Returns an instance of ProductService.
   *
   * @return \Drupal\amazon_product_widget\ProductService
   */
  public function getProductService() {
    if (!$this->productService) {
      $this->productService = \Drupal::service('amazon_product_widget.product_service');
    }
    return $this->productService;
  }

}
