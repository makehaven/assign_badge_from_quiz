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
      $quiz_links = $this->buildLinkedQuizLinks($term);
      $quiz_quality_for_badge = $this->evaluateBadgeLinkedQuizQuality($term, $quiz_quality_map);

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
          'url' => Url::fromRoute('assign_badge_from_quiz.quality_report'),
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
          'data' => $this->buildIndicatorMarkup($quiz_quality_for_badge['ok'], (string) $this->t($quiz_quality_for_badge['ok'] ? 'Greenlight' : 'Redlight'), implode('; ', $quiz_quality_for_badge['issues'])),
        ],
        'checkout_requirement' => $this->getCheckoutRequirementLabel($checkout_requirement),
        'checkout_minutes' => $checkout_minutes === NULL ? $this->t('Not set') : $checkout_minutes,
        'checklist' => $has_checklist ? $this->t('Yes') : $this->t('No'),
        'internal_checklist' => $has_internal_checklist ? $this->t('Yes') : $this->t('No'),
        'training_documentation' => $has_training_doc ? $this->t('Yes') : $this->t('No'),
        'video' => $has_video ? $this->t('Yes') : $this->t('No'),
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
    $tool_link = Link::fromTextAndUrl($this->t('Tool Quality Report'), Url::fromRoute('assign_badge_from_quiz.tool_quality_report'))->toString();

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
   * Builds the tool quality report from item nodes.
   */
  public function toolQualityReport(): array {
    $status_filter = (string) (\Drupal::request()->query->get('status') ?? 'all');
    $allowed_status_filters = ['all', 'published', 'unpublished', 'reservable', 'no_badge', 'no_docs', 'no_slack', 'stale', 'needs_attention'];
    if (!in_array($status_filter, $allowed_status_filters, TRUE)) {
      $status_filter = 'all';
    }

    $header = [
      'tool' => $this->t('Tool'),
      'published' => $this->t('Published'),
      'status' => $this->t('Status'),
      'primary_badge' => $this->t('Primary Badge'),
      'badge_quality' => $this->t('Badge Quality'),
      'instructions' => $this->t('Instructions'),
      'manuals' => $this->t('Manuals'),
      'video' => $this->t('Video'),
      'image' => $this->t('Image'),
      'location' => $this->t('Location'),
      'contact' => $this->t('Question Contact'),
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
      $is_published = method_exists($node, 'isPublished') ? (bool) $node->isPublished() : TRUE;
      $status_label = $node->hasField('field_item_status') && !$node->get('field_item_status')->isEmpty()
        ? (string) $node->get('field_item_status')->entity?->label()
        : '';
      $has_status = $status_label !== '';

      $primary_badges = $this->getReferencedTerms($node, 'field_member_badges');
      $additional_badges = $this->getReferencedTerms($node, 'field_additional_badges');
      $all_badges = $this->mergeTermsById($primary_badges, $additional_badges);
      $primary_badge_links = $this->buildBadgeLinksForTerms($primary_badges);

      $badge_quality = $this->evaluateToolBadgeQuality($primary_badges, $all_badges, $badge_quality_by_term);

      $has_instructions = $node->hasField('field_item_instructions') && !$node->get('field_item_instructions')->isEmpty() && trim((string) $node->get('field_item_instructions')->value) !== '';
      $has_manuals = $node->hasField('field_manuals') && !$node->get('field_manuals')->isEmpty();
      $has_video = $node->hasField('field_item_instructional_video') && !$node->get('field_item_instructional_video')->isEmpty();
      $has_docs = $has_instructions && ($has_manuals || $has_video);

      $has_image = $node->hasField('field_item_image') && !$node->get('field_item_image')->isEmpty();
      $has_location = $node->hasField('field_item_location') && !$node->get('field_item_location')->isEmpty() && trim((string) $node->get('field_item_location')->value) !== '';
      $has_contact = $node->hasField('field_question_contact') && !$node->get('field_question_contact')->isEmpty() && trim((string) $node->get('field_question_contact')->value) !== '';

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
      if (!$badge_quality['ok']) {
        $issues = array_merge($issues, $badge_quality['issues']);
      }
      if (!$has_docs) {
        $issues[] = (string) $this->t('Missing core documentation (instructions + manual or video)');
      }
      if (!$has_image) {
        $issues[] = (string) $this->t('Missing image');
      }
      if (!$has_location) {
        $issues[] = (string) $this->t('Missing location');
      }
      if (!$has_contact) {
        $issues[] = (string) $this->t('Missing question contact');
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
          'url' => Url::fromRoute('assign_badge_from_quiz.badge_quality_report'),
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
        'primary_badge' => [
          'data' => [
            '#markup' => !empty($primary_badge_links) ? implode(', ', $primary_badge_links) : (string) $this->t('Not set'),
          ],
        ],
        'badge_quality' => [
          'data' => $this->buildIndicatorMarkup($badge_quality['ok'], (string) $this->t($badge_quality['ok'] ? 'Greenlight' : 'Redlight'), implode('; ', $badge_quality['issues'])),
        ],
        'instructions' => $has_instructions ? $this->t('Yes') : $this->t('No'),
        'manuals' => $has_manuals ? $this->t('Yes') : $this->t('No'),
        'video' => $has_video ? $this->t('Yes') : $this->t('No'),
        'image' => $has_image ? $this->t('Yes') : $this->t('No'),
        'location' => $has_location ? (string) $node->get('field_item_location')->value : $this->t('Not set'),
        'contact' => $has_contact ? (string) $node->get('field_question_contact')->value : $this->t('Not set'),
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
   * Builds status filter links for the tool report.
   */
  protected function buildToolStatusFilters(string $active_filter): array {
    $route = 'assign_badge_from_quiz.tool_quality_report';
    $options = [
      'all' => $this->t('All'),
      'needs_attention' => $this->t('Needs Attention'),
      'published' => $this->t('Published'),
      'unpublished' => $this->t('Unpublished'),
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
   * Returns whether a tool matches the current report filter.
   */
  protected function matchesToolStatusFilter(
    string $filter,
    bool $is_published,
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
      'reservable' => $is_reservable,
      'no_badge' => $missing_badge,
      'no_docs' => $missing_docs,
      'no_slack' => $missing_slack,
      'stale' => $is_stale,
      'needs_attention' => $has_quality_issues,
      default => TRUE,
    };
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
    if (empty($primary_badges)) {
      $issues[] = (string) $this->t('Missing required badge');
    }
    if (empty($all_badges)) {
      return [
        'ok' => empty($issues),
        'issues' => $issues,
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

}
