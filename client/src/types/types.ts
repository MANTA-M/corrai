
export interface Persona {
  name: string,
  key: string,
}

export interface CryptoAddress {
  blockchain: string,
  public_key: string,
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
  content_type?: string
}

export interface AssessmentStudent {
  id: string
  name: string
  status?: string
  mark?: number | null
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
}
