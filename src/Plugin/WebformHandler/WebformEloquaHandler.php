<?php

namespace Drupal\webform_eloqua\Plugin\WebformHandler;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\eloqua_api_redux\Service\Forms as EloquaFormsService;
use Drupal\webform\Plugin\WebformHandlerBase;
use Drupal\webform\WebformSubmissionConditionsValidatorInterface;
use Drupal\webform\WebformSubmissionInterface;
use Drupal\webform\WebformTokenManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Webform submission post handler.
 *
 * @WebformHandler(
 *   id = "webform_eloqua",
 *   label = @Translation("Eloqua"),
 *   category = @Translation("Webform"),
 *   description = @Translation("Post Webform data to Eloqua."),
 *   cardinality = Drupal\webform\Plugin\WebformHandlerInterface::CARDINALITY_UNLIMITED,
 *   results = Drupal\webform\Plugin\WebformHandlerInterface::RESULTS_PROCESSED,
 * )
 */
class WebformEloquaHandler extends WebformHandlerBase {

  /**
   * Array of available Eloqua forms.
   *
   * @var array
   */
  protected $eloquaForms;

  /**
   * The selected form id.
   *
   * @var array
   */
  protected $eloquaFormId;

  /**
   * Array of Eloqua fields.
   *
   * @var array
   */
  protected $eloquaFields;

  /**
   * Eloqua Forms Service.
   *
   * @var \Drupal\eloqua_api_redux\Service\Forms
   */
  protected $eloquaFormsService;

  /**
   * The token manager.
   *
   * @var \Drupal\webform\WebformTokenManagerInterface
   */
  protected $tokenManager;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration,
                              $plugin_id,
                              $plugin_definition,
                              LoggerChannelFactoryInterface $logger_factory,
                              ConfigFactoryInterface $config_factory,
                              EntityTypeManagerInterface $entity_type_manager,
                              WebformSubmissionConditionsValidatorInterface $conditions_validator,
                              EloquaFormsService $eloqua_forms_service,
                              WebformTokenManagerInterface $token_manager) {
    parent::__construct(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $logger_factory,
      $config_factory,
      $entity_type_manager,
      $conditions_validator);

    $this->tokenManager = $token_manager;
    $this->eloquaFormsService = $eloqua_forms_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('logger.factory'),
      $container->get('config.factory'),
      $container->get('entity_type.manager'),
      $container->get('webform_submission.conditions_validator'),
      $container->get('eloqua_api_redux.forms'),
      $container->get('webform.token_manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'eloqua_form_id' => '',
      'eloqua_field_mapping' => [],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    // General Eloqua settings.
    $form['eloqua'] = [
      '#type' => 'details',
      '#title' => $this->t('Eloqua settings'),
      '#open' => TRUE,
    ];

    $options = $this->getEloquaForms();
    $form['eloqua']['eloqua_form_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Eloqua Form'),
      '#required' => TRUE,
      '#options' => $options,
      '#empty_option' => $this->t('- Select an Eloqua form -'),
      '#empty_value' => '',
      '#default_value' => $this->configuration['eloqua_form_id'],
      '#ajax' => [
        'callback' => [$this, 'ajaxCallback'],
        'progress' => [
          'type' => 'throbber',
          'message' => t('Loading fields...'),
        ],
        'wrapper' => 'field-mapping-wrapper'
      ],
      '#parents' => ['settings', 'eloqua_form_id']
    ];

    // Eloqua field mapping:
    //  Source  -> destination
    //  webform -> eloqua field
    $destination_options = [];

    // If we have a form id selected, load all its fields to populate the
    // destination options.
    $eloqua_form_id = $form_state->getValue('eloqua_form_id') ?:
      $this->configuration['eloqua_form_id'];
    $this->eloquaFormId = $eloqua_form_id;

    if (!empty($eloqua_form_id)) {
      $eloqua_fields = $this->getEloquaFields($eloqua_form_id);

      foreach ($eloqua_fields as $key => $eloqua_field) {
        $label = $eloqua_field['name'];

        // Add an indicator to the label if the field is required.
        if (isset($eloqua_field['isRequired']) && $eloqua_field['isRequired'] == TRUE) {
          $label .= ' (*)';
        }

        $destination_options[$key] = $label;
      }

      // Sort Eloqua fields alphabetically.
      asort($destination_options);
    }

    // Add a wrapper around both default and user webform field mapping for
    // AJAX loading of fields.
    $form['field_mapping'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'field-mapping-wrapper'
      ],
    ];

    // Default webform fields
    $form['field_mapping']['default'] = [
      '#type' => 'details',
      '#title' => $this->t('Default Webform Field Mapping'),
      '#states' => [
        'visible' => [
          ':input[name="eloqua[eloqua_form_id]"]' => ['!value' => ''],
        ],
      ],
    ];

    // Load all standard webform submission fields. These are properties that
    // are available for all webforms.
    $source_options = [];
    $default_webform_fields = $this->getDefaultWebformSubmissionFields();
    foreach ($default_webform_fields as $key => $webform_field) {
      $source_options[$key] = $webform_field['title'] ?: $key;
    }

    $form['field_mapping']['default']['eloqua_field_mapping'] = [
      '#type' => 'webform_mapping',
      '#required' => FALSE,
      '#source' => $source_options,
      '#default_value' => $this->configuration['eloqua_field_mapping'],
      '#source__title' => $this->t('Webform element'),
      '#destination__type' => 'select',
      '#destination' => $destination_options,
      '#destination__title' => $this->t('Eloqua Field'),
      '#destination__description' => NULL,
      '#parents' => ['settings', 'default_eloqua_field_mapping']
    ];

    // User generate webform elements
    $form['field_mapping']['user'] = [
      '#type' => 'details',
      '#title' => $this->t('User Field Mapping'),
      '#states' => [
        'visible' => [
          ':input[name="eloqua[eloqua_form_id]"]' => ['!value' => ''],
        ],
      ],
    ];

    // Load all user generated webform elements. These elements are specific to
    // the webform to which this plugin is attached.
    $source_options = [];
    /** @var \Drupal\webform\Plugin\WebformElementInterface[] $webform_elements */
    $webform_elements = $this->webform->getElementsInitializedFlattenedAndHasValue();
    foreach ($webform_elements as $key => $element) {
      $source_options[$key] = $element['#admin_title'] ?: $element['#title'] ?: $key;
    }

    $form['field_mapping']['user']['eloqua_field_mapping'] = [
      '#type' => 'webform_mapping',
      '#required' => FALSE,
      '#source' => $source_options,
      '#default_value' => $this->configuration['eloqua_field_mapping'],
      '#source__title' => $this->t('Webform element'),
      '#destination__type' => 'select',
      '#destination' => $destination_options,
      '#destination__title' => $this->t('Eloqua Field'),
      '#destination__description' => NULL,
      '#parents' => ['settings', 'user_eloqua_field_mapping']
    ];

    return $form;
  }

  /**
   * Ajax callback.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   *
   * @return
   *   The form element represented by the 'wrapper' attribute in the AJAX
   *   callback settings.
   */
  public function ajaxCallback(array $form, FormStateInterface $form_state) {
    return $form['settings']['field_mapping'];
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    $eloqua_form_id = $form_state->getValue('eloqua_form_id');
    $default_field_mapping = $form_state->getValue('default_eloqua_field_mapping');
    $user_field_mapping = $form_state->getValue('user_eloqua_field_mapping');


    // Assert that all required Eloqua fields are mapped.
    $required_eloqua_fields = [];
    $required_eloqua_fields_submitted = [];

    // Load all required Eloqua fields.
    $eloqua_fields = $this->getEloquaFields($eloqua_form_id);
    foreach ($eloqua_fields as $eloqua_field) {
      if (isset($eloqua_field['isRequired']) && $eloqua_field['isRequired'] == TRUE) {
        $required_eloqua_fields[$eloqua_field['id']] = $eloqua_field['name'];
      }
    }

    // Check each field mapping for default webform fields.
    foreach ($default_field_mapping as $webform_element_id => $eloqua_field_id) {
      // Does the specified mapping field actually exist?
      if (!array_key_exists($eloqua_field_id, $eloqua_fields)) {
        // Bah, no field with that ID.
        $form_state->setErrorByName('field_mapping][default][eloqua_field_mapping',
          $this->t('Could not find field in Eloqua with the specified ID %id', [
            '%id' => $eloqua_field_id,
          ])
        );
      }

      // Is the submitted field a required field?
      if (array_key_exists($eloqua_field_id, $required_eloqua_fields)) {
        $required_eloqua_fields_submitted[$eloqua_field_id] = $required_eloqua_fields[$eloqua_field_id];
      }
    }

    // Check each field mapping for user webform fields.
    foreach ($user_field_mapping as $webform_element_id => $eloqua_field_id) {
      // Does the specified mapping field actually exist?
      if (!array_key_exists($eloqua_field_id, $eloqua_fields)) {
        // Bah, no field with that ID.
        $form_state->setErrorByName('field_mapping][user][eloqua_field_mapping',
          $this->t('Could not find field in Eloqua with the specified ID %id', [
            '%id' => $eloqua_field_id,
          ])
        );
      }

      // Is the submitted field a required field?
      if (array_key_exists($eloqua_field_id, $required_eloqua_fields)) {
        $required_eloqua_fields_submitted[$eloqua_field_id] = $required_eloqua_fields[$eloqua_field_id];
      }
    }

    // Any unmapped required fields?
    $required_eloqua_fields_missing = array_diff_key($required_eloqua_fields, $required_eloqua_fields_submitted);
    if (!empty($required_eloqua_fields_missing)) {
      $missing_fields = [
        '#theme' => 'item_list',
        '#items' => $required_eloqua_fields_missing,
      ];

      $form_state->setErrorByName(NULL, $this->t('The following fields are required in Eloqua: @fields', [
        '@fields' => \Drupal::service('renderer')->render($missing_fields),
      ]));
    }

    // Merge default and user field mapping and store it into the form state
    // so it can be saved into the config.
    $field_mapping = array_merge($default_field_mapping, $user_field_mapping);
    $form_state->setValue('eloqua_field_mapping', $field_mapping);
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);
    $this->applyFormStateToConfiguration($form_state);
  }

  /**
   * Get a list of all Eloqua forms.
   *
   * @return array
   *   Form from API lookup.
   */
  protected function getEloquaForms() {
    if (isset($this->eloquaForms)) {
      return $this->eloquaForms;
    }

    $forms = [];
    $result = $this->eloquaFormsService->getForms([
      'orderBy' => 'name'
    ]);

    if (!empty($result['elements'])) {
      foreach ($result['elements'] as $form) {
        $forms[$form['id']] = $form['name'];
      }
    }

    // Store the Eloqua forms to avoid further API calls.
    $this->eloquaForms = $forms;

    return $forms;
  }

  /**
   * Get Eloqua form fields.
   *
   * @param int $form_id
   *   The Eloqua form id to load the fields for.
   *
   * @return array
   *   Fields from API lookup.
   */
  protected function getEloquaFields($form_id) {
    if ($this->eloquaFormId === $form_id && isset($this->eloquaFields)) {
      return $this->eloquaFields;
    }

    $raw_fields = $this->eloquaFormsService->getFieldsRaw($form_id);
    $fields = [];

    foreach ($raw_fields as $field) {
      if (!empty($field['validations'])) {
        foreach ($field['validations'] as $validation) {
          if (!empty($validation['condition']) && $validation['condition']['type'] == 'IsRequiredCondition') {
            $field['isRequired'] = TRUE;
          }
        }
      }
      $fields[$field['id']] = $field;
    }

    // Store the Eloqua fields to reduce API calls.
    $this->eloquaFields = $fields;

    return $fields;
  }

  /**
   * Retrieve a list of default webform submission fields.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   *
   * @return array
   *   A list of webform submission fields, keyed by machine name.
   */
  protected function getDefaultWebformSubmissionFields() {
    /** @var \Drupal\webform\WebformInterface $webform */
    $webform = $this->webform;

    $options = [];

    /** @var \Drupal\webform\WebformSubmissionStorageInterface $submission_storage */
    $submission_storage = \Drupal::entityTypeManager()->getStorage('webform_submission');
    $field_definitions = $submission_storage->getFieldDefinitions();
    $field_definitions = $submission_storage->checkFieldDefinitionAccess($webform, $field_definitions);
    foreach ($field_definitions as $key => $field_definition) {
      $options[$key] = [
        'title' => $field_definition['title'],
        'name' => $key,
        'type' => $field_definition['type'],
      ];
    }

    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function postSave(WebformSubmissionInterface $webform_submission, $update = TRUE) {
    $state = $webform_submission->getWebform()->getSetting('results_disabled') ? WebformSubmissionInterface::STATE_COMPLETED : $webform_submission->getState();
    $this->remotePost($state, $webform_submission);
  }

  /**
   * Executes a remote post to Eloqua.
   *
   * @param string $state
   *   The state of the webform submission.
   *   Either STATE_NEW, STATE_DRAFT, STATE_COMPLETED, STATE_UPDATED, or
   *   STATE_CONVERTED depending on the last save operation performed.
   * @param \Drupal\webform\WebformSubmissionInterface $webform_submission
   *   The webform submission to be posted.
   */
  protected function remotePost($state, WebformSubmissionInterface $webform_submission) {
    // TODO Maybe make these configurable in the future.
    $completed = WebformSubmissionInterface::STATE_COMPLETED;
    if ($state != $completed) {
      return;
    }

    $form_id = $this->configuration['eloqua_form_id'];
    $form_data = [
      'fieldValues' => [],
    ];

    // Get the parsed submission data.
    $data = $this->getRequestData($state, $webform_submission);

    // Load the webform element -> eloqua field mapping.
    $eloqua_field_mapping = $this->configuration['eloqua_field_mapping'];
    foreach ($eloqua_field_mapping as $webform_element_id => $eloqua_field_id) {
      $form_data['fieldValues'][] = [
        'type' => 'FormField',
        'id' => $eloqua_field_id,
        'value' => $data[$webform_element_id],
      ];
    }

    // Submit the form data to Eloqua using the REST API.
    $submission = $this->eloquaFormsService->createFormData($form_id, $form_data);

    // TODO Improve error checking and logging.
    if (empty($submission)) {
      // Log error message.
      $context = [
        '@form' => $this->getWebform()->label(),
        '@message' => 'Could not create Eloqua Form submission',
      ];
      $this->getLogger()->error('@form webform remote post to Eloqua failed. @message', $context);
    }
  }

  /**
   * Get a webform submission's request data.
   *
   * @param string $state
   *   The state of the webform submission.
   *   Either STATE_NEW, STATE_DRAFT, STATE_COMPLETED, STATE_UPDATED, or
   *   STATE_CONVERTED depending on the last save operation performed.
   * @param \Drupal\webform\WebformSubmissionInterface $webform_submission
   *   The webform submission to be posted.
   *
   * @return array
   *   A webform submission converted to an associative array.
   */
  protected function getRequestData($state, WebformSubmissionInterface $webform_submission) {
    // Get submission and elements data.
    $data = $webform_submission->toArray(TRUE);

    // Flatten data and prioritize the element data over the
    // webform submission data.
    $element_data = $data['data'];
    unset($data['data']);
    $data = $element_data + $data;

    // Replace tokens.
    $data = $this->tokenManager->replaceNoRenderContext($data, $webform_submission);

    return $data;
  }

}
