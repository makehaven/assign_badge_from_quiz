<?php

declare(strict_types=1);

namespace Drupal\assign_badge_from_quiz\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Gives the quiz result page a heading that says what happened.
 *
 * Contrib titles it from QuizResult::label(): "Jane Doe's Quiz result in
 * "New Member Quiz"", which members found meaningless (staff feedback
 * 2026-07).
 */
class RouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection): void {
    if ($route = $collection->get('entity.quiz_result.canonical')) {
      $route->setDefault('_title_callback', '\Drupal\assign_badge_from_quiz\Controller\QuizResultTitleController::title');
    }
  }

}
