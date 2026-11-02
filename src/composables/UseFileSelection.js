import { useDropZone, useFileDialog } from '@vueuse/core'
import { computed, ref } from 'vue'
import { objectStore } from './../store/store.js'

/**
 * File selection composable
 *
 * @param {Array} options
 *
 * Special thanks to Github user adamreisnz for creating most of this file
 * https://github.com/adamreisnz
 * https://github.com/vueuse/vueuse/issues/4085
 *
 * @spec openspec/specs/file-management/spec.md
 */
export function useFileSelection(options) {
	// Extract options
	const { dropzone, allowMultiple, allowedFileTypes, onFileDrop, onFileSelect } =
		options

	// Data types computed ref
	const dataTypes = computed(() => {
		if (allowedFileTypes) {
			if (!Array.isArray(allowedFileTypes)) {
				return [allowedFileTypes]
			}
			return allowedFileTypes
		}
		return null
	})

	let tags = []
	const setTags = (_tags) => {
		tags = _tags
	}

	// Accept string computed ref
	const accept = computed(() => {
		if (Array.isArray(dataTypes.value)) {
			return dataTypes.value.join(',')
		}
		return '*'
	})

	// Reactive snapshot of the most recent duplicate-rejection event.
	// Shape: { names: string[], seq: number } where seq increments on every
	// drop so identical-content arrays still trigger watchers (a plain
	// string-array ref reassigned with the same contents would not).
	const rejectedDuplicates = ref({ names: [], seq: 0 })

	// The names of the files a store list holds: each file's name, or its
	// title when it has no name. A list that cannot be read holds none.
	const namesIn = (readList) => {
		try {
			const list = readList()
			const results = Array.isArray(list?.results) ? list.results : []
			return results.map((f) => f?.name || f?.title).filter(Boolean)
		} catch (_) {
			return []
		}
	}

	const relatedFiles = () => objectStore.getRelatedData
		&& objectStore.getRelatedData('publication', 'files')
	const attachmentCollection = () => objectStore.getCollection
		&& objectStore.getCollection('publicationAttachments')

	// Build the set of filenames that should block a new drop: everything
	// currently queued plus everything already attached to the publication.
	// Reads from both possible sources because depending on how the user got
	// to the modal one or the other may be the populated one:
	//   - relatedData.publication.files is what ViewObject fetches and renders
	//   - collections.publicationAttachments is what PublicationDetail / a prior
	//     upload populates
	const getExistingNames = () => {
		const queued = Array.isArray(filesList.value)
			? filesList.value.map((f) => f.name)
			: []
		return new Set([...queued, ...namesIn(relatedFiles), ...namesIn(attachmentCollection)])
	}

	// Wrap a raw File into a new File with tags/status metadata.
	const wrapFile = (file) => {
		const newFile = new File([file], file.name, {
			type: file.type,
			lastModified: file.lastModified,
		})
		Object.defineProperty(newFile, 'tags', {
			value: tags,
			writable: true,
			enumerable: true,
		})
		Object.defineProperty(newFile, 'status', {
			value: 'pending',
			writable: true,
			enumerable: true,
		})
		return newFile
	}

	// The files of one drop as an array; without allowMultiple only the first.
	const dropList = (files) => {
		const list = files instanceof FileList ? Array.from(files) : files
		return list.length > 1 && !allowMultiple ? [list[0]] : list
	}

	// Cross-session + within-session duplicate filter: splits the dropped
	// files into the ones to add and the names already queued, attached, or
	// earlier in the same drop.
	const splitDuplicates = (files) => {
		const existingNames = getExistingNames()
		const rejected = []
		const accepted = []
		for (const file of files) {
			if (existingNames.has(file.name)) {
				rejected.push(file.name)
			} else {
				accepted.push(file)
				existingNames.add(file.name)
			}
		}
		return { accepted, rejected }
	}

	// Appends to a non-empty list when multiple files are allowed, otherwise
	// replaces the list; a drop that added nothing keeps the list.
	const addFiles = (wrapped) => {
		if (
			allowMultiple
			&& Array.isArray(filesList.value)
			&& filesList.value.length > 0
		) {
			filesList.value = [...filesList.value, ...wrapped]
		} else {
			filesList.value = wrapped.length > 0 ? wrapped : filesList.value
		}
	}

	// Handling of files drop. The duplicate filter applies to every drop
	// path (initial, additive, single-file) so the rule is uniform.
	const onDrop = (files) => {
		if (!files || files.length === 0) {
			return
		}
		const { accepted, rejected } = splitDuplicates(dropList(files))
		rejectedDuplicates.value = {
			names: rejected,
			seq: rejectedDuplicates.value.seq + 1,
		}
		addFiles(accepted.map(wrapFile))

		onFileDrop && onFileDrop()
		onFileSelect && onFileSelect()
	}

	const reset = (name = null) => {
		if (name) {
			filesList.value =
				filesList.value.filter((file) => file.name !== name).length > 0
					? filesList.value.filter((file) => file.name !== name)
					: null
		} else {
			filesList.value = null
		}
	}
	const setFiles = (files) => {
		filesList.value = files
		objectStore.setActiveObject('attachment', null)
	}

	// Setup dropzone and file dialog composables
	const { isOverDropZone } = useDropZone(dropzone, { dataTypes: null, onDrop })
	const {
		onChange,
		open,
		reset: resetFileDialog,
	} = useFileDialog({
		accept: accept.value,
		multiple: allowMultiple,
		// Required so picking the same filename twice in a row still fires
		// change — without this the browser's <input type="file"> dedupes
		// against its own .files list and our onDrop never runs.
		reset: true,
	})

	const filesList = ref(null)

	// Use onChange handler. After consuming the FileList, also clear the
	// underlying input so the same filename can be re-selected later (covers
	// the duplicate-warning re-trigger case).
	onChange((fileList) => {
		onDrop(fileList)
		try {
			resetFileDialog && resetFileDialog()
		} catch (_) {
			/* ignore */
		}
	})

	// Expose interface
	return {
		isOverDropZone,
		openFileUpload: open,
		files: filesList,
		reset,
		setFiles,
		setTags,
		rejectedDuplicates,
	}
}
