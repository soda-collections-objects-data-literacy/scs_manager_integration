<?php

declare(strict_types=1);

namespace OCA\ScsManagerIntegration\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getId()
 * @method void setId(int $id)
 * @method int getGroupFolderId()
 * @method void setGroupFolderId(int $groupFolderId)
 * @method string getFolderName()
 * @method void setFolderName(string $folderName)
 * @method string getMachineName()
 * @method void setMachineName(string $machineName)
 * @method string getExternalProjectId()
 * @method void setExternalProjectId(string $externalProjectId)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $createdBy)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 */
class FeedFolder extends Entity {
	protected int $groupFolderId = 0;
	protected string $folderName = '';
	protected string $machineName = '';
	protected string $externalProjectId = '';
	protected string $createdBy = '';
	protected int $createdAt = 0;

	public function __construct() {
		$this->addType('groupFolderId', 'integer');
		$this->addType('folderName', 'string');
		$this->addType('machineName', 'string');
		$this->addType('externalProjectId', 'string');
		$this->addType('createdBy', 'string');
		$this->addType('createdAt', 'integer');
	}
}
