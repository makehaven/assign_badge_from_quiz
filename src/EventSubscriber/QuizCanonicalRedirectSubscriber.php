<?php

namespace Drupal\assign_badge_from_quiz\EventSubscriber;

use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\quiz\Entity\Quiz;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Redirects quiz canonical pages to the direct take route when appropriate.
 */
class QuizCanonicalRedirectSubscriber implements EventSubscriberInterface {

  /**
   * Constructs the subscriber.
   */
  public function __construct(
    protected readonly AccountProxyInterface $currentUser,
  ) {}

  /**
   * Redirects canonical quiz pages to the direct take page.
   */
  public function onRequest(RequestEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }

    $request = $event->getRequest();
    if (!in_array($request->getMethod(), ['GET', 'HEAD'], TRUE)) {
      return;
    }

    if ($request->attributes->get('_route') !== 'entity.quiz.canonical') {
      return;
    }

    $quiz = $request->attributes->get('quiz');
    if (!$quiz instanceof Quiz) {
      return;
    }

    $access = $quiz->access('take', $this->currentUser, TRUE);
    if (!$access->isAllowed()) {
      return;
    }

    $response = new RedirectResponse(Url::fromRoute('entity.quiz.take', [
      'quiz' => $quiz->id(),
    ], [
      'query' => $request->query->all(),
    ])->toString(), 302);
    $response->headers->set('Cache-Control', 'no-cache, private');
    $event->setResponse($response);
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::REQUEST => ['onRequest', 30],
    ];
  }

}
