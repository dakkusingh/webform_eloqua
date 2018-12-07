<?php

namespace Drupal\webform_eloqua\Plugin\WebformHandler;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\eloqua_api_redux\Service\Forms as EloquaFormsService;
use Drupal\webform\Plugin\WebformHandlerBase;
use Drupal\webform\WebformSubmissionConditionsValidatorInterface;
use Drupal\webform\WebformSubmissionInterface;
use Drupal\webform\Element\WebformExcludedColumns;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;

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
   * Eloqua Forms Service.
   *
   * @var \Drupal\eloqua_api_redux\Service\Forms
   */
  private $eloquaFormsService;

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
                              EloquaFormsService $eloquaFormsService) {
    parent::__construct(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $logger_factory,
      $config_factory,
      $entity_type_manager,
      $conditions_validator);
    $this->eloquaFormsService = $eloquaFormsService;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container,
                                array $configuration,
                                $plugin_id,
                                $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('logger.factory'),
      $container->get('config.factory'),
      $container->get('entity_type.manager'),
      $container->get('webform_submission.conditions_validator'),
      $container->get('eloqua_api_redux.forms')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    // Mimic functionality from
    // WebformExcludedColumns::getWebformExcludedOptions
    // TODO find a better way to do this.
    // See discussion at
    // https://www.drupal.org/project/webform/issues/3017676#comment-12880632
    $webform = $this->getWebform();
    $fakeElement['#webform_id'] = $webform->id();
    $options = WebformExcludedColumns::getWebformExcludedOptions($fakeElement);

    $form['eloqua_field_ids'] = [
      '#type' => 'table',
      '#caption' => $this->t('Drupal to Eloqua Field Mapping'),
      '#header' => [
        'drupal_fields' => $this->t('Drupal Field Name'),
        'eloqua_fields' => $this->t('Eloqua Field ID'),
      ],
    ];

    if ($this->configuration['eloqua_formid'] != '') {
      $eloquaFieldOptions = [];
      $eloquaFields = $this->getEloquaFields($this->configuration['eloqua_formid']);

      if (!empty($eloquaFields)) {
        $eloquaFieldOptions['-'] = '- Select -';
        foreach ($eloquaFields as $eloquaField) {
          if (isset($eloquaField['isRequired']) && $eloquaField['isRequired'] == TRUE) {
            $eloquaFieldOptions[$eloquaField['id']] = $eloquaField['name'] . ' (required)';
          }
          else {
            $eloquaFieldOptions[$eloquaField['id']] = $eloquaField['name'];
          }

        }
      }
    }

    foreach ($options as $key => $value) {
      $form['eloqua_field_ids'][$key]['drupal_field_name'] = [
        '#markup' => $value['title'],
      ];

      if (!empty($eloquaFieldOptions)) {
        $form['eloqua_field_ids'][$key]['eloqua_field_id'] = [
          '#type' => 'select',
          '#options' => $eloquaFieldOptions,
          '#default_value' => $this->configuration[$key],
        ];
      }
      else {
        $form['eloqua_field_ids'][$key]['eloqua_field_id'] = [
          '#type' => 'textfield',
          '#default_value' => $this->configuration[$key],
        ];
      }
    }

    $form['eloqua_formid'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Eloqua Form ID'),
      '#required' => TRUE,
      '#default_value' => $this->configuration['eloqua_formid'],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();
    $eloquaFormId = $values['eloqua_formid'];

    if ($eloquaFormId != '') {
      // Validate if the form exists.
      $eloquaForm = $this->getEloquaForm($eloquaFormId);
      if (empty($eloquaForm)) {
        // Huh there is no form with that ID.
        $form_state->setErrorByName(
          'eloqua_formid',
          $this->t('Could not find any form in Eloqua with the specified ID')
        );
      }

      // Validate field mapping exists.
      $eloquaFields = $this->getEloquaFields($eloquaFormId);
      foreach ($eloquaFields as $eloquaField) {
        $eloquaFieldOptions[] = $eloquaField['id'];
        if (isset($eloquaField['isRequired'])&& $eloquaField['isRequired'] == TRUE) {
          $mandatoryEloquaFields[$eloquaField['id']] = $eloquaField['name'];
        }
      }

      // Build the submitted mandatory field.
      $mandatoryEloquaFieldsSubmitted = [];

      // Check each field mapping.
      foreach ($values['eloqua_field_ids'] as $key => $value) {
        // Only interested in actual values.
        if ($value['eloqua_field_id'] != "" && $value['eloqua_field_id'] != "-") {
          // Does the specified mapping field actually exist?
          if (!in_array($value['eloqua_field_id'], $eloquaFieldOptions)) {
            // Bah, no field with that ID.
            $form_state->setErrorByName(
              $key,
              $this->t('Could not find field in Eloqua with the specified ID')
            );
          }

          // Is this submitted field a mandatory request?
          if (array_key_exists($value['eloqua_field_id'], $mandatoryEloquaFields)) {
            $mandatoryEloquaFieldsSubmitted[$value['eloqua_field_id']] = $mandatoryEloquaFields[$value['eloqua_field_id']];
          }

        }
      }

      // Any unmapped required fields?
      $mandatoryEloquaFieldsMissing = array_diff_key($mandatoryEloquaFields, $mandatoryEloquaFieldsSubmitted);
      if (!empty($mandatoryEloquaFieldsMissing)) {
        $missing = [
          '#theme' => 'item_list',
          '#items' => $mandatoryEloquaFieldsMissing,
        ];

        $form_state->setErrorByName(NULL, $this->t('The following fields are required in Eloqua: @fields', [
          '@fields' => drupal_render($missing),
        ]));
      }

    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);

    // Get all submitted values.
    $values = $form_state->getValues();

    // Process custom fields.
    foreach ($values['eloqua_field_ids'] as $key => $value) {
      // Create a Dummy array to use later.
      $eloquaFields[$key] = $value['eloqua_field_id'];
      $this->configuration[$key] = $value['eloqua_field_id'];
    }

    // Finally save the Eloqua Form ID.
    $this->configuration['eloqua_formid'] = $values['eloqua_formid'];
    $this->configuration['eloqua_field_ids'] = serialize($eloquaFields);
  }

  /**
   * Get Eloqua Form Fields.
   *
   * @param int $formId
   *   Form ID.
   *
   * @return array
   *   Fields from API lookup.
   */
  private function getEloquaFields($formId) {
    $fields = $this->eloquaFormsService->getFieldsRaw($formId);
    $newFields = [];

    foreach ($fields as $field) {
      if (!empty($field['validations'])) {
        foreach ($field['validations'] as $validation) {
          if (!empty($validation['condition']) && $validation['condition']['type'] == 'IsRequiredCondition') {
            $field['isRequired'] = TRUE;
          }
        }
      }
      $newFields[] = $field;
    }
    return $newFields;
  }

  /**
   * Get Eloqua Form.
   *
   * @param int $formId
   *   Form ID.
   *
   * @return array
   *   Form from API lookup.
   */
  private function getEloquaForm($formId) {
    $form = $this->eloquaFormsService->getForm($formId);
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function postSave(WebformSubmissionInterface $webform_submission, $update = TRUE) {
    $state = $webform_submission->getWebform()->getSetting('results_disabled') ? WebformSubmissionInterface::STATE_COMPLETED : $webform_submission->getState();
    $this->remotePost($state, $webform_submission);
  }

  /**
   * Execute a remote post.
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

    // Get submission and elements data.
    $data = $webform_submission->toArray(TRUE);
    // Flatten data and prioritize the element data over the
    // webform submission data.
    $elementData = $data['data'];
    unset($data['data']);
    $data = $elementData + $data;

    // Annoying..
    $eloquaFieldIds = array_filter(unserialize($this->configuration['eloqua_field_ids']));
    $eloquaFields = [];

    // Filter out the crap fields we dont care about.
    foreach ($eloquaFieldIds as $key => $value) {
      if ($value != "" && $value != "-") {
        $eloquaFields[] = [
          'type' => 'FormField',
          'id' => $value,
          'value' => $data[$key],
        ];
      }
    }

    $formData = [
      'fieldValues' => $eloquaFields,
    ];

    $submission = $this->eloquaFormsService->createFormData($this->configuration['eloqua_formid'], $formData);

    // TODO Improve error checking and logging.
    if (empty($submission)) {
      // Log error message.
      $context = [
        '@form' => $this->getWebform()->label(),
        '@message' => 'Could not create Eloqua Form submission',
      ];
      $this->getLogger()
        ->error('@form webform remote post to Eloqua failed. @message', $context);
    }
  }

}
