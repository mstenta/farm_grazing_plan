<?php

declare(strict_types=1);

namespace Drupal\Tests\farm_grazing_plan\Kernel;

use Drupal\farm_grazing_plan\Plugin\Validation\Constraint\GrazingEventLog;
use Drupal\farm_grazing_plan\Plugin\Validation\Constraint\GrazingEventLogRestrictedFields;
use Drupal\log\Entity\Log;
use Drupal\log\Entity\LogInterface;
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
    $this->assertGrazingEventLogViolations($record, []);

    // Create a non-movement log and confirm the violation.
    $non_movement_log = $this->createTestLog($timestamp, [$this->animalAssets[0]], [$this->landAssets[0]], FALSE);
    $record = $this->createGrazingEventRecord($non_movement_log);
    $this->assertGrazingEventLogViolations($record, [
      'Only movement logs can be added to a grazing plan.',
    ]);

    // Confirm that a log that is already part of a grazing event cannot be
    // associated with a new grazing event.
    $existing_log = reset($this->movementLogs);
    $record = $this->createGrazingEventRecord($existing_log);
    $this->assertGrazingEventLogViolations($record, [
      'This log is already part of a grazing plan.',
    ]);

    // Confirm that an existing grazing event is not flagged for referencing
    // its own log.
    $existing_records = \Drupal::entityTypeManager()->getStorage('plan_record')->loadByProperties([
      'type' => 'grazing_event',
    ]);
    $existing_record = reset($existing_records);
    $this->assertGrazingEventLogViolations($existing_record, []);

    // Create a log that does not reference an asset and confirm the
    // violation.
    $no_asset_log = $this->createTestLog($timestamp, [], [$this->landAssets[0]]);
    $record = $this->createGrazingEventRecord($no_asset_log);
    $this->assertGrazingEventLogViolations($record, [
      'This log does not reference an asset. A grazing event must move one asset.',
    ]);

    // Create a log that references multiple assets and confirm the
    // violation.
    $multi_asset_log = $this->createTestLog($timestamp, [
      $this->animalAssets[0],
      $this->animalAssets[1],
    ], [$this->landAssets[0]]);
    $record = $this->createGrazingEventRecord($multi_asset_log);
    $this->assertGrazingEventLogViolations($record, [
      'This log references multiple assets. A grazing event can only move one asset.',
    ]);

    // Create a log that does not reference a location and confirm the
    // violation.
    $no_location_log = $this->createTestLog($timestamp, [$this->animalAssets[0]]);
    $record = $this->createGrazingEventRecord($no_location_log);
    $this->assertGrazingEventLogViolations($record, [
      'This log does not reference a location. A grazing event must move an asset to a location.',
    ]);

    // Create a log that references multiple locations and confirm the
    // violation.
    $multi_location_log = $this->createTestLog($timestamp, [$this->animalAssets[0]], [
      $this->landAssets[0],
      $this->landAssets[1],
    ]);
    $record = $this->createGrazingEventRecord($multi_location_log);
    $this->assertGrazingEventLogViolations($record, [
      'This log references multiple locations. A grazing event can only move an asset to a single location.',
    ]);

    // Confirm that a non-existent log is flagged.
    $record = $this->createGrazingEventRecord();
    $this->assertGrazingEventLogViolations($record, [
      'The referenced log does not exist.',
    ]);

    // Confirm that the constraint does not apply to plan records of other
    // types.
    $record = PlanRecord::create([
      'type' => 'test',
      'plan' => $this->plan->id(),
    ]);
    $this->assertGrazingEventLogViolations($record, []);
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
   *
   * @return \Drupal\plan\Entity\PlanRecordInterface
   *   Returns the plan record.
   */
  protected function createGrazingEventRecord(?LogInterface $log = NULL): PlanRecordInterface {
    return PlanRecord::create([
      'type' => 'grazing_event',
      'plan' => $this->plan->id(),
      'log' => $log,
      'start' => \Drupal::time()->getRequestTime(),
      'duration' => 7 * 24,
    ]);
  }

  /**
   * Assert the GrazingEventLog violations for a plan record.
   *
   * @param \Drupal\plan\Entity\PlanRecordInterface $record
   *   The plan record to validate.
   * @param string[] $expected_messages
   *   The expected violation messages.
   */
  protected function assertGrazingEventLogViolations(PlanRecordInterface $record, array $expected_messages): void {
    $messages = [];
    foreach ($record->validate() as $violation) {
      if ($violation->getConstraint() instanceof GrazingEventLog) {
        $messages[] = $violation->getMessage();
      }
    }
    sort($expected_messages);
    sort($messages);
    $this->assertEquals($expected_messages, $messages);
  }

}
