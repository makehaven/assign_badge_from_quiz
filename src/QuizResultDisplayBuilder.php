<?php

namespace Drupal\assign_badge_from_quiz;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for building the custom display for quiz results.
 *
 * @deprecated This service is deprecated and its functionality has been moved
 *   to QuizResultPageSubscriber. It is kept to avoid breaking the service
 *   container definition but should be removed in a future update.
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
   *   The logger factory.
   */
  public function __construct(LoggerChannelFactoryInterface $logger_factory) {
    $this->logger = $logger_factory->get('assign_badge_from_quiz');
  }

}