<?php

namespace Drupal\assign_badge_from_quiz\Plugin\Block;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\assign_badge_from_quiz\Service\PendingDocumentationFinder;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Dashboard tile showing how many documentation approvals are waiting.
 *
 * Place on a staff dashboard so the pending count is visible at a glance and
 * links straight to the queue — a persistent surface that does not depend on
 * anyone noticing the notification email.
 *
 * @Block(
 *   id = "assign_badge_from_quiz_pending_docs_tile",
 *   admin_label = @Translation("Pending documentation approvals (tile)"),
 *   category = @Translation("Makerspace")
 * )
 */
class PendingDocumentationTileBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs the pending-documentation tile block.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected PendingDocumentationFinder $finder,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('assign_badge_from_quiz.pending_documentation_finder'),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function blockAccess(AccountInterface $account): AccessResult {
    return AccessResult::allowedIfHasPermission($account, 'view assign badge from quiz reports');
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $count = $this->finder->countPending();

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mh-tile', 'mh-tile--doc-approvals']],
      '#cache' => ['max-age' => 0],
    ];
    $build['count'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#value' => (string) $count,
      '#attributes' => ['class' => ['mh-tile__count']],
    ];
    $build['label'] = [
      '#type' => 'link',
      '#title' => $this->formatPlural(
        $count,
        'documentation approval waiting',
        'documentation approvals waiting',
      ),
      '#url' => Url::fromRoute('assign_badge_from_quiz.documentation_queue'),
    ];

    return $build;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge(): int {
    return 0;
  }

}
