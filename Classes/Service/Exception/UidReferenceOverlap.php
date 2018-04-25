<?php
namespace Innologi\TYPO3ExtUpdate\Service\Exception;

/**
 * Uid Reference Overlap Exception
 *
 * @package TYPO3ExtUpdate
 * @author Frenck Lutke
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License, version 2 or later
 */
class UidReferenceOverlap extends Exception
{

    /**
     *
     * @var string
     */
    protected $message = 'Automatic migration completely failed due to uid-reference-overlapping. You will have to start over completely by reverting a database/table backup, or remove all data and re-import all Decos data manually. Possible reason: imports were updated or TCA records were created before migration was complete. (Table: %1$s, Property: %2$s, Source: %3$s, Target: %4$s)';
}
