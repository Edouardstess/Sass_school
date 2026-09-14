import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'
import { VitePWA } from 'vite-plugin-pwa'
import path from 'node:path'

export default defineConfig({
  plugins: [
    react(),
    tailwindcss(),
    VitePWA({
      registerType: 'autoUpdate',
      includeAssets: ['favicon.svg'],
      manifest: {
        name: 'STED Restaurant OS',
        short_name: 'STED',
        description: 'Order from your table.',
        theme_color: '#0f1115',
        background_color: '#0f1115',
        display: 'standalone',
        start_url: '/',
        icons: [
          { src: 'icon-192.png', sizes: '192x192', type: 'image/png' },
          { src: 'icon-512.png', sizes: '512x512', type: 'image/png' },
        ],
      },
      workbox: {
        // Menu images and assets can come from cache. Orders and payments
        // never can: confirming an order offline would mean committing to a
        // price and an availability the server has not agreed to.
        navigateFallbackDenylist: [/^\/api/, /^\/hubs/],
        runtimeCaching: [
          {
            urlPattern: /^https?:\/\/.*\/api\/v1\/public\/menu/,
            handler: 'NetworkFirst',
            options: {
              cacheName: 'menu',
              networkTimeoutSeconds: 4,
              expiration: { maxEntries: 20, maxAgeSeconds: 60 * 60 },
            },
          },
          {
            urlPattern: /\.(?:png|jpg|jpeg|svg|webp)$/,
            handler: 'CacheFirst',
            options: {
              cacheName: 'images',
              expiration: { maxEntries: 200, maxAgeSeconds: 60 * 60 * 24 * 30 },
            },
          },
        ],
      },
    }),
  ],
  resolve: {
    alias: { '@': path.resolve(__dirname, './src') },
  },
  server: {
    port: 5173,
    proxy: {
      '/api': { target: 'http://localhost:8080', changeOrigin: true },
      '/hubs': { target: 'http://localhost:8080', ws: true, changeOrigin: true },
    },
  },
  build: {
    // The guest app must not ship the back office. Route-level splitting keeps
    // a phone on a weak connection downloading only what it needs.
    rollupOptions: {
      output: {
        manualChunks: {
          vendor: ['react', 'react-dom', 'react-router-dom'],
          query: ['@tanstack/react-query'],
          realtime: ['@microsoft/signalr'],
        },
      },
    },
  },
})
