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
    $daily_data = $this->getDailyTokenData($filters);
    $endpoint_stats = $this->getEndpointStats($filters);
    $model_stats = $this->getModelStats($filters);
    $module_stats = $this->getModuleStats($filters);
    $summary = $this->getSummary($filters);

    $alert_message = NULL;

    if ($summary['cost'] >= 0.01) {
      $alert_message = [
        'level' => 'danger',
        'text' => '⚠️ You have exceeded USD10.00 in OpenAI usage. Please monitor your tokens and budget.',
      ];
    } elseif ($summary['cost'] >= 5) {
      $alert_message = [
        'level' => 'warning',
        'text' => '⚠️ Your usage is above USD5.00. Monitor usage to avoid over-budget.',
      ];
    }

    return [
      '#theme' => 'openai_usage_dashboard',
      '#form' => $this->formBuilder()->getForm(FilterForm::class),
      '#summary' => $summary,
      '#daily_chart_data' => [
        'labels' => array_keys($daily_data),
        'tokens' => array_values($daily_data),
      ],
      '#endpoint_stats' => $endpoint_stats,
      '#model_stats' => $model_stats,
      '#module_stats' => $module_stats,
      '#alert_message' => $alert_message,
      '#table' => [
        '#type' => 'table',
        '#header' => $header,
        '#rows' => $rows,
        '#empty' => $this->t('No usage data found.'),
      ],
      '#attached' => [

        'library' => ['openai_usage_tracker/chart', 'openai_usage_tracker/bootstrap'],
        'drupalSettings' => [
          'openaiUsageChart' => [
            'labels' => array_keys($daily_data),
            'tokens' => array_values($daily_data),
          ],
        ],
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

  private function getDailyTokenData(array $filters) {
    $query = $this->database->select('openai_usage_log', 'log')
      ->fields('log', ['created', 'total_tokens']);

    foreach ($filters as $field => $value) {
      if (!empty($value)) {
        $query->condition($field, $value);
      }
    }

    $results = $query->execute();

    $daily = [];

    foreach ($results as $row) {
      $date = date('Y-m-d', $row->created);
      $daily[$date] = ($daily[$date] ?? 0) + $row->total_tokens;
    }

    ksort($daily); // Sort by date

    return $daily;
  }

  private function getEndpointStats(array $filters) {
    $query = $this->database->select('openai_usage_log', 'log')
      ->fields('log', ['endpoint', 'total_tokens', 'cost']);

    foreach ($filters as $field => $value) {
      if (!empty($value)) {
        $query->condition($field, $value);
      }
    }

    $results = $query->execute();

    $stats = [];

    foreach ($results as $row) {
      $endpoint = $row->endpoint;
      if (!isset($stats[$endpoint])) {
        $stats[$endpoint] = [
          'tokens' => 0,
          'cost' => 0.0,
          'requests' => 0,
        ];
      }

      $stats[$endpoint]['tokens'] += $row->total_tokens;
      $stats[$endpoint]['cost'] += $row->cost;
      $stats[$endpoint]['requests'] += 1;
    }

    return $stats;
  }

  private function getModelStats(array $filters) {
    $query = $this->database->select('openai_usage_log', 'log')
      ->fields('log', ['model', 'prompt_tokens', 'completion_tokens', 'total_tokens', 'cost']);

    foreach ($filters as $field => $value) {
      if (!empty($value)) {
        $query->condition($field, $value);
      }
    }

    $results = $query->execute();
    $stats = [];

    foreach ($results as $row) {
      $model = $row->model;
      if (!isset($stats[$model])) {
        $stats[$model] = [
          'requests' => 0,
          'prompt' => 0,
          'completion' => 0,
          'total_tokens' => 0,
          'cost' => 0.0,
        ];
      }

      $stats[$model]['requests'] += 1;
      $stats[$model]['prompt'] += $row->prompt_tokens;
      $stats[$model]['completion'] += $row->completion_tokens;
      $stats[$model]['total_tokens'] += $row->total_tokens;
      $stats[$model]['cost'] += $row->cost;
    }

    return $stats;
  }

  private function getModuleStats(array $filters) {
    $query = $this->database->select('openai_usage_log', 'log')
      ->fields('log', ['module', 'prompt_tokens', 'completion_tokens', 'total_tokens', 'cost']);

    foreach ($filters as $field => $value) {
      if (!empty($value)) {
        $query->condition($field, $value);
      }
    }

    $results = $query->execute();
    $stats = [];

    foreach ($results as $row) {
      $module = $row->module;
      if (!isset($stats[$module])) {
        $stats[$module] = [
          'requests' => 0,
          'prompt' => 0,
          'completion' => 0,
          'total_tokens' => 0,
          'cost' => 0.0,
        ];
      }

      $stats[$module]['requests'] += 1;
      $stats[$module]['prompt'] += $row->prompt_tokens;
      $stats[$module]['completion'] += $row->completion_tokens;
      $stats[$module]['total_tokens'] += $row->total_tokens;
      $stats[$module]['cost'] += $row->cost;
    }

    return $stats;
  }
  public function exportCsv() {
    $filters = [
      'user_id' => $this->request->query->get('user_id'),
      'module' => $this->request->query->get('module'),
      'model' => $this->request->query->get('model'),
    ];

    $query = $this->database->select('openai_usage_log', 'log')
      ->fields('log')
      ->orderBy('created', 'DESC');

    foreach ($filters as $field => $value) {
      if (!empty($value)) {
        $query->condition("log.$field", $value);
      }
    }

    $results = $query->execute();
    $rows = [];

    foreach ($results as $row) {
      $rows[] = [
        $row->id,
        $row->module,
        $row->user_id,
        $row->model,
        $row->endpoint,
        $row->prompt_tokens,
        $row->completion_tokens,
        $row->total_tokens,
        $row->cost,
        $row->content_type ?? '',
        $row->node_id ?? '',
        $row->title ?? '',
        date('Y-m-d H:i:s', $row->created),
      ];
    }

    $header = [
      'ID', 'Module', 'User ID', 'Model', 'Endpoint',
      'Prompt Tokens', 'Completion Tokens', 'Total Tokens',
      'Cost', 'Content Type', 'Node ID', 'Title', 'Created',
    ];

    $filename = 'openai-usage-' . date('Y-m-d') . '.csv';
    $csv = fopen('php://temp', 'r+');
    fputcsv($csv, $header);
    foreach ($rows as $line) {
      fputcsv($csv, $line);
    }
    rewind($csv);
    $contents = stream_get_contents($csv);
    fclose($csv);

    return new \Symfony\Component\HttpFoundation\Response(
      $contents,
      200,
      [
        'Content-Type' => 'text/csv',
        'Content-Disposition' => 'attachment; filename="' . $filename . '"',
      ]
    );
  }

}
