# block_scorm_incca

**Tipo de plugin:** Bloque (block)  
**Componente:** block_scorm_incca  
**Version:** 1.0.1  
**Moodle requerido:** 4.0+  
**Dependencia:** local_scorm_incca 1.0.10 o superior  
**Autor:** Kevin Garzon — Universidad INCCA de Colombia  
**Licencia:** GNU GPL v3 or later

---

## Descripcion general

`block_scorm_incca` es un bloque de navegacion rapida al panel de administracion del
plugin `local_scorm_incca`. Permite que cualquier usuario con la capability
`local/scorm_incca:viewadminpanel` acceda directamente a las paginas de administracion
del plugin sin necesidad de ser site admin ni navegar por el arbol de administracion
del sitio.

El bloque se auto-oculta para usuarios que no tienen la capability: cuando el contenido
esta vacio, Moodle no renderiza el contenedor del bloque fuera del modo de edicion.

---

## Requisitos

- Moodle 4.0 o superior
- Plugin `local_scorm_incca` version 1.0.10 o superior instalado y activo
- El plugin `local_scorm_incca` debe estar instalado primero

---

## Instalacion

1. Instalar primero `local_scorm_incca` si no esta instalado.
2. Subir `block_scorm_incca_1.0.1.zip` desde
   `Administracion del sitio > Plugins > Instalar plugins`.
3. Ejecutar la actualizacion de base de datos cuando Moodle lo solicite.
4. Purgar caches: `Administracion del sitio > Desarrollo > Purgar todas las caches`.

---

## Uso

### Agregar el bloque a una pagina

1. Navegar a la pagina donde se desea agregar el bloque (Dashboard, curso u otra).
2. Activar el modo de edicion.
3. En la opcion para agregar bloques, seleccionar **Panel SCORM INCCA**.
4. Desactivar el modo de edicion.

El bloque muestra su contenido unicamente a usuarios con la capability
`local/scorm_incca:viewadminpanel`. Para el resto de usuarios el bloque es invisible.

### Configuracion recomendada

Se recomienda agregar el bloque al **Dashboard por defecto** del sitio para que sea
visible a todos los usuarios con `viewadminpanel` sin que cada uno lo tenga que agregar
manualmente:

`Administracion del sitio > Apariencia > Pagina por defecto` (o segun el tema).

---

## Contenido del bloque

El bloque muestra un listado de enlaces segun los permisos del usuario:

| Enlace | Visible para |
|--------|--------------|
| Paquetes SCORM registrados (`/local/scorm_incca/index.php`) | Usuarios con `viewadminpanel` |
| Logs de auditoria (`/local/scorm_incca/logs.php`) | Usuarios con `viewadminpanel` |
| Usuarios con permisos personalizados (`/local/scorm_incca/users.php`) | Solo site admins |

---

## Capabilities del bloque

### block/scorm_incca:addinstance

| Propiedad | Valor |
|-----------|-------|
| Tipo | write |
| Contexto | CONTEXT_BLOCK |
| Archetype por defecto | manager: CAP_ALLOW |

Permite agregar el bloque a paginas de cursos, categorias y otras paginas del sitio.
Hereda permisos de `moodle/site:manageblocks`.

### block/scorm_incca:myaddinstance

| Propiedad | Valor |
|-----------|-------|
| Tipo | write |
| Contexto | CONTEXT_SYSTEM |
| Archetype por defecto | user: CAP_ALLOW, manager: CAP_ALLOW |

Permite a cualquier usuario autenticado agregar el bloque a su propio Dashboard.
Los usuarios con `viewadminpanel` pueden agregarlo a su dashboard sin asistencia del
administrador. Hereda permisos de `moodle/my:manageblocks`.

---

## Asignacion de permisos para acceso al panel

La visibilidad del contenido del bloque depende de la capability
`local/scorm_incca:viewadminpanel`, que pertenece al plugin `local_scorm_incca`.

**Requisito critico:** esta capability debe asignarse a nivel de CONTEXTO SISTEMA.

Si se asigna en un contexto inferior (curso, categoria), la verificacion del plugin
no la detectara y el bloque quedara vacio para ese usuario.

### Procedimiento de asignacion

1. Ir a `Administracion del sitio > Usuarios > Permisos > Asignar roles del sistema`.
2. Seleccionar el rol que se desea asignar (por ejemplo, un rol personalizado con
   `viewadminpanel`).
3. Asignar ese rol al usuario en el contexto del sistema.

Alternativamente, se puede usar `Override permissions` en el contexto sistema para
que un rol existente adquiera la capability `local/scorm_incca:viewadminpanel` a
nivel del sistema.

### Creacion de un rol personalizado

Para asignar acceso al panel sin otorgar el rol de manager completo, se puede crear
un rol con unicamente la capability necesaria:

1. `Administracion del sitio > Usuarios > Permisos > Definir roles > Agregar un nuevo rol`.
2. Asignar `local/scorm_incca:viewadminpanel` con valor CAP_ALLOW.
3. Configurar el contexto del rol como Sistema.
4. Asignar el nuevo rol al usuario en
   `Administracion del sitio > Usuarios > Permisos > Asignar roles del sistema`.

---

## Comportamiento segun tipo de usuario

| Usuario | Bloque visible | Contenido |
|---------|---------------|-----------|
| Sin autenticar | No | No aplica |
| Autenticado sin capability | No (bloque auto-oculto) | No aplica |
| Autenticado con `viewadminpanel` a nivel sistema | Si | Paquetes SCORM + Logs |
| Site admin | Si | Paquetes SCORM + Logs + Usuarios con permisos |

---

## Compatibilidad de temas

El bloque utiliza exclusivamente la API PHP de Moodle para generar su contenido
(`html_writer`, `moodle_url`). No incluye JavaScript propio ni hojas de estilo
personalizadas. El renderizado es manejado completamente por el tema activo, por lo
que es compatible con cualquier tema basado en Boost, Classic o temas personalizados.

---

## Estructura de archivos

```
blocks/scorm_incca/
├── block_scorm_incca.php      Clase principal del bloque: init(), get_content(),
│                              applicable_formats(), has_config()
├── version.php
├── classes/
│   └── privacy/
│       └── provider.php       Declaracion GDPR: el bloque no almacena datos personales
├── db/
│   └── access.php             Definicion de capabilities del bloque
└── lang/
    ├── en/block_scorm_incca.php
    └── es/block_scorm_incca.php
```

---

## Base de datos

El bloque no crea tablas propias ni almacena datos. Toda la informacion proviene del
plugin `local_scorm_incca` en tiempo de ejecucion.

---

## Desinstalacion

Desinstalar el bloque desde `Administracion del sitio > Plugins > Informacion del plugin`.
Moodle elimina automaticamente todas las instancias del bloque de las paginas donde
estuviera colocado y revoca las capabilities asociadas. No quedan datos remanentes.

Desinstalar el bloque no afecta al plugin `local_scorm_incca` ni a sus datos.
