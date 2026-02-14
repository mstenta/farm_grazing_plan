<?php

declare(strict_types=1);

namespace Drupal\farm_grazing_plan\Hook;

use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Form hook implementations for farm_grazing_plan.
 */
class FormHooks {

  use AutowireTrait;

  public function __construct(
    protected RequestStack $requestStack,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Implements hook_form_BASE_FORM_ID_alter().
   */
  #[Hook('form_plan_record_grazing_event_edit_form_alter')]
  public function formPlanRecordGrazingEventEditFormAlter(&$form, FormStateInterface $form_state, $form_id) {

    // Hide plan and log fields in grazing_event edit forms.
    if (!empty($form['plan'])) {
      $form['plan']['#access'] = FALSE;
    }
    if (!empty($form['log'])) {
      $form['log']['#access'] = FALSE;
    }
  }

  /**
   * Implements hook_form_FORM_ID_alter().
   */
  #[Hook('form_quick_form_movement_alter')]
  public function formQuickFormMovementAlter(&$form, FormStateInterface $form_state, $form_id) {

    // Alter the movement quick form, if a grazing plan was specified.
    $plan_id = $this->requestStack->getCurrentRequest()->query->get('plan');
    if (empty($plan_id)) {
      return;
    }
    /** @var \Drupal\plan\Entity\PlanInterface|null $plan */
    $plan = $this->entityTypeManager->getStorage('plan')->load($plan_id);
    if (is_null($plan) || $plan->bundle() !== 'grazing') {
      return;
    }

    // Save the plan ID.
    $form['plan_id'] = [
      '#type' => 'value',
      '#value' => $plan->id(),
    ];

    // Add a submit function that will redirect to the "Add grazing event" form
    // with the new log pre-populated.
    $form['#submit'][] = [self::class, 'plantingQuickFormSubmit'];
  }

  /**
   * Movement quick form submit function.
   */
  public static function plantingQuickFormSubmit(array $form, FormStateInterface $form_state) {

    // Find the log that was just created.
    $log_id = \Drupal::database()->query("SELECT entity_id FROM {log__quick} WHERE quick_value = 'movement' ORDER BY entity_id DESC LIMIT 1")->fetchField();

    // Redirect to the "Add grazing event" form with the log ID pre-populated.
    $form_state->setRedirect('farm_grazing_plan.add_event', ['plan' => $form_state->getValue('plan_id')], ['query' => ['log' => $log_id]]);
  }

}
