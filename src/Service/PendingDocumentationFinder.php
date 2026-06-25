<?php

namespace Drupal\assign_badge_from_quiz\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;

/**
 * Finds documentation-badge submissions still awaiting staff approval.
 *
 * "Pending" = a submission on one of the documentation webforms (any badges
 * term's field_training_documentation form) that genuinely still needs staff
 * action. Two filters define that:
 *  - the status element has NOT been set to approved or rejected (a brand-new,
 *    never-reviewed submission has no status value at all, so it qualifies), AND
 *  - the submitter does NOT already hold the badge.
 *
 * The second filter matters because the status field was historically almost
 * never set — badges were granted at in-person checkout without flipping the
 * form to approved. Counting raw "no status" submissions would surface a large
 * backlog of members who already earned the badge out-of-band. Cross-referencing
 * actual badge_request holdings (the reliable signal) leaves only the members
 * who submitted docs and are genuinely waiting.
 *
 * Shared by the staff queue page, the dashboard tile, and the reminder digest
 * so all three agree on what "still waiting" means.
 */
class PendingDocumentationFinder {

  public function __construct(
    protected Connection $database,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Map of documentation webform id => badge term name.
   *
   * @return string[]
   *   Keyed by webform machine id.
   */
  public function getDocumentationWebforms(): array {
    return array_map(fn($info) => $info['name'], $this->getDocumentationBadgeMap());
  }

  /**
   * Map of documentation webform id => ['tid' => int, 'name' => string].
   *
   * The badge term id is needed to check whether the submitter already holds
   * the badge.
   *
   * @return array[]
   *   Keyed by webform machine id.
   */
  protected function getDocumentationBadgeMap(): array {
    $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $tids = $term_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('vid', 'badges')
      ->exists('field_training_documentation')
      ->execute();
    if (!$tids) {
      return [];
    }
    $map = [];
    foreach ($term_storage->loadMultiple($tids) as $term) {
      $node = $term->get('field_training_documentation')->entity;
      if (!$node || !$node->hasField('webform') || $node->get('webform')->isEmpty()) {
        continue;
      }
      $webform_id = $node->get('webform')->target_id;
      if ($webform_id) {
        $map[$webform_id] = ['tid' => (int) $term->id(), 'name' => $term->getName()];
      }
    }
    return $map;
  }

  /**
   * All pending documentation submissions, oldest first.
   *
   * @return array[]
   *   Each row: sid, webform_id, badge_name, uid, member_name, member_email,
   *   created (timestamp), submission_url.
   */
  public function findPending(): array {
    $map = $this->getDocumentationBadgeMap();
    if (!$map) {
      return [];
    }
    $user_storage = $this->entityTypeManager->getStorage('user');
    $rows = [];
    foreach ($map as $webform_id => $info) {
      $badge_name = $info['name'];
      $badge_tid = $info['tid'];
      // Submissions a staffer has already approved or rejected are resolved.
      $resolved = $this->database->select('webform_submission_data', 'd')
        ->fields('d', ['sid']);
      $resolved->condition('d.webform_id', $webform_id);
      $resolved->condition('d.name', 'status');
      $resolved->condition('d.value', ['approved', 'rejected'], 'IN');

      $query = $this->database->select('webform_submission', 's')
        ->fields('s', ['sid', 'uid', 'created']);
      $query->condition('s.webform_id', $webform_id);
      $query->condition('s.sid', $resolved, 'NOT IN');
      $query->orderBy('s.created', 'ASC');

      foreach ($query->execute() as $record) {
        $uid = (int) $record->uid;
        // Skip members who already hold the badge — the docs step succeeded
        // out-of-band (typically granted at in-person checkout). They need no
        // approval action, so they are not "waiting".
        if ($uid > 0 && $this->memberHoldsBadge($uid, $badge_tid)) {
          continue;
        }
        $user = $uid > 0 ? $user_storage->load($uid) : NULL;
        $rows[] = [
          'sid' => (int) $record->sid,
          'webform_id' => $webform_id,
          'badge_name' => $badge_name,
          'uid' => $uid,
          'member_name' => $user ? $user->getDisplayName() : '(anonymous)',
          'member_email' => $user ? ($user->getEmail() ?? '') : '',
          'created' => (int) $record->created,
          'submission_url' => $this->submissionUrl($webform_id, (int) $record->sid),
        ];
      }
    }
    // Oldest across all forms first — those are the ones most at risk.
    usort($rows, fn($a, $b) => $a['created'] <=> $b['created']);
    return $rows;
  }

  /**
   * Count of pending documentation submissions.
   */
  public function countPending(): int {
    return count($this->findPending());
  }

  /**
   * Whether the member already holds (a non-rejected request for) the badge.
   *
   * Mirrors DocumentationApprovalHandler::loadExistingBadgeRequest — any active
   * badge_request that is not rejected/duplicate means the badge was effectively
   * granted, so the documentation step no longer needs staff action.
   */
  protected function memberHoldsBadge(int $uid, int $badge_tid): bool {
    if ($badge_tid <= 0) {
      return FALSE;
    }
    $nids = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'badge_request')
      ->condition('status', 1)
      ->condition('field_member_to_badge.target_id', $uid)
      ->condition('field_badge_requested.target_id', $badge_tid)
      ->condition('field_badge_status.value', ['duplicate', 'Rejected', 'rejected'], 'NOT IN')
      ->range(0, 1)
      ->execute();
    return !empty($nids);
  }

  /**
   * Absolute URL of a webform submission's edit (review) form.
   */
  protected function submissionUrl(string $webform_id, int $sid): string {
    try {
      return Url::fromRoute('entity.webform_submission.edit_form', [
        'webform' => $webform_id,
        'webform_submission' => $sid,
      ], ['absolute' => TRUE])->toString();
    }
    catch (\Throwable) {
      return '';
    }
  }

}
