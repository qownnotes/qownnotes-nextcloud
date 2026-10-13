<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\QOwnNotes\Tests\unit\Controller;

use OCA\QOwnNotes\AppInfo\Application;
use OCA\QOwnNotes\Controller\NoteHistoryApiController;
use OCA\QOwnNotes\Controller\NotesApiController;
use OCA\QOwnNotes\Controller\QOwnNotesApiController;
use OCA\QOwnNotes\Controller\SubFoldersApiController;
use OCA\QOwnNotes\Controller\TagsApiController;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Expensive writes outside of the note sync are rate-limited, the note sync itself must never be
 */
class RateLimitTest extends TestCase {
	private const RATE_LIMITED = [
		TagsApiController::class => ['create', 'update', 'destroy', 'batch', 'setNoteTags'],
		SubFoldersApiController::class => ['create', 'move', 'destroy'],
		NoteHistoryApiController::class => ['restore'],
		QOwnNotesApiController::class => ['restoreTrashedNote'],
		NotesApiController::class => ['uploadFile', 'deleteAttachment'],
	];

	public function testRateLimitedEndpoints(): void {
		foreach (self::RATE_LIMITED as $class => $expected) {
			$limited = [];
			foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
				$attributes = $method->getAttributes(UserRateLimit::class);
				if ($attributes === []) {
					continue;
				}
				$limit = $attributes[0]->newInstance();
				$this->assertSame(Application::RATE_LIMIT_WRITES, $limit->getLimit());
				$this->assertSame(Application::RATE_LIMIT_PERIOD, $limit->getPeriod());
				$limited[] = $method->getName();
			}

			sort($expected);
			sort($limited);
			$this->assertSame($expected, $limited, $class);
		}
	}
}
