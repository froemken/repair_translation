<?php
namespace StefanFroemken\RepairTranslation\SignalSlot;

/*
 * This file is part of the repair_translation project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Utility\VersionNumberUtility;
use TYPO3\CMS\Extbase\Persistence\Generic\Qom\JoinInterface;
use TYPO3\CMS\Extbase\Persistence\Generic\Qom\SelectorInterface;
use TYPO3\CMS\Extbase\Persistence\QueryInterface;

/**
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License, version 3 or later
 */
class Repair
{
    /**
     * @var \TYPO3\CMS\Frontend\Page\PageRepository
     */
    protected $pageRepository;

    /**
     * @var \TYPO3\CMS\Extbase\Service\EnvironmentService
     * @inject
     */
    protected $environmentService;

    /**
     * @var \TYPO3\CMS\Extbase\Persistence\Generic\Mapper\DataMapper
     * @inject
     */
    protected $dataMapper;

    /**
     * @var \StefanFroemken\RepairTranslation\Parser\QueryParser
     * @inject
     */
    protected $queryParser;

    /**
     * Modify sys_file_reference language
     *
     * @param \TYPO3\CMS\Extbase\Persistence\QueryInterface $query
     * @param array $result
     *
     * @return array
     */
    public function modifySysFileReferenceLanguage(QueryInterface $query, array $result)
    {
        if ($this->isSysFileReferenceTable($query)) {
            $mergedImages = array();
            $translatedReferencesWithDefaultLanguage = $this->reduceResultToTranslatedRecords($result);
            $translatedReferencesWithNoDefaultLanguage = $this->getTranslatedSysFileReferencesWithNoDefaultLanguage($query);

            $record = current($result);
            if (
                is_array($record)
                && !empty($record)
            ) {
                $parentRecord = $this->getParentRecord($record);
                if (
                    (
                        VersionNumberUtility::convertVersionNumberToInteger(TYPO3_branch) < 8006000
                        && isset($GLOBALS['TCA'][$record['tablenames']]['columns'][$record['fieldname']]['l10n_mode'])
                        && $GLOBALS['TCA'][$record['tablenames']]['columns'][$record['fieldname']]['l10n_mode'] === 'mergeIfNotBlank'
                    )
                    || (
                        VersionNumberUtility::convertVersionNumberToInteger(TYPO3_branch) >= 8006000
                        && array_key_exists('l10n_state', $parentRecord)
                        && $this->configuredToUseDefaultLanguage($parentRecord['l10n_state'], $record['fieldname'])
                    )
                ) {
                    // if translated field is empty, than use the images from default language
                    // mergeIfNotBlank has nothing to do with "merging" of default and translated records
                    $this->addImagesToResult(
                        $mergedImages,
                        $translatedReferencesWithNoDefaultLanguage ? $translatedReferencesWithNoDefaultLanguage : $result
                    );
                } else {
                    // merge translated images with images, which are only available in current language
                    $this->addImagesToResult($mergedImages, $translatedReferencesWithDefaultLanguage);
                    $this->addImagesToResult($mergedImages, $translatedReferencesWithNoDefaultLanguage);
                }
            }

            $result = $mergedImages;
        }

        return array(
            0 => $query,
            1 => $result
        );
    }

    /**
     * Get parent record, to check l10n_state value
     *
     * @param array $record
     * @return array
     */
    protected function getParentRecord(array $record)
    {
        $parentRecord = $this->getDatabaseConnection()->exec_SELECTgetSingleRow(
            '*',
            $record['tablenames'],
            'uid=' . (int)$record['uid_foreign']
        );
        if (is_array($parentRecord)) {
            $sysLanguageMode = $GLOBALS['TSFE']->sys_language_mode ? $GLOBALS['TSFE']->sys_language_mode : null;
            $overlayMode = $sysLanguageMode === 'strict' ? 'hideNonTranslated' : '';
            $parentRecord = $this->getPageRepository()->getRecordOverlay(
                $record['tablenames'],
                $parentRecord,
                $GLOBALS['TSFE']->sys_language_uid,
                $overlayMode
            );
        }
        if (empty($parentRecord)) {
            $parentRecord = array();
        }
        return $parentRecord;
    }

    /**
     * Check, how parent record handles image field
     *
     * @param string $json
     * @param string $fieldName
     * @return bool
     */
    protected function configuredToUseDefaultLanguage($json, $fieldName)
    {
        $json = trim($json);
        if (empty($json)) {
            return true;
        }

        $fieldConfiguration = json_decode($json, true);
        if (empty($fieldConfiguration)) {
            return true;
        }

        if (array_key_exists($fieldName, $fieldConfiguration)) {
            if ($fieldConfiguration[$fieldName] === 'custom') {
                return false;
            }
        }
        return true;
    }

    /**
     * Add multiple images to Signal result
     *
     * @param array $result
     * @param array $images
     */
    protected function addImagesToResult(&$result, $images)
    {
        if (is_array($images) && !empty($images)) {
            foreach ($images as $image) {
                array_push($result, $image);
            }
        }
    }

    /**
     * Reduce sysFileReference array to translated records
     *
     * @param array $sysFileReferenceRecords
     *
     * @return array
     */
    protected function reduceResultToTranslatedRecords(array $sysFileReferenceRecords)
    {
        $translatedRecords = array();
        foreach ($sysFileReferenceRecords as $key => $record) {
            if (isset($record['_LOCALIZED_UID'])) {
                // The image reference in translated parent record was not manually deleted.
                // So, l10n_parent is filled and we have a valid translated sys_file_reference record here
                $translatedRecords[] = $record;
            }
        }

        return $translatedRecords;
    }

    /**
     * Check for sys_file_reference table
     *
     * @param QueryInterface $query
     *
     * @return bool
     */
    protected function isSysFileReferenceTable(QueryInterface $query)
    {
        $source = $query->getSource();
        if ($source instanceof SelectorInterface) {
            $tableName = $source->getSelectorName();
        } elseif ($source instanceof JoinInterface) {
            $tableName = $source->getRight()->getSelectorName();
        } else {
            $tableName = '';
        }

        return $tableName === 'sys_file_reference';
    }

    /**
     * Get translated sys_file_references,
     * which do not have a relation to the default language
     * This will happen, if you translate a record, delete the sys_file_record and create a new one
     *
     * @param QueryInterface $query
     *
     * @return array
     */
    protected function getTranslatedSysFileReferencesWithNoDefaultLanguage(QueryInterface $query)
    {
        // Find references which do not have a relation to default language
        $where = array(
            0 => 'sys_file_reference.l10n_parent = 0'
        );
        // add where statements. uid_foreign=UID of translated parent record
        $this->queryParser->parseConstraint($query->getConstraint(), $where);

        if ($this->environmentService->isEnvironmentInFrontendMode()) {
            $where[] = ' 1=1 ' . $this->getPageRepository()->enableFields('sys_file_reference');
        } else {
            $where[] = sprintf(
                ' 1=1 %s %s',
                BackendUtility::BEenableFields('sys_file_reference'),
                BackendUtility::deleteClause('sys_file_reference')
            );
        }
        $rows = $this->getDatabaseConnection()->exec_SELECTgetRows(
            '*',
            'sys_file_reference',
            implode(' AND ', $where),
            '',
            'sorting_foreign ASC'
        );
        if (empty($rows)) {
            $rows = array();
        }

        foreach ($rows as $key => &$row) {
            BackendUtility::workspaceOL('sys_file_reference', $row);
            // t3ver_state=2 indicates that the live element must be deleted upon swapping the versions.
            if ((int)$row['t3ver_state'] === 2) {
                unset($rows[$key]);
            }
        }

        return $rows;
    }

    /**
     * Get page repository
     *
     * @return \TYPO3\CMS\Frontend\Page\PageRepository
     */
    protected function getPageRepository()
    {
        if (!$this->pageRepository instanceof \TYPO3\CMS\Frontend\Page\PageRepository) {
            if ($this->environmentService->isEnvironmentInFrontendMode() && is_object($GLOBALS['TSFE'])) {
                $this->pageRepository = $GLOBALS['TSFE']->sys_page;
            } else {
                $this->pageRepository = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance('TYPO3\\CMS\\Frontend\\Page\\PageRepository');
            }
        }

        return $this->pageRepository;
    }

    /**
     * Get TYPO3s Database Connection
     *
     * @return \TYPO3\CMS\Core\Database\DatabaseConnection
     */
    protected function getDatabaseConnection()
    {
        return $GLOBALS['TYPO3_DB'];
    }
}
