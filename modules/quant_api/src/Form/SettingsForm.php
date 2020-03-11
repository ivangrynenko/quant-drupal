<?php

namespace Drupal\quant_api\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\quant_api\Client\QuantClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Quant configuration form.
 *
 * @see Drupal\Core\Form\ConfigFormBase
 */
class SettingsForm extends ConfigFormBase {

  const SETTINGS = 'quant_api.settings';

  /**
   * Build the form.
   */
  public function __construct(QuantClientInterface $client) {
    $this->client = $client;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('quant_api.client')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'quant_api_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function getEditableConfigNames() {
    return [
      self::SETTINGS,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config(self::SETTINGS);

    if ($config->get('api_token')) {
      if ($this->client->ping()) {
        $form['api_status'] = [
          '#markup' => $this->t('Successfully connected to the API'),
        ];
      }
      else {
        $form['api_status'] = [
          '#markup' => $this->t('Cannot connect to the API'),
        ];
      }
    }

    $form['api_endpoint'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API Endpoint'),
      '#description' => $this->t('e.g: http://api.quantcdn.io'),
      '#default_value' => $config->get('api_endpoint'),
    ];

    $form['api_token'] = [
      '#type' => 'password',
      '#title' => $this->t('API Token'),
      '#default_value' => $config->get('api_token'),
    ];

    // @TODO QUANT API CONFIGURATION...

    return parent::buildForm($form, $form_state);
  }


  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Retrieve the configuration.
    $this->configFactory->getEditable(self::SETTINGS)
      ->set('api_endpoint', $form_state->getValue('api_endpoint'))
      ->set('api_token', $form_state->getValue('api_token'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
