<?php

namespace Drupal\spreadspace_cancel\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

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
    $form['email_from'] = [
      '#type' => 'email',
      '#title' => $this->t('Sender email'),
      '#required' => TRUE,
      '#default_value' => $this->config('spreadspace_cancel.settings')->get('email_from'),
    ];
    $form['email_from_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Sender name'),
      '#required' => TRUE,
      '#default_value' => $this->config('spreadspace_cancel.settings')->get('email_from_name'),
    ];

    $form['description'] = [
      '#type' => 'markup',
      '#markup' => new TranslatableMarkup('It is possible to specify client specific values in <b>settings.php</b>. Set any of this values inside of <b>clients</b> array keyed with client id, then pass <b>client</b> property with that id to request and config will be taken for that client or default from this form will be used.'),
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
      ->set('email_from', $form_state->getValue('email_from'))
      ->set('email_from_name', $form_state->getValue('email_from_name'))
      ->save();
    parent::submitForm($form, $form_state);
  }

}
