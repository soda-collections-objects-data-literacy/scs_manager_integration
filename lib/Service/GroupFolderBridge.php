<?php

declare(strict_types=1);

namespace OCA\ScsManagerIntegration\Service;

use OCA\GroupFolders\Folder\FolderDefinition;
use OCA\GroupFolders\Folder\FolderManager;
use OCA\GroupFolders\Folder\FolderWithMappingsAndCache;
use OCP\App\IAppManager;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\Server;
use Psr\Container\ContainerExceptionInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Thin wrapper around the Team Folders (groupfolders) FolderManager.
 */
class GroupFolderBridge {
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
	) {
	}

	public function isAvailable(): bool {
		return $this->appManager->isEnabledForUser('groupfolders')
			&& $this->resolveManager() !== null;
	}

	private function resolveManager(): ?FolderManager {
		if (!$this->appManager->isEnabledForUser('groupfolders')) {
			return null;
		}
		try {
			return Server::get(FolderManager::class);
		} catch (ContainerExceptionInterface|\Throwable) {
			return null;
		}
	}

	private function manager(): FolderManager {
		$manager = $this->resolveManager();
		if ($manager === null) {
			throw new RuntimeException('The groupfolders app is not available');
		}
		return $manager;
	}

	/**
	 * @return array<int, FolderDefinition>
	 */
	public function getAllFolders(): array {
		return $this->manager()->getAllFolders();
	}

	public function getFolder(int $id): ?FolderWithMappingsAndCache {
		return $this->manager()->getFolder($id);
	}

	/**
	 * @return list<FolderDefinition>
	 */
	public function getFoldersForUser(IUser $user): array {
		return $this->manager()->getFoldersForUser($user);
	}

	public function findByMountPoint(string $mountPoint): ?FolderWithMappingsAndCache {
		$mountPoint = trim($mountPoint, '/');
		foreach ($this->manager()->getAllFolders() as $folder) {
			if ($folder->mountPoint === $mountPoint) {
				return $this->manager()->getFolder($folder->id);
			}
		}
		return null;
	}

	/**
	 * Create a Team Folder and attach groups with full permissions.
	 *
	 * @param list<string> $groups
	 */
	public function createWithGroups(string $mountPoint, array $groups): int {
		$mountPoint = trim($mountPoint, '/');
		if ($mountPoint === '') {
			throw new RuntimeException('Mount point must not be empty');
		}

		$existing = $this->findByMountPoint($mountPoint);
		if ($existing !== null) {
			$id = $existing->id;
			$this->syncGroups($id, $groups);
			return $id;
		}

		$id = $this->manager()->createFolder($mountPoint);
		$this->syncGroups($id, $groups);
		return $id;
	}

	/**
	 * @param list<string> $groups
	 */
	public function syncGroups(int $folderId, array $groups): void {
		$folder = $this->manager()->getFolder($folderId);
		if ($folder === null) {
			throw new RuntimeException('Group folder ' . $folderId . ' not found');
		}

		$desired = [];
		foreach ($groups as $groupId) {
			$groupId = trim((string)$groupId);
			if ($groupId === '') {
				continue;
			}
			if ($this->manager()->isACircle($groupId)) {
				$desired[$groupId] = true;
				continue;
			}
			if (!$this->groupManager->groupExists($groupId)) {
				$created = $this->groupManager->createGroup($groupId);
				if ($created === null) {
					$this->logger->warning('SCS manager integration: could not create Nextcloud group {group}', [
						'group' => $groupId,
					]);
					continue;
				}
				$this->logger->info('SCS manager integration: created Nextcloud group {group}', [
					'group' => $groupId,
				]);
			}
			$desired[$groupId] = true;
		}

		// PHP casts numeric string keys to int; GroupFolders requires string group ids.
		$current = array_map('strval', array_keys($folder->groups));
		foreach ($current as $groupId) {
			if (!isset($desired[$groupId]) && !isset($desired[(int) $groupId])) {
				$this->manager()->removeApplicableGroup($folderId, $groupId);
			}
		}
		foreach (array_keys($desired) as $groupId) {
			$groupId = (string) $groupId;
			if (!in_array($groupId, $current, true)) {
				$this->manager()->addApplicableGroup($folderId, $groupId);
			}
		}
	}

	public function rename(int $folderId, string $newMountPoint): void {
		$this->manager()->renameFolder($folderId, trim($newMountPoint, '/'));
	}

	public function remove(int $folderId): void {
		$this->manager()->removeFolder($folderId);
	}

	public function userCanAccess(IUser $user, int $folderId): bool {
		if ($this->groupManager->isAdmin($user->getUID())) {
			return true;
		}
		foreach ($this->getFoldersForUser($user) as $folder) {
			if ($folder->id === $folderId) {
				return true;
			}
		}
		return false;
	}
}
