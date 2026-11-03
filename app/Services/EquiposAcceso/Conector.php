<?php

namespace App\Services\EquiposAcceso;

use App\Models\EquipoAcceso;
use Illuminate\Http\Request;

/**
 * Lo que toda marca de equipo de acceso le da a la plataforma (decisión 10,
 * ADR-0025). Para agregar una marca: una clase que lo implemente y su línea
 * en Conectores::MARCAS; el registro de marcajes, la cola de comandos, la
 * pantalla y el aviso de «sin conexión» no cambian.
 *
 * La plataforma vive en un hospedaje compartido: solo RECIBE llamadas del
 * equipo. Nunca llama a la red del cliente.
 */
interface Conector
{
    /** Clave guardada en equipos_acceso.marca. */
    public function marca(): string;

    /** Nombre comercial que ve el usuario. */
    public function nombre(): string;

    /**
     * ¿La plataforma puede cargar altas y bajas de usuarios en el equipo?
     * (ZKTeco sí: el equipo las recoge al preguntar. Hikvision no sin un
     * puente local: fase siguiente.)
     */
    public function sincronizaUsuarios(): bool;

    /**
     * Marcajes que trae la petición del equipo (ya validados). Lo que no se
     * entiende se descarta sin error; lo que no es un marcaje vuelve como
     * MarcajeLeido con `ignorar`.
     *
     * @return list<MarcajeLeido>
     */
    public function marcajes(Request $request, EquipoAcceso $equipo): array;

    /** Comando de alta o actualización de un usuario (null si la marca no sincroniza). */
    public function comandoAlta(string $pin, ?string $tarjeta): ?string;

    /** Comando de baja de un usuario (null si la marca no sincroniza). */
    public function comandoBaja(string $pin): ?string;

    /**
     * Pasos para configurar el equipo apuntando a la plataforma (pantalla
     * del equipo). `$token` solo viene recién generado.
     *
     * @return list<string>
     */
    public function comoConfigurar(EquipoAcceso $equipo, ?string $token): array;
}
