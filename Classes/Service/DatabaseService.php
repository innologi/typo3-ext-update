<?php
namespace Innologi\TYPO3ExtUpdate\Service;

use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Database\DatabaseConnection;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Ext Update Database Service
 *
 * Provides several database methods for common use-cases in ext-update context.
 * Note that it must be instantiated with the ObjectManager!
 *
 * @package TYPO3ExtUpdate
 * @author Frenck Lutke
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License, version 2 or later
 */
class DatabaseService implements SingletonInterface
{

    /**
     *
     * @var \TYPO3\CMS\Core\Database\DatabaseConnection
     */
    protected $databaseConnection;

    /**
     *
     * @var FileService
     */
    protected $fileService;

    /**
     *
     * @var array
     */
    protected $referenceUidCache = [];

    /**
     *
     * @var array
     */
    protected $filesForReference = [];

    /**
     *
     * @param FileService $fileService
     * @return void
     */
    public function injectFileService(FileService $fileService)
    {
        $this->fileService = $fileService;
    }

    /**
     * Constructor
     *
     * @param \TYPO3\CMS\Extbase\Object\ObjectManagerInterface $objectManager
     * @return void
     */
    public function __construct(\TYPO3\CMS\Extbase\Object\ObjectManagerInterface $objectManager)
    {
        // @extensionScannerIgnoreLine
        $this->databaseConnection = $GLOBALS['TYPO3_DB'];
        $this->databaseConnection->store_lastBuiltQuery = true;
        // @TODO ___utilize $this->databaseConnection->sql_error() ?
    }

    /**
     * Returns whether extension tables exist.
     *
     * @param string $extensionKey
     * @return boolean
     */
    public function doExtensionTablesExist(string $extensionKey): bool
    {
        $tables = $this->databaseConnection->admin_get_tables();
        foreach ($tables as $tableName => $tableData) {
            if (strpos($tableName, 'tx_' . $extensionKey) === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Migrates table data from one table to another.
     * Does not touch the source table data except for setting
     * a single reference uid property.
     *
     * Note that this only works with tables using an AUTO_INCREMENT uid,
     * as we rely on this property and insert_id
     *
     * @param string $sourceTable
     * @param string $targetTable
     * @param array $propertyMap
     *            Contains sourceProperty => targetProperty mappings
     * @param string $sourceReferenceProperty
     *            Property that will refer to new uid
     * @param array $evaluation
     * @param integer $limitRecords
     * @param \Symfony\Component\Console\Style\SymfonyStyle $io
     * @return integer Affected record count
     * @throws Exception\NoData Nothing to migrate
     */
    public function migrateTableDataWithReferenceUid(string $sourceTable, string $targetTable, array $propertyMap, string $sourceReferenceProperty, array $evaluation = [], int $limitRecords = 100, ?SymfonyStyle $io = null): int
    {
        $evaluation[] = $sourceReferenceProperty . '=0';
        // @extensionScannerIgnoreLine
        $where = join(' ' . DatabaseConnection::AND_Constraint . ' ', $evaluation);
        $max = $this->countTableRecords($sourceTable, $where);
        if ($max <= 0) {
            throw new Exception\NoData(1448612998, [
                $sourceTable
            ]);
        }

        // @LOW this shouldn't really be here, but for now it works
        if ($io !== null) {
            $io->text('Migrate data from \'' . $sourceTable . '\' to \'' . $targetTable . '\'.');
            $io->progressStart($max);
        }
        $count = 0;
        do {

            // select all data rows to migrate, set uid as keys
            $toMigrate = $this->selectTableRecords($sourceTable, $where, '*', $limitRecords);
            $steps = count($toMigrate);
            $count += $steps;

            // translate rows to insertable data according to $propertyMap
            $fields = [];
            $toInsert = $this->translatePropertiesOfRows($toMigrate, $propertyMap, $fields);

            // insert
            $this->insertTableRecords($targetTable, $fields, $toInsert);
            // retrieve first new uid and use it as a starting point for the reference property
            $i = $this->databaseConnection->sql_insert_id();

            // depending on any files for reference, we either just update (much quicker), or update and set fileReferences
            if (empty($this->filesForReference)) {
                foreach ($toMigrate as $uid => $row) {
                    $this->updateTableRecords($sourceTable, [
                        $sourceReferenceProperty => $i ++
                    ], [
                        'uid' => $uid
                    ]);
                }
            } else {
                foreach ($toMigrate as $uid => $row) {
                    $this->updateTableRecords($sourceTable, [
                        $sourceReferenceProperty => $i
                    ], [
                        'uid' => $uid
                    ]);
                    if (isset($this->filesForReference[$uid])) {
                        $this->fileService->setFileReference($this->filesForReference[$uid]['uid'], $targetTable, $i ++, $this->filesForReference[$uid]['field'], (int) $row['pid']);
                    }
                }
                // reset
                $this->filesForReference = [];
            }
        } while ($io !== null && $io->progressAdvance($steps) === null && $count < $max);

        return $count;
    }

    /**
     * Migrates table data from one table to another.
     * Deletes source table data, putting references into the $uidMap argument.
     *
     * Note that this only works with tables using an AUTO_INCREMENT uid,
     * as we rely on this property and insert_id
     *
     * @param string $sourceTable
     * @param string $targetTable
     * @param array $propertyMap
     *            Contains sourceProperty => targetProperty mappings
     * @param integer $limitRecords
     * @param array $uidMap
     *            Reference for storing sourceUid => targetUid mappings
     * @return integer Affected record count
     * @throws Exception\NoData Nothing to migrate
     */
    public function migrateTableDataAndDeleteSource(string $sourceTable, string $targetTable, array $propertyMap, int $limitRecords = 5000, array &$uidMap = []): int
    {
        // select all data rows to migrate, set uid as keys
        $toMigrate = $this->selectTableRecords($sourceTable, '', '*', $limitRecords);
        $count = count($toMigrate);

        if ($count <= 0) {
            throw new Exception\NoData(1448613031, [
                $sourceTable
            ]);
        }

        // translate rows to insertable data according to $propertyMap
        $fields = [];
        $toInsert = $this->translatePropertiesOfRows($toMigrate, $propertyMap, $fields);

        // insert
        $this->insertTableRecords($targetTable, $fields, $toInsert);
        // retrieve new uid's and combine them with original uid's
        $startUid = $this->databaseConnection->sql_insert_id();
        // affected_rows is not reliable when debugging (not sure if xdebug issue), so I'm using $count instead
        $endUid = $startUid + $count;
        $targetUidArray = [];
        for ($i = $startUid; $i < $endUid; $i ++) {
            $targetUidArray[] = $i;
        }

        $sourceUidArray = array_keys($toMigrate);
        $uidMap = array_combine($sourceUidArray, $targetUidArray);

        // remove the old data
        if ($count < $limitRecords) {
            // if there are definitely no more rows to convert,
            // then truncate is quicker and cleaner than delete
            $this->databaseConnection->exec_TRUNCATEquery($sourceTable);
        } else {
            $this->deleteTableRecords($sourceTable, 'uid IN (\'' . join('\',\'', $sourceUidArray) . '\')');
        }

        return $count;
    }

    /**
     * Migrates MM table from one table to another by relying on
     * referenceUids ($localConfig['uid'] and $foreignConfig['uid']
     * for the new uid values.
     *
     * Does not touch the sourceTable other than setting a flag
     * in $sourceFlagProperty.
     *
     * Variation of migrateTableData* methods.
     *
     * @param string $sourceTable
     * @param string $targetTable
     * @param array $localConfig
     *            Local table + its new uid property
     * @param array $foreignConfig
     *            Foreign table + its new uid property
     * @param array $propertyMap
     *            Contains sourceProperty => targetProperty mappings, excluding uid-mappings
     * @param string $sourceFlagProperty
     * @param integer $limitRecords
     * @param \Symfony\Component\Console\Style\SymfonyStyle $io
     * @return integer Affected record count
     * @throws Exception\NoData Nothing to migrate
     */
    public function migrateMmTableWithReferenceUid(string $sourceTable, string $targetTable, array $localConfig, array $foreignConfig, array $propertyMap, string $sourceFlagProperty, int $limitRecords = 100, ?SymfonyStyle $io = null): int
    {
        $select = sprintf('mm.*, l.%1$s AS new_local, f.%2$s AS new_foreign', $localConfig['uid'], $foreignConfig['uid']);
        $from = sprintf('%1$s mm, %2$s l, %3$s f', $sourceTable, $localConfig['table'], $foreignConfig['table']);
        // @TODO ___ why not use AND_Constraint?
        $where = sprintf('mm.%3$s=0 AND f.uid=mm.uid_foreign AND l.uid=mm.uid_local AND f.%2$s > 0 AND l.%1$s > 0', $localConfig['uid'], $foreignConfig['uid'], $sourceFlagProperty);
        if (isset($localConfig['evaluation'])) {
            foreach ($localConfig['evaluation'] as $eval) {
                $where .= sprintf(' AND l.%1$s', $eval);
            }
        }
        if (isset($foreignConfig['evaluation'])) {
            foreach ($foreignConfig['evaluation'] as $eval) {
                $where .= sprintf(' AND f.%1$s', $eval);
            }
        }

        $max = $this->countTableRecords($from, $where);
        if ($max <= 0) {
            throw new Exception\NoData(1448613061, [
                $sourceTable
            ]);
        }

        // @LOW this shouldn't really be here, but for now it works
        if ($io !== null) {
            $io->text('Migrate references from \'' . $sourceTable . '\' to \'' . $targetTable . '\'.');
            $io->progressStart($max);
        }
        $count = 0;
        do {

            // select all data rows to migrate
            $toMigrate = $this->databaseConnection->exec_SELECTgetRows($select, $from, $where, '', '', $limitRecords);
            $steps = count($toMigrate);
            $count += $steps;

            // translate rows to insertable data according to $propertyMap
            $propertyMap = array_merge($propertyMap, [
                'new_local' => 'uid_local',
                'new_foreign' => 'uid_foreign'
            ]);
            $fields = [];
            $toInsert = $this->translatePropertiesOfRows($toMigrate, $propertyMap, $fields);

            // insert
            $this->insertTableRecords($targetTable, $fields, $toInsert);

            // create a where which only matches the original uid's
            $whereArray = [];
            $intersect = [
                'uid_local' => 1,
                'uid_foreign' => 1
            ];
            foreach ($toMigrate as $row) {
                $whereArray[] = $this->getWhereFromConditionArray(array_intersect_key($row, $intersect), $sourceTable);
            }

            // update the $sourceFlagProperty value
            // @extensionScannerIgnoreLine
            $this->databaseConnection->exec_UPDATEquery($sourceTable, '(' . join(') ' . DatabaseConnection::OR_Constraint . ' (', $whereArray) . ')', [
                $sourceFlagProperty => 1
            ]);
        } while ($io !== null && $io->progressAdvance($steps) === null && $count < $max);

        return $count;
    }

    /**
     * Migrates MM table from one table to another.
     *
     * Variation of migrateTableData* methods.
     *
     * @param string $sourceTable
     * @param string $targetTable
     * @param array $propertyMap
     *            Contains sourceProperty => targetProperty mappings
     * @param integer $limitRecords
     * @return integer Affected record count
     * @throws Exception\NoData Nothing to migrate
     */
    public function migrateMmTableAndDeleteSource(string $sourceTable, string $targetTable, array $propertyMap, int $limitRecords = 5000): int
    {
        // select all data rows to migrate
        $toMigrate = $this->databaseConnection->exec_SELECTgetRows('*', $sourceTable, '', '', '', $limitRecords);
        $count = count($toMigrate);

        if ($count <= 0) {
            throw new Exception\NoData(1448613107, [
                $sourceTable
            ]);
        }

        // translate rows to insertable data according to $propertyMap
        $fields = [];
        $toInsert = $this->translatePropertiesOfRows($toMigrate, $propertyMap, $fields);

        // insert
        $this->insertTableRecords($targetTable, $fields, $toInsert);

        // remove the old data
        if ($count < $limitRecords) {
            // if there are definitely no more rows to convert,
            // then truncate is quicker and cleaner than delete
            $this->databaseConnection->exec_TRUNCATEquery($sourceTable);
        } else {
            // remove every migrated uid_local/foreign combination
            $whereArray = [];
            $intersect = [
                'uid_local' => 1,
                'uid_foreign' => 1
            ];
            foreach ($toMigrate as $row) {
                $whereArray[] = $this->getWhereFromConditionArray(array_intersect_key($row, $intersect), $sourceTable);
            }
            // @extensionScannerIgnoreLine
            $this->deleteTableRecords($sourceTable, '(' . join(') ' . DatabaseConnection::OR_Constraint . ' (', $whereArray) . ')');
        }

        return $count;
    }

    /**
     * Creates unique records from values taken from source.
     *
     * Note that this only works with tables using an AUTO_INCREMENT uid,
     * as we rely on insert_id
     *
     * @param string $sourceTable
     * @param string $targetTable
     * @param array $propertyMap
     *            Contains sourceProperty => targetProperty mappings
     * @param array $evaluation
     *            Contains database-evaluations used to select valid unique values
     * @param integer $limitRecords
     * @param array $uidMap
     *            Reference for storing targetUid => array(sourceProperty => value) mappings
     * @return integer Affected record count
     * @throws Exception\NoUniqueData Nothing to migrate
     */
    public function createUniqueRecordsFromValues(string $sourceTable, string $targetTable, array $propertyMap, array $evaluation = [], int $limitRecords = 10000, array &$uidMap = []): int
    {
        $uniqueBy = join(',', array_keys($propertyMap));
        // @extensionScannerIgnoreLine
        $where = empty($evaluation) ? '' : join(' ' . DatabaseConnection::AND_Constraint . ' ', $evaluation);
        // select all unique propertymap combinations
        $toInsert = $this->databaseConnection->exec_SELECTgetRows($uniqueBy, $sourceTable, $where, $uniqueBy, $uniqueBy, $limitRecords);
        $count = count($toInsert);

        if ($count <= 0) {
            throw new Exception\NoUniqueData(1448613241, [
                $targetTable
            ]);
        }

        // find already existing matches
        $uidMap = $this->insertUniqueRecords($targetTable, $propertyMap, $toInsert);
        return $this->databaseConnection->sql_affected_rows();
    }

    /**
     * Mass replaces a property value with a targetValue for those records
     * who meet the paired conditions.
     *
     * @param string $table
     * @param string $property
     * @param array $valueConditionMap
     *            targetValue => condition-array(property => value)
     * @return integer Affected record count
     */
    public function updatePropertyByCondition(string $table, string $property, array $valueConditionMap): int
    {
        $count = 0;
        foreach ($valueConditionMap as $targetValue => $conditions) {
            $values = [
                $property => $targetValue
            ];
            $count += $this->updateTableRecords($table, $values, $conditions);
        }
        return $count;
    }

    /**
     * Mass replace values on a single table property.
     *
     * If you're replacing uid references with this method,
     * enabling $strictlyUidReferences will report any issues
     * that are of utmost importance for those. For instance,
     * sourceUids need to be offered in ascending order so that
     * targetUids will never be higher than sourceUids. If they
     * are, targets will end up being overlapped by sources,
     * throwing off the ENTIRE update-mechanism.
     *
     * @param string $table
     * @param string $property
     * @param array $valueMap
     *            sourceValue => targetValue
     * @param boolean $strictlyUidReferences
     * @return integer Affected record count
     * @throws Exception\UidReferenceOverlap
     */
    public function updatePropertyBySourceValue(string $table, string $property, array $valueMap, bool $strictlyUidReferences = false): int
    {
        $count = 0;
        foreach ($valueMap as $sourceValue => $targetValue) {
            if ($strictlyUidReferences && (int) $targetValue > (int) $sourceValue) {
                // stops updating/migrating entirely
                throw new Exception\UidReferenceOverlap(1448615606, [
                    $table,
                    $property,
                    $sourceValue,
                    $targetValue
                ]);
            }

            $values = [
                $property => $targetValue
            ];
            $where = [
                $property => $sourceValue
            ];
            $count += $this->updateTableRecords($table, $values, $where);
        }
        return $count;
    }

    /**
     * Update table records wrapper function that will format $where
     * automatically for you.
     *
     * @param string $table
     * @param array $values
     * @param array $whereArray
     * @return integer Affected record count
     */
    public function updateTableRecords(string $table, array $values, array $whereArray = []): int
    {
        $where = empty($whereArray) ? '' : $this->getWhereFromConditionArray($whereArray, $table);
        $this->databaseConnection->exec_UPDATEquery($table, $where, $values);
        return $this->databaseConnection->sql_affected_rows();
    }

    /**
     * Returns total count of selection.
     *
     * @param string $table
     * @param string $where
     * @param string $select
     * @return integer
     */
    public function countTableRecords(string $table, string $where = '', string $select = '*'): int
    {
        return $this->databaseConnection->exec_SELECTcountRows($select, $table, $where);
    }

    /**
     * Select $table records that meet $where condition, sorted
     * by uid (ASC) and returns them in an array with uid as key.
     *
     * @param string $table
     * @param string $where
     *            Note that an empty $where will return all
     * @param string $select
     *            Will return all columns by default
     * @param integer $limit
     *            Limits record count
     * @param string $orderBy
     * @return array
     * @throws Exception\SqlError
     */
    public function selectTableRecords(string $table, string $where = '', string $select = '*', int $limit = 5000, string $orderBy = 'uid ASC'): array
    {
        $groupBy = '';
        $rows = $this->databaseConnection->exec_SELECTgetRows($select, $table, $where, $groupBy, $orderBy, $limit, 'uid');
        if ($rows === null) {
            throw new Exception\SqlError(1448613438, [
                $this->databaseConnection->debug_lastBuiltQuery
            ]);
        }
        return $rows;
    }

    /**
     * Deletes table records.
     *
     * @param string $table
     * @param string $where
     * @return integer Affected row count
     */
    public function deleteTableRecords(string $table, string $where = ''): int
    {
        $this->databaseConnection->exec_DELETEquery($table, $where);
        return $this->databaseConnection->sql_affected_rows();
    }

    /**
     * Inserts multiple rows into table.
     *
     * @param string $table
     * @param array $fields
     * @param array $values
     *            Each element is an array with values
     * @return integer
     */
    public function insertTableRecords(string $table, array $fields, array $values): int
    {
        $this->databaseConnection->exec_INSERTmultipleRows($table, $fields, $values);
        return $this->databaseConnection->sql_affected_rows();
    }

    /**
     * Translates multiple rows' properties according to propertyMap.
     *
     * Providing the $fields parameter, you can retrieve a reference
     * of the target row field names.
     *
     * @param array $sourceRows
     * @param array $propertyMap
     * @param array $fields
     * @return array Target rows result
     */
    protected function translatePropertiesOfRows(array $sourceRows, array $propertyMap, array &$fields = []): array
    {
        $targetRows = [];
        $latestRow = null;
        foreach ($sourceRows as $row) {
            $latestRow = $this->translatePropertiesOfRow($row, $propertyMap);
            $targetRows[] = $latestRow;
        }
        if ($latestRow !== null) {
            $fields = array_keys($latestRow);
        }
        return $targetRows;
    }

    /**
     * Translates single row's properties according to propertyMap.
     *
     * If any properties aren't found in the sourceRow,
     * they will be removed from the $propertyMap reference.
     *
     * When a $targetProperty is an array instead of a string, this
     * indicates a special configuration for said property:
     * - valueReference: the value is to be stored in its own table and is to be replaced by a reference uid
     * - fileReference: the value is a file uid, and will be skipped here but stored as a settable FileReference
     *
     * @param array $sourceRow
     * @param array $propertyMap
     * @return array Target row result
     */
    protected function translatePropertiesOfRow(array $sourceRow, array &$propertyMap): array
    {
        $targetRow = [];
        foreach ($propertyMap as $sourceProperty => $targetProperty) {
            if (isset($sourceRow[$sourceProperty])) {
                if (! is_array($targetProperty)) {
                    // normal translation
                    $targetRow[$targetProperty] = $sourceRow[$sourceProperty];
                } else {
                    // special config
                    if (isset($targetProperty['valueReference'])) {
                        // valueReferences are usually for unique valueObjects for which you want the reference uid
                        // or vice versa. In the latter case, the value must already exist, because an insert cannot
                        // possibly generate your expected value unless you provide it already, which defeats the
                        // purpose of trying to retrieve it
                        $valueReference = $targetProperty['valueReference'];
                        // note that the source value will be replaced in target with its new reference uid, effectively creating a 1:N relation
                        $targetRow[$valueReference['targetProperty']] = $this->getReferenceUid($valueReference, $sourceRow[$sourceProperty], $sourceRow);
                    } elseif (isset($targetProperty['fileReference']) && (int) $sourceRow[$sourceProperty] > 0) {
                        // fileReferences are file uid's to be set in relation to the targetRow
                        $fileReference = $targetProperty['fileReference'];
                        $this->filesForReference[$sourceRow['uid']] = [
                            'uid' => (int) $sourceRow[$sourceProperty],
                            'field' => $fileReference['targetProperty']
                        ];
                        // note that it currently stores nothing in targetRow
                    }
                }
            } else {
                // prevents it being iterated over for every row
                unset($propertyMap[$sourceProperty]);
            }
        }
        return $targetRow;
    }

    /**
     * Returns the property reference uid either directly from database or
     * by inserting it first.
     *
     * $propertyConfig needs to contain the following elements:
     * - foreignTable
     * - foreignField
     * - valueField
     * .. may contain additional elements:
     * - uniqueBy
     *
     * @param array $propertyConfig
     * @param string $value
     * @param array $sourceRow
     * @return integer
     * @throws Exception\SqlError
     */
    protected function getReferenceUid(array $propertyConfig, string $value, array $sourceRow): int
    {
        // @TODO ___throw exception if $propertyConfig misses configuration fields
        $values = [
            $propertyConfig['valueField'] => $value
        ];
        if (isset($propertyConfig['uniqueBy'])) {
            foreach ($propertyConfig['uniqueBy'] as $uniqueBy) {
                $values[$uniqueBy] = $sourceRow[$uniqueBy];
            }
        }

        $refId = join(';;;', $values);
        if (! isset($this->referenceUidCache[$propertyConfig['foreignTable']][$refId])) {
            $row = $this->databaseConnection->exec_SELECTgetSingleRow($propertyConfig['foreignField'], $propertyConfig['foreignTable'], $this->getWhereFromConditionArray($values, $propertyConfig['foreignTable']), '', 'uid DESC');
            if ($row === false) {
                $this->databaseConnection->exec_INSERTquery($propertyConfig['foreignTable'], $values);
                $uid = $this->databaseConnection->sql_insert_id();
            } elseif (isset($row[$propertyConfig['foreignField']])) {
                $uid = $row[$propertyConfig['foreignField']];
            } else {
                throw new Exception\SqlError(1448613495, [
                    $this->databaseConnection->debug_lastBuiltQuery
                ]);
            }
            $this->referenceUidCache[$propertyConfig['foreignTable']][$refId] = $uid;
        }

        return $this->referenceUidCache[$propertyConfig['foreignTable']][$refId];
    }

    /**
     * Creates a where string from a condition array
     *
     * Note that a condition value can hold special configuration if given
     * as array instead of string
     *
     * @param array $conditions
     *            Contains property => value combinations
     * @param string $table
     *            Table from which the conditions originate
     * @return string
     */
    protected function getWhereFromConditionArray(array $conditions, ?string $table = null): string
    {
        $where = [];
        foreach ($conditions as $property => $value) {
            $operator = '=%1$s';
            if (! is_array($value)) {
                $value = $this->databaseConnection->fullQuoteStr($value, $table);
            } else {
                $noQuote = false;
                // process value array recursively
                while (is_array($value)) {
                    if (isset($value['value'])) {
                        // if $value contains 'value', it is capable of providing
                        // a bit of configuration
                        if (isset($value['operator'])) {
                            // e.g. ' IN (%1$s)' or ' NOT IN (%1$s)' or '!=%1$s', etc.
                            $operator = $value['operator'];
                        }
                        if (isset($value['no_quote'])) {
                            // setting no_quote can be useful when providing a string value
                            // or array with strings already processed by e.g. fullQuoteStr().
                            $noQuote = (bool) $value['no_quote'];
                        }
                        if (is_array($value['value']) || $noQuote) {
                            $value = $value['value'];
                        } else {
                            // effectively ends the recursive while loop
                            $value = $this->databaseConnection->fullQuoteStr($value['value'], $table);
                        }
                    } else {
                        // if $value is an array but no value subelement exists, we assume
                        // the array consists solely of values that should be joined as a string,
                        // ending the recursive while loop
                        $vArray = [];
                        if ($noQuote) {
                            foreach ($value['value'] as $v) {
                                $vArray[] = $v;
                            }
                        } else {
                            foreach ($value['value'] as $v) {
                                $vArray[] = $this->databaseConnection->fullQuoteStr($v, $table);
                            }
                        }
                        $value = join(',', $vArray);
                    }
                }
            }
            $where[] = $property . sprintf($operator, $value);
        }
        // @extensionScannerIgnoreLine
        return join(' ' . DatabaseConnection::AND_Constraint . ' ', $where);
    }

    /**
     * Insert unique records, while checking for any existing ones.
     * Will return an UidMap that contains all relevant Uid references,
     * both new and existing ones.
     *
     * @param string $table
     * @param array $properties
     *            sourceProperties => targetProperties
     * @param array $valueRows
     * @return array UidMap containing uid => array(property => value)
     * @throws Exception\UnexpectedNoMatch
     */
    protected function insertUniqueRecords(string $table, array $properties, array $valueRows): array
    {
        $uidMap = [];
        // create where-conditions per valueRow so as to find specific matches
        $whereArray = [];
        foreach ($valueRows as $row) {
            $whereArray[] = $this->getWhereFromConditionArray(array_combine($properties, $row), $table);
        }
        // select without limit
        // @extensionScannerIgnoreLine
        $matches = $this->selectTableRecords($table, '(' . join(') ' . DatabaseConnection::OR_Constraint . ' (', $whereArray) . ')', '*', '');

        // if there are existing matches, we need to trim $values
        if (! empty($matches)) {
            $trimmedValues = [];
            foreach ($valueRows as $row) {
                $trimmedValues[md5(join('|', $row))] = $row;
            }
            // we need the target properties as key in a moment
            $flippedProperties = array_flip($properties);
            foreach ($matches as $uid => $row) {
                // only get the part of $row that matches keys with the target properties,
                // in case $matches holds more properties than $valueRows does
                $row = array_intersect_key($row, $flippedProperties);
                $index = join('|', $row);
                $indexHashed = md5($index);
                if (! isset($trimmedValues[$indexHashed])) {
                    throw new Exception\UnexpectedNoMatch(1448615948, [
                        $table,
                        $index
                    ]);
                }
                unset($trimmedValues[$indexHashed]);
                // register the known values with their uid already
                // row contains target properties, but uidMap needs source properties
                $uidMap[$uid] = $this->translatePropertiesOfRow($row, $flippedProperties);
            }
            $valueRows = $trimmedValues;
        }

        if (! empty($valueRows)) {
            // insert
            $this->insertTableRecords($table, $properties, $valueRows);
            // retrieve new uid's and register them with the newly stored data
            $uid = $this->databaseConnection->sql_insert_id();
            foreach ($valueRows as $row) {
                $uidMap[$uid ++] = $row;
            }
        }

        return $uidMap;
    }
}
