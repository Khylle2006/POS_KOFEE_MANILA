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
    run(php, ['vendor/bin/phpcs']);
} else if (mode === 'typecheck') run(php, ['vendor/bin/phpstan', 'analyse', '--no-progress']);
else if (mode === 'test') {
    run(process.execPath, ['--test', 'tests/ui.test.cjs', 'tests/security.test.cjs', 'tests/analytics.test.cjs', 'tests/public_pages.test.cjs']);
    run(php, ['vendor/bin/phpunit']);
} else throw new Error('Use lint, typecheck, or test.');
