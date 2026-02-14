<?php

declare(strict_types=1);

namespace Drupal\farm_grazing_plan;

use Drupal\plan\Entity\PlanInterface;

/**
 * Grazing plan logic.
 */
interface GrazingPlanInterface {

  /**
   * Get all grazing event plan entity relationship records for a given plan.
   *
   * @param \Drupal\plan\Entity\PlanInterface $plan
   *   The plan entity.
   *
   * @return \Drupal\farm_grazing_plan\Bundle\GrazingEventInterface[]
   *   Returns an array of plan_record entities of type grazing_event.
   */
  public function getGrazingEvents(PlanInterface $plan): array;

  /**
   * Get grazing events indexed by the asset(s) that their log references.
   *
   * @param \Drupal\plan\Entity\PlanInterface $plan
   *   The plan entity.
   *
   * @return array
   *   Returns arrays of plan_record entities of type grazing_event, indexed by
   *   asset ID.
   */
  public function getGrazingEventsByAsset(PlanInterface $plan): array;

  /**
   * Get grazing events indexed by the location(s) that their log references.
   *
   * @param \Drupal\plan\Entity\PlanInterface $plan
   *   The plan entity.
   *
   * @return array
   *   Returns arrays of plan_record entities of type grazing_event, indexed by
   *    location asset ID.
   */
  public function getGrazingEventsByLocation(PlanInterface $plan): array;

}
