<?php

namespace Drupal\farm_grazing_plan\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\plan\Entity\PlanInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\farm_grazing_plan\GrazingPlanInterface;
use Drupal\farm_grazing_plan\Bundle\GrazingEventInterface;

/**
 * Grazing plan remove event form.
 */
class GrazingPlanRemoveEventForm extends FormBase {

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
   * Constructs a new GrazingPlanRemoveEventForm.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
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
      $container->get('farm_grazing_plan')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'farm_grazing_plan_remove_event_form';
  }

  /**
   * Helper function for populating the form selection with grazing events.
   *
   * Saves the event ID to the form state using the rendered label as key.
   * Returns an associative array where keys are rendered labels and values are
   * the label objects.
   *
   * @param \Drupal\plan\Entity\PlanInterface $plan
   *   The plan entity.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array
   *   An associative array of grazing event labels.
   */
  public function loadGrazingEvents(PlanInterface $plan, FormStateInterface $form_state): array {
    $grazing_events = $this->grazingPlan->getGrazingEvents($plan);
    $options = [];

    foreach ($grazing_events as $grazing_event) {
      $label = $grazing_event->label();
      $rendered_label = $label->render();
      // Store the label as both the key and the value.
      $options[$rendered_label] = $label;
      // Save the event ID for later retrieval.
      $form_state->set($rendered_label, $grazing_event->id());
    }

    return $options;
  }

  /**
   * Title callback.
   *
   * @param \Drupal\plan\Entity\PlanInterface|null $plan
   *   The plan entity.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   Returns the title.
   */
  public function title(?PlanInterface $plan = NULL) {
    if (empty($plan)) {
      return $this->t('Remove grazing event');
    }
    return $this->t('Remove grazing event from @plan', ['@plan' => $plan->label()]);
  }

  /**
   * {@inheritdoc}
   *
   * @param \Drupal\plan\Entity\PlanInterface|null $plan
   *   The plan entity.
   * @param string|null $assetId
   *   (Optional) An asset ID to preselect a grazing event.
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?PlanInterface $plan = NULL, ?string $assetId = NULL) {
    if (empty($plan)) {
      return $form;
    }
    $form_state->set('plan_id', $plan->id());

    $options = $this->loadGrazingEvents($plan, $form_state);
    $form['event'] = [
      '#type' => 'select',
      '#title' => $this->t('Select grazing event:'),
      '#options' => $options,
      '#required' => TRUE,
    ];

    // Prepopulate event value if an asset ID was provided.
    if (!empty($assetId)) {
      $eventEntity = $this->entityTypeManager->getStorage('plan_record')->load($assetId);
      if (!empty($eventEntity)) {
        $default_label = $eventEntity->label()->render();
        $form['event']['#default_value'] = $default_label;
      }
    }

    $form['scope'] = [
      '#type' => 'radios',
      '#title' => $this->t('Delete:'),
      '#options' => [
        $this->t('Grazing event only'),
        $this->t('Grazing event & movement log'),
      ],
      '#required' => TRUE,
    ];

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $plan_id = $form_state->get('plan_id');
    $options = $form['event']['#options'];
    $selected_rendered_label = $form_state->getValue('event');

    // Retrieve the event ID stored earlier.
    $event_id = $form_state->get($selected_rendered_label);
    $grazing_event = $this->entityTypeManager->getStorage('plan_record')->load($event_id);
    $log = NULL;
    if ($form_state->getValue('scope') == 1) {
      $log = $grazing_event->getLog();
    }

    $grazing_event->delete();
    if (!empty($log)) {
      $log->delete();
    }

    $this->messenger()->addMessage($this->t('Deleted @grazing_event', ['@grazing_event' => $options[$selected_rendered_label]]));
    $form_state->setRedirect('entity.plan.canonical', ['plan' => $plan_id]);
  }
}
