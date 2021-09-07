<?php

namespace Drupal\amazon_product_widget\Plugin\views\field;

use Drupal\amazon_product_widget\ProductUsageService;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\views\Plugin\views\display\DisplayPluginBase;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;
use Drupal\views\ViewExecutable;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Shows the unavailable ASINs for the Entity.
 *
 * @ViewsField("amazon_product_widget_unavailable_asins")
 *
 * @package Drupal\amazon_product_widget\Plugin\views\field
 */
class UnavailableAsins extends FieldPluginBase {

  use StringTranslationTrait;

  /**
   * Entity type manager.
   *
   * @var \Drupal\amazon_product_widget\ProductUsageService
   */
  protected $usageService;

  /**
   * Product usage service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * {@inheritDoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, ProductUsageService $usageService, EntityTypeManagerInterface $entityTypeManager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->usageService = $usageService;
    $this->entityTypeManager = $entityTypeManager;
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('amazon_product_widget.usage'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritDoc}
   */
  public function init(ViewExecutable $view, DisplayPluginBase $display, array &$options = NULL) {
    parent::init($view, $display, $options);
  }

  /**
   * {@inheritDoc}
   */
  public function query() {
    return;
  }

  /**
   * {@inheritDoc}
   */
  public function render(ResultRow $values) {
    $entityId = $values->amazon_product_widget_asin_map_entity_id ?? NULL;
    $entityType = $values->amazon_product_widget_asin_map_entity_type ?? NULL;
    if ($entityId) {
      try {
        $storage = $this->entityTypeManager->getStorage($entityType);
        $entity  = $storage->load($entityId);
        if (!$entity) {
          return parent::render($values);
        }

        $unavailableAsins = $this->usageService->getUnavailableAsinsForEntity($entity);
        return join(', ', $unavailableAsins);
      }
      catch (\Exception $exception) {
        watchdog_exception('amazon_product_widget', $exception);
      }
    }

    return '';
  }

}
