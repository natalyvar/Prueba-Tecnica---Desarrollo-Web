# Gestión de documentos de gastos (OCR + extracción de información)

Prueba técnica: aplicación para cargar facturas/recibos (JPG, PNG, PDF), extraer
texto por OCR, convertir ese texto en campos estructurados, marcar qué tan confiable
es cada campo, y permitir que un usuario revise, corrija y gestione esos documentos.

## Stack y decisiones

| Capa | Elección | Por qué |
|---|---|---|
| Backend | **Laravel 11**  |
| Frontend | **React + Vite** | SPA simple, sin necesidad de SSR. |
| Base de datos | **SQLite** | Cero configuración para levantar el proyecto en minutos; el diseño (migraciones Eloquent estándar) es portable a MySQL/PostgreSQL solo cambiando `.env`. |
| OCR | **Tesseract** (vía `thiagoalessio/tesseract_ocr`) | Motor open source, corre 100% local, sin API keys ni costos — ideal para una prueba técnica reproducible. Los PDF se rasterizan a imagen con Imagick/Ghostscript antes del OCR. |
| Extracción de campos | **Reglas + regex** sobre el texto del OCR (`ExpenseExtractionService`) | No depende de un LLM externo (sin API key), es determinística, rápida, y fácil de auditar/depurar. El servicio está aislado, así que cambiarlo por un LLM en el futuro es solo reemplazar esta clase. |
| Confiabilidad | Score 0.0–1.0 por campo (`field_confidence`, JSON) + score global | Ver sección "Estrategia de confiabilidad" abajo. |

### Estrategia de confiabilidad (punto 4 del enunciado)

Cada campo extraído recibe un score:

- **1.0** → se encontró una etiqueta explícita e inequívoca en el texto (ej. "Total: $45.000").
- **0.5–0.6** → se obtuvo por una heurística de respaldo (ej. proveedor = primera línea no numérica del documento; total = el monto con `$` más grande cuando no hay etiqueta "Total").
- **0.0** → no se encontró nada; el campo queda `null` para que el usuario lo complete.

Campos con score < 0.6 se marcan como "dudosos": en el frontend aparecen con un badge
de advertencia (⚠) y el input se resalta en ámbar, para que el usuario los revise
antes de confiar en ellos. Esto evita que un dato mal leído por el OCR (ej. un "8"
leído como "3") se guarde silenciosamente como si fuera correcto.

### Trazabilidad

El texto crudo del OCR se guarda completo en `ocr_raw_text`, y el archivo original
queda almacenado y enlazado al registro (`file_path`). Desde el detalle del
documento se puede ver el texto de OCR y el archivo original, y también
"reprocesar" (volver a correr OCR + extracción) si se ajusta la lógica.

## Estructura del repositorio

```
gestion-gastos/
├── backend/     # Laravel — API REST (este paquete NO incluye el esqueleto de Laravel,
│                  ver "Paso a paso" para generarlo)
│   ├── app/Models/ExpenseDocument.php
│   ├── app/Services/OcrService.php                 <- Etapa 2: OCR
│   ├── app/Services/ExpenseExtractionService.php    <- Etapa 3 y 4: extracción + confiabilidad
│   ├── app/Http/Controllers/Api/ExpenseDocumentController.php
│   ├── app/Http/Requests/UpdateExpenseDocumentRequest.php
│   ├── app/Http/Resources/ExpenseDocumentResource.php
│   ├── database/migrations/..._create_expense_documents_table.php
│   ├── routes/api.php
│   ├── config/cors.php
│   ├── composer-requirements.md
│   └── .env.example
└── frontend/    # React + Vite — SPA completa
    ├── src/pages/DocumentsList.jsx      <- Etapa 6 y 7: listado + filtros
    ├── src/pages/DocumentUpload.jsx     <- Etapa 1: carga
    ├── src/pages/DocumentDetail.jsx     <- Etapa 5: revisión humana
    ├── src/components/Filters.jsx
    ├── src/components/ConfidenceBadge.jsx
    ├── src/api/client.js
    └── src/index.css
```

> **Nota:** este paquete trae solo los archivos *propios* de la aplicación (los que
> tienen la lógica de negocio), no el esqueleto completo de Laravel (que son cientos
> de archivos de framework generados por Composer). El paso a paso de abajo genera
> ese esqueleto y luego copia estos archivos encima.

## Paso a paso para ejecutar el proyecto

### 0. Requisitos previos

- PHP 8.2+ y Composer
- Node.js 18+
- Motor de OCR a nivel de sistema operativo:

  ```bash
  # Ubuntu / Debian
  sudo apt-get update
  sudo apt-get install -y tesseract-ocr tesseract-ocr-spa imagemagick ghostscript php-imagick

  # macOS
  brew install tesseract tesseract-lang imagemagick ghostscript
  ```

  Si `php-imagick` no está disponible en tu entorno, revisa la nota al final de
  `backend/composer-requirements.md` para usar `pdftoppm` como alternativa.

### 1. Backend (Laravel)

```bash
# Generar el esqueleto de Laravel en una carpeta nueva
composer create-project laravel/laravel backend-app "^11.0"
cd backend-app

# Copiar los archivos de esta entrega ENCIMA del esqueleto recién creado
cp -r ../gestion-gastos/backend/app/Models/ExpenseDocument.php app/Models/
cp -r ../gestion-gastos/backend/app/Services app/
cp -r ../gestion-gastos/backend/app/Http/Controllers/Api app/Http/Controllers/
cp -r ../gestion-gastos/backend/app/Http/Requests/UpdateExpenseDocumentRequest.php app/Http/Requests/
cp -r ../gestion-gastos/backend/app/Http/Resources app/Http/
cp ../gestion-gastos/backend/database/migrations/*.php database/migrations/
cp ../gestion-gastos/backend/routes/api.php routes/api.php
cp ../gestion-gastos/backend/config/cors.php config/cors.php
cp ../gestion-gastos/backend/.env.example .env.example

# Instalar la dependencia de OCR
composer require thiagoalessio/tesseract_ocr

# Configurar entorno
cp .env.example .env
php artisan key:generate
touch database/database.sqlite   # porque .env usa DB_CONNECTION=sqlite

# Migrar y enlazar storage público (para poder ver las imágenes cargadas)
php artisan migrate
php artisan storage:link

# Levantar el servidor
php artisan serve   # http://localhost:8000
```

> Laravel 11 no trae `routes/api.php` habilitado por defecto. Si al llamar a
> `/api/expense-documents` te da 404, agrega esto en `bootstrap/app.php`, dentro de
> `Application::configure(...)->withRouting(...)`:
> ```php
> ->withRouting(
>     web: __DIR__.'/../routes/web.php',
>     api: __DIR__.'/../routes/api.php',
>     commands: __DIR__.'/../routes/console.php',
>     health: '/up',
> )
> ```

### 2. Frontend (React)

```bash
cd gestion-gastos/frontend
cp .env.example .env
npm install
npm run dev   # http://localhost:5173
```

Abre `http://localhost:5173`. Con el backend corriendo en `:8000`, la SPA ya
debería poder listar, cargar, filtrar y editar documentos.

## Cómo funciona cada módulo

1. **Carga (`DocumentUpload.jsx` → `ExpenseDocumentController@store`)**
   El usuario sube un JPG/PNG/PDF. El backend lo valida (tipo y peso máx. 10 MB),
   lo guarda en `storage/app/public/expense-documents` y crea el registro.

2. **OCR (`OcrService`)**
   Si es PDF, cada página se rasteriza a PNG con Imagick/Ghostscript. Cada imagen
   pasa por Tesseract (idiomas español + inglés) y se concatena el texto.

3. **Extracción (`ExpenseExtractionService`)**
   Sobre el texto normalizado se aplican expresiones regulares por campo
   (proveedor, número de factura, fecha, subtotal, impuestos, total, moneda) y
   una clasificación por palabras clave para la categoría. Cada campo recibe un
   score de confianza según qué tan directo fue el match (ver tabla arriba).

4. **Persistencia**
   Todo (campos + `ocr_raw_text` + `field_confidence` + `overall_confidence`)
   se guarda en `expense_documents` con estado `pendiente_revision`.

5. **Revisión humana (`DocumentDetail.jsx` → `@update`)**
   El usuario ve el documento original al lado de los campos extraídos. Los
   campos de baja confianza están resaltados. Puede corregir cualquier campo,
   completar los que quedaron vacíos, cambiar la categoría y guardar; al guardar,
   el registro pasa a `revisado` y queda marcado `was_manually_edited = true`
   (así se distingue lo que dijo el OCR de lo que confirmó una persona).

6. **Listado y gestión (`DocumentsList.jsx` → `@index` / `@destroy`)**
   Tabla con proveedor, fecha, categoría, total, score de confianza y estado.
   Permite ver/editar y eliminar cada documento.

7. **Filtros (`Filters.jsx` → query params `fecha_desde`, `fecha_hasta`, `categoria`)**
   Filtro por rango de fechas y por categoría (obligatorios en el enunciado),
   más un filtro adicional por proveedor.

8. **Git**
   Este entregable está pensado para inicializarse como repo desde el momento en
   que generes el esqueleto de Laravel (`git init` dentro de `backend-app/`) y
   hagas commits a medida que copies/ajustes cada pieza — así queda visible la
   evolución real del trabajo, tal como pide el enunciado.

## Endpoints de la API

| Método | Ruta | Descripción |
|---|---|---|
| GET | `/api/expense-documents` | Lista (filtros: `fecha_desde`, `fecha_hasta`, `categoria`, `proveedor`) |
| POST | `/api/expense-documents` | Sube un archivo (`file`), corre OCR + extracción |
| GET | `/api/expense-documents/{id}` | Detalle (incluye `ocr_raw_text`) |
| PUT/PATCH | `/api/expense-documents/{id}` | Guarda correcciones manuales |
| DELETE | `/api/expense-documents/{id}` | Elimina el documento y su archivo |
| POST | `/api/expense-documents/{id}/reprocess` | Vuelve a correr OCR + extracción |

## Limitaciones conocidas / próximos pasos

- La extracción por regex funciona bien con facturas en español con etiquetas
  razonablemente estándar; documentos muy atípicos pueden requerir más reglas o,
  como evolución natural, delegar la extracción a un LLM (el `ExpenseExtractionService`
  está aislado justamente para poder intercambiarlo sin tocar el resto de la app).
- No hay autenticación (no se pidió para esta prueba).
- No hay tests automatizados por el límite de tiempo de la prueba; los servicios
  (`OcrService`, `ExpenseExtractionService`) están desacoplados del controlador
  precisamente para que sean fáciles de testear con PHPUnit si se quisiera ampliar.
