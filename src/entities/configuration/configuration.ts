import { TConfiguration } from './configuration.types'
import { SafeParseReturnType, z } from 'zod'

export class Configuration implements TConfiguration {

	constructor(data?: TConfiguration) {
		this.hydrate(data)
	}

	/* istanbul ignore next */ // Jest does not recognize the code coverage of these 2 methods
	// eslint-disable-next-line @typescript-eslint/no-unused-vars
	private hydrate(data?: TConfiguration) {
		// No configuration properties needed — search backend is managed by OpenRegister.
	}

	/* istanbul ignore next */
	public validate(): SafeParseReturnType<TConfiguration, unknown> {
		const schema = z.object({})

		const result = schema.safeParse({
			...this,
		})

		return result
	}

}
