# EAD Migration

The module extracts Drupal media entities of type Finding Aid that contain EAD file attachments (in .xml format) and migrates them into repository item nodes in the modern Islandora site. For each Finding Aid media item, a corresponding new Drupal node entity is created during the migration. The migrated node is then linked back to the original Finding Aid media via the Media Of field.
To manage incremental updates, the module uses the file_updated timestamp of the attached media file as a high-water mark. Only media items whose attached file has a file_updated value greater than the current high‑water mark are imported or updated during migration.

## Requirements
This module enables users to automatically generate Repository Item nodes from Drupal Media records containing EAD (Encoded Archival Description) XML files.

### Prerequisites
Before using this module, the following must be in place:

1. Media Type 
  Users need create a 'Finding Aid' Media type (machine name: `findingaid`) in Drupal if not existing
  - Go to Drupal Site->Administration->Structure->Media types: Add Media types
  - Setup type configurations:  
    Name: FindingAid
    Media source: File
  - Add required fields under 'Manage fields' with the following configurations:
     - field_media_file 
       * Field Storage: Select 'Private files' under 'Upload destination' Option
       * Allowed file extensions: xml
       * File directory: findingaid (or your configured private directory)
     - field_media_of - Entity reference field 
2. Finding Aid Type Media
  Users must have Finding Aid Media records created in Drupal with the following configuration:
  - field_media_file - File field containing the EAD XML file
  - field_media_of - automatically populated by migration to link back to the Repository Item 
 
## Module Usage
1. Install and enable the module in Drupal container
    - Install via composer: `composer require drupal/ead_migration`
    - Enable module via drush: `drush en -y ead_migration`
    - Confirm modules status: `drush pml --type=module --status=enabled | grep ead_migration`
2. Install and enable the module via Admin in UI
   - Goto Admin->Extend, search 'EAD Migration', and Click to install

## Migration dataflow
   -  ![ead migration dataflow](dataflow/ead-migration-dataflow.png)
