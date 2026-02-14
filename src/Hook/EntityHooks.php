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
   * Implements hook_entity_bundle_info_alter().
   */
  #[Hook('entity_bundle_info_alter')]
  public function entityBundleInfoAlter(array &$bundles): void {
    if (isset($bundles['plan_record']['grazing_event'])) {
      $bundles['plan_record']['grazing_event']['class'] = GrazingEvent::class;
    }
  }

}
