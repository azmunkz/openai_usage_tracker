<?php

namespace Drupal\openai_usage_tracker\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\user\Entity\User;
use Drupal\Core\Database\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;

class FilterForm extends FormBase {

  protected $database;

  public function __construct(Connection $database) {
    $this->database = $database;
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database')
    );
  }

  public function getFormId() {
    return 'openai_usage_filter_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    // Fetch unique modules & models
    $modules = $this->getDistinctValues('module');
    $models = $this->getDistinctValues('model');

    // User list
    $users = ['' => '- Any -'];
    $user_query = $this->database->select('openai_usage_log', 'l')
      ->fields('l', ['user_id'])
      ->distinct()
      ->execute();
    foreach ($user_query as $row) {
      $account = User::load($row->user_id);
      if ($account) {
        $users[$row->user_id] = $account->getDisplayName();
      }
    }

    // Filter Fields
    $form['user_id'] = [
      '#type' => 'select',
      '#title' => $this->t('User'),
      '#options' => $users,
      '#default_value' => \Drupal::request()->query->get('user_id'),
    ];

    $form['module'] = [
      '#type' => 'select',
      '#title' => $this->t('Module'),
      '#options' => ['' => '- Any -'] + array_combine($modules, $modules),
      '#default_value' => \Drupal::request()->query->get('module'),
    ];

    $form['model'] = [
      '#type' => 'select',
      '#title' => $this->t('Model'),
      '#options' => ['' => '- Any -'] + array_combine($models, $models),
      '#default_value' => \Drupal::request()->query->get('model'),
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Filter'),
    ];

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $params = [];
    foreach (['user_id', 'module', 'model'] as $field) {
      $val = $form_state->getValue($field);
      if (!empty($val)) {
        $params[$field] = $val;
      }
    }

    $form_state->setRedirect('openai_usage_tracker.dashboard', [], ['query' => $params]);
  }

  private function getDistinctValues($field) {
    $query = $this->database->select('openai_usage_log', 'l')
      ->fields('l', [$field])
      ->distinct()
      ->execute();
    return array_column(iterator_to_array($query), $field);
  }

}
