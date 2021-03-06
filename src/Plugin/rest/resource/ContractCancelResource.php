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
use Drupal\file\FileInterface;
use Drupal\file\FileRepositoryInterface;
use Drupal\rest\ModifiedResourceResponse;
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
    $this->validate($data);

    // Get client from request if set.
    $this->client = $data['client'] ?? '';

    // Abort if configuration is not yet set.
    if (empty($this->getConfig('email'))) {
      return new ModifiedResourceResponse([
        'message' => 'Backend is not fully configured for the functionality.',
      ], 500);
    }

    // Generating pdf file.
    try {
      $pdf = $this->generatePDF($data);
    }
    catch (\Exception $e) {
      $this->logger
        ->error('There was an issue with generating pdf file: ' . implode(' ', [
          $e->getCode(),
          $e->getMessage()
        ]));
      return new ModifiedResourceResponse([
        'message' => 'There was an issue with contract cancellation formular generation.',
      ], 500);
    }

    // Send 2 emails to user and other email specified in configs.
    $attachment = [
      'filepath' => $pdf->getFileUri(),
      'filename' => $pdf->getFilename(),
      'filemime' => $pdf->getMimeType(),
    ];
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
        'customer_id' => $data['customer ID'],
        'sender' => $this->getConfig('email_from'),
        'sender_name' => $this->getConfig('email_from_name'),
      ]);

    $this->flood->register($this->getPluginId(), self::FLOOD_WINDOW);

    // Return the pdf url in response.
    return new ModifiedResourceResponse([
      'url' => $pdf->createFileUrl(FALSE),
    ], 200);
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
      throw new BadRequestHttpException('No data received.');
    }

    foreach (self::REQUIRED_FIELDS as $field_name) {
      if (empty($data[$field_name])) {
        throw new BadRequestHttpException($field_name . ' is required.');
      }
    }

    if (!empty($data['reason for extraordinary termination'])
  && strlen($data['reason for extraordinary termination']) > 500) {
      throw new BadRequestHttpException('Reason for extraordinary termination should not exceed 500 characters limit.');
    }

    if (!$this->flood->isAllowed($this->getPluginId(), self::FLOOD_THRESHOLD, self::FLOOD_WINDOW)) {
      throw new BadRequestHttpException('Too much requests.');
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
    $heading =  new FormattableMarkup("Diese K??ndigung wurde am @date um @time Uhr durch Bet??tigung der Schaltfl??che ???jetzt k??ndigen??? an die Telekom Deutschland GmbH gesendet. Nach Eingang erhalten Sie eine automatische Eingangsbest??tigung.", [
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
    $pdf->Write(6, $this->prepareText('Best??tigungsseite'));
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont(self::FONT, '', 9);
    $pdf->Ln(6);
    $pdf->Write(5, $this->prepareText("??ber diese Seite k??nnen Sie Ihren Vertrag mit der Telekom Deutschland GmbH f??r die Marke {$this->getConfig('email_from_name')} k??ndigen. Bitte tragen Sie daf??r nachfolgend die notwendigen Angaben ein."));
    $pdf->Ln(6);

    // Set borders width and get coordinates of start of table.
    $pdf->SetLineWidth(0.2);
    $table_start_x = $x = $pdf->GetX();
    $table_start_y = $y = $pdf->GetY();
    $max_y = $y;

    // First table row with name and surname.
    $pdf->MultiCell(35, 10, $this->prepareText('Name*'), 0, 'L');
    $max_y = max($max_y, $pdf->GetY());

    $pdf->SetXY($x + 35, $y);
    $this->multiCell($pdf, 50, 10, $data['first name']);
    $max_y = max($max_y, $pdf->GetY());

    $pdf->SetXY($x + 35 + 50, $y);
    $pdf->MultiCell(35, 10, $this->prepareText('Vorname*'), 0, 'L');
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
    $pdf->MultiCell(35, 5, $this->prepareText('Stra??e*'));
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
    $pdf->MultiCell(170, 5, $this->prepareText("Welchen Vertrag oder welche Vertr??ge mit der Telekom Deutschland GmbH (Marke {$this->getConfig('email_from_name')}) m??chten Sie k??ndigen?"), 0, 'C');
    $max_y = max($max_y, $pdf->GetY());

    // Border for fifth row.
    $pdf->Line($x, $max_y, $x + 170, $max_y);

    $y = $max_y;

    // Sixth row with id and phone number.
    $pdf->SetXY($x + 35, $y);
    $this->multiCell($pdf, 50, 10, $data['customer ID']);
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
    $pdf->MultiCell(50, 10, $this->prepareText('N??chstm??glicher Zeitpunkt'), 0, 'R');
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
    $pdf->MultiCell(85, 14, $this->prepareText('Ordentliche K??ndigung'), 0, 'C');
    $max_y = max($max_y, $pdf->GetY());

    $pdf->SetXY($x + 85, $y);
    $pdf->MultiCell(85, 14, $this->prepareText('Au??erordentliche K??ndigung'), 0, 'C');
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
    $pdf->MultiCell(170, 5, $this->prepareText('Nur bei au??erordentlicher K??ndigung! Bitte geben Sie den Grund f??r die au??erordentliche K??ndigung an:'), 0, 'L');
    $pdf->Ln(4);
    $max_y = max($max_y, $pdf->GetY());

    $pdf->SetFont(self::FONT, '', 9);
    if (!empty($data['reason for extraordinary termination'])) {
      $pdf->SetXY($x, $max_y);
      $this->multiCell($pdf, 170, 6, $data['reason for extraordinary termination']);
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

    $destination = 'public://pdf/' . bin2hex(random_bytes(4));
    $this->fileSystem->prepareDirectory($destination, FileSystemInterface::CREATE_DIRECTORY);
    $destination .= '/K??ndigungsbest??tigung.pdf';
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
   * @param $w
   *   Cell width.
   * @param $h
   *   Preferable cell height if text won't exceed one line.
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
    $text = $this->prepareText($txt);
    $lines = ceil($pdf->GetStringWidth($text) / $w);
    $height = max(self::MIN_HEIGHT, floor($h / $lines));
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
