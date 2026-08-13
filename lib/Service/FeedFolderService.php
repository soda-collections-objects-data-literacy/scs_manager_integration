<?php

declare(strict_types=1);

namespace OCA\ScsManagerIntegration\Service;

use OCA\ScsManagerIntegration\Db\FeedFolder;
use OCA\ScsManagerIntegration\Db\FeedFolderMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;
use OCP\IUser;
use RuntimeException;

class FeedFolderService {
	public function __construct(
		private readonly FeedFolderMapper $mapper,
		private readonly GroupFolderBridge $groupFolderBridge,
		private readonly IGroupManager $groupManager,
	) {
	}

	/**
	 * @param list<string> $groups
	 * @return array<string, mixed>
	 */
	public function createOrRegister(
		IUser $actor,
		string $label,
		string $externalProjectId,
		array $groups,
		bool $createGroupFolder = true,
		?int $groupFolderId = null,
		?string $machineName = null,
	): array {
		if (!$this->groupFolderBridge->isAvailable()) {
			throw new RuntimeException('groupfolders app is required');
		}

		$label = trim($label, '/');
		$externalProjectId = trim($externalProjectId);
		$machineName = trim((string)($machineName ?? $externalProjectId));
		if ($label === '' || $externalProjectId === '' || $machineName === '') {
			throw new RuntimeException('label (name), externalProjectId and machineName are required');
		}

		try {
			$existingByExternal = $this->mapper->findByExternalProjectId($externalProjectId);
			throw new RuntimeException('externalProjectId already registered as folder id ' . $existingByExternal->getId());
		} catch (DoesNotExistException) {
			// ok
		}

		try {
			$existingByMachine = $this->mapper->findByMachineName($machineName);
			throw new RuntimeException('machineName already registered as folder id ' . $existingByMachine->getId());
		} catch (DoesNotExistException) {
			// ok
		}

		if ($groupFolderId !== null) {
			$gf = $this->groupFolderBridge->getFolder($groupFolderId);
			if ($gf === null) {
				throw new RuntimeException('groupFolderId not found');
			}
			$label = $gf->mountPoint;
			if ($groups !== []) {
				$this->groupFolderBridge->syncGroups($groupFolderId, $groups);
			}
		} elseif ($createGroupFolder) {
			$groupFolderId = $this->groupFolderBridge->createWithGroups($label, $groups);
		} else {
			$gf = $this->groupFolderBridge->findByMountPoint($label);
			if ($gf === null) {
				throw new RuntimeException('Team Folder "' . $label . '" not found; pass createGroupFolder=true to create it');
			}
			$groupFolderId = $gf->id;
			if ($groups !== []) {
				$this->groupFolderBridge->syncGroups($groupFolderId, $groups);
			}
		}

		try {
			$existingByGf = $this->mapper->findByGroupFolderId($groupFolderId);
			throw new RuntimeException('groupFolderId already registered as folder id ' . $existingByGf->getId());
		} catch (DoesNotExistException) {
			// ok
		}

		$feed = new FeedFolder();
		$feed->setGroupFolderId($groupFolderId);
		$feed->setFolderName($label);
		$feed->setMachineName($machineName);
		$feed->setExternalProjectId($externalProjectId);
		$feed->setCreatedBy($actor->getUID());
		$feed->setCreatedAt(time());
		$feed = $this->mapper->insert($feed);

		return $this->serialize($feed, $actor, true);
	}

	/**
	 * Update the display label (Team Folder mount point). machineName stays immutable.
	 *
	 * @param list<string>|null $groups Optional ACL refresh.
	 * @return array<string, mixed>
	 */
	public function updateLabel(IUser $actor, int $id, string $label, ?array $groups = null): array {
		if (!$this->groupFolderBridge->isAvailable()) {
			throw new RuntimeException('groupfolders app is required');
		}

		$label = trim($label, '/');
		if ($label === '') {
			throw new RuntimeException('label must not be empty');
		}

		$feed = $this->mapper->find($id);
		if ($label !== $feed->getFolderName()) {
			try {
				$conflict = $this->mapper->findByFolderName($label);
				if ($conflict->getId() !== $feed->getId()) {
					throw new RuntimeException('Another managed folder already uses label "' . $label . '"');
				}
			} catch (DoesNotExistException) {
				// ok
			}
			$this->groupFolderBridge->rename($feed->getGroupFolderId(), $label);
			$feed->setFolderName($label);
			$feed = $this->mapper->update($feed);
		}

		if ($groups !== null) {
			$this->groupFolderBridge->syncGroups($feed->getGroupFolderId(), $groups);
		}

		return $this->serialize($feed, $actor, true);
	}

	/**
	 * @param list<string> $groups
	 * @return array<string, mixed>
	 */
	public function syncFolderGroups(IUser $actor, int $id, array $groups): array {
		$feed = $this->mapper->find($id);
		$this->groupFolderBridge->syncGroups($feed->getGroupFolderId(), $groups);
		return $this->serialize($feed, $actor, true);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function listForUser(IUser $user): array {
		if ($this->groupManager->isAdmin($user->getUID())) {
			$feeds = $this->mapper->findAll();
		} else {
			if (!$this->groupFolderBridge->isAvailable()) {
				return [];
			}
			$accessibleIds = array_map(
				static fn ($folder): int => $folder->id,
				$this->groupFolderBridge->getFoldersForUser($user),
			);
			$feeds = $this->mapper->findByGroupFolderIds($accessibleIds);
		}

		$result = [];
		foreach ($feeds as $feed) {
			$result[] = $this->serialize($feed, $user, false);
		}
		return $result;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getForUser(IUser $user, int $id): array {
		$feed = $this->mapper->find($id);
		if (!$this->groupFolderBridge->userCanAccess($user, $feed->getGroupFolderId())) {
			throw new DoesNotExistException('Managed folder not accessible');
		}
		return $this->serialize($feed, $user, true);
	}

	public function getEntityForUser(IUser $user, int $id): FeedFolder {
		$feed = $this->mapper->find($id);
		if (!$this->groupFolderBridge->userCanAccess($user, $feed->getGroupFolderId())) {
			throw new DoesNotExistException('Managed folder not accessible');
		}
		return $feed;
	}

	/**
	 * Resolve by Drupal/external project id (admin orchestration).
	 *
	 * @throws DoesNotExistException
	 */
	public function getEntityByExternalProjectId(string $externalProjectId): FeedFolder {
		return $this->mapper->findByExternalProjectId($externalProjectId);
	}

	/**
	 * @return array{removed:bool,storageDeleted:bool}
	 */
	public function delete(IUser $actor, int $id, bool $deleteStorage = false): array {
		$feed = $this->mapper->find($id);
		$groupFolderId = $feed->getGroupFolderId();
		$this->mapper->delete($feed);

		$storageDeleted = false;
		if ($deleteStorage && $this->groupFolderBridge->isAvailable()) {
			$this->groupFolderBridge->remove($groupFolderId);
			$storageDeleted = true;
		}

		return [
			'removed' => true,
			'storageDeleted' => $storageDeleted,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function serialize(FeedFolder $feed, IUser $user, bool $includeGroups): array {
		$groups = [];
		$path = '/' . $feed->getFolderName();
		$rootId = null;

		if ($this->groupFolderBridge->isAvailable()) {
			$gf = $this->groupFolderBridge->getFolder($feed->getGroupFolderId());
			if ($gf !== null) {
				$path = '/' . $gf->mountPoint;
				$rootId = $gf->rootId;
				if ($includeGroups) {
					$groups = array_keys($gf->groups);
				}
			}
		}

		return [
			'id' => $feed->getId(),
			'groupFolderId' => $feed->getGroupFolderId(),
			'name' => $feed->getFolderName(),
			'label' => $feed->getFolderName(),
			'machineName' => $feed->getMachineName(),
			'path' => $path,
			'externalProjectId' => $feed->getExternalProjectId(),
			'rootFileId' => $rootId,
			'createdBy' => $feed->getCreatedBy(),
			'createdAt' => $feed->getCreatedAt(),
			'managed' => true,
			'groups' => $groups,
			'accessible' => $this->groupFolderBridge->isAvailable()
				? $this->groupFolderBridge->userCanAccess($user, $feed->getGroupFolderId())
				: false,
		];
	}
}
