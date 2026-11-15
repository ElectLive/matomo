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
use Piwik\Container\StaticContainer;
use Piwik\DataAccess\ArchiveTableCreator;
use Piwik\DataAccess\ArchiveWriter;
use Piwik\DataAccess\Model;
use Piwik\Db;
use Piwik\Option;
use Piwik\Period;
use Piwik\Period\Day;
use Piwik\Period\Range;
use Piwik\Plugins\SitesManager\Model as SitesModel;
use Piwik\Segment;

/**
 * The partial archives holding pre-processed Transitions records, `done<segment hash>.Transitions`: written by the
 * rebuild of an added page and by builds within report requests.
 *
 * Core doesn't invalidate partial archives, so they are marked {@link Records::INVALIDATED} when core invalidates the
 * periods they hold. Their records are then no longer served, but they are kept, like core's invalidated archives,
 * until the period is archived again, which also builds the removed pages they hold records of. An invalidation core
 * only remembers, like that of a data subject's deleted visits, marks them once core applies it. Until then they are
 * served like core's own archives, and they stay like those if core skips it, as the logs of the day were deleted
 * meanwhile.
 */
final class PartialArchives
{
    private const INVALIDATION_MARKER_OPTION_PREFIX = 'Transitions.invalidated_';

    private const IDARCHIVES_PER_QUERY = 1000;

    public function __construct(
        private readonly Dao $dao,
        private readonly Model $model
    ) {
    }

    /**
     * Marks the partial archives of the periods core invalidated as invalidated, for the segment, or for all segments
     * without one. A build of a day within a request that runs meanwhile notices it, see {@link getInvalidationMarker()}.
     *
     * @param list<int> $idSites
     * @param list<Period> $periods the periods core invalidated, see `Archiving.markArchivesAsInvalidated.end`
     */
    public function invalidateForPeriods(array $idSites, array $periods, ?Segment $segment): void
    {
        // ranges aren't archived, see PageTransitions
        $periods = array_values(array_filter($periods, fn(Period $period): bool => !$period instanceof Range));
        if (empty($periods) || empty($idSites)) {
            return;
        }

        $idSites = $this->dao->getSitesWithAnyPages(array_values(array_unique($idSites)));
        if (empty($idSites)) {
            return;
        }

        $periodsByTable = [];
        $hasDays = false;
        foreach ($periods as $period) {
            $table = ArchiveTableCreator::getNumericTable($period->getDateStart(), false);
            if (!empty($table)) {
                $periodsByTable[$table][$period->getId() . ',' . $period->getRangeString()] = $period;
            }
            $hasDays = $hasDays || $period->getLabel() === 'day';
        }

        if ($hasDays) {
            foreach ($idSites as $idSite) {
                $this->touchInvalidationMarker($idSite);
            }
        }

        foreach ($periodsByTable as $table => $periodsOfTable) {
            $this->invalidatePartialArchives($table, $idSites, array_values($periodsOfTable), $segment);
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
     * Changes whenever days of the website are invalidated.
     */
    public function getInvalidationMarker(int $idSite): ?string
    {
        $name = $this->getInvalidationMarkerOptionName($idSite);
        Option::clearCachedOption($name);
        $value = Option::get($name);

        return is_string($value) ? $value : null;
    }

    public function forgetSite(int $idSite): void
    {
        Option::delete($this->getInvalidationMarkerOptionName($idSite));
    }

    /**
     * Forgets the websites deleted while the plugin was deactivated.
     */
    public function forgetDeletedSites(): void
    {
        $idSites = array_map('intval', StaticContainer::get(SitesModel::class)->getSitesId());
        foreach (array_keys(Option::getLike(self::INVALIDATION_MARKER_OPTION_PREFIX . '%')) as $name) {
            $name = (string) $name;
            if (!in_array((int) substr($name, strlen(self::INVALIDATION_MARKER_OPTION_PREFIX)), $idSites, true)) {
                Option::delete($name);
            }
        }
    }

    private function touchInvalidationMarker(int $idSite): void
    {
        Option::set($this->getInvalidationMarkerOptionName($idSite), Common::getRandomString(16));
    }

    private function getInvalidationMarkerOptionName(int $idSite): string
    {
        return self::INVALIDATION_MARKER_OPTION_PREFIX . $idSite;
    }

    /**
     * @param list<int> $idSites
     * @param list<Period> $periods
     */
    private function invalidatePartialArchives(string $table, array $idSites, array $periods, ?Segment $segment): void
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
            'SELECT idarchive, idsite, date1, date2, period, ts_archived FROM `' . $table . '` WHERE idsite IN (' . implode(',', $idSites) . ')'
            . ' AND (' . implode(' OR ', $periodConditions) . ') AND ' . $nameCondition
            . ' AND value = ' . ArchiveWriter::DONE_PARTIAL,
            $bind
        );

        foreach (array_chunk($rows, self::IDARCHIVES_PER_QUERY) as $chunk) {
            $values = [];
            $bind = [];
            foreach ($chunk as $row) {
                $values[] = '(?, ?, ?, ?, ?, ?, ?, 1)';
                array_push($bind, $row['idarchive'], $row['idsite'], $row['date1'], $row['date2'], $row['period'], $row['ts_archived'], Records::INVALIDATED);
            }

            // the partial archive stays DONE_PARTIAL, so core deletes it once a newer archive replaces it
            Db::query(
                'INSERT IGNORE INTO `' . $table . '` (idarchive, idsite, date1, date2, period, ts_archived, name, value) VALUES ' . implode(',', $values),
                $bind
            );
        }
    }
}
