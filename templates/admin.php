<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/** @var \OCP\IL10N $l */
/** @var array $_ */
?>
<div id="qownnotes-admin-settings" class="section">
	<h2><?php p($l->t('QOwnNotes')); ?></h2>

	<form method="post" action="<?php p($_['saveUrl']); ?>">
		<input type="hidden" name="requesttoken" value="<?php p($_['requesttoken']); ?>">
		<input type="hidden" name="uiEnabled" value="0">
		<p>
			<input type="checkbox" class="checkbox" id="qownnotes-ui-enabled" name="uiEnabled" value="1"
				<?php if ($_['uiEnabled']) {
					p('checked');
				} ?>>
			<label for="qownnotes-ui-enabled"><?php p($l->t('Enable web interface')); ?></label>
		</p>
		<p class="settings-hint">
			<?php p($l->t('When disabled, only the APIs for QOwnNotes Desktop and Android are available.')); ?>
		</p>

		<h3><?php p($l->t('Tags')); ?></h3>
		<p>
			<?php if ($_['tagsAvailable']) {
				p($l->t('Tags of the note folder database "notes.sqlite" are supported.'));
			} else {
				p($l->t('The PHP extension "pdo_sqlite" is missing, so tags of the note folder database "notes.sqlite" are not available.'));
			} ?>
		</p>
		<p>
			<label for="qownnotes-tag-database-max-size"><?php p($l->t('Maximum size of "notes.sqlite" (MiB)')); ?></label>
			<input type="number" id="qownnotes-tag-database-max-size" name="tagDatabaseMaxSizeMib"
				min="1" max="<?php p($_['tagDatabaseMaxSizeLimitMib']); ?>" step="1"
				value="<?php p($_['tagDatabaseMaxSizeMib']); ?>">
		</p>
		<p class="settings-hint">
			<?php p($l->t('Larger note folder databases are rejected, because they are copied and checked for every change of tags.')); ?>
		</p>

		<p>
			<input type="submit" value="<?php p($l->t('Save')); ?>">
		</p>
	</form>
</div>
