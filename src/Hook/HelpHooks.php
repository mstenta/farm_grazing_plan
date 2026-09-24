<?php

declare(strict_types=1);

namespace Drupal\farm_grazing_plan\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Help hook implementations for farm_grazing_plan.
 */
class HelpHooks {

  use StringTranslationTrait;

  /**
   * Implements hook_help().
   */
  #[Hook('help')]
  public function help($route_name, RouteMatchInterface $route_match) {
    $output = '';

    // Add help text to the "Add grazing event" form.
    if ($route_name == 'farm_grazing_plan.add_event') {
      $output .= '<p>' . $this->t('Use this form to add a new "grazing event" to the plan. Grazing events represent the period of time that asset(s) are in a location. Most information (the asset, location, <em>actual</em> start date, etc.) is stored in a linked movement log. The <em>planned</em> start date, duration, and recovery times are specific to this plan.') . '</p>';
    }

    return $output;
  }

}
