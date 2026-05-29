# SCORM INCCA — Sistema de protección de paquetes SCORM para Moodle

**Autor:** Kevin Garzon — Universidad INCCA de Colombia  
**Versión del sistema:** 1.0.14  
**Moodle requerido:** 4.5 o superior  
**Licencia:** GNU GPL v3 or later

---

## Descripción general

Este repositorio contiene dos plugins de Moodle que trabajan en conjunto para proteger los paquetes SCORM de un sitio educativo. La solución permite que los administradores controlen quiénes pueden descargar, descomprimir, eliminar o hacer copias de seguridad de los paquetes SCORM, **sin modificar ningún archivo del núcleo de Moodle**.

Ambos plugins funcionan completamente fuera del core de Moodle. Se instalan como plugins normales, se pueden desinstalar sin dejar rastros, y no alteran el comportamiento de ninguna otra funcionalidad del sistema.

---

## Componentes

### Plugin principal: `local_scorm_incca`

Motor del sistema. Gestiona registro, protección y auditoría de paquetes SCORM.

**Funcionalidades:**

- **Registro automático:** cada SCORM creado queda registrado con estado protegido o público según la capability del creador.
- **Protección de descarga:** bloquea el acceso al `.zip` del paquete por todos los vectores disponibles en Moodle (descarga directa, gestor de archivos, descarga masiva, descompresión).
- **Protección de eliminación:** impide borrar actividades SCORM protegidas a usuarios sin permisos, tanto por la interfaz web como por el editor AJAX de Moodle 4.x.
- **Protección de copias de seguridad:** bloquea la creación de backups (`.mbz`) en cursos que contienen SCORMs protegidos para usuarios sin el permiso correspondiente.
- **Registro de SCORMs importados:** cuando se importa contenido entre cursos, los SCORMs importados quedan registrados automáticamente heredando el estado de protección del curso origen.
- **Preservación del estado en edición:** editar una actividad SCORM desde el formulario de Moodle nunca cambia su estado de protección. Solo el panel de administración puede modificarlo.
- **Panel de administración** con búsqueda, paginación y diseño responsive:
  - Lista de SCORMs registrados con filtros, buscador y toggle de estado.
  - Log de auditoría con filtros por tipo de evento y por actividad.
  - (Solo admins) Lista de usuarios con permisos personalizados.
- **Log de auditoría:** registra descargas, bloqueos, importaciones, cambios de estado y errores.

### Bloque: `block_scorm_incca`

Proporciona acceso rápido al panel de administración para usuarios con permiso `viewadminpanel` que no son administradores del sitio.

El bloque se oculta automáticamente para usuarios sin permisos, por lo que puede colocarse en el Dashboard general sin afectar a los demás usuarios.

---

## Orden de instalación

**Paso 1 — Instalar `local_scorm_incca`:**

1. Acceder como administrador del sitio.
2. Ir a Administración del sitio > Plugins > Instalar plugins.
3. Subir el archivo `local_scorm_incca.zip`.
4. Seguir el proceso de instalación y confirmar la actualización de base de datos.
5. Purgar todas las caches: Administración del sitio > Desarrollo > Purgar todas las caches.

**Paso 2 — Instalar `block_scorm_incca`:**

1. Ir a Administración del sitio > Plugins > Instalar plugins.
2. Subir el archivo `block_scorm_incca.zip`.
3. Confirmar la actualización de base de datos.
4. Purgar caches nuevamente.

**Para actualizar** una versión existente: subir el nuevo zip desde Instalar plugins. Moodle detecta la versión nueva y ejecuta el upgrade automáticamente. Purgar caches después.

**Para reinstalar desde cero:** desinstalar primero (Admin > Plugins > Información del plugin > Desinstalar), purgar caches, instalar el zip nuevo, purgar caches nuevamente.

---

## Permisos del sistema

El sistema funciona con cuatro capabilities propias que el administrador asigna según las necesidades de cada usuario o rol.

### `local/scorm_incca:cargar`
**Nivel de asignación:** curso  
Cuando un usuario con este permiso crea un SCORM en un curso, el paquete queda automáticamente marcado como **protegido**. También le permite eliminar SCORMs protegidos y crear copias de seguridad de cursos con SCORMs protegidos.

### `local/scorm_incca:descargar`
**Nivel de asignación:** curso  
Permite al usuario descargar el archivo `.zip` de un SCORM protegido, y también crear copias de seguridad de cursos con SCORMs protegidos. Sin este permiso, cualquier intento de descarga, descompresión, eliminación del archivo o backup es bloqueado.

### `local/scorm_incca:backup`
**Nivel de asignación:** curso  
Permite crear copias de seguridad (`.mbz`) de cursos que contienen SCORMs protegidos, sin necesidad de tener `cargar` o `descargar`. Útil para usuarios que solo necesitan acceso de backup sin otras capacidades de gestión.

### `local/scorm_incca:viewadminpanel`
**Nivel de asignación:** sistema (obligatorio)  
Permite acceder al panel de administración del plugin. **Debe asignarse a nivel del sistema**, no de un curso específico. La sección de usuarios con permisos solo es visible para administradores del sitio.

---

## Cómo asignar permisos

### Permisos de curso (`cargar`, `descargar`, `backup`)

Se asignan en el contexto del curso específico:

1. Ir al curso → Administración del curso > Usuarios > Permisos.
2. Seleccionar el rol a modificar → Anular permisos.
3. Buscar la capability y establecer Permitir.

O crear un rol personalizado con las capabilities necesarias y asignarlo al usuario a nivel de curso o categoría.

### Permiso de panel (`viewadminpanel`)

1. Ir a Administración del sitio > Usuarios > Permisos > Asignar roles del sistema.
2. Seleccionar el rol que incluye `viewadminpanel` (o crear uno personalizado).
3. Buscar al usuario y asignarle ese rol en el contexto del sistema.

Una vez asignado, el usuario puede agregar el bloque Panel SCORM INCCA a su Dashboard para acceder directamente al panel.

---

## Comportamiento por perfil de usuario

| Perfil | Panel | Descargar SCORM protegido | Eliminar SCORM protegido | Backup curso con SCORMs protegidos |
|--------|-------|--------------------------|--------------------------|-------------------------------------|
| Sin permisos | No | No | No | No |
| Con `descargar` (en el curso) | No | Sí | No | Sí |
| Con `cargar` (en el curso) | No | Sí | Sí | Sí |
| Con `backup` (en el curso) | No | No | No | Sí |
| Con `viewadminpanel` (sistema) | Sí | Según `descargar` | Según `cargar`/`descargar` | Según `backup`/`cargar`/`descargar` |
| Administrador del sitio | Sí (completo) | Siempre | Siempre | Siempre |

---

## Vectores de protección cubiertos

| Endpoint | Qué bloquea |
|---|---|
| `pluginfile.php` | Descarga directa del paquete ZIP desde el reproductor SCORM o enlace |
| `draftfile.php` | Descarga del ZIP desde el gestor de archivos al editar la actividad |
| `draftfiles_ajax.php?action=downloadselected` | Descarga masiva como ZIP desde el gestor de archivos |
| `draftfiles_ajax.php?action=unzip` | Descompresión del paquete en el gestor de archivos |
| `draftfiles_ajax.php?action=delete` | Eliminación del archivo .zip desde el gestor al editar |
| `lib/ajax/service.php` | Eliminación del módulo SCORM via el editor de cursos AJAX (Moodle 4.x) |
| `backup/backup.php` | Creación de copia de seguridad de actividad o curso con SCORMs protegidos |

---

## Comportamiento del sistema de importación

Cuando se importa contenido entre cursos (`backup/import.php`), Moodle crea los course_modules mediante inserción directa en la base de datos, sin disparar el evento `course_module_created`. El plugin detecta esta situación escuchando el evento `course_restored` que Moodle sí dispara al finalizar el proceso.

Al completarse el import:
1. El plugin busca todos los SCORMs del curso destino que no tienen registro en la tabla del plugin.
2. Para cada SCORM sin registrar, busca el SCORM equivalente en el curso origen (por nombre de actividad).
3. Si el SCORM origen estaba protegido, el SCORM importado queda protegido.
4. Si el SCORM origen era público o no tenía registro, el importado queda público.

---

## Limitaciones conocidas

- El archivo `.mbz` ya descargado por un usuario con permisos no puede controlarse después de generado.
- El backup automático/cron de Moodle usa una ruta interna (`backup_cron_helper`) que no pasa por `backup/backup.php` — no queda cubierto por esta protección.
- Si un SCORM se restaura en una instancia de Moodle sin el plugin instalado, los paquetes quedan sin restricción de acceso.

---

## Desinstalación

Ambos plugins pueden desinstalarse desde Administración del sitio > Plugins > Información del plugin sin dejar datos remanentes en la base de datos de Moodle.

Se recomienda desinstalar primero el bloque y luego el plugin principal. Purgar caches después de cada desinstalación.

Los SCORMs, cursos, usuarios y archivos no se ven afectados por la desinstalación.

---

## Documentación técnica

Cada plugin incluye su propio archivo README con la documentación técnica detallada:

- local_scorm_incca/README.md — arquitectura de interceptación, hooks, observadores de eventos, esquema de base de datos, tipos de log, estructura de archivos.
- block_scorm_incca/README.md — capabilities del bloque y comportamiento según permisos.
