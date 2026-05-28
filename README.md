# SCORM INCCA — Sistema de proteccion de paquetes SCORM para Moodle

**Autor:** Kevin Garzon — Universidad INCCA de Colombia  
**Version del sistema:** 1.0.10  
**Moodle requerido:** 4.5 o superior  
**Licencia:** GNU GPL v3 or later

---

## Descripcion general

Este repositorio contiene dos plugins de Moodle que trabajan en conjunto para proteger
los paquetes SCORM de un sitio educativo. La solucion permite que los administradores
controlen quienes pueden descargar, descomprimir o eliminar los paquetes SCORM, sin
modificar ningun archivo del nucleo de Moodle.

Ambos plugins funcionan completamente fuera del core de Moodle. Se instalan como
plugins normales, se pueden desinstalar sin dejar rastros, y no alteran el comportamiento
de ninguna otra funcionalidad del sistema.

---

## Componentes

### Plugin principal: local_scorm_incca

Es el motor del sistema. Se encarga de:

- Registrar cada actividad SCORM que se crea en el sitio y clasificarla como
  **protegida** o **publica**.
- Bloquear la descarga del archivo `.zip` del paquete a usuarios que no tengan
  el permiso correspondiente, independientemente de la via que usen para intentarlo.
- Bloquear la eliminacion de una actividad SCORM protegida por usuarios sin permisos.
- Bloquear la descompresion del paquete dentro del gestor de archivos.
- Registrar en un log de auditoria todos los eventos relevantes: descargas permitidas,
  descargas bloqueadas, eliminaciones bloqueadas y cambios de estado.
- Proveer un panel de administracion para visualizar todos los SCORMs registrados,
  cambiar su estado de proteccion y revisar el historial de eventos.

Un SCORM queda marcado como protegido automaticamente cuando quien lo sube tiene el
permiso `cargar` del plugin. Si lo sube alguien sin ese permiso, queda como publico y
no tiene restricciones adicionales. El estado de proteccion puede modificarse manualmente
desde el panel en cualquier momento.

### Bloque: block_scorm_incca

Complementa al plugin principal proporcionando acceso rapido al panel de administracion
para usuarios que no son administradores del sitio pero tienen permiso para ver el panel.

Sin el bloque, un usuario con ese permiso no tiene ningun punto de entrada en la
interfaz de Moodle que lo lleve al panel. El bloque aparece en la pagina donde se
coloque y muestra los enlaces directamente. Se oculta de forma automatica para los
usuarios que no tienen el permiso, por lo que colocarlo en el Dashboard general del
sitio no afecta a los demas usuarios.

---

## Orden de instalacion

Es obligatorio instalar primero el plugin principal y luego el bloque.

**Paso 1 — Instalar local_scorm_incca:**

1. Acceder como administrador del sitio.
2. Ir a Administracion del sitio > Plugins > Instalar plugins.
3. Subir el archivo `local_scorm_incca_1.0.10.zip`.
4. Seguir el proceso de instalacion y confirmar la actualizacion de base de datos.
5. Purgar todas las caches: Administracion del sitio > Desarrollo > Purgar todas las caches.

**Paso 2 — Instalar block_scorm_incca:**

1. Ir a Administracion del sitio > Plugins > Instalar plugins.
2. Subir el archivo `block_scorm_incca_1.0.1.zip`.
3. Confirmar la actualizacion de base de datos.
4. Purgar caches nuevamente.

Si en el futuro se actualiza alguno de los plugins, el procedimiento es el mismo:
desinstalar la version anterior desde Administracion del sitio > Plugins > Informacion
del plugin, purgar caches, instalar la nueva version y volver a purgar caches.

---

## Permisos del sistema

El sistema funciona con tres permisos propios que el administrador asigna segun las
necesidades de cada usuario o rol.

### Permiso para subir paquetes protegidos

**Nombre interno:** `local/scorm_incca:cargar`  
**Nivel de asignacion:** curso

Cuando un usuario con este permiso crea o actualiza una actividad SCORM en un curso,
el paquete queda automaticamente marcado como protegido. Solo los usuarios que tengan
el permiso de descarga podran acceder al archivo.

Este permiso se asigna sobreescribiendo los permisos del rol en el curso especifico,
o incluyendolo en un rol a nivel de curso o categoria.

### Permiso para descargar paquetes protegidos

**Nombre interno:** `local/scorm_incca:descargar`  
**Nivel de asignacion:** curso

Permite al usuario descargar el archivo `.zip` de un SCORM protegido. Sin este permiso,
cualquier intento de descarga, descompresion o eliminacion del archivo sera bloqueado
por el sistema.

Este permiso se asigna de la misma forma que el anterior: en el contexto del curso,
sobreescribiendo los permisos del rol o mediante un rol propio a nivel de curso.

### Permiso para ver el panel de administracion

**Nombre interno:** `local/scorm_incca:viewadminpanel`  
**Nivel de asignacion:** sistema (obligatorio)

Permite al usuario acceder al panel de administracion del plugin, donde puede ver
la lista de SCORMs registrados y el log de auditoria. La seccion de usuarios con
permisos personalizados dentro del panel solo es visible para administradores del sitio.

A diferencia de los dos permisos anteriores, este debe asignarse a nivel del sistema,
no de un curso especifico. Si se asigna en un nivel inferior, el sistema no lo
detectara y el usuario no podra acceder al panel.

---

## Como asignar el permiso de panel a un usuario

Para que un usuario que no es administrador del sitio pueda acceder al panel de
administracion y usar el bloque:

1. Ir a Administracion del sitio > Usuarios > Permisos > Asignar roles del sistema.
2. Seleccionar el rol que incluye el permiso `viewadminpanel` (puede ser el rol
   Manager o un rol personalizado creado para este fin).
3. Buscar al usuario y asignarle ese rol en el contexto del sistema.

Si se prefiere no usar el rol Manager completo, se puede crear un rol personalizado
con unicamente el permiso `local/scorm_incca:viewadminpanel` y asignarlo al usuario
a nivel de sistema siguiendo el mismo procedimiento.

Una vez asignado, el usuario puede agregar el bloque Panel SCORM INCCA a su Dashboard
activando el modo de edicion y seleccionando el bloque desde el menu de bloques
disponibles. Desde ahi tendra acceso directo a los SCORMs registrados y al log de
auditoria.

---

## Comportamiento segun tipo de usuario

| Perfil de usuario | Puede ver el bloque | Puede acceder al panel | Puede descargar SCORMs protegidos | Puede eliminar SCORMs protegidos |
|-------------------|---------------------|------------------------|-----------------------------------|----------------------------------|
| Usuario sin permisos especiales | No | No | No | No |
| Usuario con permiso descargar (en el curso) | No | No | Si | No |
| Usuario con permiso cargar (en el curso) | No | No | Si | Si |
| Usuario con permiso viewadminpanel (sistema) | Si | Si (sin seccion usuarios) | Depende del permiso descargar | Depende del permiso cargar o descargar |
| Administrador del sitio | Si | Si (completo) | Si | Si |

---

## Desinstalacion

Ambos plugins pueden desinstalarse desde Administracion del sitio > Plugins >
Informacion del plugin sin dejar datos remanentes en la base de datos de Moodle.
Los SCORMs, cursos, usuarios y archivos no se ven afectados por la desinstalacion.

Se recomienda desinstalar primero el bloque y luego el plugin principal. Purgar
caches despues de cada desinstalacion.

---

## Documentacion tecnica

Cada plugin incluye su propio archivo README con la documentacion tecnica detallada:

- `local_scorm_incca/README.md` — arquitectura de intercepcion, hooks utilizados,
  observadores de eventos, esquema de base de datos, tipos de log y estructura de archivos.

- `block_scorm_incca/README.md` — capabilities del bloque, compatibilidad de temas
  y comportamiento detallado segun permisos.
