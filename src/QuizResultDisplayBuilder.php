<?php

namespace Drupal\assign_badge_from_quiz;

use Drupal\Core\Render\Markup;
use Drupal\Core\Url;
use Drupal\quiz\Entity\QuizResult;
use Drupal\taxonomy\Entity\Term;
use Drupal\views\Views;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

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
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

/**
   * Constructs a new QuizResultDisplayBuilder object.
   *
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(LoggerChannelFactoryInterface $logger_factory, ConfigFactoryInterface $config_factory) {
    $this->logger = $logger_factory->get('assign_badge_from_quiz');
    $this->configFactory = $config_factory;
  }




  public function buildFacilitatorSchedule(Term $badge_term): array {
    $config = $this->configFactory->get('assign_badge_from_quiz.settings');
    $view_and_display = $config->get('facilitator_schedule_view') ?: 'facilitator_schedules:facilitator_schedule_tool_eva';
    $parts = explode(':', $view_and_display);
    if (count($parts) !== 2) {
      $this->logger->warning('The facilitator schedule view setting is invalid. It should be in the format view_name:display_name.');
      return [];
    }
    $view_name = $parts[0];
    $display_name = $parts[1];

    $view = Views::getView($view_name);
    if (!$view) {
      $this->logger->warning('The facilitator schedule view "@view" could not be loaded.', ['@view' => $view_name]);
      return [];
    }

    if (!$view->setDisplay($display_name)) {
        $this->logger->warning('The display "@display" could not be found on view "@view".', ['@display' => $display_name, '@view' => $view_name]);
        return [];
    }
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