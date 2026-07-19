import {
    defineConfig,
    loadEnv
} from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from "@tailwindcss/vite";

// Only needed if you're running Vite behind a reverse proxy on a custom
// hostname (e.g. Traefik, nginx). For a plain `docker compose up` or bare
// metal `npm run dev`, leave these unset — Vite's defaults (localhost, auto
// client port) just work. See README.md's "Custom local domain" section.
export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, process.cwd(), '');

    return {
        server: {
            host: '0.0.0.0', // allows access from other devices on your network
            hmr: {
                host: env.VITE_HMR_HOST || undefined,
                clientPort: env.VITE_HMR_CLIENT_PORT ? Number(env.VITE_HMR_CLIENT_PORT) : undefined,
            },
            cors: true,
            // `true` allows any Host header — fine for local dev. Restrict to a
            // comma-separated list via VITE_ALLOWED_HOSTS if you need to.
            allowedHosts: env.VITE_ALLOWED_HOSTS ? env.VITE_ALLOWED_HOSTS.split(',') : true,
        },
        plugins: [
            laravel({
                input: ['resources/css/app.css', 'resources/js/app.js'],
                refresh: [`resources/views/**/*`],
            }),
            tailwindcss(),
        ],
    };
});
