<?php

declare(strict_types=1);

namespace OCA\ScsManagerIntegration\Service;

use OCA\ScsManagerIntegration\Db\FeedFolder;
use OCA\ScsManagerIntegration\Db\FeedFolderMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\Node;
use Psr\Log\LoggerInterface;

/**
 * Tracks SCS-managed project folder roots for soft UI protection and cleanup.
 */
class SoftProtectionService {
	public function __construct(
		private readonly FeedFolderMapper $mapper,
		private readonly GroupFolderBridge $groupFolderBridge,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * @return list<array{id:int,groupFolderId:int,name:string,label:string,machineName:string,externalProjectId:string}>
	 */
	public function getManagedFoldersForCapabilities(): array {
		$result = [];
		foreach ($this->mapper->findAll() as $feed) {
			$result[] = [
				'id' => $feed->getId(),
				'groupFolderId' => $feed->getGroupFolderId(),
				'name' => $feed->getFolderName(),
				'label' => $feed->getFolderName(),
				'machineName' => $feed->getMachineName(),
				'externalProjectId' => $feed->getExternalProjectId(),
			];
		}
		return $result;
	}

	public function isManagedMountName(string $name): bool {
		$name = trim($name, '/');
		try {
			$this->mapper->findByFolderName($name);
			return true;
		} catch (DoesNotExistException) {
			return false;
		}
	}

	public function findByNode(Node $node): ?FeedFolder {
		$name = $node->getName();
		try {
			return $this->mapper->findByFolderName($name);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * Keep registration in sync when a managed root is renamed in Files.
	 * Soft protection: allow the rename, update our record + Team Folder mount.
	 */
	public function handleRename(Node $source, Node $target): void {
		$feed = $this->findByNode($source);
		if ($feed === null) {
			// Source name already changed in some flows; try target parent path basename of source path.
			return;
		}

		$newName = $target->getName();
		if ($newName === $feed->getFolderName()) {
			return;
		}

		$this->logger->warning('SCS-managed project folder renamed from {old} to {new} (feed id {id})', [
			'old' => $feed->getFolderName(),
			'new' => $newName,
			'id' => $feed->getId(),
		]);

		try {
			if ($this->groupFolderBridge->isAvailable()) {
				$this->groupFolderBridge->rename($feed->getGroupFolderId(), $newName);
			}
		} catch (\Throwable $e) {
			$this->logger->error('Failed to rename Team Folder for feed {id}: {message}', [
				'id' => $feed->getId(),
				'message' => $e->getMessage(),
			]);
		}

		$feed->setFolderName($newName);
		$this->mapper->update($feed);
	}

	/**
	 * Soft cleanup when a managed root disappears.
	 */
	public function handleDelete(Node $node): void {
		$feed = $this->findByNode($node);
		if ($feed === null) {
			return;
		}

		$this->logger->warning('SCS-managed project folder deleted in Files; removing feed registration {id} ({name})', [
			'id' => $feed->getId(),
			'name' => $feed->getFolderName(),
		]);
		$this->mapper->delete($feed);
	}
}
