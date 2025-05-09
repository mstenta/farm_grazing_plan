<?php

namespace Drupal\farm_grazing_plan\Controller;

use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\Core\State\StateInterface;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\farm_grazing_plan\Bundle\GrazingEventInterface;
use Drupal\farm_grazing_plan\GrazingPlanInterface;
use Drupal\farm_log\AssetLogsInterface;
use Drupal\farm_timeline\TypedData\TimelineRowDefinition;
use Drupal\log\Entity\LogInterface;
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
   * The asset logs service.
   *
   * @var \Drupal\farm_log\AssetLogsInterface
   */
  protected $assetLogs;

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
   * The state service for checking the overlap setting.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * Constructs a GrazingPlanTimeline instance.
   *
   * @param \Drupal\farm_grazing_plan\GrazingPlanInterface $grazing_plan
   *   The grazing plan service.
   * @param \Drupal\farm_log\AssetLogsInterface $asset_logs
   *   The asset logs service.
   * @param \Drupal\Component\Uuid\UuidInterface $uuid_service
   *   The UUID service.
   * @param \Drupal\Core\TypedData\TypedDataManagerInterface $typed_data_manager
   *   The typed data manager interface.
   * @param \Symfony\Component\Serializer\SerializerInterface $serializer
   *   The serializer service.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   */
  public function __construct(GrazingPlanInterface $grazing_plan, AssetLogsInterface $asset_logs, UuidInterface $uuid_service, TypedDataManagerInterface $typed_data_manager, SerializerInterface $serializer, StateInterface $state) {
    $this->grazingPlan = $grazing_plan;
    $this->assetLogs = $asset_logs;
    $this->uuidService = $uuid_service;
    $this->typedDataManager = $typed_data_manager;
    $this->serializer = $serializer;
    $this->state = $state;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('farm_grazing_plan'),
      $container->get('asset.logs'),
      $container->get('uuid'),
      $container->get('typed_data_manager'),
      $container->get('serializer'),
      $container->get('state')
    );
  }

  /**
   * API endpoint for grazing plan timeline by asset.
   *
   * @param \Drupal\plan\Entity\PlanInterface $plan
   *   The crop plan.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response of timeline data.
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
   *   JSON response of timeline data.
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
   *   Grazing events indexed by asset/location ID.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response of timeline data.
   */
  protected function buildTimeline(PlanInterface $plan, array $grazing_events_by_asset) {
    // Retrieve the state flag for shifting overlapping logs.
    $shift_overlapping = $this->state->get('log_reschedule.shift_overlapping', TRUE);
     if ($shift_overlapping) {
      \Drupal::logger('buildTimeline')->notice($shift_overlapping ? 'TRUE' : 'FALSE');
      \Drupal::logger('build')
    ->notice(sprintf(
      $shift_overlapping ? 'TRUE' : 'FALSE'
    ));
      $this->shiftOverlappingLogs($grazing_events_by_asset);
    }
    $data = [];
    foreach ($grazing_events_by_asset as $asset_id => $grazing_events) {
      // Load the asset.
      /** @var \Drupal\asset\Entity\AssetInterface $asset */
      $asset = $this->entityTypeManager()->getStorage('asset')->load($asset_id);

      // Build the row values for the asset.
      $row_values = [
        'id' => "asset--$asset_id",
        'label' => $asset->label(),
        'link' => $asset->toLink()->toString(),
        'expanded' => TRUE,
        'tasks' => array_map(function (LogInterface $log) use ($plan) {
          return $this->buildLogTask($plan, $log);
        }, $this->assetLogs->getLogs($asset)),
        'children' => [],
      ];

      // Add each grazing event record.
      foreach ($grazing_events as $grazing_event) {
        $row_values['children'][] = $this->buildGrazingEventRow($grazing_event);
      }

      // Sort the grazing event rows by the start time of their first task.
      usort($row_values['children'], function ($a, $b) {
        return $a['tasks'][0]['start'] <=> $b['tasks'][0]['start'];
      });

      // Create and add a timeline row.
      $row_definition = TimelineRowDefinition::create('farm_timeline_row');
      $data['rows'][] = $this->typedDataManager->create($row_definition, $row_values);
    }

    // Serialize the timeline data as JSON and return a response.
    $serialized = $this->serializer->serialize($data, 'json');
    return new JsonResponse($serialized, 200, [], TRUE);
  }

  /**
   * Helper method for building a grazing event row.
   *
   * Differentiates between planned (default) and actual events by checking the
   * "planned" field and assigns distinct CSS classes. Also adds a delete button.
   *
   * @param \Drupal\farm_grazing_plan\Bundle\GrazingEventInterface $grazing_event
   *   The grazing event record.
   *
   * @return array
   *   An array representing a single timeline row.
   */
  protected function buildGrazingEventRow(GrazingEventInterface $grazing_event) {
    // Load the associated movement log.
    $log = $grazing_event->getLog();

    // Prepare destination and edit URLs.
    $destination_url = $grazing_event->get('plan')->referencedEntities()[0]->toUrl()->toString();
    $edit_url = $grazing_event->toUrl('edit-form', ['query' => ['destination' => $destination_url]])->toString();

    $tasks = [];

    // Determine if the event is actual (planned field equals 1) or planned.
    if ($grazing_event->hasField('planned') && $grazing_event->get('planned')->value == 1) {
      // Actual event: use distinct classes for duration and recovery.
      $tasks[] = [
        'id' => 'grazing-event--duration--' . $grazing_event->id(),
        'edit_url' => $edit_url,
        'start' => $grazing_event->get('start')->value,
        'end' => $grazing_event->get('start')->value + ($grazing_event->get('duration')->value * 3600),
        'meta' => [
          'stage' => 'duration',
        ],
        'classes' => [
          'stage',
          'stage--actualduration',
        ],
      ];
      if (!empty($grazing_event->get('recovery')->value)) {
        $tasks[] = [
          'id' => 'grazing-event--recovery--' . $grazing_event->id(),
          'edit_url' => $edit_url,
          'start' => $grazing_event->get('start')->value + ($grazing_event->get('duration')->value * 3600),
          'end' => $grazing_event->get('start')->value + ($grazing_event->get('duration')->value * 3600)
            + ($grazing_event->get('recovery')->value * 3600),
          'meta' => [
            'stage' => 'recovery',
          ],
          'classes' => [
            'stage',
            'stage--actualrecovery',
          ],
        ];
      }
    }
    else {
      // Planned event.
      $tasks[] = [
        'id' => 'grazing-event--duration--' . $grazing_event->id(),
        'edit_url' => $edit_url,
        'start' => $grazing_event->get('start')->value,
        'end' => $grazing_event->get('start')->value + ($grazing_event->get('duration')->value * 3600),
        'meta' => [
          'stage' => 'duration',
        ],
        'classes' => [
          'stage',
          'stage--duration',
        ],
      ];
      if (!empty($grazing_event->get('recovery')->value)) {
        $tasks[] = [
          'id' => 'grazing-event--recovery--' . $grazing_event->id(),
          'edit_url' => $edit_url,
          'start' => $grazing_event->get('start')->value + ($grazing_event->get('duration')->value * 3600),
          'end' => $grazing_event->get('start')->value + ($grazing_event->get('duration')->value * 3600)
            + ($grazing_event->get('recovery')->value * 3600),
          'meta' => [
            'stage' => 'recovery',
          ],
          'classes' => [
            'stage',
            'stage--recovery',
          ],
        ];
      }
    }

    // Always include a task for the movement log.
    $plan = $grazing_event->get('plan')->first()?->entity;
    $tasks[] = $this->buildLogTask($plan, $log);

    return [
      'id' => $this->uuidService->generate(),
      'label' => $log->label(),
      'link' => $log->toLink($log->label(), 'canonical')->toString(),
      'tasks' => $tasks,
      'children' => [$this->buildDeletionRow($plan, $grazing_event)],
    ];
  }

  /**
   * Helper function for building a single log task.
   *
   * @param \Drupal\plan\Entity\PlanInterface $plan
   *   The plan entity.
   * @param \Drupal\log\Entity\LogInterface $log
   *   The log entity.
   *
   * @return array
   *   Returns an array representing a single log task.
   */
  protected function buildLogTask(PlanInterface $plan, LogInterface $log) {
    $destination_url = $plan->toUrl()->toString();
    $edit_url = $log->toUrl('edit-form', ['query' => ['destination' => $destination_url]])->toString();
    $log_id = $log->id();
    $bundle = $log->bundle();
    $status = $log->get('status')->value;
    return [
      'id' => $this->uuidService->generate(),
      'edit_url' => $edit_url,
      'start' => $log->get('timestamp')->value,
      'end' => $log->get('timestamp')->value + 86400,
      'meta' => [
        'label' => $log->label(),
        'entity_id' => $log_id,
        'entity_type' => 'log',
        'entity_bundle' => $bundle,
        'log_status' => $status,
      ],
      'classes' => [
        'log',
        "log--$bundle",
        "log--status-$status",
      ],
    ];
  }

  /**
   * Helper function for supplying a grazing event row with a delete button.
   *
   * @param \Drupal\plan\Entity\PlanInterface $plan
   *   The plan entity.
   * @param \Drupal\farm_grazing_plan\Bundle\GrazingEventInterface $grazing_event
   *   The grazing event entity.
   *
   * @return array
   *   Returns an array representing the deletion row.
   */
  protected function buildDeletionRow(PlanInterface $plan, GrazingEventInterface $grazing_event) {
    return [
      'id' => $this->uuidService->generate(),
      'label' => $grazing_event->label(),
      'link' => Link::createFromRoute('Delete grazing event', 'farm_grazing_plan.remove_event', [
        'plan' => $plan->id(),
        'assetId' => $grazing_event->id(),
      ])->toString(),
    ];
  }

  /**
   * Shift overlapping grazing events to avoid conflicts.
   *
   * Iterates through each asset's grazing events and, for events whose associated
   * log is in a pending status, adjusts the start time if it overlaps with the
   * previous event (including its recovery time).
   *
   * @param array $grazing_events_by_asset
   *   An array of grazing events indexed by asset ID.
   */
  protected function shiftOverlappingLogs(array $grazing_events_by_asset) {
    foreach ($grazing_events_by_asset as $asset_id => $grazing_events) {
      if (empty($grazing_events)) {
        continue;
      }
      // Sort grazing events by start time.
      usort($grazing_events, function ($a, $b) {
        return $a->get('start')->value <=> $b->get('start')->value;
      });
      $last_end_time = null;
      foreach ($grazing_events as $grazing_event) {
        // Only shift events whose log status is "pending".
        $log = $grazing_event->getLog();
        $status = $log->get('status')->value;
        if ($status === 'pending'
          && $grazing_event->hasField('planned')
          && (int) $grazing_event->get('planned')->value === 0)) {
          $start_time = $grazing_event->get('start')->value;
          $duration = $grazing_event->hasField('duration') ? $grazing_event->get('duration')->value * 3600 : 0;
          $recovery = $grazing_event->hasField('recovery') ? $grazing_event->get('recovery')->value * 3600 : 0;
          if ($last_end_time !== null && $start_time <= $last_end_time) {
            $grazing_event->set('start', $last_end_time);
            $grazing_event->save();
            \Drupal::logger('log')->notice("Shifted Grazing Event ID {$grazing_event->id()} to start at " . date('Y-m-d H:i:s', $last_end_time));
            $start_time = $last_end_time;
          }
          $last_end_time = $start_time + $duration + $recovery;
        }
      }
    }
  }

}
