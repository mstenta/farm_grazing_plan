<?php

declare(strict_types=1);

namespace Drupal\Tests\farm_grazing_plan\Kernel;

use Drupal\farm_grazing_plan\Plugin\Validation\Constraint\GrazingEventLog;
use Drupal\farm_grazing_plan\Plugin\Validation\Constraint\GrazingEventLogRestrictedFields;
use Drupal\farm_grazing_plan\Plugin\Validation\Constraint\GrazingEventOrder;
use Drupal\log\Entity\Log;
use Drupal\log\Entity\LogInterface;
use Drupal\plan\Entity\Plan;
use Drupal\plan\Entity\PlanRecord;
use Drupal\plan\Entity\PlanRecordInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests for the farm_grazing_plan entity validation constraints.
 */
#[Group('farm_grazing_plan')]
#[RunTestsInSeparateProcesses]
class GrazingPlanConstraintsTest extends GrazingPlanTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'farm_grazing_plan_constraint_test',
    'views',
  ];

  /**
   * Test the GrazingEventLogRestrictedFields constraint on log entities.
   */
  public function testGrazingEventLogRestrictedFieldsValidation() {

    // Create mock plan entities.
    $this->createMockPlanEntities();

    // Load grazing events.
    /** @var \Drupal\farm_grazing_plan\Bundle\GrazingEventInterface[] $grazing_events */
    $grazing_events = \Drupal::service('farm_grazing_plan')->getGrazingEvents($this->plan);
    $this->assertCount(10, $grazing_events);

    // Load the first grazing event log.
    $grazing_event = reset($grazing_events);
    $log = $grazing_event->getLog();

    // Confirm that the log validates to start.
    $violations = $log->validate();
    $this->assertEmpty($violations);

    // Confirm that modifying restricted fields causes violations.
    $modify_fields = [
      'timestamp' => strtotime('tomorrow'),
      'asset' => [],
      'location' => [],
      'is_movement' => FALSE,
    ];
    foreach ($modify_fields as $field_name => $value) {
      $this->assertGrazingEventLogRestrictedFieldsViolation($log, $field_name, $value);
    }
  }

  /**
   * Assert a GrazingEventLogRestrictedFields violation for a field change.
   *
   * @param \Drupal\log\Entity\LogInterface $log
   *   The log entity.
   * @param string $field_name
   *   The field name to test.
   * @param array|bool|int $value
   *   The field value to set.
   */
  protected function assertGrazingEventLogRestrictedFieldsViolation(LogInterface $log, string $field_name, array|bool|int $value): void {

    // Remember the original field value.
    $original_value = $log->get($field_name)->getValue();

    // Change the field value and confirm violation.
    $log->set($field_name, $value);
    $violations = $log->validate();
    $this->assertEquals(1, $violations->count());
    $this->assertInstanceOf(GrazingEventLogRestrictedFields::class, $violations->get(0)->getConstraint());

    // Reset field value and confirm no violation.
    $log->set($field_name, $original_value);
    $violations = $log->validate();
    $this->assertEmpty($violations);
  }

  /**
   * Test the GrazingEventLog constraint on plan_record entities.
   */
  public function testGrazingEventLogValidation() {

    // Create mock plan entities.
    $this->createMockPlanEntities();

    // Get a timestamp for the next grazing event.
    $timestamp = $this->nextGrazingEventTimestamp();

    // Create a valid movement log that is not yet associated with a grazing
    // event, and confirm that a grazing event referencing it has no
    // GrazingEventLog violations.
    $valid_log = $this->createTestLog($timestamp, [$this->animalAssets[0]], [$this->landAssets[0]]);
    $record = $this->createGrazingEventRecord($valid_log);
    $this->assertGrazingEventViolations($record, GrazingEventLog::class, []);

    // Create a non-movement log and confirm the violation.
    $non_movement_log = $this->createTestLog($timestamp, [$this->animalAssets[0]], [$this->landAssets[0]], FALSE);
    $record = $this->createGrazingEventRecord($non_movement_log);
    $this->assertGrazingEventViolations($record, GrazingEventLog::class, [
      'Only movement logs can be added to a grazing plan.',
    ]);

    // Confirm that a log that is already part of a grazing event cannot be
    // associated with a new grazing event.
    $existing_log = reset($this->movementLogs);
    $record = $this->createGrazingEventRecord($existing_log);
    $this->assertGrazingEventViolations($record, GrazingEventLog::class, [
      'This log is already part of a grazing plan.',
    ]);

    // Confirm that an existing grazing event is not flagged for referencing
    // its own log.
    $existing_records = \Drupal::entityTypeManager()->getStorage('plan_record')->loadByProperties([
      'type' => 'grazing_event',
    ]);
    $existing_record = reset($existing_records);
    $this->assertGrazingEventViolations($existing_record, GrazingEventLog::class, []);

    // Create a log that does not reference an asset and confirm the
    // violation.
    $no_asset_log = $this->createTestLog($timestamp, [], [$this->landAssets[0]]);
    $record = $this->createGrazingEventRecord($no_asset_log);
    $this->assertGrazingEventViolations($record, GrazingEventLog::class, [
      'This log does not reference an asset. A grazing event must move one asset.',
    ]);

    // Create a log that references multiple assets and confirm the
    // violation.
    $multi_asset_log = $this->createTestLog($timestamp, [
      $this->animalAssets[0],
      $this->animalAssets[1],
    ], [$this->landAssets[0]]);
    $record = $this->createGrazingEventRecord($multi_asset_log);
    $this->assertGrazingEventViolations($record, GrazingEventLog::class, [
      'This log references multiple assets. A grazing event can only move one asset.',
    ]);

    // Create a log that does not reference a location and confirm the
    // violation.
    $no_location_log = $this->createTestLog($timestamp, [$this->animalAssets[0]]);
    $record = $this->createGrazingEventRecord($no_location_log);
    $this->assertGrazingEventViolations($record, GrazingEventLog::class, [
      'This log does not reference a location. A grazing event must move an asset to a location.',
    ]);

    // Create a log that references multiple locations and confirm the
    // violation.
    $multi_location_log = $this->createTestLog($timestamp, [$this->animalAssets[0]], [
      $this->landAssets[0],
      $this->landAssets[1],
    ]);
    $record = $this->createGrazingEventRecord($multi_location_log);
    $this->assertGrazingEventViolations($record, GrazingEventLog::class, [
      'This log references multiple locations. A grazing event can only move an asset to a single location.',
    ]);

    // Confirm that a non-existent log is flagged.
    $record = $this->createGrazingEventRecord();
    $this->assertGrazingEventViolations($record, GrazingEventLog::class, [
      'The referenced log does not exist.',
    ]);

    // Confirm that the constraint does not apply to plan records of other
    // types.
    $record = PlanRecord::create([
      'type' => 'test',
      'plan' => $this->plan->id(),
    ]);
    $this->assertGrazingEventViolations($record, GrazingEventLog::class, []);
  }

  /**
   * Test the GrazingEventOrder constraint on plan_record entities.
   */
  public function testGrazingEventOrderValidation() {

    // Create mock plan entities.
    $this->createMockPlanEntities();

    // Get the last grazing events for each animal asset.
    /** @var \Drupal\farm_grazing_plan\GrazingPlanInterface $grazing_plan */
    $grazing_plan = \Drupal::service('farm_grazing_plan');
    $first_animal = reset($this->animalAssets);
    $second_animal = end($this->animalAssets);
    $grazing_events = $grazing_plan->getGrazingEventsByAsset($this->plan);
    $first_animal_last_event = end($grazing_events[$first_animal->id()]);
    $second_animal_last_event = end($grazing_events[$second_animal->id()]);
    $first_last_start = (int) $first_animal_last_event->get('start')->value;
    $second_last_start = (int) $second_animal_last_event->get('start')->value;

    // The mock events are created for the first animal, then the second, so
    // the last event for the second animal is after the last event for the
    // first animal.
    $this->assertGreaterThan($first_last_start, $second_last_start);

    // Confirm that a new event for the first animal with a start after its
    // last event has no GrazingEventOrder violations.
    $log = $this->createTestLog($first_last_start + (7 * 24 * 60 * 60), [$first_animal], [$this->landAssets[0]]);
    $record = $this->createGrazingEventRecord($log, $log->get('timestamp')->value);
    $this->assertGrazingEventViolations($record, GrazingEventOrder::class, []);

    // Confirm that a new event for the first animal with a start before the
    // second animal's last event (but after its own last event) has no
    // violations, because events are ordered per asset.
    $log = $this->createTestLog($first_last_start + 60, [$first_animal], [$this->landAssets[0]]);
    $record = $this->createGrazingEventRecord($log, $log->get('timestamp')->value);
    $this->assertGrazingEventViolations($record, GrazingEventOrder::class, []);

    // Confirm that a new event with a start equal to the last event start has
    // no violations.
    $log = $this->createTestLog($first_last_start, [$first_animal], [$this->landAssets[0]]);
    $record = $this->createGrazingEventRecord($log, $log->get('timestamp')->value);
    $this->assertGrazingEventViolations($record, GrazingEventOrder::class, []);

    // Confirm that a new event with a start before the last event start has a
    // violation, and that it targets the start field.
    $log = $this->createTestLog($first_last_start + (7 * 24 * 60 * 60), [$first_animal], [$this->landAssets[0]]);
    $record = $this->createGrazingEventRecord($log, $first_last_start - 60);
    $this->assertGrazingEventViolations($record, GrazingEventOrder::class, [
      'The planned start date/time is before the last existing grazing event for this asset in the plan. Grazing events can only be added to the end of the plan.',
    ]);

    // Confirm that a new event with a log timestamp before the last event
    // start (but a valid planned start) has a violation targeting the log.
    $log = $this->createTestLog($first_last_start - 60, [$first_animal], [$this->landAssets[0]]);
    $record = $this->createGrazingEventRecord($log, $first_last_start + (7 * 24 * 60 * 60));
    $this->assertGrazingEventViolations($record, GrazingEventOrder::class, [
      'The movement log timestamp is before the last existing grazing event for this asset in the plan. Grazing events can only be added to the end of the plan.',
    ]);

    // Confirm that a new event with both an early start and an early log
    // timestamp has both violations.
    $log = $this->createTestLog($first_last_start - 60, [$first_animal], [$this->landAssets[0]]);
    $record = $this->createGrazingEventRecord($log, $first_last_start - 60);
    $this->assertGrazingEventViolations($record, GrazingEventOrder::class, [
      'The planned start date/time is before the last existing grazing event for this asset in the plan. Grazing events can only be added to the end of the plan.',
      'The movement log timestamp is before the last existing grazing event for this asset in the plan. Grazing events can only be added to the end of the plan.',
    ]);

    // Confirm that an existing grazing event is not flagged for an early
    // start, because only new events are validated.
    $existing_records = \Drupal::entityTypeManager()->getStorage('plan_record')->loadByProperties([
      'type' => 'grazing_event',
    ]);
    $existing_record = reset($existing_records);
    $existing_record->set('start', $first_last_start - 60);
    $this->assertGrazingEventViolations($existing_record, GrazingEventOrder::class, []);

    // Confirm that events are only compared within the same plan, by creating
    // a new plan without grazing events and adding a very early event to it.
    $plan2 = Plan::create([
      'name' => $this->randomMachineName(),
      'type' => 'grazing',
    ]);
    $plan2->save();
    $timestamp = $first_last_start - (365 * 24 * 60 * 60);
    $log = $this->createTestLog($timestamp, [$first_animal], [$this->landAssets[0]]);
    $record = $this->createGrazingEventRecord($log, $timestamp);
    $record->set('plan', $plan2);
    $this->assertGrazingEventViolations($record, GrazingEventOrder::class, []);
  }

  /**
   * Create a test log.
   *
   * @param int $timestamp
   *   The timestamp.
   * @param \Drupal\asset\Entity\AssetInterface[] $assets
   *   The referenced asset(s), if any.
   * @param \Drupal\asset\Entity\AssetInterface[] $locations
   *   The referenced location(s), if any.
   * @param bool $is_movement
   *   Whether the log is a movement.
   *
   * @return \Drupal\log\Entity\LogInterface
   *   Returns the log.
   */
  protected function createTestLog(int $timestamp, array $assets = [], array $locations = [], bool $is_movement = TRUE): LogInterface {
    $values = [
      'name' => $this->randomMachineName(),
      'type' => 'activity',
      'timestamp' => $timestamp,
      'asset' => $assets,
      'location' => $locations,
      'is_movement' => $is_movement,
      'status' => 'done',
    ];
    $log = Log::create($values);
    $log->save();
    return $log;
  }

  /**
   * Create an unsaved grazing event plan record.
   *
   * @param \Drupal\log\Entity\LogInterface $log
   *   The log entity to reference, or NULL.
   * @param int|null $start
   *   The start timestamp. Defaults to now.
   *
   * @return \Drupal\plan\Entity\PlanRecordInterface
   *   Returns the plan record.
   */
  protected function createGrazingEventRecord(?LogInterface $log = NULL, ?int $start = NULL): PlanRecordInterface {
    return PlanRecord::create([
      'type' => 'grazing_event',
      'plan' => $this->plan->id(),
      'log' => $log,
      'start' => !is_null($start) ? $start : \Drupal::time()->getRequestTime(),
      'duration' => 7 * 24,
    ]);
  }

  /**
   * Assert constraint violations for a grazing event plan_record entity.
   *
   * @param \Drupal\plan\Entity\PlanRecordInterface $record
   *   The plan record to validate.
   * @param string $expected_constraint
   *   The expected constraint class name.
   * @param string[] $expected_messages
   *   The expected violation messages.
   */
  protected function assertGrazingEventViolations(PlanRecordInterface $record, string $expected_constraint, array $expected_messages): void {
    $messages = [];
    foreach ($record->validate() as $violation) {
      if ($violation->getConstraint()::class === $expected_constraint) {
        $messages[] = $violation->getMessage();
      }
    }
    sort($expected_messages);
    sort($messages);
    $this->assertEquals($expected_messages, $messages);
  }

}
