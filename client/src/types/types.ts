
export interface Persona {
  name: string,
  key: string,
}

/** Stored assessment subject values. These are pipeline names, not display labels. */
export const ASSESSMENT_SUBJECTS = [
  'MathPipeline',
  'Physics',
  'Dictation',
  'English',
  'German',
  'Spanish',
  'Russian',
  'Law',
  'Other',
] as const
export type AssessmentSubject = (typeof ASSESSMENT_SUBJECTS)[number]

export function isAssessmentSubject(value: string): value is AssessmentSubject {
  return (ASSESSMENT_SUBJECTS as readonly string[]).includes(value)
}

export const ASSESSMENT_FILE_TYPES = ['subject', 'solution', 'submission', 'instructions', 'correction', 'debug'] as const
export type AssessmentFileType = (typeof ASSESSMENT_FILE_TYPES)[number]

export const ASSESSMENT_FILE_TYPE_ZONES = [...ASSESSMENT_FILE_TYPES, 'unknown'] as const
export type AssessmentFileTypeZone = (typeof ASSESSMENT_FILE_TYPE_ZONES)[number]

export interface MenuItem {
  key: string
  label: string
  icon: string
  color: string
}

/** Localised state catalogue: status key => label in one locale. */
export type StateLabelMap = Record<string, string>

export interface StateLocales {
  student_states?: StateLabelMap
  assessment_states?: StateLabelMap
  file_states?: StateLabelMap
}

export interface AssessmentFile {
  id: string
  name: string
  size: number
  created: number
  type?: string
  /** Student hash, or null/empty when unassigned */
  student?: string | null
  student_name?: string | null
  status?: string
  /** Localized status, returned by the server and shown as-is */
  status_label?: string
  content_type?: string
  /** Localized type name */
  label?: string
  menu?: MenuItem[]
  /** True when a queue task is actively processing this file */
  loading?: boolean
}

export interface AssessmentStudent {
  id: string
  name: string
  status?: string
  mark?: number | null
  /** Teacher comment, stored as Markdown */
  appreciation?: string
  menu?: MenuItem[]
  /** True when a queue task is actively processing this student's submission */
  loading?: boolean
}

export interface AssessmentQuestion {
  text: string
  answer: boolean | null
  explanation: string | null
}

export interface SubjectLevelNode {
  level: string
  name: string
}

export interface SubjectCountryNode {
  country: string
  name: string
  levels: SubjectLevelNode[]
}

export interface SubjectNode {
  subject: string
  name: string
  countries: SubjectCountryNode[]
  levels: SubjectLevelNode[]
}

export interface AssessmentStats {
  assessed_students_number?: number
  mark_average?: number | null
  mark_min?: number | null
  mark_max?: number | null
}

export interface Assessment {
  id: string | null
  author: string
  name: string
  subject: string
  country?: string | null
  level?: string | null
  date: string
  verified?: string
  verifier?: string
  locked_by?: string | null
  questions?: AssessmentQuestion[]
  files?: AssessmentFile[]
  students?: AssessmentStudent[]
  assessed_students_number?: number
  mark_average?: number | null
  mark_min?: number | null
  mark_max?: number | null
  /** Localized subject name */
  label?: string
  menu?: MenuItem[]
}
