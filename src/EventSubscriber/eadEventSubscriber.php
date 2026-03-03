<?php

namespace Drupal\ead_migration\EventSubscriber;

use Drupal\migrate\Event\MigrateEvents;
use Drupal\migrate\Event\MigratePostRowSaveEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

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
      MigrateEvents::POST_ROW_SAVE => 'onPostRowSave',
    ];
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
    $destination_ids = $event->getDestinationIdValues();
    
    // Get destination node ID
    if (!empty($destination_ids) && isset($destination_ids[0])) {
      $node_id = $destination_ids[0];
      $media_id = $row->getSourceProperty('media_id');
      
      if ($media_id && $node_id) {
        // Load the media entity and update field_media_of
        $media_storage = $this->entityTypeManager->getStorage('media');
        $media = $media_storage->load($media_id);
        
        if ($media && $media->hasField('field_media_of')) {
          // Set the reference to the destination node
          $media->set('field_media_of', ['target_id' => $node_id]);
          $media->save();
          \Drupal::logger('ead_migration')->info('Successfully saved media @mid with referencing node @nid',['@mid' => $media_id, '@nid' => $node_id]);
        }
      } else {
        \Drupal::logger('ead_migration')->error('Failed to update field_media_of for media @mid',['@mid' => $media_id,]);
      }
    }
  }

}
