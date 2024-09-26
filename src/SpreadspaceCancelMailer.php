<?php

namespace Drupal\spreadspace_cancel;

use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Mail\MailFormatHelper;
use Drupal\Component\Utility\Html;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Spreadspace Cancel SMTP Mailer service.
 */
class SpreadspaceCancelMailer
{

  /**
   * The mail manager service.
   *
   * @var \Drupal\Core\Mail\MailManagerInterface
   */
  protected $mailManager;

  /**
   * Constructs a SpreadspaceCancelMailer object.
   *
   * @param \Drupal\Core\Mail\MailManagerInterface $mail_manager
   *   The mail manager service.
   */
  public function __construct(MailManagerInterface $mail_manager)
  {
    $this->mailManager = $mail_manager;
  }

  /**
   * Sends an email using specific SMTP credentials and server settings.
   *
   * @param string $from_email
   *   The sender email address.
   * @param string $to_email
   *   The recipient email address.
   * @param string $subject
   *   The subject of the email.
   * @param string $body
   *   The body of the email.
   * @param array $smtp_credentials
   *   An associative array containing 'smtp_username', 'smtp_password', 'smtp_server', 'smtp_port', 'smtp_encryption'.
   * @param array $additional_params
   *   An associative array containing additional parameters like 'attachments', 'customer_id', 'sender_name'.
   *
   * @return bool
   *   TRUE if the email was sent successfully, FALSE otherwise.
   */
  public function sendEmail($from_email, $to_email, $subject, $body, array $smtp_credentials, array $additional_params = [])
  {
    // Validate required SMTP settings.
    if (empty($from_email) || empty($additional_params['sender_name']) || empty($smtp_credentials['smtp_username']) || empty($smtp_credentials['smtp_password']) || empty($smtp_credentials['smtp_server'])) {
      \Drupal::logger('spreadspace_cancel')->error('SMTP credentials are incomplete.');
      return FALSE;
    }

    // Set SMTP configuration dynamically.
    \Drupal::configFactory()->getEditable('smtp.settings')
      ->set('smtp_host', $smtp_credentials['smtp_server'])
      ->set('smtp_port', $smtp_credentials['smtp_port'] ?? 587)
      ->set('smtp_protocol', $smtp_credentials['smtp_encryption'] ?? 'tls')
      ->set('smtp_username', $smtp_credentials['smtp_username'])
      ->set('smtp_password', $smtp_credentials['smtp_password'])
      ->set('smtp_from', $from_email)
      ->set('smtp_fromname', $additional_params['sender_name'])
      ->save();

    // Prepare email parameters.
    $params = [
      'body' => $body,
      'subject' => $subject,
      'headers' => [
        'Content-Type' => 'text/plain; charset=UTF-8',
      ],
    ];

    // Add additional parameters to the email.
    if (!empty($additional_params['attachments'])) {
      $params['attachments'] = $additional_params['attachments'];
    }
    if (!empty($additional_params['customer_id'])) {
      $params['customer_id'] = $additional_params['customer_id'];
    }

    $langcode = 'en';

    // Send email using the overridden SMTP settings.
    $result = $this->mailManager->mail('spreadspace_cancel', $additional_params['mail_key'], $to_email, $langcode, $params, $from_email);

    // Return the result of the email send.
    return $result['result'] === true;
  }
}
