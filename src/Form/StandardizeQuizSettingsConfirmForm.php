<?php

namespace Drupal\assign_badge_from_quiz\Form;

use Drupal\assign_badge_from_quiz\Service\QuizSettingsStandardizer;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\quiz\Entity\Quiz;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Confirmation form for applying standard settings to a badge quiz.
 */
class StandardizeQuizSettingsConfirmForm extends ConfirmFormBase {

  /**
   * The quiz entity being standardized.
   *
   * @var \Drupal\quiz\Entity\Quiz|null
   */
  protected $quiz;

  /**
   * The quiz settings standardizer service.
   *
   * @var \Drupal\assign_badge_from_quiz\Service\QuizSettingsStandardizer
   */
  protected $standardizer;

  /**
   * Constructs a new StandardizeQuizSettingsConfirmForm.
   */
  public function __construct(QuizSettingsStandardizer $standardizer) {
    $this->standardizer = $standardizer;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('assign_badge_from_quiz.quiz_settings_standardizer')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'assign_badge_from_quiz_standardize_quiz_settings_confirm_form';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): string {
    return $this->t('Apply standard badge quiz settings to %title?', [
      '%title' => $this->quiz ? $this->quiz->label() : '',
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): string {
    return $this->t('This updates the quiz settings to match the currently established badge quiz standard.');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText(): string {
    return $this->t('Apply Standard');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return Url::fromRoute('assign_badge_from_quiz.quality_report');
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?Quiz $quiz = NULL): array {
    $this->quiz = $quiz;

    if (!$this->quiz || $this->quiz->bundle() !== 'badge_quiz') {
      $this->messenger()->addError($this->t('Only badge quizzes can be standardized from this report.'));
      $form_state->setRedirect('assign_badge_from_quiz.quality_report');
      return [];
    }

    $mismatches = $this->standardizer->getMismatches($this->quiz);
    if (empty($mismatches)) {
      $form['already_standard'] = [
        '#type' => 'item',
        '#markup' => $this->t('This quiz is already in standard.'),
      ];
    }
    else {
      $items = [];
      foreach ($mismatches as $setting_name => $values) {
        $items[] = $this->t('@setting: @actual -> @expected', [
          '@setting' => $setting_name,
          '@actual' => $this->formatValue($values['actual']),
          '@expected' => $this->formatValue($values['expected']),
        ]);
      }
      $form['changes'] = [
        '#theme' => 'item_list',
        '#title' => $this->t('Settings that will be changed'),
        '#items' => $items,
      ];
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    if (!$this->quiz || $this->quiz->bundle() !== 'badge_quiz') {
      $form_state->setRedirect('assign_badge_from_quiz.quality_report');
      return;
    }

    $changes = $this->standardizer->applyStandard($this->quiz);
    if (empty($changes)) {
      $this->messenger()->addStatus($this->t('No changes were needed. %title is already in standard.', [
        '%title' => $this->quiz->label(),
      ]));
    }
    else {
      $this->messenger()->addStatus($this->t('Applied standard settings to %title (@count updated).', [
        '%title' => $this->quiz->label(),
        '@count' => count($changes),
      ]));
    }

    $form_state->setRedirect('assign_badge_from_quiz.quality_report');
  }

  /**
   * Formats values for display.
   */
  protected function formatValue($value): string {
    if (is_bool($value)) {
      return $value ? 'true' : 'false';
    }
    return (string) $value;
  }

}
