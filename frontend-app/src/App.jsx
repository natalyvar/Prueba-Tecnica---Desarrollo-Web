import { NavLink, Route, Routes } from 'react-router-dom'
import { FileStack, ReceiptText, UploadCloud } from 'lucide-react'
import DocumentsList from './pages/DocumentsList.jsx'
import DocumentUpload from './pages/DocumentUpload.jsx'
import DocumentDetail from './pages/DocumentDetail.jsx'

export default function App() {
  return (
    <div className="app-shell">
      <aside className="sidebar">
        <div className="sidebar-brand">
          <span className="sidebar-mark">
            <ReceiptText size={20} strokeWidth={2.2} />
          </span>
          <div>
            <p className="sidebar-brand-name">Libro de Gastos</p>
            <p className="sidebar-brand-sub">OCR &amp; revisión</p>
          </div>
        </div>

        <nav className="sidebar-nav">
          <NavLink to="/" end className={({ isActive }) => `sidebar-link${isActive ? ' active' : ''}`}>
            <FileStack size={18} strokeWidth={2} />
            <span>Documentos</span>
          </NavLink>
          <NavLink to="/cargar" className={({ isActive }) => `sidebar-link${isActive ? ' active' : ''}`}>
            <UploadCloud size={18} strokeWidth={2} />
            <span>Cargar documento</span>
          </NavLink>
        </nav>

        <div className="sidebar-footer">
          <p>Prueba técnica</p>
          <p className="sidebar-footer-sub">Laravel + React · OCR con Tesseract</p>
        </div>
      </aside>

      <div className="app-body">
        <main className="app-main">
          <Routes>
            <Route path="/" element={<DocumentsList />} />
            <Route path="/cargar" element={<DocumentUpload />} />
            <Route path="/documentos/:id" element={<DocumentDetail />} />
          </Routes>
        </main>
      </div>
    </div>
  )
}
