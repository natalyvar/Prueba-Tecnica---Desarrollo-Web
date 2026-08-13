import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { ExpenseDocumentsAPI } from '../api/client.js'

export default function DocumentUpload() {
  const [file, setFile] = useState(null)
  const [progress, setProgress] = useState(0)
  const [status, setStatus] = useState('idle') // idle | uploading | processing | error
  const [error, setError] = useState(null)
  const navigate = useNavigate()

  const handleFile = (e) => {
    const f = e.target.files?.[0]
    if (f) setFile(f)
  }

  const handleSubmit = async (e) => {
    e.preventDefault()
    if (!file) return

    setStatus('uploading')
    setError(null)

    try {
      const doc = await ExpenseDocumentsAPI.upload(file, (evt) => {
        const pct = Math.round((evt.loaded * 100) / evt.total)
        setProgress(pct)
        if (pct === 100) setStatus('processing')
      })
      navigate(`/documentos/${doc.id}`)
    } catch (err) {
      setStatus('error')
      setError(
        err?.response?.data?.message ||
          'No se pudo procesar el documento. Verifica el formato (JPG, PNG o PDF) y que el backend esté corriendo.'
      )
    }
  }

  return (
    <div className="page page-narrow">
      <h2>Cargar documento de gasto</h2>
      <p className="muted">
        Sube una factura o recibo (JPG, PNG o PDF). El sistema hará OCR automáticamente y
        extraerá proveedor, fecha, montos, moneda y categoría — luego podrás revisarlos y corregirlos.
      </p>

      <form onSubmit={handleSubmit} className="upload-form">
        <label className="dropzone">
          <input type="file" accept=".jpg,.jpeg,.png,.pdf" onChange={handleFile} hidden />
          {file ? (
            <span>{file.name}</span>
          ) : (
            <span>Haz clic para seleccionar un archivo (JPG, PNG o PDF, máx. 10 MB)</span>
          )}
        </label>

        <button type="submit" className="btn-primary" disabled={!file || status === 'uploading' || status === 'processing'}>
          {status === 'uploading' && `Subiendo… ${progress}%`}
          {status === 'processing' && 'Procesando con OCR…'}
          {(status === 'idle' || status === 'error') && 'Cargar y procesar'}
        </button>

        {error && <p className="error-text">{error}</p>}
      </form>
    </div>
  )
}
