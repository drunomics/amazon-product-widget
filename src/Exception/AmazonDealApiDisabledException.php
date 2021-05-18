<?php

namespace Drupal\amazon_product_widget\Exception;

use Throwable;

/**
 * Amazon Deal API disabled.
 */
class AmazonDealApiDisabledException extends AmazonServiceException {

  /**
   * {@inheritDoc}
   */
  public function __construct($message = "Amazon Deal Api endpoint disabled via config setting `amazon_product_widget.deal_feed_active`.", $code = 0, Throwable $previous = null) {
    parent::__construct($message, $code, $previous);
  }

}
