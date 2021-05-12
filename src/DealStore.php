<?php

namespace Drupal\amazon_product_widget;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;

/**
 * Class DealStore.
 *
 * @package Drupal\amazon_product_widget
 */
class DealStore {

  /**
   * The deal database table.
   */
  const TABLE = 'amazon_product_widget_deal_feed';

  /**
   * Possible values for the 'deal_status' column.
   */
  const DEAL_STATUS_UNKNOWN = 0;
  const DEAL_STATUS_AVAILABLE = 1;
  const DEAL_STATUS_UPCOMING = 2;

  /**
   * Database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $connection;

  /**
   * Time interface.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected $time;

  /**
   * DealStore constructor.
   *
   * @param \Drupal\Core\Database\Connection $connection
   *   Database connection.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   Time interface.
   */
  public function __construct(Connection $connection, TimeInterface $time) {
    $this->connection = $connection;
    $this->time = $time;
  }

  /**
   * Inserts or updates a deal.
   *
   * @param array $deal
   *   The deal to insert or update.
   *
   * @return \Drupal\Core\Database\StatementInterface|int|null
   *   Returns STATUS_INSERT, STATUS_UPDATE or NULL
   *
   * @throws \Exception
   */
  public function insertOrUpdate($deal) {
    return $this->connection->merge(self::TABLE)
      ->key('asin', $deal['asin'])
      ->fields([
        'asin'        => $deal['asin'],
        'created'     => $this->time->getRequestTime(),
        'updated'     => $this->time->getRequestTime(),
        'deal_start'  => $deal['deal_start'],
        'deal_end'    => $deal['deal_end'],
        'deal_price'  => $deal['deal_price'],
        'deal_status' => $deal['deal_status'],
      ])
      ->expression('created', 'created')
      ->execute();
  }

  /**
   * Gets a non-expired, currently running deal from the database.
   *
   * @param string $asin
   *   The ASIN for which to fetch deal information.
   *
   * @return array
   *   Returns an array with deal information, empty array if none found.
   */
  public function getActiveDeal(string $asin) {
    $requestTime = $this->time->getRequestTime();
    $deal = $this->connection->select(self::TABLE, 'ta')
      ->fields('ta', ['deal_price'])
      ->condition('asin', $asin)
      ->condition('deal_start', $requestTime, '<')
      ->condition('deal_end', $requestTime, '>=')
      ->condition('deal_status', self::DEAL_STATUS_AVAILABLE)
      ->execute()
      ->fetchAssoc();

    if ($deal) {
      return $deal;
    }
    return [];
  }

  /**
   * Gets deal by ASIN, disregarding if it is active or not.
   *
   * @param string $asin
   *   The ASIN.
   *
   * @return array
   *   The deal information.
   */
  public function getByAsin(string $asin) {
    $deal = $this->connection->select(self::TABLE, 'ta')
      ->fields('ta')
      ->condition('asin', $asin)
      ->execute()
      ->fetchAssoc();

    if ($deal) {
      return $deal;
    }
    return [];
  }

  /**
   * Prettifies the deal information returned from the database.
   *
   * @param array $deal
   *   The deal array.
   *
   * @return array
   *   The same array but with some values formatted.
   */
  public function prettifyDeal(array $deal) {
    if ($this->validateDeal($deal)) {
      $deal['deal_start']  = $this->fromTimestamp($deal['deal_start']);
      $deal['deal_end']    = $this->fromTimestamp($deal['deal_end']);
      $deal['deal_status'] = $this->statusToString($deal['deal_status']);
    }
    return $deal;
  }

  /**
   * Validates a deal.
   *
   * @param array $deal
   *   The deal array.
   *
   * @return bool
   *   TRUE if the deal is valid, FALSE otherwise.
   */
  public function validateDeal(array $deal) {
    if (isset($deal['asin']) && isset($deal['deal_start']) && isset($deal['deal_end']) && isset($deal['deal_price']) && isset($deal['deal_status'])) {
      return TRUE;
    }
    return FALSE;
  }

  /**
   * Converts a timestamp to a desired format.
   *
   * @param int $timestamp
   *   The UNIX timestamp.
   * @param string $format
   *   The format. (default is DATE_RFC2822)
   *
   * @return string|null
   *   The formatted date or NULL if an error occured.
   */
  public function fromTimestamp(int $timestamp, string $format = DATE_RFC2822) {
    try {
      $dateTime = new \DateTime();
      $dateTime->setTimestamp($timestamp);
      return $dateTime->format($format);
    }
    catch(\Throwable $exception) {
      watchdog_exception('amazon_product_widget', $exception);
      return NULL;
    }
  }

  /**
   * Converts a deal status into a human readable string.
   *
   * @param int $status
   *   The status as returned by the deal store.
   *
   * @return string
   *   The status in human readable form.
   */
  public function statusToString(int $status) {
    switch ($status) {
      case DealStore::DEAL_STATUS_AVAILABLE:
        return 'AVAILABLE';

      case DealStore::DEAL_STATUS_UPCOMING:
        return 'UPCOMING';

      default:
        return 'UNKNOWN';
    }
  }

  /**
   * Converts the deal status from a string to a numeric value.
   *
   * @param string $status
   *   The deal status as returned by Amazon.
   *
   * @return int
   *   The numeric value.
   */
  public function statusToNumber(string $status) {
    switch ($status) {
      case 'AVAILABLE':
        return DealStore::DEAL_STATUS_AVAILABLE;

      case 'UPCOMING':
        return DealStore::DEAL_STATUS_UPCOMING;

      default:
        return DealStore::DEAL_STATUS_UNKNOWN;
    }
  }

  /**
   * Returns the number of active deals in the storage.
   *
   * @return int
   *   Active deals count.
   */
  public function getActiveCount() {
    $query = $this->connection->select(self::TABLE, 'ta');
    $query->condition('deal_status', self::DEAL_STATUS_AVAILABLE);
    return $query->countQuery()->execute()->fetchField();
  }

  /**
   * Returns the total number of deals in the database.
   *
   * @return int
   *   Deals count.
   */
  public function getCount() {
    $query = $this->connection->select(self::TABLE, 'ta');
    return $query->countQuery()->execute()->fetchField();
  }

}
