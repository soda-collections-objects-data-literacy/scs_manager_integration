<?php

declare(strict_types=1);

namespace OCA\ScsManagerIntegration\Listener;

use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCA\ScsManagerIntegration\AppInfo\Application;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;

/**
 * @template-implements IEventListener<LoadAdditionalScriptsEvent>
 */
class LoadAdditionalScriptsListener implements IEventListener {
	#[\Override]
	public function handle(Event $event): void {
		if (!($event instanceof LoadAdditionalScriptsEvent)) {
			return;
		}

		Util::addScript(Application::APP_ID, 'scs-manager-integration');
		Util::addStyle(Application::APP_ID, 'scs-manager-integration');
	}
}
