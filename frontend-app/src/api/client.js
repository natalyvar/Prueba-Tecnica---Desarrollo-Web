import axios from 'axios'

const API_BASE_URL = import.meta.env.VITE_API_URL || 'http://localhost:8000/api'

export const api = axios.create({
  baseURL: API_BASE_URL,
})

export const ExpenseDocumentsAPI = {
  // El listado usa Resource::collection(...) -> ya viene como { data: [...], meta, links }
  // así que aquí SÍ dejamos el objeto completo (el frontend lee data.data).
  list: (params) => api.get('/expense-documents', { params }).then((r) => r.data),

  // Estos devuelven un solo ExpenseDocumentResource -> Laravel lo envuelve como { data: {...} }.
  // Por eso desenvolvemos con r.data.data para obtener el objeto plano del documento.
  get: (id) => api.get(`/expense-documents/${id}`).then((r) => r.data.data),
  upload: (file, onUploadProgress) => {
    const formData = new FormData()
    formData.append('file', file)
    return api
      .post('/expense-documents', formData, {
        headers: { 'Content-Type': 'multipart/form-data' },
        onUploadProgress,
      })
      .then((r) => r.data.data)
  },
  update: (id, payload) => api.put(`/expense-documents/${id}`, payload).then((r) => r.data.data),
  reprocess: (id) => api.post(`/expense-documents/${id}/reprocess`).then((r) => r.data.data),

  // Este no devuelve un resource, solo un mensaje -> se deja tal cual.
  remove: (id) => api.delete(`/expense-documents/${id}`).then((r) => r.data),
}
