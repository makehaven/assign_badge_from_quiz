<?php

namespace Drupal\assign_badge_from_quiz\Plugin\WebformHandler;

use Drupal\Core\Form\FormStateInterface;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;
use Drupal\webform\Plugin\WebformHandlerBase;
use Drupal\webform\Plugin\WebformHandlerInterface;
use Drupal\webform\WebformSubmissionInterface;

/**
 * Logs every save and retro-creates badge_request on approval.
 *
 * @WebformHandler(
 *   id = "documentation_approval_handler",
 *   label = @Translation("Documentation Approval (logging + retro-create)"),
 *   category = @Translation("Makerspace"),
 *   description = @Translation("Logs every save and creates the matching badge_request when status flips to approved and the user has already passed the quiz."),
 *   cardinality = \Drupal\webform\Plugin\WebformHandlerInterface::CARDINALITY_SINGLE,
 *   results = \Drupal\webform\Plugin\WebformHandlerInterface::RESULTS_PROCESSED,
 * )
 */
class DocumentationApprovalHandler extends WebformHandlerBase {

  public function postSave(WebformSubmissionInterface $webform_submission, $update = TRUE) {
    $logger = \Drupal::logger('assign_badge_from_quiz');
    $data = $webform_submission->getData();
    $status = isset($data['status']) && is_scalar($data['status'])
      ? strtolower(trim((string) $data['status']))
      : '';

    $logger->notice('Docs webform save: webform=@wf sid=@sid submitter_uid=@suid current_uid=@cu update=@u status=@status data_keys=@keys', [
      '@wf' => $webform_submission->getWebform()->id(),
      '@sid' => $webform_submission->id(),
      '@suid' => $webform_submission->getOwnerId(),
      '@cu' => \Drupal::currentUser()->id(),
      '@u' => $update ? '1' : '0',
      '@status' => $status === '' ? '(empty)' : $status,
      '@keys' => implode(',', array_keys($data)),
    ]);

    if ($status !== 'approved') {
      return;
    }

    $member_uid = (int) $webform_submission->getOwnerId();
    if ($member_uid <= 0) {
      return;
    }

    $webform_id = $webform_submission->getWebform()->id();
    $badge_terms = $this->loadBadgeTermsReferencingWebform($webform_id);
    if (!$badge_terms) {
      $logger->warning('Docs approved on webform @wf (sid @sid) but no badge term references it.', [
        '@wf' => $webform_id,
        '@sid' => $webform_submission->id(),
      ]);
      return;
    }

    foreach ($badge_terms as $badge_term) {
      $this->maybeCreateBadgeRequest($member_uid, $badge_term);
    }
  }

  /**
   * Find badge terms whose field_training_documentation points at this webform.
   */
  private function loadBadgeTermsReferencingWebform(string $webform_id): array {
    $node_storage = \Drupal::entityTypeManager()->getStorage('node');
    $nids = $node_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'webform')
      ->condition('webform.target_id', $webform_id)
      ->execute();
    if (!$nids) {
      return [];
    }
    $term_storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
    $tids = $term_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('vid', 'badges')
      ->condition('field_training_documentation', $nids, 'IN')
      ->execute();
    if (!$tids) {
      return [];
    }
    return $term_storage->loadMultiple($tids);
  }

  /**
   * Create a pending badge_request if the user has passed the quiz and none exists.
   */
  private function maybeCreateBadgeRequest(int $member_uid, TermInterface $badge_term): void {
    $logger = \Drupal::logger('assign_badge_from_quiz');

    if (!\Drupal::hasService('appointment_facilitator.badge_gate')) {
      return;
    }
    $gate = \Drupal::service('appointment_facilitator.badge_gate');
    $gate_result = $gate->evaluate($member_uid, $badge_term);
    if (!$gate_result['allowed']) {
      $logger->notice('Retro-create skipped for uid @uid badge @badge: gate not allowed. Reasons: @reasons', [
        '@uid' => $member_uid,
        '@badge' => $badge_term->getName(),
        '@reasons' => implode(' ', $gate_result['reasons']),
      ]);
      return;
    }

    $quiz_id = $badge_term->hasField('field_badge_quiz_reference')
      ? (int) ($badge_term->get('field_badge_quiz_reference')->target_id ?? 0)
      : 0;
    if ($quiz_id <= 0) {
      $logger->notice('Retro-create skipped for badge @badge: no quiz reference on term.', [
        '@badge' => $badge_term->getName(),
      ]);
      return;
    }

    if (!$this->userHasPassedQuiz($member_uid, $quiz_id)) {
      $logger->info('Retro-create deferred for uid @uid badge @badge: quiz @qid not yet passed; quiz hook will create on pass.', [
        '@uid' => $member_uid,
        '@badge' => $badge_term->getName(),
        '@qid' => $quiz_id,
      ]);
      return;
    }

    if ($this->loadExistingBadgeRequest($member_uid, (int) $badge_term->id())) {
      return;
    }

    $checkout_requirement = $badge_term->hasField('field_badge_checkout_requirement')
      ? $badge_term->get('field_badge_checkout_requirement')->value
      : 'no';
    $status = ($checkout_requirement === 'yes' || $checkout_requirement === 'class') ? 'pending' : 'active';

    $badge_request = Node::create([
      'type' => 'badge_request',
      'title' => 'Badge Request for ' . $badge_term->getName() . ' by User ' . $member_uid,
      'field_badge_requested' => ['target_id' => $badge_term->id()],
      'field_badge_status' => $status,
      'field_member_to_badge' => ['target_id' => $member_uid],
    ]);
    $badge_request->save();

    $logger->notice('Retro-created badge_request @nid (status @status) for uid @uid badge @badge after documentation approval.', [
      '@nid' => $badge_request->id(),
      '@status' => $status,
      '@uid' => $member_uid,
      '@badge' => $badge_term->getName(),
    ]);
  }

  private function userHasPassedQuiz(int $member_uid, int $quiz_id): bool {
    $storage = \Drupal::entityTypeManager()->getStorage('quiz_result');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $member_uid)
      ->condition('qid', $quiz_id)
      ->condition('score', 100)
      ->range(0, 1)
      ->execute();
    return !empty($ids);
  }

  private function loadExistingBadgeRequest(int $member_uid, int $badge_tid): ?NodeInterface {
    $nids = \Drupal::entityQuery('node')
      ->accessCheck(FALSE)
      ->condition('type', 'badge_request')
      ->condition('status', 1)
      ->condition('field_member_to_badge.target_id', $member_uid)
      ->condition('field_badge_requested.target_id', $badge_tid)
      ->condition('field_badge_status.value', ['duplicate', 'Rejected', 'rejected'], 'NOT IN')
      ->range(0, 1)
      ->execute();
    if (!$nids) {
      return NULL;
    }
    $node = Node::load((int) reset($nids));
    return $node instanceof NodeInterface ? $node : NULL;
  }

  public function settingsForm(array $form, FormStateInterface $form_state) {
    return [];
  }

}
