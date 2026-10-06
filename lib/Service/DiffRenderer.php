<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\QOwnNotes\Service;

/**
 * Renders the differences between two texts as HTML with <ins> and <del> tags, as expected by the version
 * dialog of QOwnNotes Desktop (text is HTML-escaped, newlines are kept as they are)
 */
class DiffRenderer {
	/** Maximum edit distance for the Myers diff, which bounds memory and time */
	private const MAX_EDIT_DISTANCE = 600;

	private const EQUAL = 0;
	private const DELETE = 1;
	private const INSERT = 2;

	public function render(string $from, string $to): string {
		$operations = $this->diffTokens(self::tokenize($from, false), self::tokenize($to, false))
			?? $this->diffTokens(self::tokenize($from, true), self::tokenize($to, true))
			?? [[self::DELETE, $from], [self::INSERT, $to]];

		$html = '';
		foreach (self::mergeOperations($operations) as [$operation, $text]) {
			if ($text === '') {
				continue;
			}
			$escaped = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
			$html .= match ($operation) {
				self::DELETE => '<del>' . $escaped . '</del>',
				self::INSERT => '<ins>' . $escaped . '</ins>',
				default => $escaped,
			};
		}

		return $html;
	}

	/**
	 * Splits a text into words, whitespace and single other characters, or into lines
	 *
	 * @return list<string>
	 */
	private static function tokenize(string $text, bool $lines): array {
		if ($text === '') {
			return [];
		}

		$pattern = $lines ? '/[^\n]*\n|[^\n]+$/u' : '/\s+|[\p{L}\p{N}_]+|./su';
		preg_match_all($pattern, $text, $matches);
		return $matches[0];
	}

	/**
	 * Myers diff; returns null if the texts differ more than the maximum edit distance
	 *
	 * @param list<string> $a
	 * @param list<string> $b
	 * @return list<array{int, string}>|null
	 */
	private function diffTokens(array $a, array $b): ?array {
		// Common prefix and suffix don't need to be diffed
		$prefix = 0;
		$n = count($a);
		$m = count($b);
		while ($prefix < $n && $prefix < $m && $a[$prefix] === $b[$prefix]) {
			$prefix++;
		}
		$suffix = 0;
		while ($suffix < $n - $prefix && $suffix < $m - $prefix && $a[max(0, $n - 1 - $suffix)] === $b[max(0, $m - 1 - $suffix)]) {
			$suffix++;
		}

		$head = [[self::EQUAL, implode('', array_slice($a, 0, $prefix))]];
		$tail = [[self::EQUAL, implode('', array_slice($a, $n - $suffix))]];
		$a = array_slice($a, $prefix, $n - $prefix - $suffix);
		$b = array_slice($b, $prefix, $m - $prefix - $suffix);
		$n = count($a);
		$m = count($b);

		if ($n === 0 || $m === 0) {
			return [...$head, [self::DELETE, implode('', $a)], [self::INSERT, implode('', $b)], ...$tail];
		}

		$max = min($n + $m, self::MAX_EDIT_DISTANCE);
		$v = [1 => 0];
		$trace = [];
		for ($d = 0; $d <= $max; $d++) {
			$trace[] = $v;
			for ($k = -$d; $k <= $d; $k += 2) {
				$x = ($k === -$d || ($k !== $d && ($v[$k - 1] ?? -1) < ($v[$k + 1] ?? -1)))
					? ($v[$k + 1] ?? 0)
					: ($v[$k - 1] ?? 0) + 1;
				$y = $x - $k;
				while ($x < $n && $y < $m && $a[$x] === $b[$y]) {
					$x++;
					$y++;
				}
				$v[$k] = $x;

				if ($x >= $n && $y >= $m) {
					return [...$head, ...$this->backtrack($trace, $a, $b, $n, $m), ...$tail];
				}
			}
		}

		return null;
	}

	/**
	 * @param list<array<int, int>> $trace
	 * @param list<string> $a
	 * @param list<string> $b
	 * @return list<array{int, string}>
	 */
	private function backtrack(array $trace, array $a, array $b, int $x, int $y): array {
		$operations = [];
		for ($d = count($trace) - 1; $d > 0; $d--) {
			$v = $trace[$d];
			$k = $x - $y;
			$prevK = ($k === -$d || ($k !== $d && ($v[$k - 1] ?? -1) < ($v[$k + 1] ?? -1))) ? $k + 1 : $k - 1;
			$prevX = $v[$prevK] ?? 0;
			$prevY = $prevX - $prevK;

			while ($x > $prevX && $y > $prevY) {
				$operations[] = [self::EQUAL, $a[--$x]];
				$y--;
			}

			if ($x === $prevX) {
				$operations[] = [self::INSERT, $b[--$y]];
			} else {
				$operations[] = [self::DELETE, $a[--$x]];
			}
		}

		while ($x > 0 && $y > 0) {
			$operations[] = [self::EQUAL, $a[--$x]];
			$y--;
		}

		return array_reverse($operations);
	}

	/**
	 * Joins consecutive operations of the same type; deletions are placed before insertions
	 *
	 * @param list<array{int, string}> $operations
	 * @return list<array{int, string}>
	 */
	private static function mergeOperations(array $operations): array {
		$merged = [];
		$texts = [self::EQUAL => '', self::DELETE => '', self::INSERT => ''];

		// A final equal operation flushes the pending changes
		foreach ([...$operations, [self::EQUAL, '']] as [$operation, $text]) {
			if ($operation === self::EQUAL) {
				foreach ([self::DELETE, self::INSERT] as $change) {
					if ($texts[$change] !== '') {
						$merged[] = [$change, $texts[$change]];
						$texts[$change] = '';
					}
				}
			} elseif ($texts[self::EQUAL] !== '') {
				$merged[] = [self::EQUAL, $texts[self::EQUAL]];
				$texts[self::EQUAL] = '';
			}

			$texts[$operation] .= $text;
		}

		if ($texts[self::EQUAL] !== '') {
			$merged[] = [self::EQUAL, $texts[self::EQUAL]];
		}

		return $merged;
	}
}
