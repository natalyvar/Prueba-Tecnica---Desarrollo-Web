import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
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

  const handleDelete = async (id) => {
    if (!confirm('¿Eliminar este documento? Esta acción no se puede deshacer.')) return
    await ExpenseDocumentsAPI.remove(id)
    load()
  }

  return (
    <div className="page">
      <div className="page-header">
        <h2>Documentos registrados</h2>
        <Link to="/cargar" className="btn-primary">
          + Cargar documento
        </Link>
      </div>

      <Filters filters={filters} onChange={setFilters} />

      {error && <p className="error-text">{error}</p>}

      {loading ? (
        <p className="muted">Cargando…</p>
      ) : data.data?.length === 0 ? (
        <div className="empty-state">
          <p>Todavía no hay documentos con estos filtros.</p>
          <Link to="/cargar" className="btn-secondary">
            Cargar el primero
          </Link>
        </div>
      ) : (
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
                <td>{doc.fecha || '—'}</td>
                <td>
                  <span className="chip">{doc.categoria}</span>
                </td>
                <td className="num">{currency(doc.total, doc.moneda)}</td>
                <td>
                  <ConfidenceBadge score={doc.overall_confidence} />
                </td>
                <td>
                  <span className={`status status-${doc.status}`}>
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
      )}
    </div>
  )
}
