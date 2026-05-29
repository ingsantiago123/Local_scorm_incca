# local_scorm_incca

**Tipo de plugin:** Local  
**Componente:** `local_scorm_incca`  
**Versión:** 1.0.14  
**Moodle requerido:** 4.5+  
**PHP requerido:** 8.1+  
**Autor:** Kevin Garzon — Universidad INCCA de Colombia  
**Licencia:** GNU GPL v3 or later

---

## Descripción general

`local_scorm_incca` es un plugin de protección de paquetes SCORM para Moodle. Clasifica cada actividad SCORM como **protegida** o **pública**, controlando quiénes pueden descargar el archivo `.zip` del paquete, eliminarlo, descomprimirlo o incluirlo en una copia de seguridad, según capabilities específicas asignadas por el administrador.

La protección se aplica a nivel de petición HTTP, interceptando todos los endpoints de Moodle que permiten acceder al contenido de los paquetes, sin modificar ningún archivo del núcleo.

---

## Requisitos

- Moodle 4.5 o superior
- PHP 8.1 o superior
- MySQL 5.7+ / MariaDB 10.4+ / PostgreSQL 13+
- Módulo `mod_scorm` instalado y activo

---

## Instalación

1. Subir el ZIP desde Administración del sitio > Plugins > Instalar plugins.
2. Confirmar la actualización de base de datos cuando Moodle lo solicite.
3. Purgar todas las caches: Administración del sitio > Desarrollo > Purgar todas las caches.

Al instalar, `db/install.php` escanea todos los SCORMs existentes y los registra en la tabla del plugin. El estado inicial de cada SCORM se determina según si el creador tiene la capability `cargar` en ese momento.

---

## Funcionalidades

### 1. Registro automático de SCORMs

Cuando un usuario crea una actividad SCORM (`course_module_created`), el plugin la registra en `mdl_local_scorm_incca_items`:

- Si el creador tiene `local/scorm_incca:cargar` → registrado como **protegido**
- Si no la tiene → registrado como **público**

Cuando un usuario edita un SCORM existente (`course_module_updated`):

- El estado `isprotected` **no cambia** independientemente de quién edite o qué permisos tenga el editor.
- El formulario de edición de Moodle nunca altera la protección. Solo el panel de administración puede cambiarla.
- Si el SCORM no estaba registrado (creado antes de instalar el plugin), se registra como **público** al editarse por primera vez.

### 2. Registro de SCORMs importados

Cuando se importa contenido entre cursos (`backup/import.php`), Moodle crea los course_modules por inserción directa en BD sin disparar `course_module_created`. El plugin escucha el evento `course_restored` que Moodle dispara al finalizar el restore.

**Lógica de matching:**

1. Al completar el import, el plugin busca todos los SCORMs del curso destino sin registro en la tabla.
2. Para cada SCORM sin registrar, consulta el SCORM con el mismo nombre (`mdl_scorm.name`) en el curso origen.
3. Hereda el `isprotected` del origen via `MAX(isprotected)` — si algún SCORM con ese nombre en el origen está protegido, el importado también lo estará.
4. Si no hay coincidencia por nombre o no hay `originalcourseid` (restore desde `.mbz` externo) → registra como público.

> **Por qué nombre y no sha1hash:** el sha1hash identifica el contenido del paquete ZIP, no la instancia SCORM. Si el mismo ZIP se subió varias veces (instancias con distinto estado de protección), el sha1hash apuntaría a un cmid arbitrario. El nombre identifica la instancia específica y se preserva exactamente durante el restore.

### 3. Protección de descarga

Intercepta las peticiones HTTP antes de que Moodle sirva el archivo. Bloquea si `isprotected = 1` y el usuario no tiene `local/scorm_incca:descargar` en el curso correspondiente.

| Endpoint | Acción interceptada |
|---|---|
| `pluginfile.php` | Descarga directa del `.zip` del paquete |
| `draftfile.php` | Descarga desde el área de borrador (gestor de archivos en edición) |
| `draftfiles_ajax.php?action=downloadselected` | Descarga masiva como ZIP desde el file manager |
| `draftfiles_ajax.php?action=unzip` | Descompresión del paquete en el file manager |
| `draftfiles_ajax.php?action=delete` | Eliminación del `.zip` desde el file manager al editar |

La identificación del SCORM en áreas de borrador usa dos estrategias:

1. **Campo `source` del borrador (preciso):** Moodle almacena en `mdl_files.source` un objeto PHP serializado con `pack_reference` (base64 de un objeto con `contextid`, `component`, `filearea`, etc.). El plugin extrae el `contextid` para obtener el cmid exacto del SCORM original.
2. **Fallback por `contenthash` (postura de seguridad):** si el campo source no permite identificar el SCORM exacto, busca todos los SCORMs registrados con ese contenthash. Si alguno es protegido, bloquea.

### 4. Protección de eliminación

Bloquea la eliminación de módulos SCORM protegidos por usuarios sin `cargar` ni `descargar` en dos puntos:

1. **`lib/ajax/service.php`** — editor de cursos AJAX de Moodle 4.x (acciones `cm_delete` y `action=delete`). Interceptado en `after_config`/`after_require_login` antes de que el servicio procese la petición.
2. **`local_scorm_incca_pre_course_module_delete()`** — callback legacy, red de seguridad para la ruta síncrona `course/mod.php`. Lanza `moodle_exception` para cancelar la eliminación.

Pueden eliminar SCORMs protegidos: site admins, usuarios con `cargar` o `descargar` en el curso.

### 5. Protección de copias de seguridad

Intercepta `backup/backup.php` antes de que Moodle procese el formulario de backup.

**Backup de actividad** (`backup/backup.php?id=X&cm=Y`):
- Si el cmid Y corresponde a un SCORM protegido y el usuario no tiene `backup`, `cargar` ni `descargar` en el curso → 403 bloqueado.

**Backup de curso completo** (`backup/backup.php?id=X`):
- Si el curso contiene al menos un SCORM protegido y el usuario no tiene ninguna de las tres capabilities → 403 bloqueado.
- Si el curso no tiene SCORMs protegidos → backup sin restricciones.

Los site admins siempre pueden crear backups.

### 6. Panel de administración

Accesible desde Administración del sitio > Extensiones > Plugins locales > SCORM INCCA (para admins) o mediante el bloque `block_scorm_incca` (para usuarios con `viewadminpanel`).

**Panel principal** (`index.php`):
- Buscador en tiempo real: filtra por nombre de SCORM, nombre de curso o shortname de curso.
- Filtros de estado: todos / protegidos / públicos.
- Paginación de 10 registros por página con contador "Mostrando X–Y de Z paquetes".
- Toggle de estado (protegido ↔ público) desde el panel — única forma de cambiar `isprotected` después de la creación.
- Limpieza de huérfanos: elimina registros de SCORMs que ya no existen en Moodle.
- Diseño responsive: tabla en pantallas medianas/grandes, cards en móvil.

**Log de auditoría** (`logs.php`):
- Filtros por tipo de evento y por cmid.
- Paginación de 50 registros por página.
- Diseño responsive con cards en móvil.
- Tipos de evento: descarga, bloqueo, importación, estado preservado, error, backup bloqueado.

**Usuarios con permisos** (`users.php`) — exclusivo para site admins:
- Lista usuarios con roles que incluyen las capabilities del plugin.

---

## Capabilities

### `local/scorm_incca:cargar`

| Propiedad | Valor |
|---|---|
| Tipo | `write` |
| Contexto | `CONTEXT_COURSE` |
| Archetype por defecto | manager → `CAP_ALLOW` |

Marca el SCORM como protegido al crearlo. También habilita eliminar SCORMs protegidos y crear backups de cursos con SCORMs protegidos.

**Dónde asignar:** contexto del curso (override de rol) o rol a nivel de curso/categoría.

### `local/scorm_incca:descargar`

| Propiedad | Valor |
|---|---|
| Tipo | `read` |
| Contexto | `CONTEXT_COURSE` |
| Archetype por defecto | manager → `CAP_ALLOW` |

Permite descargar el `.zip` de un SCORM protegido, eliminarlo y crear backups de cursos con SCORMs protegidos.

**Dónde asignar:** contexto del curso o rol a nivel de curso/categoría.

### `local/scorm_incca:backup`

| Propiedad | Valor |
|---|---|
| Tipo | `read` |
| Contexto | `CONTEXT_COURSE` |
| Archetype por defecto | manager → `CAP_ALLOW` |

Permite crear backups de cursos con SCORMs protegidos sin necesidad de `cargar` ni `descargar`. No habilita descarga ni eliminación de paquetes.

**Dónde asignar:** contexto del curso o rol a nivel de curso/categoría.

### `local/scorm_incca:viewadminpanel`

| Propiedad | Valor |
|---|---|
| Tipo | `read` |
| Contexto | `CONTEXT_SYSTEM` |
| Archetype por defecto | manager → `CAP_ALLOW` |

Acceso al panel de administración. La sección `users.php` (usuarios con permisos) solo es visible para site admins.

**Dónde asignar:** obligatoriamente a nivel de sistema (`Administración del sitio > Usuarios > Permisos > Asignar roles del sistema`). Si se asigna en un contexto inferior no funciona.

---

## Arquitectura técnica

### Mecanismo de interceptación

El plugin no modifica ningún archivo del núcleo. Toda la lógica usa callbacks legacy y el sistema de hooks de Moodle.

**`after_config` (lib.php) — interceptación principal**

Dispara en `lib/setup.php` para toda petición HTTP. Filtra por script:

```
/draftfile.php
/pluginfile.php
/repository/draftfiles_ajax.php
/lib/ajax/service.php
/backup/backup.php          ← nuevo en v1.0.14
```

Para las primeras cuatro rutas, carga las clases con `include_once` explícito (evita depender del autoloader, que puede fallar cuando `NO_DEBUG_DISPLAY=true` está activo como en `draftfile.php`) y llama a `hook_callbacks::check_access()`.

Para `backup/backup.php`, llama a `hook_callbacks::handle_backup()` directamente — flujo separado que no pasa por `check_access()`.

**`after_require_login` (lib.php) — respaldo**

Dispara dentro de `require_login()`. Un guard estático basado en `REQUEST_TIME_FLOAT` evita la doble ejecución cuando ambos mecanismos disparan en la misma petición.

**`pre_course_module_delete` (lib.php) — eliminación síncrona**

Dispara desde `course_delete_module()` en `course/mod.php`. Lanza `moodle_exception` para cancelar la eliminación si el usuario no tiene permisos.

### Observadores de eventos

Declarados en `db/events.php`:

| Evento | Handler | Acción |
|---|---|---|
| `\core\event\course_module_created` | `observer::course_module_created` | Registra el SCORM. `isprotected` según capability del creador |
| `\core\event\course_module_updated` | `observer::course_module_updated` | Actualiza el registro. `isprotected` **siempre se preserva** |
| `\core\event\course_module_deleted` | `observer::course_module_deleted` | Elimina el registro del plugin |
| `\core\event\course_restored` | `observer::course_restored` | Registra SCORMs importados heredando `isprotected` del curso origen |

### Lógica de `handle_create_or_update` (observer.php)

```
Si es CREATE (course_module_created):
  isprotected = has_capability('cargar', context_module, $USER)

Si es UPDATE (course_module_updated):
  Si ya existe registro en BD:
    isprotected = valor existente en BD (sin cambios)
  Si no existe registro (SCORM pre-instalación del plugin):
    isprotected = false (público, valor más permisivo)
```

Este comportamiento garantiza que ningún usuario puede degradar un SCORM de protegido a público simplemente editando la actividad.

### Lógica de `handle_backup` (hook_callbacks.php)

```
Si siteadmin → ALLOW
Si backup de actividad (cm en GET):
  Si cm no es SCORM protegido → ALLOW
  Si cm es SCORM protegido:
    Si usuario tiene backup|cargar|descargar en CONTEXT_COURSE → ALLOW
    Si no → BLOCK (die 403)
Si backup de curso (id en GET, sin cm):
  get_protected_cmids_in_course(courseid)
  Si lista vacía → ALLOW
  Si no vacía:
    Si usuario tiene backup|cargar|descargar en CONTEXT_COURSE → ALLOW
    Si no → BLOCK (die 403)
```

### Tablas de base de datos

#### `mdl_local_scorm_incca_items`

| Campo | Tipo | Descripción |
|---|---|---|
| `id` | INT(10) PK | Clave primaria autoincremental |
| `cmid` | INT(10) UNIQUE | ID del `course_module`. Clave de búsqueda principal |
| `scormid` | INT(10) | FK a `mdl_scorm.id` |
| `courseid` | INT(10) | FK a `mdl_course.id` |
| `creatorid` | INT(10) | Usuario que creó la actividad. FK a `mdl_user.id` |
| `isprotected` | INT(1) | `1` = protegido, `0` = público |
| `timecreated` | INT(10) | Timestamp Unix de creación del registro |
| `timemodified` | INT(10) | Timestamp Unix de última modificación |

Índices: `isprotected` (no único), `creatorid` (no único).

#### `mdl_local_scorm_incca_logs`

| Campo | Tipo | Descripción |
|---|---|---|
| `id` | INT(10) PK | Clave primaria autoincremental |
| `eventtype` | CHAR(64) | Tipo de evento (ver tabla abajo) |
| `userid` | INT(10) | Usuario que generó el evento |
| `cmid` | INT(10) NULL | SCORM afectado |
| `message` | TEXT | Descripción detallada del evento |
| `ipaddress` | CHAR(45) | Dirección IP del cliente |
| `timecreated` | INT(10) | Timestamp Unix del evento |

Índices: `eventtype`, `timecreated`, `cmid` (no únicos).

**Tipos de evento (`eventtype`):**

| Valor | Cuándo se registra |
|---|---|
| `upload_protected` | SCORM creado/editado y registrado como protegido, o estado cambiado a protegido desde el panel |
| `upload_public` | SCORM creado/editado y registrado como público |
| `download_allowed` | Descarga del paquete ZIP permitida |
| `download_blocked` | Descarga, backup o backup de actividad bloqueados por falta de permiso |
| `deleted` | Registro eliminado al borrar el SCORM de Moodle |
| `error` | Error en la ejecución de la lógica del plugin |
| `delete_blocked` | Intento de eliminación del módulo SCORM bloqueado |
| `unzip_blocked` | Intento de descompresión del paquete bloqueado |
| `import_registered` | SCORM registrado automáticamente tras ser importado desde otro curso |

---

## Estructura de archivos

```
local/scorm_incca/
├── classes/
│   ├── debugger.php          Logger de diagnóstico a archivo en moodledata.
│   │                         logDiag() escribe siempre (ignora ENABLED) para diagnóstico.
│   ├── helper.php            Lógica de negocio central:
│   │                         register_scorm(), unregister_scorm(), is_protected(),
│   │                         get_protected_cmids_in_course(), register_imported_scorms(),
│   │                         find_protected_scorm_by_draft(), parse_pluginfile_path(),
│   │                         is_pluginfile_request(), is_draftfile_request(), log()
│   ├── hook_callbacks.php    Punto de entrada para todos los hooks y callbacks:
│   │                         check_access() — protección de descargas y eliminaciones
│   │                         handle_backup() — protección de backups
│   │                         handle_ajax_service_delete() — eliminación vía AJAX
│   │                         handle_draftfiles_ajax_*() — acciones del file manager
│   ├── observer.php          Handlers de eventos Moodle:
│   │                         course_module_created/updated/deleted, course_restored
│   └── privacy/
│       └── provider.php      Declaración GDPR
├── db/
│   ├── access.php            Capabilities: cargar, descargar, backup, viewadminpanel
│   ├── events.php            Observadores: course_module_*, course_restored
│   ├── hooks.php             $callbacks = [] (vacío, para no interferir con lib.php legacy)
│   ├── install.php           Registro inicial de SCORMs existentes al instalar
│   ├── install.xml           Schema XMLDB de las dos tablas del plugin
│   └── uninstall.php         Limpieza explícita antes de que Moodle elimine tablas
├── lang/
│   ├── en/local_scorm_incca.php
│   └── es/local_scorm_incca.php
├── index.php                 Panel: lista de SCORMs, búsqueda, paginación (10/pág), responsive
├── lib.php                   Callbacks legacy: after_config, after_require_login,
│                             pre_course_module_delete
├── logs.php                  Panel: log de auditoría con filtros y paginación, responsive
├── settings.php              Registro de páginas en el árbol de administración de Moodle
├── users.php                 Panel (solo admin): usuarios con capabilities del plugin
└── version.php               v1.0.14 — 2026052911
```

---

## Notas de diseño

### Por qué `die()` y no `throw`

Los callbacks `after_config` y `after_require_login` son capturados por `process_legacy_callbacks()` en un `try/catch`. Una excepción sería silenciada y la petición continuaría normalmente. `die()` es irrecuperable: no puede ser capturado, la respuesta HTTP termina inmediatamente con el HTML de error 403.

### Por qué `$callbacks = []` en `db/hooks.php`

Si `db/hooks.php` registrara el hook `\core\hook\after_config`, Moodle consideraría que el plugin "migró" al nuevo sistema y omitiría silenciosamente la función legacy `local_scorm_incca_after_config()` de `lib.php`. Se mantiene vacío para que el callback legacy siga funcionando, especialmente en contextos como `draftfile.php` donde `NO_DEBUG_DISPLAY=true` silencia errores del autoloader.

### Por qué el observer no cambia `isprotected` en UPDATE

El estado de protección es una decisión administrativa, no una consecuencia automática de quién edita el formulario. Cualquier usuario con acceso de edición al curso puede modificar la actividad SCORM (título, intentos, configuración de tracking, etc.) sin que eso deba alterar la protección del paquete. La única fuente de verdad para `isprotected` es la acción explícita en el panel de administración.

### Matching por nombre en imports

El restore de Moodle preserva exactamente el campo `mdl_scorm.name` de la actividad original. A diferencia del `sha1hash` (que identifica el contenido del ZIP y es el mismo para múltiples instancias del mismo paquete), el nombre identifica la instancia específica. Esto evita el falso positivo de marcar como protegida una copia de un SCORM público que usa el mismo archivo ZIP que un SCORM protegido.

---

## Archivos de diagnóstico

Durante el desarrollo y resolución de problemas, el plugin puede escribir logs de diagnóstico en:

- `{moodledata}/local_scorm_incca_diag.log` — flujo de edición (SCORM_SAVE) y backup (BACKUP_*)
- `{moodledata}/local_scorm_incca_import.log` — flujo de importación (obsoleto, disponible si se reactivan las llamadas)
- `{moodledata}/local_scorm_incca_debug.log` — log de descarga (requiere `debugger::ENABLED = true`)

Estos archivos no son accesibles vía web (están en `moodledata`, fuera del docroot). Para desactivar el diagnóstico en producción, eliminar las llamadas a `debugger::logDiag()` en `hook_callbacks.php` y `observer.php`.

---

## Desinstalación

El archivo `db/uninstall.php` elimina todos los registros de las dos tablas antes de que Moodle las elimine. Moodle se encarga de revocar capabilities, eliminar configuraciones y desregistrar observers.

El proceso no modifica tablas del núcleo de Moodle. Los SCORMs, cursos, usuarios y archivos permanecen intactos.

---

## Compatibilidad

| Componente | Versión mínima |
|---|---|
| Moodle | 4.5 |
| PHP | 8.1 |
| MySQL / MariaDB | 5.7 / 10.4 |
| PostgreSQL | 13 |
| mod_scorm | incluido en Moodle 4.5 |

Funciona con Apache y Nginx sin modificaciones en la configuración del servidor.
