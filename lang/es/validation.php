<?php

/*
|--------------------------------------------------------------------------
| Mensajes de validación en español
|--------------------------------------------------------------------------
|
| Mensajes estándar de las reglas de validación. Las pantallas que necesitan
| un mensaje más claro lo indican en su propia validación (tienen prioridad
| sobre estos). Ver docs/usuario para los mensajes que ve el usuario.
|
*/

return [

    'accepted' => 'Debes aceptar el campo :attribute.',
    'accepted_if' => 'Debes aceptar el campo :attribute cuando :other sea :value.',
    'active_url' => 'El campo :attribute debe ser una dirección web válida.',
    'after' => 'El campo :attribute debe ser una fecha posterior a :date.',
    'after_or_equal' => 'El campo :attribute debe ser una fecha igual o posterior a :date.',
    'alpha' => 'El campo :attribute solo puede tener letras.',
    'alpha_dash' => 'El campo :attribute solo puede tener letras, números, guiones y guiones bajos.',
    'alpha_num' => 'El campo :attribute solo puede tener letras y números.',
    'any_of' => 'El campo :attribute no es válido.',
    'array' => 'El campo :attribute debe ser una lista.',
    'array_keys' => 'El campo :attribute solo puede tener estas claves: :values.',
    'ascii' => 'El campo :attribute solo puede tener letras, números y símbolos sin acentos.',
    'base64' => 'El campo :attribute debe estar codificado en Base64.',
    'before' => 'El campo :attribute debe ser una fecha anterior a :date.',
    'before_or_equal' => 'El campo :attribute debe ser una fecha igual o anterior a :date.',
    'between' => [
        'array' => 'El campo :attribute debe tener entre :min y :max elementos.',
        'file' => 'El archivo :attribute debe pesar entre :min y :max kilobytes.',
        'numeric' => 'El campo :attribute debe estar entre :min y :max.',
        'string' => 'El campo :attribute debe tener entre :min y :max caracteres.',
    ],
    'boolean' => 'El campo :attribute debe ser sí o no.',
    'can' => 'El campo :attribute tiene un valor que no está permitido.',
    'confirmed' => 'La confirmación del campo :attribute no coincide.',
    'contains' => 'Al campo :attribute le falta un valor obligatorio.',
    'current_password' => 'La contraseña es incorrecta.',
    'date' => 'El campo :attribute debe ser una fecha válida.',
    'date_equals' => 'El campo :attribute debe ser una fecha igual a :date.',
    'date_format' => 'El campo :attribute debe tener el formato :format.',
    'decimal' => 'El campo :attribute debe tener :decimal decimales.',
    'declined' => 'Debes rechazar el campo :attribute.',
    'declined_if' => 'Debes rechazar el campo :attribute cuando :other sea :value.',
    'different' => 'Los campos :attribute y :other deben ser diferentes.',
    'digits' => 'El campo :attribute debe tener :digits dígitos.',
    'digits_between' => 'El campo :attribute debe tener entre :min y :max dígitos.',
    'dimensions' => 'La imagen :attribute no tiene las medidas permitidas.',
    'distinct' => 'El campo :attribute tiene un valor repetido.',
    'doesnt_contain' => 'El campo :attribute no puede contener ninguno de estos valores: :values.',
    'doesnt_end_with' => 'El campo :attribute no puede terminar con ninguno de estos valores: :values.',
    'doesnt_start_with' => 'El campo :attribute no puede empezar con ninguno de estos valores: :values.',
    'email' => 'El campo :attribute debe ser un correo electrónico válido.',
    'encoding' => 'El campo :attribute debe estar codificado en :encoding.',
    'ends_with' => 'El campo :attribute debe terminar con uno de estos valores: :values.',
    'enum' => 'El valor elegido en :attribute no es válido.',
    'exists' => 'El valor elegido en :attribute no es válido.',
    'extensions' => 'El archivo :attribute debe tener una de estas extensiones: :values.',
    'file' => 'El campo :attribute debe ser un archivo.',
    'filled' => 'El campo :attribute no puede quedar vacío.',
    'gt' => [
        'array' => 'El campo :attribute debe tener más de :value elementos.',
        'file' => 'El archivo :attribute debe pesar más de :value kilobytes.',
        'numeric' => 'El campo :attribute debe ser mayor que :value.',
        'string' => 'El campo :attribute debe tener más de :value caracteres.',
    ],
    'gte' => [
        'array' => 'El campo :attribute debe tener :value elementos o más.',
        'file' => 'El archivo :attribute debe pesar :value kilobytes o más.',
        'numeric' => 'El campo :attribute debe ser mayor o igual que :value.',
        'string' => 'El campo :attribute debe tener :value caracteres o más.',
    ],
    'hex_color' => 'El campo :attribute debe ser un color hexadecimal válido (por ejemplo #2563eb).',
    'image' => 'El campo :attribute debe ser una imagen.',
    'in' => 'El valor elegido en :attribute no es válido.',
    'in_array' => 'El campo :attribute debe existir en :other.',
    'in_array_keys' => 'El campo :attribute debe tener al menos una de estas claves: :values.',
    'integer' => 'El campo :attribute debe ser un número entero.',
    'ip' => 'El campo :attribute debe ser una dirección IP válida.',
    'ipv4' => 'El campo :attribute debe ser una dirección IPv4 válida.',
    'ipv6' => 'El campo :attribute debe ser una dirección IPv6 válida.',
    'json' => 'El campo :attribute debe ser un texto JSON válido.',
    'list' => 'El campo :attribute debe ser una lista.',
    'lowercase' => 'El campo :attribute debe estar en minúsculas.',
    'lt' => [
        'array' => 'El campo :attribute debe tener menos de :value elementos.',
        'file' => 'El archivo :attribute debe pesar menos de :value kilobytes.',
        'numeric' => 'El campo :attribute debe ser menor que :value.',
        'string' => 'El campo :attribute debe tener menos de :value caracteres.',
    ],
    'lte' => [
        'array' => 'El campo :attribute no puede tener más de :value elementos.',
        'file' => 'El archivo :attribute debe pesar :value kilobytes o menos.',
        'numeric' => 'El campo :attribute debe ser menor o igual que :value.',
        'string' => 'El campo :attribute debe tener :value caracteres o menos.',
    ],
    'mac_address' => 'El campo :attribute debe ser una dirección MAC válida.',
    'max' => [
        'array' => 'El campo :attribute no puede tener más de :max elementos.',
        'file' => 'El archivo :attribute no puede pesar más de :max kilobytes.',
        'numeric' => 'El campo :attribute no puede ser mayor que :max.',
        'string' => 'El campo :attribute no puede tener más de :max caracteres.',
    ],
    'max_digits' => 'El campo :attribute no puede tener más de :max dígitos.',
    'mimes' => 'El archivo :attribute debe ser de tipo: :values.',
    'mimetypes' => 'El archivo :attribute debe ser de tipo: :values.',
    'min' => [
        'array' => 'El campo :attribute debe tener al menos :min elementos.',
        'file' => 'El archivo :attribute debe pesar al menos :min kilobytes.',
        'numeric' => 'El campo :attribute debe ser al menos :min.',
        'string' => 'El campo :attribute debe tener al menos :min caracteres.',
    ],
    'min_digits' => 'El campo :attribute debe tener al menos :min dígitos.',
    'missing' => 'El campo :attribute no debe enviarse.',
    'missing_if' => 'El campo :attribute no debe enviarse cuando :other sea :value.',
    'missing_unless' => 'El campo :attribute no debe enviarse salvo que :other sea :value.',
    'missing_with' => 'El campo :attribute no debe enviarse cuando :values esté presente.',
    'missing_with_all' => 'El campo :attribute no debe enviarse cuando :values estén presentes.',
    'multiple_of' => 'El campo :attribute debe ser múltiplo de :value.',
    'not_in' => 'El valor elegido en :attribute no es válido.',
    'not_regex' => 'El formato del campo :attribute no es válido.',
    'numeric' => 'El campo :attribute debe ser un número.',
    'password' => [
        'letters' => 'El campo :attribute debe tener al menos una letra.',
        'mixed' => 'El campo :attribute debe tener al menos una letra mayúscula y una minúscula.',
        'numbers' => 'El campo :attribute debe tener al menos un número.',
        'symbols' => 'El campo :attribute debe tener al menos un símbolo.',
        'uncompromised' => 'El valor de :attribute apareció en una filtración de datos. Elige otro :attribute.',
    ],
    'present' => 'El campo :attribute debe estar presente.',
    'present_if' => 'El campo :attribute debe estar presente cuando :other sea :value.',
    'present_unless' => 'El campo :attribute debe estar presente salvo que :other sea :value.',
    'present_with' => 'El campo :attribute debe estar presente cuando :values esté presente.',
    'present_with_all' => 'El campo :attribute debe estar presente cuando :values estén presentes.',
    'prohibited' => 'El campo :attribute no está permitido.',
    'prohibited_if' => 'El campo :attribute no está permitido cuando :other sea :value.',
    'prohibited_if_accepted' => 'El campo :attribute no está permitido cuando se acepta :other.',
    'prohibited_if_declined' => 'El campo :attribute no está permitido cuando se rechaza :other.',
    'prohibited_unless' => 'El campo :attribute no está permitido salvo que :other esté en :values.',
    'prohibits' => 'El campo :attribute impide que :other esté presente.',
    'regex' => 'El formato del campo :attribute no es válido.',
    'required' => 'El campo :attribute es obligatorio.',
    'required_array_keys' => 'El campo :attribute debe tener datos para: :values.',
    'required_if' => 'El campo :attribute es obligatorio cuando :other es :value.',
    'required_if_accepted' => 'El campo :attribute es obligatorio cuando se acepta :other.',
    'required_if_declined' => 'El campo :attribute es obligatorio cuando se rechaza :other.',
    'required_unless' => 'El campo :attribute es obligatorio salvo que :other esté en :values.',
    'required_with' => 'El campo :attribute es obligatorio cuando :values está presente.',
    'required_with_all' => 'El campo :attribute es obligatorio cuando :values están presentes.',
    'required_without' => 'El campo :attribute es obligatorio cuando :values no está presente.',
    'required_without_all' => 'El campo :attribute es obligatorio cuando ninguno de :values está presente.',
    'same' => 'Los campos :attribute y :other deben coincidir.',
    'size' => [
        'array' => 'El campo :attribute debe tener :size elementos.',
        'file' => 'El archivo :attribute debe pesar :size kilobytes.',
        'numeric' => 'El campo :attribute debe ser :size.',
        'string' => 'El campo :attribute debe tener :size caracteres.',
    ],
    'starts_with' => 'El campo :attribute debe empezar con uno de estos valores: :values.',
    'string' => 'El campo :attribute debe ser texto.',
    'timezone' => 'El campo :attribute debe ser una zona horaria válida.',
    'unique' => 'Ya existe un registro con ese valor de :attribute.',
    'uploaded' => 'No se pudo subir el archivo :attribute. Inténtalo de nuevo.',
    'uppercase' => 'El campo :attribute debe estar en mayúsculas.',
    'url' => 'El campo :attribute debe ser una dirección web válida.',
    'ulid' => 'El campo :attribute debe ser un ULID válido.',
    'uuid' => 'El campo :attribute debe ser un UUID válido.',

    /*
    |--------------------------------------------------------------------------
    | Mensajes por campo
    |--------------------------------------------------------------------------
    */

    'custom' => [],

    /*
    |--------------------------------------------------------------------------
    | Nombres legibles de los campos
    |--------------------------------------------------------------------------
    |
    | Se usan cuando una pantalla no indica su propio nombre de campo.
    |
    */

    'attributes' => [
        'password' => 'contraseña',
        'password_confirmation' => 'confirmación de la contraseña',
        'current_password' => 'contraseña actual',
        'email' => 'correo',
        'name' => 'nombre',
        'nombre' => 'nombre',
        'username' => 'nombre de usuario',
        'telefono' => 'teléfono',
        'descripcion' => 'descripción',
        'direccion' => 'dirección',
        'fecha' => 'fecha',
        'sede_id' => 'sede',
        'rol_id' => 'rol',
        'departamento_id' => 'departamento',
        'puesto_id' => 'puesto',
        'colaborador_id' => 'colaborador',
        'archivo' => 'archivo',
        'comentario' => 'comentario',
        'motivo' => 'motivo',
    ],

];
