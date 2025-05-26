<?php

namespace Drupal\openai_usage_tracker\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\user\Entity\User;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\openai_usage_tracker\Form\FilterForm;

class UsageDashboardController extends ControllerBase {

  protected $database;
  protected $request;

  public function __construct(Connection $database, RequestStack $request_stack) {
    $this->database = $database;
    $this->request = $request_stack->getCurrentRequest();
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('request_stack')
    );
  }

  public function view() {
    $query = $this->database->select('openai_usage_log', 'log')
      ->fields('log')
      ->orderBy('created', 'DESC')
      ->range(0, 100);

    // Get filters from URL
    $filters = [
      'user_id' => $this->request->query->get('user_id'),
      'module' => $this->request->query->get('module'),
      'model' => $this->request->query->get('model'),
    ];

    foreach ($filters as $field => $value) {
      if (!empty($value)) {
        $query->condition("log.$field", $value);
      }
    }

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

    $summary = $this->getSummary($filters);

    return [
      'form' => $this->formBuilder()->getForm(FilterForm::class),
      'summary' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['usage-summary', 'mb-4']],
        'cost' => [
          '#markup' => '<div><strong>Total Spend:</strong> $' . number_format($summary['cost'], 4) . '</div>',
        ],
        'tokens' => [
          '#markup' => '<div><strong>Total Tokens:</strong> ' . number_format($summary['tokens']) . '</div>',
        ],
        'requests' => [
          '#markup' => '<div><strong>Total Requests:</strong> ' . $summary['requests'] . '</div>',
        ],
      ],
      'table' => [
        '#type' => 'table',
        '#header' => $header,
        '#rows' => $rows,
        '#empty' => $this->t('No usage data found.'),
        '#attributes' => ['class' => ['usage-log-table']],
      ],
    ];

  }

  private function getSummary(array $filters) {
    $query = $this->database->select('openai_usage_log', 'log')
      ->fields('log', ['total_tokens', 'cost']);

    // Apply filters
    foreach ($filters as $field => $value) {
      if (!empty($value)) {
        $query->condition($field, $value);
      }
    }

    $results = $query->execute();

    $total_tokens = 0;
    $total_cost = 0.0;
    $total_requests = 0;

    foreach ($results as $row) {
      $total_tokens += $row->total_tokens;
      $total_cost += $row->cost;
      $total_requests++;
    }

    return [
      'tokens' => $total_tokens,
      'cost' => $total_cost,
      'requests' => $total_requests,
    ];
  }


}
