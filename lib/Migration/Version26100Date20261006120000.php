<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\QOwnNotes\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Creates the note metadata cache table
 */
class Version26100Date20261006120000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('qownnotes_meta')) {
			return null;
		}

		$table = $schema->createTable('qownnotes_meta');
		$table->addColumn('id', Types::BIGINT, [
			'autoincrement' => true,
			'notnull' => true,
			'unsigned' => true,
		]);
		$table->addColumn('user_id', Types::STRING, [
			'notnull' => true,
			'length' => 64,
		]);
		$table->addColumn('file_id', Types::BIGINT, [
			'notnull' => true,
			'unsigned' => true,
		]);
		$table->addColumn('last_update', Types::BIGINT, [
			'notnull' => true,
			'unsigned' => true,
		]);
		$table->addColumn('etag', Types::STRING, [
			'notnull' => true,
			'length' => 32,
		]);
		$table->addColumn('content_etag', Types::STRING, [
			'notnull' => true,
			'length' => 32,
		]);
		$table->addColumn('file_etag', Types::STRING, [
			'notnull' => true,
			'length' => 64,
		]);
		$table->setPrimaryKey(['id']);
		// The same file can be a note of several users, if it is shared
		$table->addUniqueIndex(['user_id', 'file_id'], 'qownnotes_meta_user_file');

		return $schema;
	}
}
