/** School cycle (primaire, collège, …) with its year levels. */
export interface EducationCycle {
  code: string
  name: string
  levels: EducationLevel[]
}

export interface EducationLevel {
  code: string
  name: string
}

/**
 * Education cycles and year levels, keyed by ISO 3166-1 alpha-2 country code.
 * Display names follow the country's school system.
 */
export const EDUCATION_LEVELS: Record<string, EducationCycle[]> = {
  fr: [
    {
      code: 'superieur',
      name: 'Supérieur',
      levels: [
        { code: 'bts1', name: 'BTS 1' },
        { code: 'bts2', name: 'BTS 2' },
        { code: 'licence1', name: 'Licence 1' },
        { code: 'licence2', name: 'Licence 2' },
        { code: 'licence3', name: 'Licence 3' },
        { code: 'master1', name: 'Master 1' },
        { code: 'master2', name: 'Master 2' },
        { code: 'doctorat', name: 'Doctorat' },
      ],
    },
    {
      code: 'lycee',
      name: 'Lycée',
      levels: [
        { code: 'seconde', name: 'Seconde' },
        { code: 'premiere', name: 'Première' },
        { code: 'terminale', name: 'Terminale' },
      ],
    },
    {
      code: 'college',
      name: 'Collège',
      levels: [
        { code: '6e', name: '6ème' },
        { code: '5e', name: '5ème' },
        { code: '4e', name: '4ème' },
        { code: '3e', name: '3ème' },
      ],
    },
    {
      code: 'primaire',
      name: 'Primaire',
      levels: [
        { code: 'cp', name: 'CP' },
        { code: 'ce1', name: 'CE1' },
        { code: 'ce2', name: 'CE2' },
        { code: 'cm1', name: 'CM1' },
        { code: 'cm2', name: 'CM2' },
      ],
    },
  ],
}

/** Display name of a year level for a country, or null when that country has no such code. */
export function educationLevelName(country: string, code: string): string | null {
  const cycles = EDUCATION_LEVELS[country]
  if (!cycles) return null
  for (const cycle of cycles) {
    const level = cycle.levels.find(item => item.code === code)
    if (level) return level.name
  }
  return null
}
