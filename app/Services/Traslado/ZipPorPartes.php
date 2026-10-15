<?php

namespace App\Services\Traslado;

use RuntimeException;

/**
 * Escritor de ZIP que se puede armar en varias partes (varias peticiones o
 * varias corridas de la tarea programada) sin reescribir lo ya escrito.
 *
 * ZipArchive reescribe el archivo completo cada vez que se cierra; en un
 * hospedaje compartido, con varios GB de fotos y documentos, eso no cabe en
 * el tiempo de ejecución. Aquí cada entrada se agrega al final del archivo y
 * el índice central (lo que ZipArchive escribiría al cerrar) se guarda en el
 * estado del trabajo y se escribe una sola vez al terminar.
 *
 *  - Archivos: método «store» (sin comprimir: fotos, PDF y .sql.gz ya vienen
 *    comprimidos). El CRC y el SHA-256 se calculan en la misma lectura.
 *  - Textos (manifest, LEEME, JSON y CSV): «deflate».
 *  - ZIP64 automático cuando una entrada, un desplazamiento o el total pasan
 *    de 4 GB, o hay más de 65 535 entradas.
 *  - Nombres en UTF-8 (bandera 0x0800).
 *
 * Si una parte se interrumpe a la mitad, la siguiente recorta el archivo al
 * último desplazamiento confirmado y vuelve a escribir esa entrada.
 */
class ZipPorPartes
{
    private const LIMITE_32 = 0xFFFFFFFF;

    private const BLOQUE = 1048576;

    /** @var resource */
    private $archivo;

    private int $offset;

    /**
     * @param  list<array{0: string, 1: int, 2: int, 3: int, 4: int, 5: int, 6: int, 7: int}>  $centrales  [nombre, método, crc, comprimido, original, desplazamiento, hora DOS, fecha DOS]
     */
    public function __construct(private readonly string $ruta, int $offset = 0, private array $centrales = [])
    {
        $f = @fopen($ruta, 'c+b');
        if ($f === false) {
            throw new RuntimeException('No se pudo abrir el paquete para escribir.');
        }
        @chmod($ruta, 0600);
        $this->archivo = $f;
        ftruncate($f, $offset);
        fseek($f, $offset);
        $this->offset = $offset;
    }

    public function offset(): int
    {
        return $this->offset;
    }

    /**
     * @return list<array{0: string, 1: int, 2: int, 3: int, 4: int, 5: int, 6: int, 7: int}>
     */
    public function centrales(): array
    {
        return $this->centrales;
    }

    /**
     * Agrega un archivo del disco y devuelve su tamaño y SHA-256. Sin
     * $comprimir va tal cual (store); con $comprimir (JSON y CSV) se comprime
     * por bloques (deflate) sin cargarlo completo en memoria.
     *
     * @return array{bytes: int, sha256: string}
     */
    public function agregarArchivo(string $nombre, string $origen, bool $comprimir = false): array
    {
        $this->validarNombre($nombre);
        clearstatcache(true, $origen);
        $tamano = @filesize($origen);
        $entrada = @fopen($origen, 'rb');
        if ($tamano === false || $entrada === false) {
            throw new RuntimeException("No se pudo leer el archivo {$nombre}.");
        }
        [$hora, $fecha] = self::fechaDos((int) (@filemtime($origen) ?: time()));
        // Comprimido: el tamaño final se sabe al terminar; ZIP64 con margen
        $zip64 = $comprimir ? $tamano >= 0xF0000000 : $tamano >= self::LIMITE_32;
        $inicio = $this->offset;
        $metodo = $comprimir ? 8 : 0;

        // Encabezado con CRC (y tamaño comprimido) en cero: se corrigen al terminar de copiar
        $this->escribir($this->encabezadoLocal($nombre, $metodo, 0, $comprimir ? 0 : $tamano, $tamano, $hora, $fecha, $zip64));
        $crc = hash_init('crc32b');
        $sha = hash_init('sha256');
        $deflate = $comprimir ? deflate_init(ZLIB_ENCODING_RAW, ['level' => 6]) : null;
        $copiados = 0;
        $escritosDatos = 0;
        while (! feof($entrada)) {
            $bloque = fread($entrada, self::BLOQUE);
            if ($bloque === false) {
                break;
            }
            if ($bloque === '') {
                continue;
            }
            hash_update($crc, $bloque);
            hash_update($sha, $bloque);
            $salida = $deflate !== null ? deflate_add($deflate, $bloque, ZLIB_NO_FLUSH) : $bloque;
            $this->escribir($salida);
            $escritosDatos += strlen($salida);
            $copiados += strlen($bloque);
        }
        fclose($entrada);
        if ($deflate !== null) {
            $salida = deflate_add($deflate, '', ZLIB_FINISH);
            $this->escribir($salida);
            $escritosDatos += strlen($salida);
        }
        if ($copiados !== $tamano) {
            // El archivo cambió mientras se copiaba: se descarta la entrada para no dejarla corrupta
            ftruncate($this->archivo, $inicio);
            fseek($this->archivo, $inicio);
            $this->offset = $inicio;
            throw new RuntimeException("El archivo {$nombre} cambió mientras se copiaba; vuelve a intentarlo.");
        }
        $crcValor = (int) hexdec(hash_final($crc));

        // Corrige el CRC en el encabezado local (posición 14) y, si va comprimido, su tamaño
        fseek($this->archivo, $inicio + 14);
        fwrite($this->archivo, pack('V', $crcValor));
        if ($comprimir) {
            if ($zip64) {
                fseek($this->archivo, $inicio + 30 + strlen($nombre) + 4 + 8);
                fwrite($this->archivo, pack('P', $escritosDatos));
            } else {
                fwrite($this->archivo, pack('V', $escritosDatos));
            }
        }
        fseek($this->archivo, $this->offset);

        $this->centrales[] = [$nombre, $metodo, $crcValor, $escritosDatos, $tamano, $inicio, $hora, $fecha];

        return ['bytes' => $tamano, 'sha256' => hash_final($sha)];
    }

    /**
     * @return array{bytes: int, sha256: string}
     */
    public function agregarTexto(string $nombre, string $contenido, ?int $momento = null): array
    {
        $this->validarNombre($nombre);
        [$hora, $fecha] = self::fechaDos($momento ?? time());
        $comprimido = gzdeflate($contenido, 6);
        $crc = crc32($contenido);
        $original = strlen($contenido);
        $tamano = strlen($comprimido);
        $zip64 = $original >= self::LIMITE_32 || $tamano >= self::LIMITE_32;
        $inicio = $this->offset;
        $this->escribir($this->encabezadoLocal($nombre, 8, $crc, $tamano, $original, $hora, $fecha, $zip64));
        $this->escribir($comprimido);
        $this->centrales[] = [$nombre, 8, $crc, $tamano, $original, $inicio, $hora, $fecha];

        return ['bytes' => $original, 'sha256' => hash('sha256', $contenido)];
    }

    /**
     * Escribe el índice central y el fin del archivo. Después de esto el ZIP
     * queda completo y se puede abrir con cualquier programa.
     */
    public function cerrar(): void
    {
        $inicioIndice = $this->offset;
        foreach ($this->centrales as [$nombre, $metodo, $crc, $comprimido, $original, $desplazamiento, $hora, $fecha]) {
            $extra = '';
            if ($original >= self::LIMITE_32) {
                $extra .= pack('P', $original);
            }
            if ($comprimido >= self::LIMITE_32) {
                $extra .= pack('P', $comprimido);
            }
            if ($desplazamiento >= self::LIMITE_32) {
                $extra .= pack('P', $desplazamiento);
            }
            $extra = $extra === '' ? '' : pack('vv', 0x0001, strlen($extra)).$extra;
            $version = $extra === '' ? 20 : 45;
            $this->escribir(pack('VvvvvvvVVVvvvvvVV',
                0x02014B50, $version, $version, 0x0800, $metodo, $hora, $fecha, $crc,
                min($comprimido, self::LIMITE_32), min($original, self::LIMITE_32),
                strlen($nombre), strlen($extra), 0, 0, 0, 0, min($desplazamiento, self::LIMITE_32)
            ).$nombre.$extra);
        }
        $tamanoIndice = $this->offset - $inicioIndice;
        $total = count($this->centrales);

        if ($total > 0xFFFF || $inicioIndice >= self::LIMITE_32 || $tamanoIndice >= self::LIMITE_32) {
            $inicioZip64 = $this->offset;
            $this->escribir(pack('VPvvVVPPPP', 0x06064B50, 44, 45, 45, 0, 0, $total, $total, $tamanoIndice, $inicioIndice));
            $this->escribir(pack('VVPV', 0x07064B50, 0, $inicioZip64, 1));
        }
        $this->escribir(pack('VvvvvVVv', 0x06054B50, 0, 0, min($total, 0xFFFF), min($total, 0xFFFF),
            min($tamanoIndice, self::LIMITE_32), min($inicioIndice, self::LIMITE_32), 0));
        fflush($this->archivo);
        fclose($this->archivo);
    }

    /** Suelta el archivo sin terminarlo (la siguiente parte lo reabre en el mismo punto). */
    public function soltar(): void
    {
        if (is_resource($this->archivo)) {
            fflush($this->archivo);
            fclose($this->archivo);
        }
    }

    /**
     * Seguridad: solo rutas relativas limpias (sin «..», sin «/» inicial ni
     * diagonales invertidas) para que nadie pueda escribir fuera al extraer.
     */
    public static function nombreSeguro(string $nombre): bool
    {
        return $nombre !== '' && strlen($nombre) <= 1000 && ! str_starts_with($nombre, '/') && ! str_contains($nombre, '\\')
            && ! str_contains($nombre, "\0") && ! preg_match('#(^|/)\.\.?(/|$)#', $nombre) && mb_check_encoding($nombre, 'UTF-8');
    }

    private function validarNombre(string $nombre): void
    {
        if (! self::nombreSeguro($nombre)) {
            throw new RuntimeException('Nombre de archivo no válido dentro del paquete.');
        }
    }

    private function encabezadoLocal(string $nombre, int $metodo, int $crc, int $comprimido, int $original, int $hora, int $fecha, bool $zip64): string
    {
        $extra = $zip64 ? pack('vvPP', 0x0001, 16, $original, $comprimido) : '';

        return pack('VvvvvvVVVvv', 0x04034B50, $zip64 ? 45 : 20, 0x0800, $metodo, $hora, $fecha, $crc,
            $zip64 ? self::LIMITE_32 : $comprimido, $zip64 ? self::LIMITE_32 : $original, strlen($nombre), strlen($extra)).$nombre.$extra;
    }

    private function escribir(string $bytes): void
    {
        $largo = strlen($bytes);
        $escritos = 0;
        while ($escritos < $largo) {
            $n = fwrite($this->archivo, $escritos === 0 ? $bytes : substr($bytes, $escritos));
            if ($n === false || $n === 0) {
                throw new RuntimeException('No se pudo escribir el paquete (¿disco lleno?).');
            }
            $escritos += $n;
        }
        $this->offset += $largo;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private static function fechaDos(int $momento): array
    {
        $f = getdate(max($momento, 315532800)); // no antes de 1980

        return [($f['hours'] << 11) | ($f['minutes'] << 5) | intdiv($f['seconds'], 2),
            (($f['year'] - 1980) << 9) | ($f['mon'] << 5) | $f['mday']];
    }
}
