<?php

declare(strict_types=1);

namespace OCA\ScsManagerIntegration\Command;

use OCA\GroupFolders\Mount\GroupFolderStorage;
use OCA\ScsManagerIntegration\AppInfo\Application;
use OCA\ScsManagerIntegration\Db\FeedFolderMapper;
use OCA\ScsManagerIntegration\Service\GroupFolderBridge;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Move private Skeleton SCS-Share contents into the platform Team Folder.
 *
 * Never deletes files: moves into SCS-Share/<uid>/, renames source to legacy.
 */
class MigratePrivateScsShareCommand extends Command {
	private const MIGRATING_NAME = 'SCS-Share-migrating-TMP';
	private const DEFAULT_ACL_GROUP = 'keycloak-scs_user';

	public function __construct(
		private readonly FeedFolderMapper $mapper,
		private readonly GroupFolderBridge $groupFolderBridge,
		private readonly IConfig $config,
		private readonly IGroupManager $groupManager,
		private readonly IRootFolder $rootFolder,
		private readonly IUserManager $userManager,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure(): void {
		$this
			->setName('scs_manager_integration:migrate-private-scs-share')
			->setDescription('Move private Skeleton SCS-Share folders into the platform Team Folder (no deletes)')
			->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report only; do not move or rename')
			->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'Migrate a single Nextcloud user id')
			->addOption('all', null, InputOption::VALUE_NONE, 'Migrate all users that have a private SCS-Share')
			->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max users to process with --all', '0')
			->addOption('group', 'g', InputOption::VALUE_REQUIRED, 'ACL group to grant on the Team Folder', self::DEFAULT_ACL_GROUP);
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$dryRun = (bool)$input->getOption('dry-run');
		$userOpt = (string)($input->getOption('user') ?? '');
		$all = (bool)$input->getOption('all');
		$limit = max(0, (int)$input->getOption('limit'));
		$aclGroup = trim((string)$input->getOption('group'));
		if ($aclGroup === '') {
			$aclGroup = self::DEFAULT_ACL_GROUP;
		}

		if ($userOpt === '' && !$all) {
			$output->writeln('<error>Specify --user=<uid> or --all</error>');
			return self::FAILURE;
		}
		if ($userOpt !== '' && $all) {
			$output->writeln('<error>Use either --user or --all, not both</error>');
			return self::FAILURE;
		}

		if (!$this->groupFolderBridge->isAvailable()) {
			$output->writeln('<error>groupfolders app is not available</error>');
			return self::FAILURE;
		}

		try {
			$feed = $this->mapper->findByExternalProjectId(Application::PLATFORM_SHARE_EXTERNAL_ID);
		} catch (DoesNotExistException) {
			$output->writeln('<error>Platform SCS-Share Team Folder is not registered. Run ensure-platform-share first.</error>');
			return self::FAILURE;
		}

		$groupFolderId = $feed->getGroupFolderId();
		$output->writeln(sprintf(
			'Platform share feed id=%d groupFolderId=%d dry-run=%s acl-group=%s',
			$feed->getId(),
			$groupFolderId,
			$dryRun ? 'yes' : 'no',
			$aclGroup,
		));

		if (!$dryRun) {
			$this->groupFolderBridge->syncGroups($groupFolderId, array_values(array_unique(['admin', $aclGroup])));
			$output->writeln('Synced Team Folder ACL groups: admin, ' . $aclGroup);
		}

		$uids = $userOpt !== '' ? [$userOpt] : $this->discoverCandidateUids();
		if ($limit > 0 && $all) {
			$uids = array_slice($uids, 0, $limit);
		}

		$totals = [
			'users' => 0,
			'moved' => 0,
			'conflicts' => 0,
			'skipped' => 0,
			'errors' => 0,
		];

		foreach ($uids as $uid) {
			$totals['users']++;
			$result = $this->migrateUser($uid, $groupFolderId, $aclGroup, $dryRun, $output);
			$totals['moved'] += $result['moved'];
			$totals['conflicts'] += $result['conflicts'];
			$totals['skipped'] += $result['skipped'] ? 1 : 0;
			$totals['errors'] += $result['error'] ? 1 : 0;
		}

		$output->writeln(sprintf(
			'Done. users=%d moved=%d conflicts=%d skipped=%d errors=%d',
			$totals['users'],
			$totals['moved'],
			$totals['conflicts'],
			$totals['skipped'],
			$totals['errors'],
		));

		return $totals['errors'] > 0 ? self::FAILURE : self::SUCCESS;
	}

	/**
	 * @return list<string>
	 */
	private function discoverCandidateUids(): array {
		$fromDisk = [];
		$dataDir = $this->config->getSystemValueString('datadirectory', \OC::$SERVERROOT . '/data');
		foreach (glob(rtrim($dataDir, '/') . '/*/files/' . Application::PLATFORM_SHARE_NAME, GLOB_ONLYDIR) ?: [] as $path) {
			$uid = basename(dirname(dirname($path)));
			if ($uid === '' || str_starts_with($uid, 'appdata_')) {
				continue;
			}
			$fromDisk[] = $uid;
		}
		$fromDisk = array_values(array_unique($fromDisk));
		sort($fromDisk);
		return $fromDisk;
	}

	/**
	 * @return array{moved:int,conflicts:int,skipped:bool,error:bool}
	 */
	private function migrateUser(
		string $uid,
		int $groupFolderId,
		string $aclGroup,
		bool $dryRun,
		OutputInterface $output,
	): array {
		$user = $this->userManager->get($uid);
		if ($user === null) {
			$output->writeln("<comment>SKIP $uid — user not found</comment>");
			return ['moved' => 0, 'conflicts' => 0, 'skipped' => true, 'error' => false];
		}

		try {
			\OC_Util::tearDownFS();
			\OC_Util::setupFS($uid);
			$userFolder = $this->rootFolder->getUserFolder($uid);

			$source = $this->resolvePrivateSource($userFolder);
			if ($source === null) {
				$output->writeln("<comment>SKIP $uid — no private SCS-Share</comment>");
				return ['moved' => 0, 'conflicts' => 0, 'skipped' => true, 'error' => false];
			}

			$children = $source->getDirectoryListing();
			$childCount = count($children);
			$output->writeln(sprintf(
				'%s %s — private folder "%s" with %d item(s)',
				$dryRun ? 'DRY' : 'MIGRATE',
				$uid,
				$source->getName(),
				$childCount,
			));

			if ($childCount === 0) {
				$legacyName = $this->legacyName();
				if ($dryRun) {
					$output->writeln("  would rename empty {$source->getName()} → $legacyName");
					return ['moved' => 0, 'conflicts' => 0, 'skipped' => false, 'error' => false];
				}
				$source->move($userFolder->getPath() . '/' . $legacyName);
				$output->writeln("  renamed empty folder → $legacyName");
				return ['moved' => 0, 'conflicts' => 0, 'skipped' => false, 'error' => false];
			}

			if ($dryRun) {
				$output->writeln(sprintf(
					'  would ensure group %s, move %d item(s) into %s/%s/, rename source to legacy',
					$aclGroup,
					$childCount,
					Application::PLATFORM_SHARE_NAME,
					$uid,
				));
				return ['moved' => $childCount, 'conflicts' => 0, 'skipped' => false, 'error' => false];
			}

			// Avoid name clash with Team Folder mount: rename private source first.
			if ($source->getName() === Application::PLATFORM_SHARE_NAME) {
				$source->move($userFolder->getPath() . '/' . self::MIGRATING_NAME);
				$source = $userFolder->get(self::MIGRATING_NAME);
			}

			$this->ensureUserInGroup($user, $aclGroup);
			if (!$this->groupFolderBridge->userCanAccess($user, $groupFolderId)) {
				throw new \RuntimeException('User still cannot access Team Folder after adding group ' . $aclGroup);
			}

			\OC_Util::tearDownFS();
			\OC_Util::setupFS($uid);
			$userFolder = $this->rootFolder->getUserFolder($uid);
			$source = $userFolder->get(self::MIGRATING_NAME);

			$teamShare = $this->requireTeamShareFolder($userFolder, $groupFolderId);
			$dest = $this->getOrCreateSubFolder($teamShare, $uid);

			$moved = 0;
			$conflicts = 0;
			foreach ($source->getDirectoryListing() as $child) {
				[$ok, $wasConflict] = $this->moveChild($child, $dest);
				if ($ok) {
					$moved++;
					if ($wasConflict) {
						$conflicts++;
					}
				}
			}

			$legacyName = $this->legacyName();
			// Refresh source listing; rename remaining container (may still have failed children).
			$source = $userFolder->get(self::MIGRATING_NAME);
			$remaining = count($source->getDirectoryListing());
			$source->move($userFolder->getPath() . '/' . $legacyName);

			$output->writeln(sprintf(
				'  moved=%d conflicts=%d remaining_in_legacy=%d → %s',
				$moved,
				$conflicts,
				$remaining,
				$legacyName,
			));

			return ['moved' => $moved, 'conflicts' => $conflicts, 'skipped' => false, 'error' => false];
		} catch (Throwable $e) {
			$output->writeln("<error>ERROR $uid — {$e->getMessage()}</error>");
			return ['moved' => 0, 'conflicts' => 0, 'skipped' => false, 'error' => true];
		} finally {
			\OC_Util::tearDownFS();
		}
	}

	private function resolvePrivateSource(Folder $userFolder): ?Folder {
		foreach ([Application::PLATFORM_SHARE_NAME, self::MIGRATING_NAME] as $name) {
			if (!$userFolder->nodeExists($name)) {
				continue;
			}
			$node = $userFolder->get($name);
			if (!$node instanceof Folder) {
				continue;
			}
			if ($this->isGroupFolderNode($node)) {
				continue;
			}
			return $node;
		}

		// Already migrated?
		foreach ($userFolder->getDirectoryListing() as $node) {
			if ($node instanceof Folder && str_starts_with($node->getName(), 'SCS-Share-legacy-')) {
				return null;
			}
		}

		return null;
	}

	private function isGroupFolderNode(Node $node): bool {
		$storage = $node->getStorage();
		return $storage->instanceOfStorage(GroupFolderStorage::class)
			|| $storage->instanceOfStorage('\OCA\GroupFolders\Mount\GroupFolderStorage');
	}

	private function ensureUserInGroup(IUser $user, string $groupId): void {
		if (!$this->groupManager->groupExists($groupId)) {
			$created = $this->groupManager->createGroup($groupId);
			if ($created === null) {
				throw new \RuntimeException('Could not create group ' . $groupId);
			}
		}
		$group = $this->groupManager->get($groupId);
		if ($group === null) {
			throw new \RuntimeException('Group not found: ' . $groupId);
		}
		if (!$group->inGroup($user)) {
			$group->addUser($user);
		}
	}

	private function requireTeamShareFolder(Folder $userFolder, int $groupFolderId): Folder {
		if (!$userFolder->nodeExists(Application::PLATFORM_SHARE_NAME)) {
			throw new \RuntimeException('Team Folder mount ' . Application::PLATFORM_SHARE_NAME . ' not visible after ACL grant');
		}
		$node = $userFolder->get(Application::PLATFORM_SHARE_NAME);
		if (!$node instanceof Folder) {
			throw new \RuntimeException(Application::PLATFORM_SHARE_NAME . ' is not a folder');
		}
		if (!$this->isGroupFolderNode($node)) {
			throw new \RuntimeException(Application::PLATFORM_SHARE_NAME . ' is still a private folder; ACL/mount failed');
		}
		// Sanity: accessible group folder id is the platform one (best-effort; mounts may not expose id).
		unset($groupFolderId);
		return $node;
	}

	private function getOrCreateSubFolder(Folder $parent, string $name): Folder {
		if ($parent->nodeExists($name)) {
			$node = $parent->get($name);
			if ($node instanceof Folder) {
				return $node;
			}
			throw new \RuntimeException('Destination exists but is not a folder: ' . $name);
		}
		return $parent->newFolder($name);
	}

	/**
	 * @return array{0:bool,1:bool} [success, wasConflictRename]
	 */
	private function moveChild(Node $child, Folder $dest): array {
		$name = $child->getName();
		$conflict = false;
		if ($dest->nodeExists($name)) {
			$conflict = true;
			$name = $name . '.' . time();
			while ($dest->nodeExists($name)) {
				$name = $child->getName() . '.' . time() . '.' . random_int(1000, 9999);
			}
		}
		$target = $dest->getPath() . '/' . $name;
		$child->move($target);
		return [true, $conflict];
	}

	private function legacyName(): string {
		return 'SCS-Share-legacy-' . gmdate('Ymd');
	}
}
