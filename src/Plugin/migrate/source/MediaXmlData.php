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
          $file_path = $this->fileSystem->realpath($file_uri);
          $file_changed = $file->getChangedTime();
          
          // Determine if we should process this media
          $should_process = $this->shouldProcessMedia($media, $file_changed);
          
          if (!$should_process) {
            continue;
          }
          
          // Check if it's an XML file
          $mime_type = $file->getMimeType();
          if (in_array($mime_type, ['application/xml', 'text/xml']) || 
              pathinfo($file_path, PATHINFO_EXTENSION) === 'xml') {
            
            // Parse the XML file
            $xml_data = $this->parseXmlFile($file_path, $media_id, $file_changed);
            if ($xml_data) {
              $this->parsedData = array_merge($this->parsedData, $xml_data);
            }
          }
        }
      }
    }
  }

  /**
   * Determine if a media entity should be processed.
   *
   * @param \Drupal\media\Entity\Media $media: media entity
   * @param int $file_changed: file changed timestamp.
   *
   * @return bool: TRUE if should process, FALSE otherwise.
   */
  protected function shouldProcessMedia($media, $file_changed) {
    // Check if media has an associated node in field_media_of
    if ($media->hasField('field_media_of') && !$media->get('field_media_of')->isEmpty()) {
      $node_id = $media->get('field_media_of')->target_id;
      $node_storage = $this->entityTypeManager->getStorage('node');
      $node = $node_storage->load($node_id);
      
      if ($node) {
        $node_changed = $node->getChangedTime();
        
        // Process if file timestamp is greater than node timestamp
        // This will trigger a reimport and override the existing node
        if ($file_changed > $node_changed) {
          return TRUE;
        }
        
        // File hasn't changed since node was last updated, skip
        return FALSE;
      }
      
      // Referenced node doesn't exist, should process
      return TRUE;
    }
    
    // No associated node (field_media_of is empty)
    // Always process to create new node
    return TRUE;
  }

  /**
   * Parse an XML file and extract data based on configuration.
   *
   * @param string $file_path
   * @param int $media_id
   * @param int $file_changed
   *
   * @return array: Parsed data array.
   */
  protected function parseXmlFile($file_path, $media_id, $file_changed) {
    $data = [];
    
    if (!file_exists($file_path)) {
      return $data;
    }

    // Load XML
    libxml_use_internal_errors(TRUE);
    $xml = simplexml_load_file($file_path);
    
    if ($xml === FALSE) {
      foreach (libxml_get_errors() as $error) {
        \Drupal::logger('custom_migration')->error('XML parsing error: @error', ['@error' => $error->message]);
      }
      libxml_clear_errors();
      return $data;
    }

    // Register namespaces to SimpleXmlelements
    $namespaces = [];
    if (isset($this->configuration['namespaces'])) {
      $namespaces = $this->configuration['namespaces'];
      foreach ($namespaces as $prefix => $namespace) {
        $xml->registerXPathNamespace($prefix, $namespace);
      }
    }

    // Get item selector
    $item_selector = $this->configuration['item_selector'] ?? '//ead:ead';
    $items = $xml->xpath($item_selector);

    if (empty($items)) {
      $items = [$xml];
    }

    // Process each item
    foreach ($items as $index => $item) {
      $row_data = [
        'media_id' => $media_id,
        'file_changed' => $file_changed,
      ];
      
      // register namespaces on each elements
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

    return $data;
  }

}