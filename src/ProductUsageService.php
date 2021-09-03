<?php

namespace Drupal\amazon_product_widget;

use Drupal\amazon_product_widget\Plugin\Field\FieldType\AmazonProductField;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;

/**
 * Allows for adding or deleting product usages.
 *
 * @package Drupal\amazon_product_widget
 */
class ProductUsageService {

  /**
   * Database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * EntityFieldManager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $fieldManager;

  /**
   * Module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * ProductUsageService constructor.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   Database connection.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $fieldManager
   *   EntityFieldManager.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   Module handler.
   */
  public function __construct(Connection $database, EntityFieldManagerInterface $fieldManager, ModuleHandlerInterface $moduleHandler) {
    $this->database = $database;
    $this->fieldManager = $fieldManager;
    $this->moduleHandler = $moduleHandler;
  }

  /**
   * Updates ASIN mapping for the given entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   Entity to update the mapping for.
   */
  public function update(EntityInterface $entity) {
    if (!$entity instanceof FieldableEntityInterface) {
      return;
    }

    if ($entity->getEntityTypeId() === 'paragraph') {
      return;
    }

    // MenuLinkEntity returns NULL on call to $entity->id() so we check here to
    // be sure.
    if ($entity->id() === NULL) {
      return;
    }

    $productFields = $this->fieldManager->getFieldMapByFieldType('amazon_product_widget_field_type');
    $entityFields = $entity->getFieldDefinitions();

    $hookAsins = $this->moduleHandler->invokeAll('amazon_product_widget_alter_asin_map', [$entity]);
    $asins = [];
    foreach ($entityFields as $fieldName => $definition) {
      $targetType = $definition->getFieldStorageDefinition()->getSetting('target_type');
      if (FALSE === array_key_exists($targetType, $productFields)) {
        continue;
      }

      if ($definition->getType() === 'amazon_product_widget_field_type') {
        try {
          /** @var \Drupal\amazon_product_widget\Plugin\Field\FieldType\AmazonProductField $productField */
          $productField = $entity->get($fieldName)->first();
          if (!$productField instanceof AmazonProductField) {
            continue;
          }

          $asins = array_merge($asins, $productField->getAsins());
        }
        catch (\Exception $exception) {
          watchdog_exception('amazon_product_widget', $exception);
        }
      }
      elseif ($definition->getType() === 'entity_reference_revisions' || $definition->getType() === 'entity_reference') {
        /** @var \Drupal\Core\Entity\EntityInterface $referenceField */
        $referenceField = $entity->get($fieldName);
        if (!$referenceField) {
          continue;
        }
        $referencedEntities = $referenceField->referencedEntities();

        /** @var \Drupal\Core\Entity\FieldableEntityInterface $referencedEntity */
        foreach ($referencedEntities as $referencedEntity) {
          if (!$referencedEntity instanceof FieldableEntityInterface) {
            continue;
          }

          $fields = $referencedEntity->getFieldDefinitions();
          foreach ($fields as $field) {
            if ($field->getType() === 'amazon_product_widget_field_type') {
              try {
                /** @var \Drupal\amazon_product_widget\Plugin\Field\FieldType\AmazonProductField $productField */
                $productField = $referencedEntity->get($field->getName())->first();
                if (!$productField instanceof AmazonProductField) {
                  continue;
                }
                $asins = array_merge($asins, $productField->getAsins());
              }
              catch (\Exception $exception) {
                watchdog_exception('amazon_product_widget', $exception);
              }
            }
          }
        }
      }
    }

    $asins = array_merge($asins, $hookAsins);
    $asins = array_unique($asins);
    $oldAsins = array_unique($this->getAsinsByEntity($entity));

    // Before purging and changing the ASIN map, we make sure the ASINs have
    // changed for that entity.
    sort($asins);
    sort($oldAsins);

    if ($asins !== $oldAsins) {
      $this->purge($entity->id(), $entity->getEntityTypeId());
      $this->insert($entity->id(), $entity->getEntityTypeId(), $asins);
    }
  }

  /**
   * Inserts ASIN mapping for the given entity with ASINs.
   *
   * @param int $entityId
   *   The entity ID.
   * @param string $entityType
   *   The entity type.
   * @param string[] $asins
   *   The ASINs.
   */
  private function insert(int $entityId, string $entityType, array $asins) {
    $query = $this->database->insert('amazon_product_widget_asin_map')
      ->fields(['entity_id', 'entity_type', 'asin']);
    foreach ($asins as $asin) {
      $query->values([
        'entity_id' => $entityId,
        'entity_type' => $entityType,
        'asin' => $asin,
      ]);
    }

    try {
      $query->execute();
    }
    catch (\Exception $exception) {
      watchdog_exception('amazon_product_widget', $exception);
    }
  }

  /**
   * Purges the asin map table of a mapping with entity ID and type.
   *
   * @param int $entityId
   *   The entity ID.
   * @param string $entityType
   *   The entity type.
   */
  private function purge(int $entityId, string $entityType) {
    $this->database->delete('amazon_product_widget_asin_map')
      ->condition('entity_id', $entityId)
      ->condition('entity_type', $entityType)
      ->execute();
  }

  /**
   * Returns the unavailable ASINs in the given entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   Entity.
   *
   * @return array
   *   The unavailable ASINs.
   */
  public function getUnavailableAsinsForEntity(EntityInterface $entity) : array {
    $entityType = $entity->getEntityTypeId();
    $entityId   = $entity->id();

    $query = $this->database->select('amazon_product_widget_asin_map', 'am');
    $query->join('amazon_product_widget_key_value', 'kv', 'am.asin = kv.name AND kv.available = 0');
    $unavailableAsins = $query->condition('am.entity_id', $entityId)
      ->fields('am', ['asin'])
      ->condition('am.entity_type', $entityType)
      ->execute()
      ->fetchAll();

    $unavailableAsins = array_map(function($element) {
      return $element->asin;
    }, $unavailableAsins);

    return array_unique($unavailableAsins);
  }

  /**
   * Returns IDs of entities that contain the given ASIN in the product field.
   *
   * @param string $asin
   *   The ASIN.
   *
   * @return array
   *   Returns an array with entity IDs keyed by entity type.
   */
  public function getEntitiesByAsin(string $asin) : array {
    $rows = $this->database->select('amazon_product_widget_asin_map', 'am')
      ->fields('am', ['entity_type', 'entity_id'])
      ->condition('asin', $asin)
      ->execute()
      ->fetchAllKeyed();

    return array_unique($rows);
  }

  /**
   * Returns the ASINs contained within the given entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to search.
   *
   * @return array
   *   Returns an array of ASINs.
   */
  public function getAsinsByEntity(EntityInterface $entity) : array {
    $rows = $this->database->select('amazon_product_widget_asin_map', 'am')
      ->fields('am', ['asin'])
      ->condition('entity_id', $entity->id())
      ->condition('entity_type', $entity->getEntityTypeId())
      ->execute()
      ->fetchAll();

    return array_unique(array_map(function($element) {
      return $element->asin;
    }, $rows));
  }

  /**
   * Optimizes the asin map table.
   */
  public function optimize() {
    try {
      $this->database->query('OPTIMIZE TABLE amazon_product_widget_asin_map');
    }
    catch (\Exception $exception) {
      watchdog_exception('amazon_product_widget', $exception);
    }
  }
}
