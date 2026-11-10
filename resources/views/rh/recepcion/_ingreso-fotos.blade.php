{{--
    Recepción (ADR-0007): fotos de evidencia en caseta (opcionales). Se guardan privadas y
    viajan a la ficha del candidato. En el celular abren la cámara directamente.
--}}
<div class="bloque-perfil" data-condicion data-solo-tipos="visitante proveedor contratista">
    <details class="mas-detalles fotos-ingreso">
        <summary class="campo-etiqueta"><i class="bi bi-camera me-1" aria-hidden="true"></i>Fotos <span class="text-lowercase fw-normal">(opcional: persona e identificación)</span></summary>
        <div class="row mt-2">
            <div class="col-6">
                <label class="caja-foto-ingreso" for="ingreso_foto_persona">
                    <img alt="" hidden data-vista-foto>
                    <i class="bi bi-person-bounding-box" aria-hidden="true"></i>
                    <span>Foto de la persona</span>
                </label>
                <input type="file" id="ingreso_foto_persona" name="foto_persona" class="visually-hidden" accept="image/*" capture="user" data-foto-ingreso>
            </div>
            <div class="col-6">
                <label class="caja-foto-ingreso" for="ingreso_foto_id">
                    <img alt="" hidden data-vista-foto>
                    <i class="bi bi-person-vcard" aria-hidden="true"></i>
                    <span>Foto de la identificación</span>
                </label>
                <input type="file" id="ingreso_foto_id" name="foto_identificacion" class="visually-hidden" accept="image/*" capture="environment" data-foto-ingreso>
            </div>
        </div>
        <p class="campo-ayuda mt-1">Toca un recuadro para tomar la foto. Se guarda privada: solo la ven quien consulta la bitácora y Recursos Humanos.</p>
    </details>
</div>
