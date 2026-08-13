<?php

declare(strict_types=1);

namespace OCA\ScsManagerIntegration\Listener;

use OCA\ScsManagerIntegration\Service\SoftProtectionService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\Folder;

/**
 * Soft-cleanup feed registration when a managed project folder root is deleted.
 *
 * @template-implements IEventListener<NodeDeletedEvent>
 */
class NodeDeletedListener implements IEventListener {
	public function __construct(
		private readonly SoftProtectionService $softProtectionService,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if (!($event instanceof NodeDeletedEvent)) {
			return;
		}

		$node = $event->getNode();
		if (!($node instanceof Folder)) {
			return;
		}
		if (substr_count(trim($node->getPath(), '/'), '/') > 1) {
			return;
		}

		$this->softProtectionService->handleDelete($node);
	}
}
