<?php

declare(strict_types=1);

namespace OCA\ScsManagerIntegration\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<FeedFolder>
 */
class FeedFolderMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'scs_manager_integration', FeedFolder::class);
	}

	/**
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 * @throws Exception
	 */
	public function find(int $id): FeedFolder {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $this->findEntity($qb);
	}

	/**
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 * @throws Exception
	 */
	public function findByGroupFolderId(int $groupFolderId): FeedFolder {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('group_folder_id', $qb->createNamedParameter($groupFolderId, IQueryBuilder::PARAM_INT)));

		return $this->findEntity($qb);
	}

	/**
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 * @throws Exception
	 */
	public function findByExternalProjectId(string $externalProjectId): FeedFolder {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('external_project_id', $qb->createNamedParameter($externalProjectId)));

		return $this->findEntity($qb);
	}

	/**
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 * @throws Exception
	 */
	public function findByMachineName(string $machineName): FeedFolder {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('machine_name', $qb->createNamedParameter($machineName)));

		return $this->findEntity($qb);
	}

	/**
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 * @throws Exception
	 */
	public function findByFolderName(string $folderName): FeedFolder {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('folder_name', $qb->createNamedParameter($folderName)));

		return $this->findEntity($qb);
	}

	/**
	 * @param list<int> $groupFolderIds
	 * @return list<FeedFolder>
	 * @throws Exception
	 */
	public function findByGroupFolderIds(array $groupFolderIds): array {
		if ($groupFolderIds === []) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->in('group_folder_id', $qb->createNamedParameter($groupFolderIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->orderBy('folder_name', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * @return list<FeedFolder>
	 * @throws Exception
	 */
	public function findAll(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->orderBy('folder_name', 'ASC');

		return $this->findEntities($qb);
	}
}
