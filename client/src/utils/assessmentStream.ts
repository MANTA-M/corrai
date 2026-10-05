import type { AssessmentFile, AssessmentStats, AssessmentStudent } from '@/types/types'

export interface AssessmentStreamEvent extends AssessmentStats {
  scope: 'assessment' | 'student'
  file?: Partial<AssessmentFile> & { id: string }
  student?: (Partial<AssessmentStudent> & { id: string }) | null
}

function patchById<T extends { id?: string }>(items: T[], patch: Partial<T> & { id: string }): T[] {
  const index = items.findIndex((item) => item.id === patch.id)
  if (index === -1) {
    return [...items, patch as T]
  }
  const next = items.slice()
  next[index] = { ...items[index], ...patch }
  return next
}

export function applyAssessmentStream(
  files: AssessmentFile[],
  students: AssessmentStudent[],
  event: AssessmentStreamEvent,
): { files: AssessmentFile[]; students: AssessmentStudent[] } {
  return {
    files: event.file ? patchById(files, event.file) : files,
    students: event.student ? patchById(students, event.student) : students,
  }
}

export function applyStudentStream(
  files: AssessmentFile[],
  students: AssessmentStudent[],
  _studentId: string,
  event: AssessmentStreamEvent,
): { files: AssessmentFile[]; students: AssessmentStudent[] } {
  return {
    files: event.file ? patchById(files, event.file) : files,
    students: event.student ? patchById(students, event.student) : students,
  }
}
