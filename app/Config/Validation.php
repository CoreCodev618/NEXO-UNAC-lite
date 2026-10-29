<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;
use App\Libraries\InstitucionalValidator;
use CodeIgniter\Validation\StrictRules\CreditCardRules;
use CodeIgniter\Validation\StrictRules\FileRules;
use CodeIgniter\Validation\StrictRules\FormatRules;
use CodeIgniter\Validation\StrictRules\Rules;

class Validation extends BaseConfig
{
    // --------------------------------------------------------------------
    // Setup
    // --------------------------------------------------------------------

    /**
     * Stores the classes that contain the
     * rules that are available.
     *
     * @var list<string>
     */
    public array $ruleSets = [
        Rules::class,
        FormatRules::class,
        FileRules::class,
        CreditCardRules::class,
        InstitucionalValidator::class,
    ];

    /**
     * Specifies the views that are used to display the
     * errors.
     *
     * @var array<string, string>
     */
    public array $templates = [
        'list'   => 'CodeIgniter\Validation\Views\list',
        'single' => 'CodeIgniter\Validation\Views\single',
    ];

    // --------------------------------------------------------------------
    // Rules
    // --------------------------------------------------------------------

    /**
     * Registro Shield: reglas por defecto + `name` (solo los campos con regla se guardan).
     */
    public array $registration = [
        'name' => [
            'label' => 'Nombre',
            'rules' => ['required', 'max_length[150]'],
        ],
        'email' => [
            'label'  => 'Correo institucional',
            'rules'  => ['required', 'max_length[254]', 'valid_email', 'institutional_email', 'is_unique[auth_identities.secret]'],
            'errors' => ['is_unique' => 'Ya existe una cuenta con ese correo.'],
        ],
        'password' => [
            'label' => 'Auth.password',
            'rules' => ['required', 'max_byte[72]', 'strong_password[]'],
        ],
        'password_confirm' => [
            'label'  => 'Confirma la contraseña',
            'rules'  => 'required|matches[password]',
            'errors' => ['matches' => 'Las contraseñas no coinciden.'],
        ],
    ];
}
