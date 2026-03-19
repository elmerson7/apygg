# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- CHANGELOG.md con formato Keep a Changelog
- CONTRIBUTING.md con guías de contribución
- ARCHITECTURE.md con documentación de arquitectura
- Soporte base para multi-tenancy
- Stubs personalizados para Laravel

## [1.0.0] - 2026-01-27

### Added
- Sistema de autenticación JWT completo (login, register, logout, refresh, forgot-password)
- Sistema de roles y permisos con Spatie Permission
- Gestión de usuarios con CRUD completo
- Sistema de API Keys con scopes y rotación
- Sistema de Webhooks con reintentos automáticos
- Subida y gestión de archivos (local, S3, GCS)
- Notificaciones push (FCM) con Device Tokens
- Configuración de aplicación (Settings)
- Logging avanzado (API logs, Security logs, Activity logs)
- Health checks para monitoreo

### Middlewares
- Rate Limiting adaptativo
- CORS configurable
- Security Headers
- IP Whitelist
- ETag para caching
- TraceId para trazabilidad
- Response Compression

### Infraestructura
- Docker con FrankenPHP y Octane
- PostgreSQL 18 con soporte UUID
- Redis para cache y colas
- Laravel Horizon para monitoreo de colas
- Laravel Reverb para WebSockets
- Laravel Telescope para debugging
- Sentry para error tracking

### Documentación
- API auto-generada con Scramble (Swagger/OpenAPI)
- Documentación completa de endpoints

### Patrones de Diseño
- Repository Pattern para desacoplamiento de datos
- DTOs para tipado fuerte
- Enums centralizados
- Service Layer para lógica de negocio
- Contracts/Interfaces para inyección de dependencias
- Base classes (Model, Controller, FormRequest, Resource)

### CI/CD
- Pipeline CI con GitHub Actions
- Pipeline CD con deploy automático
- SonarCloud para análisis de código
- Dependabot para actualización de dependencias
