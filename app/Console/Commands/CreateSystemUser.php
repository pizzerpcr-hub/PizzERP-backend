<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class CreateSystemUser extends Command
{
    protected $signature = 'pizzerp:create-user';

    protected $description =
        'Crea un usuario del sistema PizzERP de forma segura';

    public function handle(): int
    {
        $nombreCompleto = trim(
            (string) $this->ask('Nombre completo')
        );

        $nombreUsuario = trim(
            (string) $this->ask('Nombre de usuario')
        );

        $rol = mb_strtoupper(trim(
            (string) $this->ask('Rol', 'ADMINISTRADOR')
        ));

        $password = (string) $this->secret(
            'Contraseña (mínimo 8 caracteres, letras y números)'
        );

        $passwordConfirmation = (string) $this->secret(
            'Confirme la contraseña'
        );

        if ($password !== $passwordConfirmation) {
            $this->error('Las contraseñas no coinciden.');

            return self::FAILURE;
        }

        $validator = Validator::make([
            'nombre_completo' => $nombreCompleto,
            'nombre_usuario' => $nombreUsuario,
            'rol' => $rol,
            'password' => $password,
        ], [
            'nombre_completo' => [
                'required',
                'string',
                'max:100',
            ],
            'nombre_usuario' => [
                'required',
                'string',
                'max:50',
                'unique:usuarios,nombre_usuario',
            ],
            'rol' => [
                'required',
                'string',
                'max:30',
            ],
            'password' => [
                'required',
                'string',
                'min:8',
                'regex:/[A-Za-z]/',
                'regex:/[0-9]/',
            ],
        ], [
            'nombre_completo.required' =>
                'El nombre completo es obligatorio.',
            'nombre_completo.max' =>
                'El nombre completo no puede superar 100 caracteres.',
            'nombre_usuario.required' =>
                'El nombre de usuario es obligatorio.',
            'nombre_usuario.max' =>
                'El nombre de usuario no puede superar 50 caracteres.',
            'nombre_usuario.unique' =>
                'El nombre de usuario ya está registrado.',
            'rol.required' =>
                'El rol es obligatorio.',
            'rol.max' =>
                'El rol no puede superar 30 caracteres.',
            'password.required' =>
                'La contraseña es obligatoria.',
            'password.min' =>
                'La contraseña debe tener al menos 8 caracteres.',
            'password.regex' =>
                'La contraseña debe contener letras y números.',
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = User::create([
            'nombre_completo' => $nombreCompleto,
            'nombre_usuario' => $nombreUsuario,
            'contrasena_hash' => $password,
            'rol' => $rol,
            'estado' => 'ACTIVO',
            'intentos_fallidos' => 0,
            'bloqueado_hasta' => null,
        ]);

        $this->info(
            "Usuario {$user->nombre_usuario} creado correctamente."
        );

        return self::SUCCESS;
    }
}