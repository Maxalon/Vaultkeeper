import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import svgLoader from 'vite-svg-loader'

// https://vite.dev/config/
export default defineConfig({
  plugins: [vue(), svgLoader()],
  server: {
    host: '0.0.0.0',
    port: 5173,
    strictPort: true,
    // HMR over the nginx reverse proxy on host port 8080
    hmr: {
      clientPort: 8080,
    },
    // Allow the nginx container hostname (and any host) when proxied
    allowedHosts: true,
    // Fedora + SELinux-labeled bind mount (`:z`) drops inotify events into
    // the container; poll the filesystem so HMR sees host-side edits.
    watch: {
      usePolling: true,
      interval: 300,
    },
  },
})
