<?php

namespace Drupal\ead_migration\Plugin\migrate\source;

use Drupal\migrate\Plugin\migrate\source\SourcePluginBase;
use Drupal\migrate\Row;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\taxonomy\Entity\Term;

/**
 * Source plugin to extract FindingAid XML from Media entities.
 *
 * @MigrateSource(
 *   id = "media_xml_data"
 * )
 */
class MediaXmlData extends SourcePluginBase implements ContainerFactoryPluginInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * Array of parsed XML data.
   *
   * @var array
   */
  protected $parsedData = [];

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, MigrationInterface $migration, EntityTypeManagerInterface $entity_type_manager, FileSystemInterface $file_system) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $migration);
    $this->entityTypeManager = $entity_type_manager;
    $this->fileSystem = $file_system;
  }

  /**
   * {@inheritdoc}
   */
  public static function create($container, array $configuration, $plugin_id, $plugin_definition, MigrationInterface $migration = NULL) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $migration,
      $container->get('entity_type.manager'),
      $container->get('file_system')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function fields() {
    $fields = [];
    if (isset($this->configuration['fields'])) {
      foreach ($this->configuration['fields'] as $field) {
        $fields[$field['name']] = $field['label'];
      }
    }
    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function getIds() {
    return [
      'media_id' => [
        'type' => 'integer',
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function __toString() {
    return 'Finding Aid Media Migration to Drupal Nodes';
  }

  /**
   * prepare each source row after initializeIterator() and trigger POST_RAW_SAVE
   * {@inheritdoc}
   */
  public function prepareRow(Row $row) {
    return parent::prepareRow($row);
  }

  /**
   * {@inheritdoc}
   */
  protected function initializeIterator() {
    $this->parseMediaXmlFiles();
    return new \ArrayIterator($this->parsedData);
  }

  /**
   * Parse XML files from Media entities.
   */
  protected function parseMediaXmlFiles() {
    // Query all FindingAid Media via injected service
    $media_storage = $this->entityTypeManager->getStorage('media');
    $query = $media_storage->getQuery()
      ->condition('bundle', 'findingaid')
      ->condition('status', 1)
      ->condition('field_media_file.target_id', NULL, 'IS NOT NULL')
      ->accessCheck(FALSE);
    $media_ids = $query->execute();
    
    if (empty($media_ids)) {
      \Drupal::logger('ead_migration')->notice('No media entities found with bundle "findingaid".');
      return;
    }

    foreach ($media_ids as $media_id) {
      $media = $media_storage->load($media_id);
      
      // Check if field_media_file exists and has a file
      if ($media->hasField('field_media_file') && !$media->get('field_media_file')->isEmpty()) {
        $file = $media->get('field_media_file')->entity;
        
        if ($file) {
          $file_uri = $file->getFileUri();
          
          // Check if it's an XML file
          $mime_type = $file->getMimeType();
          if (in_array($mime_type, ['application/xml', 'text/xml']) || 
              pathinfo($file_uri, PATHINFO_EXTENSION) === 'xml') {
            
            // Parse the XML file
            $xml_data = $this->parseXmlFile($file_uri, $media_id);
            if ($xml_data) {
              $this->parsedData = array_merge($this->parsedData, $xml_data);
            }
          }
        }
      }
    }
  }

  /**
   * Parse an XML file and extract data based on configuration.
   *
   * @param string $file_uri
   * @param int $media_id
   *
   * @return array: Parsed data array.
   */
  protected function parseXmlFile($file_uri, $media_id) {
    $data = [];
    
    if (!file_exists($file_uri)) {
      return $data;
    }

    // Check item selector configuration
    if (!isset($this->configuration['item_selector'])) {
      \Drupal::logger('ead_migration')->error(
        'configuration "item_selector" is not defined in migration configuration. Cannot process @file.',
        ['@file' => $file_uri]
      );
      return $data; 
    }
    $item_selector = $this->configuration['item_selector'];

    // Save the current error handling state
    $previous = libxml_use_internal_errors(TRUE);
    try { 
      
      $xmlContent = file_get_contents($file_uri); //Raw file contents via stream wrapper URI e.g. s3
      if ($xmlContent === FALSE || empty($xmlContent)) {
        \Drupal::logger('ead_migration')->error('Failed to read xml contents from URI: @uri', ['@uri' => $file_uri]);
        return $data;
      }
      
      // Clear any previous XML errors then load xml
      libxml_clear_errors();
      $xml = simplexml_load_string($xmlContent);
      //log errors
      if ($xml === FALSE || empty($xml)) {
        foreach (libxml_get_errors() as $error) {
          \Drupal::logger('ead_migration')->error('XML parsing error: @error in @file: @line', ['@error' => $error->message, '@file' => $file_uri, '@line' => $error->line,]);
        }
        libxml_clear_errors();
        // Restore previous state
        return $data;
        }
      
      libxml_clear_errors();

      // Register namespaces to SimpleXmlelements
      $namespaces = [];
      if (isset($this->configuration['namespaces'])) {
        $namespaces = $this->configuration['namespaces'];
        foreach ($namespaces as $prefix => $namespace) {
          $xml->registerXPathNamespace($prefix, $namespace);
        }
      }

      $items = $xml->xpath($item_selector);
      if (empty($items)) {
        \Drupal::logger('ead_migration')->notice(
          'XPath selector "@selector" returned no items for @file. Using root element as fallback.',
          ['@selector' => $item_selector, '@file' => $file_uri]
        );
        $items = [$xml];
     }

     // Process each item
      foreach ($items as $index => $item) {
        $row_data = [
          'media_id' => $media_id,
          'file_path' => $file_uri,
        ];
      
      // Register namespaces on each elements
      if (!empty($namespaces)) {
        foreach ($namespaces as $prefix => $namespace) {
          $item->registerXPathNamespace($prefix, $namespace);
        }
      }
      
      // Extract fields based on configuration
      if (isset($this->configuration['fields'])) {
        foreach ($this->configuration['fields'] as $field) {
          $selector = $field['selector'];
          $is_multiple = isset($field['multiple']) && $field['multiple'];
          
          // Execute XPath on the item
          $result = $item->xpath($selector);
          
          if (!empty($result)) {
            if ($is_multiple) {
              // Handle multiple values
              $values = [];
              foreach ($result as $element) {
                $values[] = (string) $element;
              }
              $row_data[$field['name']] = $values;
            } else {
              // Get the first result and convert to string
              $row_data[$field['name']] = (string) $result[0];
            }
          } else {
            $row_data[$field['name']] = $is_multiple ? [] : NULL;
          }
        }
      }
      
      $data[] = $row_data;
    }
  } finally { 
    //restore the previous state
    libxml_use_internal_errors($previous);
    }
  return $data;
  }
}