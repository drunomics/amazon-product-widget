<?php

namespace Drupal\amazon_product_widget\Plugin\views\field;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\views\Plugin\views\display\DisplayPluginBase;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;
use Drupal\views\ViewExecutable;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Shows the entity URL.
 *
 * @ViewsField("amazon_product_widget_entity_url")
 *
 * @package Drupal\amazon_product_widget\Plugin\views\field
 */
class EntityUrl extends FieldPluginBase {

  use StringTranslationTrait;

  /**
   * Entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * {@inheritDoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entityTypeManager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entityTypeManager;
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
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
  public function render(ResultRow $values) {
    $entityId = $values->amazon_product_widget_asin_map_entity_id ?? NULL;
    $entityType = $values->amazon_product_widget_asin_map_entity_type ?? NULL;
    if ($entityId && $entityType) {
      try {
        $storage = $this->entityTypeManager->getStorage($entityType);
        $entity  = $storage->load($entityId);
        if (!$entity) {
          return parent::render($values);
        }
        return [
          'entity_link' => [
            '#type'  => 'link',
            '#title' => $entity->label(),
            '#url'   => $entity->toUrl('edit-form'),
          ],
        ];
      }
      catch (\Exception $exception) {
        watchdog_exception('amazon_product_widget', $exception);
      }
    }
  }

}
