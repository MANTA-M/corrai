
export interface Persona {
  name: string,
  key: string,
}

export interface CryptoAddress {
  blockchain: string,
  public_key: string,
}

export const EXAM_FILE_TYPES = ['subject', 'solution', 'submission', 'instructions'] as const
export type ExamFileType = (typeof EXAM_FILE_TYPES)[number]

export const EXAM_FILE_TYPE_ZONES = [...EXAM_FILE_TYPES, 'unknown'] as const
export type ExamFileTypeZone = (typeof EXAM_FILE_TYPE_ZONES)[number]

export interface ExamFile {
  name: string
  size: number
  created: number
  type?: string
  author?: string
}

export interface ExamQuestion {
  text: string
  answer: boolean | null
  explanation: string | null
}

export interface Exam {
  id: string | null
  author: string
  name: string
  subject: string
  date: string
  verified?: string
  verifier?: string
  locked_by?: string | null
  questions?: ExamQuestion[]
  files?: ExamFile[]
}
