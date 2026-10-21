export interface Guardian {
  wali_santri_id: number
  source_name: string
  guardian_type: 'father' | 'mother' | 'guardian'
  source_namespace?: string
  source_parent_id?: string
}
export interface Student {
  id: number
  nis: string
  nama_anak: string
  kelas: string
  school_status: string
  legacy_student_id?: number
  source_namespace?: string
  source_student_id?: string
  guardians: Guardian[]
}
export interface Result {
  status: 'ok'
  data: Student[]
  meta: { page: number; limit: number; total: number; scope: 'active' | 'all'; read_only: true; data_source: 'demo' | 'mysql'; schema: 'demo' | 'family' | 'legacy' }
}
export async function readFamilies(endpoint: string, search: string, scope: string, page: number, signal: AbortSignal): Promise<Result> {
  const url = new URL(endpoint, window.location.href)
  if (url.origin !== window.location.origin) throw new Error('Alamat pratinjau tidak valid.')
  url.search = new URLSearchParams({ page: String(page), limit: '25', search, scope }).toString()
  const response = await fetch(url, { method: 'GET', credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' }, signal })
  if (response.status === 401) throw new Error('Sesi login berakhir. Masuk kembali melalui aplikasi utama.')
  if (response.status === 403) throw new Error('Pratinjau tersedia untuk admin dan PJ Tahfidz.')
  if (!response.ok) throw new Error('Pratinjau belum tersedia. Aplikasi utama tetap dapat digunakan.')
  const body: unknown = await response.json()
  const data = body as Partial<Result>
  if (data.status !== 'ok' || !Array.isArray(data.data) || data.meta?.read_only !== true) throw new Error('Data pratinjau belum dapat ditampilkan.')
  return data as Result
}
