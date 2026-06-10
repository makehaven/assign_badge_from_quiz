<?php

namespace Drupal\assign_badge_from_quiz\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Url;
use Drupal\taxonomy\TermInterface;
use Drupal\webform\WebformSubmissionInterface;

/**
 * Builds and sends documentation-workflow emails.
 *
 * Two jobs:
 *  - The member-facing "your documentation was approved, here's the next step"
 *    email, sent automatically when a submission's status flips to approved.
 *    Tailored to whether the member has already passed the badge quiz.
 *  - The signed Approve / Reject action links embedded in the staff review
 *    email (built here, rendered by the email preprocess hook).
 */
class DocumentationNotifier {

  /**
   * KeyValue collection recording which submissions have been emailed.
   *
   * Lets a later re-save of an already-approved submission skip re-notifying.
   */
  protected const SENT_COLLECTION = 'assign_badge_from_quiz.approval_emailed';

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected MailManagerInterface $mailManager,
    protected LanguageManagerInterface $languageManager,
    protected KeyValueFactoryInterface $keyValueFactory,
    protected LoggerChannelFactoryInterface $loggerFactory,
    protected DocumentationActionTokenService $tokens,
  ) {}

  /**
   * Send the member the "approved, next step" email once per submission+badge.
   */
  public function sendApprovalEmailIfNeeded(int $uid, TermInterface $badge, WebformSubmissionInterface $submission): void {
    $logger = $this->loggerFactory->get('assign_badge_from_quiz');
    if ($uid <= 0) {
      return;
    }

    $store = $this->keyValueFactory->get(self::SENT_COLLECTION);
    $key = $submission->id() . ':' . $badge->id();
    if ($store->get($key)) {
      return;
    }

    $user = $this->entityTypeManager->getStorage('user')->load($uid);
    if (!$user || !$user->getEmail()) {
      $logger->warning('Approval email skipped: uid @uid has no email.', ['@uid' => $uid]);
      return;
    }

    $quiz_passed = $this->userHasPassedBadgeQuiz($uid, $badge);
    $quiz_url = $this->resolveQuizUrl($badge);
    // The personal badges page lives in appointment_facilitator; degrade
    // gracefully if that route is unavailable.
    try {
      $badges_url = Url::fromRoute('appointment_facilitator.member_badges', [], ['absolute' => TRUE])->toString();
    }
    catch (\Throwable) {
      $badges_url = '';
    }

    $params = [
      'tool' => $badge->getName(),
      'quiz_passed' => $quiz_passed,
      'quiz_url' => $quiz_url,
      'badges_url' => $badges_url,
      'recipient_name' => $user->getDisplayName(),
    ];

    $langcode = $user->getPreferredLangcode() ?: $this->languageManager->getDefaultLanguage()->getId();
    $result = $this->mailManager->mail('assign_badge_from_quiz', 'documentation_approved', $user->getEmail(), $langcode, $params);

    if (!empty($result['result'])) {
      $store->set($key, time());
      $logger->notice('Sent documentation-approved email to uid @uid for badge @badge (sid @sid).', [
        '@uid' => $uid,
        '@badge' => $badge->getName(),
        '@sid' => $submission->id(),
      ]);
    }
    else {
      $logger->error('Failed to send documentation-approved email to uid @uid for badge @badge (sid @sid).', [
        '@uid' => $uid,
        '@badge' => $badge->getName(),
        '@sid' => $submission->id(),
      ]);
    }
  }

  /**
   * Build the signed Approve / Reject action links for the staff email.
   *
   * @return array[]
   *   List of ['url' => string, 'label' => string, 'color' => string].
   */
  public function buildActionLinks(int $submission_id): array {
    $specs = [
      'approve' => ['Approve', '#28a745'],
      'reject' => ['Reject', '#dc3545'],
    ];
    $links = [];
    foreach ($specs as $action => [$label, $color]) {
      $token = $this->tokens->generate($submission_id, $action);
      $url = Url::fromRoute('assign_badge_from_quiz.doc_action', [
        'submission' => $submission_id,
        'action' => $action,
        'token' => $token,
      ], ['absolute' => TRUE])->toString();
      $links[] = ['url' => $url, 'label' => $label, 'color' => $color];
    }
    return $links;
  }

  /**
   * Whether the member has a 100% pass on this badge's quiz.
   */
  public function userHasPassedBadgeQuiz(int $uid, TermInterface $badge): bool {
    $qid = $badge->hasField('field_badge_quiz_reference') && !$badge->get('field_badge_quiz_reference')->isEmpty()
      ? (int) $badge->get('field_badge_quiz_reference')->target_id
      : 0;
    if ($qid <= 0) {
      return FALSE;
    }
    $ids = $this->entityTypeManager->getStorage('quiz_result')->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $uid)
      ->condition('qid', $qid)
      ->condition('score', 100)
      ->range(0, 1)
      ->execute();
    return !empty($ids);
  }

  /**
   * Canonical URL of the badge's quiz, or NULL when none is referenced.
   */
  public function resolveQuizUrl(TermInterface $badge): ?string {
    if (!$badge->hasField('field_badge_quiz_reference') || $badge->get('field_badge_quiz_reference')->isEmpty()) {
      return NULL;
    }
    $quiz = $badge->get('field_badge_quiz_reference')->entity;
    if (!$quiz) {
      return NULL;
    }
    try {
      return $quiz->toUrl('canonical', ['absolute' => TRUE])->toString();
    }
    catch (\Throwable) {
      return NULL;
    }
  }

}
