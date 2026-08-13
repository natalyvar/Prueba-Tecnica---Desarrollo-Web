const CATEGORIES = ['Alimentacion', 'Transporte', 'Tecnologia', 'Servicios', 'Otros']

export default function Filters({ filters, onChange }) {
  const update = (key, value) => onChange({ ...filters, [key]: value })

  return (
    <div className="filters">
      <div className="filter-field">
        <label>Desde</label>
        <input
          type="date"
          value={filters.fecha_desde || ''}
          onChange={(e) => update('fecha_desde', e.target.value)}
        />
      </div>
      <div className="filter-field">
        <label>Hasta</label>
        <input
          type="date"
          value={filters.fecha_hasta || ''}
          onChange={(e) => update('fecha_hasta', e.target.value)}
        />
      </div>
      <div className="filter-field">
        <label>Categoría</label>
        <select value={filters.categoria || ''} onChange={(e) => update('categoria', e.target.value)}>
          <option value="">Todas</option>
          {CATEGORIES.map((c) => (
            <option key={c} value={c}>
              {c}
            </option>
          ))}
        </select>
      </div>
      <div className="filter-field">
        <label>Proveedor</label>
        <input
          type="text"
          placeholder="Buscar..."
          value={filters.proveedor || ''}
          onChange={(e) => update('proveedor', e.target.value)}
        />
      </div>
      {(filters.fecha_desde || filters.fecha_hasta || filters.categoria || filters.proveedor) && (
        <button
          type="button"
          className="btn-link"
          onClick={() => onChange({ fecha_desde: '', fecha_hasta: '', categoria: '', proveedor: '' })}
        >
          Limpiar filtros
        </button>
      )}
    </div>
  )
}
