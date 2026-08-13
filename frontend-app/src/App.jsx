import { NavLink, Route, Routes } from 'react-router-dom'
import DocumentsList from './pages/DocumentsList.jsx'
import DocumentUpload from './pages/DocumentUpload.jsx'
import DocumentDetail from './pages/DocumentDetail.jsx'

export default function App() {
  return (
    <div className="app-shell">
      <header className="app-header">
        <div className="brand">
          <span className="brand-mark">§</span>
          <div>
            <h1>Libro de gastos</h1>
            <p className="brand-sub">Carga, OCR y revisión de comprobantes</p>
          </div>
        </div>
        <nav className="app-nav">
          <NavLink to="/" end className={({ isActive }) => (isActive ? 'active' : '')}>
            Documentos
          </NavLink>
          <NavLink to="/cargar" className={({ isActive }) => (isActive ? 'active' : '')}>
            Cargar documento
          </NavLink>
        </nav>
      </header>

      <main className="app-main">
        <Routes>
          <Route path="/" element={<DocumentsList />} />
          <Route path="/cargar" element={<DocumentUpload />} />
          <Route path="/documentos/:id" element={<DocumentDetail />} />
        </Routes>
      </main>
    </div>
  )
}
