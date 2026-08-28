import type { TConfiguration } from './configuration.types'

import { Configuration } from './configuration'

/**
 *
 */
export function mockConfigurationData(): TConfiguration[] {
	return [
		{
			// full data
			useElastic: true,
			useMongo: true,
		},
		// @ts-expect-error -- useMongo doesn't exist
		{
			// partial data
			useElastic: true,
		},
		{
			// invalid data
			// @ts-expect-error -- useElastic is supposed to be a boolean
			useElastic: 'string',
			useMongo: false,
		},
	]
}

/**
 *
 * @param data
 */
export function mockConfiguration(
	data: TConfiguration[] = mockConfigurationData(),
): TConfiguration[] {
	return data.map((item) => new Configuration(item))
}
