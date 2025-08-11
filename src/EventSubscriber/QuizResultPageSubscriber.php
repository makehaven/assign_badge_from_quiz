<?php

namespace Drupal\assign_badge_from_quiz\EventSubscriber;

use Drupal\assign_badge_from_quiz\QuizResultDisplayBuilder;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ViewEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\quiz\Entity\QuizResult;
// REMOVE this use statement: use Drupal\Core\Render\RendererInterface;

/**
 * Subscriber to alter the quiz result page.
 */
class QuizResultPageSubscriber implements EventSubscriberInterface {

  protected $routeMatch;
  protected $displayBuilder;
  // REMOVE the renderer property.

  /**
   * Constructs a new QuizResultPageSubscriber.
   * REMOVE the RendererInterface from the constructor arguments.
   */
  public function __construct(RouteMatchInterface $route_match, QuizResultDisplayBuilder $display_builder) {
    $this->routeMatch = $route_match;
    $this->displayBuilder = $display_builder;
    // REMOVE the line that sets the renderer property.
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events[KernelEvents::VIEW][] = ['onView', 100];
    return $events;
  }

  /**
   * Alters the controller output on quiz result pages.
   */
  public function onView(ViewEvent $event) {
    $route_name = $this->routeMatch->getRouteName();

    if ($route_name === 'entity.quiz_result.canonical') {
      $controller_result = $event->getControllerResult();
      $quiz_result = $this->routeMatch->getParameter('quiz_result');

      if ($quiz_result instanceof QuizResult) {
        $custom_display = $this->displayBuilder->build($quiz_result);

        // Don't render here. Instead, just add our render array
        // as a new element to the main controller's render array.
        // Drupal will render it at the correct time.
        if (is_array($controller_result)) {
            $controller_result['assign_badge_results'] = $custom_display;
            $event->setControllerResult($controller_result);
        }
      }
    }
  }
}