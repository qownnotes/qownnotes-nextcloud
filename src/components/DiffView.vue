<!--
  - SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<!-- eslint-disable-next-line vue/no-v-html -- only <ins> and <del> are kept by DOMPurify -->
	<pre v-if="html !== null" class="diff-view" v-html="sanitizedHtml" />
	<pre v-else class="diff-view"><template v-for="(part, index) in parts" :key="index"><ins v-if="part.type === 'insert'">{{ part.text }}</ins><del v-else-if="part.type === 'delete'">{{ part.text }}</del><template v-else>{{ part.text }}</template></template></pre>
</template>

<script>
import DOMPurify from 'dompurify'
import { diffTexts } from '../utils/diff.js'

export default {
	name: 'DiffView',

	props: {
		/** The old text, compared to `to` in the browser */
		from: {
			type: String,
			default: '',
		},

		to: {
			type: String,
			default: '',
		},

		/** A diff rendered by the server with <ins> and <del>, used instead of `from` and `to` */
		html: {
			type: String,
			default: null,
		},
	},

	computed: {
		parts() {
			return diffTexts(this.from, this.to)
		},

		sanitizedHtml() {
			return DOMPurify.sanitize(this.html ?? '', { ALLOWED_TAGS: ['ins', 'del'], ALLOWED_ATTR: [] })
		},
	},
}
</script>

<style scoped>
.diff-view {
	max-height: 50vh;
	margin: 0;
	padding: 8px;
	overflow: auto;
	font-family: var(--font-face-monospace, monospace);
	white-space: pre-wrap;
	overflow-wrap: anywhere;
	background-color: var(--color-background-dark);
	border-radius: var(--border-radius);
}

.diff-view :deep(ins) {
	text-decoration: none;
	background-color: var(--color-success-hover, rgba(70, 186, 97, 0.3));
}

.diff-view :deep(del) {
	background-color: var(--color-error-hover, rgba(229, 50, 64, 0.3));
}
</style>
