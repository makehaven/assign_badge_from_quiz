<?php

namespace Drupal\assign_badge_from_quiz;

use Drupal\Core\Render\Markup;
use Drupal\Core\Url;
use Drupal\quiz\Entity\QuizResult;
use Drupal\taxonomy\Entity\Term;
use Drupal\views\Views;
use Psr\Log\LoggerInterface;

/**
 * Service for building the custom display for quiz results.
 */
class QuizResultDisplayBuilder {


/**
   * The logger channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

/**
   * Constructs a new QuizResultDisplayBuilder object.
   *
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   * The logger factory.
   */
  public function __construct(\Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory) {
    $this->logger = $logger_factory->get('assign_badge_from_quiz');
  }




  public function buildFacilitatorSchedule(Term $badge_term): array {
    $view = Views::getView('facilitator_schedules');
    if (!$view) {
      $this->logger->warning('The "facilitator_schedules" view could not be loaded.');
      return [];
    }
    $view->setDisplay('facilitator_schedule_tool_eva');
    $view->setArguments([$badge_term->id()]);
    $view->execute();
    if (count($view->result) > 0) {
      return $view->buildRenderable();
    }
    else {
      $message = '<div class="alert alert-warning"><h4>No Facilitators Currently Available</h4><p>There are no facilitators scheduled for this checkout in the near future. Please check back later or ask for assistance in the relevant Slack channel.</p></div>';
      return ['#markup' => Markup::create($message)];
    }
  }


}