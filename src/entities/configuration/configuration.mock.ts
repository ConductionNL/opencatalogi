import { Configuration } from './configuration'
import { TConfiguration } from './configuration.types'

export const mockConfigurationData = (): TConfiguration[] => [
	{}, // full data (empty — no configuration properties)
	{}, // partial data
	{}, // invalid data
]

export const mockConfiguration = (data: TConfiguration[] = mockConfigurationData()): TConfiguration[] => data.map(item => new Configuration(item))
