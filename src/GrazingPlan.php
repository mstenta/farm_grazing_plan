<?php

declare(strict_types=1);

namespace Drupal\farm_grazing_plan;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\plan\Entity\PlanInterface;

/**
 * Grazing plan logic.
 */
class GrazingPlan implements GrazingPlanInterface {

  /**
   * Entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Class constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   Entity type manager.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public function getGrazingEvents(PlanInterface $plan): array {
    return $this->entityTypeManager->getStorage('plan_record')->loadByProperties(['plan' => $plan->id(), 'type' => 'grazing_event']);
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
