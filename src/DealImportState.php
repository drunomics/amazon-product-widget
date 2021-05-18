<?php

namespace Drupal\amazon_product_widget;

/**
 * Stores the state of import.
 *
 * @package Drupal\amazon_product_widget
 */
class DealImportState {

  /**
   * Processed entries.
   *
   * @var int
   */
  public $processed;

  /**
   * Erroneous entries.
   *
   * @var int
   */
  public $errors;

  /**
   * Indicates whether the import is finished.
   *
   * @var bool
   */
  public $finished;

  /**
   * DealImportState constructor.
   *
   * @param int $processed
   *   Processed entries.
   * @param int $errors
   *   Erroneous entries.
   * @param bool $finished
   *   Whether the import is finished.
   */
  public function __construct(int $processed, int $errors, bool $finished = FALSE) {
    $this->processed = $processed;
    $this->errors = $errors;
    $this->finished = $finished;
  }

}
