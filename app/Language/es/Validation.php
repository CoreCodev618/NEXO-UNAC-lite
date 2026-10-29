<?php

// El framework solo trae `en`: aquí van las reglas que usa NEXO (Shield trae su propio `es`).
return [
    'required'    => 'El campo {field} es obligatorio.',
    'min_length'  => 'El campo {field} debe tener al menos {param} caracteres.',
    'max_length'  => 'El campo {field} no puede superar los {param} caracteres.',
    'max_byte'    => 'El campo {field} no puede superar los {param} bytes.',
    'valid_email' => 'El campo {field} debe ser un correo válido.',
    'is_unique'   => 'Ya existe una cuenta con ese {field}.',
    'matches'     => 'El campo {field} no coincide con {param}.',
    'regex_match' => 'El campo {field} no tiene el formato correcto.',
];
