"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.WP_BASE_URL = exports.WP_PASSWORD = exports.WP_USERNAME = exports.WP_ADMIN_USER = void 0;
const { WP_USERNAME = 'admin', WP_PASSWORD = 'password', WP_BASE_URL = 'http://localhost:8889', } = process.env;
exports.WP_USERNAME = WP_USERNAME;
exports.WP_PASSWORD = WP_PASSWORD;
exports.WP_BASE_URL = WP_BASE_URL;
const WP_ADMIN_USER = {
    username: WP_USERNAME,
    password: WP_PASSWORD,
};
exports.WP_ADMIN_USER = WP_ADMIN_USER;
//# sourceMappingURL=config.js.map