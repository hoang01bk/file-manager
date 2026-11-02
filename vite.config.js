import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';
import { readFileSync, readdirSync } from 'node:fs';
import { createRequire } from 'node:module';
import { dirname, join } from 'node:path';

const require = createRequire(import.meta.url);
const pdfRoot = dirname(require.resolve('pdfjs-dist/package.json'));
const pdfVersion = JSON.parse(readFileSync(join(pdfRoot, 'package.json'), 'utf8')).version;

export default defineConfig({
    worker: { format: 'es' },
    plugins: [
        laravel({
            input: 'resources/js/app.jsx',
            refresh: true,
        }),
        react(),
        {
            name: 'pdf-preview-assets',
            apply: 'build',
            generateBundle() {
                for (const directory of ['cmaps', 'standard_fonts', 'wasm']) {
                    for (const entry of readdirSync(join(pdfRoot, directory), { withFileTypes: true })) {
                        if (entry.isFile()) this.emitFile({
                            type: 'asset', fileName: `pdfjs/${pdfVersion}/${directory}/${entry.name}`,
                            source: readFileSync(join(pdfRoot, directory, entry.name)),
                        });
                    }
                }
            },
        },
    ],
});
