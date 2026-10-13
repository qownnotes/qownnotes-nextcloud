/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Positions in the preview of the source lines that start its blocks, ordered by line and position
 *
 * @param {HTMLElement} container the scrolling element of the preview
 * @return {Array<{line: number, top: number}>} line (0-based) and position in the scrolled content
 */
export function previewAnchors(container) {
	const offset = container.getBoundingClientRect().top - container.scrollTop
	const anchors = [{ line: 0, top: 0 }]
	for (const element of container.querySelectorAll('[data-source-line]')) {
		const line = Number(element.dataset.sourceLine)
		const top = element.getBoundingClientRect().top - offset
		const last = anchors[anchors.length - 1]
		// Nested blocks start at the same line, e.g. a list and its first item
		if (line > last.line && top > last.top) {
			anchors.push({ line, top })
		}
	}
	return anchors
}

/**
 * The position of a source line in the preview, interpolated between the blocks around it
 *
 * @param {Array<{line: number, top: number}>} anchors see previewAnchors
 * @param {number} line the line (0-based, with a fraction for the part of the line that is scrolled out of view)
 * @param {number} lineCount number of lines of the note
 * @param {number} height height of the scrolled content
 * @return {number}
 */
export function previewOffsetOfLine(anchors, line, lineCount, height) {
	if (line <= 0) {
		return 0
	}
	const points = [...anchors, { line: Math.max(lineCount, anchors[anchors.length - 1].line + 1), top: height }]
	for (let i = 1; i < points.length; i++) {
		if (line < points[i].line) {
			const previous = points[i - 1]
			return previous.top + (line - previous.line) / (points[i].line - previous.line) * (points[i].top - previous.top)
		}
	}
	return height
}

/**
 * The source line at a position in the preview, the inverse of previewOffsetOfLine
 *
 * @param {Array<{line: number, top: number}>} anchors see previewAnchors
 * @param {number} offset the position in the scrolled content
 * @param {number} lineCount number of lines of the note
 * @param {number} height height of the scrolled content
 * @return {number}
 */
export function lineOfPreviewOffset(anchors, offset, lineCount, height) {
	if (offset <= 0) {
		return 0
	}
	const points = [...anchors, { line: Math.max(lineCount, anchors[anchors.length - 1].line + 1), top: height }]
	for (let i = 1; i < points.length; i++) {
		if (offset < points[i].top) {
			const previous = points[i - 1]
			return previous.line + (offset - previous.top) / (points[i].top - previous.top) * (points[i].line - previous.line)
		}
	}
	return points[points.length - 1].line
}
