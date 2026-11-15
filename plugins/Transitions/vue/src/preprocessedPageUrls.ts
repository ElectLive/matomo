/*!
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

import { Matomo, translate } from 'CoreHome';

const SCHEME_AND_WWW = /^(https?:\/\/)?(www\.)?/i;

/** URLs come as the tracker stores them, with query values HTML-encoded; shown as text. */
export function displayUrl(url: string): string {
  return Matomo.helper.htmlDecode(url);
}

/** The question of the inline confirmation before a page is removed. */
export function stopConfirmLabel(url: string): string {
  return translate(
    'Transitions_StopPreprocessingConfirm',
    displayUrl(url).replace(/^https?:\/\/(www\.)?/i, ''),
  );
}

/** A `YYYY-MM-DD HH:MM:SS` time in UTC, as the API returns it, to the minute. */
export function formatUtcDateTime(dateTime: string): string {
  return translate('Transitions_DateTimeUtc', dateTime.substring(0, 16));
}

/** No timezone is further behind UTC (UTC−12). */
const MAX_BEHIND_UTC_MS = 12 * 60 * 60 * 1000;

/**
 * The first day of the month before the current one, as `YYYY-MM-DD`, taken 12 hours behind UTC
 * so the month has ended in every website's timezone. The `lastMonth` keyword is not used: on eg
 * March 31 it lands in the current month.
 */
export function previousMonthStart(now: Date): string {
  const behindmost = new Date(now.getTime() - MAX_BEHIND_UTC_MS);
  const month = behindmost.getUTCMonth();
  const year = behindmost.getUTCFullYear() - (month === 0 ? 1 : 0);
  const previousMonth = month === 0 ? 12 : month;

  return `${year}-${String(previousMonth).padStart(2, '0')}-01`;
}

function percentDecode(text: string): string {
  try {
    return decodeURIComponent(text);
  } catch (e) {
    return text;
  }
}

/**
 * What typed text and a decoded URL are compared by: without protocol and `www.`,
 * percent-decoded and case-insensitive.
 */
export function matchKey(text: string): string {
  return percentDecode(text.trim().replace(SCHEME_AND_WWW, '')).toLowerCase();
}

/**
 * Two decoded URLs with the same key are the same selected page: protocol and `www.` don't count,
 * and the host is case-insensitive. The path is not.
 */
export function pageKey(url: string): string {
  const withoutScheme = url.trim().replace(SCHEME_AND_WWW, '');
  const hostEnd = withoutScheme.search(/[/?#]/);
  const host = hostEnd === -1 ? withoutScheme : withoutScheme.substring(0, hostEnd);

  return host.toLowerCase() + withoutScheme.substring(host.length);
}
