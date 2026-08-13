<?php

declare(strict_types=1);

namespace OCA\ScsManagerIntegration\Listener;

use OCA\ScsManagerIntegration\Service\SoftProtectionService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\Folder;

/**
 * Soft-sync feed registration when a managed project folder root is renamed.
 *
 * @template-implements IEventListener<NodeRenamedEvent>
 */
class NodeRenamedListener implements IEventListener {
	public function __construct(
		private readonly SoftProtectionService $softProtectionService,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if (!($event instanceof NodeRenamedEvent)) {
			return;
		}

		$source = $event->getSource();
		$target = $event->getTarget();
		// Only react to top-level folder renames (project roots live in the user home).
		if (!($source instanceof Folder) || !($target instanceof Folder)) {
			return;
		}
		if ($source->getParent() === null || $target->getParent() === null) {
			return;
		}
		// User home children have parent path === /{uid} or similar depth-1 under files.
		if (substr_count(trim($source->getPath(), '/'), '/') > 1) {
			return;
		}

		$this->softProtectionService->handleRename($source, $target);
	}
}
