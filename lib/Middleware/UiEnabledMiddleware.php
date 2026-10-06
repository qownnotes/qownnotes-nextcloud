<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\QOwnNotes\Middleware;

use Exception;
use OCA\QOwnNotes\Attribute\RequiresUi;
use OCA\QOwnNotes\Service\AppSettings;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Middleware;
use ReflectionClass;

/**
 * Blocks controllers marked with #[RequiresUi] when the app runs in API-only mode
 */
class UiEnabledMiddleware extends Middleware {
	public function __construct(
		private AppSettings $appSettings,
	) {
	}

	public function beforeController($controller, $methodName): void {
		$reflection = new ReflectionClass($controller);
		if ($reflection->getAttributes(RequiresUi::class) === []) {
			return;
		}

		if (!$this->appSettings->isUiEnabled()) {
			throw new UiDisabledException();
		}
	}

	public function afterException($controller, $methodName, Exception $exception): Response {
		if (!$exception instanceof UiDisabledException) {
			throw $exception;
		}

		$reflection = new ReflectionClass($controller);
		$method = $reflection->getMethod($methodName);
		$returnType = $method->getReturnType();
		$returnTypeName = $returnType instanceof \ReflectionNamedType ? $returnType->getName() : '';

		if (is_a($returnTypeName, TemplateResponse::class, true)) {
			$response = new TemplateResponse('core', '404', [], TemplateResponse::RENDER_AS_GUEST);
			$response->setStatus(Http::STATUS_NOT_FOUND);
			return $response;
		}

		return new JSONResponse(['message' => 'The QOwnNotes web interface is disabled'], Http::STATUS_NOT_FOUND);
	}
}
