/** One `rows[]` item of `GET /attendance/site-day`: workers first, then the manager's engineers. No pay figures. */
export type SiteDayRow = {
  employee: { id: string; code: number; name_ar: string; name_en: string | null; type: string }
  section: 'workers' | 'engineers'
  day: { state: 'stored' | 'derived' | 'missing' | 'future'; version: number | null; site_id: string | null; leave_code: string | null; in_time: string | null; out_time: string | null; note: string | null }
}

export type SiteDay = { site: { id: string; name_ar: string; name_en: string | null }; date: string; today: string; rows: SiteDayRow[] }

export const MARKS = ['present', 'absent', 'sick'] as const
export type Mark = (typeof MARKS)[number]

/** The status button a stored day presses: a work-site day is present, the absent and sick leave days their own; anything else none. */
export function markOf({ state, site_id, leave_code }: SiteDayRow['day']): Mark | null {
  if (state !== 'stored') return null
  if (leave_code === null) return site_id ? 'present' : null
  return leave_code === 'absent' || leave_code === 'sick' ? leave_code : null
}
