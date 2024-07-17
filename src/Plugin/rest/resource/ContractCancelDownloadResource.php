<?php

namespace Drupal\spreadspace_cancel\Plugin\rest\resource;

use Drupal\rest\Plugin\ResourceBase;
use Drupal\rest\ModifiedResourceResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Drupal\file\Entity\File;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Provides a resource to download files.
 *
 * @RestResource(
 *   id = "spreadspace_cancel_contract_download",
 *   label = @Translation("Contract Cancel Download"),
 *   uri_paths = {
 *     "canonical" = "/api/kuendigung-download/{uuid}"
 *   }
 * )
 */
class ContractCancelDownloadResource extends ResourceBase
{

  public function get(Request $request, $uuid = null) {
    if (empty($uuid)) {
      return new ModifiedResourceResponse([
        'message' => $this->t('ID is required.'),
      ], 400);
    }

    $ip_address = $request->getClientIp();
    $ip_hash = hash('sha256', $ip_address);

    // Load the file entity using the UUID
    $files = \Drupal::entityTypeManager()->getStorage('file')->loadByProperties(['uuid' => $uuid]);
    if ($files) {
      /** @var \Drupal\file\Entity\File $file */
      // Retrieve the stored hash from the database
      $file = reset($files);
      $file_path = $file->getFileUri();
      $result = \Drupal::database()->select('spreadspace_cancel_user_agents', 'c')
        ->fields('c', ['user_agent_hash', 'file_path'])
        ->condition('user_agent_hash', $ip_hash)
        ->condition('file_path', $uuid)
        ->orderBy('created', 'DESC')
        ->range(0, 1)
        ->execute()
        ->fetchAssoc();

      if ($result) {
        // Serve the file
        $file = reset($files);
        $file_path = $file->getFileUri();
        $file_real_path = \Drupal::service('file_system')->realpath($file_path);

        // Check if the file exists
        if (file_exists($file_real_path)) {
          $response = new BinaryFileResponse($file_real_path);
          $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, 'Kündigung.pdf');
          $response->headers->set('Content-Type', 'application/pdf');

          return $response;
        } else {
          return new ModifiedResourceResponse([
            'message' => $this->t('File not found.'),
          ], 404);
        }
      } else {
        // Deny access
        return new ModifiedResourceResponse([
          'message' => $this->t('File not found.'),
        ], 403);
      }
    } else {
      // File not found
      return new ModifiedResourceResponse([
        'message' => $this->t('File not found.'),
      ], 404);
    }
  }
}
