<?php

declare(strict_types=1);

namespace OCA\ScsManagerIntegration\Exception;

use RuntimeException;

/**
 * A Team Folder mount point is already used by another folder.
 *
 * The API answers 409 so the SCS Manager can ask for a different project name
 * instead of attaching the project to someone else's folder.
 */
class FolderConflictException extends RuntimeException {
}
