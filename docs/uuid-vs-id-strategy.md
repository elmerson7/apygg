# Estrategia de IDs: UUID vs Auto-incrementable

## Resumen por Tipo de Tabla

**Regla general: Usar solo UUID por defecto. ID solo cuando sea absolutamente necesario.**

Esta guía define qué tipo de identificador usar según el tipo de tabla en el proyecto APYGG.

**Nota importante**: Las tablas nativas de Laravel (`cache`, `sessions`, `jobs`, `migrations`, etc.) **NO deben modificarse**. Esta guía aplica solo a tablas personalizadas del proyecto.

| Tipo de Tabla | ID Interno | UUID Público | Ejemplo |
|---------------|------------|--------------|---------|
| **Entidades públicas (por defecto)** | ❌ No | ✅ Sí | `users`, `roles`, `permissions`, `products`, `orders` |
| **Entidades con API** | ❌ No | ✅ Sí | `api_keys`, `webhooks`, `notifications`, `tickets` |
| **Entidades públicas simples** | ❌ No | ✅ Sí | `public_tokens`, `shareable_links`, `public_files` |
| **Logs y auditoría** | ✅ Sí | ❌ No | `logs_api`, `logs_error`, `logs_security`, `logs_activity` |
| **Tablas pivot** | ✅ Sí | ❌ No | `role_permission`, `user_role`, `user_permission` |
| **Tablas sistema Laravel** | ⚠️ No tocar | ⚠️ No tocar | `cache`, `jobs`, `sessions`, `failed_jobs`, `migrations` |
| **Tablas internas** | ✅ Sí | ❌ No | `system_settings`, `internal_config` |
| **Reportes y analytics** | ✅ Sí | ❌ No | `reports`, `analytics`, `metrics`, `daily_stats` |
| **Backups y sincronización** | ✅ Sí | ❌ No | `backups`, `sync_logs`, `replication_status` |
| **Productos y catálogos** | ❌ No | ✅ Sí | `products`, `categories`, `brands`, `variants` |
| **Órdenes y transacciones** | ❌ No | ✅ Sí | `orders`, `invoices`, `payments`, `refunds` |
| **Clientes y contactos** | ❌ No | ✅ Sí | `customers`, `contacts`, `addresses` |
| **Contenido y publicaciones** | ❌ No | ✅ Sí | `posts`, `articles`, `pages`, `comments` |
| **Archivos y medios** | ❌ No | ✅ Sí | `files`, `images`, `documents`, `videos` |
| **Notificaciones** | ❌ No | ✅ Sí | `notifications`, `messages`, `alerts` |
| **Inventario y stock** | ❌ No | ✅ Sí | `inventory`, `warehouses`, `stock_movements` |
| **Proyectos y tareas** | ❌ No | ✅ Sí | `projects`, `tasks`, `milestones`, `time_entries` |
| **Facturación y suscripciones** | ❌ No | ✅ Sí | `subscriptions`, `billing_cycles`, `invoices` |
| **Eventos y calendarios** | ❌ No | ✅ Sí | `events`, `appointments`, `bookings` |
| **Foros y comunidades** | ❌ No | ✅ Sí | `topics`, `replies`, `threads`, `forums` |
| **Encuestas y formularios** | ❌ No | ✅ Sí | `surveys`, `forms`, `responses`, `submissions` |
| **Cupones y promociones** | ❌ No | ✅ Sí | `coupons`, `promotions`, `discounts`, `vouchers` |
| **Reseñas y calificaciones** | ❌ No | ✅ Sí | `reviews`, `ratings`, `feedback` |
| **Carritos y wishlists** | ❌ No | ✅ Sí | `carts`, `wishlists`, `favorites` |
| **Envíos y logística** | ❌ No | ✅ Sí | `shipments`, `tracking`, `deliveries`, `carriers` |
| **Soporte y tickets** | ❌ No | ✅ Sí | `tickets`, `conversations`, `support_requests` |
| **Campañas y marketing** | ❌ No | ✅ Sí | `campaigns`, `email_campaigns`, `newsletters` |
| **Integraciones y webhooks** | ❌ No | ✅ Sí | `integrations`, `webhook_logs`, `api_calls` |
| **Tokens temporales** | ❌ No | ✅ Sí | `public_access_tokens`, `temporary_links` |
| **Auditoría de cambios** | ✅ Sí | ❌ No | `audit_logs`, `change_history`, `version_history` |
| **Relaciones many-to-many** | ✅ Sí | ❌ No | `product_category`, `order_item`, `user_group` |
| **Tablas de configuración** | ✅ Sí | ❌ No | `system_settings`, `feature_flags`, `environment_vars` |
| **Métricas y estadísticas** | ✅ Sí | ❌ No | `daily_stats`, `hourly_metrics`, `aggregated_data` |

---

## Criterios de Decisión

### ✅ Solo UUID (por defecto - usar en el 95% de los casos):
- **Regla general**: Todas las tablas que se exponen en APIs públicas
- Tablas con relaciones entre sí (users, products, orders, etc.)
- Cualquier entidad de negocio accesible por usuarios
- Tablas que necesitan seguridad y privacidad
- **Ventaja**: Simple, consistente, seguro, suficiente para la mayoría de casos

### ✅ Solo ID (solo cuando sea absolutamente necesario):
- Tablas de logs y auditoría (necesitan ordenamiento rápido por ID)
- Tablas pivot (solo IDs foráneos, no se exponen)
- Tablas internas del sistema (no accesibles por APIs)
- Reportes y analytics (miles de registros, ordenamiento crítico)
- **Cuándo usar**: Solo si realmente necesitas máximo rendimiento en ordenamiento o tienes millones de registros con muchos joins

### ❌ ID + UUID (evitar - solo casos excepcionales):
- **NO usar a menos que sea absolutamente crítico**
- Solo si tienes tablas con millones de registros Y muchos joins complejos Y rendimiento crítico
- Solo si integras con sistemas legacy que requieren IDs numéricos
- **Desventaja**: Más complejidad, más mantenimiento, más confusión

### ⚠️ No modificar (Tablas de Laravel):
- Las tablas nativas de Laravel (`cache`, `sessions`, `jobs`, `migrations`, etc.)
- Mantener su estructura original sin cambios
- Estas tablas ya tienen su propia estrategia de IDs definida por Laravel

**Nota importante sobre `sessions`:**
- La tabla `sessions` de Laravel usa `foreignId('user_id')` que es `bigInteger`
- Esto significa que si `users` usa UUID, la relación con `sessions` no funcionará directamente
- **Solución**: Dejar `sessions` como está (usa `bigInteger` para `user_id`) o crear una migración separada que maneje la conversión
- En la práctica, `sessions` puede seguir usando `bigInteger` ya que es una tabla interna del sistema

---

## Notas Importantes

1. **Regla de oro**: **Solo UUID por defecto**. ID solo cuando sea absolutamente necesario.

2. **UUID como identificador principal**: Usar `uuid` con generación automática (`gen_random_uuid()` en PostgreSQL) como PRIMARY KEY. Es suficiente para el 95% de los casos.

3. **Ordenamiento**: Con UUID, siempre ordenar por `created_at` o `updated_at`, nunca por UUID directamente. Esto es suficiente y más semántico.

4. **Rendimiento**: 
   - PostgreSQL maneja UUID eficientemente
   - El rendimiento es aceptable incluso con millones de registros
   - Solo necesitas ID si tienes problemas reales de rendimiento medidos

5. **Seguridad**: UUID evita que usuarios puedan adivinar IDs o enumerar registros. Es más seguro que IDs auto-incrementables.

6. **Simplicidad**: Una sola columna es más simple de mantener que dos. Menos confusión, menos código, menos errores.

7. **Tablas de Laravel**: **NO modificar** las tablas nativas (`cache`, `sessions`, `jobs`, `migrations`, `password_reset_tokens`, etc.). Mantener su estructura original.

8. **Limitación con `sessions`**: La tabla `sessions` de Laravel usa `foreignId('user_id')` que es `bigInteger`. Si `users` usa UUID, la relación directa no funcionará. En este caso, `sessions` puede mantenerse con `bigInteger` ya que es una tabla interna del sistema y no se expone en APIs.

9. **Cuándo considerar ID**: Solo si después de medir el rendimiento real encuentras problemas específicos. No optimices prematuramente.

---

## Referencias

- [PostgreSQL UUID Documentation](https://www.postgresql.org/docs/current/datatype-uuid.html)
- [Laravel UUID Guide](https://laravel.com/docs/eloquent#uuid-and-ulid-keys)
- [Best Practices: UUID vs Auto-increment](https://www.postgresql.org/docs/current/datatype-uuid.html)
