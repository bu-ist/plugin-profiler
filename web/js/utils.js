/**
 * Shared utility functions for the Plugin Profiler frontend.
 *
 * Keeping these in a dedicated module allows every JS file to import them
 * without creating circular dependencies or duplicating logic.
 */

/**
 * Escape HTML special characters in a string to prevent XSS when
 * interpolating untrusted values into innerHTML template literals.
 *
 * @param {string|null|undefined} str - Value to escape.
 * @returns {string} HTML-safe string.
 */
export function escapeHtml(str) {
  if (str == null) return '';
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}
