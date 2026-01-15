<?php

namespace Drupal\ead_migration_module\Plugin\migrate\process;

use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;

/**
 * Converts linked agent to drupal typed relations
 *
 * @MigrateProcessPlugin(
 *   id = "linked_agent_helper"
 * )
 */
class LinkedAgentHelper extends ProcessPluginBase {

  protected $relPrefix;
  protected $relType;

 /**                                                                                                        
  * {@inheritdoc}                                                                                           
  */ 
 public function __construct(array $configuration, $plugin_id, $plugin_definition) {
 	parent::__construct($configuration, $plugin_id, $plugin_definition);
	$this->relPrefix = $configuration['Prefix_agent_rel'] ?? 'relators:';
	$this->relType = $configuration['rel_type'];
	}
 /**
  * {@inheritdoc}
  */
 public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
	$relator_prefix = ($row->getSourceProperty('constants/Prefix_agent_rel')) ?? 'relators:';

	//$RelType = $relator_prefix . $this->configuration['rel_type'];
	$current = $row->getDestinationProperty($destination_property);
	$curr_source = $this->configuration['source'];
	$agents =[];
	if (is_string($curr_source)) {
		if (strpos($curr_source, '@') === 0) {
			$sourceField = substr($curr_source, 1);
	 		if ( (is_array($row->getDestinationProperty($sourceField)) &&                         
                        in_array($value, $row->getDestinationProperty($sourceField)))) {
				$agents['target_id'] = $value;                                                         
                                $agents['rel_type'] = $this->relPrefix . $this->relType;  
			}

     			if (is_string($row->getDestinationProperty($sourceField)) && strcmp($value, $row->getDestinationProperty($sourceField)) == 0) {  
                                   $agents[] = [
					'target_id' => $value,                                                       
                                	 'rel_type' => $this->relPrefix . $this->relType,                         
					];
		
                	}                                                                                            
                        return $agents;  
		}
	}
 	return $agents;	
   } 
}
