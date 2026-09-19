<?php

declare(strict_types=1);

namespace Drupal\farm_grazing_plan\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\farm_grazing_plan\Bundle\GrazingEvent;

/**
 * Entity hook implementations for farm_grazing_plan.
 */
class EntityHooks {

  /**
   * Implements hook_entity_type_alter().
   */
  #[Hook('entity_type_alter')]
  public function entityTypeAlter(array &$entity_types) {

    // Add a constraint to log entities to restrict editing fields that are
    // managed by a grazing plan.
    if (isset($entity_types['log'])) {
      $entity_types['log']->addConstraint('GrazingEventLogRestrictedFields');
    }

    // Add a constraint to plan_record entities to validate the movement log
    // associated with a grazing event.
    if (isset($entity_types['plan_record'])) {
      $entity_types['plan_record']->addConstraint('GrazingEventLog');
    }
  }

  /**
   * Implements hook_entity_bundle_info_alter().
   */
  #[Hook('entity_bundle_info_alter')]
  public function entityBundleInfoAlter(array &$bundles): void {
    if (isset($bundles['plan_record']['grazing_event'])) {
      $bundles['plan_record']['grazing_event']['class'] = GrazingEvent::class;
    }
  }

}
