
export interface Persona {
  name: string,
  key: string,
}

export interface CryptoAddress {
  blockchain: string,
  public_key: string,
}

export interface ExamFile {
  name: string
  size: number
  created: number
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
