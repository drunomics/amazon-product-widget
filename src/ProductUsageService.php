<?php

namespace Drupal\amazon_product_widget;

use Drupal\amazon_product_widget\Plugin\Field\FieldType\AmazonProductField;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;

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
   * ProductUsageService constructor.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   Database connection.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $fieldManager
   *   EntityFieldManager.
   */
  public function __construct(Connection $database, EntityFieldManagerInterface $fieldManager) {
    $this->database = $database;
    $this->fieldManager = $fieldManager;
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

    if ($entity->id() === NULL) {
      return;
    }

    $this->purge($entity->id(), $entity->getEntityTypeId());

    $productFields = $this->fieldManager->getFieldMapByFieldType('amazon_product_widget_field_type');
    $entityFields = $entity->getFieldDefinitions();

    foreach ($entityFields as $fieldName => $definition) {
      if ($definition->getType() === 'amazon_product_widget_field_type') {
        try {
          /** @var \Drupal\amazon_product_widget\Plugin\Field\FieldType\AmazonProductField $productField */
          $productField = $entity->get($fieldName)->first();
          if (!$productField instanceof AmazonProductField) {
            continue;
          }

          $asins = $productField->getAsins();
          $this->insert($entity->id(), $entity->getEntityTypeId(), $asins);
        }
        catch (\Exception $exception) {
          watchdog_exception('amazon_product_widget', $exception);
        }
      }

      if ($definition->getType() === 'entity_reference_revisions') {
        /** @var \Drupal\paragraphs\ParagraphInterface $paragraphField */
        $paragraphField = $entity->get($fieldName);
        if (!$paragraphField) {
          continue;
        }
        $paragraphs = $paragraphField->referencedEntities();

        /** @var \Drupal\paragraphs\ParagraphInterface $paragraph */
        foreach ($paragraphs as $paragraph) {
          $fields = $paragraph->getFieldDefinitions();
          foreach ($fields as $field) {
            if ($field->getType() === 'amazon_product_widget_field_type') {
              try {
                /** @var \Drupal\amazon_product_widget\Plugin\Field\FieldType\AmazonProductField $productField */
                $productField = $paragraph->get($field->getName())->first();
                if (!$productField instanceof AmazonProductField) {
                  continue;
                }

                $asins = $productField->getAsins();
                $this->insert($entity->id(), $entity->getEntityTypeId(), $asins);
              }
              catch (\Exception $exception) {
                watchdog_exception('amazon_product_widget', $exception);
              }
            }
          }
        }
      }
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
    $asins = array_unique($asins);
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
  public function getUnavailableAsinsForEntity(EntityInterface $entity) {
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
}
