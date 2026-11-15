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
 * Core doesn't invalidate partial archives, so they are deleted when core invalidates the periods they hold. An
 * invalidation core only remembers, like that of a data subject's deleted visits, deletes them once core applies it.
 * Until then they are served like core's own archives, and they stay like those if core skips it, as the logs of the
 * day were deleted meanwhile.
 */
final class PartialArchives
{
    private const INVALIDATION_MARKER_OPTION_PREFIX = 'Transitions.invalidated_';

    private const IDARCHIVES_PER_DELETE = 1000;

    public function __construct(
        private readonly Dao $dao,
        private readonly Model $model
    ) {
    }

    /**
     * Deletes the partial archives of the periods core invalidated, for the segment, or for all segments without one.
     * A build of a day within a request that runs meanwhile notices it, see {@link getInvalidationMarker()}.
     *
     * @param array<int|string> $idSites
     * @param array<mixed> $periods the periods core invalidated, see `Archiving.markArchivesAsInvalidated.end`
     */
    public function deleteForInvalidation(array $idSites, array $periods, ?Segment $segment): void
    {
        $periods = array_values(array_filter($periods, fn($period): bool => $period instanceof Period && !$period instanceof Range));
        if (empty($periods) || empty($idSites)) {
            return;
        }

        $idSites = $this->dao->getSitesWithAnyPages(array_values(array_unique(array_map('intval', $idSites))));
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
            $this->deletePartialArchives($table, $idSites, array_values($periodsOfTable), $segment);
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
            'SELECT idarchive FROM `' . $table . '` WHERE idsite IN (' . implode(',', $idSites) . ')'
            . ' AND (' . implode(' OR ', $periodConditions) . ') AND ' . $nameCondition
            . ' AND value = ' . ArchiveWriter::DONE_PARTIAL,
            $bind
        );

        $idArchives = array_map(fn(array $row): int => (int) $row['idarchive'], $rows);
        foreach (array_chunk($idArchives, self::IDARCHIVES_PER_DELETE) as $chunk) {
            $this->deleteArchives($periods[0], $chunk);
        }
    }
}
