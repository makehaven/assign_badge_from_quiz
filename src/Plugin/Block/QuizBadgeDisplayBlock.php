<?php

namespace Drupal\assign_badge_from_quiz\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\taxonomy\Entity\Term;
use Drupal\quiz\Entity\QuizResult;
use Drupal\Core\Render\Markup;
use Drupal\Core\Routing\RouteMatchInterface;
use Psr\Log\LoggerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a 'Post Badge Quiz Display' block.
 *
 * @Block(
 *   id = "post_badge_quiz_display",
 *   admin_label = @Translation("Post Badge Quiz Display")
 * )
 */
class QuizBadgeDisplayBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The logger channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The current route match.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routeMatch;

  /**
   * Constructs a new QuizBadgeDisplayBlock.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, LoggerInterface $logger, RouteMatchInterface $route_match) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->logger = $logger;
    $this->routeMatch = $route_match;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('logger.factory')->get('assign_badge_from_quiz'),
      $container->get('current_route_match')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $build = [];
    // Ensure the block rebuilds per route.
    $build['#cache']['contexts'][] = 'route';

    // Get the quiz result parameter.
    $result = $this->routeMatch->getParameter('quiz_result');
    if (!$result) {
      $result = $this->routeMatch->getParameter('result');
    }

    // Determine the QuizResult entity.
    if ($result instanceof QuizResult) {
      $quiz_result = $result;
    }
    elseif (is_numeric($result)) {
      $quiz_result = QuizResult::load($result);
    }
    else {
      return $build;
    }

    if (!$quiz_result) {
      return $build;
    }

    $score = $quiz_result->get('score')->value;
    $quiz_id = $quiz_result->get('qid')->target_id;

    if ($score == 100) {
      // Query for a matching badge term.
      $term_ids = \Drupal::entityQuery('taxonomy_term')
        ->condition('vid', 'badges')
        ->condition('field_badge_quiz_reference.target_id', $quiz_id)
        ->accessCheck(FALSE)
        ->range(0, 1)
        ->execute();

      if (!empty($term_ids)) {
        $badge_terms = Term::loadMultiple($term_ids);
        $badge_term = reset($badge_terms);
        $badge_name = $badge_term->getName();

        $message = '<div style="border:2px solid #4CAF50; border-radius:10px; padding:15px; background-color:#f9f9f9;">'
          . '<h2 style="color:#4CAF50;">Congratulations!</h2>'
          . '<p>You have completed the quiz with a perfect score! You have earned the badge: <strong>' . htmlspecialchars($badge_name) . '</strong>.</p>'
          . '</div>';
        $build['badge_message'] = [
          '#markup' => Markup::create($message),
        ];

        $build['badge_details'] = \Drupal::entityTypeManager()
          ->getViewBuilder('taxonomy_term')
          ->view($badge_term, 'checkout_availability');
      }
    }
    else {
      // Build a message and a button link to retake the quiz.
      $quiz_url = '';
      if (method_exists($quiz_result, 'getQuiz')) {
        $quiz = $quiz_result->getQuiz();
        $quiz_url = $quiz->toUrl('canonical', ['absolute' => TRUE])->toString();
      }
      $message = '<div style="padding:15px;">'
        . '<p>You did not pass the quiz.</p>'
        . '<a href="' . $quiz_url . '" class="btn btn-primary" style="font-size:1rem; padding:10px 20px; border-radius:5px;">Take the quiz again</a>'
        . '</div>';
      $build['fail_message'] = [
        '#markup' => Markup::create($message),
      ];
    }

    return $build;
  }
}
