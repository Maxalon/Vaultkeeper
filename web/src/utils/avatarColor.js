/**
 * avatarColor.js — shared avatar colour + initials helpers.
 *
 * Centralised here so the djb2-style hash and colour palette stay in
 * sync across every place avatars appear.
 */

export const AVATAR_PALETTE = [
  '#5b6ee1', // indigo
  '#37946e', // forest
  '#8b6914', // amber-dark
  '#9e3030', // crimson
  '#4e7fa0', // steel
  '#7b5ea7', // purple
  '#3d7a5f', // teal
  '#b85c38', // rust
]

/**
 * Deterministic background colour from a username string.
 * Uses a djb2-style hash so the same username always maps to the same
 * colour across sessions without any server round-trip.
 *
 * @param {string} username
 * @returns {string} CSS colour from AVATAR_PALETTE
 */
export function avatarColor(username) {
  if (!username) return AVATAR_PALETTE[0]
  let h = 5381
  for (let i = 0; i < username.length; i++) {
    h = ((h << 5) + h) ^ username.charCodeAt(i)
    h = h >>> 0
  }
  return AVATAR_PALETTE[h % AVATAR_PALETTE.length]
}

/**
 * Returns the first two characters of a username, uppercased, for use as
 * avatar initials. Falls back to '?' when username is falsy.
 *
 * @param {string} username
 * @returns {string}
 */
export function avatarInitials(username) {
  if (!username) return '?'
  return username.slice(0, 2).toUpperCase()
}
