import type { AssessmentFile } from '@/types/types'

const SUBJECT_TYPES = new Set(['subject', 'solution', 'instructions'])

export function fileTypeOf(file: AssessmentFile): string {
  return file.type ?? ''
}

export function isSubjectMaterial(file: AssessmentFile): boolean {
  return SUBJECT_TYPES.has(fileTypeOf(file))
}

export function isDebugFile(file: AssessmentFile): boolean {
  return fileTypeOf(file) === 'debug'
}

/** S3 object stored directly under the student directory, such as correction.png. */
export function isDirectStudentFile(file: AssessmentFile): boolean {
  return file.direct === true
}

/** Copies and other files that are not part of the subject and have no student. */
export function isUnassignedFile(file: AssessmentFile, debugMode: boolean): boolean {
  if (isDebugFile(file) && !debugMode) return false
  if (isSubjectMaterial(file)) return false
  return (file.student ?? '').trim() === ''
}

/** Person icon: student copies, and files that are not assigned to anyone. */
export function canReassignFile(file: AssessmentFile): boolean {
  const type = fileTypeOf(file)
  if (type === 'subject' || type === 'solution' || type === 'instructions') return false
  if (type === 'submission' || type === '' || type === 'unknown') return true
  return (file.student ?? '').trim() === ''
}

export function isEditableTextFile(file: AssessmentFile): boolean {
  const type = fileTypeOf(file)
  if (type !== 'instructions' && type !== 'solution') return false
  const contentType = file.content_type ?? ''
  if (contentType.startsWith('text/')) return true
  return /\.txt$/i.test(file.name)
}
