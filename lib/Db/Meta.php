<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\QOwnNotes\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * Cached state of a note, used for ETags and "pruneBefore" of the Notes API
 *
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method int getFileId()
 * @method void setFileId(int $fileId)
 * @method int getLastUpdate()
 * @method void setLastUpdate(int $lastUpdate)
 * @method string getEtag()
 * @method void setEtag(string $etag)
 * @method string getContentEtag()
 * @method void setContentEtag(string $contentEtag)
 * @method string getFileEtag()
 * @method void setFileEtag(string $fileEtag)
 */
class Meta extends Entity {
	protected string $userId = '';
	protected int $fileId = 0;
	protected int $lastUpdate = 0;
	protected string $etag = '';
	protected string $contentEtag = '';
	protected string $fileEtag = '';

	public function __construct() {
		$this->addType('userId', Types::STRING);
		$this->addType('fileId', Types::BIGINT);
		$this->addType('lastUpdate', Types::BIGINT);
		$this->addType('etag', Types::STRING);
		$this->addType('contentEtag', Types::STRING);
		$this->addType('fileEtag', Types::STRING);
	}
}
