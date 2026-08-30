<?php

declare(strict_types=1);

namespace Drupal\farm_grazing_plan\Hook;

use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;

/**
 * Help hook implementations for farm_grazing_plan.
 */
class HelpHooks {

  use AutowireTrait;
  use StringTranslationTrait;

  public function __construct(
    protected ModuleHandlerInterface $moduleHandler,
  ) {}

  /**
   * Implements hook_help().
   */
  #[Hook('help')]
  public function help($route_name, RouteMatchInterface $route_match) {
    $output = '';

    // Add help text to the "Add grazing event" form.
    if ($route_name == 'farm_grazing_plan.add_event') {
      $output .= '<p>' . $this->t('Use this form to add a new "grazing event" to the plan. Grazing events represent the period of time that asset(s) are in a location. Most information (the asset, location, <em>actual</em> start date, etc.) is stored in a linked movement log. The <em>planned</em> start date, duration, and recovery times are specific to this plan.') . '</p>';

      // If the Movement quick form module is installed, add a link to it.
      if ($this->moduleHandler->moduleExists('farm_quick_movement')) {
        $quick_movement_url = Url::fromRoute('farm.quick.movement', ['plan' => $route_match->getParameter('plan')->id()])->toString();
        $output .= '<p>' . $this->t('Tip: Use the <a href=":url">Movement quick form</a> to create a movement log. You will be redirected back here to fill in more details for the plan.', [':url' => $quick_movement_url]) . '</p>';
      }
    }

    return $output;
  }

}
