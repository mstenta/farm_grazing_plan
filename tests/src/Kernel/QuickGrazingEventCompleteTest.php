<?php

declare(strict_types=1);

namespace Drupal\Tests\farm_grazing_plan\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\Tests\farm_quick\Kernel\QuickFormTestBase;
use Drupal\farm_quick\Form\QuickForm;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests for the grazing event complete quick form.
 */
#[Group('farm_grazing_plan')]
#[RunTestsInSeparateProcesses]
class QuickGrazingEventCompleteTest extends QuickFormTestBase {

  /**
   * Quick form ID.
   *
   * @var string
   */
  protected $quickFormId = 'grazing_event_complete';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'farm_activity',
    'farm_grazing_plan',
    'plan',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('plan');
    $this->installEntitySchema('plan_record');
    $this->installConfig([
      'farm_activity',
      'farm_grazing_plan',
    ]);
  }

  /**
   * Test the grazing event complete quick form.
   */
  public function testQuickGrazingEventComplete() {

    // Confirm the quick form builds.
    $form_state = new FormState();
    $form = \Drupal::service('form_builder')->getForm(QuickForm::class, $form_state, $this->quickFormId);
    $this->assertArrayHasKey('submit', $form['actions']);

    // @todo Add a happy path submitQuickForm() test once the quick form has
    // form fields and submit logic.
  }

}
