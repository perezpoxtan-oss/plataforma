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
