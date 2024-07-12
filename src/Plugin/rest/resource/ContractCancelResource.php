<?php

namespace Drupal\spreadspace_cancel\Plugin\rest\resource;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Render\FormattableMarkup;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\file\FileInterface;
use Drupal\file\FileRepositoryInterface;
use Drupal\rest\ModifiedResourceResponse;
use Drupal\Core\Url;
use Drupal\rest\Plugin\ResourceBase;
use Fpdf\Fpdf;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Accept user contract cancel requests.
 *
 * @RestResource (
 *   id = "spreadspace_cancel_contract_cancel",
 *   label = @Translation("Contract cancel"),
 *   uri_paths = {
 *     "create" = "/api/kuendigung"
 *   }
 * )
 */
class ContractCancelResource extends ResourceBase {
  use StringTranslationTrait;

  /**
   * Required POST data fields.
   */
  const REQUIRED_FIELDS = [
    'last name',
    'first name',
    'street',
    'street number',
    'zipcode',
    'city',
    'email address',
    'customer ID',
    'mobile phone number',
  ];

  /**
   * Maximum amount of requests for user per window.
   */
  const FLOOD_THRESHOLD = 5;

  /**
   * Keep track of requests for this long.
   */
  const FLOOD_WINDOW = 60 * 60 * 24;

  /**
   * Font used in generated pdf.
   */
  const FONT = 'Arial';

  /**
   * Minimal line height for
   */
  const MIN_HEIGHT = 3.8;

  /**
   * Client id passed with request data.
   *
   * @var string
   */
  protected $client;

  /**
   * The config factory service.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $config;

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * The flood service.
   *
   * @var \Drupal\Core\Flood\FloodInterface
   */
  protected $flood;

  /**
   * The time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected $time;

  /**
   * The date formatter service.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected $dateFormatter;

  /**
   * The file repository service.
   *
   * @var \Drupal\file\FileRepositoryInterface
   */
  protected $fileRepository;

  /**
   * The mail manager service.
   *
   * @var \Drupal\Core\Mail\MailManagerInterface
   */
  protected $mailManager;

  /**
   * The module config object.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $moduleConfig;

  /**
   * Constructs a Drupal\rest\Plugin\rest\resource\EntityResource object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param array $serializer_formats
   *   The available serialization formats.
   * @param \Psr\Log\LoggerInterface $logger
   *   A logger instance.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config
   *   The config factory service.
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   The file system service.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Drupal\Core\Flood\FloodInterface $flood
   *   The flood service.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $date_formatter
   *   The date formatter service.
   * @param \Drupal\file\FileRepositoryInterface $repository
   *   The file repository service.
   * @param \Drupal\Core\Mail\MailManagerInterface $mail_manager
   *   The mail manager service.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, array $serializer_formats, LoggerInterface $logger, ConfigFactoryInterface $config, FileSystemInterface $file_system, StateInterface $state, FloodInterface $flood, TimeInterface $time, DateFormatterInterface $date_formatter, FileRepositoryInterface $repository, MailManagerInterface $mail_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $serializer_formats, $logger);
    $this->config = $config;
    $this->fileSystem = $file_system;
    $this->state = $state;
    $this->flood = $flood;
    $this->time = $time;
    $this->dateFormatter = $date_formatter;
    $this->fileRepository = $repository;
    $this->mailManager = $mail_manager;

    $this->moduleConfig = $config->get('spreadspace_cancel.settings');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->getParameter('serializer.formats'),
      $container->get('logger.factory')->get('rest'),
      $container->get('config.factory'),
      $container->get('file_system'),
      $container->get('state'),
      $container->get('flood'),
      $container->get('datetime.time'),
      $container->get('date.formatter'),
      $container->get('file.repository'),
      $container->get('plugin.manager.mail')
    );
  }

  /**
   * Responds to POST requests and saves the new record.
   *
   * @param mixed $data
   *   Incoming user data.
   *
   * @return \Drupal\rest\ModifiedResourceResponse
   *   The HTTP response object.
   */
  public function post($data) {
    // Get client from request if set.
    $this->client = $data['client'] ?? '';

    $this->validate($data);

    // Abort if configuration is not yet set.
    if (empty($this->getConfig('email'))) {
      return new ModifiedResourceResponse([
        'message' => $this->t('Backend is not fully configured for the functionality.'),
      ], 500);
    }

    // Generate and store the user agent hash.
    $user_agent = \Drupal::request()->headers->get('User-Agent');
    $user_agent_hash = hash('sha256', $user_agent);
    $timestamp = time();

    if ($this->client == 'share') {
      $build = [
        '#theme' => 'spreadspace_cancel_share_email_customer',
        '#data' => $data,
      ];

      $message_body = \Drupal::service('renderer')->renderPlain($build);

      $build = [
        '#theme' => 'spreadspace_cancel_share_email_service_center',
        '#data' => $data,
      ];

      $service_center_body = \Drupal::service('renderer')->renderPlain($build);

      $this->mailManager
        ->mail('spreadspace_cancel', 'share_contract_cancel_customer', $data['email address'], 'en', [
          'attachments' => [],
          'sender' => $this->getConfig('email_from'),
          'sender_name' => $this->getConfig('email_from_name'),
          'body' => $message_body,
        ]);
      $this->mailManager
        ->mail('spreadspace_cancel', 'share_contract_cancel_service_center', $this->getConfig('email'), 'en', [
          'attachments' => [],
          'customer_id' => $data['customer ID'],
          'sender' => $this->getConfig('email_from'),
          'sender_name' => $this->getConfig('email_from_name'),
          'body' => $service_center_body,
        ]);

      $response = [
        'result' => $this->t('Cancelation mail was sent successfully.'),
      ];
    }
    else {
      // Generating pdf file.
      try {
        $pdf = $this->generatePDF($data);

        $pdf_uuid = $pdf->uuid();
        // Insert data into custom_module_user_agents table
        \Drupal::database()->insert('spreadspace_cancel_user_agents')
        ->fields([
          'user_agent_hash' => $user_agent_hash,
          'request_data' => serialize($data),
          'created' => $timestamp,
          'file_path' => $pdf_uuid
        ])
        ->execute();
      }
      catch (\Exception $e) {
        $this->logger
          ->error('There was an issue with generating pdf file: ' . implode(' ', [
              $e->getCode(),
              $e->getMessage()
            ]));
        return new ModifiedResourceResponse([
          'message' => $this->t('There was an issue with contract cancellation formular generation.'),
        ], 500);
      }

      // Send 2 emails to user and other email specified in configs.
      $attachment = [
        'filepath' => $pdf->getFileUri(),
        'filename' => $pdf->getFilename(),
        'filemime' => $pdf->getMimeType(),
      ];

      $body = '';

      $address = implode(' ', [$data['street'], $data['street number'], $data['zipcode'], $data['city']]);

      if ($address) {
        $body .= sprintf('<p>%s: %s</p>' . PHP_EOL, 'Adresse', $address);
      }

      $translations = [
        'first name' => 'Vorname',
        'client' => 'Marke',
        'last name' => 'Nachname',
        'customer ID' => 'Kundennummer',
        'sim card number' => 'SIM-Kartennummer',
        'mobile phone number' => 'Mobilnummer',
      ];

      foreach ($data as $data_key => $data_value) {
        if (isset($translations[$data_key])) {
          $body .= sprintf('<p>%s: %s</p>' . PHP_EOL, $translations[$data_key], $data_value);
        }
      }

      $this->mailManager
        ->mail('spreadspace_cancel', 'contract_cancel_customer', $data['email address'], 'en', [
          'attachments' => [$attachment],
          'sender' => $this->getConfig('email_from'),
          'sender_name' => $this->getConfig('email_from_name'),
          'body' => $this->getConfig('email_body'),
        ]);
      $this->mailManager
        ->mail('spreadspace_cancel', 'contract_cancel_client', $this->getConfig('email'), 'en', [
          'attachments' => [$attachment],
          'customer_id' => in_array($data['client'], ['norma', 'kaufland']) ? $data['mobile phone number'] : $data['customer ID'],
          'sender' => $this->getConfig('email_from'),
          'sender_name' => $this->getConfig('email_from_name'),
          'body' => $body,
        ]);

      // Generate the full URL
      $url = Url::fromRoute('spreadspace_cancel.contract_download', ['uuid' => $pdf_uuid], ['absolute' => TRUE]);
      $full_url = $url->toString();

      $response = [
        'url' => $full_url . '?_format=json',
      ];
    }

    $this->flood->register($this->getPluginId(), self::FLOOD_WINDOW);

    // Return the pdf url in response.
    return new ModifiedResourceResponse($response, 200);
  }

  /**
   * Validates incoming user data.
   *
   * @param array $data
   *   Data to validate.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\BadRequestHttpException
   */
  protected function validate(array $data) {
    if (!is_array($data) || count($data) == 0) {
      throw new BadRequestHttpException($this->t('No data received.'));
    }

    foreach (self::REQUIRED_FIELDS as $field_name) {
      if (in_array($data['client'], ['norma', 'kaufland']) && $field_name == 'customer ID') {
        continue;
      }

      if (empty($data[$field_name])) {
        //        throw new BadRequestHttpException($field_name . ' is required.');
        throw new BadRequestHttpException($this->t('@field_name is required.', ['@field_name' => $field_name]));
      }
    }

    if ($data['client'] == 'share' && (!isset($data['iban']) || empty($data['iban']))) {
      throw new BadRequestHttpException($this->t('Iban is required.'));
    }

    if (!empty($data['reason for extraordinary termination'])
      && strlen($data['reason for extraordinary termination']) > 500) {
      throw new BadRequestHttpException($this->t('Reason for extraordinary termination should not exceed 500 characters limit.'));
    }

    if (in_array($data['client'], ['norma', 'kaufland']) && empty($data['customer ID']) && empty($data['sim card number'])) {
      throw new BadRequestHttpException($this->t('At least one of those fields "customer ID" or "sim card number" must be present in the request.'));
    }

    $disable_flood_protection = $this->getConfig('disable_flood_protection') ?? FALSE;

    if (!$disable_flood_protection && !$this->flood->isAllowed($this->getPluginId(), self::FLOOD_THRESHOLD, self::FLOOD_WINDOW)) {
      throw new BadRequestHttpException($this->t('Too much requests.'));
    }
  }

  /**
   * Generates pdf file based on request data.
   *
   * @param array $data
   *   Request data.
   *
   * @return \Drupal\file\FileInterface
   *   Pdf file.
   */
  protected function generatePDF(array $data):FileInterface {
    $pdf = new Fpdf('P', 'mm', [216, 279]);
    $pdf->SetMargins(24, 24, 20);
    $pdf->AddPage();

    // Header text in box.
    $heading =  new FormattableMarkup("Diese Kündigung wurde am @date um @time Uhr durch Betätigung der Schaltfläche „jetzt kündigen“ an die Telekom Deutschland GmbH gesendet. Nach Eingang erhalten Sie eine automatische Eingangsbestätigung.", [
      '@date' => $this->dateFormatter->format($this->time->getRequestTime(), 'custom', 'd/m/Y'),
      '@time' => $this->dateFormatter->format($this->time->getRequestTime(), 'custom', 'H:i'),
    ]);
    $pdf->SetTextColor(254, 0, 0);
    $pdf->SetFont(self::FONT, 'bu', 10);
    $pdf->Write(5, $this->prepareText('Hinweis:'));
    $pdf->Ln(6);
    $pdf->SetFont(self::FONT, '', 9);
    $pdf->Write(5, $this->prepareText($heading));
    $pdf->SetLineWidth(0.4);
    $pdf->Rect(24, 24, 170, 16);

    // Text above table.
    $pdf->Ln(6);
    $pdf->SetFont(self::FONT, 'b', 10);
    $pdf->SetTextColor(254, 1, 102);
    $pdf->Write(6, $this->prepareText('Bestätigungsseite'));
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont(self::FONT, '', 9);
    $pdf->Ln(6);
    $pdf->Write(5, $this->prepareText("Über diese Seite können Sie Ihren Vertrag mit der Telekom Deutschland GmbH für die Marke {$this->getConfig('email_from_name')} kündigen. Bitte tragen Sie dafür nachfolgend die notwendigen Angaben ein."));
    $pdf->Ln(6);

    // Set borders width and get coordinates of start of table.
    $pdf->SetLineWidth(0.2);
    $table_start_x = $x = $pdf->GetX();
    $table_start_y = $y = $pdf->GetY();
    $max_y = $y;

    // First table row with name and surname.
    $pdf->MultiCell(35, 10, $this->prepareText('Vorname*'), 0, 'L');
    $max_y = max($max_y, $pdf->GetY());

    $pdf->SetXY($x + 35, $y);
    $this->multiCell($pdf, 50, 10, $data['first name']);
    $max_y = max($max_y, $pdf->GetY());

    $pdf->SetXY($x + 35 + 50, $y);
    $pdf->MultiCell(35, 10, $this->prepareText('Name*'), 0, 'L');
    $max_y = max($max_y, $pdf->GetY());

    $pdf->SetXY($x + 35 + 50 + 35, $y);
    $this->multiCell($pdf, 50, 10, $data['last name']);
    $max_y = max($max_y, $pdf->GetY());

    // Borders for first row.
    $pdf->Line($x + 35, $y, $x + 35, $max_y);
    $pdf->Line($x + 35 + 50, $y, $x + 35 + 50, $max_y);
    $pdf->Line($x + 35 + 50 + 35, $y, $x + 35 + 50 + 35, $max_y);
    $pdf->Line($x, $max_y, $x + 170, $max_y);

    $y = $max_y;
    $pdf->SetFont(self::FONT, '', 8);

    // Second row with location data.
    $pdf->SetXY($x, $y);
    $this->multiCell($pdf, 35, 10, $data['street']);
    $max_y = max($max_y, $pdf->GetY());

    $pdf->SetXY($x + 35, $y);
    $this->multiCell($pdf, 50, 10, $data['street number']);
    $max_y = max($max_y, $pdf->GetY());

    $pdf->SetXY($x + 35 + 50, $y);
    $this->multiCell($pdf, 35, 10, $data['zipcode']);
    $max_y = max($max_y, $pdf->GetY());

    $pdf->SetXY($x + 35 + 50 + 35, $y);
    $this->multiCell($pdf, 50, 10, $data['city']);
    $max_y = max($max_y, $pdf->GetY());

    // Borders for second row.
    $pdf->Line($x + 35, $y, $x + 35, $max_y);
    $pdf->Line($x + 35 + 50, $y, $x + 35 + 50, $max_y);
    $pdf->Line($x + 35 + 50 + 35, $y, $x + 35 + 50 + 35, $max_y);
    $pdf->Line($x, $max_y, $x + 170, $max_y);

    $y = $max_y;

    // Third row with location labels.
    $pdf->SetXY($x, $y);
    $pdf->MultiCell(35, 5, $this->prepareText('Straße*'));
    $max_y = max($max_y, $pdf->GetY());

    $pdf->SetXY($x + 35, $y);
    $pdf->MultiCell(50, 5, $this->prepareText('Hausnummer*'));
    $max_y = max($max_y, $pdf->GetY());

    $pdf->SetXY($x + 35 + 50, $y);
    $pdf->MultiCell(35, 5, $this->prepareText('Postleitzahl*'));
    $max_y = max($max_y, $pdf->GetY());

    $pdf->SetXY($x + 35 + 50 + 35, $y);
    $pdf->MultiCell(50, 5, $this->prepareText('Ort*'));
    $max_y = max($max_y, $pdf->GetY());

    // Borders for third row.
    $pdf->Line($x + 35, $y, $x + 35, $max_y);
    $pdf->Line($x + 35 + 50, $y, $x + 35 + 50, $max_y);
    $pdf->Line($x + 35 + 50 + 35, $y, $x + 35 + 50 + 35, $max_y);
    $pdf->Line($x, $max_y, $x + 170, $max_y);

    $y = $max_y;
    $pdf->SetFont(self::FONT, '', 9);

    // Forth row with email address.
    $pdf->SetXY($x, $y);
    $pdf->MultiCell(35, 10, $this->prepareText('E-Mail-Adresse*:'));
    $max_y = max($max_y, $pdf->GetY());

    $pdf->SetXY($x + 35, $y);
    $this->multiCell($pdf, 135, 10, $data['email address']);
    $max_y = max($max_y, $pdf->GetY());

    // Borders for forth row.
    $pdf->Line($x + 35, $y, $x + 35, $max_y);
    $pdf->Line($x, $max_y, $x + 170, $max_y);

    $y = $max_y;

    // Fifth row with a question.
    $pdf->SetXY($x, $y);
    $pdf->MultiCell(170, 5, $this->prepareText("Welchen Vertrag oder welche Verträge mit der Telekom Deutschland GmbH (Marke {$this->getConfig('email_from_name')}) möchten Sie kündigen?"), 0, 'C');
    $max_y = max($max_y, $pdf->GetY());

    // Border for fifth row.
    $pdf->Line($x, $max_y, $x + 170, $max_y);

    $y = $max_y;

    // Sixth row with id and phone number.
    if (in_array($data['client'], ['norma', 'kaufland']) && isset($data['sim card number'])) {
      $pdf->SetXY($x, $y);
      $this->multiCell($pdf, 35, 10, $data['sim card number']);
    }

    $pdf->SetXY($x + 35, $y);

    if (isset($data['customer ID'])) {
      $this->multiCell($pdf, 50, 10, $data['customer ID']);
    }

    $max_y = max($max_y, $pdf->GetY());

    $pdf->SetXY($x + 35 + 50, $y);
    $this->multiCell($pdf, 85, 10, $data['mobile phone number']);
    $max_y = max($max_y, $pdf->GetY());

    // Borders for sixth row.
    $pdf->Line($x + 35, $y, $x + 35, $max_y);
    $pdf->Line($x + 35 + 50, $y, $x + 35 + 50, $max_y);
    $pdf->Line($x, $max_y, $x + 170, $max_y);

    $y = $max_y;
    $pdf->SetFont(self::FONT, '', 8);

    // Seventh row with id and phone number labels.
    if (in_array($data['client'], ['norma', 'kaufland'])) {
      $pdf->SetXY($x, $y);
      $pdf->MultiCell(35, 5, $this->prepareText('Sim-Kartennummer'));
    }

    $pdf->SetXY($x + 35, $y);
    $pdf->MultiCell(50, 5, $this->prepareText('Kundennummer*'));
    $max_y = max($max_y, $pdf->GetY());

    $pdf->SetXY($x + 35 + 50, $y);
    $pdf->MultiCell(85, 5, $this->prepareText('Mobilfunknummer*'));
    $max_y = max($max_y, $pdf->GetY());

    // Borders for seventh row.
    $pdf->Line($x + 35, $y, $x + 35, $max_y);
    $pdf->Line($x + 35 + 50, $y, $x + 35 + 50, $max_y);
    $pdf->Line($x, $max_y, $x + 170, $max_y);

    $y = $max_y;

    // Eighth row with date and terminate on next date.
    $pdf->SetFont(self::FONT, '', 9);
    $pdf->SetXY($x, $y);
    $pdf->MultiCell(35, 5, $this->prepareText('Zu welchem Zeitpunkt?'), 0, 'L');
    $max_y = max($max_y, $pdf->GetY());

    $pdf->SetXY($x + 35, $y);
    $pdf->SetFont(self::FONT, '', 8);
    $pdf->MultiCell(50, 5, $this->prepareText(implode(PHP_EOL, [
      $data['date of termination'] ?? '',
      'Angabe Datum'
    ])), 0, 'L');
    $max_y = max($max_y, $pdf->GetY());

    $pdf->SetXY($x + 35 + 50, $y);
    $pdf->SetFont(self::FONT, '', 9);
    $pdf->MultiCell(35, 10, $this->prepareText('oder'), 0, 'L');
    $max_y = max($max_y, $pdf->GetY());

    $pdf->SetXY($x + 35 + 50 + 35, $y);
    $pdf->MultiCell(50, 10, $this->prepareText('Nächstmöglicher Zeitpunkt'), 0, 'R');
    $max_y = max($max_y, $pdf->GetY());
    $pdf->SetLineWidth(0.4);
    $checkbox_x = $x + 35 + 50 + 35 + 2.5;
    $checkbox_y = $y + 2.5;
    $pdf->Rect($checkbox_x, $checkbox_y, 5, 5);
    if (!empty($data['terminate on next possible date'])) {
      $pdf->Line($checkbox_x + 1, $checkbox_y + 2, $checkbox_x + 2.5, $checkbox_y + 4.8);
      $pdf->Line($checkbox_x + 2.5, $checkbox_y + 4.8, $checkbox_x + 4.7, $checkbox_y + 0.3);
    }
    $pdf->SetLineWidth(0.2);

    // Borders for eighth row.
    $pdf->Line($x + 35, $y, $x + 35, $max_y);
    $pdf->Line($x + 35 + 50, $y, $x + 35 + 50, $max_y);
    $pdf->Line($x + 35 + 50 + 35, $y, $x + 35 + 50 + 35, $max_y);
    $pdf->Line($x, $max_y, $x + 170, $max_y);

    $y = $max_y;

    // Ninth row for 2 checkbox fields.
    $pdf->SetXY($x, $y);
    $pdf->MultiCell(85, 14, $this->prepareText('Ordentliche Kündigung'), 0, 'C');
    $max_y = max($max_y, $pdf->GetY());

    $pdf->SetXY($x + 85, $y);
    $pdf->MultiCell(85, 14, $this->prepareText('Außerordentliche Kündigung'), 0, 'C');
    $max_y = max($max_y, $pdf->GetY());

    $pdf->SetLineWidth(0.4);
    $checkbox_x = $x + 15;
    $checkbox_y = $y + 4.5;
    $pdf->Rect($checkbox_x, $checkbox_y, 5, 5);
    if (!empty($data['ordinary termination'])) {
      $pdf->Line($checkbox_x + 1, $checkbox_y + 2, $checkbox_x + 2.5, $checkbox_y + 4.8);
      $pdf->Line($checkbox_x + 2.5, $checkbox_y + 4.8, $checkbox_x + 4.7, $checkbox_y + 0.3);
    }
    $checkbox_x = $x + 85 + 10;
    $checkbox_y = $y + 4.5;
    $pdf->Rect($checkbox_x, $checkbox_y, 5, 5);
    if (!empty($data['extraordinary termination'])) {
      $pdf->Line($checkbox_x + 1, $checkbox_y + 2, $checkbox_x + 2.5, $checkbox_y + 4.8);
      $pdf->Line($checkbox_x + 2.5, $checkbox_y + 4.8, $checkbox_x + 4.7, $checkbox_y + 0.3);
    }
    $pdf->SetLineWidth(0.2);

    // Borders for ninth row.
    $pdf->Line($x + 85, $y, $x + 85, $max_y);
    $pdf->Line($x, $max_y, $x + 170, $max_y);

    $y = $max_y;

    // Tenth row for reason for termination.
    $pdf->SetXY($x, $y);
    $pdf->SetFont(self::FONT, 'b', 8);
    $pdf->MultiCell(170, 5, $this->prepareText('Nur bei außerordentlicher Kündigung! Bitte geben Sie den Grund für die außerordentliche Kündigung an:'), 0, 'L');
    $pdf->Ln(4);
    $max_y = max($max_y, $pdf->GetY());

    $pdf->SetFont(self::FONT, '', 9);
    if (!empty($data['reason for extraordinary termination'])) {
      $pdf->SetXY($x, $max_y);
      $this->multiCell($pdf, 170, 6, strip_tags($data['reason for extraordinary termination']));
      $pdf->Ln(0.5);
    }
    $max_y = max($max_y, $pdf->GetY());

    // Borders around the table.
    $pdf->SetLineWidth(0.4);
    $pdf->Rect($table_start_x, $table_start_y, 170, $max_y - $table_start_y);

    // Grey box at the bottom.
    $pdf->Ln(0.3);
    $pdf->SetFillColor(231, 230, 230);
    $pdf->Cell(35, 10, $this->prepareText('*Pflichtangaben'), 0, 0, '', TRUE);

    $destination = 'private://pdf/' . bin2hex(random_bytes(4));
    $this->fileSystem->prepareDirectory($destination, FileSystemInterface::CREATE_DIRECTORY);
    $destination .= '/Kündigung' . (in_array($data['client'], ['norma', 'kaufland']) ? '_' . $data['mobile phone number'] : '') . '.pdf';
    $file = $this->fileRepository->writeData($pdf->Output('s'), $destination);

    // Store file ids to clean up later.
    $fids = $this->state->get(SPREADSPACE_CANCEL_FILES_STATE, []);
    $fids[] = $file->id();
    $this->state->set(SPREADSPACE_CANCEL_FILES_STATE, $fids);

    return $file;
  }

  /**
   * Prepares strings before print on PDF.
   *
   * @param string $text
   *   Regular utf8 string.
   *
   * @return string
   *   Converted string to encoding for pdf.
   */
  protected function prepareText(string $text):string {
    return iconv('UTF-8', 'windows-1252', $text);
  }

  /**
   * Helper function to add multicell to pdf with adjustable height.
   *
   * @param \Fpdf\Fpdf $pdf
   *   Pdf object.
   * @param string|int $w
   *   Cell width (should be more than 0).
   * @param string|int $h
   *   Preferable cell height if text won't exceed one line (should be more than 0).
   * @param $txt
   *   Text to place in cell. Will be prepared before print.
   * @param string|int $border
   *   Place border or not.
   * @param string $align
   *   Cell align.
   * @param bool $fill
   *   Whether to fill cell with color.
   *
   * @see \Fpdf\Fpdf::MultiCell()
   */
  protected function multiCell(Fpdf $pdf, $w, $h, $txt, $border = 0, string $align = 'J', bool $fill = FALSE) {
    if ((int)$w === 0) {
      throw new \Exception($this->t('The multiCell method doesn\'t allow cell width value less or equals to 0, it should be positive integer.'));
    }

    if ((int)$h === 0) {
      throw new \Exception($this->t('The multiCell method doesn\'t allow cell height value less or equals to 0, it should be positive integer.'));
    }

    $text = $this->prepareText($txt);
    $text_width = $pdf->GetStringWidth($text);
    $lines = $text_width ? ceil($text_width / $w) : 1;
    $height = max(self::MIN_HEIGHT, floor($h / ($lines > 0 ? $lines : 1)));
    $pdf->MultiCell($w, $height, $text, $border, $align, $fill);
  }

  /**
   * Returns config data based on client.
   *
   * @param string $key
   *   Config key.
   *
   * @return mixed
   *   Config value.
   */
  protected function getConfig(string $key) {
    return $this->moduleConfig->get("clients.{$this->client}.{$key}")
      ?? $this->moduleConfig->get($key);
  }

}
