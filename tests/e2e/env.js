import { fileURLToPath } from 'node:url';

// The environment the browser tests' Laravel server runs in (Story 5.8): APP_ENV=e2e, which is the
// only environment that registers the test sign-in route, against a throwaway SQLite file that
// global-setup migrates and seeds fresh on every run. Real env vars win over .env, so a developer's
// local MySQL is never touched.
export const E2E_DATABASE = fileURLToPath(new URL('../../database/e2e.sqlite', import.meta.url));

export const E2E_ENV = {
    APP_ENV: 'e2e',
    DB_CONNECTION: 'sqlite',
    // Blanked because config/database.php's sqlite connection reads `url` from DB_URL ahead of
    // `database`: a DB_URL in a developer's .env or shell would otherwise point global-setup's
    // `migrate:fresh --force` at that database and wipe it.
    DB_URL: '',
    DB_DATABASE: E2E_DATABASE,
    SESSION_DRIVER: 'file',
    CACHE_STORE: 'array',
    QUEUE_CONNECTION: 'sync',
};
