<?php

namespace Drupal\amazon_product_widget\Exception;

use Throwable;

/**
 * Amazon API request limit reached.
 */
class AmazonApiDisabledException extends AmazonServiceException {

  /**
   * {@inheritDoc}
   */
  public function __construct($message = "Amazon Api endpoint disabled via config setting `amazon_product_widget.settings.amazon_api_disabled`.", $code = 0, Throwable $previous = null) {
    parent::__construct($message, $code, $previous);
  }

}
