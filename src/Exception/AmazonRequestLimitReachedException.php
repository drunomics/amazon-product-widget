<?php

namespace Drupal\amazon_product_widget\Exception;

use Throwable;

/**
 * Amazon API request limit reached.
 */
class AmazonRequestLimitReachedException extends AmazonServiceException {

  /**
   * {@inheritDoc}
   */
  public function __construct($message = "Maximum number of requests per day to Amazon API reached.", $code = 0, Throwable $previous = null) {
    parent::__construct($message, $code, $previous);
  }

}
