# Lección 25 — Ajustes de QA (ronda 4)

**Para:** Administrador del cliente, Recursos Humanos, Jefe de seguridad y Agentes · **Duración:** 15 minutos

## Qué cambió y por qué

1. **Días de resguardo de Lost & Found → Configuración.** Ya no se cambian desde la pantalla de Lost & Found sino en **Estructura → Configuración → Lost & Found: días de resguardo**, junto con los demás ajustes de la empresa.
2. **Equipos de Protección Civil → Padrones.** El catálogo de extintores, hidrantes y detectores es ahora un padrón propio en **Padrones → Inventarios de Seguridad → Equipos de Protección Civil**, con sus propios permisos en la Matriz. En Recorridos ya no está el botón «Catálogo de Equipos».
3. **Aviso de nombres repetidos.** Al dar de alta un usuario con el mismo nombre que otro, la plataforma avisa y pide confirmar que es otra persona; si existe un colaborador con ese nombre sin cuenta, sugiere vincularlo. En Colaboradores también avisa si ya hay alguien con ese nombre.

## Práctica (en QA, después de `plataforma:demo`)

1. **`admin.demo`**: abre Operación → **Lost & Found**. Debajo del título toca **Configurar en Estructura → Configuración**. Cambia *Ropa* a `20` y toca **Guardar días de resguardo**. Regresa a Lost & Found y revisa el semáforo de la ropa.
2. **`agente.demo`**: abre Lost & Found. No ve el enlace de configuración (no puede cambiar los días); los días de cada tipo aparecen en el filtro «Todos los tipos de valor».
3. **`admin.demo`**: abre **Padrones → Equipos de Protección Civil**. Registra un *Extintor* `EXT-90` en Hotel Demo Centro con **Guardar y capturar siguiente** y luego `EXT-91`. Imprime la etiqueta de `EXT-90`.
4. Abre Operación → **Recorridos de Protección Civil**: ya no está el botón «Catálogo de Equipos»; debajo del título hay un enlace pequeño a Padrones. Inicia un recorrido y escanea (o escribe) `EXT-90`: el recorrido lo encuentra igual que antes.
5. **`agente.demo`**: en Padrones → Equipos de Protección Civil ve los equipos de su sede y sus QR, pero no **Nuevo Equipo**.
6. **`admin.demo`**: Estructura → **Usuarios** → **Nuevo Usuario**. En **Nombre Completo** escribe `Daniela Canul May`. Aparece **Vincular con colaborador #1008 Daniela Canul May**: tócalo. Completa rol *Agente*, usuario `daniela.canul`, correo y contraseña, y registra.
7. Vuelve a **Nuevo Usuario** y escribe `daniela canul may` (en minúsculas). Aparece «Ya existe un usuario con ese nombre: @daniela.canul (Agente). ¿Es la misma persona?» y la casilla **Sí, es otra persona con el mismo nombre**. Intenta registrar sin marcarla: no se guarda y te lo explica dentro de la ventana. Cancela.
8. **`rh.demo`**: Recursos Humanos → **Colaboradores** → **Nuevo Colaborador**. Escribe *Daniela* / *Canul* / *May*: aparece el aviso con el #1008. Cancela.

## Para recordar

- Los ajustes que valen para **toda la empresa** viven en **Estructura → Configuración**.
- Los catálogos (llaves, gafetes, equipos…) viven en **Padrones**; la **Operación** los usa.
- Un nombre repetido no siempre es un error, pero **siempre** hay que confirmarlo. El usuario y el correo nunca se repiten.
- Vincular la cuenta con su colaborador evita capturar dos veces y avisa cuando Recursos Humanos da de baja a la persona.

## Autoevaluación

1. ¿Dónde cambio los días que un artículo puede estar en resguardo? *(Estructura → Configuración → Lost & Found: días de resguardo)*
2. Un Jefe de seguridad de una sede, ¿puede cambiarlos? *(No: hace falta «Configurar» de Lost & Found en toda la empresa)*
3. ¿Dónde doy de alta un extintor nuevo? *(Padrones → Equipos de Protección Civil)*
4. Escribí un nombre que ya existe y sí es otra persona, ¿qué hago? *(Marco «Sí, es otra persona con el mismo nombre» y registro)*
5. ¿Qué significa «Vinculado a Colaboradores»? *(Que la cuenta pertenece a ese colaborador del directorio de Recursos Humanos)*
