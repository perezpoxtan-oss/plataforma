# Lección 10 — Configuración: correo, avisos y respaldos

**Para:** Super Administrador y Administrador del cliente · **Duración:** 15 minutos

## Práctica (en QA)

1. **Super Administrador:**
   - abre Estructura → Configuración y captura la cuenta de correo de avisos;
   - toca **Enviar prueba** y confirma que llegó;
   - toca **Respaldar ahora** y descarga el respaldo;
   - en la Bitácora de auditoría verifica que quedó registrada la descarga;
   - en **Plataforma → Versión de PHP del servidor** anota la versión de **Páginas (web)** y de **Tareas programadas** y si dicen **Al día** o **Requiere actualización**; si alguna está en rojo, redacta la petición a tu proveedor de hospedaje con el texto del aviso.
2. **`admin.demo`:** en Configuración deja activo el aviso de altas provisionales.
3. **`agente.demo`:** en Estructura → Colaboradores haz un **Alta provisional**.
4. **`rh.demo`** (su cuenta debe tener un correo real para verlo): revisa que llegó el correo "Alta provisional por validar" y usa su botón.

## Para recordar

- La contraseña del correo solo se escribe en la pantalla de Configuración.
- Los respaldos son automáticos; el manual es para antes de un cambio grande.
- Restaurar reemplaza toda la información: hazlo solo con un motivo claro.
- La versión de PHP solo la ve el Super Administrador; las dos (páginas y tareas programadas) deben decir **Al día**.

## Autoevaluación

1. ¿Qué pasa si el servidor de correo falla cuando el guardia registra un provisional? *(El registro se guarda igual; el error aparece en Configuración)*
2. ¿Cuántos días se guardan los respaldos? *(14, y siempre al menos los 3 más recientes)*
3. ¿Qué versión de la serie 8.4 de PHP ya corrige la falla PHP2Shell? *(8.4.27 o posterior; en 8.5, la 8.5.12 o posterior)*
