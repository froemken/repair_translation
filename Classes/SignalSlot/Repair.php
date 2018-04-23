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
use TYPO3\CMS\Core\Utility\GeneralUtility;
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
     * @param array $referencesFromDefaultLanguage
     *
     * @return array
     */
    public function modifySysFileReferenceLanguage(QueryInterface $query, array $referencesFromDefaultLanguage)
    {
        if (
            $this->isSysFileReferenceTable($query)
            && $GLOBALS['TSFE']->sys_language_uid > 0
        ) {
            $mergedImages = array();
            $translatedReferences = $this->getTranslatedSysFileReferences($query);

            $translatedReference = array();
            if (!empty($translatedReferences)) {
                $translatedReference = current($translatedReferences);
            }

            // only valid since TYPO3 8.6
            if (empty($translatedReference) && !empty($referencesFromDefaultLanguage)) {
                $translatedReference = current($referencesFromDefaultLanguage);
            }

            $translatedForeignRecord = array();
            if (!empty($translatedReference)) {
                $translatedForeignRecord = $this->getTranslatedForeignRecord($translatedReference);
            }

            if (
                !empty($translatedForeignRecord)
                && (
                    $this->isMergeIfNotBlankConfigured($translatedReference)
                    || $this->isRecordConfiguredToUseDefaultLanguage($translatedReference, $translatedForeignRecord)
                )
            ) {
                $foreignRecord = $this->getForeignRecordInDefaultLanguage($translatedForeignRecord, $translatedReference);
                if ($foreignRecord) {
                    // if translated field is empty, than use the images from default language
                    // mergeIfNotBlank has nothing to do with "merging" of default and translated records
                    $this->addImagesToResult(
                        $mergedImages,
                        $this->getSysFileReferencesInDefaultLanguage($query, $foreignRecord)
                    );
                }
            } else {
                // merge translated images with images, which are only available in current language
                $this->addImagesToResult($mergedImages, $translatedReferences);
            }

            $referencesFromDefaultLanguage = $mergedImages;
        }

        return array(
            0 => $query,
            1 => $referencesFromDefaultLanguage
        );
    }

    /**
     * In TYPO3 versions less than 8.6 you can configure TYPO3 to use
     * the images from default language, as long as translation does not contain any images
     *
     * @param array $sysFileRecord
     * @return bool
     */
    protected function isMergeIfNotBlankConfigured(array $sysFileRecord)
    {
        return VersionNumberUtility::convertVersionNumberToInteger(TYPO3_branch) < 8006000
            && isset($GLOBALS['TCA'][$sysFileRecord['tablenames']]['columns'][$sysFileRecord['fieldname']]['l10n_mode'])
            && $GLOBALS['TCA'][$sysFileRecord['tablenames']]['columns'][$sysFileRecord['fieldname']]['l10n_mode'] === 'mergeIfNotBlank';
    }

    /**
     * Since TYPO3 8.6 you can decide on your own to use default language or values of translated record.
     * This was saved in a json in column l10n_state
     *
     * @param array $sysFileRecord
     * @param array $foreignRecord
     *
     * @return bool
     */
    protected function isRecordConfiguredToUseDefaultLanguage(array $sysFileRecord, array $foreignRecord)
    {
        return VersionNumberUtility::convertVersionNumberToInteger(TYPO3_branch) >= 8006000
            && array_key_exists('l10n_state', $foreignRecord)
            && $this->configuredToUseDefaultLanguage($foreignRecord['l10n_state'], $sysFileRecord['fieldname']);
    }

    /**
     * Get translated foreign record, to check l10n_state value
     *
     * @param array $record
     *
     * @return array
     */
    protected function getTranslatedForeignRecord(array $record)
    {
        $translatedForeignRecord = $this->getDatabaseConnection()->exec_SELECTgetSingleRow(
            '*',
            $record['tablenames'],
            'uid=' . (int)$record['uid_foreign']
        );
        if (is_array($translatedForeignRecord)) {
            $sysLanguageMode = $GLOBALS['TSFE']->sys_language_mode ? $GLOBALS['TSFE']->sys_language_mode : null;
            $overlayMode = $sysLanguageMode === 'strict' ? 'hideNonTranslated' : '';
            $translatedForeignRecord = $this->getPageRepository()->getRecordOverlay(
                $record['tablenames'],
                $translatedForeignRecord,
                $GLOBALS['TSFE']->sys_language_uid,
                $overlayMode
            );
        }
        if (empty($translatedForeignRecord)) {
            $translatedForeignRecord = array();
        }

        BackendUtility::workspaceOL($record['tablenames'], $translatedForeignRecord);
        // t3ver_state=2 indicates that the live element must be deleted upon swapping the versions.
        if ((int)$translatedForeignRecord['t3ver_state'] === 2) {
            $translatedForeignRecord = array();
        }

        return $translatedForeignRecord;
    }

    /**
     * Get foreign record in default language
     *
     * @param array $translatedRecord
     * @param array $sysFileReference
     *
     * @return array
     */
    protected function getForeignRecordInDefaultLanguage(array $translatedRecord, array $sysFileReference)
    {
        $parentField = $GLOBALS['TCA'][$sysFileReference['tablenames']]['ctrl']['transOrigPointerField'];
        $foreignRecord = $this->getDatabaseConnection()->exec_SELECTgetSingleRow(
            '*',
            $sysFileReference['tablenames'],
            'uid=' . (int)$translatedRecord[$parentField]
        );
        if (empty($foreignRecord)) {
            $foreignRecord = array();
        }

        BackendUtility::workspaceOL($sysFileReference['tablenames'], $foreignRecord);
        // t3ver_state=2 indicates that the live element must be deleted upon swapping the versions.
        if ((int)$foreignRecord['t3ver_state'] === 2) {
            $foreignRecord = array();
        }

        return $foreignRecord;
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
            return false;
        }

        $fieldConfiguration = json_decode($json, true);
        if (empty($fieldConfiguration)) {
            return false;
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
     *
     * @param QueryInterface $query
     *
     * @return array
     */
    protected function getTranslatedSysFileReferences(QueryInterface $query)
    {
        $where = array();
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
     * Get sys_file_references in default language
     *
     * @param QueryInterface $query
     * @param array $foreignRecord
     *
     * @return array
     */
    protected function getSysFileReferencesInDefaultLanguage(QueryInterface $query, array $foreignRecord)
    {
        $where = array();
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

        foreach ($where as $key => $condition) {
            if (GeneralUtility::isFirstPartOfStr($condition, 'sys_file_reference.uid_foreign=')) {
                $where[$key] = 'sys_file_reference.uid_foreign=' . (int)$foreignRecord['uid'];
                break;
            }
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
