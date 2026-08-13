# Gestión de documentos de gastos (OCR + extracción de información)

Prueba técnica: una app para cargar facturas y recibos (JPG, PNG, PDF), sacarles el
texto con OCR, convertir ese texto en campos estructurados (proveedor, fecha, montos,
etc.), marcar qué tan confiable es cada campo, y dejar que un usuario revise y
corrija todo antes de guardarlo.

## Stack y por qué se eligió así

| Capa | Elección | Por qué |
|---|---|---|
| Backend | Laravel 11 | |
| Frontend | React + Vite | SPA simple, no hace falta SSR para esto. |
| Base de datos | SQLite | Para que cualquiera pueda levantar el proyecto sin instalar un motor aparte. Las migraciones son Eloquent estándar, así que pasar a MySQL/PostgreSQL es solo cambiar el `.env`. |
| OCR | Tesseract (vía `thiagoalessio/tesseract_ocr`) | Corre local, sin API keys ni costos de por medio. Los PDF se convierten a imagen antes de pasarlos por Tesseract (ver más abajo por qué se usa `pdftoppm` y no Imagick). |
| Extracción de campos | Reglas + regex sobre el texto del OCR (`ExpenseExtractionService`) | No depende de un LLM externo, es determinística y se puede depurar leyendo el código. El servicio queda aislado del resto, así que si más adelante se quiere cambiar por un LLM, es solo reemplazar esa clase. |
| Confiabilidad | Score 0.0–1.0 por campo (`field_confidence`) + score global | Ver la sección de abajo. |

### Cómo funciona lo de la confiabilidad

Cada campo que se extrae recibe un puntaje:

- **1.0** — se encontró pegado a una etiqueta clara del documento (ej. "Total: $45.000").
- **0.7–0.9** — se encontró cerca de la etiqueta pero no exactamente pegado (pasa seguido cuando el OCR desordena un poco el texto de una tabla).
- **0.5–0.6** — se sacó con una heurística de respaldo (ej. proveedor = primera línea del documento que no sea un encabezado tipo NIT/régimen; total = el monto con `$` más grande cuando no hay etiqueta "Total").
- **0.0** — no se encontró nada, el campo queda vacío para que el usuario lo llene.

Los campos con score menor a 0.6 se marcan como dudosos en la interfaz (con un
puntito ámbar al lado del label y el input resaltado), para que el usuario los mire
con más cuidado antes de guardar. La idea es que un dato mal leído por el OCR nunca
se guarde en automático como si fuera confiable.

### Trazabilidad

El texto crudo que devuelve el OCR se guarda completo (`ocr_raw_text`), y el
archivo original queda almacenado y enlazado al registro. Desde el detalle de cada
documento se puede ver ese texto y también "reprocesar" (correr OCR + extracción de
nuevo), útil si se ajusta la lógica de extracción después de haber cargado algo.

## Estructura del repositorio

```
gestion-gastos/
├── backend/     Laravel — API REST (no incluye el esqueleto completo de Laravel,
│                eso se genera en el paso 1 de abajo)
│   ├── app/Models/ExpenseDocument.php
│   ├── app/Services/OcrService.php                 <- OCR
│   ├── app/Services/ExpenseExtractionService.php    <- extracción + confiabilidad
│   ├── app/Http/Controllers/Api/ExpenseDocumentController.php
│   ├── app/Http/Requests/UpdateExpenseDocumentRequest.php
│   ├── app/Http/Resources/ExpenseDocumentResource.php
│   ├── database/migrations/..._create_expense_documents_table.php
│   ├── routes/api.php
│   ├── config/cors.php
│   └── .env.example
└── frontend/    React + Vite — SPA completa
    ├── src/pages/DocumentsList.jsx      <- listado + filtros
    ├── src/pages/DocumentUpload.jsx     <- carga
    ├── src/pages/DocumentDetail.jsx     <- revisión humana
    ├── src/components/
    ├── src/api/client.js
    └── src/index.css
```

## Lo que hay que tener instalado

Esto es lo que realmente se necesitó instalar para que todo funcionara de punta a
punta (probado en Windows 10/11, que es donde más fricción da; en Linux/Mac los
pasos son más directos):

| Herramienta | Versión usada en las pruebas | Para qué |
|---|---|---|
| PHP | 8.3.33 | Correr Laravel |
| Composer | cualquier versión reciente (2.x) | Instalar dependencias de PHP |
| Node.js | 18+ | Correr el frontend |
| npm | el que trae Node | Instalar dependencias del frontend |
| Tesseract OCR | 5.5.3 | Leer el texto de las imágenes |
| Paquete de idioma español de Tesseract (`spa.traineddata`) | — | Sin esto, Tesseract solo lee inglés y el OCR de facturas en español sale vacío |
| Poppler (`pdftoppm`) | cualquier build reciente para Windows | Convertir cada página de un PDF a imagen antes de pasarla por Tesseract |

**Nota sobre PDF vs. imágenes:** el plan original era usar la extensión Imagick de
PHP para convertir PDF a imagen, pero instalar Imagick en Windows es bastante
complicado (hay que hacer coincidir la versión exacta del `.dll` con la versión y
arquitectura de PHP). Se terminó reemplazando por `pdftoppm` (parte de Poppler),
que es un ejecutable independiente — se descarga, se agrega al PATH y ya. El
`OcrService` intenta usar Imagick primero si está disponible, y si no, cae
automáticamente a `pdftoppm`.

### Instalar Tesseract (Windows)

1. Descargar el instalador desde [github.com/UB-Mannheim/tesseract/wiki](https://github.com/UB-Mannheim/tesseract/wiki).
2. Durante la instalación, en "Additional language data" marcar **Spanish**. Si no
   se marca ahí, hay que bajar `spa.traineddata` aparte desde
   [tessdata_fast](https://github.com/tesseract-ocr/tessdata_fast) y copiarlo a
   `C:\Program Files\Tesseract-OCR\tessdata\`.
3. Agregar `C:\Program Files\Tesseract-OCR` al PATH del sistema.
4. Verificar en una consola nueva:
   ```
   tesseract --version
   tesseract --list-langs
   ```
   `spa` debe aparecer en la lista de idiomas.

### Instalar Poppler / pdftoppm (Windows)

1. Descargar el zip más reciente desde
   [github.com/oschwartz10612/poppler-windows/releases](https://github.com/oschwartz10612/poppler-windows/releases).
2. Descomprimir en una carpeta fija, por ejemplo `C:\poppler\` (queda
   `C:\poppler\Library\bin\pdftoppm.exe`).
3. Agregar `C:\poppler\Library\bin` al PATH del sistema.
4. Verificar en una consola nueva: `pdftoppm -v`.

En Linux es un solo comando: `sudo apt-get install -y tesseract-ocr tesseract-ocr-spa poppler-utils`.
En Mac: `brew install tesseract tesseract-lang poppler`.

## Cómo levantar el proyecto

### Backend

```bash
# 1. Generar el esqueleto de Laravel
composer create-project laravel/laravel backend-app "^11.0"
cd backend-app

# 2. Copiar los archivos de esta entrega encima del esqueleto
cp -r ../gestion-gastos/backend/app/Models/ExpenseDocument.php app/Models/
cp -r ../gestion-gastos/backend/app/Services app/
cp -r ../gestion-gastos/backend/app/Http/Controllers/Api app/Http/Controllers/
cp ../gestion-gastos/backend/app/Http/Requests/UpdateExpenseDocumentRequest.php app/Http/Requests/
cp -r ../gestion-gastos/backend/app/Http/Resources app/Http/
cp ../gestion-gastos/backend/database/migrations/*.php database/migrations/
cp ../gestion-gastos/backend/routes/api.php routes/api.php
cp ../gestion-gastos/backend/config/cors.php config/cors.php
cp ../gestion-gastos/backend/.env.example .env.example

# 3. Instalar la dependencia de OCR
composer require thiagoalessio/tesseract_ocr

# 4. Configurar el entorno
cp .env.example .env
php artisan key:generate
touch database/database.sqlite

# 5. Habilitar routes/api.php (ver nota abajo, Laravel 11 no lo carga solo)

# 6. Migrar
php artisan migrate

# 7. Levantar el servidor
php artisan serve
```

Backend corriendo en `http://localhost:8000`.

**Nota — `routes/api.php` no se carga solo en Laravel 11.** Hay que abrir
`bootstrap/app.php` y agregar la línea `api:` dentro de `withRouting(...)`:

```php
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',   // <- esta línea hay que agregarla
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // ...
```

Sin esto, cualquier llamada a `/api/...` responde 404 aunque el archivo de rutas
exista y tenga las rutas bien definidas.

**Nota — por qué las imágenes/PDF no se sirven con `storage:link`.** El plan
original era el típico de Laravel: `php artisan storage:link` crea un enlace
simbólico de `public/storage` hacia `storage/app/public`, y desde ahí se sirven los
archivos. El problema es que el servidor de desarrollo de PHP (`php artisan serve`)
no sigue bien los enlaces simbólicos en Windows, así que cualquier archivo servido
por esa ruta devuelve **403 Forbidden**. La solución fue agregar una ruta propia en
`routes/api.php` que sirve el archivo directamente desde el disco sin depender del
symlink:

```php
Route::get('/files/{path}', function (string $path) {
    if (! Storage::disk('public')->exists($path)) {
        abort(404);
    }
    return response()->file(Storage::disk('public')->path($path));
})->where('path', '.*')->name('files.show');
```

Y el `ExpenseDocumentResource` arma la URL de cada documento con
`route('files.show', ...)` en vez de `asset('storage/...')`. Si el proyecto se
despliega más adelante detrás de un servidor real (Apache/Nginx), esto se puede
volver a simplificar y usar el symlink normal — el workaround es específico para
correr con `php artisan serve` en Windows.

### Frontend

```bash
cd gestion-gastos/frontend
cp .env.example .env
npm install
npm run dev
```

Frontend corriendo en `http://localhost:5173`. Con el backend arriba en `:8000`, ya
se puede cargar, listar, filtrar y editar documentos desde ahí.

### Después de cualquier cambio en el backend

Estos dos comandos resuelven la mayoría de los dolores de cabeza después de tocar
código PHP (clases nuevas que "no existen", rutas que no aparecen, cachés viejas):

```bash
composer dump-autoload -o
php artisan optimize:clear
```

Y siempre reiniciar `php artisan serve` después de tocar `bootstrap/app.php` o
`routes/api.php` — esos archivos no se recargan solos con el servidor corriendo.

## Cómo funciona cada parte

1. **Carga** (`DocumentUpload.jsx` → `ExpenseDocumentController@store`): se sube un
   JPG/PNG/PDF, se valida tipo y peso (máx. 10 MB), se guarda en
   `storage/app/public/expense-documents` y se crea el registro en la base de datos.

2. **OCR** (`OcrService`): si es PDF, cada página se convierte a PNG con `pdftoppm`
   (o Imagick si está disponible). Cada imagen resultante pasa por Tesseract en
   español + inglés y el texto se concatena.

3. **Extracción** (`ExpenseExtractionService`): sobre el texto del OCR se aplican
   expresiones regulares por campo (proveedor, número de factura, fecha, subtotal,
   impuestos, total, moneda), con varios niveles de prioridad — por ejemplo, para la
   fecha primero busca explícitamente "fecha de facturación/emisión", y solo si no
   la encuentra cae a una búsqueda más genérica de la palabra "fecha" (evitando
   agarrar por error la fecha del Formulario DIAN, que es un dato administrativo
   aparte). La categoría sale de una clasificación por palabras clave. Cada campo
   queda con su score de confianza.

4. **Persistencia**: todo (campos + texto de OCR + scores) se guarda con estado
   `pendiente_revision`.

5. **Revisión humana** (`DocumentDetail.jsx` → `@update`): se ve el documento
   original al lado de los campos extraídos, con los de baja confianza resaltados.
   Se puede corregir cualquier campo, completar los vacíos, cambiar la categoría y
   guardar; al guardar, el estado pasa a `revisado` y queda marcado
   `was_manually_edited = true` (para distinguir lo que dijo el OCR de lo que
   confirmó una persona).

6. **Listado y gestión** (`DocumentsList.jsx` → `@index` / `@destroy`): tabla con
   proveedor, fecha, categoría, total, score de confianza y estado, con acciones de
   ver/editar y eliminar.

7. **Filtros** (`Filters.jsx`): por rango de fechas y por categoría (los dos que
   pide el enunciado), más uno adicional por proveedor.

8. **Git**: pensado para inicializar el repo desde el momento en que se genera el
   esqueleto de Laravel (`git init` dentro de `backend-app/`) y hacer commits a
   medida que se copian y ajustan las piezas, para que quede visible cómo fue
   avanzando el trabajo.

## Endpoints de la API

| Método | Ruta | Qué hace |
|---|---|---|
| GET | `/api/expense-documents` | Lista (filtros: `fecha_desde`, `fecha_hasta`, `categoria`, `proveedor`) |
| POST | `/api/expense-documents` | Sube un archivo (`file`), corre OCR + extracción |
| GET | `/api/expense-documents/{id}` | Detalle (incluye `ocr_raw_text`) |
| PUT/PATCH | `/api/expense-documents/{id}` | Guarda correcciones manuales |
| DELETE | `/api/expense-documents/{id}` | Elimina el documento y su archivo |
| POST | `/api/expense-documents/{id}/reprocess` | Vuelve a correr OCR + extracción |
| GET | `/api/files/{path}` | Sirve el archivo original (imagen/PDF) |

## Cosas que se quedaron pendientes / se podrían mejorar

- La extracción por regex funciona bien con facturas en español que tienen
  etiquetas más o menos estándar. Documentos muy raros van a necesitar más reglas,
  o eventualmente pasar la extracción a un LLM — el `ExpenseExtractionService` está
  aislado justamente para que ese cambio no toque el resto de la app.
- No hay autenticación (no se pidió para esta prueba).
- No hay tests automatizados por tiempo. `OcrService` y `ExpenseExtractionService`
  están desacoplados del controlador a propósito, para que sea fácil escribirles
  tests con PHPUnit si se quiere ampliar esto después.
