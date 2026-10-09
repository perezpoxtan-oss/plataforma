# Guía de estilo del Manual de usuario (obligatoria)

El manual vive en `docs/usuario/*.md` (una página por pantalla/módulo) y la plataforma lo muestra en el módulo «Manual». Lo leerán agentes de caseta, supervisores, RR. HH. y directivos sin experiencia técnica: debe ser **a prueba de errores**.

## Estructura de cada página
1. Primera línea: front matter YAML:
   ```
   ---
   titulo: Bitácora de accesos
   modulos: [accesos]          # claves del catálogo de módulos; la página se muestra si el usuario puede ver alguno
   seccion: Operación          # Operación | Padrones | Recursos Humanos | Informes | Estructura | Primeros pasos
   orden: 10
   resumen: Registrar quién entra y sale de la sede.
   ---
   ```
   `modulos: []` = página general visible para todos (inicio de sesión, menús, mis pendientes…).
2. `# Título` y un párrafo «**¿Para qué sirve?**» de 2–3 renglones.
3. «**Antes de empezar**»: qué permiso o dato se necesita (en palabras: «necesitas que tu rol pueda registrar accesos; si no ves el botón, pide a tu administrador…»).
4. Una sección `## Cómo …` por tarea (registrar, buscar, editar, dar de baja, imprimir…). Cada una:
   - Pasos **numerados**, uno por acción, con el texto EXACTO de botones y menús en negritas: «Toca **Nuevo Ingreso**».
   - La ruta del menú completa la primera vez: **Operación → Caseta → Accesos**.
   - Una captura `![Descripción breve](img/<modulo>/<archivo>.png)` donde ayude (al menos una por tarea principal).
   - «**Qué debes ver:**» el resultado esperado.
5. «**Si algo sale mal**»: tabla Mensaje o síntoma | Qué significa | Qué hacer (los mensajes de error reales de la pantalla).
6. «**Preguntas frecuentes**» (3–6), y «**Relacionado**» con enlaces a otras páginas del manual (`[Préstamo de llaves](prestamo-llaves.md)`).

## Reglas de redacción
- Español de México, tú, frases cortas, sin tecnicismos (nada de «endpoint», «CSV» sin explicar → «archivo de Excel (CSV)», «PR», «migración», «QA», «demo», «seeder»).
- Usa los nombres de las pantallas tal como se ven hoy (menús reorganizados: Operación, Padrones, Recursos Humanos, Informes, Estructura; botón «Mis pendientes»; campana).
- Ejemplos con nombres ficticios genéricos («Hotel Centro», «Juan Pérez»); NO uses datos de prueba con contraseñas.
- **Prohibido** incluir cualquier dato que vincule al autor o a la infraestructura: dominios o direcciones reales (vdcp, qa., cPanel, Neubox, GitHub, rutas del servidor), nombres de personas reales, correos reales, contraseñas, nombres de usuarios de prueba (*.demo), número de versión de desarrollo, referencias a «SEGCAT» o a «la migración». Las direcciones se escriben como «la dirección de tu plataforma».
- No describas cómo está hecho por dentro; solo cómo se usa.
- Las capturas existentes en `docs/usuario/img/` se pueden reutilizar; revisa que no muestren barras de dirección, dominios, nombres de usuario de prueba visibles en grande ni datos reales. Si una captura muestra «agente.demo» u otro usuario de prueba en el encabezado, vuelve a tomarla con un usuario de ejemplo (crea en tu base local usuarios con nombres genéricos, p. ej. «Ana López — Agente») o recórtala.
- Si una página antigua mezcla notas de pruebas, rondas, «Qué se corrigió respecto a …», IDs de casos de QA o fechas de entrega: elimínalo.
