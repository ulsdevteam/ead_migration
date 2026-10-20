<?php

namespace Drupal\ead_migration\EventSubscriber;

use Drupal\migrate\Event\MigrateEvents;
use Drupal\migrate\Event\MigratePreRowSaveEvent;
use Drupal\migrate\Event\MigratePostRowSaveEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\migrate\Plugin\MigrateIdMapInterface; 

/**
 * Event subscriber to update Media entities after node creation.
 */
class eadEventSubscriber implements EventSubscriberInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The node bundle we expect field_media_of to point to.
   */
  const EXPECTED_NODE_TYPE = 'islandora_object';

  /**
   * Keys of rows that are new in this migration run (never imported before)
   */
  protected array $newRows = [];

  /**
   * Constructs a MigrationSubscriber object
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      MigrateEvents::PRE_ROW_SAVE  => ['onPreRowSave', 100],
      MigrateEvents::POST_ROW_SAVE => ['onPostRowSave', 0]
    ];
  }

   public function onPreRowSave(MigratePreRowSaveEvent $event): void {
    $migration = $event->getMigration();
     if ($migration->id() !== 'ead_media_to_node') {
     return;
    }

    $row = $event->getRow();
    $id_map = $migration->getIdMap();
    $sourceIdvalues = $row->getSourceIdValues(); 
    $mediaId  = $sourceIdvalues['media_id'] ?? NULL;

    if (empty($mediaId)){
      return;
    }
    //this is an update. Already mapped by an earlier run.
    $DestIds = $id_map->lookupDestinationIds($sourceIdvalues);
    if (!empty($DestIds) && !empty(array_filter($DestIds[0]))) {
     return;
   } 
   // flag for a first-time row
   $this->newRows[$mediaId] = TRUE;
   
   $media = $this->entityTypeManager->getStorage('media')->load($mediaId);
   if (!$media || !$media->hasField('field_media_of')) {
    return;
   }

   $referenced_nodes = $media->get('field_media_of')->referencedEntities();
   if (empty($referenced_nodes)) {
    return;  
   }
   $existing_node = reset($referenced_nodes);
   $existing_nid = $existing_node->id();

   if ($existing_node->bundle() !== self::EXPECTED_NODE_TYPE) {
    \Drupal::logger('ead_migration')->warning(
      'Media @mid references node @nid but it is bundle "@bundle", skipping pre-map.',
      ['@mid' => $media_id, '@nid' => $existing_nid, '@bundle' => $existing_node->bundle()]
    );
    return;
  }

  // Pre-map: Write the existing nid as destid1 in migration map 
  $id_map->saveIdMapping(
    $row,
    ['nid' => $existing_nid],
    MigrateIdMapInterface::STATUS_NEEDS_UPDATE
  );
  $row->setDestinationProperty('nid', $existing_nid);
    \Drupal::logger('ead_migration')->info(
      'Pre-mapped media @mid to existing node @nid via field_media_of.',
      ['@mid' => $mediaId, '@nid' => $existing_nid]
    );
  }

  /**
   * React to a post row save event.
   * @param \Drupal\migrate\Event\MigratePostRowSaveEvent $event
   */
   public function onPostRowSave(MigratePostRowSaveEvent $event): void {
    $migration = $event->getMigration();
    
    // Only process the specific ead migration
    if ($migration->id() !== 'ead_media_to_node') {
      return;
    }

    $row = $event->getRow();
    // Add media save so as to trigger detrivatives for the first node migration run
    $media_id = $row->getSourceProperty('media_id');
    if (empty($this->newRows[$media_id])) { //Not a first-time row; skip
      return;
    }
    unset($this->newRows[$media_id]);
    $destination_ids = $event->getDestinationIdValues();
    
    // Get destination node ID
    $node_id = $destination_ids ? reset($destination_ids) : NULL;
    if ($media_id && $node_id) {
        // Load the media entity and update field_media_of
        $media_storage = $this->entityTypeManager->getStorage('media');
        $media = $media_storage->load($media_id);
        
        if ($media && $media->hasField('field_media_of')) {
          // Set the reference to the destination node for pre-mapped existing node
          $media->set('field_media_of', ['target_id' => $node_id]);
          $media->save();
          $node = $this->entityTypeManager->getStorage('node')->load($node_id);
          $storage = $this->entityTypeManager->getStorage('action');
          foreach (['xml_to_extracted_text', 'xslt_derivative'] as $action_id) {
          if ($node && ($action = $storage->load($action_id))) {
                $action->execute([$node]);
            }
          }
          \Drupal::logger('ead_migration')->info('Successfully saved media @mid against node @nid to trigger detrivatives',['@mid' => $media_id, '@nid' => $node_id]);
        }
    } else {
        \Drupal::logger('ead_migration')->error('Failed to update field_media_of for media @mid (node @nid).',['@mid' => $media_id, '@nid' => $node_id]);
        return;
     }
  }
}
