<?php

namespace Drupal\assign_badge_from_quiz\Controller;

use Drupal\assign_badge_from_quiz\Service\QuizSettingsStandardizer;
use Drupal\Component\Utility\Html;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for the Quiz Quality Report.
 */
class QuizQualityReportController extends ControllerBase {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The quiz settings standardizer service.
   *
   * @var \Drupal\assign_badge_from_quiz\Service\QuizSettingsStandardizer
   */
  protected $quizSettingsStandardizer;

  /**
   * Constructs a new QuizQualityReportController object.
   */
  public function __construct(Connection $database, QuizSettingsStandardizer $quiz_settings_standardizer) {
    $this->database = $database;
    $this->quizSettingsStandardizer = $quiz_settings_standardizer;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('assign_badge_from_quiz.quiz_settings_standardizer')
    );
  }

  /**
   * Generates the report.
   */
  public function report() {
    $header = [
      'title' => $this->t('Quiz Title'),
      'associated_badge' => $this->t('Associated Badge'),
      'badge_quality' => $this->t('Badge Quality'),
      'badge_applied_after_quiz' => $this->t('Badge Applied After Quiz'),
      'checkout_requirement' => $this->t('Checkout Requirement'),
      'questions' => $this->t('Questions'),
      'feedback' => $this->t('With Feedback'),
      'quality' => $this->t('Quality Status'),
      'settings_standard' => $this->t('Badge Settings Standard'),
      'actions' => $this->t('Actions'),
    ];

    $rows = [];
    $results = $this->loadBadgeQuizRows();
    $settings_fields = $this->quizSettingsStandardizer->getSettingsFields();
    $settings_standard = $this->quizSettingsStandardizer->deriveSettingsStandardFromRows($results, $settings_fields);
    $quiz_quality_map = $this->buildQuizQualityMapFromRows($results, $settings_standard);
    $badge_quality_by_quiz = $this->buildBadgeQualityByQuizMap($quiz_quality_map);
    $badge_map = $this->loadBadgeMapByQuizId();

    foreach ($results as $result) {
      $qid = (int) $result->qid;
      $badge_summary = $this->buildBadgeSummaryForQuiz((int) $result->qid, $badge_map);
      $quiz_quality = $quiz_quality_map[$qid] ?? ['ok' => FALSE, 'issues' => [(string) $this->t('Unknown quiz quality state')]];
      $badge_quality = $badge_quality_by_quiz[$qid] ?? ['ok' => FALSE, 'issues' => [(string) $this->t('No linked badge terms')]];
      $settings_issues = $this->extractSettingsIssuesFromQuizQuality($quiz_quality['issues']);
      $settings_status = empty($settings_issues)
        ? $this->buildIndicatorMarkup(TRUE, (string) $this->t('In standard'))
        : $this->buildIndicatorMarkup(FALSE, (string) $this->t('Out of standard'), implode('; ', $settings_issues));

      $operation_links = [
        'manage' => [
          'title' => $this->t('Manage Questions'),
          'url' => Url::fromRoute('quiz.questions', ['quiz' => $result->qid]),
          'weight' => -10, // Ensure this is first.
        ],
        'edit' => [
          'title' => $this->t('Edit Quiz'),
          'url' => Url::fromRoute('entity.quiz.edit_form', ['quiz' => $result->qid]),
        ],
        'badge_quality' => [
          'title' => $this->t('Badge Quality Report'),
          'url' => Url::fromRoute('assign_badge_from_quiz.badge_quality_report'),
        ],
      ];
      if (!empty($settings_issues)) {
        $operation_links['standardize'] = [
          'title' => $this->t('Apply Standard'),
          'url' => Url::fromRoute('assign_badge_from_quiz.standardize_quiz_settings', ['quiz' => $result->qid]),
        ];
      }

      $rows[] = [
        'title' => Link::fromTextAndUrl($result->title, Url::fromRoute('entity.quiz.canonical', ['quiz' => $result->qid])),
        'associated_badge' => $badge_summary['associated_badge'],
        'badge_quality' => [
          'data' => $this->buildIndicatorMarkup($badge_quality['ok'], (string) $this->t($badge_quality['ok'] ? 'Greenlight' : 'Redlight'), implode('; ', $badge_quality['issues'])),
        ],
        'badge_applied_after_quiz' => $badge_summary['badge_applied_after_quiz'],
        'checkout_requirement' => $badge_summary['checkout_requirement'],
        // Make the question count a link directly to the management page.
        'questions' => Link::fromTextAndUrl($result->total_questions, Url::fromRoute('quiz.questions', ['quiz' => $result->qid])),
        'feedback' => $result->feedback_count,
        'quality' => [
          'data' => $this->buildIndicatorMarkup($quiz_quality['ok'], (string) $this->t($quiz_quality['ok'] ? 'Greenlight' : 'Redlight'), implode('; ', $quiz_quality['issues'])),
        ],
        'settings_standard' => [
          'data' => $settings_status,
        ],
        'actions' => [
          'data' => [
            '#type' => 'operations',
            '#links' => $operation_links,
          ],
        ],
      ];
    }

    return [
      'nav' => $this->buildReportNavigation('quiz'),
      'table' => [
        '#type' => 'table',
        '#header' => $header,
        '#rows' => $rows,
        '#empty' => $this->t('No badge quizzes found.'),
      ],
      '#attached' => [
        'library' => ['core/drupal.dropbutton', 'assign_badge_from_quiz/quality_reports'],
      ],
    ];
  }

  /**
   * Builds the badge quality report from badge taxonomy term definitions.
   */
  public function badgeQualityReport(): array {
    $status_filter = (string) (\Drupal::request()->query->get('status') ?? 'all');
    $allowed_status_filters = ['all', 'active', 'inactive', 'unpublished', 'unlisted', 'needs_attention'];
    if (!in_array($status_filter, $allowed_status_filters, TRUE)) {
      $status_filter = 'all';
    }

    $header = [
      'badge' => $this->t('Badge Term'),
      'quiz' => $this->t('Quiz'),
      'quiz_quality' => $this->t('Quiz Quality'),
      'checkout_requirement' => $this->t('Checkout Requirement'),
      'checkout_minutes' => $this->t('Checkout Minutes'),
      'checklist' => $this->t('Checklist'),
      'internal_checklist' => $this->t('Internal Checklist'),
      'training_documentation' => $this->t('Training Documentation'),
      'video' => $this->t('Video'),
      'transcript' => $this->t('Transcript'),
      'prerequisites' => $this->t('Prerequisites'),
      'issuers' => $this->t('Issuers'),
      'access_control' => $this->t('Access Control'),
      'status' => $this->t('Status Flags'),
      'quality' => $this->t('Quality Status'),
      'actions' => $this->t('Actions'),
    ];

    $quiz_results = $this->loadBadgeQuizRows();
    $settings_fields = $this->quizSettingsStandardizer->getSettingsFields();
    $settings_standard = $this->quizSettingsStandardizer->deriveSettingsStandardFromRows($quiz_results, $settings_fields);
    $quiz_quality_map = $this->buildQuizQualityMapFromRows($quiz_results, $settings_standard);

    $rows = [];
    $storage = $this->entityTypeManager()->getStorage('taxonomy_term');
    $tids = $storage->getQuery()
      ->condition('vid', 'badges')
      ->sort('name', 'ASC')
      ->accessCheck(TRUE)
      ->execute();

    if (empty($tids)) {
      return [
        '#type' => 'table',
        '#header' => $header,
        '#rows' => [],
        '#empty' => $this->t('No badge terms found.'),
      ];
    }

    /** @var \Drupal\taxonomy\TermInterface[] $terms */
    $terms = $storage->loadMultiple($tids);
    foreach ($terms as $term) {
      $quiz_links = $this->buildLinkedQuizLinks($term);
      $quiz_quality_for_badge = $this->evaluateBadgeLinkedQuizQuality($term, $quiz_quality_map);
      $badge_issues = $this->getBadgeTermQualityIssues($term, $quiz_quality_for_badge);

      $checkout_requirement = 'unknown';
      if ($term->hasField('field_badge_checkout_requirement') && !$term->get('field_badge_checkout_requirement')->isEmpty()) {
        $checkout_requirement = (string) $term->get('field_badge_checkout_requirement')->value;
      }

      $checkout_minutes = NULL;
      if ($term->hasField('field_badge_checkout_minutes') && !$term->get('field_badge_checkout_minutes')->isEmpty()) {
        $checkout_minutes = (int) $term->get('field_badge_checkout_minutes')->value;
      }

      $has_checklist = $term->hasField('field_badge_checklist') && !$term->get('field_badge_checklist')->isEmpty();
      $has_internal_checklist = $term->hasField('field_badge_internal_checklist') && !$term->get('field_badge_internal_checklist')->isEmpty();
      $has_training_doc = $term->hasField('field_training_documentation') && !$term->get('field_training_documentation')->isEmpty();
      $has_video = $term->hasField('field_badge_video') && !$term->get('field_badge_video')->isEmpty();
      $has_transcript = $term->hasField('field_badge_video_transcript') && !$term->get('field_badge_video_transcript')->isEmpty();
      $has_access_control = $term->hasField('field_badge_access_control') && !$term->get('field_badge_access_control')->isEmpty();
      $prereq_count = ($term->hasField('field_badge_prerequisite') && !$term->get('field_badge_prerequisite')->isEmpty()) ? count($term->get('field_badge_prerequisite')->getValue()) : 0;
      $issuer_count = ($term->hasField('field_badge_issuer') && !$term->get('field_badge_issuer')->isEmpty()) ? count($term->get('field_badge_issuer')->getValue()) : 0;
      $is_inactive = $term->hasField('field_badge_inactive') && (bool) $term->get('field_badge_inactive')->value;
      $is_unlisted = $term->hasField('field_badge_unlisted') && (bool) $term->get('field_badge_unlisted')->value;
      $is_published = method_exists($term, 'isPublished') ? (bool) $term->isPublished() : TRUE;

      if (!$this->matchesBadgeStatusFilter($status_filter, $is_published, $is_inactive, $is_unlisted, !empty($badge_issues))) {
        continue;
      }

      $rows[] = [
        'badge' => Link::fromTextAndUrl($term->label(), Url::fromRoute('entity.taxonomy_term.edit_form', ['taxonomy_term' => $term->id()])),
        'quiz' => [
          'data' => [
            '#markup' => !empty($quiz_links) ? implode(', ', $quiz_links) : (string) $this->t('Not linked'),
          ],
        ],
        'quiz_quality' => [
          'data' => $this->buildIndicatorMarkup($quiz_quality_for_badge['ok'], (string) $this->t($quiz_quality_for_badge['ok'] ? 'Greenlight' : 'Redlight'), implode('; ', $quiz_quality_for_badge['issues'])),
        ],
        'checkout_requirement' => $this->getCheckoutRequirementLabel($checkout_requirement),
        'checkout_minutes' => $checkout_minutes === NULL ? $this->t('Not set') : $checkout_minutes,
        'checklist' => $has_checklist ? $this->t('Yes') : $this->t('No'),
        'internal_checklist' => $has_internal_checklist ? $this->t('Yes') : $this->t('No'),
        'training_documentation' => $has_training_doc ? $this->t('Yes') : $this->t('No'),
        'video' => $has_video ? $this->t('Yes') : $this->t('No'),
        'transcript' => $has_transcript ? $this->t('Yes') : $this->t('No'),
        'prerequisites' => $prereq_count,
        'issuers' => $issuer_count,
        'access_control' => $has_access_control ? $this->t('Yes') : $this->t('No'),
        'status' => [
          'data' => [
            '#markup' => implode(' ', [
              $this->buildPillMarkup($is_published ? 'published' : 'unpublished', (string) $this->t($is_published ? 'Published' : 'Unpublished')),
              $this->buildPillMarkup($is_inactive ? 'inactive' : 'active', (string) $this->t($is_inactive ? 'Inactive' : 'Active')),
              $this->buildPillMarkup($is_unlisted ? 'unlisted' : 'listed', (string) $this->t($is_unlisted ? 'Unlisted' : 'Listed')),
            ]),
          ],
        ],
        'quality' => [
          'data' => $this->buildIndicatorMarkup(empty($badge_issues), (string) $this->t(empty($badge_issues) ? 'Greenlight' : 'Redlight'), implode('; ', $badge_issues)),
        ],
        'actions' => [
          'data' => [
            '#type' => 'operations',
            '#links' => [
              'edit_term' => [
                'title' => $this->t('Edit badge'),
                'url' => Url::fromRoute('entity.taxonomy_term.edit_form', ['taxonomy_term' => $term->id()]),
              ],
              'view_term' => [
                'title' => $this->t('View badge'),
                'url' => Url::fromRoute('entity.taxonomy_term.canonical', ['taxonomy_term' => $term->id()]),
              ],
              'quiz_quality' => [
                'title' => $this->t('Quiz Quality Report'),
                'url' => Url::fromRoute('assign_badge_from_quiz.quality_report'),
              ],
            ],
          ],
        ],
      ];
    }

    return [
      'nav' => $this->buildReportNavigation('badge'),
      'filters' => $this->buildBadgeStatusFilters($status_filter),
      'table' => [
        '#type' => 'table',
        '#header' => $header,
        '#rows' => $rows,
        '#empty' => $this->t('No badge terms found for the selected filter.'),
      ],
      '#attached' => [
        'library' => ['core/drupal.dropbutton', 'assign_badge_from_quiz/quality_reports'],
      ],
    ];
  }

  /**
   * Loads badge quiz rows with quiz quality source fields.
   */
  protected function loadBadgeQuizRows(): array {
    $query = "
      SELECT
        q.qid,
        q.title,
        q.pass_rate,
        q.allow_resume,
        q.allow_skipping,
        q.backwards_navigation,
        q.repeat_until_correct,
        q.show_passed,
        q.allow_jumping,
        q.allow_change,
        q.allow_change_blank,
        q.show_attempt_stats,
        q.mark_doubtful,
        COUNT(qqr.question_id) as total_questions,
        SUM(CASE WHEN (qq.feedback__value IS NOT NULL AND qq.feedback__value != '') THEN 1 ELSE 0 END) as feedback_count
      FROM {quiz} q
      LEFT JOIN {quiz_question_relationship} qqr ON q.qid = qqr.quiz_id
      LEFT JOIN {quiz_question} qq ON qqr.question_id = qq.qqid
      WHERE q.type = 'badge_quiz'
      GROUP BY q.qid, q.title
      ORDER BY q.title ASC
    ";
    return $this->database->query($query)->fetchAll();
  }

  /**
   * Builds a quiz quality map keyed by quiz id.
   */
  protected function buildQuizQualityMapFromRows(array $results, array $settings_standard): array {
    $map = [];
    foreach ($results as $result) {
      $issues = [];
      if ($result->total_questions < 3) {
        $issues[] = (string) $this->t('Low question count (< 3)');
      }
      if ($result->feedback_count < $result->total_questions) {
        $issues[] = (string) $this->t('Missing feedback (@count)', ['@count' => $result->total_questions - $result->feedback_count]);
      }
      foreach ($settings_standard as $setting_name => $expected_value) {
        $actual_value = $this->quizSettingsStandardizer->normalizeSettingValue($setting_name, $result->{$setting_name} ?? NULL);
        if ($actual_value !== $expected_value) {
          $issues[] = $this->formatSettingMismatch($setting_name, $actual_value, $expected_value);
        }
      }
      $map[(int) $result->qid] = [
        'ok' => empty($issues),
        'issues' => $issues,
      ];
    }
    return $map;
  }

  /**
   * Builds badge quality rollups keyed by quiz id.
   */
  protected function buildBadgeQualityByQuizMap(array $quiz_quality_map): array {
    $storage = $this->entityTypeManager()->getStorage('taxonomy_term');
    $tids = $storage->getQuery()
      ->condition('vid', 'badges')
      ->accessCheck(TRUE)
      ->execute();
    if (empty($tids)) {
      return [];
    }

    $rollup = [];
    /** @var \Drupal\taxonomy\TermInterface[] $terms */
    $terms = $storage->loadMultiple($tids);
    foreach ($terms as $term) {
      $quiz_quality_for_badge = $this->evaluateBadgeLinkedQuizQuality($term, $quiz_quality_map);
      $issues = $this->getBadgeTermQualityIssues($term, $quiz_quality_for_badge);
      foreach ($this->getLinkedQuizIds($term) as $qid) {
        $rollup[$qid]['issues'] = array_merge($rollup[$qid]['issues'] ?? [], $issues);
      }
    }

    $map = [];
    foreach ($rollup as $qid => $data) {
      $issues = array_values(array_unique($data['issues']));
      $map[(int) $qid] = [
        'ok' => empty($issues),
        'issues' => !empty($issues) ? $issues : [(string) $this->t('All linked badges pass quality checks')],
      ];
    }
    return $map;
  }

  /**
   * Builds linked quiz links for a badge term.
   */
  protected function buildLinkedQuizLinks($term): array {
    $links = [];
    if ($term->hasField('field_badge_quiz_reference') && !$term->get('field_badge_quiz_reference')->isEmpty()) {
      foreach ($term->get('field_badge_quiz_reference')->referencedEntities() as $quiz) {
        $links[] = Link::fromTextAndUrl($quiz->label(), Url::fromRoute('entity.quiz.edit_form', ['quiz' => $quiz->id()]))->toString();
      }
    }
    return $links;
  }

  /**
   * Returns linked quiz ids for a badge term.
   */
  protected function getLinkedQuizIds($term): array {
    $ids = [];
    if ($term->hasField('field_badge_quiz_reference') && !$term->get('field_badge_quiz_reference')->isEmpty()) {
      foreach ($term->get('field_badge_quiz_reference')->referencedEntities() as $quiz) {
        $ids[] = (int) $quiz->id();
      }
    }
    return array_values(array_unique($ids));
  }

  /**
   * Evaluates linked quiz quality for a badge term.
   */
  protected function evaluateBadgeLinkedQuizQuality($term, array $quiz_quality_map): array {
    $linked_quiz_ids = $this->getLinkedQuizIds($term);
    if (empty($linked_quiz_ids)) {
      return [
        'ok' => FALSE,
        'issues' => [(string) $this->t('Missing quiz reference')],
      ];
    }

    $issues = [];
    foreach ($linked_quiz_ids as $qid) {
      if (empty($quiz_quality_map[$qid])) {
        $issues[] = (string) $this->t('Linked quiz @qid not found in quiz-quality dataset', ['@qid' => $qid]);
        continue;
      }
      if (!$quiz_quality_map[$qid]['ok']) {
        $issues[] = (string) $this->t('Linked quiz @qid has quiz-quality issues', ['@qid' => $qid]);
      }
    }

    return [
      'ok' => empty($issues),
      'issues' => !empty($issues) ? $issues : [(string) $this->t('All linked quizzes pass quiz-quality checks')],
    ];
  }

  /**
   * Returns quality issues for a badge term.
   */
  protected function getBadgeTermQualityIssues($term, array $quiz_quality_for_badge): array {
    $issues = [];
    $linked_quiz_ids = $this->getLinkedQuizIds($term);
    if (empty($linked_quiz_ids)) {
      $issues[] = (string) $this->t('Missing quiz reference');
    }

    $checkout_requirement = 'unknown';
    if ($term->hasField('field_badge_checkout_requirement') && !$term->get('field_badge_checkout_requirement')->isEmpty()) {
      $checkout_requirement = (string) $term->get('field_badge_checkout_requirement')->value;
    }
    if ($checkout_requirement === 'unknown') {
      $issues[] = (string) $this->t('Missing checkout requirement');
    }

    $checkout_minutes = NULL;
    if ($term->hasField('field_badge_checkout_minutes') && !$term->get('field_badge_checkout_minutes')->isEmpty()) {
      $checkout_minutes = (int) $term->get('field_badge_checkout_minutes')->value;
    }
    $has_checklist = $term->hasField('field_badge_checklist') && !$term->get('field_badge_checklist')->isEmpty();
    if (in_array($checkout_requirement, ['yes', 'class'], TRUE)) {
      if (!$has_checklist) {
        $issues[] = (string) $this->t('Missing checklist for checkout flow');
      }
      if ($checkout_minutes === NULL || $checkout_minutes <= 0) {
        $issues[] = (string) $this->t('Missing/invalid checkout minutes');
      }
    }

    $has_video = $term->hasField('field_badge_video') && !$term->get('field_badge_video')->isEmpty();
    $has_transcript = $term->hasField('field_badge_video_transcript') && !$term->get('field_badge_video_transcript')->isEmpty();
    if ($has_video && !$has_transcript) {
      $issues[] = (string) $this->t('Video present without transcript');
    }

    if (!$quiz_quality_for_badge['ok']) {
      $issues[] = (string) $this->t('Linked quiz quality is redlight');
    }

    return array_values(array_unique($issues));
  }

  /**
   * Builds status filter links for the badge report.
   */
  protected function buildBadgeStatusFilters(string $active_filter): array {
    $route = 'assign_badge_from_quiz.badge_quality_report';
    $options = [
      'all' => $this->t('All'),
      'active' => $this->t('Active'),
      'inactive' => $this->t('Inactive'),
      'unpublished' => $this->t('Unpublished'),
      'unlisted' => $this->t('Unlisted'),
      'needs_attention' => $this->t('Needs Attention'),
    ];

    $items = [];
    foreach ($options as $value => $label) {
      $link = Link::fromTextAndUrl($label, Url::fromRoute($route, [], ['query' => ['status' => $value]]))->toString();
      $class = $value === $active_filter ? 'abfq-pill abfq-pill--selected' : 'abfq-pill';
      $items[] = '<span class="' . $class . '">' . $link . '</span>';
    }

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['abfq-filter-row']],
      'label' => [
        '#markup' => '<strong>' . $this->t('Status Filter:') . '</strong>',
      ],
      'links' => [
        '#markup' => implode(' ', $items),
      ],
    ];
  }

  /**
   * Returns whether a badge matches the current report filter.
   */
  protected function matchesBadgeStatusFilter(string $filter, bool $is_published, bool $is_inactive, bool $is_unlisted, bool $has_quality_issues): bool {
    return match ($filter) {
      'active' => $is_published && !$is_inactive,
      'inactive' => $is_inactive,
      'unpublished' => !$is_published,
      'unlisted' => $is_unlisted,
      'needs_attention' => $has_quality_issues,
      default => TRUE,
    };
  }

  /**
   * Builds navigation links between quality reports.
   */
  protected function buildReportNavigation(string $current): array {
    $quiz_link = Link::fromTextAndUrl($this->t('Quiz Quality Report'), Url::fromRoute('assign_badge_from_quiz.quality_report'))->toString();
    $badge_link = Link::fromTextAndUrl($this->t('Badge Quality Report'), Url::fromRoute('assign_badge_from_quiz.badge_quality_report'))->toString();

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['abfq-nav-row']],
      'quiz' => [
        '#markup' => '<span class="' . ($current === 'quiz' ? 'abfq-pill abfq-pill--selected' : 'abfq-pill') . '">' . $quiz_link . '</span>',
      ],
      'badge' => [
        '#markup' => '<span class="' . ($current === 'badge' ? 'abfq-pill abfq-pill--selected' : 'abfq-pill') . '">' . $badge_link . '</span>',
      ],
    ];
  }

  /**
   * Builds red/green indicator markup.
   */
  protected function buildIndicatorMarkup(bool $ok, string $label, string $details = ''): array {
    $class = $ok ? 'abfq-indicator--ok' : 'abfq-indicator--bad';
    $title_attr = $details !== '' ? ' title="' . Html::escape($details) . '"' : '';
    $markup = '<span class="abfq-indicator ' . $class . '"' . $title_attr . '>' . Html::escape($label) . '</span>';
    if (!$ok && $details !== '') {
      $markup .= '<div class="abfq-indicator-notes">' . Html::escape($details) . '</div>';
    }
    return ['#markup' => $markup];
  }

  /**
   * Builds small status pill markup.
   */
  protected function buildPillMarkup(string $modifier, string $label): string {
    return '<span class="abfq-pill abfq-pill--' . Html::escape($modifier) . '">' . Html::escape($label) . '</span>';
  }

  /**
   * Extracts settings mismatch items from the combined quiz quality issues.
   */
  protected function extractSettingsIssuesFromQuizQuality(array $issues): array {
    $settings_issues = [];
    foreach ($issues as $issue) {
      if (str_contains($issue, '(expected ')) {
        $settings_issues[] = $issue;
      }
    }
    return $settings_issues;
  }

  /**
   * Formats setting mismatch details for display.
   */
  protected function formatSettingMismatch(string $setting_name, $actual_value, $expected_value): string {
    $format = static function ($value): string {
      return is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
    };

    return sprintf('%s=%s (expected %s)', $setting_name, $format($actual_value), $format($expected_value));
  }

  /**
   * Loads associated badges grouped by quiz id.
   */
  protected function loadBadgeMapByQuizId(): array {
    $query = "
      SELECT
        bqr.field_badge_quiz_reference_target_id AS qid,
        td.name AS badge_name,
        bcr.field_badge_checkout_requirement_value AS checkout_requirement
      FROM {taxonomy_term__field_badge_quiz_reference} bqr
      INNER JOIN {taxonomy_term_field_data} td ON td.tid = bqr.entity_id
      LEFT JOIN {taxonomy_term__field_badge_checkout_requirement} bcr ON bcr.entity_id = bqr.entity_id
      WHERE td.vid = 'badges' AND td.default_langcode = 1
      ORDER BY td.name ASC
    ";

    $rows = $this->database->query($query)->fetchAll();
    $badge_map = [];
    foreach ($rows as $row) {
      $qid = (int) $row->qid;
      $badge_map[$qid]['names'][] = (string) $row->badge_name;
      $badge_map[$qid]['checkout'][] = (string) ($row->checkout_requirement ?? '');
    }
    return $badge_map;
  }

  /**
   * Builds badge association/checkout summary for a quiz.
   */
  protected function buildBadgeSummaryForQuiz(int $qid, array $badge_map): array {
    if (empty($badge_map[$qid]['names'])) {
      return [
        'associated_badge' => $this->t('Not linked'),
        'badge_applied_after_quiz' => $this->t('Unknown'),
        'checkout_requirement' => $this->t('Unknown'),
      ];
    }

    $badge_names = array_values(array_unique($badge_map[$qid]['names']));
    $checkout_values = array_values(array_unique(array_filter($badge_map[$qid]['checkout'], static fn(string $value): bool => $value !== '')));

    $labels = [];
    foreach ($checkout_values as $value) {
      $labels[] = $this->getCheckoutRequirementLabel($value);
    }
    $checkout_label = !empty($labels) ? implode('; ', $labels) : (string) $this->t('Unknown');

    $has_auto_apply = in_array('no', $checkout_values, TRUE);
    $has_checkout = count(array_intersect($checkout_values, ['yes', 'class'])) > 0;
    if ($has_auto_apply && !$has_checkout) {
      $applied_after_quiz = $this->t('Yes');
    }
    elseif (!$has_auto_apply && $has_checkout) {
      $applied_after_quiz = $this->t('No');
    }
    else {
      $applied_after_quiz = $this->t('Mixed');
    }

    return [
      'associated_badge' => implode(', ', $badge_names),
      'badge_applied_after_quiz' => $applied_after_quiz,
      'checkout_requirement' => $checkout_label,
    ];
  }

  /**
   * Converts checkout requirement machine values to report labels.
   */
  protected function getCheckoutRequirementLabel(string $value): string {
    return match ($value) {
      'yes' => (string) $this->t('In person checkout required'),
      'no' => (string) $this->t('Badge applied after successful quiz'),
      'class' => (string) $this->t('Submit documentation or complete a workshop'),
      default => (string) $this->t('Unknown'),
    };
  }

}
