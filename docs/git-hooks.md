# Git Hooks - Guía de Uso

## Descripción

Sistema de Git Hooks para validaciones automáticas antes de commits y push. Los hooks aseguran calidad de código, formato consistente y mensajes de commit estructurados.

> **Nota**: Los hooks están disponibles pero no están instalados por defecto. Puedes instalarlos cuando lo desees usando `make install-hooks` o `./scripts/install-git-hooks.sh`. Los hooks son opcionales y pueden desinstalarse en cualquier momento.

## Hooks Disponibles

### 1. Pre-commit Hook

Se ejecuta automáticamente antes de cada commit y realiza las siguientes validaciones:

#### Validaciones

1. **Sintaxis PHP** (`php -l`)
   - Valida que todos los archivos PHP staged tengan sintaxis correcta
   - Falla el commit si hay errores de sintaxis

2. **Detección de Código de Debug**
   - Busca y previene commits con:
     - `dd()` - Laravel dump and die
     - `dump()` - Laravel dump
     - `var_dump()` - PHP var_dump
     - `console.log()` - JavaScript console.log
   - **Acción**: Falla el commit si encuentra código de debug

3. **Formateo Automático con Pint**
   - Ejecuta Laravel Pint automáticamente en archivos staged
   - Si Pint modifica archivos, los agrega automáticamente al staging
   - Asegura formato consistente según PSR-12

4. **Tests Rápidos** (Opcional)
   - Ejecuta tests antes del commit
   - Puede ser lento, se puede saltar con `SKIP_TESTS=true`

#### Comportamiento con Docker

- Los hooks detectan automáticamente si Docker está corriendo
- Si el contenedor no está activo, los hooks se saltan automáticamente
- Los comandos PHP se ejecutan dentro del contenedor Docker

#### Ejemplos de Uso

```bash
# Commit normal (ejecuta todas las validaciones)
git commit -m "feat: agregar nueva funcionalidad"

# Saltar validaciones (no recomendado)
git commit --no-verify -m "feat: agregar nueva funcionalidad"

# Saltar solo tests (más rápido)
SKIP_TESTS=true git commit -m "feat: agregar nueva funcionalidad"
```

### 2. Commit-msg Hook

Valida que los mensajes de commit sigan el formato **Conventional Commits**.

#### Formato Requerido

```
tipo(scope): descripción
```

#### Tipos Válidos

- `feat`: Nueva funcionalidad
- `fix`: Corrección de bug
- `docs`: Cambios en documentación
- `style`: Cambios de formato (espacios, comas, etc.)
- `refactor`: Refactorización de código
- `test`: Agregar o modificar tests
- `chore`: Tareas de mantenimiento
- `perf`: Mejoras de rendimiento
- `ci`: Cambios en CI/CD
- `build`: Cambios en sistema de build
- `revert`: Revertir un commit anterior

#### Ejemplos Válidos

```bash
# Sin scope
feat: agregar autenticación JWT
fix: corregir error 500 en usuarios
docs: actualizar README

# Con scope
feat(auth): agregar login con Google
fix(api): corregir validación de email
refactor(services): mejorar estructura de servicios

# Con descripción larga
feat(backups): implementar sistema de backups automáticos con S3
```

#### Reglas

- **Tipo**: Obligatorio, debe ser uno de los tipos válidos
- **Scope**: Opcional, entre paréntesis
- **Descripción**: Obligatoria, mínimo 10 caracteres después de ":"
- **Formato**: Debe seguir el patrón `tipo(scope): descripción`

#### Excepciones

Los siguientes tipos de commits se permiten sin validación:
- Commits de merge: `Merge branch 'feature'`
- Commits de revert: `Revert "feat: ..."`
- Commits de fixup/squash: `fixup! ...`, `squash! ...`
- Commits que empiezan con `#` (comentarios)

## Instalación

### Instalación Automática (Recomendado)

```bash
# Usando Make
make install-hooks

# O directamente
./scripts/install-git-hooks.sh
```

### Instalación Manual

```bash
# Copiar hooks
cp scripts/git-hooks/pre-commit .git/hooks/pre-commit
cp scripts/git-hooks/commit-msg .git/hooks/commit-msg

# Dar permisos de ejecución
chmod +x .git/hooks/pre-commit
chmod +x .git/hooks/commit-msg
```

## Desinstalación

```bash
# Eliminar hooks
rm .git/hooks/pre-commit .git/hooks/commit-msg
```

## Deshabilitar Validación de Mensajes de Commit

Si prefieres no validar el formato de los mensajes de commit, tienes varias opciones:

### Opción 1: Deshabilitar permanentemente (recomendado)

```bash
# Deshabilitar validación de mensajes
git config hooks.skip-commit-msg-validation true
```

### Opción 2: Deshabilitar temporalmente

```bash
# Solo para este commit
SKIP_COMMIT_MSG_VALIDATION=true git commit -m "tu mensaje"

# O saltar todos los hooks
git commit --no-verify -m "tu mensaje"
```

### Opción 3: Desinstalar solo el hook de mensajes

```bash
# Eliminar solo el hook de validación de mensajes
rm .git/hooks/commit-msg
```

### Volver a habilitar

```bash
# Habilitar validación nuevamente
git config hooks.skip-commit-msg-validation false

# O reinstalar hooks
make install-hooks
```

## Configuración

### Variables de Entorno

- `SKIP_TESTS=true`: Saltar ejecución de tests en pre-commit
- `ENV=dev`: Especificar perfil de Docker (por defecto: dev)

### Git Config

```bash
# Permitir nombres de archivo no-ASCII (por defecto deshabilitado)
git config hooks.allownonascii true

# Deshabilitar validación de mensajes de commit (opcional)
git config hooks.skip-commit-msg-validation true

# Volver a habilitar validación de mensajes de commit
git config hooks.skip-commit-msg-validation false
```

### Variables de Entorno

- `SKIP_COMMIT_MSG_VALIDATION=true`: Deshabilitar validación de mensajes de commit temporalmente

## Troubleshooting

### El hook no se ejecuta

1. Verificar que el hook está instalado:
   ```bash
   ls -la .git/hooks/pre-commit
   ```

2. Verificar permisos de ejecución:
   ```bash
   chmod +x .git/hooks/pre-commit
   ```

### Docker no está corriendo

- Los hooks detectan automáticamente si Docker está corriendo
- Si el contenedor no está activo, los hooks se saltan con una advertencia
- Para ejecutar validaciones, asegúrate de que Docker esté corriendo:
  ```bash
  make up
  ```

### Tests son muy lentos

- Puedes saltar tests en pre-commit:
  ```bash
  SKIP_TESTS=true git commit -m "feat: ..."
  ```
- Los tests se ejecutarán en CI/CD de todas formas

### Error: "Formato de commit inválido"

- Asegúrate de seguir el formato Conventional Commits
- Revisa los ejemplos válidos en la sección anterior
- **Si Cursor/VS Code genera el mensaje automáticamente**: Edita el mensaje antes de hacer commit para seguir el formato `tipo: descripción`
- El hook ahora sugiere automáticamente una corrección basada en palabras comunes (Add → feat, Fix → fix, etc.)
- Si necesitas saltar la validación (no recomendado):
  ```bash
  git commit --no-verify -m "mensaje"
  ```

### Cursor/VS Code genera mensajes automáticamente

Si Cursor o VS Code generan mensajes de commit automáticamente que no siguen el formato:

1. **Edita el mensaje antes de hacer commit**: En la interfaz de Cursor/VS Code, modifica el mensaje generado para seguir el formato Conventional Commits.

2. **Usa la plantilla configurada**: Se ha configurado `.vscode/settings.json` con una plantilla que sugiere el formato correcto.

3. **Ejemplo de corrección**:
   - Mensaje generado: `Add Git hooks for pre-commit and commit-msg validation`
   - Mensaje corregido: `feat: add git hooks for pre-commit and commit-msg validation`

### Pint modifica archivos automáticamente

- Esto es normal y esperado
- Pint formatea el código según PSR-12
- Los archivos modificados se agregan automáticamente al staging
- Si prefieres formatear manualmente:
  ```bash
  make pint
  git add .
  git commit -m "style: formatear código"
  ```

## Integración con CI/CD

Los hooks locales complementan el pipeline de CI/CD:

- **Hooks locales**: Validaciones rápidas antes de commit
- **CI/CD**: Validaciones completas en servidor remoto

Ambos trabajan juntos para asegurar calidad de código en todo el flujo de desarrollo.

## Mejores Prácticas

1. **No saltar hooks a menos que sea necesario**
   - Los hooks están diseñados para mantener calidad de código
   - Si necesitas saltar, considera por qué y si es realmente necesario

2. **Mensajes de commit descriptivos**
   - Usa Conventional Commits para mejor trazabilidad
   - Los mensajes claros ayudan en el historial de Git

3. **Ejecutar Pint manualmente antes de commit**
   - Puedes ejecutar `make pint` antes de hacer commit
   - Esto hace que el hook sea más rápido

4. **Mantener hooks actualizados**
   - Si se actualizan los hooks en el repositorio, reinstálalos:
     ```bash
     make install-hooks
     ```

## Referencias

- [Conventional Commits](https://www.conventionalcommits.org/)
- [Laravel Pint](https://laravel.com/docs/pint)
- [Git Hooks Documentation](https://git-scm.com/docs/githooks)
