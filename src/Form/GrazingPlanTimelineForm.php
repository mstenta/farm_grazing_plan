<?php

namespace Drupal\farm_grazing_plan\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\farm_grazing_plan\GrazingPlanInterface;
use Drupal\plan\Entity\PlanInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Grazing plan form.
 */
class GrazingPlanTimelineForm extends FormBase {

  /**
   * Entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The grazing plan service.
   *
   * @var \Drupal\farm_grazing_plan\GrazingPlanInterface
   */
  protected GrazingPlanInterface $grazingPlan;

  /**
   * GrazingPlanTimelineForm constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   Entity type manager.
   * @param \Drupal\farm_grazing_plan\GrazingPlanInterface $grazing_plan
   *   The grazing plan service.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, GrazingPlanInterface $grazing_plan) {
    $this->entityTypeManager = $entity_type_manager;
    $this->grazingPlan = $grazing_plan;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('farm_grazing_plan'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'farm_grazing_plan_timeline_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $plan = NULL) {

    // If a plan is not available, bail.
    if (empty($plan) || !($plan instanceof PlanInterface) || $plan->bundle() != 'grazing') {
      return [
        '#type' => 'markup',
        '#markup' => 'No grazing plan was provided.',
      ];
    }

    // Toggle the timeline view by asset (default) or by location.
    $mode_options = [
      'asset' => $this->t('Asset'),
      'location' => $this->t('Location'),
    ];
    $mode_default = 'asset';
    $form['options'] = [
      '#type' => 'details',
      '#title' => $this->t('Options'),
      '#weight' => 100,
    ];
    $form['options']['mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('Organize timeline by'),
      '#options' => $mode_options,
      '#default_value' => $mode_default,
      '#ajax' => [
        'callback' => [$this, 'timelineCallback'],
        'wrapper' => 'timeline-wrapper',
      ],
    ];
    
    $shift_overlapping = !\Drupal::state()->get('log_reschedule.shift_overlapping', TRUE);
    $form['options']['enable_time_conflict'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable time conflicts'),
      '#description' => $this->t('When checked, grazing events can overlap in time. When unchecked, events will automatically shift to avoid conflicts.'),
      '#default_value' => $shift_overlapping,
      '#ajax' => [
        'callback' => [$this, 'timelineCallback'],
        'wrapper' => 'timeline-wrapper',
      ],
    ];
    // Add a wrapper for the timeline.
    $form['timeline'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => [
        'id' => 'timeline-wrapper',
        'class' => ['gin-layer-wrapper'],
      ],
    ];

    // Get the selected display mode from form state.
    $display_mode = $form_state->getValue('mode', $mode_default);
    
    $enable_time_conflict = $form_state->getValue('enable_time_conflict', $shift_overlapping);
    if($enable_time_conflict !== $shift_overlapping){
      \Drupal::state()->set('log_reschedule.shift_overlapping', !$enable_time_conflict);
      \Drupal::logger('farm_grazing_plan')->notice('Updated time conflict setting: ' . ($enable_time_conflict ? 'Enabled' : 'Disabled'));
    }
    // Render the timeline.
    $row_url = Url::fromRoute("farm_grazing_plan.timeline_by_$display_mode", ['plan' => $plan->id()]);
    $form['timeline']['gantt'] = [
      '#type' => 'farm_timeline',
      '#rows' => [$row_url->setAbsolute()->toString()],
      '#attributes' => [
        'data-table-header' => $this->t('Grazing events (by @type)', ['@type' => $mode_options[$display_mode]]),
      ],
      '#attached' => [
        'library' => ['farm_grazing_plan/timeline'],
      ],
    ];

    return $form;
  }

  /**
   * Ajax callback for timeline.
   */
  public function timelineCallback(array $form, FormStateInterface $form_state) {
    return $form['timeline'];
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {

  }

}
