<?php

namespace Drupal\assign_badge_from_quiz;

use Drupal\Core\Render\Markup;
use Drupal\Core\Url;
use Drupal\quiz\Entity\QuizResult;
use Drupal\taxonomy\Entity\Term;
use Drupal\views\Views;
use Psr\Log\LoggerInterface;

/**
 * Service for building the custom display for quiz results.
 */
class QuizResultDisplayBuilder {


/**
   * The logger channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

/**
   * Constructs a new QuizResultDisplayBuilder object.
   *
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   * The logger factory.
   */
  public function __construct(\Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory) {
    $this->logger = $logger_factory->get('assign_badge_from_quiz');
  }

  /**
   * Builds the entire render array for the quiz result.
   */
  public function build(QuizResult $quiz_result) {
    $score = $quiz_result->get('score')->value;

    if ($score == 100) {
      return $this->buildSuccessOutput($quiz_result);
    }
    else {
      return $this->buildFailureOutput($quiz_result);
    }
  }

  /**
   * Builds the render array for a passing score.
   */
  protected function buildSuccessOutput(QuizResult $quiz_result) {
    $build = [];
    $quiz_id = $quiz_result->get('qid')->target_id;

    $term_ids = \Drupal::entityQuery('taxonomy_term')
      ->condition('vid', 'badges')
      ->condition('field_badge_quiz_reference.target_id', $quiz_id)
      ->accessCheck(FALSE)
      ->range(0, 1)
      ->execute();

    if (empty($term_ids)) {
      return [];
    }

    $badge_term = Term::load(reset($term_ids));
    $badge_name = $badge_term->getName();
    $badge_url = Url::fromRoute('entity.taxonomy_term.canonical', ['taxonomy_term' => $badge_term->id()])->toString();
    $checkout_requirement = $badge_term->hasField('field_badge_checkout_requirement') ? $badge_term->get('field_badge_checkout_requirement')->value : 'no';

    $build['#prefix'] = '<div class="post-quiz-results-wrapper" style="border:2px solid #4CAF50; border-radius:10px; padding:20px; background-color:#f9f9f9;">';
    $build['#suffix'] = '</div>';

    $build['congrats_message'] = ['#markup' => Markup::create('<h2 style="color:#4CAF50;">Congratulations! You passed the quiz!</h2>')];

    switch ($checkout_requirement) {
      case 'yes':
      case 'class':
        $build['next_steps_intro'] = ['#markup' => Markup::create('<p>The next step is to complete a practical checkout with a facilitator to earn the <strong>' . htmlspecialchars($badge_name) . '</strong> badge.</p><hr>')];
        $build['badge_details'] = $this->buildBadgeDetails($badge_term);
        $build['facilitator_heading'] = ['#markup' => Markup::create('<h3>Schedule Your Checkout</h3>')];
        $build['facilitator_schedule'] = $this->renderFacilitatorView($badge_term->id());
        break;

      default:
        $build['badge_earned'] = ['#markup' => Markup::create('<p>You have earned the <strong>' . htmlspecialchars($badge_name) . '</strong> badge and can now use the associated tools.</p><hr>')];
        $build['badge_details'] = $this->buildBadgeDetails($badge_term);
        break;
    }

    // Combined the button logic into a single element for clarity.
    $build['action_buttons'] = [
      '#markup' => Markup::create(
        '<div style="margin-top: 25px;">' .
        // Your styled "Return to Badge" button
        '<a href="' . $badge_url . '" class="btn btn-light" style="margin-right: 10px;">Return to ' . htmlspecialchars($badge_name) . ' Page</a>' .
        // The new "View Equipment List" button
        '<a href="/equipment" class="btn btn-secondary">View Equipment List</a>' .
        '</div>'
      ),
      '#weight' => 100,
    ];

    return $build;
  }

  protected function buildBadgeDetails(Term $badge_term) {
      $details = [];
      if ($badge_term->hasField('field_badge_checklist') && !$badge_term->get('field_badge_checklist')->isEmpty()) {
          $checklist_url = $badge_term->get('field_badge_checklist')->first()->getUrl()->toString();
          $details['checklist'] = ['#markup' => Markup::create('<a href="' . $checklist_url . '" class="btn btn-info" target="_blank" style="margin-right: 10px;">View Checkout Checklist</a>')];
      }
      if ($badge_term->hasField('field_badge_checkout_minutes') && !$badge_term->get('field_badge_checkout_minutes')->isEmpty()) {
          $minutes = $badge_term->get('field_badge_checkout_minutes')->value;
          $details['minutes'] = ['#markup' => Markup::create("<p style=\"display: inline-block; margin-left: 15px;\"><strong>Estimated time:</strong> {$minutes} minutes</p>")];
      }
      return $details;
  }

  protected function renderFacilitatorView($badge_tid) {
    $view = Views::getView('facilitator_schedules');
    if (!$view) {
      $this->logger->warning('The "facilitator_schedules" view could not be loaded.');
      return [];
    }
    $view->setDisplay('facilitator_schedule_tool_eva');
    $view->setArguments([$badge_tid]);
    $view->execute();
    if (count($view->result) > 0) {
      return $view->buildRenderable();
    }
    else {
      $message = '<div class="alert alert-warning"><h4>No Facilitators Currently Available</h4><p>There are no facilitators scheduled for this checkout in the near future. Please check back later or ask for assistance in the relevant Slack channel.</p></div>';
      return ['#markup' => Markup::create($message)];
    }
  }

  protected function buildFailureOutput(QuizResult $quiz_result) {
    $quiz_url = '';
    if (method_exists($quiz_result, 'getQuiz')) {
      $quiz = $quiz_result->getQuiz();
      $quiz_url = $quiz->toUrl('canonical', ['absolute' => TRUE])->toString();
    }
    $message = '<div style="border:2px solid #D9534F; border-radius:10px; padding:15px; background-color:#f9f9f9;">'
             . '<h2>You did not pass the quiz.</h2>'
             . '<p>A perfect score of 100% is required to proceed. Please review the material and try again.</p>'
             . '<a href="' . $quiz_url . '" class="btn btn-primary" style="font-size:1rem; padding:10px 20px; border-radius:5px;">Take the quiz again</a>'
             . '</div>';
    return ['#markup' => Markup::create($message)];
  }

}