<?php

namespace Drupal\amazon_product_widget\Plugin\Field\FieldType;

use Drupal\Core\Field\FieldItemBase;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;

/**
 * Plugin implementation of the Amazon Product Widget field type.
 *
 * @FieldType(
 *   id = "amazon_product_widget_field_type",
 *   label = @Translation("Amazon Product Widget"),
 *   description = @Translation("Create and store amazon products."),
 *   default_widget = "amazon_product_widget",
 *   default_formatter = "amazon_product_widget_field_formatter"
 * )
 */
class AmazonProductField extends FieldItemBase {

  /**
   * {@inheritdoc}
   */
  public static function schema(FieldStorageDefinitionInterface $field_definition) {
    return [
      'columns' => [
        'title' => [
          'description' => 'Title.',
          'type' => 'varchar',
          'length' => 255,
        ],
        'asins' => [
          'description' => 'Comma separated list of ASINs for amazon products.',
          'type' => 'varchar',
          'length' => 255,
          'not null' => TRUE,
        ],
        'search_terms' => [
          'description' => 'Search terms used for fetching products as a fallback when the provided ASINs are unavailable.',
          'type' => 'varchar',
          'length' => 255,
        ],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition) {
    $properties['title'] = DataDefinition::create('string')
      ->setSetting('case_sensitive', TRUE)
      ->setLabel(new TranslatableMarkup('Title'));

    $properties['asins'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Comma separated list of ASINs for amazon products.'))
      ->setSetting('case_sensitive', TRUE)
      ->setRequired(TRUE);

    $properties['search_terms'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Search terms used for fetching products as a fallback when the provided ASINs are unavailable.'));

    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public function isEmpty() {
    $asins = $this->getAsins();
    return empty($asins);
  }

  /**
   * Get the ASINs.
   *
   * @return string[]
   *   Array of ASINs.
   */
  public function getAsins() {
    $asins = $this->get('asins')->getValue();
    $asins = explode(',', $asins);
    array_map('trim', $asins);
    return $asins;
  }

  /**
   * Get title.
   *
   * @return string
   *   Title.
   */
  public function getTitle() {
    return $this->get('title')->getValue();
  }

  /**
   * Get search terms.
   *
   * @return string
   *   Search terms.
   */
  public function getSearchTerms() {
    return $this->get('search_terms')->getValue();
  }

}
