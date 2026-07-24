import { TConfiguration } from './configuration.types'
import { SafeParseReturnType, z } from 'zod'

/**
 * @spec openspec/specs/entity-typescript-models/spec.md
 * @spec openspec/specs/entity-typescript-models/spec.md
 * @spec openspec/specs/entity-typescript-models/spec.md
 */
export class Configuration implements TConfiguration {

	public useElastic!: boolean
	public useMongo!: boolean

	/** @spec openspec/specs/entity-typescript-models/spec.md */
	constructor(data: TConfiguration) {
		this.hydrate(data)
	}

	/* istanbul ignore next */ // Jest does not recognize the code coverage of these 2 methods
	private hydrate(data: TConfiguration) {
		this.useElastic = data?.useElastic || false
		this.useMongo = data?.useMongo || false
	}

	/* istanbul ignore next */
	public validate(): SafeParseReturnType<TConfiguration, unknown> {
		// https://conduction.stoplight.io/docs/open-catalogi/8azwyic71djee-create-listing
		const schema = z.object({
			useElastic: z.boolean(),
			useMongo: z.boolean(),
		})

		const result = schema.safeParse({
			...this,
		})

		return result
	}

}
