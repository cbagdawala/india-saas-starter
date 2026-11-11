import { fileURLToPath, URL } from 'node:url'
import { defineConfig } from 'vite'
import laravel from 'laravel-vite-plugin'
import vue from '@vitejs/plugin-vue'
import tailwindcss from '@tailwindcss/vite'

export default defineConfig({
    plugins: [
        laravel({ input: ['resources/js/app.ts', 'resources/js/public.ts', 'resources/css/public.css'], refresh: true }),
        vue(),
        tailwindcss(),
    ],
    resolve: {
        alias: { '@': fileURLToPath(new URL('./resources/js', import.meta.url)) },
    },
    server: {
        watch: { ignored: ['**/storage/framework/views/**'] },
    },
})
