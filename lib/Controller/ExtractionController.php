<?php

declare(strict_types=1);

namespace OCA\Unzip\Controller;

use OC\Files\Filesystem;
use OCA\Unzip\Service\ExtractionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\Constants;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class ExtractionController extends Controller {
	private IRootFolder $rootFolder;
	private ExtractionService $extractionService;
	private LoggerInterface $logger;
	private string $userId;

	public function __construct(
		string $appName,
		IRequest $request,
		IRootFolder $rootFolder,
		ExtractionService $extractionService,
		LoggerInterface $logger,
		?string $userId
	) {
		parent::__construct($appName, $request);
		$this->rootFolder = $rootFolder;
		$this->extractionService = $extractionService;
		$this->logger = $logger;
		$this->userId = (string)$userId;
	}

	/**
	 * CSRF is enforced; the client must send the request token header.
	 */
	#[NoAdminRequired]
	public function extract(int $fileId, string $type): DataResponse {
		if ($this->userId === '') {
			$this->logger->warning('unzip.extract missing user context', ['app' => 'unzip']);
			return new DataResponse(['code' => 0, 'desc' => 'Missing user context'], 400);
		}

		$userFolder = $this->rootFolder->getUserFolder($this->userId);
		$nodes = $userFolder->getById($fileId);
		if (count($nodes) === 0 || !($nodes[0] instanceof File)) {
			$this->logger->info('unzip.extract file not found', ['app' => 'unzip', 'user' => $this->userId, 'fileId' => $fileId]);
			return new DataResponse(['code' => 0, 'desc' => 'Archive file not found'], 404);
		}
		/** @var File $archiveNode */
		$archiveNode = $nodes[0];
		$parent = $archiveNode->getParent();
		if (!($parent instanceof Folder)) {
			return new DataResponse(['code' => 0, 'desc' => 'Invalid parent folder'], 400);
		}

		$permissions = $archiveNode->getPermissions();
		if (($permissions & Constants::PERMISSION_READ) === 0) {
			return new DataResponse(['code' => 0, 'desc' => 'Missing permission to read archive'], 403);
		}
		if (($parent->getPermissions() & Constants::PERMISSION_CREATE) === 0) {
			return new DataResponse(['code' => 0, 'desc' => 'Missing permission to create files in target folder'], 403);
		}

		$archivePath = $archiveNode->getStorage()->getLocalFile($archiveNode->getInternalPath());
		if (!is_string($archivePath) || $archivePath === '' || !is_file($archivePath)) {
			return new DataResponse(['code' => 0, 'desc' => 'Archive is not available on local storage'], 400);
		}

		$detectedType = $this->detectType($archiveNode->getName(), $archiveNode->getMimeType());
		if ($detectedType === null) {
			// Fall back to client-supplied type for edge cases, but prefer server-side detection.
			$detectedType = $type !== '' ? $type : null;
		}
		if ($detectedType === null) {
			return new DataResponse(['code' => 0, 'desc' => 'Unsupported archive type'], 400);
		}

		$folderName = $this->makeTargetFolderName($archiveNode->getName(), $parent);
		$targetNode = $parent->newFolder($folderName);
		$targetPath = $targetNode->getStorage()->getLocalFile($targetNode->getInternalPath());

		$this->logger->info('unzip.extract start', [
			'app' => 'unzip',
			'user' => $this->userId,
			'fileId' => $fileId,
			'type' => $detectedType,
			'archive' => $archiveNode->getName(),
			'targetFolder' => $targetNode->getName(),
		]);

		$response = $this->extractionService->extractByType($archivePath, $targetPath, $detectedType);
		if (($response['code'] ?? 0) !== 1) {
			try {
				$targetNode->delete();
			} catch (\Throwable $e) {
				$this->logger->warning('Failed to cleanup target folder after extraction failure: ' . $e->getMessage());
			}
			return new DataResponse($response, 400);
		}

		$this->removeBlacklistedFiles($targetPath);

		try {
			$targetNode->getStorage()->getScanner()->scan($targetNode->getInternalPath(), true);
		} catch (\Throwable $e) {
			$this->logger->warning('Extraction scan warning: ' . $e->getMessage());
		}

			return new DataResponse(['code' => 1, 'folder' => $targetNode->getName()]);
	}

	private function detectType(string $name, string $mimeType): ?string {
		$mime = strtolower($mimeType);
		if (in_array($mime, ['application/zip', 'application/x-zip-compressed'], true)) {
			return 'zip';
		}
		if (in_array($mime, ['application/x-rar-compressed', 'application/vnd.rar', 'application/x-rar'], true)) {
			return 'rar';
		}

		$lower = strtolower($name);
		if (str_ends_with($lower, '.zip')) {
			return 'zip';
		}
		if (str_ends_with($lower, '.rar')) {
			return 'rar';
		}
		foreach (['.7z', '.tar', '.tgz', '.tar.gz', '.gz', '.bz2', '.deb'] as $ext) {
			if (str_ends_with($lower, $ext)) {
				return 'other';
			}
		}
		return null;
	}

	private function makeTargetFolderName(string $archiveName, Folder $parent): string {
		$baseName = pathinfo($archiveName, PATHINFO_FILENAME);
		if (pathinfo($baseName, PATHINFO_EXTENSION) === 'tar') {
			$baseName = pathinfo($baseName, PATHINFO_FILENAME);
		}
		$name = $baseName !== '' ? $baseName : 'archive';
		$counter = 1;
		while ($parent->nodeExists($name)) {
			$name = $baseName . ' (' . $counter . ')';
			$counter++;
		}
		return $name;
	}

	private function removeBlacklistedFiles(string $extractTo): void {
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($extractTo, \FilesystemIterator::SKIP_DOTS)
		);
		foreach ($iterator as $file) {
			/** @var \SplFileInfo $file */
			if (Filesystem::isFileBlacklisted($file->getBasename())) {
				@unlink($file->getPathname());
			}
		}
	}
}
