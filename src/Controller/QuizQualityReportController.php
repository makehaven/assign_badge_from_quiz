<?php

namespace Drupal\assign_badge_from_quiz\Controller;

use Drupal\assign_badge_from_quiz\Service\QuizSettingsStandardizer;
use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\Unicode;
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
    $focus_quiz_id = $this->getPositiveQueryInt('quiz');

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
      if ($focus_quiz_id > 0 && $qid !== $focus_quiz_id) {
        continue;
      }
      $has_any_badges = !empty($badge_map[$qid]['names']);
      $has_actionable_badges = isset($badge_quality_by_quiz[$qid]);
      if ($focus_quiz_id === 0 && $has_any_badges && !$has_actionable_badges) {
        continue;
      }

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
          'url' => Url::fromRoute('assign_badge_from_quiz.badge_quality_report', [], ['query' => ['quiz' => $result->qid, 'status' => 'needs_attention']]),
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
          'data' => $this->buildIndicatorWithLink(
            $badge_quality['ok'],
            (string) $this->t($badge_quality['ok'] ? 'Greenlight' : 'Redlight'),
            implode('; ', $badge_quality['issues']),
            Url::fromRoute('assign_badge_from_quiz.badge_quality_report', [], ['query' => ['quiz' => $qid, 'status' => 'needs_attention']]),
            (string) $this->t('Open related badge issues')
          ),
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
      'focus' => $this->buildFocusSummary([
        $focus_quiz_id > 0 ? (string) $this->t('Focused on quiz ID @qid.', ['@qid' => $focus_quiz_id]) : '',
      ], 'assign_badge_from_quiz.quality_report'),
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
    $status_filter = (string) (\Drupal::request()->query->get('status') ?? 'needs_attention');
    $allowed_status_filters = ['all', 'active', 'inactive', 'unpublished', 'unlisted', 'needs_attention'];
    if (!in_array($status_filter, $allowed_status_filters, TRUE)) {
      $status_filter = 'needs_attention';
    }
    $focus_badge_id = $this->getPositiveQueryInt('badge');
    $focus_quiz_id = $this->getPositiveQueryInt('quiz');
    $focus_issuer_id = $this->getPositiveQueryInt('issuer');

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

    // Load transcript failure counters once for the whole report.
    $transcript_failures = \Drupal::keyValue('youtube_transcript')->getAll();

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
      if ($focus_badge_id > 0 && (int) $term->id() !== $focus_badge_id) {
        continue;
      }
      if ($focus_quiz_id > 0 && !in_array($focus_quiz_id, $this->getLinkedQuizIds($term), TRUE)) {
        continue;
      }
      if ($focus_issuer_id > 0 && !$this->isUserReferencedInBadgeIssuerFields($term, $focus_issuer_id)) {
        continue;
      }

      $quiz_links = $this->buildLinkedQuizLinks($term);
      $quiz_quality_for_badge = $this->evaluateBadgeLinkedQuizQuality($term, $quiz_quality_map);
      $linked_quiz_ids = $this->getLinkedQuizIds($term);

      $has_video = $term->hasField('field_badge_video') && !$term->get('field_badge_video')->isEmpty();
      $has_transcript = $term->hasField('field_badge_video_transcript') && !$term->get('field_badge_video_transcript')->isEmpty();

      // Check if transcript fetch is permanently blocked for this video.
      $video_id = NULL;
      $transcript_blocked = FALSE;
      if ($has_video) {
        $video_url = $term->get('field_badge_video')->getValue()[0]['input'] ?? '';
        if ($video_url) {
          /** @var \Drupal\youtube_transcript\YoutubeTranscriptFetcher $fetcher */
          $fetcher = \Drupal::service('youtube_transcript.fetcher');
          $video_id = $fetcher->extractVideoId($video_url);
          if ($video_id) {
            $fail_count = (int) ($transcript_failures['fail_' . $video_id] ?? 0);
            $transcript_blocked = $fail_count >= YOUTUBE_TRANSCRIPT_MAX_FAILURES;
          }
        }
      }

      $badge_issues = $this->getBadgeTermQualityIssues($term, $quiz_quality_for_badge, $transcript_blocked);

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
      $has_access_control = $term->hasField('field_badge_access_control') && !$term->get('field_badge_access_control')->isEmpty();
      $prereq_count = ($term->hasField('field_badge_prerequisite') && !$term->get('field_badge_prerequisite')->isEmpty()) ? count($term->get('field_badge_prerequisite')->getValue()) : 0;
      $issuer_count = ($term->hasField('field_badge_issuer') && !$term->get('field_badge_issuer')->isEmpty()) ? count($term->get('field_badge_issuer')->getValue()) : 0;
      $is_inactive = $term->hasField('field_badge_inactive') && (bool) $term->get('field_badge_inactive')->value;
      $is_unlisted = $term->hasField('field_badge_unlisted') && (bool) $term->get('field_badge_unlisted')->value;
      $is_published = method_exists($term, 'isPublished') ? (bool) $term->isPublished() : TRUE;

      if (!$this->matchesBadgeStatusFilter($status_filter, $is_published, $is_inactive, $is_unlisted, !empty($badge_issues))) {
        continue;
      }

      // Build transcript cell: show blocked status with links when applicable.
      $transcript_cell = $this->buildTranscriptCell($has_video, $has_transcript, $transcript_blocked, $video_id);

      // Build action links, adding Studio + Retry when blocked.
      $action_links = [
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
          'url' => Url::fromRoute('assign_badge_from_quiz.quality_report', [], ['query' => array_filter([
            'quiz' => $linked_quiz_ids[0] ?? NULL,
          ])]),
        ],
      ];
      if ($transcript_blocked && $video_id) {
        $action_links['add_captions'] = [
          'title' => $this->t('Add captions in YouTube Studio'),
          'url' => Url::fromUri('https://studio.youtube.com/video/' . $video_id . '/translations'),
          'attributes' => ['target' => '_blank'],
        ];
        $action_links['retry_transcript'] = [
          'title' => $this->t('Retry transcript fetch'),
          'url' => Url::fromRoute('youtube_transcript.retry_video', ['video_id' => $video_id]),
        ];
      }

      $rows[] = [
        'badge' => Link::fromTextAndUrl($term->label(), Url::fromRoute('entity.taxonomy_term.edit_form', ['taxonomy_term' => $term->id()])),
        'quiz' => [
          'data' => [
            '#markup' => !empty($quiz_links) ? implode(', ', $quiz_links) : (string) $this->t('Not linked'),
          ],
        ],
        'quiz_quality' => [
          'data' => $this->buildIndicatorWithLink(
            $quiz_quality_for_badge['ok'],
            (string) $this->t($quiz_quality_for_badge['ok'] ? 'Greenlight' : 'Redlight'),
            implode('; ', $quiz_quality_for_badge['issues']),
            Url::fromRoute('assign_badge_from_quiz.quality_report', [], ['query' => array_filter([
              'quiz' => $linked_quiz_ids[0] ?? NULL,
            ])]),
            (string) $this->t('Open related quiz issues')
          ),
        ],
        'checkout_requirement' => $this->getCheckoutRequirementLabel($checkout_requirement),
        'checkout_minutes' => $checkout_minutes === NULL ? $this->t('Not set') : $checkout_minutes,
        'checklist' => $has_checklist ? $this->t('Yes') : $this->t('No'),
        'internal_checklist' => $has_internal_checklist ? $this->t('Yes') : $this->t('No'),
        'training_documentation' => $has_training_doc ? $this->t('Yes') : $this->t('No'),
        'video' => $has_video ? $this->t('Yes') : $this->t('Optional'),
        'transcript' => ['data' => $transcript_cell],
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
            '#links' => $action_links,
          ],
        ],
      ];
    }

    return [
      'nav' => $this->buildReportNavigation('badge'),
      'filters' => $this->buildBadgeStatusFilters($status_filter),
      'focus' => $this->buildFocusSummary([
        $focus_badge_id > 0 ? (string) $this->t('Focused on badge ID @tid.', ['@tid' => $focus_badge_id]) : '',
        $focus_quiz_id > 0 ? (string) $this->t('Filtered to badges linked to quiz ID @qid.', ['@qid' => $focus_quiz_id]) : '',
        $focus_issuer_id > 0 ? (string) $this->t('Filtered to badges where user ID @uid is an issuer.', ['@uid' => $focus_issuer_id]) : '',
      ], 'assign_badge_from_quiz.badge_quality_report', ['status' => $status_filter]),
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
      if (!$this->isActionableBadgeTerm($term)) {
        continue;
      }

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
   * Builds badge quality maps keyed by badge term id.
   */
  protected function buildBadgeQualityByTermMap(array $quiz_quality_map): array {
    $storage = $this->entityTypeManager()->getStorage('taxonomy_term');
    $tids = $storage->getQuery()
      ->condition('vid', 'badges')
      ->accessCheck(TRUE)
      ->execute();
    if (empty($tids)) {
      return [];
    }

    $map = [];
    /** @var \Drupal\taxonomy\TermInterface[] $terms */
    $terms = $storage->loadMultiple($tids);
    foreach ($terms as $term) {
      if (!$this->isActionableBadgeTerm($term)) {
        continue;
      }

      $quiz_quality_for_badge = $this->evaluateBadgeLinkedQuizQuality($term, $quiz_quality_map);
      $issues = $this->getBadgeTermQualityIssues($term, $quiz_quality_for_badge);
      $map[(int) $term->id()] = [
        'ok' => empty($issues),
        'issues' => !empty($issues) ? $issues : [(string) $this->t('Badge passes quality checks')],
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
   *
   * @param bool $transcript_blocked
   *   TRUE when the video's transcript fetch has permanently failed (>= max
   *   failures). Used to surface a more specific issue message.
   */
  protected function getBadgeTermQualityIssues($term, array $quiz_quality_for_badge, bool $transcript_blocked = FALSE): array {
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
      if ($transcript_blocked) {
        $issues[] = (string) $this->t('Transcript blocked — upload manual captions in YouTube Studio then click "Retry transcript fetch"');
      }
      else {
        $issues[] = (string) $this->t('Video present without transcript');
      }
    }

    if (!$quiz_quality_for_badge['ok']) {
      $issues[] = (string) $this->t('Linked quiz quality is redlight');
    }

    return array_values(array_unique($issues));
  }

  /**
   * Builds the transcript status cell for the badge quality report.
   *
   * Shows:
   *   - "Yes" when transcript is populated.
   *   - "Blocked" (red pill) + YouTube Studio link when fetch has permanently
   *     failed; the admin can upload captions there then click Retry.
   *   - "Pending" when a video exists but no transcript yet (cron will try).
   *   - "No video" when no video URL is set.
   */
  protected function buildTranscriptCell(bool $has_video, bool $has_transcript, bool $transcript_blocked, ?string $video_id): array {
    if ($has_transcript) {
      return ['#markup' => '<span class="abfq-pill abfq-pill--active">Yes</span>'];
    }
    if (!$has_video) {
      return ['#markup' => '<span class="abfq-pill">No video</span>'];
    }
    if ($transcript_blocked && $video_id) {
      $studio_url = 'https://studio.youtube.com/video/' . $video_id . '/translations';
      $markup = '<span class="abfq-pill abfq-pill--inactive" title="Cron has failed 3+ times. Upload a manual SRT/VTT caption in YouTube Studio, then use Retry in the actions menu.">Blocked</span>';
      $markup .= ' <a href="' . Html::escape($studio_url) . '" target="_blank" rel="noopener">YouTube Studio →</a>';
      return ['#markup' => $markup];
    }
    return ['#markup' => '<span class="abfq-pill abfq-pill--unpublished" title="Cron will fetch this automatically.">Pending</span>'];
  }

  /**
   * Builds status filter links for the badge report.
   */
  protected function buildBadgeStatusFilters(string $active_filter): array {
    $route = 'assign_badge_from_quiz.badge_quality_report';
    $options = [
      'needs_attention' => $this->t('Needs Attention'),
      'active' => $this->t('Active'),
      'all' => $this->t('All'),
      'inactive' => $this->t('Inactive'),
      'unpublished' => $this->t('Unpublished'),
      'unlisted' => $this->t('Unlisted'),
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
      'active' => $is_published && !$is_inactive && !$is_unlisted,
      'inactive' => $is_inactive,
      'unpublished' => !$is_published,
      'unlisted' => $is_unlisted,
      'needs_attention' => $is_published && !$is_inactive && !$is_unlisted && $has_quality_issues,
      default => TRUE,
    };
  }

  /**
   * Builds navigation links between quality reports.
   */
  protected function buildReportNavigation(string $current): array {
    $quiz_link = Link::fromTextAndUrl($this->t('Quiz Quality Report'), Url::fromRoute('assign_badge_from_quiz.quality_report'))->toString();
    $badge_link = Link::fromTextAndUrl($this->t('Badge Quality Report'), Url::fromRoute('assign_badge_from_quiz.badge_quality_report'))->toString();
    $tool_link = Link::fromTextAndUrl($this->t('Tool Quality Report'), Url::fromRoute('assign_badge_from_quiz.tool_quality_report'))->toString();
    $tool_ai_link = Link::fromTextAndUrl($this->t('Tool AI Quality Report'), Url::fromRoute('assign_badge_from_quiz.tool_ai_quality_report'))->toString();
    $facilitator_link = Link::fromTextAndUrl($this->t('Facilitator Setup Report'), Url::fromRoute('assign_badge_from_quiz.facilitator_setup_report'))->toString();

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['abfq-nav-row']],
      'quiz' => [
        '#markup' => '<span class="' . ($current === 'quiz' ? 'abfq-pill abfq-pill--selected' : 'abfq-pill') . '">' . $quiz_link . '</span>',
      ],
      'badge' => [
        '#markup' => '<span class="' . ($current === 'badge' ? 'abfq-pill abfq-pill--selected' : 'abfq-pill') . '">' . $badge_link . '</span>',
      ],
      'tool' => [
        '#markup' => '<span class="' . ($current === 'tool' ? 'abfq-pill abfq-pill--selected' : 'abfq-pill') . '">' . $tool_link . '</span>',
      ],
      'tool_ai' => [
        '#markup' => '<span class="' . ($current === 'tool_ai' ? 'abfq-pill abfq-pill--selected' : 'abfq-pill') . '">' . $tool_ai_link . '</span>',
      ],
      'facilitator' => [
        '#markup' => '<span class="' . ($current === 'facilitator' ? 'abfq-pill abfq-pill--selected' : 'abfq-pill') . '">' . $facilitator_link . '</span>',
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
   * Builds an indicator plus a direct follow-up link.
   */
  protected function buildIndicatorWithLink(bool $ok, string $label, string $details, Url $url, string $link_label): array {
    $build = $this->buildIndicatorMarkup($ok, $label, $details);
    $markup = (string) ($build['#markup'] ?? '');
    $markup .= '<div class="abfq-indicator-notes"><a href="' . Html::escape($url->toString()) . '">' . Html::escape($link_label) . '</a></div>';
    return ['#markup' => $markup];
  }

  /**
   * Builds small status pill markup.
   */
  protected function buildPillMarkup(string $modifier, string $label): string {
    return '<span class="abfq-pill abfq-pill--' . Html::escape($modifier) . '">' . Html::escape($label) . '</span>';
  }

  /**
   * Builds a compact summary of any active focus query params.
   */
  protected function buildFocusSummary(array $messages, string $route_name, array $query = []): array {
    $messages = array_values(array_filter($messages));
    if ($messages === []) {
      return [];
    }

    $clear_url = Url::fromRoute($route_name, [], ['query' => $query])->toString();
    return [
      '#markup' => '<div class="abfq-focus-summary">' . Html::escape(implode(' ', $messages)) . ' <a href="' . Html::escape($clear_url) . '">' . Html::escape((string) $this->t('Clear focus')) . '</a></div>',
    ];
  }

  /**
   * Reads a positive integer query parameter.
   */
  protected function getPositiveQueryInt(string $key): int {
    $value = \Drupal::request()->query->get($key);
    return is_numeric($value) && (int) $value > 0 ? (int) $value : 0;
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
   * Builds the tool quality report from item nodes.
   */
  public function toolQualityReport(): array {
    $status_filter = (string) (\Drupal::request()->query->get('status') ?? 'needs_attention');
    $allowed_status_filters = ['all', 'published', 'unpublished', 'gone', 'reservable', 'no_badge', 'no_docs', 'no_slack', 'stale', 'needs_attention'];
    if (!in_array($status_filter, $allowed_status_filters, TRUE)) {
      $status_filter = 'needs_attention';
    }
    $focus_tool_id = $this->getPositiveQueryInt('tool');
    $focus_badge_id = $this->getPositiveQueryInt('badge');

    $header = [
      'tool' => $this->t('Tool'),
      'published' => $this->t('Published'),
      'status' => $this->t('Status'),
      'area' => $this->t('Area of Interest'),
      'category' => $this->t('Category'),
      'hazard' => $this->t('Hazard Band'),
      'description' => $this->t('Description'),
      'primary_badge' => $this->t('Badge Requirement'),
      'badge_quality' => $this->t('Badge Quality'),
      'documentation' => $this->t('Use Docs'),
      'model' => $this->t('Model'),
      'value' => $this->t('Value'),
      'image' => $this->t('Image'),
      'location' => $this->t('Location'),
      'reservable' => $this->t('Reservable Profile'),
      'slack' => $this->t('Slack Routing'),
      'last_updated' => $this->t('Last Updated'),
      'quality' => $this->t('Quality Status'),
      'actions' => $this->t('Actions'),
    ];

    $quiz_results = $this->loadBadgeQuizRows();
    $settings_fields = $this->quizSettingsStandardizer->getSettingsFields();
    $settings_standard = $this->quizSettingsStandardizer->deriveSettingsStandardFromRows($quiz_results, $settings_fields);
    $quiz_quality_map = $this->buildQuizQualityMapFromRows($quiz_results, $settings_standard);
    $badge_quality_by_term = $this->buildBadgeQualityByTermMap($quiz_quality_map);

    $rows = [];
    $node_storage = $this->entityTypeManager()->getStorage('node');
    $nids = $node_storage->getQuery()
      ->condition('type', 'item')
      ->sort('title', 'ASC')
      ->accessCheck(TRUE)
      ->execute();

    if (empty($nids)) {
      return [
        'nav' => $this->buildReportNavigation('tool'),
        'filters' => $this->buildToolStatusFilters($status_filter),
        'table' => [
          '#type' => 'table',
          '#header' => $header,
          '#rows' => [],
          '#empty' => $this->t('No tool pages found.'),
        ],
        '#attached' => [
          'library' => ['core/drupal.dropbutton', 'assign_badge_from_quiz/quality_reports'],
        ],
      ];
    }

    /** @var \Drupal\node\NodeInterface[] $nodes */
    $nodes = $node_storage->loadMultiple($nids);
    foreach ($nodes as $node) {
      if ($focus_tool_id > 0 && (int) $node->id() !== $focus_tool_id) {
        continue;
      }

      $is_published = method_exists($node, 'isPublished') ? (bool) $node->isPublished() : TRUE;
      $status_label = $node->hasField('field_item_status') && !$node->get('field_item_status')->isEmpty()
        ? (string) $node->get('field_item_status')->entity?->label()
        : '';
      $has_status = $status_label !== '';
      $is_gone = $this->isGoneToolStatusLabel($status_label);
      $area_labels = $this->getReferencedEntityLabels($node, 'field_item_area_interest');
      $category_labels = $this->getReferencedEntityLabels($node, 'field_item_category');
      $hazard_label = $node->hasField('field_item_hazard_band') && !$node->get('field_item_hazard_band')->isEmpty()
        ? trim((string) $node->get('field_item_hazard_band')->value)
        : '';
      $description = $node->hasField('body') && !$node->get('body')->isEmpty()
        ? trim(strip_tags((string) $node->get('body')->value))
        : '';
      $model = $node->hasField('field_item_model') && !$node->get('field_item_model')->isEmpty()
        ? trim((string) $node->get('field_item_model')->value)
        : '';
      $value_amount = $node->hasField('field_item_value') && !$node->get('field_item_value')->isEmpty()
        ? (float) $node->get('field_item_value')->value
        : NULL;
      $is_individual_asset = $this->isIndividualAsset($node, $category_labels);

      $primary_badges = $this->getReferencedTerms($node, 'field_member_badges');
      $additional_badges = $this->getReferencedTerms($node, 'field_additional_badges');
      $all_badges = $this->mergeTermsById($primary_badges, $additional_badges);
      if ($focus_badge_id > 0 && !$this->termIdExistsInArray($focus_badge_id, $all_badges)) {
        continue;
      }
      $primary_badge_links = $this->buildBadgeLinksForTerms($primary_badges);

      $badge_quality = $this->evaluateToolBadgeQuality($primary_badges, $all_badges, $badge_quality_by_term);

      $has_instructions = $node->hasField('field_item_instructions') && !$node->get('field_item_instructions')->isEmpty() && trim((string) $node->get('field_item_instructions')->value) !== '';
      $has_manuals = $node->hasField('field_manuals') && !$node->get('field_manuals')->isEmpty();
      $has_video = $node->hasField('field_item_instructional_video') && !$node->get('field_item_instructional_video')->isEmpty();
      $has_docs = $has_instructions || $has_manuals || $has_video;

      $has_image = $node->hasField('field_item_image') && !$node->get('field_item_image')->isEmpty();
      $has_location = $node->hasField('field_item_location') && !$node->get('field_item_location')->isEmpty() && trim((string) $node->get('field_item_location')->value) !== '';

      $reservable_values = $this->getItemReservableValues($node);
      $is_reservable = !empty($reservable_values);
      $has_min_advance = $node->hasField('field_item_min_advance_hours') && !$node->get('field_item_min_advance_hours')->isEmpty();
      $has_max_duration = $node->hasField('field_item_max_reservation_hours') && !$node->get('field_item_max_reservation_hours')->isEmpty();
      $has_email_template = $node->hasField('field_item_email_template') && !$node->get('field_item_email_template')->isEmpty() && trim((string) $node->get('field_item_email_template')->value) !== '';
      $reservation_profile_label = !empty($reservable_values) ? implode(', ', $this->mapReservableLabels($reservable_values)) : (string) $this->t('No');

      $slack_routing = $this->resolveToolSlackRouting($node);
      $has_slack_routing = $slack_routing['channel'] !== '';

      $changed = (int) $node->getChangedTime();
      $stale_cutoff = strtotime('-365 days', \Drupal::time()->getRequestTime());
      $is_stale = $changed < $stale_cutoff;

      $issues = [];
      if (!$has_status) {
        $issues[] = (string) $this->t('Missing tool status');
      }
      if (empty($area_labels)) {
        $issues[] = (string) $this->t('Missing area of interest');
      }
      if (empty($category_labels)) {
        $issues[] = (string) $this->t('Missing category');
      }
      if ($hazard_label === '') {
        $issues[] = (string) $this->t('Missing hazard band');
      }
      if ($description === '') {
        $issues[] = (string) $this->t('Missing description');
      }
      if (!$badge_quality['ok']) {
        $issues = array_merge($issues, $badge_quality['issues']);
      }
      if (!$has_docs) {
        $issues[] = (string) $this->t('Missing usage documentation (instructions, manual, or video)');
      }
      if ($is_individual_asset && $model === '') {
        $issues[] = (string) $this->t('Missing model for individual asset');
      }
      if ($is_individual_asset && ($value_amount === NULL || $value_amount <= 0)) {
        $issues[] = (string) $this->t('Missing or invalid value for individual asset');
      }
      if (!$has_image) {
        $issues[] = (string) $this->t('Missing image');
      }
      if (!$has_location) {
        $issues[] = (string) $this->t('Missing location');
      }
      if ($is_reservable && (!$has_min_advance || !$has_max_duration || !$has_email_template)) {
        $issues[] = (string) $this->t('Incomplete reservation profile for reservable tool');
      }
      if (!$has_slack_routing) {
        $issues[] = (string) $this->t('Missing Slack routing');
      }
      if ($is_stale) {
        $issues[] = (string) $this->t('Stale page (> 365 days since update)');
      }

      $has_quality_issues = !empty($issues);
      if (!$this->matchesToolStatusFilter(
        $status_filter,
        $is_published,
        $is_gone,
        $is_reservable,
        empty($primary_badges),
        !$has_docs,
        !$has_slack_routing,
        $is_stale,
        $has_quality_issues
      )) {
        continue;
      }

      $operations = [
        'edit' => [
          'title' => $this->t('Edit tool'),
          'url' => Url::fromRoute('entity.node.edit_form', ['node' => $node->id()]),
        ],
        'view' => [
          'title' => $this->t('View tool'),
          'url' => Url::fromRoute('entity.node.canonical', ['node' => $node->id()]),
        ],
        'badge_report' => [
          'title' => $this->t('Badge Quality Report'),
          'url' => Url::fromRoute('assign_badge_from_quiz.badge_quality_report', [], ['query' => array_filter([
            'badge' => !empty($primary_badges) ? (int) reset($primary_badges)->id() : NULL,
            'status' => 'needs_attention',
          ])]),
        ],
      ];
      if (!empty($primary_badges)) {
        $primary = reset($primary_badges);
        $operations['edit_badge'] = [
          'title' => $this->t('Edit primary badge'),
          'url' => Url::fromRoute('entity.taxonomy_term.edit_form', ['taxonomy_term' => $primary->id()]),
        ];
      }

      $rows[] = [
        'tool' => Link::fromTextAndUrl($node->label(), Url::fromRoute('entity.node.edit_form', ['node' => $node->id()])),
        'published' => [
          'data' => [
            '#markup' => $this->buildPillMarkup($is_published ? 'published' : 'unpublished', (string) $this->t($is_published ? 'Published' : 'Unpublished')),
          ],
        ],
        'status' => $has_status ? $status_label : $this->t('Not set'),
        'area' => !empty($area_labels) ? implode(', ', $area_labels) : $this->t('Not set'),
        'category' => !empty($category_labels) ? implode(', ', $category_labels) : $this->t('Not set'),
        'hazard' => $hazard_label !== '' ? $hazard_label : $this->t('Not set'),
        'description' => $description !== '' ? Unicode::truncate($description, 110, TRUE, TRUE) : $this->t('Not set'),
        'primary_badge' => [
          'data' => [
            '#markup' => !empty($primary_badge_links) ? implode(', ', $primary_badge_links) : (string) $this->t('Not required'),
          ],
        ],
        'badge_quality' => [
          'data' => $this->buildIndicatorWithLink(
            $badge_quality['ok'],
            (string) $this->t($badge_quality['ok'] ? 'Greenlight' : 'Redlight'),
            implode('; ', $badge_quality['issues']),
            Url::fromRoute('assign_badge_from_quiz.badge_quality_report', [], ['query' => array_filter([
              'badge' => !empty($primary_badges) ? (int) reset($primary_badges)->id() : NULL,
              'status' => 'needs_attention',
            ])]),
            (string) $this->t('Open related badge issues')
          ),
        ],
        'documentation' => implode(' / ', [
          $has_instructions ? (string) $this->t('Instructions') : (string) $this->t('No instructions'),
          $has_manuals ? (string) $this->t('Manual') : (string) $this->t('No manual'),
          $has_video ? (string) $this->t('Video') : (string) $this->t('No video'),
        ]),
        'model' => $model !== '' ? $model : ($is_individual_asset ? $this->t('Not set') : $this->t('Grouped item')),
        'value' => $value_amount !== NULL && $value_amount > 0 ? '$' . number_format($value_amount, 2) : ($is_individual_asset ? $this->t('Not set') : $this->t('Grouped item')),
        'image' => $has_image ? $this->t('Yes') : $this->t('No'),
        'location' => $has_location ? (string) $node->get('field_item_location')->value : $this->t('Not set'),
        'reservable' => [
          'data' => [
            '#markup' => Html::escape($reservation_profile_label) . ($is_reservable ? '<div class="abfq-indicator-notes">' . Html::escape($this->formatReservableDetails($has_min_advance, $has_max_duration, $has_email_template)) . '</div>' : ''),
          ],
        ],
        'slack' => [
          'data' => [
            '#markup' => $has_slack_routing
              ? Html::escape($slack_routing['channel']) . '<div class="abfq-indicator-notes">' . Html::escape($slack_routing['source']) . '</div>'
              : (string) $this->t('Not set'),
          ],
        ],
        'last_updated' => [
          'data' => [
            '#markup' => \Drupal::service('date.formatter')->format($changed, 'short') . ($is_stale ? ' ' . $this->buildPillMarkup('inactive', (string) $this->t('Stale')) : ''),
          ],
        ],
        'quality' => [
          'data' => $this->buildIndicatorMarkup(!$has_quality_issues, (string) $this->t(!$has_quality_issues ? 'Greenlight' : 'Redlight'), implode('; ', array_values(array_unique($issues)))),
        ],
        'actions' => [
          'data' => [
            '#type' => 'operations',
            '#links' => $operations,
          ],
        ],
      ];
    }

    return [
      'nav' => $this->buildReportNavigation('tool'),
      'filters' => $this->buildToolStatusFilters($status_filter),
      'focus' => $this->buildFocusSummary([
        $focus_tool_id > 0 ? (string) $this->t('Focused on tool node ID @nid.', ['@nid' => $focus_tool_id]) : '',
        $focus_badge_id > 0 ? (string) $this->t('Filtered to tools linked to badge ID @tid.', ['@tid' => $focus_badge_id]) : '',
      ], 'assign_badge_from_quiz.tool_quality_report', ['status' => $status_filter]),
      'table' => [
        '#type' => 'table',
        '#header' => $header,
        '#rows' => $rows,
        '#empty' => $this->t('No tool pages found for the selected filter.'),
      ],
      '#attached' => [
        'library' => ['core/drupal.dropbutton', 'assign_badge_from_quiz/quality_reports'],
      ],
    ];
  }

  /**
   * Builds the tool AI quality report from item nodes.
   */
  public function toolAiQualityReport(): array {
    $status_filter = (string) (\Drupal::request()->query->get('status') ?? 'needs_attention');
    $allowed_status_filters = ['all', 'needs_attention', 'ready', 'missing_context', 'missing_transcript', 'missing_timestamps', 'manuals_unprocessed', 'feedback_flagged', 'suspect_answers'];
    if (!in_array($status_filter, $allowed_status_filters, TRUE)) {
      $status_filter = 'needs_attention';
    }
    $focus_tool_id = $this->getPositiveQueryInt('tool');

    $header = [
      'tool' => $this->t('Tool'),
      'hazard' => $this->t('Hazard'),
      'ai_context' => $this->t('AI Context'),
      'source_coverage' => $this->t('AI Source Coverage'),
      'feedback' => $this->t('Feedback / Suspect Answers'),
      'quality' => $this->t('AI Quality Status'),
      'actions' => $this->t('Actions'),
    ];

    $rows = [];
    $node_storage = $this->entityTypeManager()->getStorage('node');
    $nids = $node_storage->getQuery()
      ->condition('type', 'item')
      ->sort('title', 'ASC')
      ->accessCheck(TRUE)
      ->execute();

    if (empty($nids)) {
      return [
        'nav' => $this->buildReportNavigation('tool_ai'),
        'filters' => $this->buildToolAiStatusFilters($status_filter),
        'table' => [
          '#type' => 'table',
          '#header' => $header,
          '#rows' => [],
          '#empty' => $this->t('No tool pages found.'),
        ],
        '#attached' => [
          'library' => ['core/drupal.dropbutton', 'assign_badge_from_quiz/quality_reports'],
        ],
      ];
    }

    foreach ($node_storage->loadMultiple($nids) as $node) {
      if ($focus_tool_id > 0 && (int) $node->id() !== $focus_tool_id) {
        continue;
      }

      $hazard_label = $node->hasField('field_item_hazard_band') && !$node->get('field_item_hazard_band')->isEmpty()
        ? trim((string) $node->get('field_item_hazard_band')->value)
        : '';
      $has_ai_context = $node->hasField('field_item_ai_context') && !$node->get('field_item_ai_context')->isEmpty() && trim((string) $node->get('field_item_ai_context')->value) !== '';
      $has_ai_suggestions = $node->hasField('field_item_ai_suggestions') && !$node->get('field_item_ai_suggestions')->isEmpty() && trim((string) $node->get('field_item_ai_suggestions')->value) !== '';

      $instructions_markup = $node->hasField('field_item_instructions') && !$node->get('field_item_instructions')->isEmpty()
        ? (string) ($node->get('field_item_instructions')->value ?? '')
        : '';
      $instruction_anchor_count = $this->countStructuredDocAnchors($instructions_markup);

      $transcript_stats = $this->getToolTranscriptStats($node);
      $manual_stats = $this->getToolManualAiStats($node);
      $feedback_stats = $this->getToolAiFeedbackStats((int) $node->id(), (string) $node->label());

      $issues = [];
      if (!$has_ai_context) {
        $issues[] = (string) $this->t('Missing staff AI context summary');
      }
      if ($instruction_anchor_count <= 0) {
        $issues[] = (string) $this->t('Instructions lack headings that make section links useful');
      }
      if ($transcript_stats['transcripts'] <= 0) {
        $issues[] = (string) $this->t('Missing transcript coverage for AI grounding');
      }
      if ($transcript_stats['timestamps'] <= 0) {
        $issues[] = (string) $this->t('Missing timestamp links for source-first answers');
      }
      if ($manual_stats['total'] > 0 && $manual_stats['with_text'] < $manual_stats['total']) {
        $issues[] = (string) $this->t('One or more manuals are missing extracted text');
      }
      if ($feedback_stats['feedback_count'] > 0) {
        $issues[] = (string) $this->t('Member feedback flags need review');
      }
      if ($feedback_stats['suspect_count'] > 0) {
        $issues[] = (string) $this->t('Suspect chatbot answers detected in logs');
      }

      if (!$this->matchesToolAiStatusFilter(
        $status_filter,
        !$has_ai_context,
        $transcript_stats['transcripts'] <= 0,
        $transcript_stats['timestamps'] <= 0,
        $manual_stats['total'] > 0 && $manual_stats['with_text'] < $manual_stats['total'],
        $feedback_stats['feedback_count'] > 0,
        $feedback_stats['suspect_count'] > 0,
        empty($issues)
      )) {
        continue;
      }

      $source_lines = [];
      $source_lines[] = (string) $this->t('Instruction headings: @count', ['@count' => $instruction_anchor_count]);
      $source_lines[] = (string) $this->t('Transcripts: @count', ['@count' => $transcript_stats['transcripts']]);
      $source_lines[] = (string) $this->t('Tool transcripts: @count | Badge transcripts: @badge', [
        '@count' => $transcript_stats['tool_transcripts'],
        '@badge' => $transcript_stats['badge_transcripts'],
      ]);
      $source_lines[] = (string) $this->t('Timestamp links: @count', ['@count' => $transcript_stats['timestamps']]);
      $source_lines[] = (string) $this->t('Tool timestamps: @count | Badge timestamps: @badge', [
        '@count' => $transcript_stats['tool_timestamps'],
        '@badge' => $transcript_stats['badge_timestamps'],
      ]);
      $source_lines[] = (string) $this->t('Manuals with extracted text: @done/@total', ['@done' => $manual_stats['with_text'], '@total' => $manual_stats['total']]);
      if ($manual_stats['with_summary'] > 0 || $manual_stats['total'] > 0) {
        $source_lines[] = (string) $this->t('Manual AI summaries: @done/@total', ['@done' => $manual_stats['with_summary'], '@total' => $manual_stats['total']]);
      }

      $actions = [
        'edit_tool' => [
          'title' => $this->t('Edit tool'),
          'url' => Url::fromRoute('entity.node.edit_form', ['node' => $node->id()]),
        ],
        'view_tool' => [
          'title' => $this->t('View tool'),
          'url' => Url::fromRoute('entity.node.canonical', ['node' => $node->id()]),
        ],
        'tool_quality' => [
          'title' => $this->t('Tool Quality Report'),
          'url' => Url::fromRoute('assign_badge_from_quiz.tool_quality_report', [], ['query' => ['tool' => $node->id(), 'status' => 'needs_attention']]),
        ],
        'tool_ai_focus' => [
          'title' => $this->t('Focus this tool'),
          'url' => Url::fromRoute('assign_badge_from_quiz.tool_ai_quality_report', [], ['query' => ['tool' => $node->id(), 'status' => $status_filter]]),
        ],
      ];

      $rows[] = [
        'tool' => Link::fromTextAndUrl($node->label(), Url::fromRoute('entity.node.edit_form', ['node' => $node->id()])),
        'hazard' => $hazard_label !== '' ? $hazard_label : $this->t('Not set'),
        'ai_context' => [
          'data' => [
            '#markup' => implode('<br>', [
              Html::escape((string) $this->t('Staff summary: @state', ['@state' => $has_ai_context ? 'Yes' : 'No'])),
              Html::escape((string) $this->t('Learned suggestions: @state', ['@state' => $has_ai_suggestions ? 'Yes' : 'No'])),
            ]),
          ],
        ],
        'source_coverage' => [
          'data' => [
            '#markup' => Html::escape(array_shift($source_lines)) . '<div class="abfq-indicator-notes">' . Html::escape(implode(' | ', $source_lines)) . '</div>',
          ],
        ],
        'feedback' => [
          'data' => [
            '#markup' => $this->buildToolAiFeedbackMarkup($feedback_stats),
          ],
        ],
        'quality' => [
          'data' => $this->buildIndicatorMarkup(empty($issues), (string) $this->t(empty($issues) ? 'Greenlight' : 'Redlight'), implode('; ', $issues)),
        ],
        'actions' => [
          'data' => [
            '#type' => 'operations',
            '#links' => $actions,
          ],
        ],
      ];
    }

    return [
      'nav' => $this->buildReportNavigation('tool_ai'),
      'filters' => $this->buildToolAiStatusFilters($status_filter),
      'focus' => $this->buildFocusSummary([
        $focus_tool_id > 0 ? (string) $this->t('Focused on tool node ID @nid.', ['@nid' => $focus_tool_id]) : '',
      ], 'assign_badge_from_quiz.tool_ai_quality_report', ['status' => $status_filter]),
      'summary' => [
        '#markup' => '<div class="abfq-focus-summary">' . Html::escape((string) $this->t('This report is AI-focused: it highlights grounding coverage, including connected badge transcripts and timestamps, plus manual extraction and user-flagged answers that affect chatbot quality.')) . '</div>',
      ],
      'table' => [
        '#type' => 'table',
        '#header' => $header,
        '#rows' => $rows,
        '#empty' => $this->t('No tool pages found for the selected filter.'),
      ],
      '#attached' => [
        'library' => ['core/drupal.dropbutton', 'assign_badge_from_quiz/quality_reports'],
      ],
    ];
  }

  /**
   * Builds the facilitator setup quality report.
   */
  public function facilitatorSetupReport(): array {
    $status_filter = (string) (\Drupal::request()->query->get('status') ?? 'needs_attention');
    $allowed_status_filters = ['all', 'needs_attention', 'ready', 'no_badges', 'no_schedule_now', 'no_coordinator_profile', 'no_slack'];
    if (!in_array($status_filter, $allowed_status_filters, TRUE)) {
      $status_filter = 'needs_attention';
    }

    $header = [
      'facilitator' => $this->t('Facilitator'),
      'badge_issuer' => $this->t('Can Badge On Things'),
      'issuer_breakdown' => $this->t('Issuer Coverage'),
      'coordinator_profile' => $this->t('Facilitator Profile'),
      'schedule_now' => $this->t('Current/Future Coverage'),
      'schedule_summary' => $this->t('Schedule Summary'),
      'slack_id' => $this->t('Slack ID'),
      'quality' => $this->t('Quality Status'),
      'actions' => $this->t('Actions'),
    ];

    $user_storage = $this->entityTypeManager()->getStorage('user');
    $uids = $user_storage->getQuery()
      ->condition('status', 1)
      ->condition('roles', 'facilitator')
      ->sort('name', 'ASC')
      ->accessCheck(TRUE)
      ->execute();

    if (empty($uids)) {
      return [
        'nav' => $this->buildReportNavigation('facilitator'),
        'filters' => $this->buildFacilitatorStatusFilters($status_filter),
        'table' => [
          '#type' => 'table',
          '#header' => $header,
          '#rows' => [],
          '#empty' => $this->t('No active facilitators found.'),
        ],
        '#attached' => [
          'library' => ['core/drupal.dropbutton', 'assign_badge_from_quiz/quality_reports'],
        ],
      ];
    }

    $rows = [];
    $users = $user_storage->loadMultiple($uids);
    foreach ($users as $user) {
      $main_profile = $this->loadUserProfile($user, 'main');
      $coordinator_profile = $this->loadUserProfile($user, 'coordinator');
      $main_profile_url = $main_profile
        ? Url::fromRoute('entity.profile.edit_form', ['profile' => $main_profile->id()])
        : Url::fromRoute('profile.user_page.single', ['user' => $user->id(), 'profile_type' => 'main']);
      $coordinator_profile_url = $coordinator_profile
        ? Url::fromRoute('entity.profile.edit_form', ['profile' => $coordinator_profile->id()])
        : Url::fromRoute('profile.user_page.single', ['user' => $user->id(), 'profile_type' => 'coordinator']);
      $badge_report_url = Url::fromRoute('assign_badge_from_quiz.badge_quality_report', [], ['query' => [
        'status' => 'active',
        'issuer' => $user->id(),
      ]]);
      $slack_id = $main_profile && $main_profile->hasField('field_member_slack_id_number') && !$main_profile->get('field_member_slack_id_number')->isEmpty()
        ? trim((string) $main_profile->get('field_member_slack_id_number')->value)
        : '';

      $issuer_counts = $this->getFacilitatorBadgeIssuerCounts((int) $user->id());
      $schedule_state = $this->getCoordinatorScheduleState($coordinator_profile);

      $issues = [];
      if ($issuer_counts['total'] <= 0) {
        $issues[] = (string) $this->t('Not listed as badge issuer on any badge');
      }
      if (!$coordinator_profile) {
        $issues[] = (string) $this->t('Missing coordinator/facilitator profile');
      }
      else {
        if ($schedule_state['hidden']) {
          $issues[] = (string) $this->t('Coordinator schedule is hidden');
        }
        if (!$schedule_state['has_any']) {
          $issues[] = (string) $this->t('No facilitator hours configured');
        }
        elseif (!$schedule_state['has_upcoming']) {
          $issues[] = (string) $this->t('No current or upcoming schedule coverage');
        }
      }
      if ($slack_id === '') {
        $issues[] = (string) $this->t('Missing Slack ID on main profile');
      }

      if (!$this->matchesFacilitatorStatusFilter(
        $status_filter,
        empty($issues),
        $issuer_counts['total'] <= 0,
        !$schedule_state['has_upcoming'],
        !$coordinator_profile,
        $slack_id === ''
      )) {
        continue;
      }

      $actions = [
        'edit_user' => [
          'title' => $this->t('Edit user'),
          'url' => Url::fromRoute('entity.user.edit_form', ['user' => $user->id()]),
        ],
        'main_profile' => [
          'title' => $main_profile ? $this->t('Edit main profile') : $this->t('Create main profile'),
          'url' => $main_profile_url,
        ],
        'coordinator_profile' => [
          'title' => $coordinator_profile ? $this->t('Edit coordinator profile') : $this->t('Create coordinator profile'),
          'url' => $coordinator_profile_url,
        ],
      ];

      $rows[] = [
        'facilitator' => Link::fromTextAndUrl($user->getDisplayName(), Url::fromRoute('entity.user.edit_form', ['user' => $user->id()])),
        'badge_issuer' => [
          'data' => $this->buildIndicatorWithLink(
            $issuer_counts['total'] > 0,
            (string) $this->t($issuer_counts['total'] > 0 ? 'Yes' : 'No'),
            (string) $this->t('@count active badge(s)', ['@count' => $issuer_counts['total']]),
            $badge_report_url,
            (string) $this->t('Open filtered badge report')
          ),
        ],
        'issuer_breakdown' => $this->t('Direct: @direct | On-request: @request', [
          '@direct' => $issuer_counts['direct'],
          '@request' => $issuer_counts['on_request'],
        ]),
        'coordinator_profile' => [
          'data' => $this->buildIndicatorWithLink(
            (bool) $coordinator_profile,
            (string) $this->t($coordinator_profile ? 'Present' : 'Missing'),
            '',
            $coordinator_profile_url,
            (string) $this->t($coordinator_profile ? 'Edit coordinator profile' : 'Create coordinator profile')
          ),
        ],
        'schedule_now' => [
          'data' => $this->buildIndicatorWithLink(
            $schedule_state['has_upcoming'],
            (string) $this->t(
              $schedule_state['has_current']
                ? 'Now'
                : ($schedule_state['has_upcoming'] ? 'Upcoming' : 'No')
            ),
            $schedule_state['hidden']
              ? (string) $this->t('Schedule is hidden on coordinator profile')
              : (!$schedule_state['has_any']
                ? (string) $this->t('No facilitator hours configured')
                : (!$schedule_state['has_upcoming'] ? (string) $this->t('Only past schedule entries found') : '')),
            $coordinator_profile_url,
            (string) $this->t('Open coordinator profile')
          ),
        ],
        'schedule_summary' => $schedule_state['summary'] !== '' ? $schedule_state['summary'] : $this->t('Not set'),
        'slack_id' => [
          'data' => $this->buildIndicatorWithLink(
            $slack_id !== '',
            $slack_id !== '' ? $slack_id : (string) $this->t('Missing'),
            $slack_id === '' ? (string) $this->t('Slack ID is stored on the main profile') : '',
            $main_profile_url,
            (string) $this->t($main_profile ? 'Edit main profile' : 'Create main profile')
          ),
        ],
        'quality' => [
          'data' => $this->buildIndicatorMarkup(empty($issues), (string) $this->t(empty($issues) ? 'Greenlight' : 'Redlight'), implode('; ', $issues)),
        ],
        'actions' => [
          'data' => [
            '#type' => 'operations',
            '#links' => $actions,
          ],
        ],
      ];
    }

    return [
      'nav' => $this->buildReportNavigation('facilitator'),
      'filters' => $this->buildFacilitatorStatusFilters($status_filter),
      'table' => [
        '#type' => 'table',
        '#header' => $header,
        '#rows' => $rows,
        '#empty' => $this->t('No facilitators found for the selected filter.'),
      ],
      '#attached' => [
        'library' => ['core/drupal.dropbutton', 'assign_badge_from_quiz/quality_reports'],
      ],
    ];
  }

  /**
   * Builds status filter links for the tool report.
   */
  protected function buildToolStatusFilters(string $active_filter): array {
    $route = 'assign_badge_from_quiz.tool_quality_report';
    $options = [
      'needs_attention' => $this->t('Needs Attention'),
      'published' => $this->t('Published'),
      'all' => $this->t('All'),
      'unpublished' => $this->t('Unpublished'),
      'gone' => $this->t('Gone'),
      'reservable' => $this->t('Reservable'),
      'no_badge' => $this->t('No Badge'),
      'no_docs' => $this->t('No Docs'),
      'no_slack' => $this->t('No Slack'),
      'stale' => $this->t('Stale'),
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
   * Builds status filter links for the tool AI report.
   */
  protected function buildToolAiStatusFilters(string $active_filter): array {
    $route = 'assign_badge_from_quiz.tool_ai_quality_report';
    $options = [
      'needs_attention' => $this->t('Needs Attention'),
      'ready' => $this->t('Ready'),
      'all' => $this->t('All'),
      'missing_context' => $this->t('No AI Summary'),
      'missing_transcript' => $this->t('No Transcript'),
      'missing_timestamps' => $this->t('No Timestamps'),
      'manuals_unprocessed' => $this->t('Manuals Unprocessed'),
      'feedback_flagged' => $this->t('Feedback Flagged'),
      'suspect_answers' => $this->t('Suspect Answers'),
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
   * Builds status filter links for the facilitator report.
   */
  protected function buildFacilitatorStatusFilters(string $active_filter): array {
    $route = 'assign_badge_from_quiz.facilitator_setup_report';
    $options = [
      'needs_attention' => $this->t('Needs Attention'),
      'ready' => $this->t('Ready'),
      'all' => $this->t('All'),
      'no_badges' => $this->t('No Badge Issuer'),
      'no_schedule_now' => $this->t('No Current/Future Coverage'),
      'no_coordinator_profile' => $this->t('No Facilitator Profile'),
      'no_slack' => $this->t('No Slack ID'),
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
   * Returns whether a tool matches the current report filter.
   */
  protected function matchesToolStatusFilter(
    string $filter,
    bool $is_published,
    bool $is_gone,
    bool $is_reservable,
    bool $missing_badge,
    bool $missing_docs,
    bool $missing_slack,
    bool $is_stale,
    bool $has_quality_issues
  ): bool {
    return match ($filter) {
      'published' => $is_published,
      'unpublished' => !$is_published,
      'gone' => $is_gone,
      'reservable' => $is_reservable,
      'no_badge' => $missing_badge,
      'no_docs' => $missing_docs,
      'no_slack' => $missing_slack,
      'stale' => $is_stale,
      'needs_attention' => $is_published && !$is_gone && $has_quality_issues,
      default => TRUE,
    };
  }

  /**
   * Returns whether a tool matches the current AI quality report filter.
   */
  protected function matchesToolAiStatusFilter(
    string $filter,
    bool $missing_context,
    bool $missing_transcript,
    bool $missing_timestamps,
    bool $manuals_unprocessed,
    bool $feedback_flagged,
    bool $suspect_answers,
    bool $is_ready
  ): bool {
    return match ($filter) {
      'ready' => $is_ready,
      'missing_context' => $missing_context,
      'missing_transcript' => $missing_transcript,
      'missing_timestamps' => $missing_timestamps,
      'manuals_unprocessed' => $manuals_unprocessed,
      'feedback_flagged' => $feedback_flagged,
      'suspect_answers' => $suspect_answers,
      'needs_attention' => !$is_ready,
      default => TRUE,
    };
  }

  /**
   * Returns whether a facilitator matches the current report filter.
   */
  protected function matchesFacilitatorStatusFilter(
    string $filter,
    bool $is_ready,
    bool $missing_badges,
    bool $missing_schedule_now,
    bool $missing_coordinator_profile,
    bool $missing_slack
  ): bool {
    return match ($filter) {
      'ready' => $is_ready,
      'no_badges' => $missing_badges,
      'no_schedule_now' => $missing_schedule_now,
      'no_coordinator_profile' => $missing_coordinator_profile,
      'no_slack' => $missing_slack,
      'needs_attention' => !$is_ready,
      default => TRUE,
    };
  }

  /**
   * Loads a single profile for a user/bundle combination.
   */
  protected function loadUserProfile($user, string $bundle) {
    $profiles = $this->entityTypeManager()->getStorage('profile')->loadByUser($user, $bundle);
    if (is_array($profiles)) {
      return reset($profiles) ?: NULL;
    }
    return $profiles ?: NULL;
  }

  /**
   * Counts badges a facilitator can issue.
   */
  protected function getFacilitatorBadgeIssuerCounts(int $uid): array {
    $term_storage = $this->entityTypeManager()->getStorage('taxonomy_term');
    $direct_ids = $term_storage->getQuery()
      ->condition('vid', 'badges')
      ->condition('field_badge_issuer', $uid)
      ->accessCheck(TRUE)
      ->execute();
    $on_request_ids = $term_storage->getQuery()
      ->condition('vid', 'badges')
      ->condition('field_badge_issuer_on_request', $uid)
      ->accessCheck(TRUE)
      ->execute();

    $all_ids = array_unique(array_merge(array_values($direct_ids), array_values($on_request_ids)));
    if ($all_ids === []) {
      return [
        'direct' => 0,
        'on_request' => 0,
        'total' => 0,
      ];
    }

    $terms = $term_storage->loadMultiple($all_ids);
    $direct = 0;
    $on_request = 0;
    $all = [];
    foreach ($terms as $term) {
      if (!$this->isActionableBadgeTerm($term)) {
        continue;
      }
      $tid = (int) $term->id();
      $all[$tid] = TRUE;
      if ($this->isUserReferencedInBadgeIssuerField($term, 'field_badge_issuer', $uid)) {
        $direct++;
      }
      if ($this->isUserReferencedInBadgeIssuerField($term, 'field_badge_issuer_on_request', $uid)) {
        $on_request++;
      }
    }

    return [
      'direct' => $direct,
      'on_request' => $on_request,
      'total' => count($all),
    ];
  }

  /**
   * Returns schedule state for a coordinator profile.
   */
  protected function getCoordinatorScheduleState($profile): array {
    $state = [
      'hidden' => FALSE,
      'has_any' => FALSE,
      'has_current' => FALSE,
      'has_upcoming' => FALSE,
      'summary' => '',
    ];
    if (!$profile) {
      return $state;
    }

    if ($profile->hasField('field_coordinator_hours_display')
      && !$profile->get('field_coordinator_hours_display')->isEmpty()
      && $profile->get('field_coordinator_hours_display')->value === 'hide') {
      $state['hidden'] = TRUE;
    }

    if (!$profile->hasField('field_coordinator_hours') || $profile->get('field_coordinator_hours')->isEmpty()) {
      return $state;
    }

    $slots = $this->getCoordinatorHourSlots($profile);
    if ($slots === []) {
      return $state;
    }

    $state['has_any'] = TRUE;
    $state['summary'] = $this->formatCoordinatorHoursSummary($profile, $slots);

    $now = \Drupal::time()->getRequestTime();
    foreach ($slots as $slot) {
      $start = $slot['start'];
      $end = $slot['end'];
      if ($start > 0 && $end > 0 && $start <= $now && $end >= $now) {
        $state['has_current'] = TRUE;
        $state['has_upcoming'] = TRUE;
      }
      elseif ($end > 0 && $end >= $now) {
        $state['has_upcoming'] = TRUE;
      }
    }

    return $state;
  }

  /**
   * Formats coordinator hours into a compact summary.
   */
  protected function formatCoordinatorHoursSummary($profile, ?array $slots = NULL): string {
    $raw = $slots ?? $this->getCoordinatorHourSlots($profile);
    usort($raw, fn($a, $b) => $a['ts'] <=> $b['ts']);
    $slots = [];
    foreach ($raw as $entry) {
      $start_ts = $entry['ts'] ?? $entry['start'] ?? 0;
      $end_ts = $entry['end'] ?? 0;
      $day = \Drupal::service('date.formatter')->format($start_ts, 'custom', 'D');
      if (isset($slots[$day])) {
        continue;
      }
      $start = \Drupal::service('date.formatter')->format($start_ts, 'custom', 'g:ia');
      $end = $end_ts > $start_ts ? \Drupal::service('date.formatter')->format($end_ts, 'custom', 'g:ia') : '';
      $slots[$day] = $end ? $day . ' ' . $start . '–' . $end : $day . ' ' . $start;
    }

    return implode(' · ', array_values($slots));
  }

  /**
   * Resolves stored coordinator availability occurrence slots.
   */
  protected function getCoordinatorHourSlots($profile): array {
    $slots = [];
    foreach ($profile->get('field_coordinator_hours')->getValue() as $item) {
      $start_ts = isset($item['value']) ? (int) $item['value'] : 0;
      $end_ts = isset($item['end_value']) ? (int) $item['end_value'] : 0;
      if ($start_ts <= 0) {
        continue;
      }

      $slots[] = [
        'ts' => $start_ts,
        'start' => $start_ts,
        'end' => $end_ts,
      ];
    }

    return $slots;
  }

  /**
   * Returns referenced taxonomy terms for a node field.
   */
  protected function getReferencedTerms($node, string $field_name): array {
    if (!$node->hasField($field_name) || $node->get($field_name)->isEmpty()) {
      return [];
    }
    return $node->get($field_name)->referencedEntities();
  }

  /**
   * Merges two term arrays using term id uniqueness.
   */
  protected function mergeTermsById(array $terms_a, array $terms_b): array {
    $merged = [];
    foreach (array_merge($terms_a, $terms_b) as $term) {
      $merged[(int) $term->id()] = $term;
    }
    return array_values($merged);
  }

  /**
   * Builds badge edit links for terms.
   */
  protected function buildBadgeLinksForTerms(array $terms): array {
    $links = [];
    foreach ($terms as $term) {
      $links[] = Link::fromTextAndUrl($term->label(), Url::fromRoute('entity.taxonomy_term.edit_form', ['taxonomy_term' => $term->id()]))->toString();
    }
    return $links;
  }

  /**
   * Evaluates badge quality for a tool node.
   */
  protected function evaluateToolBadgeQuality(array $primary_badges, array $all_badges, array $badge_quality_by_term): array {
    $issues = [];
    if (empty($all_badges)) {
      return [
        'ok' => TRUE,
        'issues' => [(string) $this->t('No badge required')],
      ];
    }

    foreach ($all_badges as $term) {
      $tid = (int) $term->id();
      $badge_quality = $badge_quality_by_term[$tid] ?? ['ok' => FALSE];
      if (!$badge_quality['ok']) {
        $issues[] = (string) $this->t('Linked badge "@badge" has redlight quality', ['@badge' => $term->label()]);
      }
    }

    $issues = array_values(array_unique($issues));
    return [
      'ok' => empty($issues),
      'issues' => !empty($issues) ? $issues : [(string) $this->t('All linked badges pass quality checks')],
    ];
  }

  /**
   * Counts heading-like structures that can support useful section links.
   */
  protected function countStructuredDocAnchors(string $markup): int {
    if (trim($markup) === '') {
      return 0;
    }

    preg_match_all('/<(h2|h3|h4)\b/i', $markup, $matches);
    return count($matches[0] ?? []);
  }

  /**
   * Returns transcript and timestamp coverage for a tool.
   */
  protected function getToolTranscriptStats($node): array {
    $tool_transcripts = 0;
    $tool_timestamps = 0;
    $badge_transcripts = 0;
    $badge_timestamps = 0;

    if ($node->hasField('field_item_videos') && !$node->get('field_item_videos')->isEmpty()) {
      foreach ($node->get('field_item_videos')->referencedEntities() as $paragraph) {
        if ($paragraph->bundle() !== 'tool_video') {
          continue;
        }
        if ($paragraph->hasField('field_video_transcript') && !$paragraph->get('field_video_transcript')->isEmpty() && trim((string) $paragraph->get('field_video_transcript')->value) !== '') {
          $tool_transcripts++;
        }
        if ($paragraph->hasField('field_video_timestamps') && !$paragraph->get('field_video_timestamps')->isEmpty()) {
          $tool_timestamps += count($paragraph->get('field_video_timestamps')->getValue());
        }
      }
    }

    foreach (['field_member_badges', 'field_additional_badges'] as $field_name) {
      if (!$node->hasField($field_name) || $node->get($field_name)->isEmpty()) {
        continue;
      }
      foreach ($node->get($field_name)->referencedEntities() as $term) {
        if ($term->hasField('field_badge_video_transcript') && !$term->get('field_badge_video_transcript')->isEmpty() && trim((string) $term->get('field_badge_video_transcript')->value) !== '') {
          $badge_transcripts++;
        }
        if ($term->hasField('field_badge_video_timestamps') && !$term->get('field_badge_video_timestamps')->isEmpty()) {
          $badge_timestamps += count($term->get('field_badge_video_timestamps')->getValue());
        }
      }
    }

    return [
      'tool_transcripts' => $tool_transcripts,
      'tool_timestamps' => $tool_timestamps,
      'badge_transcripts' => $badge_transcripts,
      'badge_timestamps' => $badge_timestamps,
      'transcripts' => $tool_transcripts + $badge_transcripts,
      'timestamps' => $tool_timestamps + $badge_timestamps,
    ];
  }

  /**
   * Returns manual extraction and summary coverage for a tool.
   */
  protected function getToolManualAiStats($node): array {
    $stats = [
      'total' => 0,
      'with_text' => 0,
      'with_summary' => 0,
    ];

    if (!$node->hasField('field_manuals') || $node->get('field_manuals')->isEmpty()) {
      return $stats;
    }

    foreach ($node->get('field_manuals') as $item) {
      $file = $item->entity;
      if (!$file) {
        continue;
      }
      $stats['total']++;
      if ($file->hasField('field_manual_extracted_text') && !$file->get('field_manual_extracted_text')->isEmpty() && trim((string) $file->get('field_manual_extracted_text')->value) !== '') {
        $stats['with_text']++;
      }
      if ($file->hasField('field_manual_ai_summary') && !$file->get('field_manual_ai_summary')->isEmpty() && trim((string) $file->get('field_manual_ai_summary')->value) !== '') {
        $stats['with_summary']++;
      }
    }

    return $stats;
  }

  /**
   * Returns explicit feedback and heuristic suspect-answer counts for a tool.
   */
  protected function getToolAiFeedbackStats(int $nid, string $tool_name): array {
    $feedback_count = 0;
    $top_categories = [];
    $recent_feedback = [];
    if ($this->database->schema()->tableExists('makerspace_ai_chat_feedback')) {
      $feedback_rows = $this->database->select('makerspace_ai_chat_feedback', 'f')
        ->fields('f', ['category', 'question_excerpt', 'answer_excerpt', 'user_note', 'created'])
        ->condition('nid', $nid)
        ->orderBy('created', 'DESC')
        ->execute()
        ->fetchAll();
      $feedback_count = count($feedback_rows);
      if (!empty($feedback_rows)) {
        $counts = array_count_values(array_map(static fn($row): string => strtoupper((string) $row->category), $feedback_rows));
        arsort($counts);
        $top_categories = array_slice(array_keys($counts), 0, 3);
        foreach (array_slice($feedback_rows, 0, 3) as $row) {
          $question = trim((string) ($row->question_excerpt ?? ''));
          $answer = trim((string) ($row->answer_excerpt ?? ''));
          $note = trim((string) ($row->user_note ?? ''));
          if ($question === '' && $answer === '' && $note === '') {
            continue;
          }
          $recent_feedback[] = [
            'category' => strtoupper((string) $row->category),
            'question' => $question,
            'answer' => $answer,
            'note' => $note,
          ];
        }
      }
    }

    $suspect_count = 0;
    $recent_suspects = [];
    $safe_name = $this->database->escapeLike($tool_name);
    $rows = $this->database->select('ai_log', 'l')
      ->fields('l', ['prompt', 'output_text'])
      ->condition('extra_data', '%"Tool: ' . $safe_name . '%', 'LIKE')
      ->orderBy('created', 'DESC')
      ->range(0, 80)
      ->execute()
      ->fetchAll();

    foreach ($rows as $row) {
      $question = trim(preg_replace('/^user\n?/i', '', (string) ($row->prompt ?? '')));
      $answer = $this->extractAiLogAnswerText((string) ($row->output_text ?? ''));
      if ($question === '' || $answer === '') {
        continue;
      }
      if ($this->isSuspectToolAnswer($question, $answer)) {
        $suspect_count++;
        if (count($recent_suspects) < 3) {
          $recent_suspects[] = [
            'question' => $question,
            'answer' => $answer,
          ];
        }
      }
    }

    return [
      'feedback_count' => $feedback_count,
      'top_categories' => $top_categories,
      'recent_feedback' => $recent_feedback,
      'suspect_count' => $suspect_count,
      'recent_suspects' => $recent_suspects,
    ];
  }

  /**
   * Builds formatted markup for the tool AI feedback report cell.
   */
  protected function buildToolAiFeedbackMarkup(array $feedback_stats): string {
    $summary_parts = [
      Html::escape((string) $this->t('Flags: @count', ['@count' => $feedback_stats['feedback_count'] ?? 0])),
      Html::escape((string) $this->t('Suspect answers: @count', ['@count' => $feedback_stats['suspect_count'] ?? 0])),
    ];

    if (empty($feedback_stats['top_categories']) && empty($feedback_stats['recent_feedback']) && empty($feedback_stats['recent_suspects'])) {
      return implode('<br>', $summary_parts);
    }

    $details = [];
    if (!empty($feedback_stats['top_categories'])) {
      $details[] = '<div><strong>' . Html::escape((string) $this->t('Categories')) . ':</strong> ' . Html::escape(implode(', ', $feedback_stats['top_categories'])) . '</div>';
    }
    if (!empty($feedback_stats['recent_feedback'])) {
      $items = array_map(function (array $item): string {
        $parts = [];
        $parts[] = '<div><strong>' . Html::escape((string) $this->t('Category')) . ':</strong> ' . Html::escape($item['category'] ?? '') . '</div>';
        if (!empty($item['question'])) {
          $parts[] = '<div><strong>' . Html::escape((string) $this->t('Question')) . ':</strong> ' . Html::escape($item['question']) . '</div>';
        }
        if (!empty($item['answer'])) {
          $parts[] = '<div><strong>' . Html::escape((string) $this->t('AI answer')) . ':</strong> ' . Html::escape($item['answer']) . '</div>';
        }
        if (!empty($item['note'])) {
          $parts[] = '<div><strong>' . Html::escape((string) $this->t('Member note')) . ':</strong> ' . Html::escape($item['note']) . '</div>';
        }
        return '<li>' . implode('', $parts) . '</li>';
      }, $feedback_stats['recent_feedback']);
      $details[] = '<div><strong>' . Html::escape((string) $this->t('Recent feedback')) . ':</strong><ul class="abfq-compact-list">' . implode('', $items) . '</ul></div>';
    }
    if (!empty($feedback_stats['recent_suspects'])) {
      $items = array_map(function (array $item): string {
        $parts = [];
        if (!empty($item['question'])) {
          $parts[] = '<div><strong>' . Html::escape((string) $this->t('Question')) . ':</strong> ' . Html::escape($item['question']) . '</div>';
        }
        if (!empty($item['answer'])) {
          $parts[] = '<div><strong>' . Html::escape((string) $this->t('AI answer')) . ':</strong> ' . Html::escape($item['answer']) . '</div>';
        }
        return '<li>' . implode('', $parts) . '</li>';
      }, $feedback_stats['recent_suspects']);
      $details[] = '<div><strong>' . Html::escape((string) $this->t('Recent suspect topics')) . ':</strong><ul class="abfq-compact-list">' . implode('', $items) . '</ul></div>';
    }

    return '<div>' . implode('<br>', $summary_parts) . '</div>'
      . '<details class="abfq-details"><summary>' . Html::escape((string) $this->t('View details')) . '</summary>'
      . '<div class="abfq-indicator-notes">' . implode('', $details) . '</div></details>';
  }

  /**
   * Extracts assistant reply text from ai_log output_text.
   */
  protected function extractAiLogAnswerText(string $output_text): string {
    if ($output_text === '') {
      return '';
    }

    $decoded = json_decode($output_text, TRUE);
    if (is_array($decoded)) {
      $content = $decoded['choices'][0]['message']['content'] ?? NULL;
      if (is_string($content) && $content !== '') {
        return trim($content);
      }
    }

    if (strlen($output_text) < 5000 && !str_starts_with(trim($output_text), '{')) {
      return trim($output_text);
    }

    return '';
  }

  /**
   * Returns TRUE when a tool answer looks likely to need staff review.
   */
  protected function isSuspectToolAnswer(string $question, string $answer): bool {
    $q = mb_strtolower($question);
    $a = mb_strtolower($answer);

    $settings_question = preg_match('/\b(ppi|speed|power|rpm|psi|settings?|temperature|feed|feeds|mylar|plywood|acrylic|vector|raster)\b/i', $question) === 1;
    $numeric_answer = preg_match('/\b\d+(?:\.\d+)?\s*(?:%|ppi|rpm|psi|watt|watts|in|inch|mm|°|deg)?\b/i', $answer) === 1;
    if ($settings_question && $numeric_answer) {
      return TRUE;
    }

    if (str_contains($q, 'where did you get') || str_contains($q, 'made it up') || str_contains($q, 'double check') || str_contains($q, 'not finding')) {
      return TRUE;
    }

    if ((str_contains($q, 'help me replace') || str_contains($q, 'replace the lens') || str_contains($q, 'take apart'))
      && preg_match('/\b1\.|\b2\.|\b3\.|turn off|unscrew|remove|install|reattach/i', $answer) === 1) {
      return TRUE;
    }

    if ((str_contains($a, "i don't know how to answer that yet") || str_contains($a, 'ask in #'))
      && (str_contains($q, 'the table says') || str_contains($q, 'double check') || str_contains($q, 'where did you get'))) {
      return TRUE;
    }

    return FALSE;
  }

  /**
   * Returns referenced entity labels for a node field.
   */
  protected function getReferencedEntityLabels($node, string $field_name): array {
    $labels = [];
    if (!$node->hasField($field_name) || $node->get($field_name)->isEmpty()) {
      return [];
    }
    foreach ($node->get($field_name)->referencedEntities() as $entity) {
      $label = trim((string) $entity->label());
      if ($label !== '') {
        $labels[] = $label;
      }
    }
    return array_values(array_unique($labels));
  }

  /**
   * Returns selected reservable machine values for a tool.
   */
  protected function getItemReservableValues($node): array {
    if (!$node->hasField('field_item_reservable') || $node->get('field_item_reservable')->isEmpty()) {
      return [];
    }
    $values = [];
    foreach ($node->get('field_item_reservable')->getValue() as $item) {
      $value = (string) ($item['value'] ?? '');
      if ($value !== '') {
        $values[] = $value;
      }
    }
    return array_values(array_unique($values));
  }

  /**
   * Maps reservable machine values to labels.
   */
  protected function mapReservableLabels(array $values): array {
    $map = [
      'member' => (string) $this->t('Member Self-Serve'),
      'business' => (string) $this->t('Business Accounts Only'),
      'staff' => (string) $this->t('Staff Only'),
      'reservable' => (string) $this->t('Legacy'),
    ];
    $labels = [];
    foreach ($values as $value) {
      $labels[] = $map[$value] ?? $value;
    }
    return $labels;
  }

  /**
   * Formats reservable detail flags.
   */
  protected function formatReservableDetails(bool $has_min_advance, bool $has_max_duration, bool $has_email_template): string {
    $parts = [];
    $parts[] = (string) $this->t('Min advance: @value', ['@value' => $has_min_advance ? 'Yes' : 'No']);
    $parts[] = (string) $this->t('Max duration: @value', ['@value' => $has_max_duration ? 'Yes' : 'No']);
    $parts[] = (string) $this->t('Email template: @value', ['@value' => $has_email_template ? 'Yes' : 'No']);
    return implode(' | ', $parts);
  }

  /**
   * Resolves tool Slack routing using item and area fallback values.
   */
  protected function resolveToolSlackRouting($node): array {
    $channel = '';
    $source = '';

    if ($node->hasField('field_item_slack_channel') && !$node->get('field_item_slack_channel')->isEmpty()) {
      $item_channel = trim((string) $node->get('field_item_slack_channel')->value);
      if ($item_channel !== '') {
        $channel = $item_channel;
        $source = (string) $this->t('Item field');
      }
    }

    if ($channel === '' && $node->hasField('field_item_area_interest') && !$node->get('field_item_area_interest')->isEmpty()) {
      foreach ($node->get('field_item_area_interest')->referencedEntities() as $term) {
        if ($term->hasField('field_interest_slack_channel') && !$term->get('field_interest_slack_channel')->isEmpty()) {
          $term_channel = trim((string) $term->get('field_interest_slack_channel')->value);
          if ($term_channel !== '') {
            $channel = $term_channel;
            $source = (string) $this->t('Area interest fallback');
            break;
          }
        }
      }
    }

    return [
      'channel' => $channel,
      'source' => $source,
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

  /**
   * Returns TRUE when a badge should count toward actionable quality rollups.
   */
  protected function isActionableBadgeTerm($term): bool {
    $is_published = method_exists($term, 'isPublished') ? (bool) $term->isPublished() : TRUE;
    $is_inactive = $term->hasField('field_badge_inactive') && (bool) $term->get('field_badge_inactive')->value;
    $is_unlisted = $term->hasField('field_badge_unlisted') && (bool) $term->get('field_badge_unlisted')->value;
    return $is_published && !$is_inactive && !$is_unlisted;
  }

  /**
   * Returns TRUE when the user is referenced on either badge issuer field.
   */
  protected function isUserReferencedInBadgeIssuerFields($term, int $uid): bool {
    return $this->isUserReferencedInBadgeIssuerField($term, 'field_badge_issuer', $uid)
      || $this->isUserReferencedInBadgeIssuerField($term, 'field_badge_issuer_on_request', $uid);
  }

  /**
   * Returns TRUE when the user is referenced on a specific badge issuer field.
   */
  protected function isUserReferencedInBadgeIssuerField($term, string $field_name, int $uid): bool {
    if (!$term->hasField($field_name) || $term->get($field_name)->isEmpty()) {
      return FALSE;
    }

    foreach ($term->get($field_name)->getValue() as $item) {
      if ((int) ($item['target_id'] ?? 0) === $uid) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Returns TRUE when a tool status label means the tool is effectively gone.
   */
  protected function isGoneToolStatusLabel(string $status_label): bool {
    $normalized = mb_strtolower(trim($status_label));
    return in_array($normalized, ['gone', 'retired', 'missing'], TRUE);
  }

  /**
   * Returns TRUE when the item looks like an individual asset.
   */
  protected function isIndividualAsset($node, array $category_labels): bool {
    if ($node->hasField('field_item_set') && !$node->get('field_item_set')->isEmpty()) {
      return FALSE;
    }

    foreach ($category_labels as $label) {
      $normalized = mb_strtolower(trim($label));
      if (in_array($normalized, ['room', 'rooms', 'zone', 'zones', 'area', 'areas', 'set', 'sets'], TRUE)) {
        return FALSE;
      }
    }

    return TRUE;
  }

  /**
   * Returns TRUE when the provided term id exists in a referenced term list.
   */
  protected function termIdExistsInArray(int $tid, array $terms): bool {
    foreach ($terms as $term) {
      if ((int) $term->id() === $tid) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
