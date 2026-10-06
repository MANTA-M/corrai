import { test, expect } from '@playwright/test'
import { applyAssessmentStream, applyStudentStream, type AssessmentStreamEvent } from '../src/utils/assessmentStream'
import type { AssessmentFile, AssessmentStudent } from '../src/types/types'

test.describe('Assessment stream loading state', () => {
  const initialFiles: AssessmentFile[] = [
    {
      id: 'file-1',
      name: 'copy1.png',
      size: 1024,
      created: 1700000000,
      status: 'stored',
      status_label: 'Stocké',
      student: null,
      loading: false,
    },
    {
      id: 'file-2',
      name: 'copy2.png',
      size: 2048,
      created: 1700000001,
      status: 'ocr_done',
      status_label: 'OCR terminé',
      student: 'student-1',
      loading: false,
    },
  ]

  const initialStudents: AssessmentStudent[] = [
    {
      id: 'student-1',
      name: 'Marie Curie',
      status: '',
      mark: null,
      loading: false,
    },
  ]

  test('applies loading: true when task begins, and loading: false when task ends', () => {
    // 1. Task begins on file-1
    const taskStartEvent: AssessmentStreamEvent = {
      scope: 'assessment',
      file: {
        id: 'file-1',
        loading: true,
      },
    }

    const afterStart = applyAssessmentStream(initialFiles, initialStudents, taskStartEvent)
    const file1AfterStart = afterStart.files.find((f) => f.id === 'file-1')
    expect(file1AfterStart).toBeDefined()
    expect(file1AfterStart?.loading).toBe(true)
    // Other properties are preserved
    expect(file1AfterStart?.name).toBe('copy1.png')
    expect(file1AfterStart?.status).toBe('stored')

    // 2. Task completes on file-1 with status update
    const taskEndEvent: AssessmentStreamEvent = {
      scope: 'assessment',
      file: {
        id: 'file-1',
        status: 'errors_found',
        status_label: 'Erreurs trouvées',
        loading: false,
      },
    }

    const afterEnd = applyAssessmentStream(afterStart.files, afterStart.students, taskEndEvent)
    const file1AfterEnd = afterEnd.files.find((f) => f.id === 'file-1')
    expect(file1AfterEnd).toBeDefined()
    expect(file1AfterEnd?.loading).toBe(false)
    expect(file1AfterEnd?.status).toBe('errors_found')
    expect(file1AfterEnd?.status_label).toBe('Erreurs trouvées')
  })

  test('applies loading: true and false to student stream', () => {
    // 1. Task begins for student's file
    const startEvent: AssessmentStreamEvent = {
      scope: 'student',
      file: {
        id: 'file-2',
        loading: true,
      },
      student: {
        id: 'student-1',
        loading: true,
      },
    }

    const afterStart = applyStudentStream(initialFiles, initialStudents, 'student-1', startEvent)
    expect(afterStart.files.find((f) => f.id === 'file-2')?.loading).toBe(true)
    expect(afterStart.students.find((s) => s.id === 'student-1')?.loading).toBe(true)

    // 2. Task ends
    const endEvent: AssessmentStreamEvent = {
      scope: 'student',
      file: {
        id: 'file-2',
        status: 'corrected',
        status_label: 'Corrigé',
        loading: false,
      },
      student: {
        id: 'student-1',
        status: 'graded',
        mark: 18.5,
        loading: false,
      },
    }

    const afterEnd = applyStudentStream(afterStart.files, afterStart.students, 'student-1', endEvent)
    const file2 = afterEnd.files.find((f) => f.id === 'file-2')
    const student1 = afterEnd.students.find((s) => s.id === 'student-1')

    expect(file2?.loading).toBe(false)
    expect(file2?.status).toBe('corrected')
    expect(student1?.loading).toBe(false)
    expect(student1?.mark).toBe(18.5)
  })
})
