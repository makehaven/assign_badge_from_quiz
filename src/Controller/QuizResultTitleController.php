<?php

declare(strict_types=1);

namespace Drupal\assign_badge_from_quiz\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\quiz\Entity\QuizResult;

/**
 * Title callback for /quiz/{quiz}/result/{quiz_result} (see RouteSubscriber).
 */
class QuizResultTitleController extends ControllerBase {

  /**
   * Titles the page "You passed: Laser Cutter Quiz", or whose it is for staff.
   *
   * A pass is a 100% score, the same rule that requests the badge
   * (QuizResultHook) and picks the success message (QuizResultPageSubscriber).
   */
  public function title(QuizResult $quiz_result): string {
    $quiz = $quiz_result->getQuiz()->label();
    if ((int) $quiz_result->getOwnerId() !== (int) $this->currentUser()->id()) {
      return (string) $this->t('@name: @quiz result', [
        '@name' => $quiz_result->getOwner()->getDisplayName(),
        '@quiz' => $quiz,
      ]);
    }
    if (!$quiz_result->isEvaluated()) {
      return (string) $this->t('Submitted: @quiz', ['@quiz' => $quiz]);
    }
    if ((int) $quiz_result->get('score')->value === 100) {
      return (string) $this->t('You passed: @quiz', ['@quiz' => $quiz]);
    }
    return (string) $this->t('Not passed yet: @quiz', ['@quiz' => $quiz]);
  }

}
