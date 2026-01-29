-- ============================================
-- Datos Iniciales para Pruebas
-- Usuarios, Roles, Permisos y Relaciones
-- ============================================
-- 
-- Este archivo contiene datos de prueba para:
-- - Usuarios (varios con diferentes roles)
-- - Roles (Admin, Manager, User, Guest)
-- - Permisos (CRUD para diferentes recursos)
-- - Relaciones usuario-rol
-- - Relaciones rol-permiso
-- - Relaciones usuario-permiso
--
-- Password por defecto para todos los usuarios: "password"
-- Hash argon2id: $argon2id$v=19$m=65536,t=5,p=3$Ym1WVWgvc2JMbS54VWxMcg$trHOeKRjtuZCBISms5F7qXge/8MfHrwP5w90Pih0ud8
--
-- Ejecutar con:
-- psql -U apygg -d apygg -f database/seeders/initial_data.sql
-- O desde Docker:
-- docker compose exec postgres psql -U apygg -d apygg -f /app/database/seeders/initial_data.sql
-- ============================================

-- Limpiar datos existentes (opcional, comentar si no quieres borrar)
-- TRUNCATE TABLE user_permission CASCADE;
-- TRUNCATE TABLE user_role CASCADE;
-- TRUNCATE TABLE role_permission CASCADE;
-- TRUNCATE TABLE permissions CASCADE;
-- TRUNCATE TABLE roles CASCADE;
-- TRUNCATE TABLE users CASCADE;

-- ============================================
-- ROLES
-- ============================================

INSERT INTO roles (id, name, display_name, description, created_at, updated_at) VALUES
('00000000-0000-0000-0000-000000000001', 'admin', 'Administrador', 'Rol con todos los permisos del sistema', NOW(), NOW()),
('00000000-0000-0000-0000-000000000002', 'manager', 'Gerente', 'Rol con permisos de gestión y supervisión', NOW(), NOW()),
('00000000-0000-0000-0000-000000000003', 'user', 'Usuario', 'Rol básico de usuario con permisos limitados', NOW(), NOW()),
('00000000-0000-0000-0000-000000000004', 'guest', 'Invitado', 'Rol de solo lectura sin permisos de escritura', NOW(), NOW()),
('00000000-0000-0000-0000-000000000005', 'moderator', 'Moderador', 'Rol con permisos de moderación de contenido', NOW(), NOW()),
('00000000-0000-0000-0000-000000000006', 'editor', 'Editor', 'Rol con permisos de edición de contenido', NOW(), NOW());

-- ============================================
-- PERMISOS
-- ============================================

-- Permisos de Usuarios
INSERT INTO permissions (id, name, display_name, resource, action, description, created_at, updated_at) VALUES
('10000000-0000-0000-0000-000000000001', 'users.create', 'Crear Usuarios', 'users', 'create', 'Permite crear nuevos usuarios', NOW(), NOW()),
('10000000-0000-0000-0000-000000000002', 'users.read', 'Ver Usuarios', 'users', 'read', 'Permite ver listado y detalles de usuarios', NOW(), NOW()),
('10000000-0000-0000-0000-000000000003', 'users.update', 'Actualizar Usuarios', 'users', 'update', 'Permite actualizar información de usuarios', NOW(), NOW()),
('10000000-0000-0000-0000-000000000004', 'users.delete', 'Eliminar Usuarios', 'users', 'delete', 'Permite eliminar usuarios', NOW(), NOW()),
('10000000-0000-0000-0000-000000000005', 'users.manage-roles', 'Gestionar Roles de Usuarios', 'users', 'manage-roles', 'Permite asignar y quitar roles a usuarios', NOW(), NOW()),

-- Permisos de Roles
('20000000-0000-0000-0000-000000000001', 'roles.create', 'Crear Roles', 'roles', 'create', 'Permite crear nuevos roles', NOW(), NOW()),
('20000000-0000-0000-0000-000000000002', 'roles.read', 'Ver Roles', 'roles', 'read', 'Permite ver listado y detalles de roles', NOW(), NOW()),
('20000000-0000-0000-0000-000000000003', 'roles.update', 'Actualizar Roles', 'roles', 'update', 'Permite actualizar información de roles', NOW(), NOW()),
('20000000-0000-0000-0000-000000000004', 'roles.delete', 'Eliminar Roles', 'roles', 'delete', 'Permite eliminar roles', NOW(), NOW()),
('20000000-0000-0000-0000-000000000005', 'roles.manage-permissions', 'Gestionar Permisos de Roles', 'roles', 'manage-permissions', 'Permite asignar y quitar permisos a roles', NOW(), NOW()),

-- Permisos de Permisos
('30000000-0000-0000-0000-000000000001', 'permissions.create', 'Crear Permisos', 'permissions', 'create', 'Permite crear nuevos permisos', NOW(), NOW()),
('30000000-0000-0000-0000-000000000002', 'permissions.read', 'Ver Permisos', 'permissions', 'read', 'Permite ver listado y detalles de permisos', NOW(), NOW()),
('30000000-0000-0000-0000-000000000003', 'permissions.update', 'Actualizar Permisos', 'permissions', 'update', 'Permite actualizar información de permisos', NOW(), NOW()),
('30000000-0000-0000-0000-000000000004', 'permissions.delete', 'Eliminar Permisos', 'permissions', 'delete', 'Permite eliminar permisos', NOW(), NOW()),

-- Permisos de Posts/Contenido
('40000000-0000-0000-0000-000000000001', 'posts.create', 'Crear Posts', 'posts', 'create', 'Permite crear nuevos posts', NOW(), NOW()),
('40000000-0000-0000-0000-000000000002', 'posts.read', 'Ver Posts', 'posts', 'read', 'Permite ver listado y detalles de posts', NOW(), NOW()),
('40000000-0000-0000-0000-000000000003', 'posts.update', 'Actualizar Posts', 'posts', 'update', 'Permite actualizar posts propios', NOW(), NOW()),
('40000000-0000-0000-0000-000000000004', 'posts.update-any', 'Actualizar Cualquier Post', 'posts', 'update-any', 'Permite actualizar cualquier post', NOW(), NOW()),
('40000000-0000-0000-0000-000000000005', 'posts.delete', 'Eliminar Posts', 'posts', 'delete', 'Permite eliminar posts propios', NOW(), NOW()),
('40000000-0000-0000-0000-000000000006', 'posts.delete-any', 'Eliminar Cualquier Post', 'posts', 'delete-any', 'Permite eliminar cualquier post', NOW(), NOW()),
('40000000-0000-0000-0000-000000000007', 'posts.moderate', 'Moderar Posts', 'posts', 'moderate', 'Permite moderar y aprobar posts', NOW(), NOW()),

-- Permisos de Comentarios
('50000000-0000-0000-0000-000000000001', 'comments.create', 'Crear Comentarios', 'comments', 'create', 'Permite crear comentarios', NOW(), NOW()),
('50000000-0000-0000-0000-000000000002', 'comments.read', 'Ver Comentarios', 'comments', 'read', 'Permite ver comentarios', NOW(), NOW()),
('50000000-0000-0000-0000-000000000003', 'comments.update', 'Actualizar Comentarios', 'comments', 'update', 'Permite actualizar comentarios propios', NOW(), NOW()),
('50000000-0000-0000-0000-000000000004', 'comments.delete', 'Eliminar Comentarios', 'comments', 'delete', 'Permite eliminar comentarios propios', NOW(), NOW()),
('50000000-0000-0000-0000-000000000005', 'comments.moderate', 'Moderar Comentarios', 'comments', 'moderate', 'Permite moderar comentarios', NOW(), NOW()),

-- Permisos de Sistema
('60000000-0000-0000-0000-000000000001', 'system.settings', 'Gestionar Configuración', 'system', 'settings', 'Permite modificar configuración del sistema', NOW(), NOW()),
('60000000-0000-0000-0000-000000000002', 'system.logs', 'Ver Logs', 'system', 'logs', 'Permite ver logs del sistema', NOW(), NOW()),
('60000000-0000-0000-0000-000000000003', 'system.backup', 'Gestionar Backups', 'system', 'backup', 'Permite crear y restaurar backups', NOW(), NOW()),
('60000000-0000-0000-0000-000000000004', 'system.users', 'Gestionar Usuarios del Sistema', 'system', 'users', 'Permite gestionar usuarios del sistema', NOW(), NOW());

-- ============================================
-- USUARIOS
-- ============================================
-- Password por defecto: "password" (hash argon2id: $argon2id$v=19$m=65536,t=5,p=3$Ym1WVWgvc2JMbS54VWxMcg$trHOeKRjtuZCBISms5F7qXge/8MfHrwP5w90Pih0ud8)

INSERT INTO users (id, name, email, email_verified_at, password, identity_document, remember_token, created_at, updated_at) VALUES
('a0000000-0000-0000-0000-000000000001', 'Admin User', 'admin@apygg.com', NOW(), '$argon2id$v=19$m=65536,t=5,p=3$Ym1WVWgvc2JMbS54VWxMcg$trHOeKRjtuZCBISms5F7qXge/8MfHrwP5w90Pih0ud8', '12345678', NULL, NOW(), NOW()),
('a0000000-0000-0000-0000-000000000002', 'Manager User', 'manager@apygg.com', NOW(), '$argon2id$v=19$m=65536,t=5,p=3$Ym1WVWgvc2JMbS54VWxMcg$trHOeKRjtuZCBISms5F7qXge/8MfHrwP5w90Pih0ud8', '23456789', NULL, NOW(), NOW()),
('a0000000-0000-0000-0000-000000000003', 'John Doe', 'john.doe@example.com', NOW(), '$argon2id$v=19$m=65536,t=5,p=3$Ym1WVWgvc2JMbS54VWxMcg$trHOeKRjtuZCBISms5F7qXge/8MfHrwP5w90Pih0ud8', '34567890', NULL, NOW(), NOW()),
('a0000000-0000-0000-0000-000000000004', 'Jane Smith', 'jane.smith@example.com', NOW(), '$argon2id$v=19$m=65536,t=5,p=3$Ym1WVWgvc2JMbS54VWxMcg$trHOeKRjtuZCBISms5F7qXge/8MfHrwP5w90Pih0ud8', '45678901', NULL, NOW(), NOW()),
('a0000000-0000-0000-0000-000000000005', 'Bob Johnson', 'bob.johnson@example.com', NOW(), '$argon2id$v=19$m=65536,t=5,p=3$Ym1WVWgvc2JMbS54VWxMcg$trHOeKRjtuZCBISms5F7qXge/8MfHrwP5w90Pih0ud8', '56789012', NULL, NOW(), NOW()),
('a0000000-0000-0000-0000-000000000006', 'Alice Williams', 'alice.williams@example.com', NOW(), '$argon2id$v=19$m=65536,t=5,p=3$Ym1WVWgvc2JMbS54VWxMcg$trHOeKRjtuZCBISms5F7qXge/8MfHrwP5w90Pih0ud8', '67890123', NULL, NOW(), NOW()),
('a0000000-0000-0000-0000-000000000007', 'Charlie Brown', 'charlie.brown@example.com', NOW(), '$argon2id$v=19$m=65536,t=5,p=3$Ym1WVWgvc2JMbS54VWxMcg$trHOeKRjtuZCBISms5F7qXge/8MfHrwP5w90Pih0ud8', '78901234', NULL, NOW(), NOW()),
('a0000000-0000-0000-0000-000000000008', 'Diana Prince', 'diana.prince@example.com', NOW(), '$argon2id$v=19$m=65536,t=5,p=3$Ym1WVWgvc2JMbS54VWxMcg$trHOeKRjtuZCBISms5F7qXge/8MfHrwP5w90Pih0ud8', '89012345', NULL, NOW(), NOW()),
('a0000000-0000-0000-0000-000000000009', 'Moderator User', 'moderator@apygg.com', NOW(), '$argon2id$v=19$m=65536,t=5,p=3$Ym1WVWgvc2JMbS54VWxMcg$trHOeKRjtuZCBISms5F7qXge/8MfHrwP5w90Pih0ud8', '90123456', NULL, NOW(), NOW()),
('a0000000-0000-0000-0000-000000000010', 'Editor User', 'editor@apygg.com', NOW(), '$argon2id$v=19$m=65536,t=5,p=3$Ym1WVWgvc2JMbS54VWxMcg$trHOeKRjtuZCBISms5F7qXge/8MfHrwP5w90Pih0ud8', '01234567', NULL, NOW(), NOW()),
('a0000000-0000-0000-0000-000000000011', 'Guest User', 'guest@apygg.com', NULL, '$argon2id$v=19$m=65536,t=5,p=3$Ym1WVWgvc2JMbS54VWxMcg$trHOeKRjtuZCBISms5F7qXge/8MfHrwP5w90Pih0ud8', '11223344', NULL, NOW(), NOW()),
('a0000000-0000-0000-0000-000000000012', 'Test User', 'test@apygg.com', NOW(), '$argon2id$v=19$m=65536,t=5,p=3$Ym1WVWgvc2JMbS54VWxMcg$trHOeKRjtuZCBISms5F7qXge/8MfHrwP5w90Pih0ud8', '22334455', NULL, NOW(), NOW());

-- ============================================
-- RELACIONES USUARIO-ROL
-- ============================================

INSERT INTO user_role (user_id, role_id, created_at, updated_at) VALUES
('a0000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000001', NOW(), NOW()),
('a0000000-0000-0000-0000-000000000002', '00000000-0000-0000-0000-000000000002', NOW(), NOW()),
('a0000000-0000-0000-0000-000000000003', '00000000-0000-0000-0000-000000000003', NOW(), NOW()),
('a0000000-0000-0000-0000-000000000004', '00000000-0000-0000-0000-000000000003', NOW(), NOW()),
('a0000000-0000-0000-0000-000000000005', '00000000-0000-0000-0000-000000000003', NOW(), NOW()),
('a0000000-0000-0000-0000-000000000006', '00000000-0000-0000-0000-000000000003', NOW(), NOW()),
('a0000000-0000-0000-0000-000000000007', '00000000-0000-0000-0000-000000000003', NOW(), NOW()),
('a0000000-0000-0000-0000-000000000008', '00000000-0000-0000-0000-000000000003', NOW(), NOW()),
('a0000000-0000-0000-0000-000000000009', '00000000-0000-0000-0000-000000000005', NOW(), NOW()),
('a0000000-0000-0000-0000-000000000010', '00000000-0000-0000-0000-000000000006', NOW(), NOW()),
('a0000000-0000-0000-0000-000000000011', '00000000-0000-0000-0000-000000000004', NOW(), NOW()),
('a0000000-0000-0000-0000-000000000012', '00000000-0000-0000-0000-000000000003', NOW(), NOW()),
('a0000000-0000-0000-0000-000000000003', '00000000-0000-0000-0000-000000000006', NOW(), NOW()),
('a0000000-0000-0000-0000-000000000004', '00000000-0000-0000-0000-000000000005', NOW(), NOW());

-- ============================================
-- RELACIONES ROL-PERMISO
-- ============================================

-- Admin tiene TODOS los permisos
INSERT INTO role_permission (role_id, permission_id, created_at, updated_at)
SELECT 
    '00000000-0000-0000-0000-000000000001'::uuid,
    id,
    NOW(),
    NOW()
FROM permissions;

-- Manager tiene permisos de gestión (sin sistema)
INSERT INTO role_permission (role_id, permission_id, created_at, updated_at) VALUES
('00000000-0000-0000-0000-000000000002', '10000000-0000-0000-0000-000000000001', NOW(), NOW()),
('00000000-0000-0000-0000-000000000002', '10000000-0000-0000-0000-000000000002', NOW(), NOW()),
('00000000-0000-0000-0000-000000000002', '10000000-0000-0000-0000-000000000003', NOW(), NOW()),
('00000000-0000-0000-0000-000000000002', '10000000-0000-0000-0000-000000000005', NOW(), NOW()),
('00000000-0000-0000-0000-000000000002', '20000000-0000-0000-0000-000000000001', NOW(), NOW()),
('00000000-0000-0000-0000-000000000002', '20000000-0000-0000-0000-000000000002', NOW(), NOW()),
('00000000-0000-0000-0000-000000000002', '20000000-0000-0000-0000-000000000003', NOW(), NOW()),
('00000000-0000-0000-0000-000000000002', '40000000-0000-0000-0000-000000000001', NOW(), NOW()),
('00000000-0000-0000-0000-000000000002', '40000000-0000-0000-0000-000000000002', NOW(), NOW()),
('00000000-0000-0000-0000-000000000002', '40000000-0000-0000-0000-000000000004', NOW(), NOW()),
('00000000-0000-0000-0000-000000000002', '40000000-0000-0000-0000-000000000006', NOW(), NOW()),
('00000000-0000-0000-0000-000000000002', '50000000-0000-0000-0000-000000000001', NOW(), NOW()),
('00000000-0000-0000-0000-000000000002', '50000000-0000-0000-0000-000000000002', NOW(), NOW()),
('00000000-0000-0000-0000-000000000002', '50000000-0000-0000-0000-000000000005', NOW(), NOW());

-- User tiene permisos básicos
INSERT INTO role_permission (role_id, permission_id, created_at, updated_at) VALUES
('00000000-0000-0000-0000-000000000003', '10000000-0000-0000-0000-000000000002', NOW(), NOW()),
('00000000-0000-0000-0000-000000000003', '20000000-0000-0000-0000-000000000002', NOW(), NOW()),
('00000000-0000-0000-0000-000000000003', '30000000-0000-0000-0000-000000000002', NOW(), NOW()),
('00000000-0000-0000-0000-000000000003', '40000000-0000-0000-0000-000000000001', NOW(), NOW()),
('00000000-0000-0000-0000-000000000003', '40000000-0000-0000-0000-000000000002', NOW(), NOW()),
('00000000-0000-0000-0000-000000000003', '40000000-0000-0000-0000-000000000003', NOW(), NOW()),
('00000000-0000-0000-0000-000000000003', '40000000-0000-0000-0000-000000000005', NOW(), NOW()),
('00000000-0000-0000-0000-000000000003', '50000000-0000-0000-0000-000000000001', NOW(), NOW()),
('00000000-0000-0000-0000-000000000003', '50000000-0000-0000-0000-000000000002', NOW(), NOW()),
('00000000-0000-0000-0000-000000000003', '50000000-0000-0000-0000-000000000003', NOW(), NOW()),
('00000000-0000-0000-0000-000000000003', '50000000-0000-0000-0000-000000000004', NOW(), NOW());

-- Guest solo lectura
INSERT INTO role_permission (role_id, permission_id, created_at, updated_at) VALUES
('00000000-0000-0000-0000-000000000004', '10000000-0000-0000-0000-000000000002', NOW(), NOW()),
('00000000-0000-0000-0000-000000000004', '20000000-0000-0000-0000-000000000002', NOW(), NOW()),
('00000000-0000-0000-0000-000000000004', '30000000-0000-0000-0000-000000000002', NOW(), NOW()),
('00000000-0000-0000-0000-000000000004', '40000000-0000-0000-0000-000000000002', NOW(), NOW()),
('00000000-0000-0000-0000-000000000004', '50000000-0000-0000-0000-000000000002', NOW(), NOW());

-- Moderator tiene permisos de moderación
INSERT INTO role_permission (role_id, permission_id, created_at, updated_at) VALUES
('00000000-0000-0000-0000-000000000005', '10000000-0000-0000-0000-000000000002', NOW(), NOW()),
('00000000-0000-0000-0000-000000000005', '40000000-0000-0000-0000-000000000001', NOW(), NOW()),
('00000000-0000-0000-0000-000000000005', '40000000-0000-0000-0000-000000000002', NOW(), NOW()),
('00000000-0000-0000-0000-000000000005', '40000000-0000-0000-0000-000000000004', NOW(), NOW()),
('00000000-0000-0000-0000-000000000005', '40000000-0000-0000-0000-000000000006', NOW(), NOW()),
('00000000-0000-0000-0000-000000000005', '40000000-0000-0000-0000-000000000007', NOW(), NOW()),
('00000000-0000-0000-0000-000000000005', '50000000-0000-0000-0000-000000000001', NOW(), NOW()),
('00000000-0000-0000-0000-000000000005', '50000000-0000-0000-0000-000000000002', NOW(), NOW()),
('00000000-0000-0000-0000-000000000005', '50000000-0000-0000-0000-000000000004', NOW(), NOW()),
('00000000-0000-0000-0000-000000000005', '50000000-0000-0000-0000-000000000005', NOW(), NOW());

-- Editor tiene permisos de edición
INSERT INTO role_permission (role_id, permission_id, created_at, updated_at) VALUES
('00000000-0000-0000-0000-000000000006', '10000000-0000-0000-0000-000000000002', NOW(), NOW()),
('00000000-0000-0000-0000-000000000006', '40000000-0000-0000-0000-000000000001', NOW(), NOW()),
('00000000-0000-0000-0000-000000000006', '40000000-0000-0000-0000-000000000002', NOW(), NOW()),
('00000000-0000-0000-0000-000000000006', '40000000-0000-0000-0000-000000000003', NOW(), NOW()),
('00000000-0000-0000-0000-000000000006', '40000000-0000-0000-0000-000000000004', NOW(), NOW()),
('00000000-0000-0000-0000-000000000006', '40000000-0000-0000-0000-000000000005', NOW(), NOW()),
('00000000-0000-0000-0000-000000000006', '50000000-0000-0000-0000-000000000001', NOW(), NOW()),
('00000000-0000-0000-0000-000000000006', '50000000-0000-0000-0000-000000000002', NOW(), NOW()),
('00000000-0000-0000-0000-000000000006', '50000000-0000-0000-0000-000000000003', NOW(), NOW()),
('00000000-0000-0000-0000-000000000006', '50000000-0000-0000-0000-000000000004', NOW(), NOW());

-- ============================================
-- RELACIONES USUARIO-PERMISO (Directas)
-- ============================================
-- Algunos usuarios tienen permisos directos además de los de sus roles

-- Test User tiene permisos adicionales directos
INSERT INTO user_permission (user_id, permission_id, created_at, updated_at) VALUES
('a0000000-0000-0000-0000-000000000012', '40000000-0000-0000-0000-000000000004', NOW(), NOW()),
('a0000000-0000-0000-0000-000000000012', '50000000-0000-0000-0000-000000000005', NOW(), NOW());

-- ============================================
-- RESUMEN DE DATOS INSERTADOS
-- ============================================
-- 
-- Roles: 6 (admin, manager, user, guest, moderator, editor)
-- Permisos: 30 (usuarios, roles, permisos, posts, comentarios, sistema)
-- Usuarios: 12 (admin, manager, 6 usuarios regulares, moderator, editor, guest, test)
-- Relaciones usuario-rol: 14 (algunos usuarios tienen múltiples roles)
-- Relaciones rol-permiso: ~60+ (distribución según roles)
-- Relaciones usuario-permiso: 2 (permisos directos adicionales)
--
-- ============================================
