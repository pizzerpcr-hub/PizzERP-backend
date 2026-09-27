# PizzERP – Backend

API del sistema PizzERP (Pizzería Mabet), hecha con Laravel 13 y PostgreSQL (Supabase).
El frontend está en el repositorio [PizzERP-frontend](https://github.com/pizzerpcr-hub/PizzERP-frontend).

## Requisitos

| Programa | Versión | Descarga |
| --- | --- | --- |
| Git | cualquiera | https://git-scm.com/download/win |
| PHP | **8.4.1 o superior** | https://windows.php.net/download/ (bloque "x64", archivo Zip) |
| Composer | 2.x | https://getcomposer.org/download/ |

> En Windows no use el instalador de php.new: instala PHP 8.4.0, que no sirve para este proyecto.

### Instalar PHP en Windows

1. Extraiga el Zip de PHP en `C:\php`. El archivo `C:\php\php.exe` debe quedar directamente ahí, no dentro de una subcarpeta.
2. En `C:\php`, copie `php.ini-development` y cambie el nombre de la copia a `php.ini`.
3. Abra `php.ini` y quite el `;` inicial de estas líneas:

   ```ini
   extension_dir = "ext"
   extension=curl
   extension=fileinfo
   extension=intl
   extension=mbstring
   extension=openssl
   extension=pdo_pgsql
   extension=pdo_sqlite
   extension=pgsql
   extension=sqlite3
   extension=zip
   ```

4. Agregue `C:\php` al `Path` de su usuario (en PowerShell):

   ```powershell
   [Environment]::SetEnvironmentVariable("Path", "C:\php;" + [Environment]::GetEnvironmentVariable("Path", "User"), "User")
   ```

5. Cierre y vuelva a abrir PowerShell, y verifique:

   ```powershell
   where.exe php        # la primera línea debe ser C:\php\php.exe
   php -v               # 8.4.1 o superior
   php -r "echo defined('PASSWORD_ARGON2ID') ? 'ok' : 'falta';"   # debe decir ok
   ```

## Instalación

```powershell
git clone -b develop https://github.com/pizzerpcr-hub/PizzERP-backend.git
cd PizzERP-backend
composer install
copy .env.example .env
php artisan key:generate
```

Abra `.env` y escriba en `DB_PASSWORD` la contraseña de Supabase; pídasela al equipo.
Ese archivo no se sube a GitHub.

> **No ejecute `php artisan migrate:fresh` ni `migrate:refresh` contra Supabase:** borran los
> datos de todo el equipo. `php artisan migrate` solo hace falta cuando alguien agrega una
> migración nueva.

Para confirmar la conexión con la base de datos:

```powershell
php artisan db:show
```

## Ejecutar

```powershell
php artisan serve
```

La API queda en http://localhost:8000. Luego encienda el frontend (vea su README) y abra
http://localhost:5173.

### Crear un usuario desde la consola

```powershell
php artisan pizzerp:create-user
```

Recuerde que el usuario queda en la base compartida, así que use datos reales, no datos de prueba.

## Pruebas y formato

```powershell
php artisan test        # pruebas automáticas (usan SQLite en memoria, no tocan Supabase)
vendor/bin/pint --test  # revisa el formato del código
vendor/bin/pint         # corrige el formato
```

## Traer los cambios del equipo

```powershell
git pull origin develop --no-rebase
composer install
```

## Ramas

- `main`: versión estable.
- `develop`: integración del sprint.
- `feature/...` y `bugfix/...`: trabajo de cada integrante (estándar EP10).
