<?php

declare(strict_types=1);

namespace Drupal\Tests\farm_grazing_plan\Kernel;

use Drupal\farm_grazing_plan\Plugin\Validation\Constraint\GrazingEventLogRestrictedFields;
use Drupal\log\Entity\LogInterface;
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

}
