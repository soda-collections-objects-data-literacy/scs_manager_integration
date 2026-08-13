<?php

declare(strict_types=1);

namespace OCA\ScsManagerIntegration\Controller;

use OCA\ScsManagerIntegration\Service\FeedFolderService;
use OCA\ScsManagerIntegration\Service\FolderFeedService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\AppFramework\OCSController;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use RuntimeException;

class ApiController extends OCSController {
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly FeedFolderService $feedFolderService,
		private readonly FolderFeedService $folderFeedService,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
	) {
		parent::__construct($appName, $request);
	}

	private function requireUser(): IUser {
		$user = $this->userSession->getUser();
		if (!$user instanceof IUser) {
			throw new OCSForbiddenException('Not logged in');
		}
		return $user;
	}

	private function requireAdmin(IUser $user): void {
		if (!$this->groupManager->isAdmin($user->getUID())) {
			throw new OCSForbiddenException('Admin privileges required');
		}
	}

	/**
	 * @return list<string>
	 */
	private function parseGroups(mixed $groupsParam): array {
		if (is_string($groupsParam)) {
			$decoded = json_decode($groupsParam, true);
			$groups = is_array($decoded) ? $decoded : array_filter(array_map('trim', explode(',', $groupsParam)));
		} elseif (is_array($groupsParam)) {
			$groups = $groupsParam;
		} else {
			$groups = [];
		}
		return array_values(array_map('strval', $groups));
	}

	/**
	 * List managed project folders visible to the current user.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function listFolders(): DataResponse {
		$user = $this->requireUser();
		return new DataResponse([
			'folders' => $this->feedFolderService->listForUser($user),
		]);
	}

	/**
	 * Register (and optionally create) a Team Folder for an SCS project.
	 *
	 * Body: name|label, machineName, externalProjectId, groups[], createGroupFolder, groupFolderId?
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function createFolder(): DataResponse {
		$user = $this->requireUser();
		$this->requireAdmin($user);

		$label = (string)$this->request->getParam('label', '');
		if ($label === '') {
			$label = (string)$this->request->getParam('name', '');
		}
		$machineName = (string)$this->request->getParam('machineName', '');
		$externalProjectId = (string)$this->request->getParam('externalProjectId', '');
		$groups = $this->parseGroups($this->request->getParam('groups', []));
		$createGroupFolder = filter_var(
			$this->request->getParam('createGroupFolder', true),
			FILTER_VALIDATE_BOOLEAN,
			FILTER_NULL_ON_FAILURE,
		);
		if ($createGroupFolder === null) {
			$createGroupFolder = true;
		}
		$groupFolderIdParam = $this->request->getParam('groupFolderId');
		$groupFolderId = $groupFolderIdParam !== null && $groupFolderIdParam !== ''
			? (int)$groupFolderIdParam
			: null;

		try {
			$folder = $this->feedFolderService->createOrRegister(
				$user,
				$label,
				$externalProjectId,
				$groups,
				$createGroupFolder,
				$groupFolderId,
				$machineName !== '' ? $machineName : null,
			);
		} catch (RuntimeException $e) {
			throw new OCSBadRequestException($e->getMessage());
		}

		return new DataResponse(['folder' => $folder], Http::STATUS_OK);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function getFolder(int $id): DataResponse {
		$user = $this->requireUser();
		try {
			$folder = $this->feedFolderService->getForUser($user, $id);
		} catch (DoesNotExistException) {
			throw new OCSNotFoundException('Managed folder not found');
		}
		return new DataResponse(['folder' => $folder]);
	}

	/**
	 * Update display label and/or group ACLs. machineName is immutable.
	 *
	 * Body: label|name?, groups[]?
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function updateFolder(int $id): DataResponse {
		$user = $this->requireUser();
		$this->requireAdmin($user);

		$label = (string)$this->request->getParam('label', '');
		if ($label === '') {
			$label = (string)$this->request->getParam('name', '');
		}
		$groupsParam = $this->request->getParam('groups');
		$groups = $groupsParam !== null ? $this->parseGroups($groupsParam) : null;

		try {
			if ($label !== '') {
				$folder = $this->feedFolderService->updateLabel($user, $id, $label, $groups);
			} elseif ($groups !== null) {
				$folder = $this->feedFolderService->syncFolderGroups($user, $id, $groups);
			} else {
				throw new RuntimeException('Provide label and/or groups to update');
			}
		} catch (DoesNotExistException) {
			throw new OCSNotFoundException('Managed folder not found');
		} catch (RuntimeException $e) {
			throw new OCSBadRequestException($e->getMessage());
		}

		return new DataResponse(['folder' => $folder]);
	}

	/**
	 * Deregister a managed folder. Pass deleteStorage=1 to also remove the Team Folder.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function deleteFolder(int $id): DataResponse {
		$user = $this->requireUser();
		$this->requireAdmin($user);

		$deleteStorage = filter_var(
			$this->request->getParam('deleteStorage', false),
			FILTER_VALIDATE_BOOLEAN,
		);

		try {
			$result = $this->feedFolderService->delete($user, $id, $deleteStorage);
		} catch (DoesNotExistException) {
			throw new OCSNotFoundException('Managed folder not found');
		} catch (RuntimeException $e) {
			throw new OCSBadRequestException($e->getMessage());
		}

		return new DataResponse($result);
	}

	/**
	 * Activity feed for a project folder (ACL-filtered).
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function getFeed(int $id, int $limit = 50, int $since = 0): DataResponse {
		$user = $this->requireUser();
		try {
			$feed = $this->feedFolderService->getEntityForUser($user, $id);
		} catch (DoesNotExistException) {
			throw new OCSNotFoundException('Managed folder not found');
		}

		$items = $this->folderFeedService->getFeed($user, $feed, $limit, $since);
		return new DataResponse([
			'folderId' => $id,
			'externalProjectId' => $feed->getExternalProjectId(),
			'machineName' => $feed->getMachineName(),
			'name' => $feed->getFolderName(),
			'label' => $feed->getFolderName(),
			'items' => $items,
		]);
	}
}
