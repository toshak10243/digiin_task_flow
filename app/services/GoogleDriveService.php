<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use Google\Client;
use Google\Service\Drive;
use GuzzleHttp\Client as GuzzleClient;

class GoogleDriveService
{
    private $client;
    private $drive;
    private $initError = null;

    public function __construct()
    {
        try {
            $clientId     = $_ENV['GOOGLE_CLIENT_ID'] ?? '';
            $clientSecret = $_ENV['GOOGLE_CLIENT_SECRET'] ?? '';
            $refreshToken = $_ENV['GOOGLE_REFRESH_TOKEN'] ?? '';

            if (empty($clientId) || empty($clientSecret)) {
                $this->initError = "Google Drive API credentials not configured in .env";
                return;
            }

            $this->client = new Client();
            $this->client->setClientId($clientId);
            $this->client->setClientSecret($clientSecret);
            $this->client->addScope(Drive::DRIVE);
            $this->client->setAccessType('offline');

            // Set fast Guzzle timeouts (15s request, 5s connect) to prevent PHP freezing/hanging
            $guzzle = new GuzzleClient([
                'timeout'         => 15.0,
                'connect_timeout' => 5.0,
                'http_errors'     => false
            ]);
            $this->client->setHttpClient($guzzle);

            if (empty($refreshToken)) {
                $this->initError = "GOOGLE_REFRESH_TOKEN is missing in .env";
                return;
            }

            // High Performance Token Caching: Re-use active token for 1 hour without hitting Google endpoint
            $cacheFile = sys_get_temp_dir() . '/digiink_gtoken_' . md5($refreshToken) . '.json';
            $cachedToken = null;

            if (file_exists($cacheFile)) {
                $content = @file_get_contents($cacheFile);
                if ($content) {
                    $parsed = json_decode($content, true);
                    if (!empty($parsed['access_token']) && !empty($parsed['expires_at']) && $parsed['expires_at'] > (time() + 90)) {
                        $cachedToken = $parsed;
                    }
                }
            }

            if ($cachedToken) {
                $this->client->setAccessToken($cachedToken);
            } else {
                $token = $this->client->fetchAccessTokenWithRefreshToken($refreshToken);
                if (isset($token['error'])) {
                    $this->initError = "Google OAuth Error: " . ($token['error_description'] ?? $token['error']);
                    return;
                }
                $token['expires_at'] = time() + ($token['expires_in'] ?? 3500);
                @file_put_contents($cacheFile, json_encode($token));
            }

            $this->drive = new Drive($this->client);
        } catch (\Exception $e) {
            $this->initError = $e->getMessage();
        }
    }

    public function isReady()
    {
        return empty($this->initError) && ($this->drive !== null);
    }

    public function getInitError()
    {
        return $this->initError;
    }

    public function testConnection()
    {
        if (!$this->isReady()) {
            return [
                'success' => false,
                'message' => $this->initError ?? 'Google Drive not initialized'
            ];
        }

        try {
            $about = $this->drive->about->get(['fields' => 'user']);
            return [
                'success' => true,
                'user'    => $about->getUser()->getDisplayName()
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    public function createFolder($folderName, $parentFolderId = null)
    {
        if (!$this->isReady()) {
            return [
                'success' => false,
                'message' => $this->initError
            ];
        }

        try {
            if (!$parentFolderId) {
                $parentFolderId = $_ENV['GOOGLE_DRIVE_ROOT_FOLDER_ID'] ?? null;
            }

            $folderMetadata = new Google\Service\Drive\DriveFile([
                'name'     => $folderName,
                'mimeType' => 'application/vnd.google-apps.folder',
                'parents'  => $parentFolderId ? [$parentFolderId] : []
            ]);

            $folder = $this->drive->files->create($folderMetadata, [
                'fields' => 'id,name,webViewLink'
            ]);

            return [
                'success' => true,
                'id'      => $folder->getId(),
                'name'    => $folder->getName(),
                'link'    => "https://drive.google.com/drive/folders/" . $folder->getId()
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    public function createTaskFolder($taskKey, $existingFolderId = null)
    {
        if (!$this->isReady()) {
            return [
                'success' => false,
                'message' => $this->initError
            ];
        }

        try {
            $rootFolderId = $_ENV['GOOGLE_DRIVE_ROOT_FOLDER_ID'] ?? '';
            $folderName   = str_replace('/', '_', trim($taskKey));

            $taskFolderId   = null;
            $taskFolderName = $folderName;
            $taskFolderLink = null;
            $exists         = false;

            if ($existingFolderId) {
                $taskFolderId   = $existingFolderId;
                $taskFolderLink = "https://drive.google.com/drive/folders/" . $existingFolderId;
                $exists         = true;
            } else {
                $query = sprintf(
                    "name='%s' and '%s' in parents and mimeType='application/vnd.google-apps.folder' and trashed=false",
                    addslashes($folderName),
                    $rootFolderId
                );

                $response = $this->drive->files->listFiles([
                    'q'      => $query,
                    'fields' => 'files(id,name)'
                ]);

                if (count($response->getFiles()) > 0) {
                    $folder         = $response->getFiles()[0];
                    $exists         = true;
                    $taskFolderId   = $folder->getId();
                    $taskFolderName = $folder->getName();
                    $taskFolderLink = "https://drive.google.com/drive/folders/" . $folder->getId();
                } else {
                    $created = $this->createFolder($folderName, $rootFolderId);
                    if (!$created['success']) {
                        return $created;
                    }
                    $taskFolderId   = $created['id'];
                    $taskFolderName = $created['name'];
                    $taskFolderLink = $created['link'];
                }
            }

            $clientFolder     = $this->createSubFolder($taskFolderId, 'Client Files');
            $additionalFolder = $this->createSubFolder($taskFolderId, 'Additional Files');
            $operationsFolder = $this->createSubFolder($taskFolderId, 'Operations Files');

            return [
                'success'           => true,
                'exists'            => $exists,
                'task_folder_id'    => $taskFolderId,
                'task_folder_name'  => $taskFolderName,
                'task_folder_link'  => $taskFolderLink,
                'client_folder'     => $clientFolder,
                'additional_folder' => $additionalFolder,
                'operations_folder' => $operationsFolder
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    public function createSubFolder($parentId, $folderName)
    {
        if (!$this->isReady()) {
            return ['id' => null, 'name' => $folderName, 'link' => null];
        }

        try {
            $query = sprintf(
                "name='%s' and '%s' in parents and mimeType='application/vnd.google-apps.folder' and trashed=false",
                addslashes($folderName),
                $parentId
            );

            $response = $this->drive->files->listFiles([
                'q'      => $query,
                'fields' => 'files(id,name)'
            ]);

            if (count($response->getFiles()) > 0) {
                $folder = $response->getFiles()[0];
                return [
                    'id'   => $folder->getId(),
                    'name' => $folder->getName(),
                    'link' => "https://drive.google.com/drive/folders/" . $folder->getId()
                ];
            }

            $created = $this->createFolder($folderName, $parentId);
            return [
                'id'   => $created['id'] ?? null,
                'name' => $created['name'] ?? $folderName,
                'link' => $created['link'] ?? null
            ];
        } catch (\Exception $e) {
            return ['id' => null, 'name' => $folderName, 'link' => null, 'error' => $e->getMessage()];
        }
    }

    public function uploadFile($filePath, $folderId, $fileName = null)
    {
        if (!$this->isReady()) {
            return [
                'success' => false,
                'message' => $this->initError
            ];
        }

        try {
            if (!file_exists($filePath)) {
                return [
                    'success' => false,
                    'message' => 'File not found: ' . $filePath
                ];
            }

            if (!$fileName) {
                $fileName = basename($filePath);
            }

            $fileSize = filesize($filePath);
            $mimeType = 'application/octet-stream';
            if (function_exists('mime_content_type')) {
                $detected = @mime_content_type($filePath);
                if ($detected) $mimeType = $detected;
            }

            $fileMetadata = new Google\Service\Drive\DriveFile([
                'name'    => $fileName,
                'parents' => [$folderId]
            ]);

            // For files > 5MB, use chunked stream upload to prevent memory exhaustion
            if ($fileSize > 5 * 1024 * 1024) {
                $chunkSizeBytes = 2 * 1024 * 1024; // 2MB chunk
                $this->client->setDefer(true);
                $request = $this->drive->files->create($fileMetadata, ['fields' => 'id,name,webViewLink,size']);
                $media = new \Google\Http\MediaFileUpload(
                    $this->client,
                    $request,
                    $mimeType,
                    null,
                    true,
                    $chunkSizeBytes
                );
                $media->setFileSize($fileSize);

                $status = false;
                $handle = fopen($filePath, "rb");
                while (!$status && !feof($handle)) {
                    $chunk = fread($handle, $chunkSizeBytes);
                    $status = $media->nextChunk($chunk);
                }
                fclose($handle);
                $this->client->setDefer(false);
                $file = $status;
            } else {
                $file = $this->drive->files->create(
                    $fileMetadata,
                    [
                        'data'       => file_get_contents($filePath),
                        'mimeType'   => $mimeType,
                        'uploadType' => 'multipart',
                        'fields'     => 'id,name,webViewLink,size'
                    ]
                );
            }

            if ($file && method_exists($file, 'getId')) {
                return [
                    'success' => true,
                    'id'      => $file->getId(),
                    'name'    => $file->getName(),
                    'link'    => $file->getWebViewLink(),
                    'size'    => $file->getSize()
                ];
            }

            return [
                'success' => false,
                'message' => 'Upload completed without file ID'
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    public function deleteFolder($folderId)
    {
        if (!$this->isReady() || empty($folderId)) {
            return ['success' => false, 'message' => $this->initError ?? 'Invalid folder ID'];
        }

        try {
            $this->drive->files->delete($folderId);
            return ['success' => true];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function deleteFile($fileId)
    {
        if (!$this->isReady() || empty($fileId)) {
            return ['success' => false, 'message' => $this->initError ?? 'Invalid file ID'];
        }

        try {
            $this->drive->files->delete($fileId);
            return ['success' => true];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function emptyFolder($folderId)
    {
        if (!$this->isReady() || empty($folderId)) {
            return ['success' => false, 'message' => $this->initError ?? 'Invalid folder ID'];
        }

        try {
            $query = sprintf("'%s' in parents and trashed=false", $folderId);
            $files = $this->drive->files->listFiles([
                'q'      => $query,
                'fields' => 'files(id, name, mimeType)'
            ]);

            foreach ($files->getFiles() as $file) {
                try {
                    $this->drive->files->delete($file->getId());
                } catch (\Exception $ignore) {}
            }

            return ['success' => true];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function clearOperationsFolder($taskFolderId, $operationsFolderId = null)
    {
        if (!$this->isReady()) {
            return ['success' => false, 'message' => $this->initError];
        }

        try {
            if (empty($operationsFolderId) && !empty($taskFolderId)) {
                $sub = $this->getSubFolderInfo($taskFolderId, 'Operations Files');
                if ($sub && !empty($sub['id'])) {
                    $operationsFolderId = $sub['id'];
                }
            }

            if (!empty($operationsFolderId)) {
                return $this->emptyFolder($operationsFolderId);
            }

            return ['success' => true, 'message' => 'No operations folder found to clear'];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function getSubFolderInfo($parentId, $folderName)
    {
        if (!$this->isReady() || empty($parentId)) {
            return null;
        }

        try {
            $query = sprintf(
                "name='%s' and '%s' in parents and mimeType='application/vnd.google-apps.folder' and trashed=false",
                addslashes($folderName),
                $parentId
            );

            $response = $this->drive->files->listFiles([
                'q'      => $query,
                'fields' => 'files(id, name)'
            ]);

            if (count($response->getFiles()) > 0) {
                $folder = $response->getFiles()[0];
                return [
                    'id'   => $folder->getId(),
                    'name' => $folder->getName(),
                    'link' => "https://drive.google.com/drive/folders/" . $folder->getId()
                ];
            }
        } catch (\Exception $e) {
            error_log("getSubFolderInfo error for {$folderName}: " . $e->getMessage());
        }

        return null;
    }

    /**
     * Archive existing files inside a folder into a subfolder (Option 2: Version History)
     * e.g. Moves previous files into "Previous_Work_Before_Feedback_YYYY-MM-DD"
     */
    public function archiveFolderContents($folderId, $archiveSubfolderName = 'Previous_Submissions')
    {
        if (!$this->isReady() || empty($folderId)) {
            return ['success' => false, 'message' => 'Invalid folder ID or service not ready'];
        }

        try {
            // Find existing non-folder files and non-archive folders directly inside folderId
            $query = sprintf("'%s' in parents and trashed=false", $folderId);
            $response = $this->drive->files->listFiles([
                'q'      => $query,
                'fields' => 'files(id, name, mimeType)'
            ]);

            $itemsToMove = [];
            foreach ($response->getFiles() as $item) {
                // Do not re-archive existing archive folders
                if ($item->getMimeType() === 'application/vnd.google-apps.folder' && strpos($item->getName(), 'Previous_') === 0) {
                    continue;
                }
                $itemsToMove[] = $item;
            }

            if (empty($itemsToMove)) {
                return ['success' => true, 'archived_count' => 0];
            }

            // Create archive folder inside folderId
            $archiveFolder = $this->createSubFolder($folderId, $archiveSubfolderName);
            if (empty($archiveFolder['id'])) {
                return ['success' => false, 'message' => 'Could not create archive subfolder'];
            }
            $archiveFolderId = $archiveFolder['id'];

            // Move each item into archiveFolder
            foreach ($itemsToMove as $file) {
                try {
                    $this->drive->files->update($file->getId(), new Google\Service\Drive\DriveFile(), [
                        'addParents'    => $archiveFolderId,
                        'removeParents' => $folderId,
                        'fields'        => 'id, parents'
                    ]);
                } catch (\Exception $ignoreMove) {
                    error_log("Failed to move file {$file->getId()} to archive: " . $ignoreMove->getMessage());
                }
            }

            return ['success' => true, 'archived_count' => count($itemsToMove), 'archive_folder_id' => $archiveFolderId];
        } catch (\Exception $e) {
            error_log("archiveFolderContents error: " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}