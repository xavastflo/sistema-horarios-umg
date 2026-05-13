# Sistema de Horarios Universitarios — Instalación Sprint 1

## Requisitos del servidor

| Componente | Versión mínima |
|------------|---------------|
| PHP        | 8.2+          |
| Composer   | 2.x           |
| MySQL      | 8.0+          |
| Node.js    | 18+           |
| Nginx      | 1.18+         |

---

## 1. Clonar el repositorio

```bash
git clone https://github.com/tu-org/horarios-universitarios.git
cd horarios-universitarios
```

---

## 2. Configurar la base de datos MySQL

### 2a. Crear usuario y base de datos (como root)

```bash
mysql -u root -p < backend/database/create_db_user.sql
```

Esto crea:
- Base de datos: `horarios_universitarios`
- Usuario: `horarios_user` con permisos mínimos

> ⚠ **Cambia la contraseña** `CambiarEstaPassword123!` en `create_db_user.sql` antes de ejecutarlo.

### 2b. Opción A — Usar script SQL directo

```bash
mysql -u horarios_user -p horarios_universitarios < backend/database/sprint1_schema.sql
```

### 2b. Opción B — Usar migraciones Laravel (recomendado)

Continúa con los pasos 3 y 4 y ejecuta las migraciones desde Laravel.

---

## 3. Configurar el backend Laravel

```bash
cd backend

# Instalar dependencias PHP
composer install --no-dev --optimize-autoloader

# Copiar variables de entorno
cp .env.example .env

# Editar .env con tus datos reales
nano .env
```

### Variables críticas que debes editar en `.env`

```dotenv
APP_KEY=           # Se genera en el paso siguiente
APP_URL=https://tu-dominio.com

DB_HOST=127.0.0.1
DB_DATABASE=horarios_universitarios
DB_USERNAME=horarios_user
DB_PASSWORD=TU_PASSWORD_REAL

FRONTEND_URL=https://tu-frontend.com

MAX_CURSOS_DOCENTE=6   # Ajustar según decisión institucional
```

```bash
# Generar clave de aplicación
php artisan key:generate

# Publicar configuración de Sanctum
php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider"
```

---

## 4. Ejecutar migraciones y seeders

### Con migraciones Laravel (Opción B)

```bash
# Crear tablas
php artisan migrate

# Poblar datos iniciales (roles, jornadas, días, estados, admin)
php artisan db:seed

# Ver credenciales del admin en la salida del comando anterior
```

### Verificar que el seeder muestra las credenciales

La salida de `php artisan db:seed` mostrará:

```
╔══════════════════════════════════════╗
║     CREDENCIALES INICIALES ADMIN     ║
╠══════════════════════════════════════╣
║  Usuario : admin                     ║
║  Password: Admin@2024!               ║
║  Correo  : admin@universidad.edu     ║
╚══════════════════════════════════════╝
  ⚠ Cambie la contraseña en producción.
```

---

## 5. Probar el backend localmente

```bash
# Servidor de desarrollo
php artisan serve --port=8000
```

### Probar endpoints con curl

#### Login
```bash
curl -X POST http://localhost:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"nombre_usuario": "admin", "password": "Admin@2024!"}'
```

Respuesta esperada:
```json
{
  "token": "1|abc123...",
  "tipo_token": "Bearer",
  "usuario": {
    "id_usuario": 1,
    "nombre_completo": "Administrador Sistema",
    "perfil_activo": "administrador",
    "roles": [{"id_rol": 1, "nombre_rol": "administrador"}]
  }
}
```

#### Usar el token en peticiones protegidas
```bash
TOKEN="1|abc123..."  # Token del login anterior

# Ver mi perfil
curl http://localhost:8000/api/auth/me \
  -H "Authorization: Bearer $TOKEN"

# Listar facultades
curl http://localhost:8000/api/facultades \
  -H "Authorization: Bearer $TOKEN"

# Listar catálogos (sin token)
curl http://localhost:8000/api/catalogos/roles
curl http://localhost:8000/api/catalogos/dias
```

#### Crear una facultad
```bash
curl -X POST http://localhost:8000/api/facultades \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "nombre_facultad": "Facultad de Ingeniería",
    "codigo_facultad": "FING",
    "descripcion": "Facultad de Ingeniería en Sistemas"
  }'
```

#### Crear un usuario y asignarle rol coordinador
```bash
# 1. Crear usuario
curl -X POST http://localhost:8000/api/usuarios \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "nombres": "María",
    "apellidos": "González López",
    "nombre_usuario": "mgonzalez",
    "correo_electronico": "mgonzalez@universidad.edu",
    "password": "Password123!",
    "password_confirmation": "Password123!",
    "pregunta_seguridad": "¿Nombre de tu mascota?",
    "respuesta_seguridad": "fido"
  }'

# 2. Asignar rol coordinador (usa el id_rol del catálogo)
curl -X POST http://localhost:8000/api/usuarios/2/roles \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"id_rol": 2}'
```

#### Recuperar contraseña
```bash
# 1. Ver pregunta de seguridad
curl http://localhost:8000/api/auth/pregunta-seguridad/mgonzalez

# 2. Cambiar contraseña con la respuesta
curl -X POST http://localhost:8000/api/auth/recuperar-password \
  -H "Content-Type: application/json" \
  -d '{
    "nombre_usuario": "mgonzalez",
    "respuesta": "fido",
    "nueva_password": "NuevoPass456!",
    "nueva_password_confirmation": "NuevoPass456!"
  }'
```

---

## 6. Despliegue en VPS Linux (Nginx + PHP-FPM)

### 6.1 Instalar dependencias en el VPS

```bash
# PHP 8.2 y extensiones necesarias
sudo apt update
sudo apt install -y php8.2 php8.2-fpm php8.2-mysql php8.2-mbstring \
    php8.2-xml php8.2-curl php8.2-zip php8.2-bcmath php8.2-tokenizer

# MySQL
sudo apt install -y mysql-server

# Nginx
sudo apt install -y nginx

# Composer
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
```

### 6.2 Configurar Nginx

Crear el archivo `/etc/nginx/sites-available/horarios-backend`:

```nginx
server {
    listen 80;
    server_name api.tu-dominio.com;
    root /var/www/horarios/backend/public;
    index index.php;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";
    add_header X-XSS-Protection "1; mode=block";

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }

    client_max_body_size 20M;
}
```

```bash
# Activar el sitio
sudo ln -s /etc/nginx/sites-available/horarios-backend /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
```

### 6.3 Permisos de carpetas Laravel

```bash
sudo chown -R www-data:www-data /var/www/horarios/backend/storage
sudo chown -R www-data:www-data /var/www/horarios/backend/bootstrap/cache
sudo chmod -R 775 /var/www/horarios/backend/storage
sudo chmod -R 775 /var/www/horarios/backend/bootstrap/cache
```

### 6.4 Optimizar para producción

```bash
cd /var/www/horarios/backend

# Variables de entorno de producción
cp .env.example .env
nano .env   # Editar APP_ENV=production, APP_DEBUG=false, etc.

php artisan key:generate
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan migrate --force
php artisan db:seed --force
```

---

## 7. Endpoints REST — Sprint 1 completo

### Autenticación (públicos)

| Método | Endpoint                                    | Descripción                        |
|--------|---------------------------------------------|------------------------------------|
| POST   | `/api/auth/login`                           | Iniciar sesión                     |
| POST   | `/api/auth/logout`                          | Cerrar sesión                      |
| GET    | `/api/auth/me`                              | Perfil del usuario autenticado     |
| POST   | `/api/auth/cambiar-perfil`                  | Cambiar perfil activo (multi-rol)  |
| GET    | `/api/auth/pregunta-seguridad/{usuario}`    | Obtener pregunta de seguridad      |
| POST   | `/api/auth/recuperar-password`              | Cambiar contraseña con pregunta    |

### Catálogos (públicos)

| Método | Endpoint                          | Descripción                    |
|--------|-----------------------------------|--------------------------------|
| GET    | `/api/catalogos/roles`            | Lista de roles del sistema     |
| GET    | `/api/catalogos/jornadas`         | Lista de jornadas              |
| GET    | `/api/catalogos/dias`             | Lista de días de la semana     |
| GET    | `/api/catalogos/estados-horario`  | Lista de estados de horario    |

### Usuarios (requiere rol: administrador)

| Método | Endpoint                              | Descripción                  |
|--------|---------------------------------------|------------------------------|
| GET    | `/api/usuarios`                       | Listar usuarios              |
| POST   | `/api/usuarios`                       | Crear usuario                |
| GET    | `/api/usuarios/{id}`                  | Ver usuario                  |
| PUT    | `/api/usuarios/{id}`                  | Actualizar usuario           |
| DELETE | `/api/usuarios/{id}`                  | Desactivar usuario           |
| POST   | `/api/usuarios/{id}/roles`            | Asignar rol                  |
| DELETE | `/api/usuarios/{id}/roles/{id_rol}`   | Quitar rol                   |

### Facultades (requiere rol: administrador)

| Método | Endpoint                  | Descripción           |
|--------|---------------------------|-----------------------|
| GET    | `/api/facultades`         | Listar facultades     |
| POST   | `/api/facultades`         | Crear facultad        |
| GET    | `/api/facultades/{id}`    | Ver facultad          |
| PUT    | `/api/facultades/{id}`    | Actualizar facultad   |
| DELETE | `/api/facultades/{id}`    | Desactivar facultad   |

### Carreras (admin crea/edita; admin+coordinador lee)

| Método | Endpoint                                | Descripción                    |
|--------|-----------------------------------------|--------------------------------|
| GET    | `/api/carreras`                         | Listar carreras                |
| POST   | `/api/carreras`                         | Crear carrera                  |
| GET    | `/api/carreras/{id}`                    | Ver carrera                    |
| PUT    | `/api/carreras/{id}`                    | Actualizar carrera             |
| DELETE | `/api/carreras/{id}`                    | Desactivar carrera             |
| POST   | `/api/carreras/{id}/coordinador`        | Asignar coordinador            |
| DELETE | `/api/carreras/{id}/coordinador`        | Desasignar coordinador         |
| POST   | `/api/carreras/{id}/jornadas`           | Asociar jornadas a carrera     |

### Docentes (admin+coordinador gestionan)

| Método | Endpoint                          | Descripción                    |
|--------|-----------------------------------|--------------------------------|
| GET    | `/api/docentes`                   | Listar docentes (por prioridad ASC) |
| POST   | `/api/docentes`                   | Crear perfil docente           |
| GET    | `/api/docentes/{id}`              | Ver docente                    |
| PUT    | `/api/docentes/{id}`              | Actualizar docente             |
| DELETE | `/api/docentes/{id}`              | Desactivar docente             |
| PATCH  | `/api/docentes/{id}/prioridad`    | Cambiar solo la prioridad      |
| GET    | `/api/docentes/mi-perfil`         | Propio perfil (rol: docente)   |

### Historial (requiere rol: administrador)

| Método | Endpoint                        | Descripción                      |
|--------|---------------------------------|----------------------------------|
| GET    | `/api/historial`                | Listar historial con filtros     |
| GET    | `/api/historial/{tabla}/{id}`   | Historial de un registro         |

---

## 8. Credenciales iniciales

```
Usuario:    admin
Password:   Admin@2024!
Correo:     admin@universidad.edu
Rol:        administrador
```

> ⚠ **Cambiar inmediatamente en producción** usando el endpoint de actualización de usuario o directamente en la base de datos.

---

## 9. Seguridad — Checklist para producción

- [ ] Cambiar `APP_DEBUG=false` en `.env`
- [ ] Cambiar `APP_ENV=production` en `.env`
- [ ] Cambiar contraseña del usuario `admin`
- [ ] Cambiar contraseña del usuario MySQL `horarios_user`
- [ ] Configurar HTTPS con Let's Encrypt: `sudo certbot --nginx`
- [ ] Restringir `allowed_origins` en `config/cors.php` al dominio real
- [ ] Configurar firewall: solo puertos 80, 443, 22
- [ ] Deshabilitar acceso externo a MySQL (solo localhost)
- [ ] Ejecutar `php artisan config:cache` y `php artisan route:cache`
- [ ] Configurar backups automáticos de la base de datos

```bash
# Certbot para HTTPS
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d api.tu-dominio.com
```

---

## 10. Estructura de archivos generados — Sprint 1

```
backend/
├── app/
│   ├── Http/
│   │   ├── Controllers/Api/
│   │   │   ├── AuthController.php
│   │   │   ├── UsuarioController.php
│   │   │   ├── FacultadController.php
│   │   │   ├── CarreraController.php
│   │   │   ├── DocenteController.php
│   │   │   ├── CatalogoController.php
│   │   │   └── HistorialController.php
│   │   ├── Middleware/
│   │   │   └── CheckRol.php
│   │   └── Requests/
│   │       ├── Auth/LoginRequest.php
│   │       ├── Auth/RecuperarPasswordRequest.php
│   │       ├── Usuario/StoreUsuarioRequest.php
│   │       ├── Usuario/UpdateUsuarioRequest.php
│   │       ├── Facultad/StoreFacultadRequest.php
│   │       ├── Facultad/UpdateFacultadRequest.php
│   │       ├── Carrera/StoreCarreraRequest.php
│   │       ├── Carrera/UpdateCarreraRequest.php
│   │       ├── Docente/StoreDocenteRequest.php
│   │       └── Docente/UpdateDocenteRequest.php
│   ├── Models/
│   │   ├── Usuario.php
│   │   ├── Rol.php
│   │   ├── UsuarioRol.php
│   │   ├── Facultad.php
│   │   ├── Carrera.php
│   │   ├── Jornada.php
│   │   ├── Dia.php
│   │   ├── EstadoHorario.php
│   │   ├── Docente.php
│   │   └── HistorialCambios.php
│   └── Services/
│       └── HistorialService.php
├── bootstrap/app.php
├── config/
│   ├── academico.php
│   └── cors.php
├── database/
│   ├── migrations/ (11 archivos)
│   ├── seeders/
│   │   ├── DatabaseSeeder.php
│   │   ├── RolSeeder.php
│   │   ├── JornadaSeeder.php
│   │   ├── DiaSeeder.php
│   │   ├── EstadoHorarioSeeder.php
│   │   └── AdminSeeder.php
│   ├── sprint1_schema.sql    ← Script SQL completo alternativo
│   └── create_db_user.sql    ← Crear usuario MySQL
├── routes/api.php
├── composer.json
└── .env.example
```
