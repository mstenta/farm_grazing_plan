<?php

namespace Drupal\farm_grazing_plan\Plugin\PlanRecord\PlanRecordType;

use Drupal\farm_entity\Plugin\PlanRecord\PlanRecordType\FarmPlanRecordType;

/**
 * Provides the grazing event plan record type.
 *
 * @PlanRecordType(
 *   id = "grazing_event",
 *   label = @Translation("Grazing event"),
 * )
 */
class GrazingEvent extends FarmPlanRecordType {

  /**
   * {@inheritdoc}
   */
  public function buildFieldDefinitions() {
    $fields = parent::buildFieldDefinitions();
    $field_info = [
      'log' => [
        'type' => 'entity_reference',
        'label' => $this->t('Log'),
        'description' => $this->t('Associates the grazing plan with a movement log.'),
        'target_type' => 'log',
        'cardinality' => 1,
        'required' => TRUE,
      ],
      'start' => [
        'type' => 'timestamp',
        'label' => $this->t('Planned start date/time'),
        'required' => TRUE,
      ],
      'duration' => [
        'type' => 'integer',
        'label' => $this->t('Duration (hours)'),
        'min' => 1,
        'max' => 8760,
        'required' => TRUE,
      ],
      'recovery' => [
        'type' => 'integer',
        'label' => $this->t('Recovery (hours)'),
        'min' => 1,
        'max' => 8760,
      ],
      'planned' => [
        'type' => 'integer',
        'label' => $this->t('Planned'),
        'min' => 0,
        'max' => 1,
        'required' => TRUE,
      ],
    ];
    foreach ($field_info as $name => $info) {
      $fields[$name] = $this->farmFieldFactory->bundleFieldDefinition($info);
    }
    return $fields;
  }

}
