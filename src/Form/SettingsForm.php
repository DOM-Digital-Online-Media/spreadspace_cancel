<?php

namespace Drupal\spreadspace_cancel\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure Spreadspace cancel settings for this site.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'spreadspace_cancel_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['spreadspace_cancel.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['email_body'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Cancel email body'),
      '#required' => TRUE,
      '#default_value' => $this->config('spreadspace_cancel.settings')->get('email_body'),
    ];
    $form['email'] = [
      '#type' => 'email',
      '#title' => $this->t('Email'),
      '#required' => TRUE,
      '#default_value' => $this->config('spreadspace_cancel.settings')->get('email'),
    ];
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('spreadspace_cancel.settings')
      ->set('email_body', $form_state->getValue('email_body'))
      ->set('email', $form_state->getValue('email'))
      ->save();
    parent::submitForm($form, $form_state);
  }

}
