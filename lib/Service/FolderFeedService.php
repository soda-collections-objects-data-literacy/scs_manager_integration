<?php

declare(strict_types=1);

namespace OCA\ScsManagerIntegration\Service;

use OCA\ScsManagerIntegration\Db\FeedFolder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUser;

/**
 * Loads activity rows scoped to a registered project folder tree.
 */
class FolderFeedService {
	public function __construct(
		private readonly IDBConnection $db,
	) {
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function getFeed(IUser $user, FeedFolder $feed, int $limit = 50, int $since = 0): array {
		$limit = max(1, min(200, $limit));
		$mount = trim($feed->getFolderName(), '/');
		if ($mount === '') {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('activity_id', 'timestamp', 'type', 'user', 'subject', 'subjectparams', 'file', 'object_type', 'object_id', 'app')
			->from('activity')
			->where($qb->expr()->eq('affecteduser', $qb->createNamedParameter($user->getUID())))
			->andWhere($qb->expr()->orX(
				$qb->expr()->eq('file', $qb->createNamedParameter('/' . $mount)),
				$qb->expr()->eq('file', $qb->createNamedParameter($mount)),
				$qb->expr()->like('file', $qb->createNamedParameter('/' . $this->escapeLike($mount) . '/%')),
				$qb->expr()->like('file', $qb->createNamedParameter($this->escapeLike($mount) . '/%')),
			))
			->orderBy('timestamp', 'DESC')
			->addOrderBy('activity_id', 'DESC')
			->setMaxResults($limit);

		if ($since > 0) {
			$qb->andWhere($qb->expr()->lt('timestamp', $qb->createNamedParameter($since, IQueryBuilder::PARAM_INT)));
		}

		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();

		$items = [];
		foreach ($rows as $row) {
			$path = (string)($row['file'] ?? '');
			$path = ltrim($path, '/');
			$name = basename($path);
			$subjectParams = [];
			if (!empty($row['subjectparams'])) {
				$decoded = json_decode((string)$row['subjectparams'], true);
				if (is_array($decoded)) {
					$subjectParams = $decoded;
				}
			}

			$items[] = [
				'id' => (string)$row['activity_id'],
				'type' => (string)$row['type'],
				'timestamp' => (int)$row['timestamp'],
				'user' => (string)$row['user'],
				'subjectKey' => (string)$row['subject'],
				'subject' => $this->formatSubject((string)$row['subject'], $subjectParams, $path),
				'app' => (string)$row['app'],
				'object' => [
					'name' => $name,
					'path' => $path,
					'fileId' => (int)$row['object_id'],
					'type' => (string)$row['object_type'],
				],
			];
		}

		return $items;
	}

	private function escapeLike(string $value): string {
		return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
	}

	/**
	 * @param array<string, mixed> $params
	 */
	private function formatSubject(string $subject, array $params, string $path): string {
		// Activity subjects are translation keys; expose a readable fallback for consumers.
		$file = $params['file'] ?? $params['path'] ?? $path;
		if (is_array($file)) {
			$file = $file['path'] ?? $file['name'] ?? $path;
		}
		$file = (string)$file;

		return match ($subject) {
			'created_self', 'created_by' => 'Created ' . $file,
			'changed_self', 'changed_by' => 'Changed ' . $file,
			'deleted_self', 'deleted_by' => 'Deleted ' . $file,
			'restored_self', 'restored_by' => 'Restored ' . $file,
			'renamed_self', 'renamed_by' => 'Renamed ' . $file,
			'moved_self', 'moved_by' => 'Moved ' . $file,
			default => $subject !== '' ? $subject . ($file !== '' ? ': ' . $file : '') : $file,
		};
	}
}
