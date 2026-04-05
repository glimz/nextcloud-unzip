<?php

declare(strict_types=1);

namespace OCA\Unzip\Controller;

use OCA\Unzip\Service\ExtractionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\Constants;
use OC\Files\Filesystem;
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

		$detectedType = $this->detectType($archiveNode->getName(), $archiveNode->getMimeType());
		if ($detectedType === null) {
			// Fall back to client-supplied type for edge cases, but prefer server-side detection.
			$detectedType = $type !== '' ? $type : null;
		}
		if ($detectedType === null) {
			return new DataResponse(['code' => 0, 'desc' => 'Unsupported archive type'], 400);
		}

		$this->logger->info('unzip.extract start', [
			'app' => 'unzip',
			'user' => $this->userId,
			'fileId' => $fileId,
			'type' => $detectedType,
			'archive' => $archiveNode->getName(),
			'targetFolder' => $parent->getName(),
		]);

		$tmpArchive = null;
		$tmpExtractDir = null;
		$importStats = ['files' => 0, 'folders' => 0, 'skipped' => 0];
		try {
			[$tmpArchive, $tmpExtractDir] = $this->prepareTempExtraction($archiveNode);

			$response = $this->extractionService->extractByType($tmpArchive, $tmpExtractDir, $detectedType);
			if (($response['code'] ?? 0) !== 1) {
				return new DataResponse($response, 400);
			}

			$importStats = $this->importExtractedTree($tmpExtractDir, $parent);
		} catch (\Throwable $e) {
			$this->logger->error('unzip.extract failed: ' . $e->getMessage(), ['app' => 'unzip']);
			return new DataResponse(['code' => 0, 'desc' => 'Extraction failed'], 400);
		} finally {
			if (is_string($tmpArchive) && $tmpArchive !== '' && is_file($tmpArchive)) {
				@unlink($tmpArchive);
			}
			if (is_string($tmpExtractDir) && $tmpExtractDir !== '' && is_dir($tmpExtractDir)) {
				$this->deleteDirectoryRecursive($tmpExtractDir);
			}
		}

		try {
			$parent->getStorage()->getScanner()->scan($parent->getInternalPath(), true);
		} catch (\Throwable $e) {
			$this->logger->warning('Extraction scan warning: ' . $e->getMessage());
		}

		return new DataResponse([
			'code' => 1,
			'files' => (int)($importStats['files'] ?? 0),
			'folders' => (int)($importStats['folders'] ?? 0),
			'skipped' => (int)($importStats['skipped'] ?? 0),
		]);
	}

	/**
	 * @return array{0:string,1:string} [tmpArchivePath, tmpExtractDir]
	 */
	private function prepareTempExtraction(File $archiveNode): array {
		$tmpDir = rtrim((string)sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'nc-unzip-' . bin2hex(random_bytes(8));
		if (!@mkdir($tmpDir, 0700, true) && !is_dir($tmpDir)) {
			throw new \RuntimeException('Unable to create temporary directory');
		}

		$tmpArchivePath = $tmpDir . DIRECTORY_SEPARATOR . 'archive';
		$in = $archiveNode->fopen('r');
		if (!is_resource($in)) {
			throw new \RuntimeException('Unable to read archive');
		}
		$out = @fopen($tmpArchivePath, 'wb');
		if (!is_resource($out)) {
			@fclose($in);
			throw new \RuntimeException('Unable to create temporary archive file');
		}
		stream_copy_to_stream($in, $out);
		@fclose($in);
		@fclose($out);

		$tmpExtractDir = $tmpDir . DIRECTORY_SEPARATOR . 'extract';
		if (!@mkdir($tmpExtractDir, 0700, true) && !is_dir($tmpExtractDir)) {
			throw new \RuntimeException('Unable to create temporary extraction directory');
		}

		return [$tmpArchivePath, $tmpExtractDir];
	}

	/**
	 * @return array{files:int,folders:int,skipped:int}
	 */
	private function importExtractedTree(string $extractDir, Folder $targetFolder): array {
		$stats = ['files' => 0, 'folders' => 0, 'skipped' => 0];
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($extractDir, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ($iterator as $node) {
			/** @var \SplFileInfo $node */
			$fullPath = $node->getPathname();
			$relativePath = substr($fullPath, strlen(rtrim($extractDir, DIRECTORY_SEPARATOR)) + 1);
			$relativePath = str_replace('\\', '/', $relativePath);
			if ($relativePath === '' || str_contains($relativePath, "\0") || str_contains($relativePath, '../') || str_starts_with($relativePath, '../')) {
				$stats['skipped']++;
				continue;
			}

			if ($node->isDir()) {
				$this->ensureFolder($targetFolder, $relativePath);
				$stats['folders']++;
				continue;
			}

			$dirName = str_contains($relativePath, '/') ? dirname($relativePath) : '.';
			$fileName = basename($relativePath);
			if (Filesystem::isFileBlacklisted($fileName)) {
				$stats['skipped']++;
				continue;
			}
			$destFolder = $dirName === '.' ? $targetFolder : $this->ensureFolder($targetFolder, $dirName);
			$safeName = $fileName;
			$suffix = 1;
			while ($destFolder->nodeExists($safeName)) {
				$base = pathinfo($fileName, PATHINFO_FILENAME);
				$ext = pathinfo($fileName, PATHINFO_EXTENSION);
				$safeName = $base . ' (' . $suffix . ')' . ($ext !== '' ? ('.' . $ext) : '');
				$suffix++;
			}
			$newFile = $destFolder->newFile($safeName);

			$in = @fopen($fullPath, 'rb');
			if (!is_resource($in)) {
				throw new \RuntimeException('Unable to read extracted file');
			}
			$out = $newFile->fopen('w');
			if (!is_resource($out)) {
				@fclose($in);
				throw new \RuntimeException('Unable to write extracted file');
			}
			stream_copy_to_stream($in, $out);
			@fclose($in);
			@fclose($out);
			$stats['files']++;
		}
		return $stats;
	}

	private function ensureFolder(Folder $base, string $path): Folder {
		$path = trim($path, '/');
		if ($path === '') {
			return $base;
		}
		$parts = array_values(array_filter(explode('/', $path), static fn ($p) => $p !== '' && $p !== '.' && $p !== '..'));
		$current = $base;
		foreach ($parts as $part) {
			if ($current->nodeExists($part)) {
				$existing = $current->get($part);
				if ($existing instanceof Folder) {
					$current = $existing;
					continue;
				}
				// Name collision: create a safe folder name.
				$suffix = 1;
				$newName = $part . ' (' . $suffix . ')';
				while ($current->nodeExists($newName)) {
					$suffix++;
					$newName = $part . ' (' . $suffix . ')';
				}
				$current = $current->newFolder($newName);
				continue;
			}
			$current = $current->newFolder($part);
		}
		return $current;
	}

	private function deleteDirectoryRecursive(string $dir): void {
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ($iterator as $node) {
			/** @var \SplFileInfo $node */
			if ($node->isDir()) {
				@rmdir($node->getPathname());
			} else {
				@unlink($node->getPathname());
			}
		}
		@rmdir($dir);
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

}
