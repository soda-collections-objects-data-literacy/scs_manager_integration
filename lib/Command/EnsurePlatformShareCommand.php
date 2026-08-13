<?php

declare(strict_types=1);

namespace OCA\ScsManagerIntegration\Command;

use OCA\ScsManagerIntegration\AppInfo\Application;
use OCA\ScsManagerIntegration\Db\FeedFolderMapper;
use OCA\ScsManagerIntegration\Service\FeedFolderService;
use OCA\ScsManagerIntegration\Service\GroupFolderBridge;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Ensure the platform SCS-Share Team Folder exists and is registered as a feed.
 */
class EnsurePlatformShareCommand extends Command {
	public function __construct(
		private readonly FeedFolderService $feedFolderService,
		private readonly FeedFolderMapper $mapper,
		private readonly GroupFolderBridge $groupFolderBridge,
		private readonly IUserManager $userManager,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure(): void {
		$this
			->setName('scs_manager_integration:ensure-platform-share')
			->setDescription('Create/register the SCS-Share Team Folder as a project feed source')
			->addOption('groups', 'g', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Groups with access', [])
			->addOption('admin', 'a', InputOption::VALUE_REQUIRED, 'Admin user performing the registration', 'admin');
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		if (!$this->groupFolderBridge->isAvailable()) {
			$output->writeln('<error>groupfolders app is not available</error>');
			return self::FAILURE;
		}

		$adminId = (string)$input->getOption('admin');
		$admin = $this->userManager->get($adminId);
		if ($admin === null) {
			$output->writeln('<error>Admin user not found: ' . $adminId . '</error>');
			return self::FAILURE;
		}

		/** @var list<string> $groups */
		$groups = array_values(array_filter(array_map('strval', (array)$input->getOption('groups'))));

		try {
			$existing = $this->mapper->findByExternalProjectId(Application::PLATFORM_SHARE_EXTERNAL_ID);
			$output->writeln('SCS-Share feed already registered (id ' . $existing->getId() . ', groupFolderId ' . $existing->getGroupFolderId() . ')');
			if ($groups !== []) {
				$this->groupFolderBridge->syncGroups($existing->getGroupFolderId(), $groups);
				$output->writeln('Updated group ACLs.');
			}
			return self::SUCCESS;
		} catch (DoesNotExistException) {
			// continue
		}

		$folder = $this->feedFolderService->createOrRegister(
			$admin,
			Application::PLATFORM_SHARE_NAME,
			Application::PLATFORM_SHARE_EXTERNAL_ID,
			$groups,
			true,
			null,
			Application::PLATFORM_SHARE_EXTERNAL_ID,
		);

		$output->writeln('Registered SCS-Share feed id=' . $folder['id'] . ' groupFolderId=' . $folder['groupFolderId']);
		return self::SUCCESS;
	}
}
