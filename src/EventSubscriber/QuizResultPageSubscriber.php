<?php

namespace Drupal\assign_badge_from_quiz\EventSubscriber;

use Drupal\assign_badge_from_quiz\QuizResultDisplayBuilder;
use Drupal\assign_badge_from_quiz\Service\PostQuizRenderer;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\quiz\Entity\QuizResult;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ViewEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Subscriber to alter the quiz result page.
 */
final class QuizResultPageSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly RouteMatchInterface $routeMatch,
    private readonly PostQuizRenderer $postQuizRenderer,
    private readonly QuizResultDisplayBuilder $displayBuilder,
    private readonly AccountProxyInterface $currentUser,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

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

    $controller_result = $event->getControllerResult();
    if ($controller_result instanceof Response || !is_array($controller_result)) {
      return;
    }

    $quiz = $quiz_result->getQuiz();
    $quiz_nid = $quiz->id();
    $quiz_type = $quiz->bundle();
    $score = (int) $quiz_result->get('score')->value;

    // Logic to find the related badge term.
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

    $badge_data = null;
    if ($badge_term) {
        $checklist_url = '';
        if ($badge_term->hasField('field_badge_checklist') && !$badge_term->get('field_badge_checklist')->isEmpty()) {
            $checklist_url = $badge_term->get('field_badge_checklist')->first()->getUrl()->toString();
        }

        $badge_data = [
            'nid' => $badge_term->id(),
            'title' => $badge_term->label(),
            'url' => Url::fromRoute('entity.taxonomy_term.canonical', ['taxonomy_term' => $badge_term->id()])->toString(),
            'checklist_url' => $checklist_url,
            'has_checklist' => !empty($checklist_url),
            'checkout_minutes' => '',
        ];

        if ($badge_term->hasField('field_badge_checkout_minutes') && !$badge_term->get('field_badge_checkout_minutes')->isEmpty()) {
            $badge_data['checkout_minutes'] = $badge_term->get('field_badge_checkout_minutes')->value;
        }
        $badge_data['checkout_requirement'] = $badge_term->hasField('field_badge_checkout_requirement') ? $badge_term->get('field_badge_checkout_requirement')->value : 'no';
    }

    $context = [
      'quiz_nid' => $quiz_nid,
      'quiz_title' => $quiz->label(),
      'quiz_type' => $quiz_type,
      'has_related_term' => $has_related_term,
      'user' => [
        'uid' => $this->currentUser->id(),
        'display_name' => $user_account->getDisplayName(),
      ],
      'badge' => $badge_data,
      'base_url' => Url::fromRoute('<front>', [], ['absolute' => TRUE])->toString(),
    ];

    if ($score === 100) {
        // Add the custom success message.
        if ($custom_display = $this->postQuizRenderer->build($context)) {
            $controller_result['assign_badge_post_quiz_message'] = $custom_display;
        }

        // Add the detailed badge display if enabled.
        $config = $this->configFactory->get('assign_badge_from_quiz.settings');
        $show_details_for_types = $config->get('show_badge_details') ?: [];
        if ($badge_term && in_array($quiz_type, $show_details_for_types, TRUE)) {
            if (isset($context['badge']['checkout_requirement']) && $context['badge']['checkout_requirement'] !== 'no') {
                $controller_result['assign_badge_facilitator_schedule'] = $this->displayBuilder->buildFacilitatorSchedule($badge_term);
            }
        }
    }
    else {
        // Add the custom failure message.
        if ($custom_display = $this->postQuizRenderer->buildFailure($context)) {
            $controller_result['assign_badge_post_quiz_failure_message'] = $custom_display;
        }
    }

    $event->setControllerResult($controller_result);
  }
}