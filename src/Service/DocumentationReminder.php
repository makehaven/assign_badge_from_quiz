<?php

namespace Drupal\assign_badge_from_quiz\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\State\StateInterface;

/**
 * Periodic "still waiting" digest for documentation approvals.
 *
 * The one-shot notification email is sent once, when a submission arrives. If
 * it is missed, nothing re-surfaces it. This digest is the safety net: on a
 * throttled interval (default weekly) it emails staff the full list of
 * submissions still awaiting approval — with the same signed Approve / Reject
 * links — until the queue is empty.
 */
class DocumentationReminder {

  /**
   * State key holding the last-sent timestamp.
   */
  protected const LAST_SENT = 'assign_badge_from_quiz.pending_digest_last_sent';

  public function __construct(
    protected PendingDocumentationFinder $finder,
    protected DocumentationNotifier $notifier,
    protected ConfigFactoryInterface $configFactory,
    protected StateInterface $state,
    protected MailManagerInterface $mailManager,
    protected LanguageManagerInterface $languageManager,
    protected DateFormatterInterface $dateFormatter,
    protected LoggerChannelFactoryInterface $loggerFactory,
    protected TimeInterface $time,
  ) {}

  /**
   * Send the digest if the throttle interval has elapsed. Called from cron.
   */
  public function maybeSendDigest(): void {
    $cfg = $this->configFactory->get('assign_badge_from_quiz.settings');
    $interval_days = (int) ($cfg->get('reminder_interval_days') ?? 1);
    // 0 (or negative) disables the digest entirely.
    if ($interval_days <= 0) {
      return;
    }

    $now = $this->time->getRequestTime();
    $last = (int) $this->state->get(self::LAST_SENT, 0);
    if ($now - $last < $interval_days * 86400) {
      return;
    }

    $logger = $this->loggerFactory->get('assign_badge_from_quiz');
    $pending = $this->finder->findPending();
    if (!$pending) {
      // Nothing waiting — mark the window consumed so we wait a full interval
      // before checking again, and don't email an empty list.
      $this->state->set(self::LAST_SENT, $now);
      return;
    }

    $recipient = $cfg->get('reminder_recipient')
      ?: ($cfg->get('notification_recipient') ?: 'jrlogan@makehaven.org');

    // Build plain-text lines plus the signed action links per submission.
    $items = [];
    foreach ($pending as $row) {
      $age = $this->dateFormatter->formatTimeDiffSince($row['created']);
      $links = $this->notifier->buildActionLinks($row['sid']);
      $items[] = [
        'badge' => $row['badge_name'],
        'member' => $row['member_name'],
        'email' => $row['member_email'],
        'age' => (string) $age,
        'submission_url' => $row['submission_url'],
        'action_links' => $links,
      ];
    }

    $params = [
      'count' => count($pending),
      'items' => $items,
    ];
    $langcode = $this->languageManager->getDefaultLanguage()->getId();
    $result = $this->mailManager->mail('assign_badge_from_quiz', 'documentation_pending_digest', $recipient, $langcode, $params);

    if (!empty($result['result'])) {
      $this->state->set(self::LAST_SENT, $now);
      $logger->notice('Sent pending-documentation digest (@count waiting) to @to.', [
        '@count' => count($pending),
        '@to' => $recipient,
      ]);
    }
    else {
      // Leave LAST_SENT untouched so the next cron retries.
      $logger->error('Failed to send pending-documentation digest to @to.', ['@to' => $recipient]);
    }
  }

}
