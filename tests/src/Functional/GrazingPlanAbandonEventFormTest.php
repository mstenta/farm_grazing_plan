<?php

declare(strict_types=1);

namespace Drupal\Tests\farm_grazing_plan\Functional;

use Drupal\Tests\farm_grazing_plan\Traits\MockGrazingPlanEntitiesTrait;
use Drupal\Tests\farm_test\Functional\FarmBrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the grazing plan abandon event form.
 */
#[Group('farm_grazing_plan')]
#[RunTestsInSeparateProcesses]
class GrazingPlanAbandonEventFormTest extends FarmBrowserTestBase {

  use MockGrazingPlanEntitiesTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'farm_grazing_plan',
    'farm_land',
  ];

  /**
   * Test that a completed grazing event cannot be abandoned.
   */
  public function testAbandonCompletedEventBlocked() {

    // Create mock plan entities. The first events are done.
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

    // Get a done grazing event and its log.
    /** @var \Drupal\farm_grazing_plan\Bundle\GrazingEventInterface $grazing_event */
    $grazing_event = reset($this->grazingEvents);
    $log = $grazing_event->getLog();
    $log_id = $log->id();
    $log_revision_id = $log->getRevisionId();
    $this->assertEquals('done', $log->get('status')->value);

    // Submit the abandon form for the done grazing event.
    $this->drupalGet('/plan/' . $this->plan->id() . '/grazing/event/' . $grazing_event->id() . '/abandon');
    $this->submitForm([], 'Confirm');

    // Confirm that the error message is shown.
    $this->assertSession()->pageTextContains('Only pending grazing events can be abandoned.');

    // Confirm that the log is unchanged.
    $log = \Drupal::entityTypeManager()->getStorage('log')->load($log_id);
    $this->assertEquals('done', $log->get('status')->value);
    $this->assertEquals($log_revision_id, $log->getRevisionId());
    $this->assertNull($log->getRevisionLogMessage());
  }

}
