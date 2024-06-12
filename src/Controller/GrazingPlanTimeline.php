<?php

namespace Drupal\farm_grazing_plan\Controller;

use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\farm_grazing_plan\GrazingPlanInterface;
use Drupal\farm_timeline\TypedData\TimelineRowDefinition;
use Drupal\plan\Entity\PlanInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Grazing plan timeline controller.
 */
class GrazingPlanTimeline extends ControllerBase {

  /**
   * The grazing plan service.
   *
   * @var \Drupal\farm_grazing_plan\GrazingPlanInterface
   */
  protected GrazingPlanInterface $grazingPlan;

  /**
   * The UUID service.
   *
   * @var \Drupal\Component\Uuid\UuidInterface
   */
  protected $uuidService;

  /**
   * The typed data manager interface.
   *
   * @var \Drupal\Core\TypedData\TypedDataManagerInterface
   */
  protected $typedDataManager;

  /**
   * The serializer service.
   *
   * @var \Symfony\Component\Serializer\SerializerInterface
   */
  protected $serializer;

  /**
   * GrazingPlanTimeline constructor.
   *
   * @param \Drupal\farm_grazing_plan\GrazingPlanInterface $grazing_plan
   *   The grazing plan service.
   * @param \Drupal\Component\Uuid\UuidInterface $uuid_service
   *   The UUID service.
   * @param \Drupal\Core\TypedData\TypedDataManagerInterface $typed_data_manager
   *   The typed data manager interface.
   * @param \Symfony\Component\Serializer\SerializerInterface $serializer
   *   The serializer service.
   */
  public function __construct(GrazingPlanInterface $grazing_plan, UuidInterface $uuid_service, TypedDataManagerInterface $typed_data_manager, SerializerInterface $serializer) {
    $this->grazingPlan = $grazing_plan;
    $this->uuidService = $uuid_service;
    $this->typedDataManager = $typed_data_manager;
    $this->serializer = $serializer;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('farm_grazing_plan'),
      $container->get('uuid'),
      $container->get('typed_data_manager'),
      $container->get('serializer'),
    );
  }

  /**
   * API endpoint for grazing plan timeline by asset.
   *
   * @param \Drupal\plan\Entity\PlanInterface $plan
   *   The crop plan.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   Json response of timeline data.
   */
  public function byAsset(PlanInterface $plan) {
    $grazing_events = $this->grazingPlan->getGrazingEventsByAsset($plan);
    return $this->buildTimeline($plan, $grazing_events);
  }

  /**
   * API endpoint for grazing plan timeline by location.
   *
   * @param \Drupal\plan\Entity\PlanInterface $plan
   *   The grazing plan.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   Json response of timeline data.
   */
  public function byLocation(PlanInterface $plan) {
    $grazing_events = $this->grazingPlan->getGrazingEventsByLocation($plan);
    return $this->buildTimeline($plan, $grazing_events);
  }

  /**
   * Build grazing plan timeline data.
   *
   * @param \Drupal\plan\Entity\PlanInterface $plan
   *   The grazing plan.
   * @param array $grazing_events_by_asset
   *   Grazing events indexed by asset/location ID, as returned by
   *   GrazingPlan::getGrazingEventsByAsset() or
   *   GrazingPlan::getGrazingEventsByLocation().
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   Json response of timeline data.
   */
  protected function buildTimeline(PlanInterface $plan, array $grazing_events_by_asset) {
    $data = [];
    foreach ($grazing_events_by_asset as $asset_id => $grazing_events) {

      // Load the asset.
      $asset = $this->entityTypeManager()->getStorage('asset')->load($asset_id);

      // Build the asset row values.
      $row_values = [
        'id' => "asset--$asset_id",
        'label' => $asset->label(),
        'link' => $asset->toLink()->toString(),
        'expanded' => TRUE,
        'children' => [],
      ];

      // Include each grazing event record.
      /** @var \Drupal\farm_grazing_plan\Bundle\GrazingEventInterface[] $grazing_events */
      foreach ($grazing_events as $grazing_event) {
        $row_values['children'][] = $this->buildGrazingEventRow($grazing_event);
      }

      // Add the row object.
      // @todo Create and instantiate a wrapper data type instead of rows.
      $row_definition = TimelineRowDefinition::create('farm_timeline_row');
      $data['rows'][] = $this->typedDataManager->create($row_definition, $row_values);
    }

    // Serialize to JSON and return response.
    $serialized = $this->serializer->serialize($data, 'json');
    return new JsonResponse($serialized, 200, [], TRUE);
  }

  /**
   * Helper method for building a grazing event row.
   *
   * @param \Drupal\farm_grazing_plan\Bundle\GrazingEventInterface $grazing_event
   *   The grazing event plan_record entity.
   *
   * @return array
   *   Returns an array representing a single timeline row.
   */
  protected function buildGrazingEventRow(GrazingEventInterface $grazing_event) {

    // Load the grazing event's movement log.
    $log = $grazing_event->getLog();

    // Start a list of tasks.
    $tasks = [];

    // Add a task for the grazing event duration.
    $tasks[] = [
      'id' => 'grazing-event--duration--' . $grazing_event->id(),
      'start' => $grazing_event->get('start')->value,
      'end' => $grazing_event->get('start')->value + ($grazing_event->get('duration')->value * 60 * 60),
      'meta' => [
        'stage' => 'duration',
      ],
      'classes' => [
        'stage',
        "stage--duration",
      ],
    ];

    // Add a task for the recovery time.
    if (!empty($grazing_event->get('recovery')->value)) {
      $tasks[] = [
        'id' => 'grazing-event--recovery--' . $grazing_event->id(),
        'start' => $grazing_event->get('start')->value + ($grazing_event->get('duration')->value * 60 * 60),
        'end' => $grazing_event->get('start')->value + ($grazing_event->get('duration')->value * 60 * 60) + ($grazing_event->get('recovery')->value * 60 * 60),
        'meta' => [
          'stage' => 'recovery',
        ],
        'classes' => [
          'stage',
          "stage--recovery",
        ],
      ];
    }

    // Assemble the grazing event row.
    return [
      'id' => $this->uuidService->generate(),
      'label' => $log->label(),
      'link' => $log->toLink($log->label(), 'canonical')->toString(),
      'tasks' => $tasks,
    ];
  }

}
