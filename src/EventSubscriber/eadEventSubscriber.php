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
   * Keys of mediaId rows that are new in this migration run (never imported before)
   */
  protected array $newRows = [];

  /**
   * The Islandora reaction plugin class for derivative actions.
   */
  const DERIVATIVE_REACTION = '\Drupal\islandora\Plugin\ContextReaction\DerivativeReaction';

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
    // Check if the source MediaId has a destinationId mapped
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
      ['@mid' => $mediaId, '@nid' => $existing_nid, '@bundle' => $existing_node->bundle()]
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
   * Save the media and fire the derivative reaction
   * @param \Drupal\migrate\Event\MigratePostRowSaveEvent $event
   */
  public function onPostRowSave(MigratePostRowSaveEvent $event): void {
    $migration = $event->getMigration();
    
    // Only process the specific ead migration
    if ($migration->id() !== 'ead_media_to_node') {
      return;
    }

    $row = $event->getRow();
    $media_id = $row->getSourceProperty('media_id');
    
    //Only first-time rows
    if (empty($media_id) || empty($this->newRows[$media_id])) { 
      return;
    }
    unset($this->newRows[$media_id]);
    $destination_ids = $event->getDestinationIdValues();
    
    // Get destination node ID
    $node_id = $destination_ids ? reset($destination_ids) : NULL;
    
    // Load the media entity and update field_media_of
    $media_storage = $this->entityTypeManager->getStorage('media');
    $node_storage = $this->entityTypeManager->getStorage('node');

    //migration just saved, not stale cache.
    $media_storage->resetCache([$media_id]);
    $media = $media_storage->load($media_id);
    $node = NULL;
    if ($node_id) {
      $node_storage->resetCache([$node_id]);
      $node = $node_storage->load($node_id);
    }
  
    if ($media && $node && $media->hasField('field_media_of')) {
      // Set the reference to the destination node for pre-mapped existing node
      $media->set('field_media_of', ['target_id' => $node_id]);
      $media->save();

      // Fire the context reactions explicitly since Context module evaluates active states on http requests e.g. hits a page route
      try {
        \Drupal::service('islandora.utils')->executeDerivativeReactions(self::DERIVATIVE_REACTION, $node, $media);
      }
      catch (\Throwable $e) {
        \Drupal::logger('ead_migration')->error('Failed to execute Derivative Reactions @msg',['@msg' => $e->getMessage()]);
        return;
      }
      \Drupal::logger('ead_migration')->info('Successfully saved media @mid against node @nid to trigger derivatives',['@mid' => $media_id, '@nid' => $node_id]);
    } else {
       \Drupal::logger('ead_migration')->error('Failed to load media @mid (node @nid).',['@mid' => $media_id, '@nid' => $node_id]);
       return;
    }
  }
}
