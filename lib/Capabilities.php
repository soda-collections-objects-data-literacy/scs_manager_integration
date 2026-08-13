<?php

declare(strict_types=1);

namespace OCA\ScsManagerIntegration;

use OCA\ScsManagerIntegration\AppInfo\Application;
use OCA\ScsManagerIntegration\Service\SoftProtectionService;
use OCP\Capabilities\ICapability;

class Capabilities implements ICapability {
	public function __construct(
		private readonly SoftProtectionService $softProtectionService,
	) {
	}

	#[\Override]
	public function getCapabilities(): array {
		return [
			Application::APP_ID => [
				'version' => '0.2.0',
				'managedFolders' => $this->softProtectionService->getManagedFoldersForCapabilities(),
				'softProtection' => true,
			],
		];
	}
}
