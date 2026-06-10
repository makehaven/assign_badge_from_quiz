<?php

namespace Drupal\assign_badge_from_quiz\Form;

use Drupal\assign_badge_from_quiz\Service\DocumentationActionTokenService;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * No-login confirmation page for an Approve / Reject documentation action.
 *
 * Reached from the signed link in the staff review email. The HMAC token is
 * the authorization; rendering the page (GET) has no side effect, so an email
 * scanner that pre-fetches the link cannot change anything. Only submitting the
 * form (POST, CSRF-protected) applies the status change.
 */
class DocumentationActionConfirmForm extends ConfirmFormBase {

  /**
   * The webform submission ID from the action link.
   *
   * @var int
   */
  protected int $submissionId = 0;

  /**
   * The requested action ('approve' or 'reject').
   *
   * @var string
   */
  protected string $action = '';

  /**
   * The signed HMAC token from the action link.
   *
   * @var string
   */
  protected string $token = '';

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected DocumentationActionTokenService $tokens,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('assign_badge_from_quiz.documentation_action_tokens'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'assign_badge_from_quiz_documentation_action_confirm';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $submission = NULL, $action = NULL, $token = NULL): array {
    $this->submissionId = (int) $submission;
    $this->action = (string) $action;
    $this->token = (string) $token;

    $data = $this->tokens->validate($this->token);
    if (!$data || (int) $data['s'] !== $this->submissionId || $data['a'] !== $this->action) {
      throw new AccessDeniedHttpException('This action link is invalid or has expired.');
    }

    $entity = $this->loadSubmission();
    if (!$entity) {
      throw new AccessDeniedHttpException('Submission not found.');
    }

    $member = $entity->getOwner();
    $sub_data = $entity->getData();
    $current = isset($sub_data['status']) ? (string) $sub_data['status'] : '';

    $form = parent::buildForm($form, $form_state);

    $form['summary'] = [
      '#theme' => 'item_list',
      '#weight' => -10,
      '#items' => [
        $this->t('Member: @name', ['@name' => $member ? $member->getDisplayName() : $this->t('(unknown)')]),
        $this->t('Tool: @tool', ['@tool' => $entity->getWebform()->label()]),
        $this->t('Current status: @status', ['@status' => $current !== '' ? $current : $this->t('(none / pending)')]),
      ],
    ];
    if ($current === 'approved' && $this->action === 'approve') {
      $form['already'] = [
        '#markup' => '<p><em>' . $this->t('This submission is already approved. Confirming again is harmless.') . '</em></p>',
        '#weight' => -9,
      ];
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): string {
    $verb = $this->action === 'approve' ? $this->t('Approve') : $this->t('Reject');
    return $this->t('@verb this documentation submission?', ['@verb' => $verb]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): string {
    if ($this->action === 'approve') {
      return $this->t('The member will be emailed that their documentation is approved, along with their next step (take the quiz, then schedule a checkout).');
    }
    return $this->t('The submission will be marked rejected. The member is not emailed automatically.');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText(): string {
    return $this->action === 'approve' ? $this->t('Approve') : $this->t('Reject');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return Url::fromUri('internal:/');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // Re-validate on submit — never trust that buildForm ran on this request.
    $data = $this->tokens->validate($this->token);
    if (!$data || (int) $data['s'] !== $this->submissionId || $data['a'] !== $this->action) {
      throw new AccessDeniedHttpException('This action link is invalid or has expired.');
    }

    $entity = $this->loadSubmission();
    if (!$entity) {
      throw new AccessDeniedHttpException('Submission not found.');
    }

    $new_status = $this->action === 'approve' ? 'approved' : 'rejected';
    $entity->setElementData('status', $new_status);
    // Saving triggers DocumentationApprovalHandler::postSave, which (on
    // approval) emails the member and retro-creates the badge request.
    $entity->save();

    $this->logger('assign_badge_from_quiz')->notice('Documentation @action applied to submission @sid via signed email link.', [
      '@action' => $this->action,
      '@sid' => $this->submissionId,
    ]);

    $this->messenger()->addStatus($this->action === 'approve'
      ? $this->t('Approved. The member has been emailed their next step.')
      : $this->t('Marked rejected.'));

    $form_state->setRedirectUrl(Url::fromUri('internal:/'));
  }

  /**
   * Load the webform submission named in the action link.
   */
  protected function loadSubmission() {
    return $this->entityTypeManager->getStorage('webform_submission')->load($this->submissionId);
  }

}
