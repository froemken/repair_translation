<?php
namespace StefanFroemken\RepairTranslation\SignalSlot;

/***************************************************************
 *  Copyright notice
 *
 *  (c) 2016 Stefan Froemken <froemken@gmail.com>
 *
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/
use TYPO3\CMS\Backend\Utility\BackendUtility;
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
     * Modify sys_file_reference language
     *
     * @param \TYPO3\CMS\Extbase\Persistence\QueryInterface $query
     * @param array $result
     *
     * @return array
     */
    public function modifySysFileReferenceLanguage(QueryInterface $query, array $result)
    {
        if (
            $this->getTableName($query) === 'sys_file_reference' &&
            ($expectedLanguageUid = (int)$query->getQuerySettings()->getLanguageUid()) &&
            $firstSysFileReferenceRow = current($result)
        ) {
            $origTranslatedReferences = $this->reduceResultToTranslatedRecords($result);
            $newTranslatedReferences = $this->getNewlyCreatedTranslatedSysFileReferences(
                $firstSysFileReferenceRow,
                $expectedLanguageUid
            );
            $result = array_merge($origTranslatedReferences, $newTranslatedReferences);
        }
        
        return array(
            0 => $query,
            1 => $result
        );
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
                // So l10n_parent is filled and we have a valid translated sys_file_reference record here
                $translatedRecords[] = $record;
            }
        }
        
        return $translatedRecords;
    }
    
    /**
     * Get table name
     *
     * @param QueryInterface $query
     *
     * @return string
     */
    protected function getTableName(QueryInterface $query)
    {
        $source = $query->getSource();
        if ($source instanceof SelectorInterface) {
            $tableName = $source->getSelectorName();
        } elseif ($source instanceof JoinInterface) {
            $tableName = $source->getRight()->getSelectorName();
        } else {
            $tableName = '';
        }
        
        return $tableName;
    }
    
    /**
     * Get newly created translated sys_file_references,
     * which do not have a relation to the default language
     * This will happen, if you translate a record, delete the sys_file_record and create a new one
     *
     * @param array $sysFileReferenceRow
     * @param int $expectedLanguageUid
     *
     * @return array
     */
    protected function getNewlyCreatedTranslatedSysFileReferences($sysFileReferenceRow, $expectedLanguageUid)
    {
        $constraints = array();
        
        // $constraints[] = 'sys_language_uid=' . (int)$expectedLanguageUid;
        $constraints[] = 'tablenames=' . $this->getDatabaseConnection()->fullQuoteStr($sysFileReferenceRow['tablenames'], 'sys_file_reference');
        $constraints[] = 'fieldname=' . $this->getDatabaseConnection()->fullQuoteStr($sysFileReferenceRow['fieldname'], 'sys_file_reference');
        $constraints[] = 'uid_foreign=' . (int)$this->getUidOfTranslatedParentRecord($sysFileReferenceRow, $expectedLanguageUid);
    
        if ($this->environmentService->isEnvironmentInFrontendMode()) {
            $constraints[] = ' 1=1 ' . $this->getPageRepository()->enableFields('sys_file_reference');
        } else {
            $constraints[] = sprintf(
                ' 1=1 %s %s',
                BackendUtility::BEenableFields('sys_file_reference'),
                BackendUtility::deleteClause('sys_file_reference')
            );
        }
        $rows = $this->getDatabaseConnection()->exec_SELECTgetRows(
            '*',
            'sys_file_reference',
            implode(' AND ', $constraints)
        );
        if (empty($rows)) {
            $rows = array();
        }
        
        return $rows;
    }
    
    /**
     * Get UID of parent translated record
     * We need this UID to get the images of the translated record
     *
     * @param $sysFileReferenceRow
     * @param $expectedLanguageUid
     *
     * @return int
     */
    protected function getUidOfTranslatedParentRecord($sysFileReferenceRow, $expectedLanguageUid)
    {
        $parentTableName = $sysFileReferenceRow['tablenames'];
        $languageField = $GLOBALS['TCA'][$parentTableName]['ctrl']['languageField'];
        $transPointerField = $GLOBALS['TCA'][$parentTableName]['ctrl']['transOrigPointerField'];
        
        $constraints = array();
        $constraints[] = $languageField . '=' . (int)$expectedLanguageUid;
        $constraints[] = $transPointerField . '=' . $sysFileReferenceRow['uid_foreign'];
    
        if ($this->environmentService->isEnvironmentInFrontendMode()) {
            $constraints[] = ' 1=1 ' . $this->getPageRepository()->enableFields($parentTableName);
        } else {
            $constraints[] = sprintf(
                ' 1=1 %s %s',
                BackendUtility::BEenableFields($parentTableName),
                BackendUtility::deleteClause($parentTableName)
            );
        }
        $row = $this->getDatabaseConnection()->exec_SELECTgetSingleRow(
            'uid',
            $parentTableName,
            implode(' AND ', $constraints)
        );
        if (empty($row)) {
            return 0;
        } else {
            return $row['uid'];
        }
    }
    
    /**
     * Get page repository
     *
     * @return \TYPO3\CMS\Frontend\Page\PageRepository
     */
    protected function getPageRepository() {
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
