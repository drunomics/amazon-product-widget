<?php

namespace Drupal\amazon_product_widget;

use DateTime;
use Drupal\amazon_product_widget\Exception\AmazonDealApiDisabledException;
use Drupal\amazon_product_widget\Exception\AmazonServiceException;
use Drupal\amazon_product_widget\Exception\DealFeedFinishedProcessingException;
use Drupal\amazon_product_widget\Form\DealFeedSettingsForm;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use GuzzleHttp\Client as GuzzleClient;
use Symfony\Component\Filesystem\Exception\FileNotFoundException;

/**
 * Class DealFeedService.
 *
 * @package Drupal\amazon_product_widget
 */
class DealFeedService {

  use StringTranslationTrait;

  /**
   * Where the values are located in the CSV returned by Amazon.
   */
  const DEAL_KEY_STATUS = 2;
  const DEAL_KEY_ASIN = 4;
  const DEAL_KEY_START = 6;
  const DEAL_KEY_END = 7;
  const DEAL_KEY_PRICE = 9;

  /**
   * Maximum number of validation / insert errors with deals before quitting.
   */
  const MAX_INVALID_DEALS = 100;

  /**
   * How many entries to import on each call to importChunked.
   */
  const MAX_IMPORT_ENTRIES = 1000;

  /**
   * Amazon product widget deal feed settings.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $settings;

  /**
   * Guzzle http client.
   *
   * @var \GuzzleHttp\Client
   */
  protected $httpClient;

  /**
   * File system.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * Deal store.
   *
   * @var \Drupal\amazon_product_widget\DealStore
   */
  protected $dealStore;

  /**
   * Lock.
   *
   * @var \Drupal\Core\Lock\LockBackendInterface
   */
  protected $lock;

  /**
   * DealFeedService constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config
   *   Config factory.
   * @param \GuzzleHttp\Client $httpClient
   *   Guzzle client.
   * @param \Drupal\Core\File\FileSystemInterface $fileSystem
   *   File system.
   * @param \Drupal\amazon_product_widget\DealStore $dealStore
   *   Deal store.
   * @param \Drupal\Core\Lock\LockBackendInterface $lock
   *   Lock.
   */
  public function __construct(ConfigFactoryInterface $config, GuzzleClient $httpClient, FileSystemInterface $fileSystem, DealStore $dealStore, LockBackendInterface $lock) {
    $this->settings = $config->get('amazon_product_widget.deal_settings');
    $this->httpClient = $httpClient;
    $this->fileSystem = $fileSystem;
    $this->dealStore = $dealStore;
    $this->lock = $lock;
  }

  /**
   * Gets an active deal for the ASIN.
   *
   * @param string $asin
   *   The ASIN.
   *
   * @return float|null
   *   Returns the deal price for that product, NULL if no deal available.
   */
  public function get(string $asin) {
    $deal = $this->dealStore->get($asin);
    if (count($deal)) {
      return $deal['deal_price'];
    }

    return NULL;
  }

  /**
   * Imports the CSV into storage.
   *
   * @param string $path
   *   Path to the CSV file.
   * @param int $start
   *   Where to start reading the file.
   * @param int $entries
   *   How many entries to import. (default is all)
   *
   * @return int
   *   The amount of invalid deals that were encountered.
   *
   * @throws \Drupal\amazon_product_widget\Exception\DealFeedFinishedProcessingException
   */
  public function import(string $path, int $start = 0, $entries = -1) {
    $invalidDeals = 0;
    $fileHandle = NULL;
    try {
      $fileHandle = new \SplFileObject($path);
    }
    catch (\Exception $exception) {
      watchdog_exception('amazon_product_widget', $exception);
      return 0;
    }

    $current = ($start === 0) ? 1 : $start;
    $processedLines = 0;
    while (true) {
      $fileHandle->seek($current);

      if (FALSE === $fileHandle->valid()) {
        throw new DealFeedFinishedProcessingException();
      }

      $line = $fileHandle->getCurrentLine();
      if ($line === FALSE) {
        $invalidDeals += 1;
        $current += 1;
        $processedLines += 1;
        continue;
      }

      $row = str_getcsv($line);

      $dealStart = $this->dateToLocalTimestamp($row[self::DEAL_KEY_START]);
      $dealEnd   = $this->dateToLocalTimestamp($row[self::DEAL_KEY_END]);
      $status    = $this->dealStore->statusToNumber($row[self::DEAL_KEY_STATUS]);
      $asin      = $row[self::DEAL_KEY_ASIN];
      $price     = $row[self::DEAL_KEY_PRICE];

      // Some deals do not have an ASIN, they are a hub page for various
      // collections of deals, these do not have an ASIN, we skip them.
      if (!amazon_product_widget_is_valid_asin($asin)) {
        $processedLines += 1;
        $current += 1;
        continue;
      }

      if ($dealEnd === NULL || $dealStart === NULL) {
        $invalidDeals += 1;
        $processedLines += 1;
        $current += 1;
      }
      else {
        try {
          $this->dealStore->insertOrUpdate([
            'asin' => $asin,
            'deal_start' => $dealStart,
            'deal_end' => $dealEnd,
            'deal_status' => $status,
            'deal_price' => $price,
          ]);
        } catch (\Exception $exception) {
          $invalidDeals += 1;
        }
      }
      $processedLines += 1;
      if ($entries !== -1 && $processedLines >= $entries) {
        break;
      }
      else {
        $current += 1;
      }
    }

    return $invalidDeals;
  }

  /**
   * Connects to the Amazon API and downloads the CSV containing the deals.
   *
   * @param string $destination
   *   Where to download the file. (default is temporary directory)
   *
   * @return string
   *   The path to the file where the CSV was stored.
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   * @throws \Drupal\amazon_product_widget\Exception\AmazonServiceException
   */
  public function downloadDealsCsv($destination = NULL) {
    $username = $this->settings->get(DealFeedSettingsForm::SETTINGS_DEAL_FEED_USERNAME);
    $password = $this->settings->get(DealFeedSettingsForm::SETTINGS_DEAL_FEED_PASSWORD);
    $parsedUrl = parse_url($this->settings->get(DealFeedSettingsForm::SETTINGS_DEAL_FEED_URL));

    // Need to pass username and password in URL as well as in digest, otherwise
    // Amazon throws a fit.
    $url = 'https://' . $username . ':' . $password . '@' . $parsedUrl['host'] . $parsedUrl['path'] . '?' . $parsedUrl['query'];

    $response = $this->httpClient->request('GET', $url, [
      'allow_redirects' => FALSE,
      'http_errors' => FALSE,
      'auth' => [
        $username,
        $password,
        'digest',
      ],
    ]);

    // First response will be 302 redirecting us to an URL with proper signed
    // parameters where we can download the file.
    if ($response->getStatusCode() === 302) {
      $location = $response->getHeader('Location');
      if (count($location) < 1) {
        throw new \RuntimeException('Unexpected redirect response from Amazon.');
      }
      $location = reset($location);

      $temporaryDirectory = $this->fileSystem->getTempDirectory();
      $filename = substr(uniqid(), 0, 8);
      $temporaryFile = $temporaryDirectory . '/' . $filename;
      $destinationFile = $destination ? $destination : ($temporaryDirectory . '/' . $filename . '.csv');

      $response = $this->httpClient->get($location, [
        'sink' => $temporaryFile,
      ]);

      if ($response->getStatusCode() === 200) {
        if (!file_exists($temporaryFile)) {
          throw new FileNotFoundException("Could not download deal feed file.");
        }
        $this->extractGzip($temporaryFile, $destinationFile, TRUE);
        return $destinationFile;
      }
    }
    throw new AmazonServiceException('Could not download CSV file from Amazon.');
  }

  /**
   * Updates the deal feed.
   *
   * If $path is passed, that file will be used to update the deal store,
   * otherwise the file will be downloaded through an API call to Amazon.
   *
   * @param string $path
   *   The path to the CSV file. (optional)
   *
   * @throws \Drupal\amazon_product_widget\Exception\AmazonDealApiDisabledException
   * @throws \GuzzleHttp\Exception\GuzzleException
   * @throws \Drupal\amazon_product_widget\Exception\AmazonServiceException
   */
  public function update(string $path = NULL) {
    $active = $this->settings->get(DealFeedSettingsForm::SETTINGS_DEAL_FEED_ACTIVE);
    if (!$active) {
      throw new AmazonDealApiDisabledException();
    }

    if ($path) {
      $importPath = $path;
    }
    else {
      $importPath = $this->downloadDealsCsv();
    }
    $this->startBatchImport($importPath);
  }

  /**
   * Starts a batch import.
   *
   * @param string $path
   *   Path to the CSV to import.
   */
  protected function startBatchImport(string $path) {
    if (!file_exists($path)) {
      throw new FileNotFoundException("File at path '$path' does not exist.");
    }

    $totalDeals = count(file($path)) - 1;
    $batch = [
      'title' => $this->t('Parsing and importing CSV file.'),
      'init_message' => $this->t('Preparing to import @total deals into the database.', [
        '@total' => $totalDeals,
      ]),
      'progress_message' => '',
      'finished' => '\Drupal\amazon_product_widget\BatchDealImportService::finishImport',
      'batch_redirect' => Url::fromRoute('amazon_product_widget.deal_feed_settings_form'),
    ];
    $batch['operations'][] = [
      '\Drupal\amazon_product_widget\BatchDealImportService::importChunked', [
        $path,
        $totalDeals,
      ],
    ];
    batch_set($batch);
  }

  /**
   * Decompresses $source to $destination.
   *
   * @param string $source
   *   The source path to the file.
   * @param string $destination
   *   The destination path to the file.
   * @param bool $deleteSource
   *   Whether to delete the source file when done. (default: FALSE)
   */
  protected function extractGzip(string $source, string $destination, bool $deleteSource = FALSE) {
    if (file_exists($source) === FALSE) {
      throw new FileNotFoundException("Source file '$source' does not exist.");
    }

    $gzipHandle = gzopen($source, 'rb');
    if ($gzipHandle === FALSE) {
      throw new \RuntimeException("Could not open '$source' file for unzipping.");
    }

    $outputHandle = fopen($destination, 'wb+');
    if ($outputHandle === FALSE) {
      throw new \RuntimeException("Could not open '$destination' file for writing.");
    }

    while (gzeof($gzipHandle) === FALSE) {
      fwrite($outputHandle, gzread($gzipHandle, 4096));
    }
    fclose($outputHandle);
    gzclose($gzipHandle);

    if ($deleteSource) {
      $this->fileSystem->delete($source);
    }
  }

  /**
   * Converts the provided formatted date into local time.
   *
   * @param string $formatted
   *   Formatted datetime string.
   *
   * @return int|null
   *   Unix timestamp (local timezone), or NULL on failure.
   */
  protected function dateToLocalTimestamp($formatted) {
    try {
      $localTimezone = (new DateTime())->getTimezone();
      $dateTime = new DateTime($formatted);
      $dateTime->setTimezone($localTimezone);

      return $dateTime->getTimestamp();
    }
    catch (\Exception $exception) {
      return NULL;
    }
  }

  /**
   * Returns the deal store.
   *
   * @return \Drupal\amazon_product_widget\DealStore
   *   Deal store.
   */
  public function getDealStore() {
    return $this->dealStore;
  }

  /**
   * Returns the max time that should be taken processing a chunk of CSV.
   *
   * @return int
   *   Seconds.
   */
  public function getMaxProcessingTime() {
    return $this->settings->get(DealFeedSettingsForm::SETTINGS_MAX_CSV_PROCESSING_TIME);
  }

  /**
   * Returns the maximum number of import errors before stopping.
   *
   * @return int
   *   The number of errors.
   */
  public function getMaxDealImportErrors() {
    return self::MAX_INVALID_DEALS;
  }

  /**
   * Returns the cron interval (in minutes).
   *
   * @return int
   *   Cron interval in minutes.
   */
  public function getCronInterval() {
    return $this->settings->get('deal_cron_interval');
  }

}
