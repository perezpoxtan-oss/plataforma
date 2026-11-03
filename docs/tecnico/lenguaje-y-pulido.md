# Etapa 7 · Lenguaje, manual y pulido

Plan de correcciones, Etapa 7 (C-35, C-38, C-39, C-40, C-41, C-43 y C-42). Riesgo bajo pero visible: casi todo son textos, el lugar de los ajustes y pasos siguientes. Ninguna corrección toca Accidentes (vistas, formato, textos ni la palabra «Huésped»).

## C-35 · Recursos Humanos pulido

- **El turno de la vacante pasa al colaborador.** `AdministradorCandidatos::contratar()` agrega `turno_id`: el que elige RR. HH. en el diálogo «Contratar» (campo **Turno**; vacío = sin turno) o, si el campo no llega, `turnoDeLaVacante()` (el turno de la vacante de la postulación, solo si está activo y se usa en la sede del colaborador; si no, ninguno, para que Colaboradores no rechace el alta). La validación del turno sigue siendo la de `AdministradorColaboradores`.
- **Un solo campo «¿A qué vacante o puesto?»** (`a_que` en el alta y la edición de la ficha de RR. HH.): `v:ID` (vacante publicada o en pausa → su puesto y su departamento), `p:ID` (puesto activo → el departamento si el puesto es de un solo departamento activo), `otro` (texto libre en «¿Cuál?») o vacío. `AdministradorCandidatos::aQue()` lo traduce a `vacante_id`, `puesto_id`, `departamento_id` y `vacante` antes de validar. **X-02:** el texto libre se compara (sin acentos ni mayúsculas, `Columna::normalizar`) con las vacantes publicadas y los puestos; si coincide, se liga al catálogo y el texto no se guarda. El departamento elegido a mano (**Avanzado: elegir otro departamento**) gana sobre el deducido; la vista solo lo marca cuando es distinto del que sale de la vacante o el puesto (`departamentoDeducido()`). Sin `a_que` (caseta, kiosco, bolsa de trabajo) los campos llegan como antes.
- **Lista de Candidatos corta:** arriba solo **Nuevo candidato** y la búsqueda; sede, departamento, vacante, Recepción, Criterios de entrevista, Documentos y plazos y la exportación van en **Avanzado** (`<details data-avanzado-candidatos>`, abierto si hay un filtro de Avanzado puesto; los filtros usan `form="filtrosCandidatos"`).
- **Etapas con un solo nombre:** `Candidato::ETAPAS['considerar']` = «Considerar» (antes «Considerar / cartera»), igual que su botón. Pantallas, Excel, Informes y el manual toman el nombre de `Candidato::ETAPAS`.
- Pruebas: `EtapaSieteRrhhTest`.

## C-38 · Configuración ordenada

Un solo lugar por ajuste (ningún ajuste se pierde; las rutas no cambian, solo la sección donde se ve la tarjeta):

| Tarjeta | Antes | Ahora |
|---|---|---|
| **Colaboradores: avisos por correo** (`#config-colaboradores`) | Caseta y accesos | **Estructura** (`#sec-estructura`, sección nueva) |
| **Procedimientos: acuse «Leí y entendí» y recordatorio** (`#cfg-procedimientos`) y **Procedimientos: avisos por correo** (`#config-procedimientos`) | Caseta y accesos | Sección de la bitácora (`#sec-novedades`), junto a «¿Qué hacer? Ver procedimiento» |
| Aviso **«Autorizaciones: avisar al responsable del departamento…»** (`autorizacion_departamento`) | Grupo «Recepción y candidatos» (Recepción y RR. HH.) | Grupo propio **Autorizaciones de visitas** (`GruposAvisos::GRUPOS['autorizaciones']`, `#config-autorizaciones`) en **Caseta y accesos**, junto a «Quién espera autorización del departamento» |

- La vista decide la sección con `$seccionDeGrupo` y muestra cada sección con `$verSeccion('<ancla>')` (antes, por índice del arreglo).
- La sección de la bitácora también aparece si la empresa tiene Procedimientos sin Novedades.
- Migración `2026_11_17_000100_avisos_de_autorizaciones_en_la_caseta`: el interruptor general del grupo nuevo (`preferencias.avisos_modulos.autorizaciones`) hereda el de `candidatos` cuando estaba apagado, para que ninguna empresa empiece a recibir un correo que no recibía. `down()` quita la llave (el aviso vuelve a obedecer al interruptor de Candidatos).
- Mi cuenta (`PreferenciasAvisos`) agrupa el aviso con su grupo nuevo.
- Pruebas: `EtapaSieteConfiguracionTest`.

## C-39 · Glosario único (DD-20)

El glosario está en el manual: `docs/usuario/glosario.md` (Primeros pasos, visible para todos). Revisión de títulos, menús y mensajes:

| Dónde | Antes | Ahora |
|---|---|---|
| Accesos (título) | Control de Accesos | **Bitácora de accesos** (como el menú) |
| Accesos (tarjeta y Excel) | «Espera a que el Host autorice», columna «Visita a / Host» | «Espera a que quien lo citó autorice», columna «A quién viene» |
| Accesos (¿A quién viene? \*) | — | Se revisó: el asterisco es correcto, el campo es obligatorio para visita, huésped, proveedor y contratista (`RegistroAccesos`). |
| Préstamo de llaves (título) | Bitácora de Llaves | **Préstamo de llaves** (como el menú) |
| Préstamo de llaves (garantía) | Gafete interno / GAFETE INTERNO / «GAFETE NÚM.» | **Credencial de empleado** / CREDENCIAL DE EMPLEADO / «CREDENCIAL NÚM.» (la clave `gafete_interno` no cambia: los préstamos de antes muestran el texto nuevo) |
| Gafetes (título) | Inventario Gafetes | **Gafetes** |
| Tablero (título y hoja impresa) | Consola de Monitoreo | **Tablero** |
| Usuarios (título) | Usuarios Operativos / Usuarios del Sistema | **Usuarios** |
| Turnos (subtítulo) | Horarios corporativos predefinidos | Horarios de trabajo de cada sede (corporativo es un giro) |
| Empresas (alta y edición) | Rubro | **Giro** (también el mensaje de validación) |
| Menú Operación | Sección «Incidentes» (con la bitácora renombrada: «Operación → Incidentes → Incidentes») | Sección **Bitácora**: Novedades/Incidentes, Lost & Found y Robo. Migración `2026_11_17_000200_seccion_bitacora_en_operacion` (solo si la sección seguía con su nombre de fábrica; `down()` probado) |
| Candidatos | Considerar / cartera | Considerar (C-35) |

- **Sin tocar:** Accidentes (formato, vistas, textos, «Huésped» y el anexo de **guardavidas**, que es parte del formato de Accidente y de su sección en Tendencias). El nombre del módulo en la Matriz de permisos, la Bitácora de auditoría y lo que se contrata pasó de **Proveedores** a **Empresas externas** (decisión del dueño del 3 nov, migración `2026_11_19_000100_empresas_externas_en_la_matriz`, `down()` lo regresa); la clave `proveedores` y los permisos `proveedores.*` no cambian. `EtapaSieteGlosarioTest` lo vigila.
- **Plural de residente:** se revisaron Residentes, Colaboradores y el manual en condominio; ninguno dice «residente(es)».
- Prueba `EtapaSieteGlosarioTest`: ninguna palabra retirada vuelve a vistas, mensajes del código, `plataforma.js` ni el manual (fuera de comentarios y de Accidentes); el título de cada pantalla es el nombre de su menú; la migración de la sección.

## C-40 · Diez verbos

**Guardar, Dar de baja, Reactivar, Imprimir, Dar salida, Recibir, Aprobar, Rechazar, Atender, Canalizar** (docs/criterios-ux.md, regla 4). Cuando el verbo solo no basta, el texto de apoyo va después de « · » (patrón de «Aprobar · es el mismo», DD-20). Cambios (pantallas, mensajes, avisos, `plataforma.js`, manual, cursos y pruebas):

| Antes | Ahora |
|---|---|
| Registrar, Crear, Registrar Empresa / Usuario / Zona / Vehículo, Crear Rol / Sección / plantilla, Agregar Piso / Área / Elemento / tipo / categoría, Generar Lote, Registrar Cierre, Despachar Ticket, Registrar firmado en papel, Registrar y (C/c)apturar siguiente, Registrar y dejar pasar | **Guardar …** (Guardar empresa, Guardar lote, Guardar ticket, Guardar y capturar siguiente, Guardar y dejar pasar…) |
| Iniciar Recorrido / Finalizar Recorrido (DD-20: «Terminar recorrido») | Guardar e iniciar recorrido / **Guardar y terminar recorrido** |
| Nueva versión, Firmar de enterado, Subir (PDF…), Conservar 6 meses más, Dar de alta como colaborador | Guardar como nueva versión, Guardar · firmar de enterado, Guardar documento (PDF…), Guardar 6 meses más, Guardar como colaborador |
| Registrar Salida, Salida Final, Confirmar Salida, Salida a Tour, Salida Temporal, Autorizar salida de regreso; acompañante «Salida» / «Temporal» | **Dar salida**, Dar salida final, Dar salida a tour, Dar salida temporal, Dar salida de regreso |
| Registrar Regreso, Confirmar Regreso, Confirmar llegada a esta sede; acompañante «Regresó» | **Recibir de regreso**, **Recibir**, Recibir en esta sede |
| Confirmar Autorización, Autorizar ingreso, Autorizar por excepción, Que pase, Que pase y lo atiendo, Llamé y autorizó · dejar pasar, Publicar | **Aprobar ingreso**, Aprobar por excepción, **Aprobar · que pase**, **Aprobar y atender**, Aprobar · llamé y autorizó, Aprobar y publicar |
| Que espere, No puede pasar, No autorizó, Confirmar Rechazo | **Rechazar · que espere**, Rechazar · no puede pasar, Rechazar · no autorizó, Rechazar |
| Confirmar y Reabrir, Reanudar, Reabrir como borrador, Activar | **Reactivar ticket**, Reactivar, Reactivar como borrador, Reactivar |
| Cancelar pase, Cancelar reserva, Cerrar vacante, Cerrar / Pausar, Cerrar como cubierta, Descartar borrador, Retirar procedimiento, Anular enlace, Quitar sus dos pasos, Desactivar | **Dar de baja el pase / la reserva / el borrador / el procedimiento / el enlace / sus dos pasos**, Dar de baja · cerrar vacante, Dar de baja · cerrar / · pausar / · cerrar como cubierta, Dar de baja |
| Listo, Ya lo revisé, Reembolso entregado, Confirmar recuperado, Verificar (aviso de la campana) | **Atender · listo**, Atender · ya lo revisé, Atender · reembolso entregado, Atender · recuperado, Atender |
| Enviar a revisión | **Canalizar a revisión** |
| Guardar unión (DD-20: «Unir») | **Aprobar · es el mismo** |
| Reimprimir | Imprimir otra vez |
| Autenticar Ingreso | Entrar |

- **Prueba automática** `Tests\Feature\Nucleo\DiezVerbosTest`: lee todos los `<button>` de enviar de las vistas Blade (sin Accidentes) y los textos de `Autorizacion::BOTONES`, `Vacante::BOTONES` y `PaseSalida::PASOS_FISICOS`; falla si un texto no empieza con uno de los diez verbos, con un verbo ya aceptado (No regresó, Restringir acceso, Quitar restricción, Liberar lugar, Canalizar al supervisor, Delegar…, Aprobar por excepción, Aprobar · es el mismo) o con algo de la **lista de exclusión** documentada en la prueba (`EXCLUIDOS`: moverse, buscar y filtrar, exportar, sesión y cuenta, respuestas de un diálogo, formularios de alguien de fuera, herramientas de la plataforma y de configuración, borrado definitivo y «No se presentó»). Agregar algo a la exclusión necesita la aprobación del dueño.
- **Sin tocar:** Accidentes; las acciones del lector universal (`AccionesLector`, Etapa 8 las unifica con «Dar salida / Recibir»); los botones que abren un diálogo (`type="button"`), que llevan al formulario cuyo botón de enviar sí usa el verbo.
- Las claves internas (respuestas de autorización, estados, rutas) no cambian.

## C-41 · Siguiente paso tras guardar

Con el patrón de Sedes («¿Qué sigue?»), el aviso verde trae su botón (`componentes.siguiente-paso`, sesión `siguiente_paso`):

| Al guardar | Botón | A dónde lleva | Quién lo ve |
|---|---|---|---|
| Un departamento nuevo | **Nombrar responsable** | Responsables por departamento con la ventana de ese departamento abierta (`?abrir=ID#depto-ID`) | Quien tiene `autorizaciones.configurar` |
| Un colaborador nuevo | **Crear su cuenta** | Usuarios con «Alta de Usuario» abierta y el colaborador ya vinculado (`usuarios.index?colaborador=ID`: número, nombre y vínculo; solo personal activo sin cuenta de la misma empresa) | Quien tiene `usuarios.crear` (no para residentes) |
| Un colaborador nuevo | **Imprimir credencial** | Etiquetas QR filtradas a ese colaborador | Quien tiene `etiquetas_qr.ver` y `colaboradores.editar` (no en altas provisionales) |
| Una cuenta que puede autorizar visitas (`autorizaciones.responder`) | **Nombrarlo responsable** | Responsables por departamento (con la ventana del departamento de su colaborador abierta, si tiene) | Quien tiene `autorizaciones.configurar` |

- Responsables por departamento abre la ventana de un departamento con `?abrir=ID` (también sirve para los enlaces de Mis pendientes).
- Pruebas: `EtapaSieteSiguientePasoTest`.

## C-43 · Pulido

- **Cambiar el giro pide confirmar (A-16).** `EmpresaController::update`: si el Super Administrador cambia `rubro_id` sin `confirmar_giro=1`, regresa al formulario con el impacto (`impactoDelGiro()`: palabras de pantallas, avisos, correos, impresiones y manual; tipos de «Nuevo Ingreso»; lo propio de cada giro; no se borra nada) y la casilla **Sí, cambiar el giro**. Sin cambiar el giro no pregunta.
- **Filtros de Colaboradores según el alcance (A-18).** Con alcance de sede, los filtros de departamento y turno ofrecen solo los que aplican en sus sedes (`aplicanEn`), más los que ya tiene alguien de su lista.
- **Importar: «Crear los que faltan» (A-19).** `App\Services\Importacion\FaltantesDeCatalogo` (solo colaboradores, en vista previa): lista los departamentos, puestos y turnos del archivo que no existen y, con el botón **Guardar · crear los que faltan** (`POST /importaciones/{id}/crear-faltantes`), los crea y vuelve a revisar el archivo (todavía no guarda colaboradores). Departamento: todas las sedes. Puesto: ligado a su departamento si todas sus filas traen el mismo. Turno: solo si el nombre trae el horario («Matutino 07:00-15:00», «7:00 a 15:00»); si no, se dice cuál falta. Cada catálogo pide su permiso «crear» con alcance de empresa. Auditoría `departamentos.creado` / `puestos.creado` / `turnos.creado` con `origen: importacion`.
- **Etiquetas QR: filtrar antes del límite (GC-12).** `EtiquetasMasivas::lista()` recorre cada tipo por lotes (`lazy(500)`) aplicando estatus y búsqueda y se detiene al juntar `POR_TIPO` (500) coincidencias; antes cortaba en 500 registros y lo buscado podía quedar fuera.
- **Ficha de llave en el celular (GC-14).** Los íconos de la ficha (`acciones-ficha-llave`) se acomodan en su propio renglón en pantallas chicas y conservan 44 px.
- **Transporte (D-21).** `BitacoraTransporte::ocurrencias()` calcula la hora de caseta del servicio de ayer, hoy o mañana contando el día en que **sale** la ruta (una llegada 23:20 → 00:30 de los lunes llega el martes a las 00:30). Lo usan la ruta sugerida (`sugerencia()`), el **estatus sugerido** (`estatusSugerido()`: «A tiempo» hasta `TOLERANCIA_A_TIEMPO` = 10 minutos después de la hora; después «Con retraso»; cada ruta del diálogo trae `data-estatus` y `plataforma.js` lo marca al elegirla, salvo que el guardia ya haya elegido o la unidad no llegó) y **Rutas sin registro** (`RutasSinRegistro`: el servicio de ayer que cruzó la medianoche también avisa; el registro cuenta con la fecha del servicio o la de hoy).
- **Exportar (E-13).** `colaboradores.exportar` (ya existía en el catálogo) tiene su botón y su ruta `GET /colaboradores/exportar` (sin datos personales). Nuevos `pases_salida.exportar`, `responsivas.exportar` y `vouchers.exportar` con sus rutas `GET /pases-salida/exportar` (búsqueda y sede), `/responsivas/exportar` y `/vouchers/exportar` (filtros), en CSV para Excel. Migración `2026_11_17_000300_permiso_exportar_pases_responsivas_vouchers`: liga la acción y la da, con el alcance con que ven el módulo, a los roles que ya exportan la Bitácora de accesos (la regla de las plantillas: no al Agente ni al Solicitante); `down()` quita la acción de los tres módulos con sus permisos.
- Pruebas: `EtapaSietePulidoTest`.

## C-42 · Manual (barrido final)

- Rutas viejas corregidas: «Configuración → Caseta y activos» (ya no existe) → **Activos** (llaves y responsivas) o **Transporte** (firmas del vale), en el manual y en la lección 43; «Recursos Humanos → Recepción → Ajustes» → **Recepción de RR. HH. → Ajustes**; «Estructura → Configuración → Mostrar en Inicio» → **… → General → Mostrar en Inicio**; «Operación → Caseta → Accesos» → **Bitácora de accesos** (guía de estilo y lecciones 65 a 68); en Usuarios, «reingresar» → **Reactivar**.
- `menus.md`: la sección **Bitácora**, **Reservas de áreas**, **Tickets de mi departamento**, **Altas por verificar** y **Residentes** en la tabla de menús; en Mis pendientes, **Llaves, gafetes o equipos que no regresaron** (el gafete que no regresó) y **Reservas por aprobar**, **Órdenes de servicio por atender** y **Reservas con cambio pedido**.
- **Prueba en CI** `Tests\Feature\Nucleo\ManualRutasTest`: cada ruta en negritas del manual que empieza con un menú («**Operación → Caseta → Préstamo de llaves**») debe existir en el menú real (secciones y pantallas de `MenuSeeder`, nombres del catálogo y las entradas que agrega `ConstructorMenu`), y cada «**Configuración → X**» (también «Estructura → Configuración → X») debe ser una sección real de Configuración; la lista de secciones de la prueba se compara con la vista. `menus.md` debe nombrar cada sección y pantalla del menú y los renglones de Mis pendientes de lo que no regresó y de Reservas. Las lecciones del curso (`docs/cursos`) describen el sistema en el momento de cada entrega y no entran en la prueba.
- Lección 68 del curso: «Las mismas palabras en toda la plataforma».
