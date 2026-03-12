<?php

namespace Drupal\assign_badge_from_quiz\Hook;

use Drupal\Core\Entity\EntityInterface;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;

/**
 * Class for handling quiz result updates.
 */
class QuizResultHook {

  public function entityUpdate(EntityInterface $entity) {
    if ($entity instanceof \Drupal\quiz\Entity\QuizResult) {
      // Retrieve relevant fields.
      $quiz_id = $entity->get('qid')->target_id;
      $score = $entity->get('score')->value;
      $user_id = $entity->get('uid')->target_id;

      // --- REMOVED MESSENGER NOTIFICATION ---
      // \Drupal::messenger()->addMessage("Your quiz score: " . $score . "%", "status");

      // Process badge request if the score is a perfect 100.
      if ($score == 100) {
        // Load the badge taxonomy terms to find the one associated with this quiz.
        $terms = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadByProperties([
          'vid' => 'badges',
          'field_badge_quiz_reference' => $quiz_id,
        ]);

        if (!empty($terms)) {
          $badge_term = reset($terms);
          $badge_name = $badge_term->getName();

          // Gate progression when documentation/prerequisites are configured.
          if (\Drupal::hasService('appointment_facilitator.badge_gate')) {
            /** @var \Drupal\appointment_facilitator\Service\BadgePrerequisiteGate $gate */
            $gate = \Drupal::service('appointment_facilitator.badge_gate');
            $gate_result = $gate->evaluate((int) $user_id, $badge_term);
            if (!$gate_result['allowed']) {
              \Drupal::logger('assign_badge_from_quiz')->notice(
                'Blocked badge request for uid @uid badge @badge (tid @tid). Reasons: @reasons',
                [
                  '@uid' => $user_id,
                  '@badge' => $badge_name,
                  '@tid' => $badge_term->id(),
                  '@reasons' => implode(' ', $gate_result['reasons']),
                ]
              );
              $messages = [];
              $messages[] = t('You passed the quiz, but you cannot receive the @badge badge yet.', [
                '@badge' => $badge_name,
              ]);
              if (!empty($gate_result['requires_documentation']) && empty($gate_result['documentation_approved'])) {
                if (!empty($gate_result['documentation_form_url'])) {
                  $messages[] = t('Next step: submit and get approval for your documentation form: @url', [
                    '@url' => $gate_result['documentation_form_url'],
                  ]);
                }
                else {
                  $messages[] = t('Next step: submit and get approval for the required training documentation.');
                }
              }
              if (!empty($gate_result['prerequisites_missing_labels'])) {
                $messages[] = t('Prerequisite badges still required (must be active): @badges', [
                  '@badges' => implode(', ', $gate_result['prerequisites_missing_labels']),
                ]);
              }
              \Drupal::messenger()->addWarning(implode(' ', $messages));
              return;
            }
          }

          // Determine checkout requirements.
          $checkout_requirement = $badge_term->hasField('field_badge_checkout_requirement') ? $badge_term->get('field_badge_checkout_requirement')->value : 'no';
          $status = ($checkout_requirement == 'yes' || $checkout_requirement == 'class') ? 'pending' : 'active';

          $existing_request = $this->loadExistingBadgeRequest((int) $user_id, (int) $badge_term->id());
          if ($existing_request instanceof NodeInterface) {
            $existing_status = trim((string) ($existing_request->get('field_badge_status')->value ?? ''));

            if ($existing_status === 'expired') {
              $existing_request->set('field_badge_status', 'active');
              $existing_request->save();
              \Drupal::logger('assign_badge_from_quiz')->notice(
                'Reactivated expired badge request @nid for uid @uid badge @badge (tid @tid) after quiz retake.',
                [
                  '@nid' => $existing_request->id(),
                  '@uid' => $user_id,
                  '@badge' => $badge_name,
                  '@tid' => $badge_term->id(),
                ]
              );
              \Drupal::messenger()->addStatus(t('Your expired @badge badge has been reactivated.', [
                '@badge' => $badge_name,
              ]));
              return;
            }

            if ($existing_status === 'active') {
              \Drupal::messenger()->addStatus(t('You already have the @badge badge. Your quiz retake counted as a refresher.', [
                '@badge' => $badge_name,
              ]));
              return;
            }

            if ($existing_status === 'pending') {
              \Drupal::messenger()->addStatus(t('You already have a pending @badge badge request. No new request was created.', [
                '@badge' => $badge_name,
              ]));
              return;
            }

            \Drupal::logger('assign_badge_from_quiz')->notice(
              'Skipped duplicate badge request creation for uid @uid badge @badge (tid @tid) because an existing request @nid is in status @status.',
              [
                '@uid' => $user_id,
                '@badge' => $badge_name,
                '@tid' => $badge_term->id(),
                '@nid' => $existing_request->id(),
                '@status' => $existing_status ?: 'unknown',
              ]
            );
            \Drupal::messenger()->addWarning(t('You already have a @badge badge record with status "@status". No new request was created.', [
              '@badge' => $badge_name,
              '@status' => $existing_status ?: 'unknown',
            ]));
            return;
          }

          // Create a badge_request node.
          $badge_request = Node::create([
            'type' => 'badge_request',
            'title' => "Badge Request for $badge_name by User $user_id",
            'field_badge_requested' => ['target_id' => $badge_term->id()],
            'field_badge_status' => $status,
            'field_member_to_badge' => ['target_id' => $user_id],
          ]);
          $badge_request->save();

          // --- REMOVED MESSENGER NOTIFICATION ---
          // \Drupal::messenger()->addMessage("Badge request created for $badge_name", "status");

          // The special case for the orientation quiz can remain if needed,
          // but consider making this a configurable field on the badge itself.
          if ($quiz_id == 1) {
              $message = "<a href='/schedule' class='btn btn-primary btn-lg' style='font-size: 1.5rem; padding: 15px 30px; border-radius: 10px;'>Schedule Your Orientation Session</a>";
              \Drupal::messenger()->addMessage($message, "status");
          }
        }
      }
    }
  }

  /**
   * Loads the canonical existing badge request for a user and badge.
   */
  private function loadExistingBadgeRequest(int $user_id, int $badge_tid): ?NodeInterface {
    $nids = \Drupal::entityQuery('node')
      ->accessCheck(FALSE)
      ->condition('type', 'badge_request')
      ->condition('status', 1)
      ->condition('field_member_to_badge.target_id', $user_id)
      ->condition('field_badge_requested.target_id', $badge_tid)
      ->condition('field_badge_status.value', ['duplicate', 'Rejected', 'rejected'], 'NOT IN')
      ->sort('changed', 'DESC')
      ->sort('nid', 'DESC')
      ->range(0, 1)
      ->execute();

    if (!$nids) {
      return NULL;
    }

    $node = Node::load((int) reset($nids));
    return $node instanceof NodeInterface ? $node : NULL;
  }
}
