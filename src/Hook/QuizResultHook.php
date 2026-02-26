<?php

namespace Drupal\assign_badge_from_quiz\Hook;

use Drupal\Core\Entity\EntityInterface;
use Drupal\node\Entity\Node;

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
}
