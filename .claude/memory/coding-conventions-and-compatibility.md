# Coding conventions and compatibility

Read before writing code or changing supported PHP/Joomla versions.

- Allman brace style (opening brace on its own line)
- `defined('_JEXEC') or die;` guard on all PHP files
- Tab indentation
- PHPDoc with `@since` version tags
- PHP 8.1+ compatible syntax (no features from PHP versions newer than the declared ceiling, currently 8.6)

## Compatibility

- PHP: 8.1.0 – 8.6.x (platform target: 8.1.0)
- Joomla: 5.4.x – 6.2.x
- Only tested on supported (non-EOL) PHP versions
- Minimum and maximum requirements enforced in install script: PHP 8.1.0–8.6.x, Joomla 5.4.0–6.2.x
