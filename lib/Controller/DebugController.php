<?php

declare(strict_types=1);

namespace OCA\Unzip\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class DebugController extends Controller {
	private LoggerInterface $logger;
	private string $userId;

	public function __construct(string $appName, IRequest $request, LoggerInterface $logger, ?string $userId) {
		parent::__construct($appName, $request);
		$this->logger = $logger;
		$this->userId = (string)$userId;
	}

	/**
	 * Kept public for JS debug beacons.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function ping(string $stage = '', string $detail = ''): DataResponse {
		$this->logger->info('unzip-js-stage', [
			'app' => 'unzip',
			'stage' => $stage,
			'detail' => $detail,
			'user' => $this->userId,
		]);
		return new DataResponse(['ok' => true, 'stage' => $stage, 'detail' => $detail]);
	}
}
