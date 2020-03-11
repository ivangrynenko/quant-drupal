<?php

namespace Drupal\quant_api\EventSubscriber;

use Drupal\quant\Event\QuantEvent;
use Drupal\quant\Event\QuantFileEvent;
use Drupal\quant_api\Client\QuantClientInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Integrate with the QuantAPI to store static assets.
 */
class QuantApi implements EventSubscriberInterface {

  /**
   * The HTTP client to make API requests.
   *
   * @var \Drupal\quant_api\Client\QuantClientInterface;
   */
  protected $client;

  /**
   * QuantAPI event subcsriber.
   *
   * Listens to Quant events and triggers requests to the configured
   * API endpoint for different operations.
   *
   * @param \Drupal\quant_api\Client\QuantClientInterface $client
   *   The Drupal HTTP Client to make requests.
   */
  public function __construct(QuantClientInterface $client) {
    $this->client = $client;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events[QuantEvent::OUTPUT][] = ['onOutput'];
    $events[QuantFileEvent::OUTPUT][] = ['onMedia'];
    return $events;
  }

  /**
   * Trigger an API request with the event data.
   *
   * @param Drupal\quant\Event\QuantEvent $event
   *   The event.
   */
  public function onOutput(QuantEvent $event) {

    $path = $event->getLocation();
    $content = $event->getContents();
    $rid = $event->getRevisionId();
    $meta = $event->getMetadata();

    $data = [
      'content' => $content,
      'url' => $path,
      'revision' => $rid,
      'published' => $meta['published'],
      'transitions' => $meta['transitions'],
    ];

    $res = $this->client->send($data);

    // @todo: Obviously make this less ridiculous.
    $media = array_merge($res['attachments']['js'], $res['attachments']['css'], $res['attachments']['media']['images'], $res['attachments']['media']['documents']);

    foreach ($media as $file) {
      // @todo: Determine local vs. remote.
      // @todo: Configurable to disallow remote files.
      // @todo: Strip base domain.

      // Ignore anything that isn't relative for now.
      if (substr($file, 0, 1) != "/") {
        continue;
      }

      // Strip query params.
      $file = strtok($file, '?');

      //$public = \Drupal::service('file_system')->realpath(file_default_scheme() . "://");
      if (file_exists(DRUPAL_ROOT . $file)) {
        \Drupal::service('event_dispatcher')->dispatch(QuantFileEvent::OUTPUT, new QuantFileEvent(DRUPAL_ROOT . $file, $file));
      }
    }
  }


  /**
   * Trigger an API push with event data for file.
   *
   * @param Drupal\quant\Event\QuantFileEvent $event
   *   The file event.
   */
  public function onMedia(QuantFileEvent $event) {
    $file = $event->getFilePath();
    $url = $event->getUrl();
    $rid = $event->getRevisionId();

    // @todo: Support revision id.
    $res = $this->client->sendfile($file, $url);
  }

}
