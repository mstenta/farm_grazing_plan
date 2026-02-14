<?php

declare(strict_types=1);

namespace Drupal\farm_grazing_plan;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\plan\Entity\PlanInterface;

/**
 * Grazing plan logic.
 */
class GrazingPlan implements GrazingPlanInterface {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getGrazingEvents(PlanInterface $plan): array {
    /** @var \Drupal\farm_grazing_plan\Bundle\GrazingEvent[] $grazing_events */
    $grazing_events = $this->entityTypeManager->getStorage('plan_record')->loadByProperties(['plan' => $plan->id(), 'type' => 'grazing_event']);
    return $grazing_events;
  }

  /**
   * {@inheritdoc}
   */
  public function getGrazingEventsByAsset(PlanInterface $plan): array {
    $grazing_events_by_asset = [];
    $grazing_events = $this->getGrazingEvents($plan);
    foreach ($grazing_events as $grazing_event) {
      $assets = $grazing_event->getLog()->get('asset')->referencedEntities();
      foreach ($assets as $asset) {
        $grazing_events_by_asset[$asset->id()][$grazing_event->id()] = $grazing_event;
      }
    }
    return $grazing_events_by_asset;
  }

  /**
   * {@inheritdoc}
   */
  public function getGrazingEventsByLocation(PlanInterface $plan): array {
    $grazing_events_by_location = [];
    $grazing_events = $this->getGrazingEvents($plan);
    foreach ($grazing_events as $grazing_event) {
      $locations = $grazing_event->getLog()->get('location')->referencedEntities();
      foreach ($locations as $location) {
        $grazing_events_by_location[$location->id()][$grazing_event->id()] = $grazing_event;
      }
    }
    return $grazing_events_by_location;
  }

}
