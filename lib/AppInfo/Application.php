<?php

declare(strict_types=1);

namespace OCA\ScsManagerIntegration\AppInfo;

use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCA\ScsManagerIntegration\Capabilities;
use OCA\ScsManagerIntegration\Listener\LoadAdditionalScriptsListener;
use OCA\ScsManagerIntegration\Listener\NodeDeletedListener;
use OCA\ScsManagerIntegration\Listener\NodeRenamedListener;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;

class Application extends App implements IBootstrap {
	public const APP_ID = 'scs_manager_integration';

	public const PLATFORM_SHARE_EXTERNAL_ID = 'scs-platform-share';
	public const PLATFORM_SHARE_NAME = 'SCS-Share';

	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	#[\Override]
	public function register(IRegistrationContext $context): void {
		$context->registerCapability(Capabilities::class);
		$context->registerEventListener(LoadAdditionalScriptsEvent::class, LoadAdditionalScriptsListener::class);
		$context->registerEventListener(NodeRenamedEvent::class, NodeRenamedListener::class);
		$context->registerEventListener(NodeDeletedEvent::class, NodeDeletedListener::class);
	}

	#[\Override]
	public function boot(IBootContext $context): void {
	}
}
