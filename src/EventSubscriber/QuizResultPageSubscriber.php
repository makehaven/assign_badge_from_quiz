<?php

namespace Drupal\assign_badge_from_quiz\EventSubscriber;

use Drupal\assign_badge_from_quiz\Service\PostQuizRenderer;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\quiz\Entity\QuizResult;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ViewEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Subscriber to alter the quiz result page.
 */
final class QuizResultPageSubscriber implements EventSubscriberInterface {

  private readonly LoggerChannelInterface $logger;

  public function __construct(
    private readonly RouteMatchInterface $routeMatch,
    private readonly PostQuizRenderer $postQuizRenderer,
    private readonly AccountProxyInterface $currentUser,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get('assign_badge_from_quiz');
  }

  public static function getSubscribedEvents(): array {
    return [KernelEvents::VIEW => ['onView', 100]];
  }

  public function onView(ViewEvent $event): void {
    if ($this->routeMatch->getRouteName() !== 'entity.quiz_result.canonical') {
      return;
    }

    $quiz_result = $this->routeMatch->getParameter('quiz_result');
    if (!($quiz_result instanceof QuizResult)) {
      return;
    }

    // The custom message should only appear for passing scores.
    if ((int) $quiz_result->get('score')->value !== 100) {
      return;
    }

    $controller_result = $event->getControllerResult();
    if (!is_array($controller_result)) {
      return;
    }

    $quiz = $quiz_result->getQuiz();
    $quiz_nid = $quiz->id();
    $quiz_type = $quiz->bundle();

    // Logic to find the related badge term, adapted from QuizResultDisplayBuilder.
    $term_ids = $this->entityTypeManager->getStorage('taxonomy_term')->getQuery()
      ->condition('vid', 'badges')
      ->condition('field_badge_quiz_reference', $quiz_nid)
      ->accessCheck(FALSE)
      ->range(0, 1)
      ->execute();

    $has_related_term = !empty($term_ids);
    $badge_term = null;
    if ($has_related_term) {
      $badge_term = $this->entityTypeManager->getStorage('taxonomy_term')->load(reset($term_ids));
    }

    $user_account = $this->entityTypeManager->getStorage('user')->load($this->currentUser->id());

    $context = [
      'quiz_nid' => $quiz_nid,
      'quiz_title' => $quiz->label(),
      'quiz_type' => $quiz_type,
      'has_related_term' => $has_related_term,
      'user' => [
        'uid' => $this->currentUser->id(),
        'display_name' => $user_account->getDisplayName(),
      ],
      'badge' => $badge_term ? [
        'nid' => $badge_term->id(),
        'title' => $badge_term->label(),
      ] : null,
      'base_url' => Url::fromRoute('<front>', [], ['absolute' => TRUE])->toString(),
    ];

    if ($custom_display = $this->postQuizRenderer->build($context)) {
      $controller_result['assign_badge_post_quiz_message'] = $custom_display;
      $event->setControllerResult($controller_result);
    }
  }
}