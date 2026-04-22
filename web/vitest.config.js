import { defineConfig, mergeConfig } from 'vitest/config'
import viteConfig from './vite.config.js'

export default mergeConfig(
  viteConfig,
  defineConfig({
    test: {
      environment: 'happy-dom',
      globals: true,
      include: ['src/**/*.spec.{js,ts}'],
      // Tests run without the dev-server HMR + host settings.
      server: { deps: { inline: ['vue', 'pinia'] } },
    },
  })
)
