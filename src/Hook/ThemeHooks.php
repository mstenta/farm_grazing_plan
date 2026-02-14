<?php

declare(strict_types=1);

namespace Drupal\farm_grazing_plan\Hook;

use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\farm_grazing_plan\Form\GrazingPlanTimelineForm;
use Drupal\farm_grazing_plan\GrazingPlanInterface;

/**
 * Theme hook implementations for farm_grazing_plan.
 */
class ThemeHooks {

  use AutowireTrait;

  public function __construct(
    protected GrazingPlanInterface $grazingPlan,
    protected FormBuilderInterface $formBuilder,
  ) {}

  /**
   * Implements hook_ENTITY_TYPE_view().
   */
  #[Hook('plan_view')]
  public function planView(array &$build, EntityInterface $plan, EntityViewDisplayInterface $display, $view_mode) {
    /** @var \Drupal\plan\Entity\PlanInterface $plan */

    // If this is not a grazing plan in full view mode, bail.
    if (!($plan->bundle() == 'grazing' && $view_mode == 'full')) {
      return;
    }

    // If there are no grazing events, bail.
    if (empty($this->grazingPlan->getGrazingEvents($plan))) {
      return;
    }

    // Render the grazing plan timeline.
    $build['grazing_plan_timeline'] = $this->formBuilder->getForm(GrazingPlanTimelineForm::class, $plan);
  }

  /**
   * Implements hook_farm_ui_theme_region_items().
   */
  #[Hook('farm_ui_theme_region_items')]
  public function farmUiThemeRegionItems(string $entity_type) {

    // Position the grazing plan timeline in the top region.
    if ($entity_type == 'plan') {
      return [
        'top' => [
          'grazing_plan_timeline',
        ],
      ];
    }
    return [];
  }

}
