<?php

declare(strict_types=1);

namespace OCA\Unzip\AppInfo;

use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCA\Unzip\Listener\LoadAdditionalListener;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Util;

class Application extends App implements IBootstrap {
	public const APP_ID = 'unzip';

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerEventListener(LoadAdditionalScriptsEvent::class, LoadAdditionalListener::class);
	}

	public function boot(IBootContext $context): void {
		$context->injectFn(static function (): void {
			// Ensure script is always loaded even if Files load event behavior changes.
			Util::addScript(Application::APP_ID, 'unzip');
		});
	}
}
