import { spawnSync } from 'node:child_process';
import { existsSync, readdirSync } from 'node:fs';
import { resolve, join } from 'node:path';

const root = resolve(import.meta.dirname, '..');
const php = process.env.PHP_BINARY || (process.platform === 'win32' && existsSync('C:/xampp/php/php.exe') ? 'C:/xampp/php/php.exe' : 'php');
const mode = process.argv[2];
function run(program, args) {
    const result = spawnSync(program, args, { cwd: root, stdio: 'inherit' });
    if (result.error) throw result.error;
    if (result.status !== 0) process.exit(result.status || 1);
}
function files(folder, extension) {
    return readdirSync(folder, { withFileTypes: true }).flatMap(entry => {
        if (['vendor', 'node_modules', '.test-mysql', 'scratch', 'dist', 'cache'].includes(entry.name)) return [];
        const path = join(folder, entry.name);
        return entry.isDirectory() ? files(path, extension) : path.endsWith(extension) && entry.name !== 'config.local.php' ? [path] : [];
    });
}
if (mode === 'lint') {
    for (const file of files(root, '.php')) run(php, ['-l', file]);
    for (const file of files(root, '.js')) run(process.execPath, ['--check', file]);
    const phpcs = existsSync(join(root, 'vendor/bin/phpcs')) ? 'vendor/bin/phpcs' : (existsSync(join(root, 'vendor/bin/phpcs.bat')) ? 'vendor/bin/phpcs.bat' : null);
    if (phpcs) run(php, [phpcs]);
} else if (mode === 'typecheck') {
    const phpstan = existsSync(join(root, 'vendor/bin/phpstan')) ? 'vendor/bin/phpstan' : (existsSync(join(root, 'vendor/bin/phpstan.bat')) ? 'vendor/bin/phpstan.bat' : null);
    if (phpstan) run(php, [phpstan, 'analyse', '--no-progress']);
}
else if (mode === 'test') {
    run(process.execPath, ['--test', 'tests/ui.test.cjs', 'tests/security.test.cjs', 'tests/analytics.test.cjs', 'tests/public_pages.test.cjs']);
    const phpunit = existsSync(join(root, 'vendor/bin/phpunit')) ? 'vendor/bin/phpunit' : (existsSync(join(root, 'vendor/bin/phpunit.bat')) ? 'vendor/bin/phpunit.bat' : null);
    if (phpunit) run(php, [phpunit]);
} else throw new Error('Use lint, typecheck, or test.');
