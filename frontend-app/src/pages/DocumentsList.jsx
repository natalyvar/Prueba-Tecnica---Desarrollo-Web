import { useEffect, useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import {
  AlertTriangle,
  CheckCircle2,
  Clock,
  FileX2,
  Layers,
  Plus,
  Wallet,
} from 'lucide-react'
import { ExpenseDocumentsAPI } from '../api/client.js'
import Filters from '../components/Filters.jsx'
import ConfidenceBadge from '../components/ConfidenceBadge.jsx'

const currency = (value, moneda) =>
  value === null || value === undefined
    ? '—'
    : `${moneda || ''} ${Number(value).toLocaleString('es-CO', { minimumFractionDigits: 2 })}`

export default function DocumentsList() {
  const [filters, setFilters] = useState({ fecha_desde: '', fecha_hasta: '', categoria: '', proveedor: '' })
  const [data, setData] = useState({ data: [], meta: null })
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)

  const load = () => {
    setLoading(true)
    setError(null)
    const params = Object.fromEntries(Object.entries(filters).filter(([, v]) => v))
    ExpenseDocumentsAPI.list(params)
      .then(setData)
      .catch(() => setError('No se pudo cargar el listado de documentos. Verifica que el backend esté corriendo.'))
      .finally(() => setLoading(false))
  }

  useEffect(() => {
    load()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [filters])

  const stats = useMemo(() => {
    const docs = data.data || []
    const pendientes = docs.filter((d) => d.status !== 'revisado').length
    const totalGasto = docs.reduce((sum, d) => sum + (d.total || 0), 0)
    const monedaDominante = docs.find((d) => d.moneda)?.moneda || ''
    return {
      total: data.meta?.total ?? docs.length,
      pendientes,
      totalGasto,
      moneda: monedaDominante,
    }
  }, [data])

  const handleDelete = async (id) => {
    if (!confirm('¿Eliminar este documento? Esta acción no se puede deshacer.')) return
    await ExpenseDocumentsAPI.remove(id)
    load()
  }

  return (
    <div className="page">
      <div className="page-header">
        <div>
          <p className="page-eyebrow">Panel de gastos</p>
          <h2>Documentos registrados</h2>
        </div>
        <Link to="/cargar" className="btn-primary">
          <Plus size={16} strokeWidth={2.4} />
          Cargar documento
        </Link>
      </div>

      <div className="stats-row">
        <div className="stat-card">
          <span className="stat-icon stat-icon-neutral">
            <Layers size={18} strokeWidth={2} />
          </span>
          <div>
            <p className="stat-value">{stats.total}</p>
            <p className="stat-label">Documentos totales</p>
          </div>
        </div>
        <div className="stat-card">
          <span className="stat-icon stat-icon-amber">
            <Clock size={18} strokeWidth={2} />
          </span>
          <div>
            <p className="stat-value">{stats.pendientes}</p>
            <p className="stat-label">Pendientes de revisión</p>
          </div>
        </div>
        <div className="stat-card">
          <span className="stat-icon stat-icon-emerald">
            <Wallet size={18} strokeWidth={2} />
          </span>
          <div>
            <p className="stat-value num">{currency(stats.totalGasto, stats.moneda)}</p>
            <p className="stat-label">Gasto en esta vista</p>
          </div>
        </div>
      </div>

      <Filters filters={filters} onChange={setFilters} />

      {error && (
        <p className="error-text">
          <AlertTriangle size={14} /> {error}
        </p>
      )}

      {loading ? (
        <p className="muted">Cargando…</p>
      ) : data.data?.length === 0 ? (
        <div className="empty-state">
          <FileX2 size={32} strokeWidth={1.5} className="empty-icon" />
          <p>Todavía no hay documentos con estos filtros.</p>
          <Link to="/cargar" className="btn-secondary">
            Cargar el primero
          </Link>
        </div>
      ) : (
        <div className="table-card">
          <table className="ledger-table">
            <thead>
              <tr>
                <th>Proveedor</th>
                <th>Fecha</th>
                <th>Categoría</th>
                <th>Total</th>
                <th>Confianza</th>
                <th>Estado</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              {data.data?.map((doc) => (
                <tr key={doc.id}>
                  <td>
                    <Link to={`/documentos/${doc.id}`} className="row-link">
                      {doc.proveedor || <span className="muted">Sin proveedor</span>}
                    </Link>
                  </td>
                  <td className="num">{doc.fecha || '—'}</td>
                  <td>
                    <span className="chip">{doc.categoria}</span>
                  </td>
                  <td className="num">{currency(doc.total, doc.moneda)}</td>
                  <td>
                    <ConfidenceBadge score={doc.overall_confidence} />
                  </td>
                  <td>
                    <span className={`status status-${doc.status}`}>
                      {doc.status === 'revisado' ? (
                        <CheckCircle2 size={13} strokeWidth={2.4} />
                      ) : (
                        <Clock size={13} strokeWidth={2.4} />
                      )}
                      {doc.status === 'revisado' ? 'Revisado' : 'Pendiente'}
                    </span>
                  </td>
                  <td className="actions">
                    <Link to={`/documentos/${doc.id}`}>Ver / Editar</Link>
                    <button type="button" className="btn-link danger" onClick={() => handleDelete(doc.id)}>
                      Eliminar
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}
