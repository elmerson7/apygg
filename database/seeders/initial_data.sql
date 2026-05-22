-- ============================================
-- Datos Iniciales para Pruebas
-- Usuarios, Roles, Permisos y Relaciones
-- ============================================
--
-- Password por defecto para todos los usuarios: "1234"
-- Generar hash con: php -r "echo password_hash('1234', PASSWORD_ARGON2ID);"
--
-- ============================================

-- ============================================
-- ROLES
-- ============================================

INSERT INTO roles (name, display_name, description, created_at, updated_at) VALUES
('gerente-general', 'Gerente General', 'Rol con todos los permisos del sistema', NOW(), NOW()),
('ejecutivo-cuentas', 'Ejecutivo de Cuentas', 'Rol con todos los permisos del sistema', NOW(), NOW()),
('soporte-tecnico', 'Soporte Técnico', 'Rol con todos los permisos del sistema', NOW(), NOW()),
('transportista', 'Transportista', 'Rol con todos los permisos del sistema', NOW(), NOW());

-- ============================================
-- PERMISOS
-- ============================================

INSERT INTO permissions (name, display_name, resource, action, description, created_at, updated_at) VALUES
('users.create', 'Crear Usuarios', 'users', 'create', 'Permite crear nuevos usuarios', NOW(), NOW()),
('users.read', 'Ver Usuarios', 'users', 'read', 'Permite ver listado y detalles de usuarios', NOW(), NOW()),
('users.update', 'Actualizar Usuarios', 'users', 'update', 'Permite actualizar información de usuarios', NOW(), NOW()),
('users.delete', 'Eliminar Usuarios', 'users', 'delete', 'Permite eliminar usuarios', NOW(), NOW()),
('roles.create', 'Crear Roles', 'roles', 'create', 'Permite crear nuevos roles', NOW(), NOW()),
('roles.read', 'Ver Roles', 'roles', 'read', 'Permite ver listado y detalles de roles', NOW(), NOW()),
('roles.update', 'Actualizar Roles', 'roles', 'update', 'Permite actualizar información de roles', NOW(), NOW()),
('roles.delete', 'Eliminar Roles', 'roles', 'delete', 'Permite eliminar roles', NOW(), NOW()),
('permissions.create', 'Crear Permisos', 'permissions', 'create', 'Permite crear nuevos permisos', NOW(), NOW()),
('permissions.read', 'Ver Permisos', 'permissions', 'read', 'Permite ver listado y detalles de permisos', NOW(), NOW()),
('permissions.update', 'Actualizar Permisos', 'permissions', 'update', 'Permite actualizar información de permisos', NOW(), NOW()),
('permissions.delete', 'Eliminar Permisos', 'permissions', 'delete', 'Permite eliminar permisos', NOW(), NOW()),
('viajes.create', 'Crear Viajes', 'viajes', 'create', 'Permite crear viajes', NOW(), NOW()),
('viajes.read', 'Ver Viajes', 'viajes', 'read', 'Permite ver viajes', NOW(), NOW()),
('viajes.update', 'Actualizar Viajes', 'viajes', 'update', 'Permite actualizar viajes', NOW(), NOW()),
('viajes.delete', 'Eliminar Viajes', 'viajes', 'delete', 'Permite eliminar viajes', NOW(), NOW()),
('clientes.create', 'Crear Clientes', 'clientes', 'create', 'Permite crear clientes', NOW(), NOW()),
('clientes.read', 'Ver Clientes', 'clientes', 'read', 'Permite ver clientes', NOW(), NOW()),
('clientes.update', 'Actualizar Clientes', 'clientes', 'update', 'Permite actualizar clientes', NOW(), NOW()),
('clientes.delete', 'Eliminar Clientes', 'clientes', 'delete', 'Permite eliminar clientes', NOW(), NOW()),
('reportes.read', 'Ver Reportes', 'reportes', 'read', 'Permite ver reportes', NOW(), NOW()),
('reportes.export', 'Exportar Reportes', 'reportes', 'export', 'Permite exportar reportes', NOW(), NOW()),
('configuracion.read', 'Ver Configuración', 'configuracion', 'read', 'Permite ver configuración del sistema', NOW(), NOW()),
('configuracion.update', 'Actualizar Configuración', 'configuracion', 'update', 'Permite actualizar configuración del sistema', NOW(), NOW());

-- ============================================
-- USUARIOS
-- ============================================
-- Password por defecto: "1234" (hash argon2id)

INSERT INTO users (name, email, username, identity_document, email_verified_at, password, created_at, updated_at) VALUES
('Elmer Merino', 'elmer@apygg.com', 'elmerson', '123456789', NOW(), '$argon2id$v=19$m=65536,t=4,p=1$U0NJa1FRVWYwcGQ5NTVISA$8xIYTl6fb0ZEOk0uNcf4fJRmgAXxNQBEXDhaCC3+QTs', NOW(), NOW()),
('Angel Silva', 'angel@apygg.com', 'angelillo', '234567890', NOW(), '$argon2id$v=19$m=65536,t=4,p=1$U0NJa1FRVWYwcGQ5NTVISA$8xIYTl6fb0ZEOk0uNcf4fJRmgAXxNQBEXDhaCC3+QTs', NOW(), NOW()),
('Carlos Mendoza', 'carlos@apygg.com', 'cmendoza', '345678901', NOW(), '$argon2id$v=19$m=65536,t=4,p=1$U0NJa1FRVWYwcGQ5NTVISA$8xIYTl6fb0ZEOk0uNcf4fJRmgAXxNQBEXDhaCC3+QTs', NOW(), NOW()),
('Lucia Fernandez', 'lucia@apygg.com', 'lfernandez', '456789012', NOW(), '$argon2id$v=19$m=65536,t=4,p=1$U0NJa1FRVWYwcGQ5NTVISA$8xIYTl6fb0ZEOk0uNcf4fJRmgAXxNQBEXDhaCC3+QTs', NOW(), NOW()),
('Pedro Ramirez', 'pedro@apygg.com', 'pramirez', '567890123', NOW(), '$argon2id$v=19$m=65536,t=4,p=1$U0NJa1FRVWYwcGQ5NTVISA$8xIYTl6fb0ZEOk0uNcf4fJRmgAXxNQBEXDhaCC3+QTs', NOW(), NOW()),
('Maria Torres', 'maria@apygg.com', 'mtorres', '678901234', NOW(), '$argon2id$v=19$m=65536,t=4,p=1$U0NJa1FRVWYwcGQ5NTVISA$8xIYTl6fb0ZEOk0uNcf4fJRmgAXxNQBEXDhaCC3+QTs', NOW(), NOW()),
('Jose Garcia', 'jose@apygg.com', 'jgarcia', '789012345', NOW(), '$argon2id$v=19$m=65536,t=4,p=1$U0NJa1FRVWYwcGQ5NTVISA$8xIYTl6fb0ZEOk0uNcf4fJRmgAXxNQBEXDhaCC3+QTs', NOW(), NOW()),
('Ana Martinez', 'ana@apygg.com', 'amartinez', '890123456', NOW(), '$argon2id$v=19$m=65536,t=4,p=1$U0NJa1FRVWYwcGQ5NTVISA$8xIYTl6fb0ZEOk0uNcf4fJRmgAXxNQBEXDhaCC3+QTs', NOW(), NOW());

-- ============================================
-- PERFILES DE USUARIO
-- ============================================

INSERT INTO user_profiles (user_id, first_name, last_name, created_at, updated_at)
SELECT u.id, 'Elmer', 'Merino', NOW(), NOW() FROM users u WHERE u.username = 'elmerson'
UNION ALL
SELECT u.id, 'Angel', 'Silva', NOW(), NOW() FROM users u WHERE u.username = 'angelillo'
UNION ALL
SELECT u.id, 'Carlos', 'Mendoza', NOW(), NOW() FROM users u WHERE u.username = 'cmendoza'
UNION ALL
SELECT u.id, 'Lucia', 'Fernandez', NOW(), NOW() FROM users u WHERE u.username = 'lfernandez'
UNION ALL
SELECT u.id, 'Pedro', 'Ramirez', NOW(), NOW() FROM users u WHERE u.username = 'pramirez'
UNION ALL
SELECT u.id, 'Maria', 'Torres', NOW(), NOW() FROM users u WHERE u.username = 'mtorres'
UNION ALL
SELECT u.id, 'Jose', 'Garcia', NOW(), NOW() FROM users u WHERE u.username = 'jgarcia'
UNION ALL
SELECT u.id, 'Ana', 'Martinez', NOW(), NOW() FROM users u WHERE u.username = 'amartinez';

-- ============================================
-- RELACIONES USUARIO-ROL
-- ============================================

INSERT INTO user_role (user_id, role_id, created_at, updated_at)
SELECT u.id, r.id, NOW(), NOW()
FROM users u, roles r
WHERE (u.username = 'elmerson' AND r.name = 'gerente-general')
   OR (u.username = 'angelillo' AND r.name = 'gerente-general')
   OR (u.username = 'cmendoza' AND r.name = 'ejecutivo-cuentas')
   OR (u.username = 'lfernandez' AND r.name = 'ejecutivo-cuentas')
   OR (u.username = 'pramirez' AND r.name = 'soporte-tecnico')
   OR (u.username = 'mtorres' AND r.name = 'soporte-tecnico')
   OR (u.username = 'jgarcia' AND r.name = 'transportista')
   OR (u.username = 'amartinez' AND r.name = 'transportista');

-- ============================================
-- RELACIONES ROL-PERMISO (todos los roles tienen todos los permisos)
-- ============================================

INSERT INTO role_permission (role_id, permission_id, created_at, updated_at)
SELECT r.id, p.id, NOW(), NOW()
FROM roles r, permissions p;

-- ============================================
-- RESUMEN
-- ============================================
--
-- Roles: 4 (gerente-general, ejecutivo-cuentas, soporte-tecnico, transportista)
-- Permisos: 24
-- Usuarios: 8
--
-- ============================================
