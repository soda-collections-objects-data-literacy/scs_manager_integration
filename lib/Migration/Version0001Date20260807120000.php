<?php

declare(strict_types=1);

namespace OCA\ScsManagerIntegration\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version0001Date20260807120000 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('scs_manager_integration')) {
			$table = $schema->createTable('scs_manager_integration');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->addColumn('group_folder_id', Types::INTEGER, [
				'notnull' => true,
			]);
			$table->addColumn('folder_name', Types::STRING, [
				'notnull' => true,
				'length' => 255,
			]);
			$table->addColumn('machine_name', Types::STRING, [
				'notnull' => true,
				'length' => 255,
			]);
			$table->addColumn('external_project_id', Types::STRING, [
				'notnull' => true,
				'length' => 255,
			]);
			$table->addColumn('created_by', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('created_at', Types::INTEGER, [
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['group_folder_id'], 'scs_mi_gf_id_uniq');
			$table->addUniqueIndex(['external_project_id'], 'scs_mi_ext_id_uniq');
			$table->addUniqueIndex(['machine_name'], 'scs_mi_machine_uniq');
		}

		return $schema;
	}
}
