<?php

namespace Drupal\assign_badge_from_quiz\Controller;

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
   * Constructs a new QuizQualityReportController object.
   */
  public function __construct(Connection $database) {
    $this->database = $database;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database')
    );
  }

  /**
   * Generates the report.
   */
  public function report() {
    $header = [
      'title' => $this->t('Quiz Title'),
      'questions' => $this->t('Questions'),
      'feedback' => $this->t('With Feedback'),
      'quality' => $this->t('Quality Status'),
      'actions' => $this->t('Actions'),
    ];

    $rows = [];

    // Query to get quizzes and their question stats.
    $query = "
      SELECT 
        q.qid, 
        q.title,
        COUNT(qqr.question_id) as total_questions,
        SUM(CASE WHEN (qq.feedback__value IS NOT NULL AND qq.feedback__value != '') THEN 1 ELSE 0 END) as feedback_count
      FROM {quiz} q
      LEFT JOIN {quiz_question_relationship} qqr ON q.qid = qqr.quiz_id
      LEFT JOIN {quiz_question} qq ON qqr.question_id = qq.qqid
      WHERE q.type = 'badge_quiz'
      GROUP BY q.qid, q.title
      ORDER BY q.title ASC
    ";

    $results = $this->database->query($query)->fetchAll();

    foreach ($results as $result) {
      $quality_issues = [];
      if ($result->total_questions < 3) {
        $quality_issues[] = $this->t('Low question count (< 3)');
      }
      if ($result->feedback_count < $result->total_questions) {
        $quality_issues[] = $this->t('Missing feedback (@count)', ['@count' => $result->total_questions - $result->feedback_count]);
      }

      $status = empty($quality_issues) ? '✅ OK' : '⚠️ ' . implode(', ', $quality_issues);

      $rows[] = [
        'title' => Link::fromTextAndUrl($result->title, Url::fromRoute('entity.quiz.canonical', ['quiz' => $result->qid])),
        // Make the question count a link directly to the management page.
        'questions' => Link::fromTextAndUrl($result->total_questions, Url::fromRoute('quiz.questions', ['quiz' => $result->qid])),
        'feedback' => $result->feedback_count,
        'quality' => $status,
        'actions' => [
          'data' => [
            '#type' => 'operations',
            '#links' => [
              'manage' => [
                'title' => $this->t('Manage Questions'),
                'url' => Url::fromRoute('quiz.questions', ['quiz' => $result->qid]),
                'weight' => -10, // Ensure this is first.
              ],
              'edit' => [
                'title' => $this->t('Edit Quiz'),
                'url' => Url::fromRoute('entity.quiz.edit_form', ['quiz' => $result->qid]),
              ],
            ],
          ],
        ],
      ];
    }

    return [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('No badge quizzes found.'),
      '#attached' => [
        'library' => ['core/drupal.dropbutton'],
      ],
    ];
  }

}
