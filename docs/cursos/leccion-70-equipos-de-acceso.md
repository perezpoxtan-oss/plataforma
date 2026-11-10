# Lección 70 · Equipos de acceso ZKTeco y Hikvision

**Duración:** 30 minutos · **Para:** Administrador del cliente e Integrador técnico (prácticas 1 a 4) y Operador de caseta (práctica 5).

Aprenderás:

1. A dar de alta un equipo ZKTeco y uno Hikvision.
2. A configurar cada equipo para que llame a la plataforma.
3. A cargar al personal en un ZKTeco con **Sincronizar** y a ver que las altas y bajas llegan solas.
4. A leer el estado de un equipo y sus últimos marcajes.
5. A reconocer en la caseta lo que registró un equipo y sus avisos.

## Antes de empezar

Usa una empresa de prueba con la Bitácora de accesos, una sede, dos colaboradores con número de empleado y su credencial con tarjeta, una cuenta de administrador y una de agente de esa sede. Si no tienes un equipo real, tu instructor simula sus llamadas.

## Práctica 1 · Alta de los equipos

1. Entra a **Estructura → Configuración → Caseta y accesos** y toca **Configurar en Equipos de acceso**.
2. Toca **Nuevo equipo**, elige **ZKTeco**, la sede, escribe «Checador de personal», su número de serie y la puerta. **Registra:** Entrada y salida. Toca **Guardar**.
3. Repite con **Hikvision**, «Torniquete de servicio», **Registra:** Solo entrada. Al guardar, toca **Copiar dirección**.

Comprueba: las dos tarjetas dicen **SIN CONECTAR** y la de Hikvision mostró su dirección una sola vez (recarga la página: ya no aparece).

## Práctica 2 · Configurar los equipos

1. ZKTeco: en el equipo, **Menú → Comunicación → Servidor en la nube**, con la dirección y el puerto que dice **Cómo configurarlo**. Reinícialo.
2. Hikvision: en su página, **Escucha HTTP** con la dirección que copiaste, formato JSON y latido cada 1 a 5 minutos.

Comprueba: en menos de un minuto el ZKTeco dice **EN LÍNEA**.

## Práctica 3 · Cargar al personal

1. En el ZKTeco toca **Sincronizar**.
2. Espera un minuto y revisa «Altas y bajas por entregar».
3. Pide a Recursos Humanos que dé de baja la credencial de uno de los colaboradores.

Comprueba: el contador llegó a 0; tras la baja vuelve a 1 y en un minuto a 0; esa tarjeta ya no abre el equipo.

## Práctica 4 · Leer el estado y los marcajes

1. Pasa la tarjeta de un colaborador en cada equipo.
2. Abre **Últimos marcajes** en cada tarjeta.
3. Pasa la tarjeta dada de baja en la práctica 3 por el Hikvision.

Comprueba: aparecen «Entrada registrada» y «Credencial no vigente». Cambia **Cuando un equipo se queda sin conexión** a 1 hora y regrésalo a 2 al terminar.

## Práctica 5 · En la caseta

1. Entra con la cuenta de agente a **Operación → Caseta → Bitácora de accesos**.
2. Busca al colaborador que pasó por el equipo.

Comprueba: dice «(por Equipo: Checador de personal)» y la campana tiene «Credencial no vigente usada en …» de la práctica 4. No tuviste que registrar nada.

## Repaso

- La plataforma **solo recibe** llamadas del equipo: si falla, la caseta registra a mano.
- ZKTeco recibe solo las altas y bajas; en Hikvision las tarjetas se cargan en el equipo.
- El antipassback **solo avisa**; el reconocimiento facial no se usa.
