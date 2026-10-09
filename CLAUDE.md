# Convenciones del proyecto

- Idioma de la interfaz, documentación y mensajes de commit: español.
- Migración espejo de SEGCAT: el diseño visual y la UX se conservan; los formularios pueden mejorar, salvo los de Accidentes, que no se tocan. Cambios visibles requieren aprobación.
- Multi-empresa: todo modelo operativo lleva `empresa_id` (+ `sede_id` cuando aplique) y usa el scope de tenant. Nunca consultas sin filtro de empresa.
- Permisos: ninguno en código; las pantallas declaran la acción (`modulo.accion`) y el motor decide.
- Tablas en InnoDB; nombres en español, snake_case, plural; auditoría `creado_por`/`actualizado_por` + timestamps.
- Hosting: Neubox cPanel sin SSH. No usar funciones que dependan de `proc_open`/`exec` en tiempo de ejecución web; las tareas programadas deben correr en proceso.
- Ramas: `main` = Producción, `develop` = QA, `feature/*` por tarea. Versiones `vMAYOR.MENOR.PARCHE`.
- Cada módulo se entrega con sus cuatro piezas (código, técnico, usuario, curso) — ver `docs/README.md`.
- Toda pantalla nueva o cambio visible actualiza su página en docs/usuario/ siguiendo docs/usuario/GUIA.md (el manual que ve el usuario).
