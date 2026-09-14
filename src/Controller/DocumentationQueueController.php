<?php

namespace Drupal\assign_badge_from_quiz\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Url;
use Drupal\assign_badge_from_quiz\Service\DocumentationNotifier;
use Drupal\assign_badge_from_quiz\Service\PendingDocumentationFinder;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Staff queue of documentation-badge submissions awaiting approval.
 *
 * One place to see everything still waiting, so an approval can't quietly hide
 * in an inbox. Each row carries the same signed one-click Approve / Reject
 * links that the notification email uses.
 */
class DocumentationQueueController extends ControllerBase {

  /**
   * Constructs the documentation queue controller.
   */
  public function __construct(
    protected PendingDocumentationFinder $finder,
    protected DocumentationNotifier $notifier,
    protected DateFormatterInterface $dateFormatter,
    protected TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('assign_badge_from_quiz.pending_documentation_finder'),
      $container->get('assign_badge_from_quiz.documentation_notifier'),
      $container->get('date.formatter'),
      $container->get('datetime.time'),
    );
  }

  /**
   * Render the pending-approval queue.
   */
  public function queue(): array {
    $pending = $this->finder->findPending();

    $rows = [];
    foreach ($pending as $item) {
      $action_links = [];
      // "Open submission" first, then the signed one-click actions.
      if ($item['submission_url']) {
        $action_links[] = [
          'title' => $this->t('Open / review'),
          'url' => Url::fromUri($item['submission_url']),
        ];
      }
      foreach ($this->notifier->buildActionLinks($item['sid']) as $link) {
        $action_links[] = [
          'title' => $link['label'],
          'url' => Url::fromUri($link['url']),
        ];
      }

      // Member cell: linked name plus email.
      $member = $item['member_name'];
      if ($item['uid'] > 0) {
        $member = [
          '#type' => 'link',
          '#title' => $item['member_name'],
          '#url' => Url::fromRoute('entity.user.canonical', ['user' => $item['uid']]),
        ];
      }

      $age_days = (int) floor(($this->time->getRequestTime() - $item['created']) / 86400);
      $submitted = [
        '#markup' => $this->t('@ago ago', [
          '@ago' => $this->dateFormatter->formatTimeDiffSince($item['created']),
        ]),
      ];

      $rows[] = [
        'data' => [
          'member' => ['data' => $member],
          'email' => $item['member_email'],
          'badge' => $item['badge_name'],
          'submitted' => ['data' => $submitted],
          'actions' => [
            'data' => [
              '#type' => 'operations',
              '#links' => array_combine(
                array_map(fn($i) => 'a' . $i, array_keys($action_links)),
                $action_links,
              ),
            ],
          ],
        ],
        // Flag anything older than a week so it stands out.
        'class' => $age_days >= 7 ? ['color-warning'] : [],
      ];
    }

    $build = [];
    $build['summary'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->formatPlural(
        count($pending),
        '<strong>1</strong> documentation submission is waiting for your approval.',
        '<strong>@count</strong> documentation submissions are waiting for your approval.',
      ),
    ];
    $build['scope'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Not listed: members who already hold the badge, and members registered for a class that awards it. The class stands in for this form and the instructor\'s class checkout issues the badge; classes still owing badges are on the <a href=":url">Education console</a>.', [
        ':url' => '/admin/education#badges-owed',
      ]),
    ];
    $build['table'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Member'),
        $this->t('Email'),
        $this->t('Badge'),
        $this->t('Submitted'),
        $this->t('Actions'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('Nothing is waiting — every documentation submission has been approved or rejected. 🎉'),
      '#attributes' => ['class' => ['assign-badge-doc-queue']],
    ];
    // Reflect live submission state on every view.
    $build['#cache']['max-age'] = 0;

    return $build;
  }

}
