// Per-process settings only: no writes to a global Astro config or telemetry.
process.env.ASTRO_TELEMETRY_DISABLED='1';
const {cli}=await import('../node_modules/astro/dist/cli/index.js');
await cli(process.argv);
