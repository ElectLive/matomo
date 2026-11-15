<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\Transitions\PreprocessedPages;

use Piwik\ArchiveProcessor\Rules;
use Piwik\Common;
use Piwik\Concurrency\LockBackend;
use Piwik\DataAccess\ArchiveTableCreator;
use Piwik\DataAccess\ArchiveWriter;
use Piwik\DataAccess\Model;
use Piwik\Date;
use Piwik\Db;
use Piwik\Period;
use Piwik\Period\Day;
use Piwik\Plugins\PrivacyManager\PrivacyManager;
use Piwik\Segment;

/**
 * The partial archives holding pre-processed Transitions records, `done<segment hash>.Transitions`: written by the
 * rebuild of an added page and by builds within report requests.
 *
 * Core never invalidates partial archives, so they are deleted when the periods they hold are invalidated. Every
 * partial archive left is then newer than the last invalidation of its period, and is served before an older,
 * invalidated archive.
 */
final class PartialArchives
{
    /**
     * How long an invalidation is noticed by a build of its day that runs meanwhile; longer than a build may run.
     */
    private const INVALIDATION_MARKER_TTL_SECONDS = 3600;

    private const INVALIDATION_MARKER_PREFIX = 'Transitions.dayInvalidated.';

    public function __construct(
        private readonly Dao $dao,
        private readonly Model $model,
        private readonly LockBackend $lockBackend
    ) {
    }

    /**
     * Deletes the partial archives of the periods core invalidates, as `ArchiveInvalidator::markArchivesAsInvalidated()`
     * selects them: the periods of the dates and their parent periods, for the segment, or for all segments without
     * one. Also those still being built, as far as they are stored yet; a build within a request notices the
     * invalidation, see {@link getInvalidationMarker()}. Dates core skips as their logs were deleted are skipped too.
     * Child periods are left, as the invalidation doesn't tell whether it cascades down to them.
     *
     * @param array<int|string> $idSites
     * @param array<Date|string> $dates
     * @param string|false|null $period the period of the dates, a day if empty
     */
    public function deleteForInvalidation(array $idSites, array $dates, $period, ?Segment $segment): void
    {
        $periodLabel = empty($period) ? 'day' : (string) $period;
        if ($periodLabel === 'range' || empty($dates)) {
            return;
        }

        $idSites = $this->dao->getSitesWithAnyPages(array_values(array_unique(array_map('intval', $idSites))));
        if (empty($idSites)) {
            return;
        }

        $periodsByTable = [];
        $days = [];
        foreach ($this->getPeriodsToInvalidate($dates, $periodLabel) as $periodToInvalidate) {
            $table = ArchiveTableCreator::getNumericTable($periodToInvalidate->getDateStart(), false);
            if (!empty($table)) {
                $periodsByTable[$table][$periodToInvalidate->getRangeString()] = $periodToInvalidate;
            }
            if ($periodToInvalidate->getLabel() === 'day') {
                $days[] = $periodToInvalidate->getDateStart()->toString();
            }
        }

        // a build of an invalidated day that runs now doesn't keep its records, see wasDayInvalidatedSince()
        foreach ($idSites as $idSite) {
            foreach ($days as $day) {
                $this->touchInvalidationMarker($idSite, $day);
            }
        }

        foreach ($periodsByTable as $table => $periods) {
            $this->deletePartialArchives($table, $idSites, array_values($periods), $segment);
        }
    }

    /**
     * Deletes the page's older partial archives of the day and segment, which hold only the page's records, once a
     * newer one is written.
     */
    public function deleteOlderPartialArchivesOfPage(int $idSite, Period $day, Segment $segment, int $idPage, int $idArchive): void
    {
        $table = ArchiveTableCreator::getNumericTable($day->getDateStart(), false);
        if (empty($table)) {
            return;
        }

        $pageviews = Records::getRecordName($idPage, Records::PAGEVIEWS);
        $rows = Db::fetchAll(
            'SELECT p.idarchive FROM `' . $table . '` p WHERE p.idsite = ? AND p.period = ? AND p.date1 = ? AND p.date2 = ?'
            . ' AND p.name = ? AND p.value = ? AND p.idarchive < ?'
            . ' AND EXISTS (SELECT 1 FROM `' . $table . '` r WHERE r.idarchive = p.idarchive AND r.name = ?)'
            . ' AND NOT EXISTS (SELECT 1 FROM `' . $table . '` o WHERE o.idarchive = p.idarchive AND o.name LIKE ? AND o.name <> ?)',
            [
                $idSite,
                Day::PERIOD_ID,
                $day->getDateStart()->toString(),
                $day->getDateEnd()->toString(),
                Rules::getDoneFlagArchiveContainsOnePlugin($segment, 'Transitions'),
                ArchiveWriter::DONE_PARTIAL,
                $idArchive,
                $pageviews,
                'Transitions\_%\_' . Records::PAGEVIEWS,
                $pageviews,
            ]
        );

        $this->deleteArchives($day, array_map(fn(array $row): int => (int) $row['idarchive'], $rows));
    }

    /**
     * @param list<int> $idArchives
     */
    public function deleteArchives(Period $period, array $idArchives): void
    {
        $table = ArchiveTableCreator::getNumericTable($period->getDateStart(), false);
        if (empty($table) || empty($idArchives)) {
            return;
        }

        $this->model->deleteArchiveIds($table, ArchiveTableCreator::getBlobTable($period->getDateStart(), false) ?: null, $idArchives);
    }

    /**
     * Changes whenever partial archives of the day are deleted for an invalidation, and for a while after.
     */
    public function getInvalidationMarker(int $idSite, string $day): ?string
    {
        $value = $this->lockBackend->get($this->getInvalidationMarkerKey($idSite, $day));

        return is_string($value) ? $value : null;
    }

    private function touchInvalidationMarker(int $idSite, string $day): void
    {
        $key = $this->getInvalidationMarkerKey($idSite, $day);
        $previous = $this->lockBackend->get($key);
        if (is_string($previous)) {
            $this->lockBackend->deleteIfKeyHasValue($key, $previous);
        }

        $this->lockBackend->setIfNotExists($key, Common::getRandomString(16), self::INVALIDATION_MARKER_TTL_SECONDS);
    }

    private function getInvalidationMarkerKey(int $idSite, string $day): string
    {
        return self::INVALIDATION_MARKER_PREFIX . $idSite . '.' . $day;
    }

    /**
     * @param list<int> $idSites
     * @param list<Period> $periods
     */
    private function deletePartialArchives(string $table, array $idSites, array $periods, ?Segment $segment): void
    {
        $bind = [];
        $periodConditions = [];
        foreach ($periods as $period) {
            $periodConditions[] = '(period = ? AND date1 = ? AND date2 = ?)';
            array_push($bind, $period->getId(), $period->getDateStart()->toString(), $period->getDateEnd()->toString());
        }

        if ($segment === null) {
            $nameCondition = "name LIKE 'done%.Transitions'";
        } else {
            $nameCondition = 'name = ?';
            $bind[] = Rules::getDoneFlagArchiveContainsOnePlugin($segment, 'Transitions');
        }

        $rows = Db::fetchAll(
            'SELECT idarchive, period, date1 FROM `' . $table . '` WHERE idsite IN (' . implode(',', $idSites) . ')'
            . ' AND (' . implode(' OR ', $periodConditions) . ') AND ' . $nameCondition
            . ' AND value IN (' . implode(',', [ArchiveWriter::DONE_PARTIAL, ArchiveWriter::DONE_ERROR, ArchiveWriter::DONE_ERROR_INVALIDATED]) . ')',
            $bind
        );
        if (empty($rows)) {
            return;
        }

        $this->deleteArchives($periods[0], array_map(fn(array $row): int => (int) $row['idarchive'], $rows));
    }

    /**
     * As `ArchiveInvalidator::markArchivesAsInvalidated()`: the period of each date, unless its logs were deleted, and
     * the parent periods of a period within one month.
     *
     * @param array<Date|string> $dates
     * @return list<Period>
     */
    private function getPeriodsToInvalidate(array $dates, string $periodLabel): array
    {
        $minimumDateWithLogs = $this->getMinimumDateWithLogs();

        $periods = [];
        foreach ($dates as $date) {
            try {
                $period = Period\Factory::build($periodLabel, $date instanceof Date ? $date : Date::factory((string) $date));
            } catch (\Exception $e) {
                // core rejects it
                continue;
            }

            if (
                $minimumDateWithLogs !== null
                && ($period->getDateEnd()->isEarlier($minimumDateWithLogs) || $period->getDateStart()->isEarlier($minimumDateWithLogs))
            ) {
                continue;
            }

            $periods[] = $period;
            if ($period->getDateStart()->toString('Y-m') !== $period->getDateEnd()->toString('Y-m')) {
                continue;
            }

            $parent = $period;
            while (
                !in_array($parent->getLabel(), ['year', 'range'], true)
                && Period\Factory::isPeriodEnabledForAPI($parent->getParentPeriodLabel())
            ) {
                $parent = Period\Factory::build($parent->getParentPeriodLabel(), $period->getDateStart());
                $periods[] = $parent;
            }
        }

        return $periods;
    }

    private function getMinimumDateWithLogs(): ?Date
    {
        $settings = PrivacyManager::getPurgeDataSettings();
        $days = (int) ($settings['delete_logs_older_than'] ?? 0);

        return !empty($settings['delete_logs_enable']) && $days > 0 ? Date::factory('today')->subDay($days) : null;
    }
}
