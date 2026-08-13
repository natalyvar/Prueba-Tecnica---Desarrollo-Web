import { useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { ExpenseDocumentsAPI } from '../api/client.js'
import ConfidenceBadge from '../components/ConfidenceBadge.jsx'

const CATEGORIES = ['Alimentacion', 'Transporte', 'Tecnologia', 'Servicios', 'Otros']

const FIELD_LABELS = {
  proveedor: 'Proveedor',
  numero_factura: 'Número de factura',
  fecha: 'Fecha',
  subtotal: 'Subtotal',
  impuestos: 'Impuestos',
  total: 'Total',
  moneda: 'Moneda',
}

export default function DocumentDetail() {
  const { id } = useParams()
  const navigate = useNavigate()
  const [doc, setDoc] = useState(null)
  const [form, setForm] = useState(null)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState(null)
  const [showOcrText, setShowOcrText] = useState(false)

  const load = () => {
    ExpenseDocumentsAPI.get(id).then((d) => {
      setDoc(d)
      setForm(d)
    })
  }

  useEffect(load, [id])

  if (!doc || !form) return <p className="muted page">Cargando documento…</p>

  const update = (key, value) => setForm({ ...form, [key]: value })

  const handleSave = async (e) => {
    e.preventDefault()
    setSaving(true)
    setError(null)
    try {
      const updated = await ExpenseDocumentsAPI.update(id, {
        proveedor: form.proveedor,
        numero_factura: form.numero_factura,
        fecha: form.fecha,
        subtotal: form.subtotal,
        impuestos: form.impuestos,
        total: form.total,
        moneda: form.moneda,
        categoria: form.categoria,
        status: 'revisado',
      })
      setDoc(updated)
      setForm(updated)
    } catch (err) {
      setError('No se pudo guardar. Revisa que los montos sean números válidos.')
    } finally {
      setSaving(false)
    }
  }

  const handleDelete = async () => {
    if (!confirm('¿Eliminar este documento?')) return
    await ExpenseDocumentsAPI.remove(id)
    navigate('/')
  }

  const handleReprocess = async () => {
    setSaving(true)
    const updated = await ExpenseDocumentsAPI.reprocess(id)
    setDoc(updated)
    setForm(updated)
    setSaving(false)
  }

  const lowConfidence = new Set(doc.low_confidence_fields || [])

  return (
    <div className="page">
      <Link to="/" className="back-link">
        ← Volver al listado
      </Link>

      <div className="detail-grid">
        <div className="detail-preview">
          <h3>Documento original</h3>
          {doc.mime_type === 'application/pdf' ? (
            <a href={doc.file_url} target="_blank" rel="noreferrer" className="btn-secondary">
              Abrir PDF original
            </a>
          ) : (
            <img src={doc.file_url} alt="Documento cargado" className="preview-image" />
          )}

          <button type="button" className="btn-link" onClick={() => setShowOcrText((s) => !s)}>
            {showOcrText ? 'Ocultar' : 'Ver'} texto extraído por OCR
          </button>
          {showOcrText && <pre className="ocr-text">{doc.ocr_raw_text || '(vacío)'}</pre>}

          <button type="button" className="btn-secondary" onClick={handleReprocess} disabled={saving}>
            Reprocesar OCR + extracción
          </button>
        </div>

        <form className="detail-form" onSubmit={handleSave}>
          <div className="form-header">
            <h3>Información extraída</h3>
            <ConfidenceBadge score={doc.overall_confidence} />
          </div>

          {lowConfidence.size > 0 && (
            <p className="warning-banner">
              ⚠ Hay {lowConfidence.size} campo(s) con baja confianza — revísalos antes de guardar.
            </p>
          )}

          {Object.entries(FIELD_LABELS).map(([key, label]) => (
            <div className="form-field" key={key}>
              <label>
                {label}
                {lowConfidence.has(key) && <span className="dot-warning" title="Baja confianza" />}
              </label>
              <input
                type={key === 'fecha' ? 'date' : ['subtotal', 'impuestos', 'total'].includes(key) ? 'number' : 'text'}
                step={['subtotal', 'impuestos', 'total'].includes(key) ? '0.01' : undefined}
                value={form[key] ?? ''}
                onChange={(e) => update(key, e.target.value)}
                className={lowConfidence.has(key) ? 'input-low-confidence' : ''}
              />
            </div>
          ))}

          <div className="form-field">
            <label>Categoría</label>
            <select value={form.categoria} onChange={(e) => update('categoria', e.target.value)}>
              {CATEGORIES.map((c) => (
                <option key={c} value={c}>
                  {c}
                </option>
              ))}
            </select>
          </div>

          {error && <p className="error-text">{error}</p>}

          <div className="form-actions">
            <button type="submit" className="btn-primary" disabled={saving}>
              {saving ? 'Guardando…' : 'Guardar cambios'}
            </button>
            <button type="button" className="btn-link danger" onClick={handleDelete}>
              Eliminar documento
            </button>
          </div>
        </form>
      </div>
    </div>
  )
}
