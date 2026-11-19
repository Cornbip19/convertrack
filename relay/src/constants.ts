/**
 * Shared constants. They live apart from the entry module because workerd
 * requires every export of the entry module to be a handler.
 */
export const RELAY_VERSION = '1.0.0';
export const RELAY_CALLBACK_PATH = '/v1/callback';
