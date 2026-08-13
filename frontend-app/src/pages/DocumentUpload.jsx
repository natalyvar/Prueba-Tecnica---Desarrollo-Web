import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { AlertTriangle, FileCheck2, ScanLine, UploadCloud } from 'lucide-react'
import { ExpenseDocumentsAPI } from '../api/client.js'

export default function DocumentUpload() {
  const [file, setFile] = useState(null)
  const [dragActive, setDragActive] = useState(false)
  const [progress, setProgress] = useState(0)
  const [status, setStatus] = useState('idle') // idle | uploading | processing | error
  const [error, setError] = useState(null)
  const navigate = useNavigate()

  const isBusy = status === 'uploading' || status === 'processing'

  const acceptFile = (f) => {
    if (!f) return
    setFile(f)
    setStatus('idle')
    setError(null)
  }

  const handleDrop = (e) => {
    e.preventDefault()
    setDragActive(false)
    if (isBusy) return
    acceptFile(e.dataTransfer.files?.[0])
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
      <p className="page-eyebrow">Nuevo comprobante</p>
      <h2>Cargar documento de gasto</h2>
      <p className="muted">
        Sube una factura o recibo (JPG, PNG o PDF). El sistema hará OCR automáticamente y
        extraerá proveedor, fecha, montos, moneda y categoría — luego podrás revisarlos y corregirlos.
      </p>

      <form onSubmit={handleSubmit} className="upload-form">
        <label
          className={`dropzone${dragActive ? ' dropzone-active' : ''}${file ? ' dropzone-filled' : ''}`}
          onDragOver={(e) => {
            e.preventDefault()
            if (!isBusy) setDragActive(true)
          }}
          onDragLeave={() => setDragActive(false)}
          onDrop={handleDrop}
        >
          <input
            type="file"
            accept=".jpg,.jpeg,.png,.pdf"
            onChange={(e) => acceptFile(e.target.files?.[0])}
            hidden
            disabled={isBusy}
          />
          {file ? (
            <>
              <FileCheck2 size={30} strokeWidth={1.6} className="dropzone-icon dropzone-icon-ok" />
              <span className="dropzone-filename">{file.name}</span>
              <span className="dropzone-hint">Haz clic para elegir otro archivo</span>
            </>
          ) : (
            <>
              <UploadCloud size={30} strokeWidth={1.6} className="dropzone-icon" />
              <span>Arrastra tu archivo aquí, o haz clic para elegirlo</span>
              <span className="dropzone-hint">JPG, PNG o PDF · máx. 10 MB</span>
            </>
          )}
        </label>

        <button type="submit" className="btn-primary btn-block" disabled={!file || isBusy}>
          {status === 'uploading' && `Subiendo… ${progress}%`}
          {status === 'processing' && (
            <>
              <ScanLine size={16} strokeWidth={2.2} className="spin-icon" />
              Procesando con OCR…
            </>
          )}
          {(status === 'idle' || status === 'error') && 'Cargar y procesar'}
        </button>

        {error && (
          <p className="error-text">
            <AlertTriangle size={14} /> {error}
          </p>
        )}
      </form>
    </div>
  )
}
