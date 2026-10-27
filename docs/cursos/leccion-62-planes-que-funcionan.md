# Lección 62 · Planes que funcionan: la organización base y «No viene en tu plan»

**Duración:** 35 minutos · **Para:** Administrador del cliente y Operador de caseta (prácticas 1 a 4); Super Administrador (prácticas 5 y 6).

Aprenderás:

1. Qué trae cada plan y por qué Colaboradores, Departamentos, Puestos, Turnos y Autorizaciones vienen en todos.
2. A distinguir «Esto no viene en tu plan» de «No tienes permiso para ver esto».
3. A registrar una visita eligiendo su motivo (ninguno viene marcado).
4. A dar de alta una ruta sin turno y un vale de taxi solo con la cantidad de pasajeros.
5. (Super Administrador) Cómo la plataforma avisa cuando un plan no funciona y qué pasa al bajar de plan.

## Antes de empezar

Usa dos empresas de prueba: una con plan **Esencial** y otra con plan **Profesional**, con su cuenta de administrador (tu instructor te da los usuarios). Para las prácticas 5 y 6 necesitas la cuenta del Super Administrador.

## Lo que hay que saber

- **Todos los planes** traen la estructura de la empresa y el **directorio**: Colaboradores, Departamentos, Puestos, Turnos y Autorizaciones (quién autoriza y «No molestar»). Están en **Estructura → Organización**. Sin ellos la caseta no puede registrar proveedores, visitas, préstamos, responsivas ni pases.
- **Recursos Humanos** (plan Corporativo o el complemento) suma Vacantes, Recepción, Candidatos, entrevistas y el **expediente** (CURP, RFC, NSS y documentos).
- **Esencial:** caseta y padrones. **Profesional:** además incidentes, Protección Civil (equipos y recorridos), procedimientos e informes. **Reservas y eventos** es un complemento para cualquier plan.
- Si abres una pantalla que tu plan no trae, la plataforma dice **Esto no viene en tu plan** y te lleva a **Tu suscripción**. Si dice **No tienes permiso para ver esto**, es tu rol.

## Práctica 1 · El directorio en el plan Esencial (Administrador)

1. Entra con el administrador de la empresa Esencial y abre **Estructura**.
2. En **Organización** abre **Departamentos** y crea «Mantenimiento» en todas las sedes.
3. Abre **Colaboradores** y da de alta a «Laura Gómez», número `E-1`, en Mantenimiento.

Comprueba: el colaborador aparece en la lista. En su ficha **no** hay datos legales ni expediente (son de Recursos Humanos).

## Práctica 2 · «No viene en tu plan» (Administrador)

1. Con la misma cuenta, escribe en la barra la dirección de tu plataforma seguida de `/procedimientos`.
2. Lee la pantalla.

Comprueba: dice **Esto no viene en tu plan (Esencial)** y tiene el botón **Ver mi suscripción**. No menciona la Matriz de permisos.

## Práctica 3 · Elegir el motivo de la visita (Operador de caseta)

1. **Operación → Caseta → Bitácora de accesos → Nuevo Ingreso → Personal Externo**.
2. Escribe el nombre y toca **Registrar y dejar pasar** sin elegir motivo. Lee el mensaje.
3. Elige **Visita general**, escribe «Mensajería» en **¿A quién o a qué viene?** y registra.

Comprueba: primero sale «Elige el motivo de la visita.»; después la tarjeta en **Gente en Sitio** dice **Viene a: MENSAJERÍA**. En el plan Esencial no aparece el motivo **Recursos Humanos**.

## Práctica 4 · Ruta sin turno y vale con cantidad de pasajeros (Administrador y caseta)

1. **Padrones → Rutas de transporte → Nueva Llegada**: elige la transportista, deja el turno vacío (o no aparece si no usas turnos) y llena un horario diario.
2. **Operación → Caseta → Bitácora de transporte**: registra que la unidad **No llegó** y agrega un taxi; abre **¿No puedes registrar a los pasajeros?** y escribe `3`.

Comprueba: la ruta se guarda sin turno y el vale queda con **3** pasajeros («Sin registrar» en el vale impreso).

## Práctica 5 · Un plan que no funciona (Super Administrador)

1. **Estructura → Catálogo comercial → Planes → Nuevo plan**: nómbralo «Solo caseta», precio 500, y marca solo **Caseta y accesos**. Guarda.
2. Lee los mensajes.

Comprueba: sale «Atención, así no funciona: Préstamo de llaves necesita Catálogo de llaves…» y arriba del catálogo **Hay planes que no funcionan como están**. Da de baja el plan de práctica.

## Práctica 6 · Bajar de plan (Super Administrador)

1. Abre la ficha de un cliente **Profesional** de prueba y cámbialo a **Esencial** con el motivo «Práctica».
2. Entra con su administrador y abre **Operación → Novedades**.

Comprueba: la bitácora se ve, pero no hay botón para registrar; la campana dice «Algunos módulos quedaron en solo consulta». Regresa el cliente a **Profesional**: todo vuelve a funcionar.

## Para el Integrador técnico

- La lista de dependencias vive en `App\Support\DependenciasModulos` y la prueba `DependenciasModulosTest` la revisa en cada cambio.
- `ModulosEfectivos` escribe `empresa_modulos.activo` y `solo_lectura`; el motor de permisos deja en solo lectura las acciones ver, exportar e imprimir.
- La página 403 lee `App\Support\NoVieneEnTuPlan`.

## Preguntas de repaso

1. ¿En qué menú están Departamentos y Turnos? *(Estructura → Organización, en todos los planes)*
2. Un agente ve «No tienes permiso para ver esto». ¿Es el plan? *(No: es su rol; el plan dice «Esto no viene en tu plan»)*
3. ¿Por qué no viene marcado ningún motivo de visita? *(Para que cada visita quede con su motivo real; la empresa puede marcar uno en Configuración)*
4. ¿Qué pasa con los datos de un módulo cuando el cliente baja de plan? *(Quedan en solo consulta: se ven, exportan e imprimen)*
5. ¿Dónde se cambian los módulos de un cliente con suscripción? *(En su ficha de cliente: plan y excepciones)*
