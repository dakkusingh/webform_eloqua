<?php

namespace Drupal\webform_eloqua\Plugin\WebformHandler;

use Drupal\Core\Form\FormStateInterface;
use Drupal\webform\Plugin\WebformHandlerBase;
use Drupal\webform\WebformSubmissionInterface;
use Drupal\webform\Element\WebformExcludedColumns;

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
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    // Mimic functionality from WebformExcludedColumns::getWebformExcludedOptions
    // TODO find a better way to do this.
    $webform = $this->getWebform();
    $fakeElement['#webform_id'] = $webform->id();
    $options = WebformExcludedColumns::getWebformExcludedOptions($fakeElement);

    $form['eloqua_field_ids'] = [
      '#type' => 'table',
      '#caption' => $this->t('Drupal to Eloqua Field Mapping'),
      '#header' => [
        'drupal_fields' => t('Drupal Field Name'),
        'eloqua_fields' => t('Eloqua Field ID'),
      ],
    ];

    if ($this->configuration['eloqua_formid'] != '') {
      $eloquaFieldOptions = [];
      $eloquaFields = $this->getEloquaFields($this->configuration['eloqua_formid']);

      if (!empty($eloquaFields)) {
        $eloquaFieldOptions['-'] = $this->t('-');
        foreach ($eloquaFields as $eloquaField) {
          $eloquaFieldOptions[$eloquaField['id']] = $eloquaField['name'];
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
      }

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
        }
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
    // TODO Dependency Inject this.
    $eloquaFormsService = \Drupal::service('eloqua_api_redux.forms');
    $fields = $eloquaFormsService->getFieldsRaw($formId);
    return $fields;
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
    // TODO Dependency Inject this.
    $eloquaFormsService = \Drupal::service('eloqua_api_redux.forms');
    $form = $eloquaFormsService->getForm($formId);
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
    if ($state != 'completed') {
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

    // TODO Dependency Inject this.
    $eloquaFormsService = \Drupal::service('eloqua_api_redux.forms');
    $submission = $eloquaFormsService->createFormData($this->configuration['eloqua_formid'], $formData);

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
