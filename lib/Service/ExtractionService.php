<?php

declare(strict_types=1);

namespace OCA\Unzip\Service;

use OCP\IL10N;
use Psr\Log\LoggerInterface;

class ExtractionService {
	private IL10N $l10n;
	private LoggerInterface $logger;
	private const MAX_LISTED_ENTRIES = 20000;

	public function __construct(IL10N $l10n, LoggerInterface $logger) {
		$this->l10n = $l10n;
		$this->logger = $logger;
	}

	public function extractByType(string $archivePath, string $targetPath, string $type): array {
		if (!is_file($archivePath)) {
			return ['code' => 0, 'desc' => $this->l10n->t('Archive file is not available')];
		}
		if (!is_dir($targetPath) && !@mkdir($targetPath, 0770, true) && !is_dir($targetPath)) {
			return ['code' => 0, 'desc' => $this->l10n->t('Unable to create extraction directory')];
		}

		if ($type === 'zip') {
			return $this->extractZip($archivePath, $targetPath);
		}
		if ($type === 'rar') {
			return $this->extractRar($archivePath, $targetPath);
		}
		return $this->extractWith7z($archivePath, $targetPath);
	}

	private function extractZip(string $archivePath, string $targetPath): array {
		if (!extension_loaded('zip')) {
			return ['code' => 0, 'desc' => $this->l10n->t('Zip PHP extension is not available')];
		}
		$zip = new \ZipArchive();
		$openResult = $zip->open($archivePath);
		if ($openResult !== true) {
			$this->logger->error('zip open failed', ['app' => 'unzip', 'result' => $openResult]);
			return ['code' => 0, 'desc' => $this->l10n->t('Cannot open zip archive')];
		}

		$preflight = $this->validateZipEntries($zip);
		if (($preflight['code'] ?? 0) !== 1) {
			$zip->close();
			return $preflight;
		}

		$ok = $zip->extractTo($targetPath);
		$zip->close();
		if (!$ok) {
			$this->logger->error('zip extract failed', ['app' => 'unzip']);
			return ['code' => 0, 'desc' => $this->l10n->t('Failed to extract zip archive')];
		}
		$this->removeUnsafeExtractedNodes($targetPath);
		return ['code' => 1];
	}

	private function extractRar(string $archivePath, string $targetPath): array {
		if (extension_loaded('rar')) {
			$rar = @rar_open($archivePath);
			if ($rar === false) {
				return ['code' => 0, 'desc' => $this->l10n->t('Cannot open rar archive')];
			}
			$list = rar_list($rar);
			if (!is_array($list)) {
				rar_close($rar);
				return ['code' => 0, 'desc' => $this->l10n->t('Cannot read rar archive entries')];
			}
			foreach ($list as $entryInfo) {
				$entry = rar_entry_get($rar, $entryInfo->getName());
				if ($entry !== false) {
					$entry->extract($targetPath);
				}
			}
			rar_close($rar);
			$this->removeUnsafeExtractedNodes($targetPath);
			return ['code' => 1];
		}

		$preflight = $this->validateUnrarListing($archivePath);
		if (($preflight['code'] ?? 0) !== 1) {
			return $preflight;
		}

		[$ok, $output] = $this->runCommand('unrar x -o+ ' . escapeshellarg($archivePath) . ' ' . escapeshellarg($targetPath) . '/');
		if (!$ok) {
			$this->logger->error('unrar extraction failed: ' . $output);
			return ['code' => 0, 'desc' => $this->l10n->t('Failed to extract rar archive (unrar)')];
		}
		$this->removeUnsafeExtractedNodes($targetPath);
		return ['code' => 1];
	}

	private function extractWith7z(string $archivePath, string $targetPath): array {
		$preflight = $this->validate7zListing($archivePath);
		if (($preflight['code'] ?? 0) !== 1) {
			return $preflight;
		}

		$cmd = '7z x -y ' . escapeshellarg($archivePath) . ' -o' . escapeshellarg($targetPath);
		[$ok, $output] = $this->runCommand($cmd);
		if (!$ok) {
			$cmd = '7za x -y ' . escapeshellarg($archivePath) . ' -o' . escapeshellarg($targetPath);
			[$ok, $output] = $this->runCommand($cmd);
		}
		if (!$ok) {
			$this->logger->error('7z extraction failed: ' . $output);
			return ['code' => 0, 'desc' => $this->l10n->t('Failed to extract archive (7z/p7zip)')];
		}
		$this->removeUnsafeExtractedNodes($targetPath);
		return ['code' => 1];
	}

	private function runCommand(string $command): array {
		$output = [];
		$exitCode = 1;
		@exec($command . ' 2>&1', $output, $exitCode);
		return [$exitCode === 0, implode("\n", $output)];
	}

	private function validateZipEntries(\ZipArchive $zip): array {
		$count = $zip->numFiles;
		if ($count > self::MAX_LISTED_ENTRIES) {
			return ['code' => 0, 'desc' => $this->l10n->t('Archive contains too many entries')];
		}

		for ($i = 0; $i < $count; $i++) {
			$stat = $zip->statIndex($i);
			$name = is_array($stat) ? (string)($stat['name'] ?? '') : '';
			if (!$this->isSafeArchiveEntryPath($name)) {
				$this->logger->warning('zip unsafe path blocked', ['app' => 'unzip', 'name' => $name]);
				return ['code' => 0, 'desc' => $this->l10n->t('Archive contains unsafe paths')];
			}
		}

		return ['code' => 1];
	}

	private function validate7zListing(string $archivePath): array {
		[$ok, $output] = $this->runCommand('7z l -slt ' . escapeshellarg($archivePath));
		if (!$ok) {
			[$ok, $output] = $this->runCommand('7za l -slt ' . escapeshellarg($archivePath));
		}
		if (!$ok) {
			$this->logger->error('7z list failed: ' . $output);
			return ['code' => 0, 'desc' => $this->l10n->t('Unable to inspect archive contents')];
		}

		$paths = [];
		foreach (explode("\n", $output) as $line) {
			if (str_starts_with($line, 'Path = ')) {
				$paths[] = substr($line, 7);
			}
		}

		if (count($paths) > self::MAX_LISTED_ENTRIES) {
			return ['code' => 0, 'desc' => $this->l10n->t('Archive contains too many entries')];
		}

		foreach ($paths as $path) {
			if (!$this->isSafeArchiveEntryPath($path)) {
				$this->logger->warning('7z unsafe path blocked', ['app' => 'unzip', 'path' => $path]);
				return ['code' => 0, 'desc' => $this->l10n->t('Archive contains unsafe paths')];
			}
		}

		return ['code' => 1];
	}

	private function validateUnrarListing(string $archivePath): array {
		[$ok, $output] = $this->runCommand('unrar lb ' . escapeshellarg($archivePath));
		if (!$ok) {
			$this->logger->error('unrar list failed: ' . $output);
			return ['code' => 0, 'desc' => $this->l10n->t('Unable to inspect archive contents')];
		}

		$paths = array_values(array_filter(array_map('trim', explode("\n", $output)), static fn ($l) => $l !== ''));
		if (count($paths) > self::MAX_LISTED_ENTRIES) {
			return ['code' => 0, 'desc' => $this->l10n->t('Archive contains too many entries')];
		}

		foreach ($paths as $path) {
			if (!$this->isSafeArchiveEntryPath($path)) {
				$this->logger->warning('unrar unsafe path blocked', ['app' => 'unzip', 'path' => $path]);
				return ['code' => 0, 'desc' => $this->l10n->t('Archive contains unsafe paths')];
			}
		}

		return ['code' => 1];
	}

	private function isSafeArchiveEntryPath(string $path): bool {
		$path = trim($path);
		if ($path === '') {
			return false;
		}
		if (str_contains($path, "\0")) {
			return false;
		}

		$path = str_replace('\\', '/', $path);

		// Absolute paths or Windows drive paths.
		if (str_starts_with($path, '/')) {
			return false;
		}
		if (preg_match('/^[a-zA-Z]:\\//', $path) === 1) {
			return false;
		}

		$parts = explode('/', $path);
		$depth = 0;
		foreach ($parts as $part) {
			if ($part === '' || $part === '.') {
				continue;
			}
			if ($part === '..') {
				return false;
			}
			$depth++;
			if ($depth > 200) {
				return false;
			}
		}
		return true;
	}

	private function removeUnsafeExtractedNodes(string $targetPath): void {
		$root = rtrim($targetPath, DIRECTORY_SEPARATOR);
		if ($root === '' || !is_dir($root)) {
			return;
		}
		$rootReal = realpath($root);
		if ($rootReal === false) {
			return;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ($iterator as $node) {
			/** @var \SplFileInfo $node */
			$path = $node->getPathname();

			// Remove symlinks always.
			if (is_link($path)) {
				@unlink($path);
				continue;
			}

			$real = realpath($path);
			if ($real === false) {
				continue;
			}
			if (!str_starts_with($real, $rootReal . DIRECTORY_SEPARATOR) && $real !== $rootReal) {
				// Shouldn't happen, but if it does, remove defensively.
				if (is_dir($path)) {
					@rmdir($path);
				} else {
					@unlink($path);
				}
			}
		}
	}
}
