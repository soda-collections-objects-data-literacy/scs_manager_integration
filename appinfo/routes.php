<?php

declare(strict_types=1);

/**
 * OCS routes for SCS Manager Integration.
 *
 * Full paths (via /ocs/v2.php):
 *   GET    /apps/scs_manager_integration/api/v1/folders
 *   POST   /apps/scs_manager_integration/api/v1/folders
 *   GET    /apps/scs_manager_integration/api/v1/folders/{id}
 *   PATCH  /apps/scs_manager_integration/api/v1/folders/{id}
 *   DELETE /apps/scs_manager_integration/api/v1/folders/{id}
 *   GET    /apps/scs_manager_integration/api/v1/folders/{id}/feed
 */

return [
	'ocs' => [
		[
			'name' => 'Api#listFolders',
			'url' => '/api/v1/folders',
			'verb' => 'GET',
		],
		[
			'name' => 'Api#createFolder',
			'url' => '/api/v1/folders',
			'verb' => 'POST',
		],
		[
			'name' => 'Api#getFolder',
			'url' => '/api/v1/folders/{id}',
			'verb' => 'GET',
			'requirements' => ['id' => '\d+'],
		],
		[
			'name' => 'Api#updateFolder',
			'url' => '/api/v1/folders/{id}',
			'verb' => 'PATCH',
			'requirements' => ['id' => '\d+'],
		],
		[
			'name' => 'Api#deleteFolder',
			'url' => '/api/v1/folders/{id}',
			'verb' => 'DELETE',
			'requirements' => ['id' => '\d+'],
		],
		[
			'name' => 'Api#getFeed',
			'url' => '/api/v1/folders/{id}/feed',
			'verb' => 'GET',
			'requirements' => ['id' => '\d+'],
		],
	],
];
