<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<div class="woo-index-connection" data-testid="woo-index-connection">
		<h3 id="woo-root-rule" class="woo-index-connection__heading">
			{{ t('opencatalogi', 'Serve robots.txt at the domain root') }}
		</h3>
		<p class="woo-index-connection__hint">
			{{
				t(
					'opencatalogi',
					'The Woo-index reads /robots.txt at the root of your domain. Nextcloud answers that path itself and tells crawlers to stay away. Add one of these rules to your web server, so the root file comes from OpenCatalogi.',
				)
			}}
		</p>
		<p class="woo-index-connection__hint">
			{{
				t(
					'opencatalogi',
					'For Apache, add it to the .htaccess of Nextcloud or to the virtual host. When the reading room runs on its own domain, add the rule on that domain.',
				)
			}}
		</p>

		<div
			v-for="rule in ruleList"
			:key="rule.id"
			class="woo-index-connection__rule">
			<strong>{{ rule.label }}</strong>
			<pre :data-testid="'woo-root-rule-' + rule.id">{{ rule.text }}</pre>
			<NcButton
				variant="secondary"
				:data-testid="'woo-root-rule-copy-' + rule.id"
				@click="copy(rule)">
				{{
					copied === rule.id
						? t('opencatalogi', 'Copied')
						: t('opencatalogi', 'Copy')
				}}
			</NcButton>
		</div>

		<h3 class="woo-index-connection__heading">
			{{ t('opencatalogi', 'Woo-index registration') }}
		</h3>
		<p class="woo-index-connection__hint" data-testid="woo-registration-status">
			{{ statusLabel }}
		</p>
		<p v-if="registration.answer" class="woo-index-connection__hint">
			{{
				t('opencatalogi', 'Answer from the Woo-index: {answer}', {
					answer: registration.answer,
				})
			}}
		</p>

		<div class="woo-index-connection__actions">
			<NcButton
				variant="primary"
				:disabled="busy"
				data-testid="woo-registration-request"
				@click="requestRegistration">
				{{ t('opencatalogi', 'Request registration') }}
			</NcButton>
			<NcButton
				v-if="registration.status === 'requested'"
				variant="secondary"
				:disabled="busy"
				data-testid="woo-registration-confirm"
				@click="confirmRegistration">
				{{ t('opencatalogi', 'Mark as registered') }}
			</NcButton>
		</div>

		<NcNoteCard
			v-if="unsent"
			type="warning"
			data-testid="woo-registration-unsent">
			<p>
				{{
					t(
						'opencatalogi',
						'No gateway took the request, so nothing was sent. Send this request to the Woo-index another way:',
					)
				}}
			</p>
			<pre>{{ unsent }}</pre>
		</NcNoteCard>
		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcNoteCard } from '@nextcloud/vue'

/**
 * The Woo-index connection in the admin settings (REQ-WIH-002, REQ-WIH-003).
 *
 * Shows the two web-server rules that serve the app's robots.txt at the domain
 * root, and requests the Woo-index registration through the gateway. When no
 * gateway takes it, the composed request is shown so it can be sent by hand.
 */
export default {
	name: 'WooIndexConnection',
	components: { NcButton, NcNoteCard },

	data() {
		return {
			registration: {
				status: 'not_registered',
				registeredUrl: '',
				registeredAt: '',
				answer: '',
			},

			rules: { apache: '', nginx: '' },
			busy: false,
			copied: '',
			unsent: '',
			error: '',
		}
	},

	computed: {
		/** @spec openspec/specs/woo-compliance/spec.md#requirement-the-administrator-gets-the-rule-that-serves-robots-txt-at-the-domain-root-req-wih-002 */
		ruleList() {
			return [
				{
					id: 'nginx',
					label: this.t('opencatalogi', 'nginx'),
					text: this.rules.nginx,
				},
				{
					id: 'apache',
					label: this.t('opencatalogi', 'Apache'),
					text: this.rules.apache,
				},
			]
		},

		/** @spec openspec/specs/woo-compliance/spec.md#requirement-the-woo-index-registration-is-requested-through-the-gateway-req-wih-003 */
		statusLabel() {
			const status = this.registration.status
			if (status === 'requested') {
				return this.t(
					'opencatalogi',
					'Requested on {date}. Waiting for the Woo-index to confirm.',
					{ date: this.registration.registeredAt },
				)
			}
			if (status === 'registered') {
				return this.t('opencatalogi', 'Registered on {date} for {url}.', {
					date: this.registration.registeredAt,
					url: this.registration.registeredUrl,
				})
			}
			return this.t('opencatalogi', 'Not registered yet.')
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * Read the registration and the rules.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/woo-compliance/spec.md#requirement-the-administrator-gets-the-rule-that-serves-robots-txt-at-the-domain-root-req-wih-002
		 */
		async load() {
			try {
				const { data } = await axios.get(
					generateUrl('/apps/opencatalogi/api/woo/registration'),
				)
				this.registration = data.registration
				this.rules = data.rules
			} catch {
				this.error = this.t(
					'opencatalogi',
					'The Woo-index connection could not be loaded.',
				)
			}
		},

		/**
		 * Put a rule on the clipboard.
		 *
		 * @param {{id: string, text: string}} rule The rule.
		 * @return {Promise<void>}
		 * @spec openspec/specs/woo-compliance/spec.md#requirement-the-administrator-gets-the-rule-that-serves-robots-txt-at-the-domain-root-req-wih-002
		 */
		async copy(rule) {
			try {
				await navigator.clipboard.writeText(rule.text)
				this.copied = rule.id
			} catch {
				this.error = this.t(
					'opencatalogi',
					'Copying did not work. Select the rule and copy it by hand.',
				)
			}
		},

		/**
		 * Request the registration through the gateway.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/woo-compliance/spec.md#requirement-the-woo-index-registration-is-requested-through-the-gateway-req-wih-003
		 */
		async requestRegistration() {
			this.busy = true
			this.unsent = ''
			this.error = ''
			try {
				const { data } = await axios.post(
					generateUrl('/apps/opencatalogi/api/woo/registration'),
				)
				this.registration = data.registration
			} catch (error) {
				const data = error && error.response ? error.response.data : null
				if (data && data.request) {
					this.unsent = JSON.stringify(data.request, null, 2)
				} else {
					this.error = this.t(
						'opencatalogi',
						'The registration could not be requested. Try again.',
					)
				}
			} finally {
				this.busy = false
			}
		},

		/**
		 * Record that the Woo-index confirmed the registration.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/woo-compliance/spec.md#requirement-the-woo-index-registration-is-requested-through-the-gateway-req-wih-003
		 */
		async confirmRegistration() {
			this.busy = true
			try {
				const { data } = await axios.post(
					generateUrl('/apps/opencatalogi/api/woo/registration/confirm'),
				)
				this.registration = { ...this.registration, ...data.registration }
			} catch {
				this.error = this.t('opencatalogi', 'That did not work. Try again.')
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.woo-index-connection {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.woo-index-connection__heading {
	margin: 12px 0 0;
}

.woo-index-connection__hint {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.woo-index-connection__rule pre {
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
	padding: 8px;
	white-space: pre-wrap;
}

.woo-index-connection__actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}
</style>
