<?php

namespace Drupal\openai_usage_tracker\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Session\AccountProxyInterface;

class OpenAIUsageLogger {

  protected $database;
  protected $currentUser;

  public function __construct(Connection $database, AccountProxyInterface $current_user) {
    $this->database = $database;
    $this->currentUser = $current_user;
  }

  /**
   * Log usage to openai_usage_log table.
   *
   * @param array $data
   *   Associative array with:
   *   - module
   *   - model
   *   - endpoint
   *   - prompt_tokens
   *   - completion_tokens
   *   - total_tokens
   *   - cost
   *   - user_id (optional, fallback to current user)
   */
  public function log(array $data) {
    $this->database->insert('openai_usage_log')->fields([
      'module' => $data['module'] ?? 'unknown',
      'user_id' => $data['user_id'] ?? $this->currentUser->id(),
      'model' => $data['model'] ?? '',
      'endpoint' => $data['endpoint'] ?? '',
      'prompt_tokens' => $data['prompt_tokens'] ?? 0,
      'completion_tokens' => $data['completion_tokens'] ?? 0,
      'total_tokens' => $data['total_tokens'] ?? 0,
      'cost' => $data['cost'] ?? 0,
      'content_type' => $options['content_type'] ?? NULL,
      'node_id' => $options['node_id'] ?? NULL,
      'title' => $options['title'] ?? NULL,
      'created' => \Drupal::time()->getCurrentTime(),
    ])->execute();
  }
}
