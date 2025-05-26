<?php

namespace Drupal\openai_usage_tracker\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\user\Entity\User;
use Symfony\Component\DependencyInjection\ContainerInterface;

class UsageDashboardController extends ControllerBase {

  protected $database;

  public function __construct(Connection $database) {
    $this->database = $database;
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database')
    );
  }

  public function view() {
    $query = $this->database->select('openai_usage_log', 'log')
      ->fields('log')
      ->orderBy('created', 'DESC')
      ->range(0, 50);
    $results = $query->execute()->fetchAll();

    $rows = [];
    foreach ($results as $row) {
      $user = User::load($row->user_id);
      $rows[] = [
        $row->id,
        $row->module,
        $user ? $user->getDisplayName() : 'Anonymous',
        $row->model,
        $row->endpoint,
        $row->prompt_tokens,
        $row->completion_tokens,
        $row->total_tokens,
        number_format($row->cost, 4),
        \Drupal::service('date.formatter')->format($row->created, 'short'),
      ];
    }

    $header = [
      'ID', 'Module', 'User', 'Model', 'Endpoint',
      'Prompt', 'Completion', 'Total', 'Cost (USD)', 'Timestamp'
    ];

    return [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('No usage data found.'),
    ];
  }
}
