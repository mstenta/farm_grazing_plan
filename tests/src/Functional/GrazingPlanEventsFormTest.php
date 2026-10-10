<?php

declare(strict_types=1);

namespace Drupal\Tests\farm_grazing_plan\Functional;

use Drupal\Tests\farm_grazing_plan\Traits\MockGrazingPlanEntitiesTrait;
use Drupal\Tests\farm_test\Functional\FarmBrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that the grazing plan events form only saves changed entities.
 */
#[Group('farm_grazing_plan')]
#[RunTestsInSeparateProcesses]
class GrazingPlanEventsFormTest extends FarmBrowserTestBase {

  use MockGrazingPlanEntitiesTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'farm_grazing_plan',
    'farm_land',
  ];

  /**
   * Test that submitting the form with no changes does not save anything.
   */
  public function testSubmitWithoutChanges() {

    // Create mock plan entities.
    $this->createMockPlanEntities();

    // Create and log in a user with access to update grazing plans.
    $user = $this->drupalCreateUser([
      'view any grazing plan',
      'update any grazing plan',
      'view any animal asset',
      'view any land asset',
      'access asset collection',
    ]);
    $this->drupalLogin($user);

    // Get the entity storages.
    $plan_storage = \Drupal::entityTypeManager()->getStorage('plan');
    $plan_record_storage = \Drupal::entityTypeManager()->getStorage('plan_record');

    // Record the plan's current revision ID and the original values of the
    // grazing events and logs.
    $plan = $plan_storage->load($this->plan->id());
    $plan_revision_id = $plan->getRevisionId();
    $original = [];
    $original_log_revision_ids = [];
    foreach ($this->grazingEvents as $grazing_event) {
      /** @var \Drupal\farm_grazing_plan\Bundle\GrazingEventInterface $grazing_event */
      $log = $grazing_event->getLog();
      $this->assertNotNull($log);
      $original[$grazing_event->id()] = [
        'start' => $grazing_event->get('start')->value,
        'duration' => $grazing_event->get('duration')->value,
        'recovery' => $grazing_event->get('recovery')->value,
        'timestamp' => $log->get('timestamp')->value,
      ];
      $original_log_revision_ids[$log->id()] = $log->getRevisionId();
    }

    // Load the grazing plan and submit the events form without changing any
    // values. The pending start dates are recomputed from the default next
    // planned movement dates, which match the original values for this test
    // data, so nothing should be saved.
    $this->drupalGet('/plan/' . $this->plan->id());
    $this->submitForm([], 'Save events');

    // Confirm that the warning message is shown.
    $this->assertSession()->pageTextContains('No grazing events saved.');

    // Confirm that the plan was not saved, and that the grazing events and
    // logs are unchanged.
    $plan = $plan_storage->load($this->plan->id());
    $this->assertEquals($plan_revision_id, $plan->getRevisionId());
    $this->assertNull($plan->getRevisionLogMessage());
    foreach ($this->grazingEvents as $grazing_event) {
      /** @var \Drupal\farm_grazing_plan\Bundle\GrazingEventInterface $grazing_event */
      $log = $grazing_event->getLog();
      $this->assertNotNull($log);
      $saved = $plan_record_storage->load($grazing_event->id());
      $this->assertEquals($original[$grazing_event->id()]['start'], $saved->get('start')->value);
      $this->assertEquals($original[$grazing_event->id()]['duration'], $saved->get('duration')->value);
      $this->assertEquals($original[$grazing_event->id()]['recovery'], $saved->get('recovery')->value);
      $this->assertEquals($original_log_revision_ids[$log->id()], $log->getRevisionId());
      $this->assertEquals($original[$grazing_event->id()]['timestamp'], $log->get('timestamp')->value);
      $this->assertNull($log->getRevisionLogMessage());
    }
  }

}
